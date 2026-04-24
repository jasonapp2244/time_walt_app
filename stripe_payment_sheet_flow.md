# Time Vault - Stripe Payment Sheet Migration

> **Last Updated:** 2026-04-22
> **Status:** NOT IMPLEMENTED — Migration plan for Flutter client integration.
> **Scope:** Remove Stripe Connect Account onboarding + Replace Checkout Session with Payment Sheet + Store card details (brand, last4, expiry, funding, country, method type) for admin panel. All other APIs (holds, transfers, emails, admin, crons) stay exactly the same.

---

## Implementation Status

| Task | Status | Description |
|------|--------|-------------|
| Remove Connect Account APIs | Not Started | Remove onboarding, Connect callbacks, web redirects |
| Add Payment Sheet backend | Not Started | New service, controller, model, migration for Payment Sheet |
| Add card details to payments | Not Started | Store card brand, last4, expiry, funding, country, method type |
| Update routes | Not Started | Add 2 new routes, remove 6 old routes |
| Config changes | Not Started | Add STRIPE_PUBLISHABLE_KEY |

---

## Table of Contents

1. [What Changes vs What Stays](#1-what-changes-vs-what-stays)
2. [What Stripe Payment Sheet Requires](#2-what-stripe-payment-sheet-requires)
3. [Complete Payment Sheet Flow Diagram](#3-complete-payment-sheet-flow-diagram)
4. [Stripe Customer & Saved Cards](#4-stripe-customer--saved-cards)
5. [Card Details Storage for Admin Panel](#5-card-details-storage-for-admin-panel)
6. [API Changes Summary](#6-api-changes-summary)
7. [Backend Implementation — Step by Step](#7-backend-implementation--step-by-step)
8. [Flutter Integration Guide](#8-flutter-integration-guide)
9. [Webhook Changes](#9-webhook-changes)
10. [Configuration Changes](#10-configuration-changes)
11. [Security Considerations](#11-security-considerations)
12. [What Stays Unchanged](#12-what-stays-unchanged)

---

## 1. What Changes vs What Stays

### Overview

```
REMOVE (Connect Account — too lengthy for users):
==================================================
  POST /api/stripe/connect/create           <- Connect account creation
  POST /api/stripe/connect/onboarding-link  <- KYC onboarding URL
  GET  /api/stripe/connect/return           <- Connect OAuth callback
  GET  /stripe/return                       <- Web redirect after onboarding
  GET  /stripe/reauth                       <- Web redirect for re-auth

REPLACE (Checkout Session -> Payment Sheet):
============================================
  POST /api/stripe/payment-intent     <- OLD: Creates Checkout Session
  POST /api/stripe/verify-payment     <- OLD: Verifies via session_id
  GET  /api/stripe/payment/return     <- OLD: Checkout redirect back
                        |
                        v
  POST /api/stripe/create-payment-intent  <- NEW: Creates PaymentIntent + Customer
  POST /api/stripe/confirm-payment        <- NEW: Verifies via payment_intent_id

KEEP EXACTLY THE SAME (zero changes):
======================================
  All auth APIs (login, signup, OTP, forgot password, etc.)
  All profile APIs (update, change password, language, timezone)
  All notification settings APIs
  All payment-holds APIs (index, summary, request-payout, withdraw)
  All transaction history APIs (all, checkouts, withdraws, hold-amounts, ready-for-transfer)
  All admin panel APIs (dashboard, users, payments, holds, transfers)
  POST /api/stripe/webhook                <- Webhooks still work
  POST /api/admin/transfer/{hold_id}      <- Admin transfer stays
  POST /admin/transfers/{hold}/execute    <- Admin web transfer stays
  Privacy policy API
  All email notifications (payment success, failed, hold ended, transfer, payout)
  All cron jobs (check:payment-holds, verify:pending-transfers)
  All models, services, middleware
```

### Side-by-Side Comparison

```
BEFORE (Current):                         AFTER (New):
=================                         ============

User opens app                            User opens app
     |                                         |
     v                                         |
Connect Account Setup (LENGTHY)                | (REMOVED - no setup needed!)
  - Create Connect account                     |
  - Open Stripe KYC page                       |
  - Upload ID, bank, tax info                  |
  - Wait for verification                      |
  - ~15 minutes, leaves app                    |
     |                                         |
     v                                         v
Payment via Checkout Session              Payment via Payment Sheet
  - Backend creates Checkout Session        - Backend creates PaymentIntent
  - User redirected to browser              - Native Payment Sheet pops up IN APP
  - Stripe-hosted payment page              - Card + Apple Pay + Google Pay
  - Card only (no Apple/Google Pay)         - Saved cards for returning users
  - Stripe redirects back                   - Stays in app entire time
  - App calls verify-payment                - App calls confirm-payment
  - ~2 minutes, leaves app                  - ~30 seconds, stays in app
     |                                         |
     v                                         v
Payment + Hold created                    Payment + Hold created
  (SAME from here on)                       (SAME from here on)
     |                                         |
     v                                         v
Hold period management (SAME)             Hold period management (SAME)
     |                                         |
     v                                         v
Transfer/Payout (SAME)                    Transfer/Payout (SAME)
     |                                         |
     v                                         v
Email notifications (SAME)                Email notifications (SAME)
```

---

## 2. What Stripe Payment Sheet Requires

Flutter's `flutter_stripe` Payment Sheet SDK needs **4 values** from the backend to work. Without ANY of these, Payment Sheet **will not open**.

```
Flutter Payment Sheet requires from backend:
=============================================

  +-------------------+---------------------------------------------+
  | 1. client_secret   | From PaymentIntent (pi_xxx_secret_xxx)     |
  |                    | Tells Stripe SDK which payment to confirm  |
  +-------------------+---------------------------------------------+
  | 2. customer_id     | Stripe Customer ID (cus_xxx)               |
  |                    | REQUIRED to show saved cards + Payment UI  |
  +-------------------+---------------------------------------------+
  | 3. ephemeral_key   | Temp access token for customer (ek_xxx)    |
  |                    | Lets Flutter SDK access customer's cards    |
  +-------------------+---------------------------------------------+
  | 4. publishable_key | Your Stripe publishable key (pk_xxx)       |
  |                    | Identifies your Stripe account             |
  +-------------------+---------------------------------------------+

  Why each is needed:
  -------------------
  client_secret   -> Without this, Stripe doesn't know WHAT to charge
  customer_id     -> Without this, Payment Sheet UI won't render
  ephemeral_key   -> Without this, Flutter can't access customer data
  publishable_key -> Without this, Stripe SDK can't initialize

  Where each comes from:
  ----------------------
  client_secret   -> Created when backend calls PaymentIntent::create()
  customer_id     -> Created when backend calls Customer::create() (first time)
                     or retrieved from stripe_customers table (returning user)
  ephemeral_key   -> Created when backend calls EphemeralKey::create()
  publishable_key -> From config/services.php (STRIPE_PUBLISHABLE_KEY env var)
```

### Why We Need a Stripe Customer (New Concept)

```
CURRENT: No Stripe Customer needed
===================================
  Checkout Session handles everything on Stripe's side.
  User enters card on Stripe's hosted page.
  No saved cards. No Apple Pay. No Google Pay.

NEW: Stripe Customer required
==============================
  Payment Sheet SDK REQUIRES a Stripe Customer object to:

  1. Present the Payment Sheet UI (won't open without it)
  2. Save payment methods (cards) for future payments
  3. Show previously saved cards on return visits
  4. Enable Apple Pay / Google Pay

  Customer is created ONCE per user (first payment).
  All future payments reuse the same customer.

  +----------+          +-----------------+         +-------------+
  |  User    |  1:1     | StripeCustomer  |  hosted | Stripe API  |
  |  (ours)  |--------->| (our table)     |-------->| Customer    |
  |          |          | cus_xxx (enc.)  |         | (has cards) |
  +----------+          +-----------------+         +-------------+
```

---

## 3. Complete Payment Sheet Flow Diagram

### API 1: POST /api/stripe/create-payment-intent

```
+---------------+        +-------------------+        +---------------+
|  Flutter App  |        |  Laravel Backend   |        |  Stripe API   |
+-------+-------+        +---------+---------+        +-------+-------+
        |                          |                          |
        |  User enters:            |                          |
        |    amount: 50.00         |                          |
        |    currency: 'usd'       |                          |
        |    hold_period_type:     |                          |
        |      'custom'            |                          |
        |    hold_start_at:        |                          |
        |      '2026-04-22'        |                          |
        |    hold_end_at:          |                          |
        |      '2026-05-22'        |                          |
        |    title: 'Savings'      |                          |
        |                          |                          |
        |  POST /api/stripe/       |                          |
        |  create-payment-intent   |                          |
        |------------------------->|                          |
        |                          |                          |
        |                     +----+----------------------+   |
        |                     | Backend Step 1:            |   |
        |                     | Get or Create Customer     |   |
        |                     |                            |   |
        |                     | Check stripe_customers     |   |
        |                     | table: user_id exists?     |   |
        |                     |                            |   |
        |                     | NO (first time):           |   |
        |                     |   Call Stripe API -------->|-->| Customer::create({
        |                     |                            |   |   email, name,
        |                     |   Store in our table <-----|<--| metadata:{user_id}
        |                     |   (encrypted cus_xxx)      |   | }) -> cus_abc123
        |                     |                            |   |
        |                     | YES (returning user):      |   |
        |                     |   Use existing cus_xxx     |   |
        |                     |   (skip Stripe call)       |   |
        |                     +----+-----------------------+   |
        |                          |                          |
        |                     +----+----------------------+   |
        |                     | Backend Step 2:            |   |
        |                     | Create Ephemeral Key       |   |
        |                     |                            |   |
        |                     | Call Stripe API ---------->|-->| EphemeralKey::create({
        |                     |                            |   |   customer: cus_abc123
        |                     | Get temp token <-----------|<--| }) -> ek_xxx
        |                     | (short-lived, auto-expires)|   |
        |                     +----+-----------------------+   |
        |                          |                          |
        |                     +----+----------------------+   |
        |                     | Backend Step 3:            |   |
        |                     | Create PaymentIntent       |   |
        |                     |                            |   |
        |                     | Call Stripe API ---------->|-->| PaymentIntent::create({
        |                     |                            |   |   amount: 5000 (cents),
        |                     |                            |   |   currency: 'usd',
        |                     |                            |   |   customer: cus_abc123,
        |                     |                            |   |   setup_future_usage:
        |                     |                            |   |     'off_session',
        |                     |                            |   |   automatic_payment_methods:
        |                     |                            |   |     { enabled: true },
        |                     |                            |   |   metadata: {
        |                     |                            |   |     user_id: '5',
        |                     |                            |   |     hold_period_type:
        |                     |                            |   |       'custom',
        |                     |                            |   |     hold_start_at:
        |                     |                            |   |       '2026-04-22',
        |                     |                            |   |     hold_end_at:
        |                     |                            |   |       '2026-05-22',
        |                     |                            |   |     title: 'Savings'
        |                     |                            |   |   }
        |                     | Get pi_xxx <---------------|<--| }) -> pi_xxx
        |                     |   + client_secret          |   |     + client_secret
        |                     +----+-----------------------+   |
        |                          |                          |
        |  Response JSON:          |                          |
        |  {                       |                          |
        |    "success": true,      |                          |
        |    "data": {             |                          |
        |      "payment_intent_id":|                          |
        |        "pi_xxx",         |    Flutter needs         |
        |      "client_secret":    |<-- these 4 values        |
        |        "pi_xxx_secret",  |    to show Payment       |
        |      "customer_id":      |    Sheet                 |
        |        "cus_abc123",     |                          |
        |      "ephemeral_key":    |                          |
        |        "ek_xxx",         |                          |
        |      "publishable_key":  |                          |
        |        "pk_live_xxx",    |                          |
        |      "amount": 50.00,    |                          |
        |      "currency": "usd"   |                          |
        |    }                     |                          |
        |  }                       |                          |
        |<-------------------------|                          |
        |                          |                          |
```

### Flutter Shows Payment Sheet (Native UI)

```
        |                          |                          |
        |  Flutter code:           |                          |
        |                          |                          |
        |  Stripe.instance         |                          |
        |    .initPaymentSheet(    |                          |
        |      clientSecret,       |                          |
        |      customerId,         |                          |
        |      ephemeralKey        |                          |
        |    )                     |                          |
        |                          |                          |
        |  Stripe.instance         |                          |
        |    .presentPaymentSheet()|                          |
        |                          |                          |
        |  +--------------------------------------+           |
        |  |     NATIVE PAYMENT SHEET UI          |           |
        |  |     (opens inside app)               |           |
        |  |                                      |           |
        |  |  FIRST-TIME USER:                    |           |
        |  |  +--------------------------------+  |           |
        |  |  | Card: ____ ____ ____ ____      |  |           |
        |  |  | MM/YY    CVC                   |  |           |
        |  |  +--------------------------------+  |           |
        |  |  +--------------------------------+  |           |
        |  |  |  Apple Pay                     |  |  <- NEW!  |
        |  |  +--------------------------------+  |           |
        |  |  +--------------------------------+  |           |
        |  |  |  Google Pay                    |  |  <- NEW!  |
        |  |  +--------------------------------+  |           |
        |  |  [x] Save card for future use        |  <- NEW!  |
        |  |  +--------------------------------+  |           |
        |  |  |       Pay $50.00               |  |           |
        |  |  +--------------------------------+  |           |
        |  |                                      |           |
        |  |  RETURNING USER (has saved cards):   |           |
        |  |  +--------------------------------+  |           |
        |  |  | * Visa **** 4242  12/28   [v]  |  |  <- Saved |
        |  |  +--------------------------------+  |           |
        |  |  +--------------------------------+  |           |
        |  |  | o MC   **** 5556  03/29        |  |  <- Saved |
        |  |  +--------------------------------+  |           |
        |  |  +--------------------------------+  |           |
        |  |  | + Add new card                 |  |           |
        |  |  +--------------------------------+  |           |
        |  |  +--------------------------------+  |           |
        |  |  |  Apple Pay                     |  |           |
        |  |  +--------------------------------+  |           |
        |  |  +--------------------------------+  |           |
        |  |  |       Pay $50.00               |  |           |
        |  |  +--------------------------------+  |           |
        |  +--------------------------------------+           |
        |                          |                          |
        |  User taps "Pay $50"     |                          |
        |  ------------------------------------------------->|
        |                          |    Stripe SDK confirms   |
        |                          |    PaymentIntent         |
        |                          |    Handles 3D Secure     |
        |                          |    automatically         |
        |  <-------------------------------------------------|
        |  Payment Sheet returns   |                          |
        |  SUCCESS                 |                          |
        |                          |                          |
```

### API 2: POST /api/stripe/confirm-payment

```
        |                          |                          |
        |  POST /api/stripe/       |                          |
        |  confirm-payment         |                          |
        |  {                       |                          |
        |    "payment_intent_id":  |                          |
        |      "pi_xxx"            |                          |
        |  }                       |                          |
        |------------------------->|                          |
        |                          |                          |
        |                     +----+----------------------+   |
        |                     | Backend Step 1:            |   |
        |                     | Retrieve PaymentIntent     |   |
        |                     |                            |   |
        |                     | Call Stripe API ---------->|-->| PaymentIntent::retrieve(
        |                     |                            |   |   'pi_xxx'
        |                     | Get status <---------------|<--| ) -> status: 'succeeded'
        |                     |                            |   |
        |                     | Verify status === succeeded|   |
        |                     | (reject if not)            |   |
        |                     +----+-----------------------+   |
        |                          |                          |
        |                     +----+----------------------+   |
        |                     | Backend Step 2:            |   |
        |                     | Verify ownership           |   |
        |                     |                            |   |
        |                     | metadata.user_id must      |   |
        |                     | match authenticated user   |   |
        |                     | (prevents fraud)           |   |
        |                     +----+-----------------------+   |
        |                          |                          |
        |                     +----+----------------------+   |
        |                     | Backend Step 3:            |   |
        |                     | Check duplicate             |   |
        |                     |                            |   |
        |                     | Payment::where(            |   |
        |                     |   payment_intent_id_index, |   |
        |                     |   blindIndex(pi_xxx)       |   |
        |                     | )                          |   |
        |                     |                            |   |
        |                     | If exists -> return it     |   |
        |                     | (idempotent, no duplicate) |   |
        |                     +----+-----------------------+   |
        |                          |                          |
        |                     +----+----------------------+   |
        |                     | Backend Step 4:            |   |
        |                     | Create Payment record      |   |
        |                     |                            |   |
        |                     | Payment::create([          |   |
        |                     |   user_id,                 |   |
        |                     |   payment_intent_id (enc), |   |
        |                     |   amount (from PI / 100),  |   |
        |                     |   currency,                |   |
        |                     |   status: 'succeeded',     |   |
        |                     |   paid_at: now(),          |   |
        |                     |   stripe_data (enc)        |   |
        |                     | ])                         |   |
        |                     +----+-----------------------+   |
        |                          |                          |
        |                     +----+----------------------+   |
        |                     | Backend Step 5:            |   |
        |                     | Create PaymentHold         |   |
        |                     | (SAME as current)          |   |
        |                     |                            |   |
        |                     | PaymentHoldService         |   |
        |                     |   ::createFromPayment(     |   |
        |                     |     $payment,              |   |
        |                     |     holdPeriodData from    |   |
        |                     |     PI metadata            |   |
        |                     |   )                        |   |
        |                     |                            |   |
        |                     | Creates:                   |   |
        |                     |   status: 'holding'        |   |
        |                     |   hold_start_at            |   |
        |                     |   hold_end_at              |   |
        |                     |   hold_days                |   |
        |                     |   remaining_amount = amount|   |
        |                     +----+-----------------------+   |
        |                          |                          |
        |                     +----+----------------------+   |
        |                     | Backend Step 6:            |   |
        |                     | Send notification          |   |
        |                     | (SAME as current)          |   |
        |                     |                            |   |
        |                     | Check user notification    |   |
        |                     | settings:                  |   |
        |                     |   transaction_alert AND    |   |
        |                     |   email_alert              |   |
        |                     |                            |   |
        |                     | If yes -> dispatch         |   |
        |                     |   SendPaymentSuccess       |   |
        |                     |   Notification job         |   |
        |                     +----+-----------------------+   |
        |                          |                          |
        |  Response JSON:          |                          |
        |  {                       |                          |
        |    "success": true,      |                          |
        |    "data": {             |                          |
        |      "payment": {        |                          |
        |        "id": 1,          |                          |
        |        "amount": 50.00,  |                          |
        |        "currency": "usd",|                          |
        |        "status":         |                          |
        |          "succeeded",    |                          |
        |        "paid_at": "..."  |                          |
        |      },                  |                          |
        |      "hold": {           |                          |
        |        "id": 1,          |                          |
        |        "amount": 50.00,  |                          |
        |        "status":         |                          |
        |          "holding",      |                          |
        |        "hold_start_at":  |                          |
        |          "2026-04-22",   |                          |
        |        "hold_end_at":    |                          |
        |          "2026-05-22",   |                          |
        |        "hold_days": 30,  |                          |
        |        "title": "Savings"|                          |
        |      }                   |                          |
        |    }                     |                          |
        |  }                       |                          |
        |<-------------------------|                          |
        |                          |                          |
        |  DONE!                   |                          |
        |  Show success screen     |                          |
        |                          |                          |
```

### After Payment - Everything Same as Current

```
AFTER PAYMENT - ZERO CHANGES FROM CURRENT FLOW:
=================================================

  [PaymentHold created: status='holding']
             |
             v
  +----------------------------------------------------------+
  |  CRON: check:payment-holds (every 5 minutes)              |
  |  File: app/Console/Commands/CheckPaymentHolds.php         |
  |  NO CHANGES                                               |
  |                                                           |
  |  1. Query: PaymentHold where status='holding'             |
  |            AND hold_end_at <= now()                        |
  |  2. Update: status -> 'ready_for_transfer'                |
  |             ready_at -> now()                              |
  |  3. Email: SendHoldPeriodEndedNotification per hold       |
  |  4. Auto-transfer if config enabled                       |
  +----------------------------------------------------------+
             |
             v
  [PaymentHold: status='ready_for_transfer']
             |
             v
  +----------------------------------------------------------+
  |  TRANSFER/PAYOUT - NO CHANGES                             |
  |                                                           |
  |  Option A: User requests payout for single hold           |
  |    POST /api/payment-holds/{id}/request-payout  (SAME)    |
  |                                                           |
  |  Option B: User withdraws specific amount                 |
  |    POST /api/payment-holds/withdraw  (SAME)               |
  |                                                           |
  |  Option C: Admin executes transfer                        |
  |    POST /admin/transfers/{hold}/execute  (SAME)           |
  +----------------------------------------------------------+
             |
             v
  [PaymentHold: status='transferred' / 'partial_transferred']
             |
             v
  +----------------------------------------------------------+
  |  CRON: verify:pending-transfers (every 5 minutes)         |
  |  NO CHANGES                                               |
  +----------------------------------------------------------+
             |
             v
  Email notifications sent (SAME as current)
```

---

## 4. Stripe Customer & Saved Cards

### How Card Saving Works

```
FIRST PAYMENT (New User):
==========================

  1. Backend: StripeCustomer exists for user? -> NO
  2. Backend: Stripe\Customer::create({ email, name, metadata })
  3. Backend: Store cus_abc123 in stripe_customers table (encrypted)
  4. Backend: Create PaymentIntent with:
       customer: cus_abc123
       setup_future_usage: 'off_session'   <-- THIS saves the card
  5. Flutter: Payment Sheet shows empty card form
  6. User: Enters card 4242424242424242
  7. Stripe: Saves card to Customer cus_abc123 automatically

SECOND PAYMENT (Returning User):
==================================

  1. Backend: StripeCustomer exists for user? -> YES (cus_abc123)
  2. Backend: SKIP Customer creation (reuse existing)
  3. Backend: Create new EphemeralKey + PaymentIntent (same customer)
  4. Flutter: Payment Sheet shows:
       +--------------------------------+
       | Saved payment methods:          |
       | * Visa **** 4242  12/28   [v]  |  <- Previously saved
       | o MC   **** 5556  03/29        |  <- Previously saved
       |                                |
       | + Add new card                 |
       |  Apple Pay                    |
       |  Google Pay                   |
       +--------------------------------+
  5. User: Taps saved card -> instant payment (no re-entering)
```

### Where Card Data Lives

```
  +-------------------------------------------+     +-----------------------------------------------+
  | Our Database                              |     | Our Database                                  |
  | (stripe_customers table)                  |     | (payments table — card detail columns)         |
  |                                           |     |                                               |
  | We store:                                 |     | We store per payment:                          |
  |   stripe_customer_id: cus_abc123 (enc.)   |     |   card_brand:          'visa'                  |
  |   stripe_customer_id_index: HMAC hash     |     |   card_last4:          '4242'                  |
  |   stripe_data: { customer object } (enc.) |     |   card_exp_month:      12                      |
  |                                           |     |   card_exp_year:       2028                    |
  | We NEVER store:                           |     |   card_funding:        'credit'                |
  |   Full card numbers                       |     |   card_country:        'US'                    |
  |   CVV                                     |     |   payment_method_type: 'card'                  |
  |   Full card details                       |     |                                               |
  +-------------------------------------------+     | We NEVER store:                                |
             |                                       |   Full card numbers                            |
             | References (cus_abc123)                |   CVV                                          |
             v                                       +-----------------------------------------------+
  +-------------------------------------------+
  | Stripe's Servers (PCI-compliant)          |
  |                                           |
  | Customer: cus_abc123                      |
  |   PaymentMethod: pm_xxx1                  |
  |     brand: visa                           |
  |     last4: 4242                           |
  |     exp: 12/28                            |
  |   PaymentMethod: pm_xxx2                  |
  |     brand: mastercard                     |
  |     last4: 5556                           |
  |     exp: 03/29                            |
  +-------------------------------------------+
```

### New Table: stripe_customers

```sql
CREATE TABLE stripe_customers (
    id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id                  BIGINT UNSIGNED NOT NULL UNIQUE,
    stripe_customer_id       TEXT NOT NULL,          -- encrypted (AES-256-CBC)
    stripe_customer_id_index VARCHAR(64) NOT NULL,   -- HMAC-SHA256 blind index
    stripe_data              TEXT NULL,              -- encrypted:array
    created_at               TIMESTAMP NULL,
    updated_at               TIMESTAMP NULL,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_customer_id_index (stripe_customer_id_index)
);

-- Same encryption pattern as payments, transfers, stripe_connect_accounts tables
-- One row per user (UNIQUE on user_id)
```

---

## 5. Card Details Storage for Admin Panel

When a payment succeeds, Stripe returns card details inside the PaymentIntent object. We store **safe, non-sensitive** card metadata in dedicated columns on the `payments` table so the admin panel can easily display them.

### What We Store vs What We NEVER Store

```
  SAFE TO STORE (non-sensitive, shown on receipts everywhere):
  ============================================================
  +-------------------------+-----------+--------------+------------------------------+
  | Column                  | Type      | Example      | Purpose                      |
  +-------------------------+-----------+--------------+------------------------------+
  | card_brand              | VARCHAR   | visa         | Card brand icon in admin     |
  |                         |           | mastercard   |                              |
  |                         |           | amex         |                              |
  |                         |           | discover     |                              |
  +-------------------------+-----------+--------------+------------------------------+
  | card_last4              | VARCHAR(4)| 4242         | Show **** 4242 in admin      |
  +-------------------------+-----------+--------------+------------------------------+
  | card_exp_month          | TINYINT   | 12           | Show 12/28 in admin          |
  +-------------------------+-----------+--------------+------------------------------+
  | card_exp_year           | SMALLINT  | 2028         | Show 12/28 in admin          |
  +-------------------------+-----------+--------------+------------------------------+
  | card_funding            | VARCHAR   | credit       | Credit / Debit / Prepaid     |
  |                         |           | debit        |                              |
  |                         |           | prepaid      |                              |
  +-------------------------+-----------+--------------+------------------------------+
  | card_country            | VARCHAR(2)| US           | Card issuing country         |
  |                         |           | GB           |                              |
  +-------------------------+-----------+--------------+------------------------------+
  | payment_method_type     | VARCHAR   | card         | How user paid                |
  |                         |           | apple_pay    |                              |
  |                         |           | google_pay   |                              |
  +-------------------------+-----------+--------------+------------------------------+

  NEVER STORED (PCI violation):
  =============================
  +-------------------------+--------------------------------------------------+
  | Data                    | Why                                              |
  +-------------------------+--------------------------------------------------+
  | Full card number        | PCI violation, illegal to store                  |
  | (4242 4242 4242 4242)   |                                                  |
  +-------------------------+--------------------------------------------------+
  | CVV (123)               | PCI violation, Stripe never returns this         |
  +-------------------------+--------------------------------------------------+
```

### Where This Data Comes From

```
  In PaymentSheetService::confirmPayment():
  ==========================================

  // Retrieve PaymentIntent with expanded charge details
  $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId, [
      'expand' => ['latest_charge.payment_method_details'],
  ]);

  // Extract card details from latest charge
  $charge      = $paymentIntent->latest_charge;
  $cardDetails = $charge->payment_method_details->card ?? null;
  $walletType  = $cardDetails->wallet->type ?? null;

  // Available data:
  $cardBrand         = $cardDetails->brand;        // 'visa'
  $cardLast4         = $cardDetails->last4;         // '4242'
  $cardExpMonth      = $cardDetails->exp_month;     // 12
  $cardExpYear       = $cardDetails->exp_year;      // 2028
  $cardFunding       = $cardDetails->funding;       // 'credit'
  $cardCountry       = $cardDetails->country;       // 'US'
  $paymentMethodType = $walletType ?? 'card';       // 'card' / 'apple_pay' / 'google_pay'

  // Store in Payment record alongside existing fields:
  Payment::create([
      // ... existing fields (user_id, payment_intent_id, amount, etc.)
      'card_brand'          => $cardBrand,            // NEW
      'card_last4'          => $cardLast4,            // NEW
      'card_exp_month'      => $cardExpMonth,         // NEW
      'card_exp_year'       => $cardExpYear,          // NEW
      'card_funding'        => $cardFunding,          // NEW
      'card_country'        => $cardCountry,          // NEW
      'payment_method_type' => $paymentMethodType,    // NEW
  ]);
```

### Admin Panel Display

```
  +-----------------------------------------------------------------------+
  |  ADMIN PANEL > Payments                                                |
  +------+----------+--------+--------------+--------+--------+-----------+
  | ID   | User     | Amount | Card         | Method | Date   | Status    |
  +------+----------+--------+--------------+--------+--------+-----------+
  | #1   | John Doe | $50.00 | Visa ****4242| Card   | 4/22   | Succeeded |
  |      |          |        | 12/28 Credit | US     |        |           |
  +------+----------+--------+--------------+--------+--------+-----------+
  | #2   | Jane Doe | $75.00 | MC ****5556  | Card   | 4/21   | Succeeded |
  |      |          |        | 03/29 Debit  | GB     |        |           |
  +------+----------+--------+--------------+--------+--------+-----------+
  | #3   | Bob Lee  | $30.00 |  Apple Pay   | Wallet | 4/20   | Succeeded |
  +------+----------+--------+--------------+--------+--------+-----------+
  | #4   | Sam Kim  | $100   |  Google Pay  | Wallet | 4/19   | Succeeded |
  +------+----------+--------+--------------+--------+--------+-----------+

  For Apple Pay / Google Pay:
    card_brand          = null (or underlying card brand if available)
    card_last4          = null (or underlying card last4 if available)
    payment_method_type = 'apple_pay' / 'google_pay'
```

### Migration: Add Card Columns to payments Table

```
File: database/migrations/xxxx_add_card_details_to_payments_table.php

  Schema::table('payments', function (Blueprint $table) {
      $table->string('card_brand')->nullable()->after('stripe_data');
      $table->string('card_last4', 4)->nullable()->after('card_brand');
      $table->unsignedTinyInteger('card_exp_month')->nullable()->after('card_last4');
      $table->unsignedSmallInteger('card_exp_year')->nullable()->after('card_exp_month');
      $table->string('card_funding')->nullable()->after('card_exp_year');
      $table->string('card_country', 2)->nullable()->after('card_funding');
      $table->string('payment_method_type')->nullable()->after('card_country');
  });

  All columns nullable because:
    - Existing payments don't have this data
    - Failed/canceled payments may not have card details
    - Apple Pay/Google Pay may not expose underlying card
```

### Payment Model Update

```
File: app/Models/Payment.php

  Add to $fillable:
    'card_brand',
    'card_last4',
    'card_exp_month',
    'card_exp_year',
    'card_funding',
    'card_country',
    'payment_method_type',

  Add to $casts:
    'card_exp_month' => 'integer',
    'card_exp_year'  => 'integer',

  Note: These fields are NOT encrypted because they are
  non-sensitive data (last 4 digits, brand, country).
  Full card numbers are NEVER stored.
```

---

## 6. API Changes Summary

### Routes to REMOVE from api.php

```php
// REMOVE these from inside auth:sanctum group (lines 64-68):
Route::post('/connect/create', ...);           // Connect account creation
Route::post('/connect/onboarding-link', ...);  // Onboarding URL

// REMOVE these from inside auth:sanctum group (lines 67-68):
Route::post('/payment-intent', ...);           // Old Checkout Session
Route::post('/verify-payment', ...);           // Old session verify

// REMOVE these callback routes (lines 94-107):
Route::get('/stripe/connect/return', ...);     // Connect OAuth callback
Route::get('/stripe/payment/return', ...);     // Checkout redirect back
```

### Routes to REMOVE from web.php

```php
// REMOVE these Stripe redirect routes (lines 18-42):
Route::get('/stripe/return', ...);    // Connect onboarding success redirect
Route::get('/stripe/reauth', ...);    // Connect re-auth redirect
```

### Routes to ADD to api.php

```php
// ADD inside auth:sanctum middleware group, under stripe prefix:
Route::post('/stripe/create-payment-intent',
    [PaymentSheetController::class, 'createPaymentIntent'])
    ->name('stripe.create-payment-intent');

Route::post('/stripe/confirm-payment',
    [PaymentSheetController::class, 'confirmPayment'])
    ->name('stripe.confirm-payment');
```

### All Routes After Migration

```
PUBLIC (No Auth):
  POST /api/auth/check-email              (SAME)
  POST /api/auth/signup                   (SAME)
  POST /api/auth/verify-otp              (SAME)
  POST /api/auth/resend-otp              (SAME)
  POST /api/auth/login                   (SAME)
  POST /api/auth/forgot-password         (SAME)
  POST /api/auth/reset-password          (SAME)
  GET  /api/privacy-policy               (SAME)

PROTECTED (Sanctum Auth):
  POST /api/auth/logout                  (SAME)
  POST /api/auth/delete-account          (SAME)
  GET  /api/profile                      (SAME)
  POST /api/profile/update               (SAME)
  POST /api/profile/change-password      (SAME)
  POST /api/profile/language             (SAME)
  POST /api/profile/timezone             (SAME)
  GET  /api/notification-settings        (SAME)
  POST /api/notification-settings/update (SAME)
  GET  /api/refer-friend-url             (SAME)

  POST /api/stripe/create-payment-intent (NEW - replaces payment-intent)
  POST /api/stripe/confirm-payment       (NEW - replaces verify-payment)

  GET  /api/payment-holds                (SAME)
  GET  /api/payment-holds/summary        (SAME)
  POST /api/payment-holds/{id}/request-payout  (SAME)
  POST /api/payment-holds/withdraw       (SAME)
  GET  /api/transactions/all             (SAME)
  GET  /api/transactions/checkouts       (SAME)
  GET  /api/transactions/withdraws       (SAME)
  GET  /api/transactions/hold-amounts    (SAME)
  GET  /api/transactions/ready-for-transfer (SAME)
  POST /api/admin/transfer/{hold_id}     (SAME)

STRIPE CALLBACKS (No Auth):
  POST /api/stripe/webhook               (SAME)

REMOVED:
  POST /api/stripe/connect/create           (REMOVED)
  POST /api/stripe/connect/onboarding-link  (REMOVED)
  POST /api/stripe/payment-intent           (REMOVED - replaced)
  POST /api/stripe/verify-payment           (REMOVED - replaced)
  GET  /api/stripe/connect/return           (REMOVED)
  GET  /api/stripe/payment/return           (REMOVED)
  GET  /stripe/return                       (REMOVED from web.php)
  GET  /stripe/reauth                       (REMOVED from web.php)

ADMIN PANEL (All SAME):
  GET  /admin/login                      (SAME)
  POST /admin/login                      (SAME)
  POST /admin/logout                     (SAME)
  GET  /admin/dashboard                  (SAME)
  GET  /admin/dashboard/stats            (SAME)
  GET  /admin/users                      (SAME)
  GET  /admin/users/{user}               (SAME)
  GET  /admin/users/stats                (SAME)
  GET  /admin/payments                   (SAME)
  GET  /admin/payments/stats             (SAME)
  GET  /admin/payment-holds              (SAME)
  GET  /admin/payment-holds/stats        (SAME)
  GET  /admin/transfers                  (SAME)
  GET  /admin/transfers/stats            (SAME)
  POST /admin/transfers/{hold}/execute   (SAME)
  GET  /admin/privacy-policy             (SAME)
  POST /admin/privacy-policy             (SAME)
  GET  /admin/profile                    (SAME)
  POST /admin/profile/update             (SAME)
  POST /admin/profile/change-password    (SAME)
```

---

## 7. Backend Implementation — Step by Step

### Step 1: Migration — stripe_customers table

```
File: database/migrations/xxxx_create_stripe_customers_table.php

  Schema::create('stripe_customers', function (Blueprint $table) {
      $table->id();
      $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
      $table->text('stripe_customer_id');               // encrypted
      $table->string('stripe_customer_id_index', 64);   // HMAC blind index
      $table->text('stripe_data')->nullable();           // encrypted:array
      $table->timestamps();

      $table->index('stripe_customer_id_index', 'idx_customer_id_index');
  });
```

### Step 2: Model — StripeCustomer.php

```
File: app/Models/StripeCustomer.php

  Same pattern as Payment.php model:
  - Encrypted casts: stripe_customer_id, stripe_data (array)
  - Hidden: stripe_customer_id_index
  - blindIndex() static method (HMAC-SHA256 with APP_KEY)
  - booted() auto-syncs stripe_customer_id_index on save
  - belongsTo(User)
```

### Step 3: Service — PaymentSheetService.php

```
File: app/Services/PaymentSheetService.php

  4 methods:

  +------------------------------------------+-----------------------------------+
  | Method                                   | What It Does                      |
  +------------------------------------------+-----------------------------------+
  | getOrCreateCustomer(User $user)          | Check DB -> if not found,         |
  |                                          | Stripe\Customer::create() ->      |
  |                                          | store encrypted -> return model   |
  +------------------------------------------+-----------------------------------+
  | createEphemeralKey(string $customerId)    | Stripe\EphemeralKey::create() ->  |
  |                                          | return secret string              |
  +------------------------------------------+-----------------------------------+
  | createPaymentIntent(User $user, $data)   | 1. getOrCreateCustomer()          |
  |                                          | 2. createEphemeralKey()           |
  |                                          | 3. PaymentIntent::create() with   |
  |                                          |    customer, metadata, future_use |
  |                                          | 4. Return 4 values Flutter needs  |
  +------------------------------------------+-----------------------------------+
  | confirmPayment(string $piId, User $user) | 1. PaymentIntent::retrieve()      |
  |                                          |    (expand latest_charge)         |
  |                                          | 2. Verify status === 'succeeded'  |
  |                                          | 3. Verify user ownership          |
  |                                          | 4. Check duplicate (blind index)  |
  |                                          | 5. Extract card details from      |
  |                                          |    latest_charge (brand, last4,   |
  |                                          |    exp, funding, country, type)   |
  |                                          | 6. Create Payment record          |
  |                                          |    (with card detail columns)     |
  |                                          | 7. Create PaymentHold (via        |
  |                                          |    PaymentHoldService)            |
  |                                          | 8. Dispatch email notification    |
  +------------------------------------------+-----------------------------------+

  PaymentIntent::create() parameters:
  {
    amount:                    in cents (50.00 -> 5000)
    currency:                  'usd' / 'eur' / 'gbp'
    customer:                  cus_abc123
    setup_future_usage:        'off_session'        <- saves card
    automatic_payment_methods: { enabled: true }    <- card + Apple + Google Pay
    metadata: {
      user_id:            '5'
      hold_period_type:   'custom'
      hold_start_at:      '2026-04-22'
      hold_end_at:        '2026-05-22'
      title:              'Savings'
    }
  }
```

### Step 4: Controller — PaymentSheetController.php

```
File: app/Http/Controllers/Api/PaymentSheetController.php

  2 endpoints:

  createPaymentIntent(CreatePaymentIntentRequest $request)
  ─────────────────────────────────────────────────────────
    Input:  { amount, currency, hold_period_type,
              hold_start_at, hold_end_at, title }
    Calls:  PaymentSheetService::createPaymentIntent()
    Output: { payment_intent_id, client_secret,
              customer_id, ephemeral_key, publishable_key,
              amount, currency }

  confirmPayment(ConfirmPaymentRequest $request)
  ───────────────────────────────────────────────
    Input:  { payment_intent_id: "pi_xxx" }
    Calls:  PaymentSheetService::confirmPayment()
    Output: { payment: {...}, hold: {...} }
```

### Step 5: Validation Requests

```
File: app/Http/Requests/Payment/CreatePaymentIntentRequest.php

  Rules:
    amount:           required | numeric | min:0.50
    currency:         required | string | in:usd,eur,gbp
    hold_period_type: required | string | in:1_month,2_months,6_months,1_year,custom
    hold_start_at:    required | date
    hold_end_at:      required | date | after:hold_start_at
    title:            nullable | string | max:255

File: app/Http/Requests/Payment/ConfirmPaymentRequest.php

  Rules:
    payment_intent_id: required | string | starts_with:pi_
```

### Step 6: Update routes/api.php

```
  ADD 2 new routes (inside auth:sanctum group, under stripe prefix)
  REMOVE 4 Connect/Checkout routes
  REMOVE 2 callback routes
```

### Step 7: Update routes/web.php

```
  REMOVE /stripe/return route (lines 18-29)
  REMOVE /stripe/reauth route (lines 31-42)
```

### Step 8: Update User model

```
File: app/Models/User.php

  ADD relationship:
    public function stripeCustomer()
    {
        return $this->hasOne(StripeCustomer::class);
    }
```

### Step 9: Update config/services.php

```
  ADD inside 'stripe' array:
    'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
```

### Step 10: Update .env

```
  ADD:
    STRIPE_PUBLISHABLE_KEY=pk_live_xxxxxxx
```

### Summary: All Files

```
NEW FILES (7):
  1. database/migrations/xxxx_create_stripe_customers_table.php
  2. database/migrations/xxxx_add_card_details_to_payments_table.php
  3. app/Models/StripeCustomer.php
  4. app/Services/PaymentSheetService.php
  5. app/Http/Controllers/Api/PaymentSheetController.php
  6. app/Http/Requests/Payment/CreatePaymentIntentRequest.php
  7. app/Http/Requests/Payment/ConfirmPaymentRequest.php

MODIFIED FILES (5):
  8.  routes/api.php         -> Add 2 routes, remove 6 routes
  9.  routes/web.php         -> Remove 2 Stripe redirect routes
  10. app/Models/User.php    -> Add stripeCustomer() relationship
  11. app/Models/Payment.php  -> Add card detail fields to $fillable + $casts
  12. config/services.php    -> Add publishable_key

TOTAL: 7 new + 5 modified = 12 file changes
EVERYTHING ELSE: ZERO CHANGES
```

---

## 8. Flutter Integration Guide

### Required Package

```yaml
# pubspec.yaml
dependencies:
  flutter_stripe: ^11.0.0    # Stripe Payment Sheet SDK
```

### Stripe Initialization (app startup)

```dart
// In main.dart or app initialization
Stripe.publishableKey = 'pk_live_xxxxxxx';  // From backend config or hardcode
Stripe.merchantIdentifier = 'merchant.com.timevault';  // For Apple Pay
```

### Payment Flow — Complete Flutter Code

```dart
Future<void> makePayment({
  required double amount,
  required String currency,
  required String holdPeriodType,
  required String holdStartAt,
  required String holdEndAt,
  String? title,
}) async {
  try {
    // ── STEP 1: Call backend to create PaymentIntent ──
    final response = await apiClient.post(
      '/stripe/create-payment-intent',
      data: {
        'amount': amount,
        'currency': currency,
        'hold_period_type': holdPeriodType,
        'hold_start_at': holdStartAt,
        'hold_end_at': holdEndAt,
        'title': title,
      },
    );

    final data = response.data['data'];
    final clientSecret = data['client_secret'];
    final customerId = data['customer_id'];
    final ephemeralKey = data['ephemeral_key'];
    final paymentIntentId = data['payment_intent_id'];

    // ── STEP 2: Initialize Payment Sheet ──
    await Stripe.instance.initPaymentSheet(
      paymentSheetParameters: SetupPaymentSheetParameters(
        paymentIntentClientSecret: clientSecret,
        customerId: customerId,
        customerEphemeralKeySecret: ephemeralKey,
        merchantDisplayName: 'Time Vault',
        applePay: const PaymentSheetApplePay(
          merchantCountryCode: 'US',
        ),
        googlePay: const PaymentSheetGooglePay(
          merchantCountryCode: 'US',
          testEnv: true,  // Set false in production
        ),
        style: ThemeMode.system,
      ),
    );

    // ── STEP 3: Present Payment Sheet ──
    // Shows native UI with saved cards, new card, Apple/Google Pay
    // THROWS StripeException if user cancels or payment fails
    await Stripe.instance.presentPaymentSheet();

    // ── STEP 4: Confirm with backend ──
    // Only reaches here if payment succeeded
    final confirmResponse = await apiClient.post(
      '/stripe/confirm-payment',
      data: {
        'payment_intent_id': paymentIntentId,
      },
    );

    if (confirmResponse.data['success'] == true) {
      // ── STEP 5: Show success ──
      final hold = confirmResponse.data['data']['hold'];
      // Navigate to success screen
    }

  } on StripeException catch (e) {
    if (e.error.code == FailureCode.Canceled) {
      // User cancelled — do nothing
      return;
    }
    // Show error: e.error.localizedMessage
  } catch (e) {
    // Show error: e.toString()
  }
}
```

### Flutter Files to Remove

```
REMOVE (Connect Account related):
  - Stripe Connect onboarding screen/widget
  - Stripe Connect status screen/widget
  - WebView for Stripe Checkout Session
  - API calls to /api/stripe/connect/create
  - API calls to /api/stripe/connect/onboarding-link
  - API calls to /api/stripe/payment-intent (old)
  - API calls to /api/stripe/verify-payment (old)
```

### Flutter Files to Modify

```
MODIFY:
  - Payment screen: Replace Checkout Session with Payment Sheet code above
  - API client: Update endpoint URLs
    OLD: POST /api/stripe/payment-intent    -> NEW: POST /api/stripe/create-payment-intent
    OLD: POST /api/stripe/verify-payment    -> NEW: POST /api/stripe/confirm-payment
  - App startup: Add Stripe.publishableKey initialization
```

---

## 9. Webhook Changes

```
KEEP (Already working, no changes needed):
==========================================

  +-----------------------------------+-------------------------------+
  | Event                             | Handler                       |
  +-----------------------------------+-------------------------------+
  | payment_intent.succeeded          | handlePaymentIntentSucceeded  |
  |                                   | Works with Payment Sheet      |
  |                                   | PaymentIntents (same event)   |
  +-----------------------------------+-------------------------------+
  | payment_intent.payment_failed     | handlePaymentIntentFailed     |
  |                                   | Same                          |
  +-----------------------------------+-------------------------------+
  | payment_intent.canceled           | handlePaymentIntentCanceled   |
  |                                   | Same                          |
  +-----------------------------------+-------------------------------+
  | checkout.session.completed        | handleCheckoutSessionCompleted|
  |                                   | Keep for backward compat      |
  |                                   | (won't fire for new payments) |
  +-----------------------------------+-------------------------------+

  WHY webhooks work without changes:
  -----------------------------------
  Payment Sheet creates a PaymentIntent (same as current).
  Stripe fires the SAME webhook events for PaymentIntents
  regardless of whether they came from Checkout Session or
  Payment Sheet. So all existing handlers work as-is.

ALREADY NOT WIRED (exist in code but not connected):
=====================================================
  handleAccountUpdated()      -> Not wired to webhook handler
  handleTransferCreated()     -> Not wired to webhook handler
  handleTransferFailed()      -> Not wired to webhook handler
  handleTransferCanceled()    -> Not wired to webhook handler

  These can be cleaned up later but are harmless.
```

---

## 10. Configuration Changes

### .env Changes

```
ADD:
  STRIPE_PUBLISHABLE_KEY=pk_live_xxxxxxx       <- Required for Payment Sheet

KEEP (unchanged):
  STRIPE_KEY=sk_live_xxxxxxx
  STRIPE_SECRET=sk_live_xxxxxxx
  STRIPE_WEBHOOK_SECRET=whsec_xxxxxxx

CAN REMOVE LATER (currently unused after migration):
  STRIPE_CONNECT_RETURN_URL=...
  STRIPE_PAYMENT_RETURN_URL=...
  STRIPE_PAYMENT_SUCCESS_URL=...
  STRIPE_PAYMENT_FAILED_URL=...
```

### config/services.php Changes

```php
'stripe' => [
    'key' => env('STRIPE_KEY'),                         // Keep
    'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'), // ADD THIS
    'secret' => env('STRIPE_SECRET'),                   // Keep
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),   // Keep

    'supported_currencies' => [                          // Keep
        'usd' => 'USD',
        'eur' => 'EUR',
        'gbp' => 'GBP',
    ],

    // These become unused after migration but won't cause errors:
    'auto_transfer_enabled' => env('STRIPE_AUTO_TRANSFER', false),
    'connect_return_url' => env('STRIPE_CONNECT_RETURN_URL', '...'),
    'payment_return_url' => env('STRIPE_PAYMENT_RETURN_URL', '...'),
    'payment_success_url' => env('STRIPE_PAYMENT_SUCCESS_URL', '...'),
    'payment_failed_url' => env('STRIPE_PAYMENT_FAILED_URL', '...'),
],
```

---

## 11. Security Considerations

```
+----------------------------+---------------------------+---------------------------+
| Area                       | Current (Checkout Session)| New (Payment Sheet)       |
+----------------------------+---------------------------+---------------------------+
| Full card number           | Not stored                | Not stored — NEVER        |
| (4242 4242 4242 4242)      |                           | touches our server.       |
|                            |                           | Payment Sheet SDK sends   |
|                            |                           | card directly to Stripe.  |
+----------------------------+---------------------------+---------------------------+
| CVV                        | Not stored                | Not stored — Stripe       |
|                            |                           | never returns CVV.        |
+----------------------------+---------------------------+---------------------------+
| Card last 4 digits         | Not stored                | STORED in payments table  |
| (4242)                     |                           | card_last4 column.        |
|                            |                           | Safe: shown on receipts,  |
|                            |                           | non-sensitive.            |
+----------------------------+---------------------------+---------------------------+
| Card brand                 | Not stored                | STORED in payments table  |
| (visa, mastercard)         |                           | card_brand column.        |
|                            |                           | Safe: public info.        |
+----------------------------+---------------------------+---------------------------+
| Card expiry                | Not stored                | STORED in payments table  |
| (12/28)                    |                           | card_exp_month/year.      |
|                            |                           | Safe: useless without     |
|                            |                           | full card number.         |
+----------------------------+---------------------------+---------------------------+
| Card funding type          | Not stored                | STORED in payments table  |
| (credit/debit/prepaid)     |                           | card_funding column.      |
+----------------------------+---------------------------+---------------------------+
| Card country               | Not stored                | STORED in payments table  |
| (US, GB)                   |                           | card_country column.      |
+----------------------------+---------------------------+---------------------------+
| Payment method type        | Not stored                | STORED in payments table  |
| (card/apple_pay/google_pay)|                           | payment_method_type.      |
+----------------------------+---------------------------+---------------------------+
| Stripe Customer ID         | N/A (no customer)         | Encrypted (AES-256-CBC)   |
|                            |                           | + blind index (HMAC-256)  |
|                            |                           | Same pattern as           |
|                            |                           | payment_intent_id         |
+----------------------------+---------------------------+---------------------------+
| Payment verification       | Server-side via           | Server-side via           |
|                            | session_id                | payment_intent_id         |
|                            |                           | ALWAYS verify status on   |
|                            |                           | backend (never trust      |
|                            |                           | client-side success)      |
+----------------------------+---------------------------+---------------------------+
| Idempotency                | payment_intent_id         | Same — blind index        |
|                            | blind index               | prevents duplicate        |
|                            |                           | Payment records           |
+----------------------------+---------------------------+---------------------------+
| Webhook verification       | Stripe signature          | Same — constructEvent()   |
+----------------------------+---------------------------+---------------------------+
| Rate limiting              | Current throttle config   | create-payment-intent:    |
|                            |                           | 10/min                    |
|                            |                           | confirm-payment: 10/min   |
+----------------------------+---------------------------+---------------------------+
| 3D Secure (SCA)            | Stripe handles on         | Stripe SDK handles        |
|                            | checkout page              | automatically in          |
|                            |                           | Payment Sheet             |
+----------------------------+---------------------------+---------------------------+
```

---

## 12. What Stays Unchanged

### Complete List of Untouched Components

```
MODELS (no changes):
  User.php                    (add 1 relationship only)
  Payment.php                 (add card detail fields to $fillable + $casts)
  PaymentHold.php
  Transfer.php
  StripeConnectAccount.php    (keep for existing data)
  StripeWebhookEvent.php
  UserNotificationSetting.php
  PrivacyPolicy.php
  PasswordResetToken.php

SERVICES (no changes):
  StripeService.php           (keep all methods — still used for transfers)
  PaymentHoldService.php
  WebhookService.php

CONTROLLERS (no changes):
  Admin/DashboardController.php
  Admin/PaymentController.php
  Admin/PaymentHoldController.php
  Admin/TransferController.php
  Admin/UserController.php
  Admin/ProfileController.php
  Admin/PrivacyPolicyController.php
  Admin/Auth/AdminLoginController.php
  Api/AuthController.php
  Api/ProfileController.php
  Api/NotificationController.php
  Api/PaymentHoldController.php
  Api/TransactionHistoryController.php
  Api/PrivacyPolicyController.php
  Api/AppConfigController.php
  Api/Admin/TransferController.php

JOBS (no changes):
  SendPaymentSuccessNotification.php
  SendPaymentFailedNotification.php
  SendHoldPeriodEndedNotification.php
  SendTransferCompletedNotification.php
  SendTransferCompletedSummaryNotification.php
  SendTransferFailedNotification.php
  SendPayoutRequestNotification.php
  SendWithdrawalSummaryNotification.php

MAIL (no changes):
  All 11 mailable classes

VIEWS (no changes):
  All admin views
  All email templates

COMMANDS (no changes):
  CheckPaymentHolds.php
  VerifyPendingTransfers.php
  CleanupUnverifiedAccounts.php
  EncryptExistingData.php
  FixHoldRemainingAmount.php

MIDDLEWARE (no changes):
  AdminMiddleware.php
  SecurityHeaders.php
  SanitizeInput.php
  LogApiRequests.php

MIGRATIONS (no changes):
  All 22 existing migrations

CRON SCHEDULE (no changes):
  check:payment-holds        every 5 minutes
  verify:pending-transfers   every 5 minutes
  users:cleanup-unverified   daily at 00:00 ET
```

### PaymentHold Status Lifecycle (UNCHANGED)

```
                                   check:payment-holds cron
  Payment succeeds --> [holding] -----------------------------> [ready_for_transfer]
                                   (hold_end_at <= now)              |
                                                                     |
                                           +-------------------------+
                                           |                         |
                                           v                         v
                              [partial_transferred]           [transferred]
                              (remaining_amount > 0)         (remaining_amount = 0)
                                           |
                                           v (more withdrawals)
                                      [transferred]
```

### Email Notification Matrix (UNCHANGED)

```
  +----------------------------+----------------------------------+-------------------+
  | Trigger                    | Job -> Mail -> Template           | Condition         |
  +----------------------------+----------------------------------+-------------------+
  | Payment succeeds           | SendPaymentSuccessNotification   | transaction_alert |
  |                            |   -> PaymentSuccessMail          | AND email_alert   |
  |                            |   -> stripe/payment-success      |                   |
  +----------------------------+----------------------------------+-------------------+
  | Payment fails              | SendPaymentFailedNotification    | email_alert       |
  |                            |   -> PaymentFailedMail           |                   |
  |                            |   -> stripe/payment-failed       |                   |
  +----------------------------+----------------------------------+-------------------+
  | Hold period ends           | SendHoldPeriodEndedNotification  | transaction_alert |
  |                            |   -> HoldPeriodEndedMail         | AND email_alert   |
  |                            |   -> stripe/hold-ended           |                   |
  +----------------------------+----------------------------------+-------------------+
  | User requests payout       | SendPayoutRequestNotification    | transaction_alert |
  |                            |   -> PayoutRequestMail           | AND email_alert   |
  |                            |   -> stripe/payout-request       |                   |
  +----------------------------+----------------------------------+-------------------+
  | Single transfer completes  | SendTransferCompletedNotification| transaction_alert |
  |                            |   -> TransferCompletedMail       | AND email_alert   |
  |                            |   -> stripe/transfer-completed   |                   |
  +----------------------------+----------------------------------+-------------------+
  | Multiple transfers         | SendTransferCompletedSummary     | transaction_alert |
  |                            |   NotificationNotification       | AND email_alert   |
  |                            |   -> TransferCompletedSummaryMail|                   |
  |                            |   -> stripe/transfer-completed-  |                   |
  |                            |      summary                     |                   |
  +----------------------------+----------------------------------+-------------------+
  | Transfer fails             | SendTransferFailedNotification   | email_alert       |
  |                            |   -> TransferFailedMail          |                   |
  |                            |   -> stripe/transfer-failed      |                   |
  +----------------------------+----------------------------------+-------------------+
  | Withdrawal summary         | SendWithdrawalSummaryNotification| transaction_alert |
  |                            |   -> WithdrawalSummaryMail       | AND email_alert   |
  |                            |   -> stripe/withdrawal-summary   |                   |
  +----------------------------+----------------------------------+-------------------+