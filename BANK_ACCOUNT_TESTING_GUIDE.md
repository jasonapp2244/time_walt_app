# Bank Account API — Postman Testing Guide

## Complete Testing Flow for Bank Details + Custom Connect

---

## Prerequisites

1. **Server running**: `composer dev` (starts server + queue + vite)
2. **Auth token**: Login via `POST /api/auth/login` → copy `token`
3. **Stripe test keys** in `.env` (already configured)
4. **Migration done**: `php artisan migrate` (already ran)

### Postman Setup

```
Base URL:    http://127.0.0.1:8000
Headers (all requests):
  Authorization: Bearer {{token}}
  Content-Type:  application/json
  Accept:        application/json
```

---

## Test 1: Check Bank Account (No Bank Yet)

```
GET {{base_url}}/api/bank-account

Headers:
  Authorization: Bearer {{token}}
  Accept: application/json
```

### Expected Response (200):
```json
{
    "success": true,
    "data": {
        "has_bank_account": false
    }
}
```

✅ No bank account exists yet — correct.

---

## Test 2: Add Bank Account (US — Test Mode)

```
POST {{base_url}}/api/bank-account

Headers:
  Authorization: Bearer {{token}}
  Content-Type: application/json
  Accept: application/json

Body (JSON):
{
    "dob": "1995-03-15",
    "account_number": "000123456789",
    "bank_name": "Test Bank",
    "routing_number": "110000000",
    "account_type": "checking",
    "country": "US",
    "currency": "usd"
}
```

### Expected Response (201):
```json
{
    "success": true,
    "message": "Bank account added successfully.",
    "data": {
        "id": 1,
        "account_holder_name": "User Name",
        "bank_name": "Test Bank",
        "account_number": "****6789",
        "account_type": "checking",
        "country": "US",
        "currency": "USD",
        "is_primary": true,
        "created_at": "2026-04-24T..."
    }
}
```

### Verify:
- ✅ `user_bank_accounts` table → new row (account_number encrypted)
- ✅ `stripe_connect_accounts` table → new row with `acct_xxx` (status: verified)
- ✅ Stripe Dashboard → Connect → Accounts → `acct_xxx` exists
- ✅ Account number returned as `****6789` (masked)

### Stripe Test Bank Numbers:

| Country | Routing Number | Account Number   | Notes              |
|---------|---------------|------------------|--------------------|
| US      | `110000000`   | `000123456789`   | Standard test bank |
| US      | `110000000`   | `000111111116`   | Will fail verify   |
| US      | `110000000`   | `000222222227`   | Requires micro-deposits |

---

## Test 3: Add Bank Account Again (Duplicate — Should Fail)

```
POST {{base_url}}/api/bank-account

Body (JSON):
{
    "dob": "1995-03-15",
    "account_number": "999888777666",
    "bank_name": "Another Bank",
    "routing_number": "110000000",
    "account_type": "savings",
    "country": "US",
    "currency": "usd"
}
```

### Expected Response (400):
```json
{
    "success": false,
    "message": "Bank account already exists. Use change endpoint to update."
}
```

✅ One bank per user enforced.

---

## Test 4: View Bank Account (Masked)

```
GET {{base_url}}/api/bank-account

Headers:
  Authorization: Bearer {{token}}
  Accept: application/json
```

### Expected Response (200):
```json
{
    "success": true,
    "data": {
        "id": 1,
        "account_holder_name": "User Name",
        "bank_name": "Test Bank",
        "account_number": "****6789",
        "account_type": "checking",
        "country": "US",
        "currency": "USD",
        "is_primary": true,
        "has_bank_account": true,
        "created_at": "2026-04-24T..."
    }
}
```

✅ Full account number NEVER returned — only `****6789`.

---

## Test 5: Change Bank Account

```
POST {{base_url}}/api/bank-account/change

Headers:
  Authorization: Bearer {{token}}
  Content-Type: application/json
  Accept: application/json

Body (JSON):
{
    "account_number": "000999888777",
    "bank_name": "New Test Bank",
    "routing_number": "110000000",
    "account_type": "savings",
    "country": "US",
    "currency": "usd"
}
```

### Expected Response (200):
```json
{
    "success": true,
    "message": "Bank account updated successfully.",
    "data": {
        "id": 1,
        "bank_name": "New Test Bank",
        "account_number": "****8777",
        "account_type": "savings",
        "country": "US"
    }
}
```

### Verify:
- ✅ Stripe Dashboard → Connect → `acct_xxx` → External Accounts → old `ba_xxx` deleted, new `ba_xxx` added
- ✅ DB `user_bank_accounts` → updated with new encrypted values
- ✅ Masked account number changed to `****8777`

---

## Test 6: Delete Bank Account

```
DELETE {{base_url}}/api/bank-account

Headers:
  Authorization: Bearer {{token}}
  Accept: application/json
```

### Expected Response (200):
```json
{
    "success": true,
    "message": "Bank account removed successfully."
}
```

### Verify:
- ✅ `user_bank_accounts` table → row deleted
- ✅ `stripe_connect_accounts` table → row still exists (Connect account kept for reuse)
- ✅ `GET /api/bank-account` now returns `has_bank_account: false`

---

## Test 7: Withdraw Without Bank Account (Should Fail)

```
POST {{base_url}}/api/payment-holds/withdraw

Headers:
  Authorization: Bearer {{token}}
  Content-Type: application/json
  Accept: application/json

Body (JSON):
{
    "amount": 10.00
}
```

### Expected Response (400):
```json
{
    "success": false,
    "message": "No bank account found. Please add your bank details first."
}
```

✅ User must add bank details before withdrawing.

---

## Test 8: Full Withdrawal Flow (End-to-End)

### Step 1: Add bank account
```
POST /api/bank-account
Body: { "dob": "1995-03-15", "account_number": "000123456789", "bank_name": "Test Bank", "routing_number": "110000000", "account_type": "checking", "country": "US", "currency": "usd" }
→ 201 success
```

### Step 2: Create a payment (if you don't have one)
```
POST /api/stripe/create-payment-intent
Body: { "amount": 50.00, "currency": "usd", "hold_period_type": "custom", "hold_start_at": "2026-04-24", "hold_end_at": "2026-04-25", "title": "Test Hold" }
→ Get payment_intent_id
```

### Step 3: Simulate payment in Stripe (since Payment Sheet is Flutter-side)
```
php artisan tinker
\Stripe\Stripe::setApiKey(config('services.stripe.secret'));
\Stripe\PaymentIntent::retrieve('pi_xxx')->confirm(['payment_method' => 'pm_card_visa']);
```

### Step 4: Confirm payment
```
POST /api/stripe/confirm-payment
Body: { "payment_intent_id": "pi_xxx" }
→ Payment + PaymentHold created
```

### Step 5: Make hold ready (update hold_end_at to past OR run cron)
```
php artisan check:payment-holds
→ Hold status: holding → ready_for_transfer
```

### Step 6: Withdraw
```
POST /api/payment-holds/withdraw
Body: { "amount": 25.00 }
→ Stripe Transfer created → money sent to user's bank
```

---

## Test 9: Validation Errors

### Missing required fields:
```
POST /api/bank-account
Body: { "account_number": "123" }

Expected (422):
{
    "message": "The dob field is required. (and 4 more errors)",
    "errors": {
        "dob": ["The dob field is required."],
        "bank_name": ["The bank name field is required."],
        "account_type": ["The account type field is required."],
        "country": ["The country field is required."],
        "currency": ["The currency field is required."]
    }
}
```

### Invalid account_number length:
```
POST /api/bank-account
Body: { ..., "account_number": "123" }

Expected (422):
{
    "errors": {
        "account_number": ["The account number field must be at least 5 characters."]
    }
}
```

### Invalid routing_number (must be exactly 9 digits):
```
POST /api/bank-account
Body: { ..., "routing_number": "12345" }

Expected (422):
{
    "errors": {
        "routing_number": ["The routing number field must be 9 characters."]
    }
}
```

### Invalid account_type:
```
POST /api/bank-account
Body: { ..., "account_type": "business" }

Expected (422):
{
    "errors": {
        "account_type": ["The selected account type is invalid."]
    }
}
```

---

## Test 10: Change/Delete With Pending Withdrawal (Should Fail)

If a transfer has `status: pending`:

```
POST /api/bank-account/change
→ 400: "Cannot change bank while a withdrawal is pending."

DELETE /api/bank-account
→ 400: "Cannot delete bank while a withdrawal is pending."
```

✅ Bank details are locked during active withdrawals.

---

## Postman Environment Variables

```

base_url:  http://127.0.0.1:8000
token:     (from login response)
```

## Quick Reference — All Bank Account Endpoints

| Method | Endpoint                  | Purpose                    | Auth     |
|--------|---------------------------|----------------------------|----------|
| POST   | `/api/bank-account`       | Add bank + Create Connect  | Required |
| GET    | `/api/bank-account`       | View masked bank details   | Required |
| POST   | `/api/bank-account/change`| Replace bank (Stripe rule) | Required |
| DELETE | `/api/bank-account`       | Remove bank account        | Required |
