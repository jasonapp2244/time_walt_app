# Webhook Aur Transfer Complete Guide - Roman Urdu

## Table of Contents

1. [Webhook Kya Hai Aur Kaise Kaam Karti Hai?](#webhook-kya-hai)
2. [Invalid Signature Error Fix](#invalid-signature-error-fix)
3. [Frontend/Backend Interaction](#frontend-backend-interaction)
4. [Transfer Payment Flow](#transfer-payment-flow)
5. [Complete Examples](#complete-examples)

---

## Webhook Kya Hai Aur Kaise Kaam Karti Hai?

### Simple Explanation:

**Webhook = Stripe se backend ko automatic notification**

```
User Payment Complete Karta Hai (Stripe)
    ↓
Stripe Ko Pata Chal Jata Hai Payment Succeed Ho Gaya
    ↓
Stripe Automatically Apna Server Se Hamare Backend Ko Call Karta Hai
    ↓
Hamara Backend Webhook Receive Karta Hai
    ↓
Payment Hold Create Karta Hai ✅
```

### Important Points:

1. **Frontend Ko Kuch Nahi Karna Hai:**
   - Frontend payment complete karke done
   - Webhook Stripe khud send karta hai
   - Frontend ko webhook ka wait nahi karna

2. **Backend Khud Handle Karta Hai:**
   - Webhook endpoint: `/api/stripe/webhook`
   - Stripe signature verify karta hai (security)
   - Payment hold create karta hai

3. **Webhook Automatic Hai:**
   - User manually webhook call nahi karta
   - Stripe automatically send karta hai
   - Timing Stripe decide karta hai

---

## Invalid Signature Error Fix

### Problem:

```
POST /api/stripe/webhook
Response: { "error": "Invalid signature" }
```

### Reasons:

1. **Wrong Webhook Secret** - `.env` mein galat secret
2. **ngrok URL Changed** - New ngrok URL par old secret use ho raha
3. **Payload Modified** - Request body modify ho gaya
4. **Raw Payload Issue** - Laravel ne payload modify kar diya

### Solution 1: Correct Webhook Secret

**Step 1:** Stripe Dashboard mein webhook endpoint ka secret copy karo:

1. Go to: https://dashboard.stripe.com/test/webhooks
2. Webhook endpoint click karo (jo ngrok URL pe hai)
3. **"Signing secret"** copy karo (yeh `whsec_xxxxx` format mein hoga)
4. Example: `whsec_abc123xyz789`

**Step 2:** `.env` file mein update karo:

```env
STRIPE_WEBHOOK_SECRET=whsec_abc123xyz789
```

**Step 3:** Application restart karo:
- XAMPP restart karo ya
- `php artisan config:clear` run karo

**Step 4:** Test karo:
- Stripe Dashboard se test webhook send karo
- Ya payment complete karo

---

### Solution 2: ngrok URL Change Hone Par

**Problem:** ngrok restart karne se URL change ho jata hai, par Stripe mein old webhook endpoint configure hai.

**Solution:**

**Step 1:** New ngrok tunnel start karo:
```bash
ngrok http localhost:80 --host-header="localhost/time_walt_app/public"
```

**Step 2:** New ngrok URL copy karo:
```
https://newabc123.ngrok-free.app
```

**Step 3:** Stripe Dashboard mein:
1. Old webhook endpoint delete karo
2. New endpoint add karo:
   ```
   https://newabc123.ngrok-free.app/api/stripe/webhook
   ```
3. Events select karo (same as before)
4. **NEW signing secret** copy karo

**Step 4:** `.env` file mein new secret update karo:
```env
STRIPE_WEBHOOK_SECRET=whsec_new_secret_here
```

**Step 5:** Restart application

---

### Solution 3: Raw Payload Issue (Most Common)

**Problem:** Laravel request body ko parse kar deta hai, par Stripe ko raw payload chahiye.

**Current Code Check:**

```php:app/Http/Controllers/Api/StripeController.php
public function handleWebhook(Request $request): JsonResponse
{
    $payload = $request->getContent(); // ✅ Ye sahi hai - raw content leta hai
    $signature = $request->header('Stripe-Signature');
    ...
}
```

**Verify Karo:**

1. Code mein `$request->getContent()` use ho raha hai (✅ Correct)
2. `$request->all()` ya `$request->json()` use nahi ho raha (✅ Good)

**Agar Issue Ho To:**

Route mein webhook endpoint check karo:
```php:routes/api.php
Route::post('/stripe/webhook', [StripeController::class, 'handleWebhook'])
    ->name('stripe.webhook')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
```

✅ CSRF exempt hai - sahi hai.

---

### Solution 4: Manual Testing (Bypass Signature)

**Note:** Sirf local testing ke liye! Production mein mat use karo.

**Temporary Fix (Testing Only):**

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

**⚠️ WARNING:** Production mein kabhi bhi signature skip mat karo!

---

### Solution 5: Verify Current Configuration

**Check Karo Ki Sab Kuch Sahi Hai:**

**Step 1: .env File Check:**
```bash
# .env file mein yeh hona chahiye:
STRIPE_KEY=pk_test_xxxxx
STRIPE_SECRET=sk_test_xxxxx
STRIPE_WEBHOOK_SECRET=whsec_xxxxx  # ✅ Yeh zaroori hai
```

**Step 2: Config Clear:**
```bash
php artisan config:clear
php artisan cache:clear
```

**Step 3: Verify Config:**
```bash
php artisan tinker
```
```php
config('services.stripe.webhook_secret');
// Output: 'whsec_xxxxx' (should match Stripe Dashboard)
```

**Step 4: Check Webhook Endpoint:**
- Stripe Dashboard → Webhooks
- Webhook endpoint URL verify karo (ngrok URL)
- Events verify karo (payment_intent.succeeded, etc.)

**Step 5: Test Webhook:**
Stripe Dashboard se test webhook send karo:
1. Webhook endpoint click karo
2. "Send test webhook" click karo
3. Event select karo: `payment_intent.succeeded`
4. Send karo
5. Response check karo (should be 200 OK)

**Step 6: Check Laravel Logs:**
```bash
tail -f storage/logs/laravel.log
```
Signature error messages check karo.

**Step 7: ngrok Request Check:**
- ngrok Dashboard: https://dashboard.ngrok.com/
- Request details check karo
- Headers verify karo (`Stripe-Signature` present hai?)

---

### Common Mistakes (Avoid Karein):

1. ❌ **Old Webhook Secret Use Kar Raha Hai:**
   - ngrok URL change hui par Stripe mein old secret use ho raha

2. ❌ **Webhook Secret Missing:**
   - `.env` mein `STRIPE_WEBHOOK_SECRET` missing hai

3. ❌ **Wrong ngrok URL:**
   - Stripe webhook endpoint mein galat ngrok URL configure hai

4. ❌ **Config Not Cleared:**
   - `.env` update kiya par config clear nahi kiya

5. ❌ **Test Webhook Signature Issue:**
   - Stripe test webhook ka signature verify nahi ho raha (development mode mein ho sakta hai)

---

### Complete Fix Checklist:

- [ ] `.env` mein `STRIPE_WEBHOOK_SECRET` sahi hai
- [ ] Stripe Dashboard se latest secret copy kiya
- [ ] ngrok URL Stripe webhook endpoint se match karta hai
- [ ] Application restart kiya (`php artisan config:clear`)
- [ ] Webhook endpoint public accessible hai (ngrok through)
- [ ] Route CSRF exempt hai
- [ ] `$request->getContent()` use ho raha hai (raw payload)

---

## Frontend/Backend Interaction

### Complete Flow:

```
┌─────────────┐
│  Frontend   │
└──────┬──────┘
       │
       │ 1. Payment Intent Create API Call
       │    POST /api/stripe/payment-intent
       │    Headers: Authorization: Bearer {token}
       │    Body: { amount, currency, hold_period_type }
       ↓
┌─────────────┐
│  Backend    │
└──────┬──────┘
       │
       │ 2. Stripe PaymentIntent Create
       │    (Backend Stripe API call karta hai)
       ↓
┌─────────────┐
│   Stripe    │
└──────┬──────┘
       │
       │ 3. Response: payment_intent_id + client_secret
       ↓
┌─────────────┐
│  Backend    │
└──────┬──────┘
       │
       │ 4. Response Frontend Ko:
       │    {
       │      payment_intent_id,
       │      client_secret,
       │      hold_period
       │    }
       ↓
┌─────────────┐
│  Frontend   │
└──────┬──────┘
       │
       │ 5. Stripe.js Use Karke Payment Complete
       │    stripe.confirmCardPayment(client_secret)
       │
       │    ✅ Payment Complete!
       │    Frontend Done - Ab Kuch Nahi Karna
       ↓
┌─────────────┐
│   Stripe    │
└──────┬──────┘
       │
       │ 6. Stripe Automatically Webhook Send Karta Hai
       │    POST https://your-ngrok-url/api/stripe/webhook
       │    Headers: Stripe-Signature
       │    Body: payment_intent.succeeded event
       ↓
┌─────────────┐
│  Backend    │
└──────┬──────┘
       │
       │ 7. Webhook Receive & Process
       │    - Signature Verify
       │    - Payment Status Update
       │    - Payment Hold Create ✅
       │
       │    Response: { "received": true }
       ↓
┌─────────────┐
│   Stripe    │
└─────────────┘
       │
       │ 8. Stripe Ko Pata Chal Jata Hai Webhook Processed
       │    (Webhook log mein success dikhega)
```

### Frontend Ko Kya Karna Hai:

#### Step 1: Payment Intent Create

```javascript
// Frontend Code
const response = await fetch('/api/stripe/payment-intent', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${userToken}`
    },
    body: JSON.stringify({
        amount: 5000, // $50.00 in cents
        currency: 'usd',
        hold_period_type: '1_month'
    })
});

const data = await response.json();
// Response:
// {
//     success: true,
//     data: {
//         payment_intent_id: "pi_xxxxx",
//         client_secret: "pi_xxxxx_secret_xxx",
//         hold_period: { ... }
//     }
// }
```

#### Step 2: Payment Complete (Stripe.js)

```javascript
// Frontend Code
import { loadStripe } from '@stripe/stripe-js';

const stripe = await loadStripe('pk_test_xxxxx'); // Stripe publishable key

// Payment confirm karo
const { error, paymentIntent } = await stripe.confirmCardPayment(
    data.data.client_secret,
    {
        payment_method: {
            card: cardElement,
            billing_details: {
                name: 'User Name'
            }
        }
    }
);

if (error) {
    // Payment failed
    console.error('Payment failed:', error);
} else if (paymentIntent.status === 'succeeded') {
    // Payment successful!
    console.log('Payment succeeded!');
    // ✅ Frontend ka kaam ho gaya
    // Ab wait karo - webhook automatically aayega
}
```

#### Step 3: Payment Hold Status Check (Optional)

Agar frontend ko payment hold ka status chahiye:

```javascript
// Polling method (optional - recommended nahi)
// Ya WebSocket use karo (better)

// Simple approach: Direct database se check mat karo
// Backend API se status check karo:

const checkHoldStatus = async (paymentIntentId) => {
    const response = await fetch(`/api/payments/${paymentIntentId}/hold-status`, {
        headers: {
            'Authorization': `Bearer ${userToken}`
        }
    });
    
    const data = await response.json();
    // Response:
    // {
    //     hold_status: 'holding',
    //     hold_end_at: '2026-02-14',
    //     days_remaining: 25
    // }
};
```

**Note:** Frontend ko webhook wait nahi karna. Agar status chahiye to polling ya WebSocket use karo.

### Backend Ko Kya Karna Hai:

Backend automatically sab kuch handle karta hai:

1. **Payment Intent Create:** ✅ Done
2. **Webhook Receive:** ✅ Automatic (Stripe khud send karta hai)
3. **Payment Hold Create:** ✅ Webhook handler mein ho jata hai

**Frontend ko koi additional call nahi karna!**

---

## Transfer Payment Flow

### Transfer Kya Hai?

**Transfer = Payment hold complete hone ke baad user ke Stripe Connect account mein paise transfer karna**

### Complete Transfer Flow:

```
┌─────────────────────────────────────────────────┐
│ Step 1: Payment Hold Created                    │
│ Status: holding                                 │
│ hold_end_at: Future date                        │
└──────────────┬──────────────────────────────────┘
               │
               │ Wait until hold_end_at passes
               ↓
┌─────────────────────────────────────────────────┐
│ Step 2: Hold Period Complete (Manual/Cron)      │
│ Status Update: holding → ready_for_transfer     │
└──────────────┬──────────────────────────────────┘
               │
               │ Admin manually triggers transfer
               ↓
┌─────────────────────────────────────────────────┐
│ Step 3: Admin Calls Transfer API                │
│ POST /api/admin/transfer/{hold_id}              │
│ Authorization: Bearer {admin_token}             │
└──────────────┬──────────────────────────────────┘
               │
               │ Backend processes transfer
               ↓
┌─────────────────────────────────────────────────┐
│ Step 4: Stripe Transfer Create                  │
│ Backend Stripe API call karta hai               │
│ Transfer to user's Connect account              │
└──────────────┬──────────────────────────────────┘
               │
               │ Stripe transfer creates
               ↓
┌─────────────────────────────────────────────────┐
│ Step 5: Transfer Record Created                 │
│ transfers table mein entry                      │
│ Status: pending                                 │
└──────────────┬──────────────────────────────────┘
               │
               │ Stripe webhook send karta hai
               ↓
┌─────────────────────────────────────────────────┐
│ Step 6: Stripe Webhook: transfer.created        │
│ Backend receive karta hai                       │
└──────────────┬──────────────────────────────────┘
               │
               │ Webhook handler processes
               ↓
┌─────────────────────────────────────────────────┐
│ Step 7: Status Update                           │
│ Transfer status: pending → completed            │
│ Payment hold status: ready_for_transfer → transferred │
│ transferred_at: Current timestamp               │
└─────────────────────────────────────────────────┘
```

### Transfer API Details:

#### Endpoint: `POST /api/admin/transfer/{hold_id}`

**Purpose:** Payment hold ke liye transfer initiate karta hai

**Authentication:** ✅ Required (Admin token)

**Who Can Call:**
- Admin users only
- Check: `user.role === 'admin'` (current code mein `'user'` check hai - fix karna hoga)

**What It Does:**

1. **Validation:**
   - Payment hold exist karta hai ya nahi
   - Hold status check (`transferred` nahi hona chahiye)
   - Transfer already exist nahi karna chahiye

2. **Stripe Transfer Create:**
   - User ka Stripe Connect account check karta hai
   - Stripe API se Transfer create karta hai
   - Amount user ke Connect account mein transfer hota hai

3. **Database Record:**
   - `transfers` table mein entry create karta hai
   - Status: `pending`
   - `hold_id` link karta hai

4. **Webhook Wait:**
   - Stripe `transfer.created` webhook send karega
   - Webhook handler status update karega

**Request:**

```http
POST /api/admin/transfer/1
Authorization: Bearer {admin_token}
Content-Type: application/json
```

**Body:** (Empty ya transfer type)

```json
{
    "type": "manual"
}
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
- `400` - Transfer already exists or already transferred
- `500` - Stripe API error or Connect account missing

### Transfer Webhook Flow:

#### Webhook Event: `transfer.created`

**When:** Stripe transfer successfully create ho jata hai

**What Backend Does:**

1. Transfer record find karta hai (`stripe_transfer_id` se)
2. Transfer status update: `pending` → `completed`
3. Payment hold status update: `ready_for_transfer` → `transferred`
4. `transferred_at` timestamp set karta hai
5. Email notification send karta hai (if enabled)

**Webhook Handler Code:**

```php:app/Services/WebhookService.php
public function handleTransferCreated(array $eventData): void
{
    $transfer = $eventData['data']['object'];
    $transferId = $transfer['id'];

    $transferRecord = Transfer::where('stripe_transfer_id', $transferId)->first();

    if ($transferRecord) {
        $transferRecord->update([
            'status' => 'completed',
            'transferred_at' => now(),
            'stripe_data' => $transfer,
        ]);

        // Update payment hold status
        $transferRecord->hold->update([
            'status' => 'transferred',
            'transferred_at' => now(),
        ]);

        // Send notification
        SendTransferCompletedNotification::dispatch($transferRecord);
    }
}
```

### Transfer Complete Flow Example:

**Step 1: Hold Ready For Transfer**

```sql
-- Check holds ready for transfer
SELECT * FROM payment_holds 
WHERE status = 'ready_for_transfer';
```

**Step 2: Admin Calls Transfer API**

```bash
curl -X POST http://localhost/api/admin/transfer/1 \
  -H "Authorization: Bearer {admin_token}" \
  -H "Content-Type: application/json"
```

**Step 3: Database Check (Immediate)**

```sql
-- Transfer record created
SELECT * FROM transfers WHERE hold_id = 1;
-- Status: pending

-- Payment hold status (abhi update nahi hua)
SELECT * FROM payment_holds WHERE id = 1;
-- Status: ready_for_transfer
```

**Step 4: Wait For Webhook**

Stripe webhook send karega: `transfer.created`

**Step 5: Database Check (After Webhook)**

```sql
-- Transfer status updated
SELECT * FROM transfers WHERE hold_id = 1;
-- Status: completed
-- transferred_at: Current timestamp

-- Payment hold status updated
SELECT * FROM payment_holds WHERE id = 1;
-- Status: transferred
-- transferred_at: Current timestamp
```

---

## Complete Examples

### Example 1: Complete Payment Hold Flow

**Frontend Code:**

```javascript
// 1. Payment Intent Create
const createPaymentIntent = async () => {
    const response = await fetch('/api/stripe/payment-intent', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${token}`
        },
        body: JSON.stringify({
            amount: 5000,
            currency: 'usd',
            hold_period_type: '1_month'
        })
    });
    
    const data = await response.json();
    return data.data.client_secret;
};

// 2. Payment Complete
const confirmPayment = async (clientSecret) => {
    const stripe = await loadStripe('pk_test_xxxxx');
    
    const result = await stripe.confirmCardPayment(clientSecret, {
        payment_method: {
            card: cardElement
        }
    });
    
    if (result.error) {
        console.error('Payment failed');
    } else {
        console.log('Payment succeeded!');
        // Frontend done - webhook automatically aayega
    }
};

// Usage
const clientSecret = await createPaymentIntent();
await confirmPayment(clientSecret);
```

**Backend (Automatic):**

1. Webhook receive → Payment hold create ✅
2. No frontend action needed

---

### Example 2: Complete Transfer Flow

**Admin Panel / Backend Code:**

```javascript
// Admin transfers a hold
const transferHold = async (holdId) => {
    const response = await fetch(`/api/admin/transfer/${holdId}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${adminToken}`
        },
        body: JSON.stringify({
            type: 'manual'
        })
    });
    
    const data = await response.json();
    return data.data;
};

// Usage
const transfer = await transferHold(1);
console.log('Transfer initiated:', transfer.stripe_transfer_id);
// Webhook automatically status update karega
```

**Backend (Automatic):**

1. Webhook receive → Transfer status update ✅
2. Payment hold status update ✅

---

### Example 3: Checking Hold Status (Frontend)

```javascript
// Get user's payment holds
const getHolds = async () => {
    const response = await fetch('/api/payment-holds', {
        headers: {
            'Authorization': `Bearer ${token}`
        }
    });
    
    const data = await response.json();
    return data.data;
};

// Usage
const holds = await getHolds();
holds.forEach(hold => {
    console.log(`Hold ${hold.id}: ${hold.status}`);
    console.log(`Amount: $${hold.amount}`);
    console.log(`Ends: ${hold.hold_end_at}`);
});
```

**Note:** Yeh API abhi implement nahi hai. Agar chahiye to banao:

```php
// routes/api.php
Route::get('/payment-holds', [PaymentHoldController::class, 'index'])
    ->middleware('auth:sanctum');
```

---

## Quick Reference

### Webhook Endpoints:

| Event | When | What Happens |
|-------|------|--------------|
| `payment_intent.succeeded` | Payment complete | Payment hold create ✅ |
| `payment_intent.payment_failed` | Payment failed | Payment status: failed |
| `transfer.created` | Transfer create | Transfer status: completed, Hold: transferred ✅ |
| `transfer.failed` | Transfer failed | Transfer status: failed |
| `account.updated` | Connect account update | Account status update |

### API Endpoints Summary:

| API | Method | Auth | Purpose |
|-----|--------|------|---------|
| Payment Intent | POST | ✅ | Create payment + hold period |
| Webhook | POST | ❌ | Receive Stripe events (automatic) |
| Transfer | POST | ✅ Admin | Initiate transfer for hold |

### Frontend Checklist:

- [ ] Payment intent create karo
- [ ] `client_secret` receive karo
- [ ] Stripe.js se payment confirm karo
- [ ] Payment success message dikhao
- [ ] **Webhook ka wait mat karo** - automatic hai
- [ ] Agar status chahiye to polling/WebSocket use karo

### Backend Checklist:

- [ ] Webhook endpoint public accessible hai (ngrok)
- [ ] Webhook secret correct hai
- [ ] Signature verification working hai
- [ ] Payment hold create ho raha hai
- [ ] Transfer create ho raha hai
- [ ] Webhooks process ho rahe hain

---

**End of Guide**

Agar koi aur question hai to batayein!
