# User Payout Aur Webhook Complete Guide - Roman Urdu

## Table of Contents

1. [User Payout Flow (Complete Process)](#user-payout-flow)
2. [Webhook Invalid Signature Error Fix](#webhook-invalid-signature-error-fix)
3. [Step By Step Testing](#step-by-step-testing)

---

## User Payout Flow (Complete Process)

### Overview:

User ko payout milne ke liye yeh steps follow karne hain:

```
Payment Hold Created (Status: holding)
    ↓
Hold Period Complete (hold_end_at pass ho jaye)
    ↓
Status Update: holding → ready_for_transfer (Cron job ya manually)
    ↓
Admin Transfer API Call Karta Hai
    ↓
Stripe Transfer Create Hota Hai
    ↓
Webhook: transfer.created
    ↓
Transfer Status: pending → completed
    ↓
Payment Hold Status: ready_for_transfer → transferred
    ↓
User Ko Payout Mil Jata Hai ✅
```

---

## Step 1: Payment Hold Created

**Current Status:**
- ✅ Payment successful: `pi_3Ss6YuPk2gzYRxNS0mG7rvxz`
- ✅ Payment ID: 10
- ✅ Payment Hold created: `hold_created: true`

**Database Check:**
```sql
SELECT * FROM payment_holds 
WHERE payment_id = 10;
```

**Expected:**
- Status: `holding`
- `hold_end_at`: Future date (30 days from now if 1_month)
- `ready_at`: NULL (abhi NULL hoga)

---

## Step 2: Hold Period Complete (Wait)

**Hold period complete hone tak wait karo:**

```sql
-- Check holds ready for transfer
SELECT * FROM payment_holds 
WHERE status = 'holding' 
AND hold_end_at <= NOW();
```

**Agar hold period complete ho gaya:**
- Status manually update karo: `holding` → `ready_for_transfer`
- Ya cron job automatically update karega

**Manual Update:**
```sql
UPDATE payment_holds 
SET status = 'ready_for_transfer', 
    ready_at = NOW() 
WHERE id = {hold_id} 
AND hold_end_at <= NOW();
```

---

## Step 3: Admin Transfer API Call

**Endpoint:** `POST /api/admin/transfer/{hold_id}`

**Prerequisites:**
1. Admin user ka token chahiye (`role = 'admin'`)
2. Payment hold `ready_for_transfer` status mein hona chahiye
3. User ka Stripe Connect account verified hona chahiye

### Step 3.1: Admin User Create/Update

**Database mein user ko admin banao:**
```sql
UPDATE users 
SET role = 'admin' 
WHERE id = {user_id};
```

Ya new admin user create karo:
```sql
INSERT INTO users (full_name, email, phone, password, role, is_verified, status)
VALUES ('Admin User', 'admin@example.com', '1234567890', '$2y$10$...', 'admin', 1, 'active');
```

### Step 3.2: Admin Login

**API:** `POST /api/auth/login`

**Request:**
```json
{
    "email": "admin@example.com",
    "password": "password123"
}
```

**Response:**
```json
{
    "success": true,
    "data": {
        "token": "1|admin_token_here"
    }
}
```

### Step 3.3: Get Hold ID

**Database se hold ID lein:**
```sql
SELECT id, payment_id, user_id, amount, status, hold_end_at 
FROM payment_holds 
WHERE payment_id = 10;
```

**Example Hold ID:** `1` (apna actual ID use karo)

### Step 3.4: Call Transfer API

**API:** `POST /api/admin/transfer/1`

**Headers:**
```
Content-Type: application/json
Authorization: Bearer {admin_token}
```

**Request Body:**
```json
{}
```

**Success Response (200):**
```json
{
    "success": true,
    "message": "Transfer initiated successfully.",
    "data": {
        "transfer_id": 1,
        "stripe_transfer_id": "tr_xxxxx",
        "status": "pending",
        "amount": 50.00,
        "currency": "usd"
    }
}
```

**Error Responses:**
- `403` - Not admin
- `404` - Hold not found
- `400` - Already transferred or transfer exists
- `500` - Stripe Connect account missing or error

---

## Step 4: Webhook Receive (Automatic)

**Stripe automatically webhook send karega:** `transfer.created`

**Backend automatically:**
1. Transfer status update: `pending` → `completed`
2. Payment hold status update: `ready_for_transfer` → `transferred`
3. `transferred_at` timestamp set
4. Email notification send (if enabled)

**Database Check:**
```sql
-- Transfer status
SELECT * FROM transfers WHERE hold_id = 1;
-- Status: completed ✅

-- Payment hold status
SELECT * FROM payment_holds WHERE id = 1;
-- Status: transferred ✅
-- transferred_at: Current timestamp ✅
```

---

## Step 5: User Ko Payout Mil Gaya ✅

**User ka Stripe Connect account mein paise transfer ho chuke hain!**

**User Stripe Dashboard se check kar sakta hai:**
1. Stripe Connect Dashboard: https://connect.stripe.com/
2. Balance check karo
3. Payout history dekho

---

## Complete Flow Example:

### Scenario: User Payment Hold Complete, Admin Transfer Karta Hai

**Step 1: Check Hold Status**
```sql
SELECT * FROM payment_holds WHERE payment_id = 10;
-- Status: holding
-- hold_end_at: 2026-02-14 (example)
```

**Step 2: Wait Until Hold Period Complete**
```sql
-- After hold_end_at passes
UPDATE payment_holds 
SET status = 'ready_for_transfer', ready_at = NOW() 
WHERE id = 1 AND hold_end_at <= NOW();
```

**Step 3: Admin Transfer Call**
```bash
POST /api/admin/transfer/1
Authorization: Bearer {admin_token}
```

**Step 4: Webhook Automatic**
- Stripe webhook: `transfer.created`
- Backend automatically process karta hai

**Step 5: Verify**
```sql
SELECT * FROM transfers WHERE hold_id = 1;
-- Status: completed ✅

SELECT * FROM payment_holds WHERE id = 1;
-- Status: transferred ✅
```

---

## Webhook Invalid Signature Error Fix

### Problem:

```
POST /api/stripe/webhook
Response: { "error": "Invalid signature" }
```

### Reasons:

1. **Wrong Webhook Secret** - `.env` mein galat secret
2. **ngrok URL Changed** - New ngrok URL par old secret use ho raha
3. **Webhook Secret Missing** - `.env` mein `STRIPE_WEBHOOK_SECRET` missing hai
4. **Config Not Cleared** - `.env` update kiya par config clear nahi kiya

---

## Solution 1: Correct Webhook Secret

### Step 1: Stripe Dashboard Se Latest Secret Copy Karo

1. **Stripe Dashboard:** https://dashboard.stripe.com/test/webhooks
2. **Webhook endpoint** click karo (jo ngrok URL pe hai)
3. **"Signing secret"** copy karo
   - Format: `whsec_xxxxxxxxxxxxx`
   - Example: `whsec_abc123xyz789`

### Step 2: .env File Mein Update Karo

**File:** `.env`

```env
STRIPE_WEBHOOK_SECRET=whsec_abc123xyz789
```

**Important:** 
- `whsec_` prefix zaroori hai
- No spaces around `=`
- Quotes mat lagao

### Step 3: Config Clear Karo

```bash
php artisan config:clear
php artisan cache:clear
```

### Step 4: Application Restart (If Needed)

- XAMPP restart karo (agar needed ho)

---

## Solution 2: ngrok URL Change Hone Par

### Problem:
ngrok restart karne se URL change ho jata hai, par Stripe webhook endpoint mein old URL configure hai.

### Solution:

**Step 1: New ngrok Tunnel Start Karo**
```bash
ngrok http localhost:80 --host-header="localhost/time_walt_app/public"
```

**Step 2: New ngrok URL Copy Karo**
```
https://newabc123.ngrok-free.app
```

**Step 3: Stripe Dashboard Mein:**

1. **Old webhook endpoint delete karo** (optional)
2. **New endpoint add karo:**
   - Endpoint URL: `https://newabc123.ngrok-free.app/api/stripe/webhook`
   - Events select karo:
     - `payment_intent.succeeded`
     - `payment_intent.payment_failed`
     - `transfer.created`
     - `transfer.failed`
     - `account.updated`
3. **"Add endpoint"** click karo
4. **NEW signing secret** copy karo

**Step 4: .env File Mein Update Karo**
```env
STRIPE_WEBHOOK_SECRET=whsec_new_secret_here
```

**Step 5: Config Clear Karo**
```bash
php artisan config:clear
```

---

## Solution 3: Verify Current Configuration

### Check Karo Ki Sab Kuch Sahi Hai:

**Step 1: .env File Check**
```bash
# .env file mein yeh hona chahiye:
STRIPE_KEY=pk_test_xxxxx
STRIPE_SECRET=sk_test_xxxxx
STRIPE_WEBHOOK_SECRET=whsec_xxxxx  # ✅ Yeh zaroori hai
```

**Step 2: Config Verify (Tinker Se)**
```bash
php artisan tinker
```
```php
config('services.stripe.webhook_secret');
// Output: 'whsec_xxxxx' (should match Stripe Dashboard)
```

**Step 3: Stripe Dashboard Verify**
1. Webhook endpoint URL verify karo (ngrok URL match karta hai?)
2. Events verify karo (sab events selected hain?)
3. Signing secret verify karo (`.env` se match karta hai?)

**Step 4: Test Webhook**
1. Stripe Dashboard → Webhooks
2. Webhook endpoint click karo
3. **"Send test webhook"** click karo
4. Event select karo: `payment_intent.succeeded`
5. Send karo
6. Response check karo (should be 200 OK)

**Step 5: Laravel Logs Check**
```bash
tail -f storage/logs/laravel.log
```
Signature error messages check karo.

**Step 6: ngrok Request Check**
- ngrok Dashboard: https://dashboard.ngrok.com/
- Request details check karo
- Headers verify karo (`Stripe-Signature` present hai?)

---

## Solution 4: Manual Webhook Testing (Bypass Signature)

**⚠️ WARNING:** Sirf local testing ke liye! Production mein mat use karo.

**Temporary Fix (Testing Only):**

Code mein temporary change (sirf testing ke liye):

```php:app/Http/Controllers/Api/StripeController.php
public function handleWebhook(Request $request): JsonResponse
{
    $payload = $request->getContent();
    $signature = $request->header('Stripe-Signature');
    $webhookSecret = config('services.stripe.webhook_secret');

    try {
        // TEMPORARY: Skip signature verification for local testing
        if (app()->environment('local') && empty($webhookSecret)) {
            // Manual event create for testing
            $eventData = json_decode($payload, true);
            $event = (object) $eventData;
        } else {
            // Normal signature verification
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $signature,
                $webhookSecret
            );
        }
        
        // ... rest of the code
    }
}
```

**⚠️ Production mein kabhi bhi signature skip mat karo!**

---

## Step By Step Testing

### Complete Testing Flow:

```
┌─────────────────────────────────────────────────┐
│ Step 1: Payment Hold Check                     │
├─────────────────────────────────────────────────┤
│ Database: payment_holds table                   │
│ Status: holding                                 │
│ hold_end_at: Future date                        │
└──────────────┬──────────────────────────────────┘
               │
               │ Wait or manually update status
               ↓
┌─────────────────────────────────────────────────┐
│ Step 2: Hold Status Update                      │
├─────────────────────────────────────────────────┤
│ Status: holding → ready_for_transfer           │
│ ready_at: Current timestamp                     │
└──────────────┬──────────────────────────────────┘
               │
               │ Admin transfer API call
               ↓
┌─────────────────────────────────────────────────┐
│ Step 3: Admin Transfer API                     │
├─────────────────────────────────────────────────┤
│ POST /api/admin/transfer/{hold_id}             │
│ Authorization: Bearer {admin_token}            │
│ Response: Transfer created (status: pending)    │
└──────────────┬──────────────────────────────────┘
               │
               │ Stripe webhook automatic
               ↓
┌─────────────────────────────────────────────────┐
│ Step 4: Webhook Receive                        │
├─────────────────────────────────────────────────┤
│ Event: transfer.created                         │
│ Backend: Status update (pending → completed)    │
│ Hold: Status update (ready_for_transfer → transferred) │
└──────────────┬──────────────────────────────────┘
               │
               │ ✅ User payout complete
               ↓
┌─────────────────────────────────────────────────┐
│ Step 5: Verify                                 │
├─────────────────────────────────────────────────┤
│ transfers table: Status = completed            │
│ payment_holds table: Status = transferred      │
│ transferred_at: Current timestamp              │
└─────────────────────────────────────────────────┘
```

---

## Testing Checklist:

### Payment Hold:
- [ ] Payment hold created (status: `holding`)
- [ ] `hold_end_at` future date set hai
- [ ] Hold period complete ho gaya (ya manually update kiya)

### Admin Transfer:
- [ ] Admin user create/update kiya (`role = 'admin'`)
- [ ] Admin login successful, token received
- [ ] Hold ID identified
- [ ] Transfer API call successful
- [ ] Transfer record created (status: `pending`)

### Webhook:
- [ ] ngrok tunnel running
- [ ] Stripe webhook endpoint configured
- [ ] Webhook secret correct (`.env` mein)
- [ ] Config cleared (`php artisan config:clear`)
- [ ] Webhook received (check ngrok dashboard)
- [ ] Transfer status updated (pending → completed)
- [ ] Payment hold status updated (ready_for_transfer → transferred)

---

## Common Issues Aur Solutions:

### Issue 1: "Unauthorized. Admin access required."

**Reason:** User ka role `admin` nahi hai.

**Solution:**
```sql
UPDATE users SET role = 'admin' WHERE id = {user_id};
```

### Issue 2: "Payment hold not found."

**Reason:** Hold ID galat hai ya hold exist nahi karta.

**Solution:**
```sql
SELECT id FROM payment_holds WHERE payment_id = 10;
```
Correct hold ID use karo.

### Issue 3: "Transfer already exists for this hold."

**Reason:** Is hold ke liye pehle se transfer create ho chuka hai.

**Solution:**
```sql
SELECT * FROM transfers WHERE hold_id = {hold_id};
```
Check karo transfer already exist karta hai ya nahi.

### Issue 4: "Stripe Connect account not found for user"

**Reason:** User ka Stripe Connect account nahi hai.

**Solution:**
1. User ko Stripe Connect account create karna hoga:
   ```
   POST /api/stripe/connect/create
   ```
2. Onboarding complete karna hoga

### Issue 5: Webhook Invalid Signature

**Reason:** Webhook secret galat hai ya missing hai.

**Solution:**
1. Stripe Dashboard se latest secret copy karo
2. `.env` file mein update karo
3. `php artisan config:clear` run karo

---

## Quick Reference:

### API Endpoints:

| API | Method | Auth | Purpose |
|-----|--------|------|---------|
| Admin Transfer | POST | ✅ Admin | Transfer initiate karein |
| Webhook | POST | ❌ | Stripe events receive (automatic) |

### Database Tables:

| Table | Key Fields | Purpose |
|-------|------------|---------|
| `payment_holds` | `status`, `hold_end_at`, `ready_at` | Hold records |
| `transfers` | `hold_id`, `status`, `stripe_transfer_id` | Transfer records |
| `stripe_webhook_events` | `stripe_event_id`, `event_type`, `status` | Webhook logs |

### Status Flow:

**Payment Hold:**
- `holding` → `ready_for_transfer` → `transferred`

**Transfer:**
- `pending` → `completed` (via webhook)

---

## SQL Queries (Useful):

### Check Holds Ready For Transfer:
```sql
SELECT * FROM payment_holds 
WHERE status = 'holding' 
AND hold_end_at <= NOW();
```

### Check Transfer Status:
```sql
SELECT t.*, ph.status as hold_status, ph.hold_end_at
FROM transfers t
JOIN payment_holds ph ON t.hold_id = ph.id
WHERE t.hold_id = {hold_id};
```

### Complete Hold & Transfer Info:
```sql
SELECT 
    ph.id as hold_id,
    ph.status as hold_status,
    ph.amount as hold_amount,
    ph.hold_end_at,
    ph.ready_at,
    ph.transferred_at,
    t.id as transfer_id,
    t.status as transfer_status,
    t.stripe_transfer_id,
    t.created_at as transfer_created
FROM payment_holds ph
LEFT JOIN transfers t ON t.hold_id = ph.id
WHERE ph.payment_id = 10;
```

---

**End of Guide**

Agar koi aur question hai ya specific issue hai, to batayein!
