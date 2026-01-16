# Stripe Testing - Quick Reference

## 🚀 Quick Setup (5 Minutes)

### 1. Stripe Dashboard
```
1. Login: https://dashboard.stripe.com
2. Get API Keys: Developers → API Keys
3. Setup Webhook: Developers → Webhooks
   - URL: http://localhost:8000/api/stripe/webhook
   - Events: payment_intent.*, account.*, transfer.*
4. Copy Webhook Secret (whsec_xxx)
```

### 2. Local Setup
```bash
# Install
composer require stripe/stripe-php

# Migrate
php artisan migrate

# Start Queue (Terminal 1)
php artisan queue:work

# Start Server (Terminal 2)
php artisan serve

# Stripe CLI (Terminal 3 - Optional)
stripe listen --forward-to localhost:8000/api/stripe/webhook
```

### 3. .env Configuration
```env
STRIPE_KEY=pk_test_xxx
STRIPE_SECRET=sk_test_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx
STRIPE_AUTO_TRANSFER=false
ADMIN_EMAIL=admin@example.com
```

---

## 📮 Postman Test Sequence

### 1. Login
```
POST /api/auth/login
Body: { "email": "...", "password": "..." }
→ Copy token
```

### 2. Create Connect Account
```
POST /api/stripe/connect/create
Headers: Authorization: Bearer {token}
→ Get connect_account_id
```

### 3. Create Payment Intent
```
POST /api/stripe/payment-intent
Headers: Authorization: Bearer {token}
Body: {
  "amount": 10000,
  "currency": "usd",
  "hold_period_type": "2_months"
}
→ Get client_secret
```

### 4. Confirm Payment (Stripe Dashboard/CLI)
```
Stripe Dashboard → Payments → Find pi_xxx → Confirm
OR
stripe payment_intents confirm pi_xxx --payment-method pm_card_visa
```

### 5. Check Webhook
```
Database: SELECT * FROM stripe_webhook_events;
→ Status should be "processed"
```

### 6. Check Hold Created
```
Database: SELECT * FROM payment_holds;
→ Status should be "holding"
```

### 7. Test Cron Job
```
php artisan check:payment-holds
→ Holds marked as "ready_for_transfer"
```

### 8. Admin Transfer
```
POST /api/admin/transfer/{hold_id}
Headers: Authorization: Bearer {admin_token}
→ Transfer created
```

---

## 🔍 Common Issues

| Issue | Solution |
|-------|----------|
| API key error | Check `.env` me keys set hain |
| Webhook not received | Use Stripe CLI or ngrok |
| Queue not working | Run `php artisan queue:work` |
| Hold not created | Check webhook logs |
| Transfer failed | Check Connect account verified |

---

## ✅ Test Cards

**Success:**
- `4242 4242 4242 4242` (Visa)
- `5555 5555 5555 4444` (Mastercard)

**Failure:**
- `4000 0000 0000 0002` (Declined)

**Expiry:** Any future date  
**CVC:** Any 3 digits

---

## 📊 Database Checks

```sql
-- Payments
SELECT * FROM payments ORDER BY created_at DESC;

-- Holds
SELECT * FROM payment_holds ORDER BY created_at DESC;

-- Transfers
SELECT * FROM transfers ORDER BY created_at DESC;

-- Webhooks
SELECT * FROM stripe_webhook_events ORDER BY created_at DESC;
```

---

**Full Guide:** See `STRIPE_TESTING_GUIDE.md`
