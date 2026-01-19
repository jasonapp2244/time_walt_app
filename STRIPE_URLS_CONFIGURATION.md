# Stripe URLs Configuration Guide - Roman Urdu

## Important: Do Different URLs Hain!

Stripe Dashboard mein **2 different sections** hain different purposes ke liye:

1. **Webhook URL** - Events receive karne ke liye
2. **Redirect URLs** - OAuth onboarding complete hone ke baad user redirect karne ke liye

---

## 1. Webhook URL (Events Receive Karne Ke Liye)

### Kahan Configure Karein:

**Stripe Dashboard → Developers → Webhooks**

**NOT** Settings → Connect → Redirects ❌

### Steps:

1. **Stripe Dashboard:** https://dashboard.stripe.com/test/webhooks
2. **"Add endpoint"** click karo
3. **Endpoint URL:**
   ```
   https://ladderless-demiurgic-corbin.ngrok-free.dev/api/stripe/webhook
   ```
   ✅ **Yeh sahi URL hai webhook ke liye!**

4. **Events select karo:**
   - `payment_intent.succeeded`
   - `payment_intent.payment_failed`
   - `transfer.created`
   - `transfer.failed`
   - `account.updated`

5. **"Add endpoint"** click karo

6. **Signing secret** copy karo:
   ```
   whsec_xxxxxxxxxxxxx
   ```

7. **`.env` file mein add karo:**
   ```env
   STRIPE_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxx
   ```

---

## 2. Redirect URLs (OAuth Onboarding Ke Liye)

### Kahan Configure Karein:

**Stripe Dashboard → Settings → Connect → Redirects**

**NOT** Webhooks section ❌

### Kya Kaam Karta Hai:

Jab user Stripe Connect onboarding complete karta hai, to Stripe user ko redirect karta hai. Ye URLs batati hain ki kahan redirect karna hai.

### Code Mein Currently:

```php:app/Services/StripeService.php
$accountLink = \Stripe\AccountLink::create([
    'account' => $connectAccount->connect_account_id,
    'refresh_url' => config('app.url').'/stripe/reauth',
    'return_url' => config('app.url').'/stripe/return',
    'type' => 'account_onboarding',
]);
```

### Kya URLs Add Karne Hain:

**Stripe Dashboard → Settings → Connect → Redirects**

**Add these URLs:**

1. **Return URL** (Onboarding success):
   ```
   https://ladderless-demiurgic-corbin.ngrok-free.dev/stripe/return
   ```

2. **Refresh URL** (Onboarding retry):
   ```
   https://ladderless-demiurgic-corbin.ngrok-free.dev/stripe/reauth
   ```

**Note:** 
- Webhook URL: `/api/stripe/webhook` ❌ (yahan mat dalo)
- Redirect URLs: `/stripe/return` aur `/stripe/reauth` ✅

---

## Complete Configuration Summary

### Webhook Configuration:

```
Stripe Dashboard → Developers → Webhooks

Endpoint URL:
https://ladderless-demiurgic-corbin.ngrok-free.dev/api/stripe/webhook

Events:
✓ payment_intent.succeeded
✓ payment_intent.payment_failed
✓ transfer.created
✓ transfer.failed
✓ account.updated
```

### Redirect URLs Configuration:

```
Stripe Dashboard → Settings → Connect → Redirects

URLs to add:
1. https://ladderless-demiurgic-corbin.ngrok-free.dev/stripe/return
2. https://ladderless-demiurgic-corbin.ngrok-free.dev/stripe/reauth
```

---

## Routes Create Karne Hain (Agar Nahi Hain)

Agar `/stripe/return` aur `/stripe/reauth` routes nahi hain, to banao:

### Option 1: Web Routes (Frontend Pages)

**File:** `routes/web.php`

```php
Route::get('/stripe/return', function () {
    // User successfully completed onboarding
    return view('stripe.success'); // Ya frontend URL redirect karo
})->name('stripe.return');

Route::get('/stripe/reauth', function () {
    // User needs to retry onboarding
    return view('stripe.retry'); // Ya frontend URL redirect karo
})->name('stripe.reauth');
```

### Option 2: API Routes (JSON Response)

**File:** `routes/api.php`

```php
// Webhook Route ke baad add karo
Route::get('/stripe/return', function () {
    return response()->json([
        'success' => true,
        'message' => 'Onboarding completed successfully.',
    ]);
})->name('stripe.return');

Route::get('/stripe/reauth', function () {
    return response()->json([
        'success' => false,
        'message' => 'Onboarding needs to be completed.',
    ]);
})->name('stripe.reauth');
```

### Option 3: Frontend Redirect (Best For SPA)

**File:** `routes/web.php` ya `routes/api.php`

```php
Route::get('/stripe/return', function () {
    // Frontend URL pe redirect karo
    $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
    return redirect("{$frontendUrl}/dashboard?onboarding=success");
});

Route::get('/stripe/reauth', function () {
    $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
    return redirect("{$frontendUrl}/dashboard?onboarding=retry");
});
```

---

## Difference Summary

| Type | Location | Purpose | URL Example |
|------|----------|---------|-------------|
| **Webhook** | Developers → Webhooks | Stripe events receive | `/api/stripe/webhook` |
| **Redirect** | Settings → Connect → Redirects | OAuth redirect after onboarding | `/stripe/return`, `/stripe/reauth` |

---

## Current ngrok URL Se Kya Karein

### Webhook URL:
```
https://ladderless-demiurgic-corbin.ngrok-free.app/api/stripe/webhook
```

**Configure Karein:**
- ✅ Stripe Dashboard → Webhooks section mein
- ❌ Redirects section mein mat dalo

### Redirect URLs:
```
https://ladderless-demiurgic-corbin.ngrok-free.app/stripe/return
https://ladderless-demiurgic-corbin.ngrok-free.app/stripe/reauth
```

**Configure Karein:**
- ✅ Stripe Dashboard → Settings → Connect → Redirects mein
- ❌ Webhooks section mein mat dalo

---

## Complete Setup Checklist

### Webhook Setup:
- [ ] ngrok tunnel running hai
- [ ] Webhook endpoint URL: `/api/stripe/webhook`
- [ ] Stripe Dashboard → Webhooks section mein add kiya
- [ ] All events selected
- [ ] Signing secret copy kiya
- [ ] `.env` mein `STRIPE_WEBHOOK_SECRET` set kiya
- [ ] `php artisan config:clear` run kiya

### Redirect URLs Setup:
- [ ] `/stripe/return` route create kiya
- [ ] `/stripe/reauth` route create kiya
- [ ] Stripe Dashboard → Settings → Connect → Redirects mein add kiya
- [ ] URLs test ki hain (manual browser access)

---

## Testing

### Test Webhook:

1. **Stripe Dashboard → Webhooks**
2. Webhook endpoint click karo
3. **"Send test webhook"** click karo
4. Event select karo: `payment_intent.succeeded`
5. Send karo
6. Response check karo (200 OK chahiye)

### Test Redirect URLs:

1. **Browser mein manually open karo:**
   ```
   https://ladderless-demiurgic-corbin.ngrok-free.app/stripe/return
   https://ladderless-demiurgic-corbin.ngrok-free.app/stripe/reauth
   ```

2. **Agar 404 error aata hai** → Routes create karo

3. **Onboarding flow test karo:**
   - Connect account create karo
   - Onboarding link open karo
   - Onboarding complete karo
   - Check karo user redirect ho raha hai ya nahi

---

## Important Notes

1. **Webhook URL aur Redirect URLs different hain**
2. **Webhook URL** Stripe se backend ko events send karne ke liye
3. **Redirect URLs** User ko onboarding ke baad wapas le aane ke liye
4. **Donon ko sahi jagah configure karna zaroori hai**
5. **ngrok URL change hone par donon update karne hain**

---

## Quick Reference

### Webhook URL:
- **Location:** Developers → Webhooks
- **URL:** `{ngrok_url}/api/stripe/webhook`
- **Purpose:** Receive events from Stripe

### Redirect URLs:
- **Location:** Settings → Connect → Redirects
- **URLs:** 
  - `{ngrok_url}/stripe/return`
  - `{ngrok_url}/stripe/reauth`
- **Purpose:** Redirect user after OAuth onboarding

---

**Agar confusion hai to yeh yaad rakho:**
- **Webhook** = Stripe → Backend (automatic events)
- **Redirect** = Stripe → User Browser (after OAuth)

---

**End of Guide**

Agar routes create karne hain to batayein!
