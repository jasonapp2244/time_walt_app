# Stripe API - Postman Quick Reference

## 🔐 Authentication

**All protected routes require Bearer token:**
```
Authorization: Bearer {your_token}
```

---

## 💳 Payment Intent Flow

### 1. Create Payment Intent
```
POST /api/stripe/payment-intent
Authorization: Bearer {token}

Body (JSON):
{
    "amount": 100.50,              // DOLLARS (not cents)
    "currency": "usd",
    "hold_period_type": "1_month"  // Optional
}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "payment_intent_id": "pi_xxxxx",
        "client_secret": "pi_xxxxx_secret_xxxxx"
    }
}
```

**Important:** 
- Amount dollar mein hai (100.50 = $100.50)
- `client_secret` frontend mein use karein Stripe Checkout ke liye

---

### 2. Payment Return (Automatic)
```
GET /api/stripe/payment/return?payment_intent=pi_xxxxx
```

**Ye automatically trigger hota hai** - Stripe redirect karta hai payment complete hone ke baad.

**Result:**
- Success → Redirects to success URL
- Failure → Redirects to failure URL
- Email notifications sent automatically

---

## 💰 Payout/Transfer Flow

### 1. Check Payment Holds
```
GET /api/payment-holds
Authorization: Bearer {token}
```

### 2. Request Payout
```
POST /api/payment-holds/{hold_id}/request-payout
Authorization: Bearer {token}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "transfer_id": 1,
        "stripe_transfer_id": "tr_xxxxx",
        "status": "pending",
        "amount": 100.50
    }
}
```

**What Happens:**
- Transfer Stripe mein create hota hai
- Email notifications sent (User + Admin)

---

## 🔔 Webhook Testing

### Webhook Endpoint:
```
POST /api/stripe/webhook
```

### Stripe CLI (Recommended):
```bash
# Install Stripe CLI
stripe listen --forward-to http://localhost:8000/api/stripe/webhook

# Test transfer.created event
stripe trigger transfer.created

# Test transfer.failed event
stripe trigger transfer.failed
```

### Manual Test (Postman):

**Headers:**
```
Content-Type: application/json
Stripe-Signature: t=1234567890,v1=signature
```

**Body (transfer.created):**
```json
{
    "id": "evt_test",
    "type": "transfer.created",
    "data": {
        "object": {
            "id": "tr_xxxxx",
            "amount": 10050,
            "currency": "usd",
            "destination": "acct_xxxxx",
            "status": "paid"
        }
    }
}
```

**Note:** Webhook signature verification ke liye proper signature chahiye. Stripe CLI recommended hai.

---

## 📋 Complete Flow

### Payment:
```
POST /payment-intent → Frontend Payment → GET /payment/return → Email Sent
```

### Payout:
```
Cron Job → Status: ready_for_transfer → POST /request-payout → Transfer Created → Webhook → Email Sent
```

---

## ✅ Testing Checklist

- [ ] Payment Intent: Amount dollar mein send kiya
- [ ] Payment Return: Automatic redirect working
- [ ] Payout Request: Transfer created successfully
- [ ] Webhook: Events properly handled
- [ ] Emails: Queue worker running, emails sent

---

## 🛠️ Setup Commands

```bash
# Queue Worker (Email ke liye)
php artisan queue:work

# Cron Job (Hold period check)
php artisan schedule:work

# Or manually
php artisan check:payment-holds
```

---

## 📝 Notes

1. **Amount**: Always in DOLLARS (100.50)
2. **Webhook**: Use Stripe CLI for local testing
3. **Emails**: Queue worker must be running
4. **Return URL**: Automatically handled by Stripe redirect
