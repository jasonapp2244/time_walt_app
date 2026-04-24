# Time Vault — Bank Details & Withdrawal Setup (Complete Guide)

> This document explains the **complete flow from payment to withdrawal** using
> Stripe Custom Connect (silent — no onboarding redirect). It covers the backend,
> Stripe third-party setup, frontend guidance, and every API involved.

---

## Table of Contents

1. [Complete Flow Diagram (Start to End)](#1-complete-flow-diagram-start-to-end)
2. [Stripe Dashboard Setup (Third Party)](#2-stripe-dashboard-setup-third-party)
3. [Backend Setup (Laravel)](#3-backend-setup-laravel)
4. [API Reference (All Endpoints)](#4-api-reference-all-endpoints)
5. [Frontend Guidance (Flutter)](#5-frontend-guidance-flutter)
6. [Security](#6-security)
7. [Files Changed Summary](#7-files-changed-summary)

---

## 1. Complete Flow Diagram (Start to End)

### 1.1 Full Journey (Payment to Withdrawal)

```
USER JOURNEY (6 Steps)
======================

STEP 1: SIGNUP & LOGIN (Already Built)
+------------------------------------------+
|  User → Signup → OTP → Login → Token     |
+------------------------------------------+
                    |
                    v
STEP 2: ADD PAYMENT VIA PAYMENT SHEET (Already Built)
+------------------------------------------+
|  User opens app                          |
|    → Enters amount ($100)                |
|    → Selects hold period (30 days)       |
|    → Payment Sheet opens                 |
|    → Pays with card                      |
|    → App calls confirm-payment           |
|    → Backend creates:                    |
|       - Payment record (succeeded)       |
|       - PaymentHold record (holding)     |
+------------------------------------------+
                    |
                    v
STEP 3: HOLD PERIOD (Automatic — Already Built)
+------------------------------------------+
|  Money sits in Stripe platform account   |
|  PaymentHold status = "holding"          |
|  hold_end_at = 30 days from now          |
|                                          |
|  ... 30 days pass ...                    |
|                                          |
|  Hold becomes eligible for withdrawal    |
|  (withdraw API checks hold_end_at)      |
+------------------------------------------+
                    |
                    v
STEP 4: CHECK BALANCE (Already Built)
+------------------------------------------+
|  User opens wallet screen                |
|    → GET /api/payment-holds/summary      |
|    → Sees:                               |
|       total_balance: $100.00             |
|       locked_amount: $0.00               |
|       ready_for_transfer: $100.00        |
+------------------------------------------+
                    |
                    v
STEP 5: SAVE BANK DETAILS — ONE TIME (NEW - Need to Build)
+------------------------------------------+
|  User taps "Withdraw" for first time     |
|    → App checks: GET /api/bank-account   |
|    → No bank details found               |
|    → App shows bank details form:        |
|       - Date of Birth                    |
|       - Bank Account Number / IBAN       |
|    → User fills and submits (1 min)      |
|    → POST /api/bank-account              |
|                                          |
|  Backend silently:                       |
|    1. Creates Stripe Custom Connect acct |
|    2. Adds bank as external account      |
|    3. Saves to stripe_connect_accounts   |
|    4. Saves to user_bank_accounts        |
|                                          |
|  User NEVER leaves the app              |
|  User NEVER sees Stripe                 |
|  Done in 1 minute                        |
+------------------------------------------+
                    |
                    v
STEP 6: WITHDRAW (Already Built — 1 Line Change)
+------------------------------------------+
|  User enters withdrawal amount ($50)     |
|    → POST /api/payment-holds/withdraw    |
|    → Backend finds available holds       |
|    → Backend finds Connect account       |
|       (created in Step 5)                |
|    → Calls Stripe Transfer API           |
|    → Money sent to user's bank           |
|    → Transfer record: completed          |
|    → PaymentHold: partial_transferred    |
|    → User gets notification              |
|                                          |
|  Fully automatic. No admin needed.       |
+------------------------------------------+
```

### 1.2 Bank Details Sub-Flow (Step 5 in Detail)

```
WHAT HAPPENS WHEN USER SAVES BANK DETAILS
==========================================

    +------------------+
    |  Flutter App     |
    |  (User's Phone)  |
    +--------+---------+
             |
             |  POST /api/bank-account
             |  {
             |    "dob": "1995-03-15",
             |    "account_number": "1234567890",
             |    "bank_name": "HBL"
             |  }
             |
             v
    +------------------+
    |  Laravel Backend  |
    |  BankAccount      |
    |  Controller       |
    +--------+---------+
             |
             |  Step A: Validate input
             |
             v
    +------------------+
    |  Stripe API #1    |
    |  Create Custom    |
    |  Connect Account  |
    +--------+---------+
             |
             |  \Stripe\Account::create([
             |    'type' => 'custom',
             |    'country' => 'US',
             |    'email' => user's email,
             |    'capabilities' => ['transfers' => ['requested' => true]],
             |    'individual' => [
             |      'first_name' => user's name,
             |      'dob' => { day, month, year },
             |    ],
             |    'tos_acceptance' => [
             |      'date' => time(),
             |      'ip' => request IP,
             |    ],
             |  ]);
             |
             |  Returns: acct_xxxxxxxxxxxx
             |
             v
    +------------------+
    |  Stripe API #2    |
    |  Add External     |
    |  Bank Account     |
    +--------+---------+
             |
             |  \Stripe\Account::createExternalAccount(
             |    'acct_xxxxxxxxxxxx',
             |    [
             |      'external_account' => [
             |        'object' => 'bank_account',
             |        'country' => 'US',
             |        'currency' => 'usd',
             |        'account_number' => '1234567890',
             |        'routing_number' => '110000000', (US only)
             |      ]
             |    ]
             |  );
             |
             |  Returns: ba_xxxxxxxxxxxx
             |
             v
    +------------------+
    |  Database         |
    |  Save Records     |
    +--------+---------+
             |
             |  1. stripe_connect_accounts table (EXISTING):
             |     - user_id: 5
             |     - connect_account_id: "acct_xxx" (encrypted)
             |     - status: "verified"
             |     - payouts_enabled: true
             |
             |  2. user_bank_accounts table (NEW):
             |     - user_id: 5
             |     - account_holder_name: encrypted
             |     - bank_name: encrypted
             |     - account_number: encrypted
             |     - stripe_bank_account_id: "ba_xxx" (encrypted)
             |     - is_primary: true
             |
             v
    +------------------+
    |  Response to App  |
    |  {                |
    |    success: true, |
    |    bank_name: HBL,|
    |    account: ****90|
    |  }                |
    +------------------+
```

### 1.3 Withdraw Sub-Flow (Step 6 in Detail)

```
WHAT HAPPENS WHEN USER WITHDRAWS
=================================

    +------------------+
    |  Flutter App     |
    |  Withdraw Screen |
    +--------+---------+
             |
             |  POST /api/payment-holds/withdraw
             |  { "amount": 50.00 }
             |
             v
    +------------------+
    |  Laravel Backend   |
    |  PaymentHold       |
    |  Controller        |
    +--------+---------+
             |
             |  Step 1: Find available holds
             |    → WHERE user_id = 5
             |    → WHERE hold_end_at <= now()
             |    → WHERE remaining_amount > 0
             |    → Found: Hold #1 ($100, ready)
             |
             |  Step 2: Check total available
             |    → Available: $100.00
             |    → Requested: $50.00
             |    → OK (50 <= 100)
             |
             |  Step 3: Check Connect account  <-- THIS IS THE KEY STEP
             |    → SELECT FROM stripe_connect_accounts
             |      WHERE user_id = 5
             |    → FOUND: acct_xxx (created when user saved bank details)
             |
             |  Step 4: Create Stripe Transfer
             v
    +------------------+
    |  Stripe API       |
    |  Create Transfer  |
    +--------+---------+
             |
             |  \Stripe\Transfer::create([
             |    'amount' => 5000, (cents)
             |    'currency' => 'usd',
             |    'destination' => 'acct_xxx',
             |  ]);
             |
             |  Stripe sends $50 from your platform
             |  account to user's connected bank account
             |
             v
    +------------------+
    |  Database Updates  |
    +--------+---------+
             |
             |  transfers table:
             |    - hold_id: 1
             |    - user_id: 5
             |    - amount: 50.00
             |    - status: completed
             |    - stripe_transfer_id: "tr_xxx"
             |
             |  payment_holds table:
             |    - id: 1
             |    - remaining_amount: 50.00 (was 100)
             |    - status: partial_transferred
             |
             v
    +------------------+
    |  Email Notification|
    |  "Your withdrawal |
    |   of $50 has been |
    |   processed"      |
    +------------------+
             |
             v
    +------------------+
    |  Response to App  |
    |  {                |
    |    success: true, |
    |    amount: 50.00, |
    |    status:        |
    |    "completed"    |
    |  }                |
    +------------------+
```

### 1.4 Change Bank Details Flow

```
CHANGE BANK DETAILS (Delete Old + Add New)
===========================================

    +------------------+
    |  Flutter App     |
    +--------+---------+
             |
             |  POST /api/bank-account/change
             |  {
             |    "account_number": "9876543210",
             |    "bank_name": "Meezan"
             |  }
             |
             v
    +------------------+
    |  Laravel Backend  |
    +--------+---------+
             |
             |  Step 1: Get existing Connect account
             |    → acct_xxx
             |
             |  Step 2: Get existing external bank
             |    → ba_old_xxx
             |
             v
    +------------------+
    |  Stripe API #1    |
    |  DELETE old bank  |
    +--------+---------+
             |
             |  \Stripe\Account::deleteExternalAccount(
             |    'acct_xxx',
             |    'ba_old_xxx'
             |  );
             |
             v
    +------------------+
    |  Stripe API #2    |
    |  ADD new bank     |
    +--------+---------+
             |
             |  \Stripe\Account::createExternalAccount(
             |    'acct_xxx',
             |    ['external_account' => [
             |      'account_number' => '9876543210',
             |      ...
             |    ]]
             |  );
             |
             |  Returns: ba_new_xxx
             |
             v
    +------------------+
    |  Database Update  |
    |  user_bank_accounts: |
    |    bank_name: Meezan  |
    |    account: encrypted |
    |    stripe_bank_id:    |
    |      ba_new_xxx       |
    +------------------+

NOTE: Stripe does NOT support updating bank details.
      You MUST delete old + add new. This is a Stripe rule.
```

### 1.5 Delete Bank Details Flow

```
DELETE BANK DETAILS
===================

    +------------------+
    |  Flutter App     |
    +--------+---------+
             |
             |  DELETE /api/bank-account
             |
             v
    +------------------+
    |  Laravel Backend  |
    +--------+---------+
             |
             |  Step 1: Delete bank from Stripe
             |  Step 2: Delete from user_bank_accounts table
             |  Step 3: Keep Connect account (reusable later)
             |
             v
    +------------------+
    |  After Delete:    |
    |  User CANNOT      |
    |  withdraw until   |
    |  they add bank    |
    |  details again    |
    +------------------+
```

---

## 2. Stripe Dashboard Setup (Third Party)

### 2.1 What You Need on Stripe Dashboard

```
STRIPE DASHBOARD CHECKLIST
===========================

+---+------------------------------------------+----------+
| # | Setting                                  | Status   |
+---+------------------------------------------+----------+
| 1 | Stripe Account (already have)            | DONE     |
| 2 | STRIPE_SECRET key (already in .env)       | DONE     |
| 3 | STRIPE_KEY publishable (already in .env)  | DONE     |
| 4 | Enable Connect in Stripe Dashboard       | REQUIRED |
| 5 | Set platform type to "Platform"          | REQUIRED |
| 6 | Enable Custom accounts                   | REQUIRED |
+---+------------------------------------------+----------+
```

### 2.2 Step-by-Step Stripe Dashboard Setup

```
STEP 1: Go to Stripe Dashboard
================================
URL: https://dashboard.stripe.com

STEP 2: Enable Stripe Connect
================================
  → Left sidebar → Click "Connect"
  → If first time → Click "Get started with Connect"
  → Platform profile:
      - Business type: "Platform or marketplace"
      - Platform type: Select your type

STEP 3: Configure Connect Settings
====================================
  → Connect → Settings
  → Account types: Enable "Custom"
  → Branding: Add your platform name & logo

STEP 4: Country Configuration
===============================
  → Connect → Settings → Account types
  → Make sure your target countries are supported
  → For US: Requires SSN last 4, DOB, address
  → For PK: Check Stripe's Pakistan support
     (if not supported, see Section 2.3)

STEP 5: Verify Your Platform
===============================
  → Settings → Business settings
  → Complete your platform verification
  → This is YOUR account verification (one-time, already done if live)
```

### 2.3 Country Support — IMPORTANT

```
STRIPE CUSTOM CONNECT SUPPORTED COUNTRIES
===========================================

FULLY SUPPORTED (Custom accounts):
  US, UK, EU countries, Canada, Australia,
  Japan, Singapore, Hong Kong, etc.

NOT SUPPORTED for Custom Connect:
  Pakistan, India (limited), Bangladesh, etc.

CHECK YOUR COUNTRY:
  → https://stripe.com/global
  → Look for "Connect" column

+------------------------------------------+
|  IF YOUR TARGET COUNTRY IS NOT SUPPORTED |
|  FOR CUSTOM CONNECT:                     |
|                                          |
|  You MUST use Option A instead:          |
|  (User saves bank details +             |
|   Admin manually transfers)             |
|                                          |
|  Custom Connect will NOT work.           |
+------------------------------------------+

IF COUNTRY IS SUPPORTED:
  → Proceed with this setup
  → Everything described in this doc will work
```

### 2.4 Required .env Variables

```
# Already in your .env (no change):
STRIPE_KEY=pk_test_xxxxx
STRIPE_PUBLISHABLE_KEY=pk_test_xxxxx
STRIPE_SECRET=sk_test_xxxxx
STRIPE_WEBHOOK_SECRET=whsec_xxxxx

# No new .env variables needed for Custom Connect.
# It uses the same STRIPE_SECRET key.
```

---

## 3. Backend Setup (Laravel)

### 3.1 New Database Table: `user_bank_accounts`

```
TABLE: user_bank_accounts
==========================

+------------------------+-------------------+-----------------------------+
| Column                 | Type              | Notes                       |
+------------------------+-------------------+-----------------------------+
| id                     | bigint (PK)       | Auto increment              |
| user_id                | foreignId         | → users.id (cascade delete) |
| account_holder_name    | text (encrypted)  | Name on bank account        |
| bank_name              | text (encrypted)  | Bank name (HBL, Chase etc)  |
| account_number         | text (encrypted)  | Full account number         |
| account_number_index   | string            | Blind index for lookups     |
| routing_number         | text (encrypted)  | US routing number (nullable)|
| iban                   | text (encrypted)  | International (nullable)    |
| swift_code             | string (nullable) | SWIFT/BIC code              |
| account_type           | enum              | savings/checking/current    |
| country                | string(2)         | ISO country: US, PK, etc    |
| currency               | string(3)         | usd, pkr, etc               |
| stripe_bank_account_id | text (encrypted)  | Stripe ba_xxx ID            |
| stripe_bank_account_id | string            | Blind index                 |
|   _index               |                   |                             |
| is_primary             | boolean           | Default: true               |
| dob                    | date (encrypted)  | Date of birth for Connect   |
| created_at             | timestamp         |                             |
| updated_at             | timestamp         |                             |
+------------------------+-------------------+-----------------------------+

INDEXES:
  - user_id (unique — one bank account per user)
  - account_number_index
  - stripe_bank_account_id_index

ENCRYPTION PATTERN:
  Same as your User model and StripeConnectAccount model:
  - Sensitive fields → Laravel encrypted cast
  - Lookup fields → HMAC-SHA256 blind index
```

### 3.2 New Model: `UserBankAccount.php`

```
MODEL STRUCTURE
================

UserBankAccount
  ├── Fillable fields (all columns above)
  ├── Encrypted casts:
  │     account_holder_name → encrypted
  │     bank_name → encrypted
  │     account_number → encrypted
  │     routing_number → encrypted
  │     iban → encrypted
  │     stripe_bank_account_id → encrypted
  │     dob → encrypted:date
  │
  ├── Hidden fields:
  │     account_number_index
  │     stripe_bank_account_id_index
  │
  ├── Blind index (same pattern as User model):
  │     saving() → auto-update account_number_index
  │     saving() → auto-update stripe_bank_account_id_index
  │
  ├── Relationships:
  │     user() → belongsTo(User::class)
  │
  └── Helper methods:
        getMaskedAccountNumberAttribute()
          → returns "****7890" (last 4 digits)
```

### 3.3 New Controller: `BankAccountController.php`

```
CONTROLLER METHODS
===================

1. store(Request)     → POST /api/bank-account
2. show(Request)      → GET  /api/bank-account
3. change(Request)    → POST /api/bank-account/change
4. destroy(Request)   → DELETE /api/bank-account
```

**Method Details:**

```
METHOD 1: store() — Add Bank Account (First Time)
===================================================

  Input:
    - dob: required, date, format Y-m-d
    - account_number: required, string
    - bank_name: required, string
    - routing_number: optional, string (required for US)
    - iban: optional, string
    - account_type: optional, enum (savings/checking/current)
    - country: required, string, 2 chars (US, PK, etc)
    - currency: required, string, 3 chars (usd, pkr, etc)

  Logic:
    1. Check if user already has bank account → error if yes
    2. Stripe API: Create Custom Connect account
    3. Stripe API: Add external bank account
    4. DB: Save to stripe_connect_accounts (EXISTING table)
    5. DB: Save to user_bank_accounts (NEW table)
    6. Return masked bank details

  Response:
    {
      "success": true,
      "message": "Bank account added successfully.",
      "data": {
        "id": 1,
        "bank_name": "HBL",
        "account_number": "****7890",
        "account_type": "savings",
        "country": "PK",
        "is_primary": true
      }
    }


METHOD 2: show() — Get Bank Details
=====================================

  Input: None (uses auth user)

  Logic:
    1. Get user's bank account from DB
    2. Return MASKED details (never full account number)

  Response (has bank account):
    {
      "success": true,
      "data": {
        "id": 1,
        "bank_name": "HBL",
        "account_holder_name": "Tauseef Aslam",
        "account_number": "****7890",
        "iban": "PK36****1234",
        "account_type": "savings",
        "country": "PK",
        "has_bank_account": true
      }
    }

  Response (no bank account):
    {
      "success": true,
      "data": {
        "has_bank_account": false
      }
    }


METHOD 3: change() — Replace Bank Account
===========================================

  Input:
    - account_number: required, string (new bank)
    - bank_name: required, string
    - routing_number: optional
    - iban: optional
    - account_type: optional
    - country: required
    - currency: required

  Logic:
    1. Get existing Connect account from DB
    2. Get existing stripe_bank_account_id
    3. Stripe API: Delete old external bank account
    4. Stripe API: Add new external bank account
    5. DB: Update user_bank_accounts with new details
    6. Return new masked details

  NOTE: Stripe does NOT allow update. Must delete + add.

  Response:
    {
      "success": true,
      "message": "Bank account updated successfully.",
      "data": {
        "bank_name": "Meezan",
        "account_number": "****3210"
      }
    }


METHOD 4: destroy() — Delete Bank Account
===========================================

  Input: None

  Logic:
    1. Get existing bank account
    2. Check no pending withdrawals exist
    3. Stripe API: Delete external bank account
    4. DB: Delete from user_bank_accounts
    5. Keep Connect account on Stripe (reusable)

  Response:
    {
      "success": true,
      "message": "Bank account removed successfully."
    }

  AFTER DELETE:
    - User cannot withdraw
    - Withdraw API returns: "Please add bank details first"
```

### 3.4 New Request Validators

```
VALIDATION RULES
=================

StoreBankAccountRequest:
  - dob:            required | date | before:today
  - account_number: required | string | min:5 | max:34
  - bank_name:      required | string | max:100
  - routing_number: nullable | string | size:9  (US only)
  - iban:           nullable | string | min:15 | max:34
  - account_type:   required | in:savings,checking,current
  - country:        required | string | size:2
  - currency:       required | string | size:3

ChangeBankAccountRequest:
  - account_number: required | string | min:5 | max:34
  - bank_name:      required | string | max:100
  - routing_number: nullable | string | size:9
  - iban:           nullable | string | min:15 | max:34
  - account_type:   required | in:savings,checking,current
  - country:        required | string | size:2
  - currency:       required | string | size:3
```

### 3.5 Changes to Existing Files

```
FILE: PaymentHoldController.php
=================================
CHANGE: Line 448 only (error message)

  BEFORE:
    'message' => 'Stripe Connect account not found. Please complete account setup.',

  AFTER:
    'message' => 'No bank account found. Please add your bank details first.',

  Nothing else changes. The withdraw logic stays exactly the same.


FILE: StripeService.php
========================
CHANGE: createConnectAccount() method

  BEFORE:
    Creates 'express' type account
    Requires Stripe-hosted onboarding

  AFTER:
    Creates 'custom' type account
    No onboarding needed — all data passed via API

  The createTransfer() method stays exactly the same.


FILE: User.php (Model)
========================
ADD: One relationship method

    public function bankAccount()
    {
        return $this->hasOne(UserBankAccount::class);
    }


FILE: routes/api.php
======================
ADD: 4 new routes inside auth:sanctum group

    Route::prefix('bank-account')->name('bank-account.')->group(function () {
        Route::post('/',       [BankAccountController::class, 'store'])->name('store');
        Route::get('/',        [BankAccountController::class, 'show'])->name('show');
        Route::post('/change', [BankAccountController::class, 'change'])->name('change');
        Route::delete('/',     [BankAccountController::class, 'destroy'])->name('destroy');
    });
```

### 3.6 Existing APIs — NO Changes Needed

```
THESE APIs STAY EXACTLY THE SAME (NO CHANGES)
===============================================

Payment APIs:
  POST /api/stripe/create-payment-intent     → No change
  POST /api/stripe/confirm-payment           → No change

Hold/Wallet APIs:
  GET  /api/payment-holds                    → No change
  GET  /api/payment-holds/summary            → No change
  POST /api/payment-holds/{id}/request-payout→ No change

Withdraw API:
  POST /api/payment-holds/withdraw           → 1 line change (error message only)

Transaction APIs:
  GET  /api/transactions/all                 → No change
  GET  /api/transactions/checkouts           → No change
  GET  /api/transactions/withdraws           → No change
  GET  /api/transactions/hold-amounts        → No change
  GET  /api/transactions/ready-for-transfer  → No change
```

---

## 4. API Reference (All Endpoints)

### 4.1 Complete API List

```
FULL API LIST (EXISTING + NEW)
===============================

AUTH:
  POST   /api/auth/signup                          EXISTING — No change
  POST   /api/auth/verify-otp                      EXISTING — No change
  POST   /api/auth/login                           EXISTING — No change
  POST   /api/auth/logout                          EXISTING — No change
  POST   /api/auth/forgot-password                 EXISTING — No change
  POST   /api/auth/reset-password                  EXISTING — No change

PROFILE:
  GET    /api/profile                              EXISTING — No change
  POST   /api/profile/update                       EXISTING — No change

PAYMENT:
  POST   /api/stripe/create-payment-intent         EXISTING — No change
  POST   /api/stripe/confirm-payment               EXISTING — No change

BANK ACCOUNT:
  POST   /api/bank-account                         NEW — Add bank details
  GET    /api/bank-account                         NEW — Get bank details
  POST   /api/bank-account/change                  NEW — Change bank details
  DELETE /api/bank-account                         NEW — Delete bank details

WALLET / HOLDS:
  GET    /api/payment-holds                        EXISTING — No change
  GET    /api/payment-holds/summary                EXISTING — No change
  POST   /api/payment-holds/{id}/request-payout    EXISTING — No change
  POST   /api/payment-holds/withdraw               EXISTING — 1 line change

TRANSACTIONS:
  GET    /api/transactions/all                     EXISTING — No change
  GET    /api/transactions/checkouts               EXISTING — No change
  GET    /api/transactions/withdraws               EXISTING — No change
```

### 4.2 New API — POST /api/bank-account (Add)

```
ENDPOINT: POST /api/bank-account
AUTH: Bearer Token (required)
RATE LIMIT: 5 per hour

REQUEST BODY:
{
  "dob": "1995-03-15",
  "account_number": "1234567890123",
  "bank_name": "HBL",
  "routing_number": "110000000",        // required for US only
  "iban": "PK36HABB0012345678901234",   // optional
  "account_type": "savings",            // savings | checking | current
  "country": "US",                      // 2-letter ISO code
  "currency": "usd"                     // 3-letter currency code
}

SUCCESS RESPONSE (201):
{
  "success": true,
  "message": "Bank account added successfully.",
  "data": {
    "id": 1,
    "account_holder_name": "Tauseef Aslam",
    "bank_name": "HBL",
    "account_number": "****0123",
    "account_type": "savings",
    "country": "US",
    "currency": "USD",
    "is_primary": true,
    "created_at": "2026-04-23T10:00:00Z"
  }
}

ERROR — Already exists (400):
{
  "success": false,
  "message": "Bank account already exists. Use change endpoint to update."
}

ERROR — Stripe failure (500):
{
  "success": false,
  "message": "Failed to setup bank account. Please try again."
}
```

### 4.3 New API — GET /api/bank-account (View)

```
ENDPOINT: GET /api/bank-account
AUTH: Bearer Token (required)

SUCCESS RESPONSE — Has bank account (200):
{
  "success": true,
  "data": {
    "id": 1,
    "account_holder_name": "Tauseef Aslam",
    "bank_name": "HBL",
    "account_number": "****0123",
    "iban": "PK36****1234",
    "account_type": "savings",
    "country": "US",
    "currency": "USD",
    "is_primary": true,
    "has_bank_account": true,
    "created_at": "2026-04-23T10:00:00Z"
  }
}

SUCCESS RESPONSE — No bank account (200):
{
  "success": true,
  "data": {
    "has_bank_account": false
  }
}
```

### 4.4 New API — POST /api/bank-account/change (Replace)

```
ENDPOINT: POST /api/bank-account/change
AUTH: Bearer Token (required)
RATE LIMIT: 3 per day

REQUEST BODY:
{
  "account_number": "9876543210",
  "bank_name": "Meezan",
  "routing_number": "110000000",
  "iban": null,
  "account_type": "checking",
  "country": "US",
  "currency": "usd"
}

SUCCESS RESPONSE (200):
{
  "success": true,
  "message": "Bank account updated successfully.",
  "data": {
    "id": 1,
    "bank_name": "Meezan",
    "account_number": "****3210",
    "account_type": "checking",
    "country": "US"
  }
}

ERROR — No bank account to change (404):
{
  "success": false,
  "message": "No bank account found. Please add one first."
}

ERROR — Pending withdrawal exists (400):
{
  "success": false,
  "message": "Cannot change bank while a withdrawal is pending."
}
```

### 4.5 New API — DELETE /api/bank-account (Remove)

```
ENDPOINT: DELETE /api/bank-account
AUTH: Bearer Token (required)

SUCCESS RESPONSE (200):
{
  "success": true,
  "message": "Bank account removed successfully."
}

ERROR — Pending withdrawal exists (400):
{
  "success": false,
  "message": "Cannot delete bank while a withdrawal is pending."
}
```

---

## 5. Frontend Guidance (Flutter)

### 5.1 Screens Needed

```
FLUTTER SCREENS
================

SCREEN 1: Bank Details Form (NEW)
===================================
  When to show:
    → User taps "Withdraw" AND has no bank account
    → OR user goes to Settings → Bank Account

  Fields:
    +-----------------------------------+
    |  Date of Birth                    |
    |  [DatePicker]                     |
    |                                   |
    |  Bank Name                        |
    |  [TextField]                      |
    |                                   |
    |  Account Number                   |
    |  [TextField - numeric]            |
    |                                   |
    |  IBAN (optional)                  |
    |  [TextField]                      |
    |                                   |
    |  Account Type                     |
    |  [Dropdown: Savings/Checking]     |
    |                                   |
    |  Country                          |
    |  [Dropdown or auto-detect]        |
    |                                   |
    |  [Save Account]                   |
    +-----------------------------------+

  API: POST /api/bank-account
  On success: Navigate to Withdraw screen


SCREEN 2: View/Manage Bank Account (NEW)
==========================================
  Shows saved bank details (masked)

    +-----------------------------------+
    |  My Bank Account                  |
    |                                   |
    |  Bank:    HBL                     |
    |  Account: ****7890               |
    |  Type:    Savings                 |
    |                                   |
    |  [Change Bank Account]            |
    |  [Delete Bank Account]            |
    +-----------------------------------+

  APIs:
    GET /api/bank-account
    POST /api/bank-account/change
    DELETE /api/bank-account


SCREEN 3: Withdraw Screen (EXISTING — Small Change)
=====================================================
  BEFORE: User taps withdraw → calls withdraw API → may get error

  AFTER:
    1. First call GET /api/bank-account
    2. If has_bank_account = false:
       → Navigate to Bank Details Form first
       → After saving → come back to withdraw
    3. If has_bank_account = true:
       → Show withdraw form as before
       → Call POST /api/payment-holds/withdraw
```

### 5.2 Flutter Logic Flow

```
WITHDRAW BUTTON TAP → FLUTTER LOGIC
=====================================

  onWithdrawTap() {

    // Step 1: Check bank account
    response = GET /api/bank-account

    if (response.data.has_bank_account == false) {
      // Navigate to bank details form
      navigateTo(BankDetailsScreen)
      // After user saves bank details, come back here
      return
    }

    // Step 2: Show amount input
    showWithdrawDialog()
    // User enters amount

    // Step 3: Call withdraw
    response = POST /api/payment-holds/withdraw
    { "amount": enteredAmount }

    if (response.success) {
      showSuccess("Withdrawal of $amount completed!")
    } else {
      showError(response.message)
    }
  }
```

---

## 6. Security

### 6.1 Security Rules

```
SECURITY CHECKLIST
===================

+---+------------------------------------------+------------------+
| # | Rule                                     | Implementation   |
+---+------------------------------------------+------------------+
| 1 | Encrypt all bank details at rest         | Laravel encrypted |
|   |                                          | cast (AES-256)   |
+---+------------------------------------------+------------------+
| 2 | Never return full account number         | Always mask:      |
|   |                                          | ****7890          |
+---+------------------------------------------+------------------+
| 3 | Blind index for DB lookups               | HMAC-SHA256 same  |
|   |                                          | as User model     |
+---+------------------------------------------+------------------+
| 4 | One bank account per user                | Unique constraint |
|   |                                          | on user_id        |
+---+------------------------------------------+------------------+
| 5 | Auth required for all bank APIs          | auth:sanctum      |
|   |                                          | middleware        |
+---+------------------------------------------+------------------+
| 6 | Rate limit bank changes                  | 3 per day for     |
|   |                                          | change, 5/hr add  |
+---+------------------------------------------+------------------+
| 7 | Block changes during pending withdrawal  | Check transfer    |
|   |                                          | status before     |
|   |                                          | change/delete     |
+---+------------------------------------------+------------------+
| 8 | Log every bank account change            | Laravel Log with  |
|   |                                          | user_id, action,  |
|   |                                          | timestamp         |
+---+------------------------------------------+------------------+
| 9 | Stripe handles bank verification         | Stripe validates  |
|   |                                          | account on their  |
|   |                                          | end               |
+---+------------------------------------------+------------------+
|10 | TOS acceptance recorded                  | IP + timestamp    |
|   |                                          | sent to Stripe    |
+---+------------------------------------------+------------------+
```

### 6.2 What You DON'T Need to Worry About

```
STRIPE HANDLES THESE:
  - Bank account validation (is the account real?)
  - KYC compliance (identity verification)
  - Fraud detection
  - Payment routing
  - Currency conversion
  - Transfer failures & retries

YOUR APP HANDLES THESE:
  - Encrypting bank details in YOUR database
  - Masking account numbers in API responses
  - Rate limiting bank changes
  - Preventing changes during pending withdrawals
  - Logging all bank account actions
```

---

## 7. Files Changed Summary

```
FILES OVERVIEW
===============

NEW FILES (5):
  database/migrations/xxxx_create_user_bank_accounts_table.php
  app/Models/UserBankAccount.php
  app/Http/Controllers/Api/BankAccountController.php
  app/Http/Requests/Bank/StoreBankAccountRequest.php
  app/Http/Requests/Bank/ChangeBankAccountRequest.php

MODIFIED FILES (3):
  routes/api.php                                    → Add 4 routes
  app/Models/User.php                               → Add bankAccount() relationship
  app/Http/Controllers/Api/PaymentHoldController.php → Change 1 error message (line 448)

EXISTING FILES — NO CHANGES (everything else):
  app/Services/StripeService.php                    → createTransfer() stays same
  app/Services/PaymentSheetService.php              → No change
  app/Http/Controllers/Api/PaymentSheetController.php → No change
  app/Http/Controllers/Api/TransactionHistoryController.php → No change
  app/Models/Payment.php                            → No change
  app/Models/PaymentHold.php                        → No change
  app/Models/Transfer.php                           → No change
  app/Models/StripeConnectAccount.php               → No change
```

```
VISUAL SUMMARY OF CHANGES
===========================

  app/
  ├── Http/
  │   ├── Controllers/Api/
  │   │   ├── BankAccountController.php          ← NEW
  │   │   ├── PaymentHoldController.php          ← MODIFY (1 line)
  │   │   ├── PaymentSheetController.php         (no change)
  │   │   ├── StripeController.php               (no change)
  │   │   └── TransactionHistoryController.php   (no change)
  │   └── Requests/
  │       └── Bank/
  │           ├── StoreBankAccountRequest.php     ← NEW
  │           └── ChangeBankAccountRequest.php    ← NEW
  ├── Models/
  │   ├── User.php                               ← MODIFY (add relationship)
  │   ├── UserBankAccount.php                     ← NEW
  │   ├── Payment.php                            (no change)
  │   ├── PaymentHold.php                        (no change)
  │   ├── Transfer.php                           (no change)
  │   └── StripeConnectAccount.php               (no change)
  └── Services/
      ├── StripeService.php                      (no change)
      └── PaymentSheetService.php                (no change)

  database/migrations/
  └── xxxx_create_user_bank_accounts_table.php    ← NEW

  routes/
  └── api.php                                     ← MODIFY (add 4 routes)
```

---

## Summary

```
WHAT WE'RE DOING:
  Adding a simple bank details form so users can withdraw
  money directly to their bank — without Stripe onboarding.

WHAT CHANGES:
  5 new files + 3 small edits in existing files.

WHAT DOESN'T CHANGE:
  Payment flow, hold logic, withdraw logic, transfer logic,
  transaction history — everything stays the same.

USER EXPERIENCE:
  One-time 1-minute bank details form.
  After that, every withdrawal is instant and automatic.
  No admin involvement. No Stripe redirect. No onboarding.
```
