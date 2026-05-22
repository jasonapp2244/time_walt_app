# Time Vault — Complete Payment Flow API Documentation

This document covers the full payment lifecycle: deposit & lock funds, add/manage bank accounts, change primary bank, and withdraw funds.

---

## Table of Contents

1. [Authentication (All Requests)](#1-authentication)
2. [Step 1 — Deposit & Lock Funds](#2-deposit--lock-funds)
3. [Step 2 — Add Bank Account (Card)](#3-add-bank-account)
4. [Step 3 — View Bank Accounts](#4-view-bank-accounts)
5. [Step 4 — Change Primary Bank Account](#5-change-primary-bank-account)
6. [Step 5 — Delete Bank Account](#6-delete-bank-account)
7. [Step 6 — View Payment Holds & Summary](#7-view-payment-holds--summary)
8. [Step 7 — Withdraw Funds](#8-withdraw-funds)
9. [Stripe Webhook (Server-to-Server)](#9-stripe-webhook)
10. [Complete Flow Diagram](#10-complete-flow-diagram)
11. [Error Reference](#11-error-reference)

---

## 1. Authentication

All protected endpoints require a **Bearer Token** (Laravel Sanctum).

**Header:**
```
Authorization: Bearer {sanctum_token}
Accept: application/json
Content-Type: application/json
```

Token is obtained via `POST /api/auth/login` or `POST /api/auth/verify-otp` and expires after **24 hours**.

---

## 2. Deposit & Lock Funds

Depositing funds is a two-step process: create a payment intent, then confirm it after the user completes payment in the mobile app.

### Step 1: Create Payment Intent

**Endpoint:** `POST /api/stripe/create-payment-intent`  
**Auth:** Required  
**Rate Limit:** 1000 req/min

**Request Body:**
```json
{
  "amount": 50.00,
  "currency": "usd",
  "hold_period_type": "custom",
  "hold_start_at": "2026-05-12",
  "hold_end_at": "2026-06-12",
  "title": "My Savings Goal"
}
```

**Field Validation:**

| Field | Type | Rules |
|-------|------|-------|
| `amount` | number | Required. Minimum: 1 |
| `currency` | string | Required. Exactly 3 chars. Allowed: `usd`, `eur`, `gbp`, `cad`, `aud`, `nzd`, `sgd`, `hkd`, `jpy`, `chf`, `dkk`, `nok`, `sek` |
| `hold_period_type` | string | Required. Value: `custom` |
| `hold_start_at` | date | Required. Must be today or later (`YYYY-MM-DD`) |
| `hold_end_at` | date | Required. Must be after `hold_start_at` (`YYYY-MM-DD`) |
| `title` | string | Optional. Max 255 characters |

**What Happens Behind the Scenes:**
1. Creates a Stripe Customer for the user (if first time)
2. Generates an Ephemeral Key for the Payment Sheet SDK
3. Creates a Stripe PaymentIntent with hold metadata embedded
4. Enables `setup_future_usage: off_session` to save card for future payments
5. Returns credentials for the mobile Payment Sheet SDK

**Success Response — 201:**
```json
{
  "success": true,
  "message": "Payment intent created. Use client_secret to present Payment Sheet.",
  "data": {
    "payment_intent_id": "pi_3ABC123DEF456",
    "client_secret": "pi_3ABC123DEF456_secret_XYZ789",
    "customer_id": "cus_ABC123",
    "ephemeral_key": "ek_live_ABC123",
    "publishable_key": "pk_test_ABC123",
    "amount": 50.00,
    "currency": "usd"
  }
}
```

**Error Response — 500:**
```json
{
  "success": false,
  "message": "Failed to create payment intent."
}
```

**Mobile App Integration:**
Use the returned `client_secret`, `customer_id`, `ephemeral_key`, and `publishable_key` to initialize the Stripe Payment Sheet SDK:

```dart
// Flutter Example
await Stripe.instance.initPaymentSheet(
  paymentSheetParameters: SetupPaymentSheetParameters(
    paymentIntentClientSecret: data['client_secret'],
    customerEphemeralKeySecret: data['ephemeral_key'],
    customerId: data['customer_id'],
    merchantDisplayName: 'Time Vault',
  ),
);
await Stripe.instance.presentPaymentSheet();
```

---

### Step 2: Confirm Payment

After the user completes payment in the Payment Sheet, confirm it on the backend.

**Endpoint:** `POST /api/stripe/confirm-payment`  
**Auth:** Required

**Request Body:**
```json
{
  "payment_intent_id": "pi_3ABC123DEF456"
}
```

**Field Validation:**

| Field | Type | Rules |
|-------|------|-------|
| `payment_intent_id` | string | Required. Must start with `pi_` |

**What Happens Behind the Scenes:**
1. Retrieves the PaymentIntent from Stripe (with expanded charge details)
2. Validates payment status is `succeeded`
3. Extracts card details (brand, last4, expiry, funding type, wallet info)
4. **Atomically** creates:
   - **Payment** record (amount, currency, status, card details)
   - **PaymentHold** record (amount locked, hold dates, status: `holding`)
5. Prevents duplicate recording via blind-index lookup on `payment_intent_id`
6. Dispatches async email notification (if user has notifications enabled)

**Success Response — 200:**
```json
{
  "success": true,
  "message": "Payment confirmed and records created successfully.",
  "data": {
    "payment": {
      "id": 123,
      "payment_intent_id": "pi_3ABC123DEF456",
      "amount": 50.00,
      "currency": "usd",
      "status": "succeeded",
      "paid_at": "2026-05-12T10:30:45Z",
      "card_brand": "visa",
      "card_last4": "4242",
      "card_exp_month": 12,
      "card_exp_year": 2027,
      "card_funding": "credit",
      "card_country": "US",
      "payment_method_type": "card"
    },
    "hold": {
      "id": 456,
      "amount": 50.00,
      "status": "holding",
      "hold_start_at": "2026-05-12T10:30:45Z",
      "hold_end_at": "2026-06-12T10:30:45Z",
      "hold_days": 31,
      "title": "My Savings Goal"
    }
  }
}
```

**Already Recorded Response — 200:**
```json
{
  "success": true,
  "message": "Payment already recorded.",
  "data": { ... }
}
```

**Payment Not Completed — 400:**
```json
{
  "success": false,
  "message": "Payment not completed yet. Status: requires_payment_method"
}
```

---

## 3. Add Bank Account

Users must add a bank account before they can withdraw funds. The first bank account automatically becomes the primary account.

**Endpoint:** `POST /api/bank-account`  
**Auth:** Required

**Request Body (US Account):**
```json
{
  "account_number": "000123456789",
  "bank_name": "Chase Bank",
  "routing_number": "110000000",
  "account_type": "checking",
  "country": "US",
  "currency": "usd",
  "dob": "1990-05-15"
}
```

**Request Body (Non-US Account — e.g., Europe):**
```json
{
  "account_number": "DE89370400440532013000",
  "bank_name": "Deutsche Bank",
  "iban": "DE89370400440532013000",
  "account_type": "checking",
  "country": "DE",
  "currency": "eur",
  "dob": "1990-05-15"
}
```

**Field Validation:**

| Field | Type | Rules |
|-------|------|-------|
| `dob` | date | Required. Must be before today (`YYYY-MM-DD`) |
| `account_number` | string | Required. 5–34 characters |
| `bank_name` | string | Required. Max 100 characters |
| `routing_number` | string | Required if `country` is `US`. Exactly 9 digits |
| `iban` | string | Required if `country` is NOT `US`. 15–34 characters |
| `account_type` | string | Required. Values: `savings`, `checking`, `current` |
| `country` | string | Required. 2-letter code. Allowed: `US`, `GB`, `CA`, `AU`, `DE`, `FR`, `IE`, `NL`, `AT`, `BE`, `ES`, `IT`, `PT`, `DK`, `FI`, `NO`, `SE`, `CH`, `NZ`, `SG`, `HK`, `JP` |
| `currency` | string | Required. 3-letter code. Allowed: `usd`, `eur`, `gbp`, `cad`, `aud`, `nzd`, `sgd`, `hkd`, `jpy`, `chf`, `dkk`, `nok`, `sek` |

**What Happens Behind the Scenes:**
1. If this is the user's **first** bank account:
   - Silently creates a Stripe Custom Connect account (type: `custom`, capability: `transfers`)
   - Stores Connect account info with TOS acceptance
2. Adds the bank account to Stripe via the Connect account
3. Creates an encrypted `UserBankAccount` record with blind indexes
4. First bank account is automatically set as **primary** (`is_primary: true`)

**Success Response — 201:**
```json
{
  "success": true,
  "message": "Bank account added successfully.",
  "data": {
    "id": 789,
    "account_holder_name": "John Doe",
    "bank_name": "Chase Bank",
    "account_number": "****6789",
    "account_type": "checking",
    "country": "US",
    "currency": "USD",
    "is_primary": true,
    "created_at": "2026-05-12T10:30:45Z"
  }
}
```

**Error Response — 500:**
```json
{
  "success": false,
  "message": "Failed to setup bank account. Please try again."
}
```

---

## 4. View Bank Accounts

Retrieve all bank accounts for the authenticated user.

**Endpoint:** `GET /api/bank-account`  
**Auth:** Required

**Request Body:** None

**Success Response — 200:**
```json
{
  "success": true,
  "data": {
    "has_bank_account": true,
    "total": 2,
    "bank_accounts": [
      {
        "id": 789,
        "account_holder_name": "John Doe",
        "bank_name": "Chase Bank",
        "account_number": "****6789",
        "account_type": "checking",
        "country": "US",
        "currency": "USD",
        "is_primary": true,
        "created_at": "2026-05-12T10:30:45Z"
      },
      {
        "id": 790,
        "account_holder_name": "John Doe",
        "bank_name": "Bank of America",
        "account_number": "****1234",
        "account_type": "savings",
        "country": "US",
        "currency": "USD",
        "is_primary": false,
        "created_at": "2026-05-13T08:15:00Z"
      }
    ]
  }
}
```

**No Bank Accounts — 200:**
```json
{
  "success": true,
  "data": {
    "has_bank_account": false,
    "bank_accounts": []
  }
}
```

---

## 5. Change Primary Bank Account

Set a different bank account as the primary withdrawal destination.

**Endpoint:** `POST /api/bank-account/{id}/set-primary`  
**Auth:** Required

**URL Parameter:**

| Param | Description |
|-------|-------------|
| `id` | Bank account ID to make primary |

**Request Body:** None

**What Happens Behind the Scenes:**
1. Verifies the bank account belongs to the authenticated user
2. If already primary, returns success immediately
3. Uses pessimistic row locking (`lockForUpdate`) to prevent race conditions
4. Removes `is_primary` from all other accounts
5. Sets the specified account as primary

**Success Response — 200:**
```json
{
  "success": true,
  "message": "Bank account set as primary.",
  "data": {
    "id": 790,
    "bank_name": "Bank of America",
    "account_number": "****1234",
    "is_primary": true
  }
}
```

**Already Primary — 200:**
```json
{
  "success": true,
  "message": "Bank account is already primary.",
  "data": {
    "id": 790,
    "bank_name": "Bank of America",
    "account_number": "****1234",
    "is_primary": true
  }
}
```

**Not Found — 404:**
```json
{
  "success": false,
  "message": "Bank account not found."
}
```

---

## 6. Delete Bank Account

Remove a bank account. Cannot delete if there are pending transfers.

**Endpoint:** `DELETE /api/bank-account/{id}`  
**Auth:** Required

**URL Parameter:**

| Param | Description |
|-------|-------------|
| `id` | Bank account ID to delete |

**Request Body:** None

**What Happens Behind the Scenes:**
1. Checks for pending transfers — blocks deletion if any exist
2. Deletes the bank account from Stripe (via Connect account)
3. If the deleted bank was primary, the oldest remaining bank becomes primary
4. Removes the record from the database

**Success Response — 200:**
```json
{
  "success": true,
  "message": "Bank account removed successfully."
}
```

**Pending Transfers — Error:**
```json
{
  "success": false,
  "message": "Cannot delete bank while a withdrawal is pending."
}
```

---

## 7. View Payment Holds & Summary

### 7a. Transaction History (Holds + Transfers)

**Endpoint:** `GET /api/payment-holds`  
**Auth:** Required

**Query Parameters:**

| Param | Type | Default | Max |
|-------|------|---------|-----|
| `per_page` | integer | 15 | 100 |

**Success Response — 200:**
```json
{
  "success": true,
  "message": "Transaction history retrieved successfully.",
  "summary": {
    "total_checkout_amount": 150.00,
    "total_transferred_amount": 50.00,
    "pending_balance": 100.00,
    "total_transactions": 5
  },
  "transactions": [
    {
      "transaction_type": "checkout",
      "transaction_id": "CHK-456",
      "hold_id": 456,
      "amount": 50.00,
      "currency": "USD",
      "status": "succeeded",
      "date": "2026-05-12T10:30:45Z",
      "description": "Checkout payment received",
      "hold_duration": {
        "hold_days": 31,
        "hold_period_type": "custom",
        "hold_start_at": "2026-05-12T10:30:45Z",
        "hold_end_at": "2026-06-12T10:30:45Z",
        "days_elapsed": 5,
        "days_remaining": 26,
        "is_complete": false
      },
      "payment_details": {
        "payment_id": 123,
        "payment_intent_id": "pi_...",
        "hold_status": "holding",
        "can_request_payout": false
      }
    },
    {
      "transaction_type": "transfer",
      "transaction_id": "TRF-789",
      "hold_id": 123,
      "amount": 50.00,
      "currency": "USD",
      "status": "completed",
      "date": "2026-05-11T10:30:45Z",
      "description": "Transfer to Stripe Connect account",
      "transfer_details": {
        "transfer_id": 789,
        "stripe_transfer_id": "tr_...",
        "transfer_type": "user_requested",
        "transferred_at": "2026-05-11T10:30:45Z"
      }
    }
  ],
  "pagination": {
    "current_page": 1,
    "per_page": 15,
    "total": 5,
    "last_page": 1,
    "from": 1,
    "to": 5,
    "has_more_pages": false
  }
}
```

### 7b. Summary by Status

**Endpoint:** `GET /api/payment-holds/summary`  
**Auth:** Required

**Success Response — 200:**
```json
{
  "success": true,
  "data": {
    "holding": {
      "count": 2,
      "total_amount": 100.00,
      "total_remaining": 100.00
    },
    "ready_for_transfer": {
      "count": 1,
      "total_amount": 50.00,
      "total_remaining": 50.00
    },
    "partial_transferred": {
      "count": 0,
      "total_amount": 0.00,
      "total_remaining": 0.00
    },
    "transferred": {
      "count": 2,
      "total_amount": 100.00,
      "total_remaining": 0.00
    },
    "abandoned": {
      "count": 0,
      "total_amount": 0.00
    }
  }
}
```

---

## 8. Withdraw Funds

Two ways to withdraw: request payout for a specific hold, or bulk withdraw across multiple holds.

### 8a. Request Payout for a Single Hold

**Endpoint:** `POST /api/payment-holds/{hold_id}/request-payout`  
**Auth:** Required

**URL Parameter:**

| Param | Description |
|-------|-------------|
| `hold_id` | The payment hold ID to withdraw from |

**Request Body:** None

**Prerequisites:**
- Hold period must be complete (`hold_end_at` has passed)
- Hold must have `remaining_amount > 0`
- No pending/processing transfer must exist for this hold
- User must have a bank account set up

**What Happens Behind the Scenes:**
1. Validates hold belongs to user and period is complete
2. Updates hold status to `ready_for_transfer`
3. Creates a Stripe Transfer to the user's Connect account
4. Creates a Transfer record with status `pending`
5. Deducts from hold's `remaining_amount`
6. Updates hold status to `transferred` (if fully paid) or `partial_transferred`
7. Dispatches email notification

**Success Response — 200:**
```json
{
  "success": true,
  "message": "Payout request submitted successfully.",
  "data": {
    "hold_id": 456,
    "transfer_id": 789,
    "stripe_transfer_id": "tr_ABC123",
    "amount": 50.00,
    "currency": "USD",
    "status": "pending",
    "transferred_at": "2026-05-12T14:30:45Z",
    "email_status": "pending",
    "can_request_again": false
  }
}
```

**Hold Period Not Complete — Error:**
```json
{
  "success": false,
  "message": "Hold period not completed yet. Payout will be available after hold period ends.",
  "data": {
    "hold_end_at": "2026-06-12T10:30:45Z",
    "days_remaining": 31
  }
}
```

**Already Requested — Error:**
```json
{
  "success": false,
  "message": "Payout request already exists for this hold.",
  "data": {
    "transfer_id": 789,
    "status": "pending"
  }
}
```

### 8b. Bulk Withdrawal (Across Multiple Holds)

**Endpoint:** `POST /api/payment-holds/withdraw`  
**Auth:** Required

**Request Body:**
```json
{
  "amount": 100.00
}
```

**Field Validation:**

| Field | Type | Rules |
|-------|------|-------|
| `amount` | number | Required. Min: 0.50. Max: 999,999.99 |

**Prerequisites:**
- User must have a primary bank account
- Must have eligible holds (status `ready_for_transfer` or `holding` with expired hold period)

**What Happens Behind the Scenes:**
1. Validates primary bank account exists
2. Finds all eligible holds (expired hold period, sorted oldest first)
3. Processes holds one by one in a database transaction:
   - For each hold, creates a Stripe Transfer for `min(amount_needed, remaining_amount)`
   - Creates Transfer records with status `pending`
   - Updates each hold's `remaining_amount` and status
4. Continues until the full requested amount is fulfilled or no more holds available
5. Dispatches email notifications based on results

**Full Withdrawal Success — 200:**
```json
{
  "success": true,
  "message": "Withdrawal of $100.00 completed successfully.",
  "data": {
    "requested_amount": 100.00,
    "processed_amount": 100.00,
    "total_transfers": 2,
    "holds_processed": [
      {
        "hold_id": 456,
        "transfer_id": 789,
        "amount": 50.00
      },
      {
        "hold_id": 457,
        "transfer_id": 790,
        "amount": 50.00
      }
    ],
    "transfers": [
      {
        "hold_id": 456,
        "transfer_id": 789,
        "stripe_transfer_id": "tr_ABC123",
        "amount": 50.00,
        "currency": "USD",
        "status": "pending",
        "transferred_at": "2026-05-12T14:30:45Z",
        "email_status": "pending",
        "email_sent_at": null
      },
      {
        "hold_id": 457,
        "transfer_id": 790,
        "stripe_transfer_id": "tr_DEF456",
        "amount": 50.00,
        "currency": "USD",
        "status": "pending",
        "transferred_at": "2026-05-12T14:30:46Z",
        "email_status": "pending",
        "email_sent_at": null
      }
    ],
    "status": "completed",
    "all_completed": true,
    "summary": {
      "requested": 100.00,
      "processed": 100.00,
      "difference": 0.00
    }
  }
}
```

**Partial Withdrawal (Insufficient Eligible Funds) — 200:**
```json
{
  "success": true,
  "message": "Withdrawal request for $150.00 submitted successfully. Transfers are being processed.",
  "data": {
    "requested_amount": 150.00,
    "processed_amount": 100.00,
    "total_transfers": 2,
    "status": "pending",
    "all_completed": false,
    "summary": {
      "requested": 150.00,
      "processed": 100.00,
      "difference": 50.00
    }
  }
}
```

**No Eligible Holds — Error:**
```json
{
  "success": false,
  "message": "No eligible holds available for withdrawal."
}
```

**No Bank Account — Error:**
```json
{
  "success": false,
  "message": "Bank account not found. Please add a bank account first."
}
```

---

## 9. Stripe Webhook (Server-to-Server)

Stripe sends event notifications to this endpoint. No authentication header needed — uses Stripe signature verification.

**Endpoint:** `POST /api/stripe/webhook`  
**Auth:** None (Stripe Signature verified via `STRIPE_WEBHOOK_SECRET`)

**Header:**
```
Stripe-Signature: t=...,v1=...
```

**Handled Events:**

| Event | Action |
|-------|--------|
| `payment_intent.succeeded` | Confirms payment, creates Payment + PaymentHold records |
| `payment_intent.payment_failed` | Marks payment as failed, sends failure notification |
| `payment_intent.canceled` | Marks payment as canceled |
| `checkout.session.completed` | Handles checkout session completion |

**Deduplication:** Events are tracked by `stripe_event_id` in the `stripe_webhook_events` table. Duplicate events are ignored.

**Response — 200:**
```json
{
  "received": true
}
```

---

## 10. Complete Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                        DEPOSIT & LOCK FLOW                         │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  Mobile App                    Backend API              Stripe      │
│  ─────────                    ───────────              ──────      │
│                                                                     │
│  1. User enters amount    ──►  POST /create-payment-intent          │
│     + hold dates                   │                                │
│                                    ├──► Create Customer ──────►     │
│                                    ├──► Create EphemeralKey ──►     │
│                                    ├──► Create PaymentIntent ─►     │
│                                    │                                │
│  2. Receive credentials  ◄──  Return client_secret,                │
│                                ephemeral_key, customer_id           │
│                                                                     │
│  3. Show Payment Sheet   ──►  Stripe SDK handles card input         │
│     (card/Apple Pay/              │                                 │
│      Google Pay)                  ▼                                 │
│                               Payment succeeds on Stripe            │
│                                                                     │
│  4. Confirm payment      ──►  POST /confirm-payment                 │
│                                    │                                │
│                                    ├──► Verify status = succeeded   │
│                                    ├──► Create Payment record       │
│                                    ├──► Create PaymentHold          │
│                                    │    (status: holding)           │
│                                    ├──► Send email notification     │
│                                    │                                │
│  5. Show success         ◄──  Return payment + hold details         │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│                       HOLD PERIOD                                   │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  PaymentHold Status: holding                                        │
│  ┌──────────────────────────────────────────┐                      │
│  │  hold_start_at ─────────► hold_end_at    │                      │
│  │  (funds locked, cannot withdraw)          │                      │
│  └──────────────────────────────────────────┘                      │
│                                    │                                │
│                              Hold expires                           │
│                                    │                                │
│                                    ▼                                │
│  PaymentHold Status: ready_for_transfer                             │
│  (user can now request payout)                                      │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│                    BANK ACCOUNT SETUP                               │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  1. POST /bank-account          Add first bank account              │
│     ├──► Auto-creates Stripe Connect account (silent)               │
│     └──► First account = primary automatically                      │
│                                                                     │
│  2. POST /bank-account          Add more bank accounts              │
│     └──► Additional accounts (is_primary: false)                    │
│                                                                     │
│  3. GET /bank-account           View all bank accounts              │
│     └──► Returns masked account numbers (****1234)                  │
│                                                                     │
│  4. POST /bank-account/{id}/set-primary    Change primary           │
│     └──► Uses row locking to prevent race conditions                │
│                                                                     │
│  5. DELETE /bank-account/{id}   Remove bank account                 │
│     ├──► Blocked if pending transfers exist                         │
│     └──► Oldest remaining becomes primary                           │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│                      WITHDRAWAL FLOW                                │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  Option A: Single Hold Payout                                       │
│  ─────────────────────────────                                      │
│  POST /payment-holds/{id}/request-payout                            │
│       │                                                             │
│       ├──► Verify hold period expired                               │
│       ├──► Create Stripe Transfer → Connect account                 │
│       ├──► Create Transfer record (status: pending)                 │
│       ├──► Update hold remaining_amount                             │
│       └──► Send email notification                                  │
│                                                                     │
│  Option B: Bulk Withdrawal                                          │
│  ─────────────────────────                                          │
│  POST /payment-holds/withdraw  { "amount": 100.00 }                │
│       │                                                             │
│       ├──► Find all eligible holds (oldest first)                   │
│       ├──► For each hold:                                           │
│       │    ├──► Create Stripe Transfer                              │
│       │    ├──► Create Transfer record                              │
│       │    └──► Deduct from remaining_amount                        │
│       ├──► Continue until requested amount fulfilled                │
│       └──► Send summary email notification                          │
│                                                                     │
│  Hold Status Transitions:                                           │
│  holding → ready_for_transfer → transferred                         │
│                              → partial_transferred → transferred    │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 11. Error Reference

### Standard Response Format

All API responses follow this format:

```json
{
  "success": true | false,
  "message": "Human-readable message",
  "data": { ... }
}
```

### HTTP Status Codes

| Code | Meaning |
|------|---------|
| 200 | Success |
| 201 | Created (new resource) |
| 400 | Bad request (e.g., payment not completed) |
| 403 | Forbidden (e.g., hold doesn't belong to user) |
| 404 | Not found (e.g., bank account, hold) |
| 422 | Validation error (invalid input fields) |
| 500 | Server error (Stripe failure, unexpected error) |

### Validation Error Format — 422

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "amount": [
      "The amount field is required.",
      "The amount must be at least 1."
    ],
    "currency": [
      "The selected currency is invalid."
    ]
  }
}
```

### PaymentHold Status Reference

| Status | Meaning | Can Withdraw? |
|--------|---------|---------------|
| `holding` | Funds locked, hold period active | No |
| `ready_for_transfer` | Hold expired, awaiting withdrawal | Yes |
| `partial_transferred` | Some funds withdrawn, remainder available | Yes (remaining) |
| `transferred` | All funds withdrawn | No |
| `abandoned` | Hold abandoned/forfeited | No |

### Transfer Status Reference

| Status | Meaning |
|--------|---------|
| `pending` | Transfer created, awaiting Stripe processing |
| `processing` | Stripe is processing the transfer |
| `completed` | Funds successfully transferred |
| `failed` | Transfer failed (see `failure_reason`) |

---

## Appendix: Supported Values

### Currencies
`usd`, `eur`, `gbp`, `cad`, `aud`, `nzd`, `sgd`, `hkd`, `jpy`, `chf`, `dkk`, `nok`, `sek`

### Countries (Bank Accounts)
`US`, `GB`, `CA`, `AU`, `DE`, `FR`, `IE`, `NL`, `AT`, `BE`, `ES`, `IT`, `PT`, `DK`, `FI`, `NO`, `SE`, `CH`, `NZ`, `SG`, `HK`, `JP`

### Account Types
`savings`, `checking`, `current`

### Card Brands (Returned in Payment)
`visa`, `mastercard`, `amex`, `discover`, `diners`, `jcb`, `unionpay`

### Payment Method Types
`card`, `apple_pay`, `google_pay`, `wallet`
