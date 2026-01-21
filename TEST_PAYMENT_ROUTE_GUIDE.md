# Test Payment Route Guide - Roman Urdu

## New Route: Test Payment With Card

### Endpoint:
```
POST /api/stripe/test-payment
```

### Purpose:
Testing ke liye complete payment flow - payment intent create + test card se payment confirm automatically.

---

## How To Use:

### Step 1: Login Karein

**API:** `POST /api/auth/login`

Token save karo.

---

### Step 2: Test Payment Call Karein

**API:** `POST /api/stripe/test-payment`

**Headers:**
```
Content-Type: application/json
Accept: application/json
Authorization: Bearer {token}
```

**Request Body (Minimum):**
```json
{
    "amount": 5000,
    "currency": "usd"
}
```

**Request Body (With Hold Period):**
```json
{
    "amount": 5000,
    "currency": "usd",
    "hold_period_type": "1_month"
}
```

**Request Body (Simple):**
```json
{
    "amount": 5000,
    "currency": "usd",
    "hold_period_type": "1_month"
}
```

**Default Test Card (Automatic):**
- Card Number: `4242424242424242` (automatic use hoga)
- Expiry: 12/2025 (automatic)
- CVC: 123 (automatic)

**Note:** `card_number` parameter ab use nahi hota - default test card automatic use hota hai.

---

## What This Route Does:

1. ✅ **Payment Intent Create** - Stripe payment intent create karta hai
2. ✅ **Payment Method Create** - Test card se payment method create karta hai
3. ✅ **Payment Confirm** - Payment intent confirm karta hai (automatic)
4. ✅ **Payment Record Create** - Database mein payment record create karta hai
5. ✅ **Payment Hold Create** - Agar payment succeed hua to payment hold create karta hai (webhook simulate karke)

---

## Response:

**Success Response (200):**
```json
{
    "success": true,
    "message": "Test payment completed successfully.",
    "data": {
        "payment_intent_id": "pi_3ABC123xyz",
        "payment_status": "succeeded",
        "payment_id": 1,
        "amount": 50.00,
        "currency": "usd",
        "hold_created": true,
        "hold_period": {
            "type": "1_month",
            "start_at": "2026-01-15T10:00:00Z",
            "end_at": "2026-02-14T10:00:00Z",
            "days": 30
        }
    }
}
```

**Error Response (400) - Card Declined:**
```json
{
    "success": false,
    "message": "Payment failed: Your card was declined.",
    "error": {
        "type": "card_error",
        "code": "card_declined",
        "message": "Your card was declined."
    }
}
```

---

## Test Cards:

| Card Number | Result | Description |
|-------------|--------|-------------|
| `4242424242424242` | ✅ Success | Standard successful card (default) |
| `4000000000000002` | ❌ Declined | Card declined |
| `4000000000009995` | ❌ Insufficient Funds | Insufficient funds |

**Custom Card Use Karne Ke Liye:**
```json
{
    "amount": 5000,
    "card_number": "4000000000000002"
}
```

---

## Complete Example (Postman):

**Request:**
```
POST http://localhost/time_walt_app/public/api/stripe/test-payment
```

**Headers:**
```
Content-Type: application/json
Authorization: Bearer 1|xxxxxxxxxxxxx
```

**Body:**
```json
{
    "amount": 5000,
    "currency": "usd",
    "hold_period_type": "1_month"
}
```

**Response:**
```json
{
    "success": true,
    "message": "Test payment completed successfully.",
    "data": {
        "payment_intent_id": "pi_xxxxx",
        "payment_status": "succeeded",
        "payment_id": 1,
        "amount": 50.00,
        "currency": "usd",
        "hold_created": true,
        "hold_period": {
            "type": "1_month",
            "start_at": "2026-01-15T10:00:00Z",
            "end_at": "2026-02-14T10:00:00Z",
            "days": 30
        }
    }
}
```

---

## Database Check:

**After Test Payment:**

```sql
-- Payment record
SELECT * FROM payments 
WHERE payment_intent_id = 'pi_xxxxx';
-- Status: succeeded ✅

-- Payment hold (if payment succeeded)
SELECT * FROM payment_holds 
WHERE payment_id = (SELECT id FROM payments WHERE payment_intent_id = 'pi_xxxxx');
-- Entry milni chahiye ✅
```

---

## Advantages:

1. ✅ **No Frontend Needed** - Direct backend se test kar sakte ho
2. ✅ **No Stripe CLI Needed** - CLI se payment confirm nahi karna padega
3. ✅ **Complete Flow** - Payment intent + confirm + hold create sab automatic
4. ✅ **Webhook Simulate** - Payment hold automatically create hota hai
5. ✅ **Easy Testing** - Postman se directly test kar sakte ho

---

## Important Notes:

1. **Testing Only** - Production mein is route ko disable karo ya remove karo
2. **Authentication Required** - Bearer token zaroori hai
3. **Test Mode** - Stripe test keys use karo (`.env` mein)
4. **Automatic Hold Creation** - Payment succeed hone par hold automatically create hota hai
5. **Raw Card Data APIs** - Stripe Dashboard mein enable karna padega (see below)

---

## ⚠️ Important: Enable Raw Card Data APIs

Agar error aaye: `"Sending credit card numbers directly to the Stripe API is generally unsafe"`

**Solution:** Stripe Dashboard mein raw card data APIs enable karo:

### Method 1: Stripe Dashboard (Recommended for Testing)

1. **Stripe Dashboard:** https://dashboard.stripe.com/test/settings/payment_methods
2. **Settings** → **API** section mein jao
3. **"Process payments unsafely"** toggle **ON** karo
4. Ya **Settings** → **Payment methods** → **Advanced settings** → **"Enable raw card data APIs"**

**Note:** Yeh sirf **test mode** mein enable karo, production mein mat karo!

### Method 2: Stripe Support (If Option 1 Not Available)

1. Stripe Support se contact karo
2. Request karo: "Enable access to raw card data APIs for testing"
3. Support team enable kar dega

### Method 3: Alternative - Use Stripe CLI

Agar raw card data APIs enable nahi kar sakte, to:

1. Payment intent create karo (API se)
2. Stripe CLI se manually confirm karo:
   ```bash
   stripe payment_intents confirm pi_xxxxx --payment-method=pm_card_visa
   ```

**Test Payment Methods (Stripe CLI):**
- `pm_card_visa` - Success
- `pm_card_visa_debit` - Success
- `pm_card_mastercard` - Success
- `pm_card_chargeDeclined` - Declined
- `pm_card_chargeDeclinedInsufficientFunds` - Insufficient funds

---

## Comparison:

### Old Method (Without This Route):
1. Payment intent create karo
2. Stripe CLI se manually confirm karo
3. Webhook wait karo
4. Payment hold check karo

### New Method (With This Route):
1. Single API call
2. Everything automatic ✅

---

## Quick Test:

```bash
# Postman ya curl se:
curl -X POST http://localhost/time_walt_app/public/api/stripe/test-payment \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "amount": 5000,
    "currency": "usd",
    "hold_period_type": "1_month"
  }'
```

---

**End of Guide**

Ab testing complete kar sakte ho! 🎉
