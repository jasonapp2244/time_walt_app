# Stripe API Implementation - Quick Reference

## 📁 Files to Create (In Order)

### Phase 1: Models (5 files)
```
app/Models/StripeConnectAccount.php
app/Models/Payment.php
app/Models/PaymentHold.php
app/Models/Transfer.php
app/Models/StripeWebhookEvent.php
```

### Phase 2: Form Requests (4 files)
```
app/Http/Requests/Stripe/CreateConnectAccountRequest.php
app/Http/Requests/Stripe/GetOnboardingLinkRequest.php
app/Http/Requests/Stripe/CreatePaymentIntentRequest.php
app/Http/Requests/Admin/TransferRequest.php
```

### Phase 3: Services (3 files)
```
app/Services/StripeService.php
app/Services/PaymentHoldService.php
app/Services/WebhookService.php
```

### Phase 4: Controllers (2 files)
```
app/Http/Controllers/Api/StripeController.php
app/Http/Controllers/Api/Admin/TransferController.php
```

### Phase 5: Email Notifications (10 files)
```
app/Mail/Stripe/PaymentSuccessMail.php
app/Mail/Stripe/HoldPeriodEndedMail.php
app/Mail/Stripe/TransferCompletedMail.php
app/Mail/Stripe/PaymentFailedMail.php
app/Mail/Stripe/TransferFailedMail.php
app/Jobs/SendPaymentSuccessNotification.php
app/Jobs/SendHoldPeriodEndedNotification.php
app/Jobs/SendTransferCompletedNotification.php
app/Jobs/SendPaymentFailedNotification.php
app/Jobs/SendTransferFailedNotification.php
```

### Phase 6: Commands (1 file)
```
app/Console/Commands/CheckPaymentHolds.php
```

---

## 🔄 API Endpoints Summary

### User Endpoints (Auth Required)
```
POST /api/stripe/connect/create
POST /api/stripe/connect/onboarding-link
POST /api/stripe/payment-intent
```

### Admin Endpoints (Admin Auth Required)
```
POST /api/admin/transfer/{hold_id}
```

### Webhook Endpoint (No Auth)
```
POST /api/stripe/webhook
```

---

## 🗄️ Database Tables Used

1. **stripe_connect_accounts** - User Stripe accounts
2. **payments** - Payment records
3. **payment_holds** - Hold records
4. **transfers** - Transfer records
5. **stripe_webhook_events** - Webhook tracking

---

## 🔑 Key Logic Flow

### Payment Flow:
1. User creates payment intent → Store hold period in metadata
2. User pays → Webhook `payment_intent.succeeded`
3. Webhook creates payment hold → Status: `holding`
4. Cron checks holds → Status: `ready_for_transfer`
5. Transfer created → Status: `pending`
6. Webhook `transfer.created` → Status: `completed`

### Hold Period Flow:
1. User selects hold period (1 month, 2 months, etc.)
2. Send with payment intent creation
3. Store in PaymentIntent metadata
4. Retrieve in webhook
5. Create payment_holds with custom dates

---

## ⚙️ Configuration Needed

### .env
```
STRIPE_KEY=pk_test_xxx
STRIPE_SECRET=sk_test_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx
STRIPE_AUTO_TRANSFER=false
```

### config/services.php
```php
'stripe' => [
    'key' => env('STRIPE_KEY'),
    'secret' => env('STRIPE_SECRET'),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    'auto_transfer_enabled' => env('STRIPE_AUTO_TRANSFER', false),
],
```

---

## 📦 Dependencies

```bash
composer require stripe/stripe-php
```

---

## ⏰ Cron Job Schedule

**Command:** `check:payment-holds`
**Schedule:** Daily at 00:00
**Purpose:** Check holds where `hold_end_at <= NOW()` and mark as `ready_for_transfer`
**Email:** Sends notification to user + admin when hold period ends

---

## 📧 Email Notifications Summary

| Event | When | Send To | Via Queue |
|-------|------|---------|-----------|
| Payment Success | Payment webhook succeeds | User + Admin | ✅ Yes |
| Payment Failed | Payment webhook fails | User + Admin | ✅ Yes |
| Hold Period Ended | Cron job marks ready | User + Admin | ✅ Yes |
| Transfer Completed | Transfer webhook succeeds | User + Admin | ✅ Yes |
| Transfer Failed | Transfer webhook fails | User + Admin | ✅ Yes |

### Email Configuration:
```env
ADMIN_EMAIL=admin@example.com
```

### Queue Worker:
```bash
php artisan queue:work
```

---

## 🧪 Testing Priority

1. ✅ Create connect account
2. ✅ Create payment intent
3. ✅ Webhook handling
4. ✅ Payment hold creation
5. ✅ Cron job
6. ✅ Transfer creation
7. ✅ Transfer webhook

---

## 📝 Important Notes

- **Hold period data** must be stored in PaymentIntent metadata
- **Webhook signature verification** is CRITICAL
- **Idempotency** - Check duplicate webhooks
- **Error handling** - Log all failures
- **Admin authorization** - Check admin role for transfer
- **Email notifications** - Always use queue (performance)
- **User preferences** - Check `UserNotificationSetting.email_alert` before sending

---

**Full Guide:** See `STRIPE_API_IMPLEMENTATION_GUIDE.md`
