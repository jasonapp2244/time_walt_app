
# Stripe Payment System - Complete Testing Guide

## 📋 Overview
Yeh guide Stripe payment system ko Postman se test karne ke liye complete steps batata hai, including Stripe Dashboard setup.

---

## 🔧 Part 1: Stripe Dashboard Setup

### Step 1: Create Stripe Account

1. Go to: https://dashboard.stripe.com/register
2. Create account (use test mode)
3. Login to Stripe Dashboard

### Step 2: Get API Keys

1. **Go to Developers → API Keys**
2. **Copy these keys:**
   - **Publishable Key** (pk_test_xxx) → `STRIPE_KEY`
   - **Secret Key** (sk_test_xxx) → `STRIPE_SECRET`

3. **Add to `.env` file:**
   ```env
   STRIPE_KEY=pk_test_xxx
   STRIPE_SECRET=sk_test_xxx
   STRIPE_AUTO_TRANSFER=false
   ADMIN_EMAIL=admin@example.com
   ```

### Step 3: Setup Webhook Endpoint

#### Method 1: Stripe Dashboard (For Production/ngrok URL)

**Detailed Steps:**

1. **Login to Stripe Dashboard:**
   - Go to: https://dashboard.stripe.com/test/webhooks
   - (Ya: https://dashboard.stripe.com → Developers → Webhooks)

2. **Find "Add endpoint" button:**
   - Dashboard ke **right side top** me **"+ Add endpoint"** button hai
   - Ya **"Add endpoint"** button page ke **center** me dikh sakta hai
   - Agar button nahi dikh raha, toh:
     - **Left sidebar** se **"Developers"** click karein
     - Phir **"Webhooks"** click karein
     - **Top right corner** me **"+ Add endpoint"** ya **"Add"** button dikhega

3. **Click "Add endpoint" button**

4. **Enter Endpoint URL:**
   - **For Local Testing with ngrok:**
     ```
     https://your-ngrok-url.ngrok.io/api/stripe/webhook
     ```
   - **For Production:**
     ```
     https://yourdomain.com/api/stripe/webhook
     ```
   - **Note:** `http://localhost` kaam nahi karega - Stripe sirf HTTPS URLs accept karta hai

5. **Select Events:**
   - **Option 1:** "Select events to send" radio button select karein
   - **Option 2:** "Send all events" (not recommended for testing)
   
   **Select these specific events:**
   - ✅ `payment_intent.succeeded`
   - ✅ `payment_intent.payment_failed`
   - ✅ `account.updated`
   - ✅ `transfer.created`
   - ✅ `transfer.failed`
   
   **How to select:**
   - Event list me scroll karein
   - Ya search box me type karein (e.g., "payment_intent")
   - Checkbox click karke select karein

6. **Click "Add endpoint" button** (bottom me)

7. **Copy Webhook Signing Secret:**
   - Endpoint create hone ke baad, endpoint ke page par jayen
   - **"Signing secret"** section me **"Reveal"** ya **"Click to reveal"** button click karein
   - Copy kar lein: `whsec_xxxxx` (yeh important hai!)
   - Ya **"Click to reveal"** ke neeche secret directly dikh sakta hai

8. **Add to `.env` file:**
   ```env
   STRIPE_WEBHOOK_SECRET=whsec_xxxxx
   ```

**⚠️ Important Notes:**
- Localhost URLs (`http://localhost`) Stripe accept nahi karta
- Testing ke liye **ngrok** ya **Stripe CLI** use karein (see Method 2)
- Production me apna domain URL use karein

---

#### Method 2: Stripe CLI (RECOMMENDED for Local Testing)

**Why Use Stripe CLI:**
- ✅ Localhost URLs kaam karte hain
- ✅ No ngrok needed
- ✅ Real-time webhook forwarding
- ✅ Easy debugging

**Install Stripe CLI:**

**Windows:**
```bash
# Method 1: Download installer
# Go to: https://github.com/stripe/stripe-cli/releases/latest
# Download: stripe_X.X.X_windows_x86_64.zip
# Extract and add to PATH

# Method 2: Using Scoop (if installed)
scoop install stripe

# Method 3: Using Chocolatey (if installed)
choco install stripe-cli
```

**Mac:**
```bash
brew install stripe/stripe-cli/stripe
```

**Linux:**
```bash
# Download from releases page
wget https://github.com/stripe/stripe-cli/releases/latest/download/stripe_X.X.X_linux_x86_64.tar.gz
tar -xvf stripe_X.X.X_linux_x86_64.tar.gz
sudo mv stripe /usr/local/bin/
```

**Setup Stripe CLI:**

1. **Login to Stripe:**
   ```bash
   stripe login
   ```
   - Browser khul jayega
   - Stripe account se login karein
   - "Allow access" click karein
   - Terminal me "Done!" dikhega

2. **Forward webhooks to local server:**
   ```bash
   stripe listen --forward-to localhost:8000/api/stripe/webhook
   ```
   
   **Expected Output:**
   ```
   > Ready! Your webhook signing secret is whsec_xxxxx (^C to quit)
   ```

3. **Copy Webhook Signing Secret:**
   - Terminal output me `whsec_xxxxx` dikhega
   - **Yeh secret copy kar lein** (yeh Stripe Dashboard ka secret se alag hai!)
   - **Add to `.env` file:**
     ```env
     STRIPE_WEBHOOK_SECRET=whsec_xxxxx
     ```
   - **Important:** CLI ka secret use karein, Dashboard ka nahi (when using CLI)

4. **Keep this terminal running:**
   - Yeh terminal open rakhni hogi
   - Webhooks automatically forward hote rahenge
   - Stop karne ke liye: `Ctrl + C`

**✅ Benefits:**
- Local testing me no need for ngrok
- Real-time webhook delivery
- Easy to test and debug
- Free to use

---

#### Method 3: Using ngrok (Alternative to CLI)

**If Stripe CLI kaam nahi kare, use ngrok:**

1. **Install ngrok:**
   ```bash
   # Download from: https://ngrok.com/download
   # Or using Chocolatey:
   choco install ngrok
   ```

2. **Start Laravel server:**
   ```bash
   php artisan serve
   ```

3. **Start ngrok:**
   ```bash
   ngrok http 8000
   ```

4. **Copy ngrok URL:**
   - Terminal me `Forwarding` line me URL dikhega
   - Example: `https://abc123.ngrok.io`

5. **Use in Stripe Dashboard:**
   - Endpoint URL: `https://abc123.ngrok.io/api/stripe/webhook`
   - Follow Method 1 steps from Step 3 onwards

**⚠️ Note:** ngrok free plan me URL har restart par change hota hai

---

### Step 4: Summary - Which Method to Use?

**For Local Testing:**
- ✅ **Stripe CLI** (Recommended) - Easiest
- ⚠️ **ngrok** (Alternative) - If CLI not available

**For Production:**
- ✅ **Stripe Dashboard** - Use your domain URL

---

### Step 4: Troubleshooting - "Add endpoint" Button Not Showing

**Problem:** "Add endpoint" button Stripe Dashboard me nahi dikh raha

**Solutions:**

#### Solution 1: Check You're in Test Mode
- **Top right corner** me **"Test mode"** toggle ON hona chahiye
- Agar "Live mode" hai, toggle ko **"Test mode"** par switch karein
- Webhooks test mode me hi create hote hain

#### Solution 2: Check Correct Page
- URL should be: `https://dashboard.stripe.com/test/webhooks`
- Ya: `https://dashboard.stripe.com` → Left sidebar → **"Developers"** → **"Webhooks"**
- Direct URL: https://dashboard.stripe.com/test/webhooks

#### Solution 3: Check Browser
- Browser cache clear karein
- Hard refresh: `Ctrl + F5` (Windows) ya `Cmd + Shift + R` (Mac)
- Different browser try karein

#### Solution 4: Check Account Permissions
- Admin account se login karein
- Some accounts me developer permissions limited hote hain

#### Solution 5: Visual Guide - Where to Find Button

**Method A - From Dashboard Home:**
1. Login to Stripe Dashboard
2. **Left sidebar** se **"Developers"** click karein
3. **"Webhooks"** submenu click karein
4. **Top right corner** me **"+ Add endpoint"** ya **"Add"** button dikhega

**Method B - Direct URL:**
1. Go to: `https://dashboard.stripe.com/test/webhooks`
2. Page load hone ke baad, **right side top** me button dikhega

**Method C - If Still Not Showing:**
1. Create endpoint via Stripe CLI instead (Recommended)
2. Ya use ngrok for testing (see Method 3 above)

---

### Step 5: Alternative - Use Stripe CLI (EASIEST for Testing)

**If Dashboard me button nahi mil raha, Stripe CLI use karein:**

1. **Install Stripe CLI** (see Method 2 above)
2. **Login:**
   ```bash
   stripe login
   ```
3. **Start Webhook Forwarding:**
   ```bash
   stripe listen --forward-to localhost:8000/api/stripe/webhook
   ```
4. **Copy Secret:**
   - Terminal output se `whsec_xxxxx` copy karein
   - `.env` me add karein
5. **Done!** No Dashboard setup needed!

**✅ Benefits:**
- No need to find "Add endpoint" button
- Works with localhost
- Real-time webhook delivery
- Easy debugging

---

### Step 6: Verify Webhook Setup

**Install Stripe CLI:**
```bash
# Windows
# Download from: https://github.com/stripe/stripe-cli/releases

# Or use scoop
scoop install stripe
```

**Login:**
```bash
stripe login
```

**Forward webhooks to local server:**
```bash
stripe listen --forward-to localhost:8000/api/stripe/webhook
```

**Copy webhook signing secret** (whsec_xxx) jo CLI ne diya

---

## 🧪 Part 2: Local Setup

### Step 1: Install Dependencies

```bash
composer require stripe/stripe-php
```

### Step 2: Run Migrations

```bash
php artisan migrate
```

### Step 3: Start Queue Worker

**New terminal window me:**
```bash
php artisan queue:work
```

### Step 4: Start Laravel Server

```bash
php artisan serve
```

**Server will run on:** `http://localhost:8000`

---

## 📮 Part 3: Postman Testing - Step by Step

### Setup Postman Collection

1. **Create new Collection:** "Stripe Payment API"
2. **Add Environment Variables:**
   - `base_url`: `http://localhost:8000`
   - `token`: (will be set after login)
   - `user_id`: (will be set after login)

---

### Test 1: User Login (Get Token)

**Request:**
```
POST {{base_url}}/api/auth/login
```

**Headers:**
```
Content-Type: application/json
```

**Body:**
```json
{
    "email": "user@example.com",
    "password": "Password@123"
}
```

**Expected Response:**
```json
{
    "success": true,
    "data": {
        "user": {...},
        "token": "1|xxxxx"
    }
}
```

**Action:**
- Copy `token` and set in environment variable
- Use in Authorization header for next requests

---

### Test 2: Create Stripe Connect Account

**Request:**
```
POST {{base_url}}/api/stripe/connect/create
```

**Headers:**
```
Authorization: Bearer {{token}}
Content-Type: application/json
```

**Body:** (Empty)

**Expected Response:**
```json
{
    "success": true,
    "message": "Stripe Connect account created successfully.",
    "data": {
        "connect_account_id": "acct_xxx",
        "status": "pending",
        "onboarding_url": null
    }
}
```

**✅ Check:**
- Response me `connect_account_id` milna chahiye
- Database me `stripe_connect_accounts` table me record create hona chahiye

**⚠️ Possible Issues:**
- **Error: "Stripe API key not set"**
  - Solution: Check `.env` me `STRIPE_SECRET` set hai
- **Error: "Invalid API key"**
  - Solution: Stripe Dashboard se correct test key copy karein

---

### Test 3: Get Onboarding Link

**Request:**
```
POST {{base_url}}/api/stripe/connect/onboarding-link
```

**Headers:**
```
Authorization: Bearer {{token}}
Content-Type: application/json
```

**Body:** (Empty)

**Expected Response:**
```json
{
    "success": true,
    "message": "Onboarding link generated successfully.",
    "data": {
        "onboarding_url": "https://connect.stripe.com/setup/xxx"
    }
}
```

**✅ Check:**
- `onboarding_url` milna chahiye
- URL open karke Stripe onboarding form dikhna chahiye

**⚠️ Possible Issues:**
- **Error: "Stripe Connect account not found"**
  - Solution: Pehle Test 2 run karein (Create Connect Account)

---

### Test 4: Create Payment Intent (With Hold Period)

**Request:**
```
POST {{base_url}}/api/stripe/payment-intent
```

**Headers:**
```
Authorization: Bearer {{token}}
Content-Type: application/json
```

**Body (Option 1 - Predefined Period):**
```json
{
    "amount": 10000,
    "currency": "usd",
    "hold_period_type": "2_months"
}
```

**Body (Option 2 - Custom Period):**
```json
{
    "amount": 10000,
    "currency": "usd",
    "hold_period_type": "custom",
    "hold_start_at": "2026-01-08T10:00:00Z",
    "hold_end_at": "2026-03-08T10:00:00Z",
    "hold_days": 60
}
```

**Expected Response:**
```json
{
    "success": true,
    "message": "Payment intent created successfully.",
    "data": {
        "payment_intent_id": "pi_xxx",
        "client_secret": "pi_xxx_secret_xxx",
        "hold_period": {
            "type": "2_months",
            "start_at": "2026-01-08T10:00:00Z",
            "end_at": "2026-03-08T10:00:00Z",
            "days": 60
        }
    }
}
```

**✅ Check:**
- `payment_intent_id` aur `client_secret` milna chahiye
- `hold_period` data milna chahiye
- Database me `payments` table me record create hona chahiye (status: pending)

**⚠️ Possible Issues:**
- **Error: "Amount must be at least 100"**
  - Solution: Amount minimum 100 cents ($1.00) hona chahiye
- **Error: "Hold period must be at least 30 days"**
  - Solution: Custom dates me minimum 30 days difference hona chahiye
- **Error: "Invalid hold period type"**
  - Solution: Use: `1_month`, `2_months`, `6_months`, `1_year`, or `custom`

---

### Test 5: Confirm Payment (Using Stripe Test Card)

**Note:** Actual payment confirm karna Flutter app me hoga, but testing ke liye Stripe Dashboard se manually confirm kar sakte hain.

**Method 1: Stripe Dashboard se**
1. Go to **Payments** in Stripe Dashboard
2. Find your `payment_intent_id` (pi_xxx)
3. Click on it
4. Click **"Confirm payment"** button
5. Use test card: `4242 4242 4242 4242`
6. Any future expiry date
7. Any CVC

**Method 2: Stripe CLI se (Recommended)**
```bash
stripe payment_intents confirm pi_xxx --payment-method pm_card_visa
```

**✅ Check After Payment Confirmation:**
- Webhook trigger hoga
- Database me:
  - `payments.status` = `succeeded`
  - `payments.paid_at` = timestamp
  - `payment_holds` record create hoga (status: `holding`)
- Email notification queue me add hoga

**⚠️ Possible Issues:**
- **Webhook not received**
  - Solution: Check webhook endpoint URL correct hai
  - Check Stripe CLI running hai (if using)
  - Check Laravel logs: `storage/logs/laravel.log`
- **Payment hold not created**
  - Solution: Check `WebhookService::handlePaymentIntentSucceeded()` method
  - Check cache me hold period data stored hai

---

### Test 6: Check Webhook Events

**Request:**
```
GET {{base_url}}/api/stripe/webhook
```
(Actually, yeh POST endpoint hai, Stripe automatically call karega)

**Check in Database:**
```sql
SELECT * FROM stripe_webhook_events ORDER BY created_at DESC;
```

**✅ Check:**
- Webhook events table me records create hone chahiye
- Status = `processed` hona chahiye
- Error messages nahi hone chahiye

**⚠️ Possible Issues:**
- **Webhook signature verification failed**
  - Solution: Check `.env` me `STRIPE_WEBHOOK_SECRET` correct hai
  - Check webhook signing secret Stripe Dashboard se match kare
- **Duplicate webhook processed**
  - Solution: Check idempotency logic working (should skip duplicates)

---

### Test 7: Check Payment Hold Created

**Check in Database:**
```sql
SELECT * FROM payment_holds ORDER BY created_at DESC;
```

**✅ Check:**
- Record create hona chahiye
- `hold_start_at` aur `hold_end_at` set hone chahiye
- `hold_days` calculate hona chahiye
- `status` = `holding` hona chahiye

---

### Test 8: Test Cron Job (Manual)

**Run Command:**
```bash
php artisan check:payment-holds
```

**Expected Output:**
```
Checking payment holds...
Processed 1 holds.
```

**✅ Check:**
- Holds jinka `hold_end_at <= now()` hai, unka status `ready_for_transfer` ho jana chahiye
- Email notification queue me add honi chahiye

**⚠️ Possible Issues:**
- **No holds processed**
  - Solution: Check `hold_end_at` date past me hai ya nahi
  - Test ke liye manually database me date update karein:
    ```sql
    UPDATE payment_holds SET hold_end_at = NOW() - INTERVAL 1 DAY WHERE id = 1;
    ```

---

### Test 9: Admin Transfer (Manual)

**Request:**
```
POST {{base_url}}/api/admin/transfer/1
```
(Replace `1` with actual `hold_id`)

**Headers:**
```
Authorization: Bearer {{admin_token}}
Content-Type: application/json
```

**Note:** Admin user ka token chahiye (role = 'admin')

**Expected Response:**
```json
{
    "success": true,
    "message": "Transfer initiated successfully.",
    "data": {
        "transfer_id": 1,
        "stripe_transfer_id": "tr_xxx",
        "status": "pending",
        "amount": 100.00,
        "currency": "usd"
    }
}
```

**✅ Check:**
- `transfers` table me record create hona chahiye
- Stripe Dashboard me transfer dikhna chahiye
- Webhook `transfer.created` trigger hoga

**⚠️ Possible Issues:**
- **Error: "Unauthorized. Admin access required"**
  - Solution: Admin user ka token use karein
  - Check user ka `role` = `admin` hai
- **Error: "Stripe Connect account not found"**
  - Solution: User ka Stripe Connect account create hona chahiye
- **Error: "Transfer already exists"**
  - Solution: Check `transfers` table me already record hai

---

### Test 10: Check Transfer Webhook

**After Transfer Created:**
- Stripe automatically `transfer.created` webhook send karega
- Check database:
  ```sql
  SELECT * FROM transfers ORDER BY created_at DESC;
  ```
- `status` = `completed` hona chahiye
- `payment_holds.status` = `transferred` hona chahiye

---

## 🔍 Part 4: Common Issues & Solutions

### Issue 1: Stripe API Key Errors

**Error:** `No API key provided`
**Solution:**
- Check `.env` me `STRIPE_KEY` aur `STRIPE_SECRET` set hain
- Run: `php artisan config:clear`

---

### Issue 2: Webhook Not Received

**Error:** Webhook events nahi aa rahe
**Solutions:**
1. **Check Webhook URL:**
   - Stripe Dashboard me correct URL set hai
   - Local testing ke liye ngrok use karein:
     ```bash
     ngrok http 8000
     ```
   - Copy ngrok URL and add in Stripe Dashboard

2. **Use Stripe CLI:**
   ```bash
   stripe listen --forward-to localhost:8000/api/stripe/webhook
   ```

3. **Check Laravel Logs:**
   ```bash
   tail -f storage/logs/laravel.log
   ```

---

### Issue 3: Queue Jobs Not Processing

**Error:** Emails nahi ja rahe
**Solution:**
- Queue worker running hona chahiye:
  ```bash
  php artisan queue:work
  ```
- Check failed jobs:
  ```bash
  php artisan queue:failed
  ```

---

### Issue 4: Payment Hold Not Created

**Error:** Webhook received but hold not created
**Solutions:**
1. Check `WebhookService::handlePaymentIntentSucceeded()` method
2. Check cache me hold period data stored hai:
   ```php
   Cache::get("payment_intent_hold_{$paymentIntentId}")
   ```
3. Check Laravel logs for errors

---

### Issue 5: Transfer Failed

**Error:** Transfer creation fails
**Solutions:**
1. Check user ka Stripe Connect account verified hai
2. Check account me `payouts_enabled` = true hai
3. Check Stripe Dashboard me account status
4. Check error message in `transfers.failure_reason`

---

## 📊 Part 5: Database Verification Queries

### Check All Payments
```sql
SELECT * FROM payments ORDER BY created_at DESC;
```

### Check All Holds
```sql
SELECT * FROM payment_holds ORDER BY created_at DESC;
```

### Check All Transfers
```sql
SELECT * FROM transfers ORDER BY created_at DESC;
```

### Check Webhook Events
```sql
SELECT stripe_event_id, event_type, status, created_at 
FROM stripe_webhook_events 
ORDER BY created_at DESC;
```

### Check Failed Webhooks
```sql
SELECT * FROM stripe_webhook_events 
WHERE status = 'failed' 
ORDER BY created_at DESC;
```

---

## 🎯 Part 6: Complete Test Flow

### Full Flow Test:

1. ✅ **Login** → Get token
2. ✅ **Create Connect Account** → Get account ID
3. ✅ **Get Onboarding Link** → (Optional - for bank details)
4. ✅ **Create Payment Intent** → Get client_secret
5. ✅ **Confirm Payment** (Stripe Dashboard/CLI) → Payment succeeds
6. ✅ **Check Webhook** → Payment hold created
7. ✅ **Check Email** → Queue me notification add
8. ✅ **Run Cron Job** → Hold marked ready (if date passed)
9. ✅ **Admin Transfer** → Transfer created
10. ✅ **Check Transfer Webhook** → Transfer completed

---

## 📝 Part 7: Stripe Test Cards

### Success Cards:
- `4242 4242 4242 4242` - Visa (Success)
- `5555 5555 5555 4444` - Mastercard (Success)

### Failure Cards:
- `4000 0000 0000 0002` - Card declined
- `4000 0000 0000 9995` - Insufficient funds

### 3D Secure:
- `4000 0027 6000 3184` - Requires authentication

**All test cards:**
- Expiry: Any future date (e.g., 12/25)
- CVC: Any 3 digits (e.g., 123)
- ZIP: Any 5 digits (e.g., 12345)

---

## ✅ Testing Checklist

- [ ] Stripe Dashboard setup complete
- [ ] API keys configured in `.env`
- [ ] Webhook endpoint configured
- [ ] Migrations run successfully
- [ ] Queue worker running
- [ ] Laravel server running
- [ ] User login successful
- [ ] Connect account created
- [ ] Payment intent created
- [ ] Payment confirmed
- [ ] Webhook received
- [ ] Payment hold created
- [ ] Email notification queued
- [ ] Cron job working
- [ ] Transfer created
- [ ] Transfer webhook received

---

## 🚀 Quick Start Commands

```bash
# 1. Install Stripe package
composer require stripe/stripe-php

# 2. Run migrations
php artisan migrate

# 3. Clear config cache
php artisan config:clear

# 4. Start queue worker (new terminal)
php artisan queue:work

# 5. Start Laravel server
php artisan serve

# 6. Start Stripe CLI (new terminal - optional)
stripe listen --forward-to localhost:8000/api/stripe/webhook
```

---

**Note:** Yeh guide local testing ke liye hai. Production me proper webhook URL, SSL certificate, aur security measures add karne honge.
