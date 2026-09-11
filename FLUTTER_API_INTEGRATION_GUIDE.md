# Time Vault - Flutter API Integration Guide

**Base URL:** `https://time-vault.devonlinetestserver.com/api`
**Auth:** All protected endpoints require `Authorization: Bearer {token}` header

---

## IMPORTANT: Timezone Setup

Before using payment/hold APIs, Flutter MUST set the user's timezone:

```
POST /api/profile/timezone
{ "timezone": "America/New_York" }
```

The backend stores all dates in **UTC** and converts using the user's timezone. All API responses return dates in **ISO-8601 UTC format** (e.g., `2026-05-30T22:00:00+00:00`). Flutter should convert to local timezone for display.

---

## Payment Flow (Step by Step)

### Step 1: Create Payment Intent

```
POST /api/stripe/create-payment-intent
```

**Request:**
```json
{
    "amount": 50.00,
    "currency": "usd",
    "title": "My Savings Vault",
    "hold_period_type": "custom",
    "hold_start_at": "2026-05-30 14:30:00",
    "hold_end_at": "2026-05-30 16:00:00"
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `amount` | number | Yes | Minimum $1.00 |
| `currency` | string | Yes | 3 chars: `usd`, `eur`, `gbp` |
| `title` | string | No | Max 255 chars |
| `hold_period_type` | string | Yes | Must be `"custom"` |
| `hold_start_at` | datetime | Yes | Send `DateTime.now()` from Flutter, in user's local time |
| `hold_end_at` | datetime | Yes | User picks this manually, in user's local time |
| `hold_hours` | int | No | 0-23, optional extra hours |
| `hold_minutes` | int | No | 0-59, optional extra minutes |

**Note:** `hold_days`, `hold_hours`, `hold_minutes` are auto-calculated from the start/end difference. Flutter only needs to send `hold_start_at` and `hold_end_at`.

**Success Response (200):**
```json
{
    "success": true,
    "message": "Payment intent created. Use client_secret to present Payment Sheet.",
    "data": {
        "payment_intent_id": "pi_3xxx",
        "client_secret": "pi_3xxx_secret_xxx",
        "customer_id": "cus_xxx",
        "ephemeral_key": "ek_live_xxx",
        "publishable_key": "pk_live_xxx",
        "amount": 50.0,
        "currency": "usd"
    }
}
```

### Step 2: Present Stripe Payment Sheet

Use the `client_secret`, `customer_id`, `ephemeral_key`, and `publishable_key` from Step 1 to present the Stripe Payment Sheet in Flutter.

### Step 3: Confirm Payment

After user completes payment in Payment Sheet:

```
POST /api/stripe/confirm-payment
```

**Request:**
```json
{
    "payment_intent_id": "pi_3xxx"
}
```

**Success Response (200):**
```json
{
    "success": true,
    "message": "Payment confirmed and records created successfully.",
    "data": {
        "payment": {
            "id": 1,
            "payment_intent_id": "pi_xxx",
            "amount": 50.0,
            "currency": "usd",
            "status": "succeeded",
            "paid_at": "2026-05-30T18:30:00+00:00",
            "card_brand": "visa",
            "card_last4": "4242",
            "card_exp_month": 12,
            "card_exp_year": 2027,
            "card_funding": "credit",
            "card_country": "US",
            "payment_method_type": "card"
        },
        "hold": {
            "id": 1,
            "amount": 50.0,
            "status": "holding",
            "hold_start_at": "2026-05-30T18:30:00+00:00",
            "hold_end_at": "2026-05-30T20:00:00+00:00",
            "hold_days": 0,
            "hold_hours": 1,
            "hold_minutes": 30,
            "title": "My Savings Vault"
        }
    }
}
```

---

## Wallet Summary

```
GET /api/payment-holds/summary
```

**Response:**
```json
{
    "success": true,
    "message": "Balance summary retrieved successfully.",
    "summary": {
        "total_balance": 100.0,
        "total_locked_amount": 60.0,
        "total_ready_for_transfer_amount": 40.0,
        "currency": "USD"
    }
}
```

| Field | Meaning |
|-------|---------|
| `total_balance` | locked + ready (total money in platform) |
| `total_locked_amount` | money still in hold period (can't withdraw) |
| `total_ready_for_transfer_amount` | money available to withdraw now |

---

## Transaction History

### All Transactions (5 categories)

```
GET /api/transactions/all
```

**Response has 5 data arrays + summary + counts:**
```json
{
    "success": true,
    "summary": {
        "total_checkout_amount": 500.0,
        "current_balance": 200.0,
        "total_locked_amount": 100.0,
        "available_balance": 100.0,
        "total_hold_amount": 100.0,
        "total_ready_amount": 100.0,
        "currency": "USD"
    },
    "data": {
        "checkout": [...],
        "withdraw": [...],
        "hold_amount": [...],
        "ready_for_transfer": [...],
        "transferred": [...]
    },
    "counts": {
        "checkout_count": 5,
        "withdraw_count": 3,
        "hold_amount_count": 2,
        "ready_for_transfer_count": 1,
        "transferred_count": 4
    }
}
```

### Checkout Item Structure:
```json
{
    "transaction_type": "checkout",
    "transaction_id": "CHK-1",
    "hold_id": 1,
    "amount": 50.0,
    "currency": "USD",
    "status": "succeeded",
    "date": "2026-05-20T10:00:00+00:00",
    "description": "Checkout payment received",
    "hold_start": "2026-05-20T10:00:00+00:00",
    "hold_end": "2026-06-19T10:00:00+00:00",
    "total_days": 30,
    "days_remaining": 20,
    "hours_remaining": 5,
    "minutes_remaining": 30
}
```

### Hold Amount Item Structure:
```json
{
    "hold_id": 3,
    "title": "Amount on Hold",
    "original_amount": 100.0,
    "remaining_amount": 100.0,
    "amount": 100.0,
    "currency": "USD",
    "status": "holding",
    "hold_period": {
        "hold_days": 30,
        "hold_hours": 0,
        "hold_minutes": 0,
        "hold_start_at": "2026-05-20T10:00:00+00:00",
        "hold_end_at": "2026-06-19T10:00:00+00:00",
        "days_remaining": 20,
        "hours_remaining": 20,
        "minutes_remaining": 45,
        "total_minutes_remaining": 29805
    },
    "hold_start": "2026-05-20T10:00:00+00:00",
    "hold_end": "2026-06-19T10:00:00+00:00",
    "total_days": 30,
    "days_remaining": 20,
    "hours_remaining": 20,
    "minutes_remaining": 45
}
```

### Ready for Transfer Item Structure:
```json
{
    "hold_id": 2,
    "title": "Ready for Withdrawal",
    "original_amount": 100.0,
    "remaining_amount": 75.0,
    "already_withdrawn": 25.0,
    "amount": 75.0,
    "currency": "USD",
    "status": "partial_transferred",
    "can_withdraw": true,
    "hold_start": "2026-05-08T10:00:00+00:00",
    "hold_end": "2026-05-15T10:00:00+00:00",
    "total_days": 7,
    "days_remaining": 0,
    "hours_remaining": 0,
    "minutes_remaining": 0
}
```

### Withdraw Item Structure (grouped by request):
```json
{
    "transaction_type": "withdraw",
    "transaction_id": "WDR-GROUP-5",
    "total_amount": 80.0,
    "currency": "USD",
    "status": "completed",
    "date": "2026-05-25T10:00:00+00:00",
    "transfers_count": 2,
    "transfers": [
        {
            "transfer_id": 5,
            "hold_id": 1,
            "amount": 50.0,
            "status": "completed",
            "stripe_transfer_id": "tr_xxx",
            "transferred_at": "2026-05-25T10:00:00+00:00"
        }
    ]
}
```

### Paginated Endpoints:

| Endpoint | Returns |
|----------|---------|
| `GET /api/transactions/checkouts?per_page=15&page=1` | Checkout payments only |
| `GET /api/transactions/withdraws?per_page=15&page=1` | Withdrawals only (grouped) |
| `GET /api/transactions/hold-amounts?per_page=15&page=1` | Currently locked holds |
| `GET /api/transactions/ready-for-transfer?per_page=15&page=1` | Available for withdrawal |

All paginated endpoints include:
```json
{
    "pagination": {
        "current_page": 1,
        "per_page": 15,
        "total": 50,
        "last_page": 4,
        "from": 1,
        "to": 15,
        "has_more_pages": true
    }
}
```

---

## Withdrawal

```
POST /api/payment-holds/withdraw
```

**Request:**
```json
{
    "amount": 50.00
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `amount` | number | Yes | Min $0.50, Max $999,999.99 |

**Success Response (200):**
```json
{
    "success": true,
    "message": "Withdrawal of $50 completed successfully.",
    "data": {
        "requested_amount": 50.0,
        "processed_amount": 50.0,
        "total_transfers": 2,
        "holds_processed": [
            {
                "hold_id": 1,
                "amount": 30.0,
                "transfer_id": 5,
                "status": "completed"
            }
        ],
        "transfers": [
            {
                "hold_id": 1,
                "transfer_id": 5,
                "stripe_transfer_id": "tr_xxx",
                "amount": 30.0,
                "currency": "USD",
                "status": "completed",
                "transferred_at": "2026-05-29T10:00:00+00:00"
            }
        ],
        "status": "completed",
        "all_completed": true,
        "summary": {
            "requested": 50.0,
            "processed": 50.0,
            "difference": 0.0
        }
    }
}
```

**Error - No funds (400):**
```json
{
    "success": false,
    "message": "No funds available for withdrawal. Hold period not completed yet."
}
```

**Error - Insufficient (400):**
```json
{
    "success": false,
    "message": "Insufficient available funds. Available: $40, Requested: $50",
    "data": {
        "available_amount": 40.0,
        "requested_amount": 50.0
    }
}
```

**Error - No bank (400):**
```json
{
    "success": false,
    "message": "No bank account found. Please add your bank details first."
}
```

---

## Bank Account

### Add Bank Account

```
POST /api/bank-account
```

**Request (US):**
```json
{
    "dob": "1990-01-15",
    "account_number": "000123456789",
    "bank_name": "Chase Bank",
    "routing_number": "110000000",
    "account_type": "checking",
    "country": "US",
    "currency": "usd",
    "address_line1": "123 Main St",
    "city": "New York",
    "state": "NY",
    "postal_code": "10001",
    "phone": "+12125551234",
    "ssn_last_4": "1234"
}
```

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| `dob` | date | Yes | Before today |
| `account_number` | string | Yes | 5-34 chars |
| `bank_name` | string | Yes | Max 100 |
| `routing_number` | string | US only | Exactly 9 chars |
| `iban` | string | Non-US | 15-34 chars |
| `account_type` | string | Yes | `savings`, `checking`, `current` |
| `country` | string | Yes | 2 chars: US, GB, CA, AU, DE, FR, etc. |
| `currency` | string | Yes | 3 chars: usd, eur, gbp, cad, etc. |
| `address_line1` | string | Yes | Max 200 |
| `city` | string | Yes | Max 100 |
| `state` | string | Yes | Max 100 |
| `postal_code` | string | Yes | Max 20 |
| `phone` | string | Yes | E.164 format with country code (e.g., `+12125551234`) |
| `ssn_last_4` | string | US only | Exactly 4 chars |
| `id_number` | string | Non-US | Max 30 |

**Success Response (201):**
```json
{
    "success": true,
    "message": "Bank account added successfully.",
    "data": {
        "id": 1,
        "account_holder_name": "John Doe",
        "bank_name": "Chase Bank",
        "account_number": "****6789",
        "account_type": "checking",
        "country": "US",
        "currency": "USD",
        "is_primary": true,
        "created_at": "2026-05-30T10:00:00+00:00"
    }
}
```

### Get Bank Accounts

```
GET /api/bank-account
```

**Response:**
```json
{
    "success": true,
    "data": {
        "has_bank_account": true,
        "total": 2,
        "bank_accounts": [
            {
                "id": 1,
                "account_holder_name": "John Doe",
                "bank_name": "Chase Bank",
                "account_number": "****6789",
                "account_type": "checking",
                "country": "US",
                "currency": "USD",
                "is_primary": true,
                "created_at": "2026-05-30T10:00:00+00:00"
            }
        ]
    }
}
```

### Set Primary Bank

```
POST /api/bank-account/{id}/set-primary
```

### Delete Bank

```
DELETE /api/bank-account/{id}
```

---

## Hold Status Lifecycle

```
holding → ready_for_transfer → partial_transferred → transferred
```

| Status | Meaning | Can Withdraw? |
|--------|---------|---------------|
| `holding` | In lock period | No |
| `ready_for_transfer` | Lock expired, full amount available | Yes |
| `partial_transferred` | Some amount withdrawn | Yes (remaining) |
| `transferred` | Fully withdrawn | No |

---

## Key Implementation Notes for Flutter

1. **Timezone:** Set user timezone via `POST /api/profile/timezone` on app start/login
2. **hold_start_at:** Send `DateTime.now().toString()` (current local time)
3. **hold_end_at:** User-selected end time in local format
4. **Dates in responses:** All ISO-8601 UTC — convert to local for display
5. **Phone for bank:** Must include country code (e.g., `+1` for US)
6. **Withdrawal:** Uses FIFO — oldest holds are drained first
7. **Partial withdrawal:** User can withdraw any amount up to available balance
8. **No platform fees:** Full amount flows through
9. **First bank account** is auto-set as primary
c