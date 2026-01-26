# Stripe Payment Intent, Transfer & Webhook Testing Guide

## Complete Testing Flow with Postman

---

## Prerequisites

1. **Authentication Token**: Pehle login karein aur token lein
2. **Stripe Connect Account**: User ke paas Connect account hona chahiye
3. **Queue Worker**: Email ke liye queue worker chalao: `php artisan queue:work`

---

## Step 1: Login & Get Token

### Request:
```
POST http://localhost:8000/api/auth/login
Content-Type: application/json

{
    "email": "user@example.com",
    "password": "password123"
}
```

### Response:
```json
{
    "success": true,
    "data": {
        "token": "1|xxxxxxxxxxxxx",
        "user": {...}
    }
}
```

**Token ko save karein** - sab requests mein use hoga.

---

## Step 2: Create Stripe Connect Account (First Time Only)

### Request:
```
POST http://localhost:8000/api/stripe/connect/create
Authorization: Bearer {token}
Content-Type: application/json
```

### Response:
```json
{
    "success": true,
    "data": {
        "connect_account_id": "acct_xxxxx",
        "status": "pending"
    }
}
```

---

## Step 3: Get Onboarding Link

### Request:
```
POST http://localhost:8000/api/stripe/connect/onboarding-link
Authorization: Bearer {token}
Content-Type: application/json
```

### Response:
```json
{
    "success": true,
    "data": {
        "onboarding_url": "https://connect.stripe.com/setup/..."
    }
}
```

**Onboarding URL ko browser mein open karein** aur complete karein.

---

## Step 4: Create Payment Intent

### Request:
```
POST http://localhost:8000/api/stripe/payment-intent
Authorization: Bearer {token}
Content-Type: application/json

{
    "amount": 100.50,
    "currency": "usd",
    "hold_period_type": "1_month",
    "return_url": "http://localhost:8000/api/stripe/payment/return"
}
```

### Request Body Options:
```json
{
    "amount": 100.50,                    // REQUIRED: Amount in DOLLARS (not cents)
    "currency": "usd",                   // REQUIRED: usd, eur, gbp
    "hold_period_type": "1_month",      // OPTIONAL: 1_month, 2_months, 6_months, 1_year, custom
    "hold_start_at": "2026-01-27",      // OPTIONAL: For custom period
    "hold_end_at": "2026-02-27",        // OPTIONAL: For custom period
    "hold_days": 30,                     // OPTIONAL: For custom period
    "return_url": "http://..."           // OPTIONAL: Custom return URL
}
```

### Response:
```json
{
    "success": true,
    "message": "Payment intent created successfully.",
    "data": {
        "payment_intent_id": "pi_xxxxx",
        "client_secret": "pi_xxxxx_secret_xxxxx",
        "hold_period": {
            "type": "1_month",
            "start_at": "2026-01-27T10:00:00Z",
            "end_at": "2026-02-26T10:00:00Z",
            "days": 30
        }
    }
}
```

**Important Points:**
- `amount` dollar mein hai (100.50 = $100.50)
- `client_secret` frontend mein use hoga Stripe Checkout ke liye
- Payment record database mein `pending` status ke saath create hota hai

---

## Step 5: Payment Processing (Frontend/Stripe)

**Frontend se payment complete karein** Stripe Checkout use karke.

**Test Card:**
- Card Number: `4242 4242 4242 4242`
- Expiry: Any future date
- CVC: Any 3 digits

---

## Step 6: Payment Return Handler (Automatic Redirect)

**Stripe automatically redirect karta hai** is URL par:
```
GET http://localhost:8000/api/stripe/payment/return?payment_intent=pi_xxxxx&payment_intent_client_secret=pi_xxxxx_secret_xxxxx
```

**Ye automatically trigger hota hai** - manually call nahi karna.

**Response (HTML Redirect):**
- Success: Redirects to `payment_success_url`
- Failure: Redirects to `payment_failed_url`

**What Happens:**
1. Payment status check hota hai Stripe se
2. Database update hota hai
3. Payment hold create hota hai (success par)
4. Email notifications send hoti hain (User + Admin)

---

## Step 7: Check Payment Holds

### Request:
```
GET http://localhost:8000/api/payment-holds
Authorization: Bearer {token}
```

### Response:
```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "amount": 100.50,
            "status": "holding",
            "hold_start_at": "2026-01-27T10:00:00Z",
            "hold_end_at": "2026-02-26T10:00:00Z",
            "hold_days": 30,
            "hold_period_type": "1_month",
            "can_request_payout": false,
            "payment": {
                "id": 1,
                "payment_intent_id": "pi_xxxxx",
                "status": "succeeded"
            }
        }
    ]
}
```

---

## Step 8: Hold Period Complete (Cron Job)

**Cron job automatically** hold period complete hone par status update karta hai.

**Manual Test:**
```bash
php artisan check:payment-holds
```

**Result:**
- Status: `holding` → `ready_for_transfer`
- Email: Hold period ended notification

---

## Step 9: Request Payout

### Request:
```
POST http://localhost:8000/api/payment-holds/{hold_id}/request-payout
Authorization: Bearer {token}
Content-Type: application/json
```

**Example:**
```
POST http://localhost:8000/api/payment-holds/1/request-payout
Authorization: Bearer {token}
```

### Response:
```json
{
    "success": true,
    "message": "Payout request submitted successfully. Transfer will be processed shortly.",
    "data": {
        "transfer_id": 1,
        "stripe_transfer_id": "tr_xxxxx",
        "status": "pending",
        "amount": 100.50,
        "currency": "usd"
    }
}
```

**What Happens:**
1. Transfer Stripe mein create hota hai
2. Database mein transfer record create hota hai
3. Email notifications send hoti hain (User + Admin)

---

## Step 10: Webhook Testing (Transfer Events)

### Webhook URL:
```
POST http://localhost:8000/api/stripe/webhook
```

**Note:** Webhook testing ke liye Stripe CLI use karein ya Stripe Dashboard se test events send karein.

### Stripe CLI Setup:
```bash
stripe listen --forward-to http://localhost:8000/api/stripe/webhook
```

### Test Transfer Created Event:
```bash
stripe trigger transfer.created
```

### Test Transfer Failed Event:
```bash
stripe trigger transfer.failed
```

### Manual Webhook Test (Postman):

**Headers:**
```
Content-Type: application/json
Stripe-Signature: t=timestamp,v1=signature
```

**Body (transfer.created):**
```json
{
    "id": "evt_test_webhook",
    "object": "event",
    "type": "transfer.created",
    "data": {
        "object": {
            "id": "tr_xxxxx",
            "object": "transfer",
            "amount": 10050,
            "currency": "usd",
            "destination": "acct_xxxxx",
            "status": "paid"
        }
    }
}
```

**Response:**
```json
{
    "received": true
}
```

**What Happens:**
1. Webhook signature verify hota hai
2. Transfer status update: `completed`
3. Hold status update: `transferred`
4. Email notifications send hoti hain (User + Admin)

---

## Complete Flow Summary:

### Payment Flow:
```
1. Create Payment Intent (POST /payment-intent)
   ↓
2. Frontend Payment (Stripe Checkout)
   ↓
3. Payment Return Handler (GET /stripe/payment/return)
   ↓
4. Payment Hold Created
   ↓
5. Email: Payment Success
```

### Payout Flow:
```
1. Hold Period Complete (Cron Job)
   ↓
2. Status: ready_for_transfer
   ↓
3. Email: Hold Period Ended
   ↓
4. User Requests Payout (POST /payment-holds/{id}/request-payout)
   ↓
5. Transfer Created in Stripe
   ↓
6. Email: Payout Request Received
   ↓
7. Stripe Processes Transfer
   ↓
8. Webhook: transfer.created
   ↓
9. Transfer Status: completed
   ↓
10. Email: Transfer Completed
```

---

## Testing Checklist:

### Payment Intent:
- [ ] Create payment intent with amount in dollars
- [ ] Check response has `client_secret`
- [ ] Verify payment record created in database
- [ ] Test with different hold periods

### Payment Return:
- [ ] Complete payment via Stripe Checkout
- [ ] Verify redirect to success/failure URL
- [ ] Check payment status updated in database
- [ ] Verify payment hold created
- [ ] Check email notifications sent

### Payout Request:
- [ ] Wait for hold period to complete (or manually update)
- [ ] Request payout
- [ ] Verify transfer created in Stripe
- [ ] Check email notifications sent
- [ ] Verify transfer record in database

### Webhook:
- [ ] Test transfer.created event
- [ ] Test transfer.failed event
- [ ] Verify transfer status updated
- [ ] Verify hold status updated
- [ ] Check email notifications sent

---

## Important Notes:

1. **Amount Format**: Always send in DOLLARS (100.50), not cents
2. **Queue Worker**: Email ke liye `php artisan queue:work` chalao
3. **Webhook Secret**: `.env` mein `STRIPE_WEBHOOK_SECRET` set karein
4. **Return URLs**: `.env` mein proper URLs set karein
5. **Cron Job**: `php artisan schedule:work` chalao for automatic hold processing

---

## Common Issues & Solutions:

### Issue: Payment Intent Creation Fails
- Check authentication token
- Verify amount is numeric (not string)
- Check Stripe API keys in `.env`

### Issue: Payment Return Not Working
- Check return URL in config
- Verify payment_intent parameter in URL
- Check logs: `storage/logs/laravel.log`

### Issue: Webhook Not Receiving Events
- Verify webhook secret in `.env`
- Check Stripe webhook endpoint URL
- Use Stripe CLI for local testing

### Issue: Emails Not Sending
- Check queue worker is running
- Verify mail configuration in `.env`
- Check `storage/logs/laravel.log` for errors

---

## Environment Variables Required:

```env
STRIPE_KEY=pk_test_xxxxx
STRIPE_SECRET=sk_test_xxxxx
STRIPE_WEBHOOK_SECRET=whsec_xxxxx
STRIPE_PAYMENT_RETURN_URL=http://localhost:8000/api/stripe/payment/return
STRIPE_PAYMENT_SUCCESS_URL=http://localhost:8000/payment/success
STRIPE_PAYMENT_FAILED_URL=http://localhost:8000/payment/failed
MAIL_ADMIN_EMAIL=admin@example.com
```

---

## Postman Collection Structure:

```
📁 Stripe Payment Flow
  ├── 1. Login
  ├── 2. Create Connect Account
  ├── 3. Get Onboarding Link
  ├── 4. Create Payment Intent
  └── 5. Check Payment Holds

📁 Payout Flow
  ├── 1. Request Payout
  ├── 2. Check Transfer Status
  └── 3. View Payment Holds Summary

📁 Webhook Testing
  ├── Transfer Created (Manual)
  ├── Transfer Failed (Manual)
  └── Transfer Canceled (Manual)
```

---

**Happy Testing! 🚀**
