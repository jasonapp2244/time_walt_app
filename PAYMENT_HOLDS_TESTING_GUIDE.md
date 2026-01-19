# Payment Holds Complete Testing Guide - Roman Urdu

## Table of Contents

1. [Local XAMPP Setup](#local-xampp-setup)
2. [ngrok Setup For Webhooks](#ngrok-setup-for-webhooks)
3. [Stripe Configuration](#stripe-configuration)
4. [Complete API Details](#complete-api-details)
5. [Step By Step Testing](#step-by-step-testing)
6. [All Scenarios Explained](#all-scenarios-explained)
7. [Database Verification](#database-verification)

---

## Payment Holds Kaise Aur Kab Create Hote Hain?

### Complete Flow Explanation:

**Payment holds table mein data tab create hota hai jab:**

```
User Login
    ↓
Payment Intent Create (API Call)
    ↓
Stripe PaymentIntent Create Hota Hai (Stripe API)
    ↓
Payment Record Create Hota Hai (Database) - Status: pending
    ↓
User Payment Complete Karta Hai (Frontend/Stripe)
    ↓
Stripe Webhook Send Karta Hai → payment_intent.succeeded
    ↓
Backend Webhook Receive Karta Hai
    ↓
Payment Status Update → succeeded
    ↓
PaymentHold Record Create Hota Hai (Database) ✅
```

**Critical Points:**
- Payment Intent API se direct hold create **NAHI** hota
- Hold tabhi create hota hai jab Stripe webhook `payment_intent.succeeded` send karta hai
- Webhook signature verify hona zaroori hai
- Local testing ke liye ngrok tunnel chahiye Stripe webhooks ke liye

---

## Local XAMPP Setup

### Step 1: XAMPP Install Karein

1. XAMPP download karo: https://www.apachefriends.org/
2. Install karo aur start karo **Apache** aur **MySQL**
3. XAMPP Control Panel mein:
   - Apache → **Start**
   - MySQL → **Start**

### Step 2: Laravel Project Setup

1. Project folder ko XAMPP `htdocs` folder mein copy karo:
   ```
   C:\xampp\htdocs\time_walt_app
   ```

2. `.env` file configure karo:
   ```env
   APP_NAME="Time Walt App"
   APP_ENV=local
   APP_DEBUG=true
   APP_URL=http://localhost/time_walt_app/public

   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=time_walt_app
   DB_USERNAME=root
   DB_PASSWORD=

   STRIPE_KEY=pk_test_xxxxxxxxxxxxx
   STRIPE_SECRET=sk_test_xxxxxxxxxxxxx
   STRIPE_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxx
   ```

3. Composer dependencies install karo:
   ```bash
   cd C:\xampp\htdocs\time_walt_app
   composer install
   ```

4. Database create karo:
   - phpMyAdmin open karo: `http://localhost/phpmyadmin`
   - New database create karo: `time_walt_app`
   - Character set: `utf8mb4_unicode_ci`

5. Migrations run karo:
   ```bash
   php artisan migrate
   ```

6. Storage link create karo:
   ```bash
   php artisan storage:link
   ```

### Step 3: Apache Virtual Host (Optional - Recommended)

XAMPP apache `httpd-vhosts.conf` file mein add karo:

```apache
<VirtualHost *:80>
    DocumentRoot "C:/xampp/htdocs/time_walt_app/public"
    ServerName time_walt_app.test
    <Directory "C:/xampp/htdocs/time_walt_app/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Windows `hosts` file mein add karo (`C:\Windows\System32\drivers\etc\hosts`):
```
127.0.0.1  time_walt_app.test
```

Ab access karo: `http://time_walt_app.test/api/`

### Step 4: Application Access

- **With Virtual Host:** `http://time_walt_app.test/api/`
- **Without Virtual Host:** `http://localhost/time_walt_app/public/api/`

**Important:** API base URL ya to virtual host ho ya full path with `/public` folder.

---

## ngrok Setup For Webhooks

### Why ngrok Needed?

Stripe webhooks local server ko directly access nahi kar sakte. ngrok local server ko public URL deta hai jisse Stripe webhooks send kar sake.

### Step 1: ngrok Install Karein

1. Download karo: https://ngrok.com/download
2. Extract karo aur `ngrok.exe` ko kisi folder mein rakho (example: `C:\ngrok\`)
3. Path ko system PATH mein add karo (optional)

### Step 2: ngrok Account Create Karein

1. Sign up karo: https://dashboard.ngrok.com/signup
2. Free account ka auth token copy karo
3. ngrok authenticate karo:
   ```bash
   ngrok config add-authtoken YOUR_AUTH_TOKEN
   ```

### Step 3: ngrok Tunnel Start Karein

XAMPP local server ke liye tunnel start karo:

**Option 1: With Virtual Host**
```bash
ngrok http time_walt_app.test:80
```

**Option 2: Without Virtual Host**
```bash
ngrok http localhost:80 --host-header="localhost/time_walt_app/public"
```

**Option 3: Specific Port (If Laravel serve use kar rahe ho)**
```bash
php artisan serve --port=8000
ngrok http 8000
```

### Step 4: ngrok URL Copy Karein

ngrok start karne ke baad terminal mein yeh dikhega:

```
Forwarding  https://abc123xyz.ngrok-free.app -> http://localhost:80
```

**Yeh `https://abc123xyz.ngrok-free.app` URL copy karo!**

### Step 5: Stripe Webhook URL Configure Karein

1. Stripe Dashboard open karo: https://dashboard.stripe.com/test/webhooks
2. "Add endpoint" click karo
3. Endpoint URL dalo:
   ```
   https://abc123xyz.ngrok-free.app/api/stripe/webhook
   ```
4. Events select karo:
   - `payment_intent.succeeded`
   - `payment_intent.payment_failed`
   - `account.updated`
   - `transfer.created`
   - `transfer.failed`
5. "Add endpoint" click karo
6. **Webhook signing secret** copy karo (yeh `whsec_xxxxxxxxxxxxx` format mein hoga)
7. `.env` file mein add karo:
   ```env
   STRIPE_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxx
   ```

### Important ngrok Notes:

- **ngrok URL har baar restart par change hota hai** (free version mein)
- Har baar Stripe webhook URL update karna padega
- Paid ngrok version mein static domain mil sakta hai
- ngrok tunnel band karne se webhooks nahi aayenge

---

## Stripe Configuration

### Step 1: Stripe Account Create

1. Sign up karo: https://dashboard.stripe.com/register
2. Test mode enable karo (top right corner)

### Step 2: API Keys Lein

1. Stripe Dashboard → Developers → API keys
2. **Publishable key** copy karo: `pk_test_xxxxxxxxxxxxx`
3. **Secret key** copy karo: `sk_test_xxxxxxxxxxxxx`
4. `.env` file mein add karo:
   ```env
   STRIPE_KEY=pk_test_xxxxxxxxxxxxx
   STRIPE_SECRET=sk_test_xxxxxxxxxxxxx
   ```

### Step 3: Webhook Secret Configure (ngrok se milne wala)

`.env` file mein add karo:
```env
STRIPE_WEBHOOK_SECRET=whsec_xxxxxxxxxxxxx
```

### Step 4: Stripe Test Cards

Testing ke liye Stripe test cards:

| Card Number | Result | Description |
|-------------|--------|-------------|
| `4242 4242 4242 4242` | Success | Standard successful card |
| `4000 0000 0000 0002` | Declined | Card declined |
| `4000 0000 0000 9995` | Insufficient Funds | Insufficient funds |

**Card Details (Any test card ke liye):**
- Expiry: **Any future date** (12/25, etc.)
- CVC: **Any 3 digits** (123, etc.)
- ZIP: **Any 5 digits** (12345, etc.)

---

## Complete API Details

### API 1: User Login

#### Endpoint: `POST /api/auth/login`

**Purpose:** User ko authenticate karta hai aur access token deta hai. Yeh token baaki sabhi protected APIs ke liye zaroori hai.

**Authentication Required:** ❌ NO

**What It Does:**
1. User email/phone aur password verify karta hai
2. User account verified hai ya nahi check karta hai
3. Account active hai ya nahi check karta hai
4. 2FA enabled hai to OTP verify karta hai
5. Success par Sanctum token generate karta hai

**Required By:**
- Stripe Connect APIs (step 2, 3)
- Payment Intent API (step 4)
- Profile APIs
- Notification APIs

**Headers:**
```
Content-Type: application/json
Accept: application/json
```

**Request Body:**
```json
{
    "email": "user@example.com",
    "password": "password123"
}
```

**Success Response (200):**
```json
{
    "success": true,
    "message": "Login successful.",
    "data": {
        "user": {
            "id": 1,
            "full_name": "Test User",
            "email": "user@example.com",
            "phone": "1234567890",
            "is_verified": true,
            "status": "active"
        },
        "token": "1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
    }
}
```

**Error Responses:**
- `401` - Invalid credentials
- `403` - Account not verified or not active
- `422` - Validation errors

**Important:** Response mein `token` save karo, baaki APIs mein `Authorization: Bearer {token}` header mein use karna hoga.

---

### API 2: Create Stripe Connect Account

#### Endpoint: `POST /api/stripe/connect/create`

**Purpose:** User ke liye Stripe Connect Express account create karta hai. Yeh account future transfers ke liye zaroori hai.

**Authentication Required:** ✅ YES (Bearer Token)

**What It Does:**
1. Check karta hai user ka pehle se connect account hai ya nahi
2. Agar hai to existing account details return karta hai
3. Agar nahi hai to Stripe API call karke new Express account create karta hai
4. Database mein `stripe_connect_accounts` table mein entry create karta hai
5. Account status `pending` set karta hai (kyunki onboarding abhi complete nahi hua)

**Dependencies:**
- **Needs:** User login token (API 1)
- **Creates:** Stripe Connect account record
- **Required For:** Future transfers (but payment holds ke liye zaroori nahi)

**Headers:**
```
Content-Type: application/json
Accept: application/json
Authorization: Bearer {token from API 1}
```

**Request Body:**
```json
{}
```

**Success Response (200):**
```json
{
    "success": true,
    "message": "Stripe Connect account created successfully.",
    "data": {
        "connect_account_id": "acct_1ABC123xyz",
        "status": "pending",
        "onboarding_url": null
    }
}
```

**Already Exists Response (200):**
```json
{
    "success": true,
    "message": "Stripe Connect account already exists.",
    "data": {
        "connect_account_id": "acct_1ABC123xyz",
        "status": "pending",
        "onboarding_url": "https://connect.stripe.com/setup/s/xxxxx"
    }
}
```

**Error Responses:**
- `401` - Unauthenticated (token missing/invalid)
- `500` - Stripe API error

**Notes:**
- Yeh API payment holds ke liye **zaroori nahi hai**
- Yeh sirf future transfers ke liye chahiye
- Pehle call par account create hota hai
- Baar baar call karein to existing account return hota hai

---

### API 3: Get Onboarding Link

#### Endpoint: `POST /api/stripe/connect/onboarding-link`

**Purpose:** Stripe Connect account ke liye onboarding URL generate karta hai. User is URL par click karke apni details complete kar sakta hai.

**Authentication Required:** ✅ YES (Bearer Token)

**What It Does:**
1. User ka connect account check karta hai (pehle API 2 se create hona chahiye)
2. Stripe API se AccountLink create karta hai
3. Onboarding URL generate karta hai
4. Database mein onboarding URL update karta hai

**Dependencies:**
- **Needs:** User login token (API 1)
- **Needs:** Stripe Connect account (API 2 se create hua)
- **Required For:** Connect account verification (payment holds ke liye zaroori nahi)

**Headers:**
```
Content-Type: application/json
Accept: application/json
Authorization: Bearer {token from API 1}
```

**Request Body:**
```json
{}
```

**Success Response (200):**
```json
{
    "success": true,
    "message": "Onboarding link generated successfully.",
    "data": {
        "onboarding_url": "https://connect.stripe.com/setup/s/acct_1ABC123xyz"
    }
}
```

**Error Responses:**
- `401` - Unauthenticated
- `404` - Connect account not found (pehle API 2 call karo)
- `500` - Stripe API error

**Notes:**
- Yeh API payment holds ke liye **zaroori nahi hai**
- Onboarding URL expire ho jata hai after some time
- User onboarding complete karne ke baad `account.updated` webhook aayega

---

### API 4: Create Payment Intent

#### Endpoint: `POST /api/stripe/payment-intent`

**Purpose:** Payment intent create karta hai aur hold period data set karta hai. Yeh **sabse important API** hai payment holds ke liye!

**Authentication Required:** ✅ YES (Bearer Token)

**What It Does:**

1. **Hold Period Data Prepare:**
   - User se hold period type leta hai (`1_month`, `2_months`, `6_months`, `1_year`, `custom`)
   - Start date aur end date calculate karta hai
   - Agar custom hai to user se dates leta hai

2. **Stripe PaymentIntent Create:**
   - Stripe API se PaymentIntent create karta hai
   - Hold period data ko **metadata** mein store karta hai
   - Cache mein bhi store karta hai (webhook ke liye)

3. **Database Payment Record Create:**
   - `payments` table mein entry create karta hai
   - Status: `pending` (kyunki payment abhi complete nahi hua)
   - Payment intent ID save karta hai

4. **Response Return:**
   - Payment intent ID
   - Client secret (frontend payment complete karne ke liye)
   - Hold period details

**Dependencies:**
- **Needs:** User login token (API 1)
- **Creates:** Payment record (status: pending)
- **Creates:** Stripe PaymentIntent
- **Stores:** Hold period data in cache and metadata
- **Required For:** Payment holds creation (but direct nahi create karta)

**Headers:**
```
Content-Type: application/json
Accept: application/json
Authorization: Bearer {token from API 1}
```

**Request Body - 1 Month Hold:**
```json
{
    "amount": 5000,
    "currency": "usd",
    "hold_period_type": "1_month"
}
```

**Request Body - 2 Months Hold:**
```json
{
    "amount": 5000,
    "currency": "usd",
    "hold_period_type": "2_months"
}
```

**Request Body - 6 Months Hold:**
```json
{
    "amount": 5000,
    "currency": "usd",
    "hold_period_type": "6_months"
}
```

**Request Body - 1 Year Hold:**
```json
{
    "amount": 5000,
    "currency": "usd",
    "hold_period_type": "1_year"
}
```

**Request Body - Custom Hold Period:**
```json
{
    "amount": 5000,
    "currency": "usd",
    "hold_period_type": "custom",
    "hold_start_at": "2026-01-15",
    "hold_end_at": "2026-03-15",
    "hold_days": 60
}
```

**Validation Rules:**
- `amount`: Required, integer, minimum 100 (means $1.00 minimum)
- `currency`: Required, must be `usd`, `eur`, or `gbp`
- `hold_period_type`: Optional, must be `1_month`, `2_months`, `6_months`, `1_year`, or `custom`
- `hold_start_at`: Required if custom, must be today or future date
- `hold_end_at`: Required if custom, must be after start date, minimum 30 days difference
- `hold_days`: Optional, minimum 30 days

**Success Response (200):**
```json
{
    "success": true,
    "message": "Payment intent created successfully.",
    "data": {
        "payment_intent_id": "pi_3ABC123xyz",
        "client_secret": "pi_3ABC123xyz_secret_xyz",
        "hold_period": {
            "type": "1_month",
            "start_at": "2026-01-15T10:00:00Z",
            "end_at": "2026-02-14T10:00:00Z",
            "days": 30
        }
    }
}
```

**Error Responses:**
- `401` - Unauthenticated
- `422` - Validation errors (invalid hold period, etc.)
- `500` - Stripe API error

**Critical Points:**
- Amount **cents mein** hota hai (5000 = $50.00)
- Hold period data Stripe metadata mein store hota hai
- Cache mein bhi store hota hai (7 days ke liye)
- **Payment hold abhi create nahi hota** - sirf payment record create hota hai
- Payment hold tab create hoga jab webhook aayega

**After This API Call:**
- ✅ `payments` table mein entry create ho chuki hogi (status: `pending`)
- ❌ `payment_holds` table mein abhi **NO ENTRY** hogi
- Payment hold ke liye webhook ka wait karo

---

### API 5: Stripe Webhook Handler

#### Endpoint: `POST /api/stripe/webhook`

**Purpose:** Stripe se webhook events receive karta hai. Yeh **automatic API** hai - Stripe khud call karta hai!

**Authentication Required:** ❌ NO (but Stripe signature verify hota hai)

**What It Does:**

1. **Webhook Signature Verify:**
   - Stripe signature verify karta hai (security ke liye)
   - Invalid signature par reject karta hai

2. **Event Already Processed Check:**
   - Database mein check karta hai event pehle process hua ya nahi
   - Idempotency ensure karta hai (same event do baar process nahi hoga)

3. **Webhook Event Record Create:**
   - `stripe_webhook_events` table mein entry create karta hai
   - Status: `pending`

4. **Event Type Ke According Process:**
   - `payment_intent.succeeded` → Payment success handle karta hai
   - `payment_intent.payment_failed` → Payment failure handle karta hai
   - `account.updated` → Connect account update handle karta hai
   - `transfer.created` → Transfer success handle karta hai
   - `transfer.failed` → Transfer failure handle karta hai

5. **Payment Hold Create (If payment_intent.succeeded):**
   - Payment record update karta hai (status: `succeeded`)
   - Hold period data retrieve karta hai (cache ya metadata se)
   - `PaymentHoldService` se payment hold create karta hai
   - **Yahin par `payment_holds` table mein entry create hoti hai!** ✅

6. **Event Status Update:**
   - Status: `processed`
   - Processed time save karta hai

**Dependencies:**
- **Called By:** Stripe automatically (user nahi call karta)
- **Needs:** ngrok tunnel (local testing ke liye)
- **Creates:** PaymentHold record (payment_intent.succeeded par)
- **Updates:** Payment status
- **Creates:** Webhook event record

**Headers (Stripe Automatically Adds):**
```
Content-Type: application/json
Stripe-Signature: t=1234567890,v1=xxxxx,v0=yyyyy
```

**Request Body (Stripe Automatically Sends):**
```json
{
    "id": "evt_3ABC123xyz",
    "type": "payment_intent.succeeded",
    "data": {
        "object": {
            "id": "pi_3ABC123xyz",
            "amount": 5000,
            "currency": "usd",
            "status": "succeeded",
            "metadata": {
                "user_id": "1",
                "hold_period_type": "1_month",
                "hold_start_at": "2026-01-15T10:00:00Z",
                "hold_end_at": "2026-02-14T10:00:00Z",
                "hold_days": "30"
            }
        }
    }
}
```

**Success Response (200):**
```json
{
    "received": true
}
```

**Error Responses:**
- `400` - Invalid signature
- `500` - Processing error

**Critical Points:**
- **Yeh API Stripe khud call karta hai** - user manually call nahi karta
- Signature verify hona zaroori hai
- Local testing ke liye ngrok tunnel chahiye
- **Yahin par payment hold create hota hai!**

**After Webhook Processing:**
- ✅ Payment status update → `succeeded`
- ✅ `payment_holds` table mein entry create ho jati hai ✅
- ✅ Hold period data save hota hai
- ✅ Hold status: `holding`

---

## Step By Step Testing

### Complete Testing Flow:

```
┌─────────────────────────────────────────────────────────┐
│ Step 1: Setup (One Time)                                │
├─────────────────────────────────────────────────────────┤
│ ✓ XAMPP Start (Apache + MySQL)                         │
│ ✓ ngrok Tunnel Start                                    │
│ ✓ Stripe Webhook URL Configure                         │
│ ✓ Postman Setup                                         │
└─────────────────────────────────────────────────────────┘
         ↓
┌─────────────────────────────────────────────────────────┐
│ Step 2: User Authentication                             │
├─────────────────────────────────────────────────────────┤
│ API 1: POST /api/auth/login                            │
│ → Token Save Karo                                       │
└─────────────────────────────────────────────────────────┘
         ↓
┌─────────────────────────────────────────────────────────┐
│ Step 3: Payment Intent Create                           │
├─────────────────────────────────────────────────────────┤
│ API 4: POST /api/stripe/payment-intent                 │
│ → Payment Intent ID Save Karo                           │
│ → Hold Period Data Check Karo                           │
└─────────────────────────────────────────────────────────┘
         ↓
┌─────────────────────────────────────────────────────────┐
│ Step 4: Database Check (Before Webhook)                 │
├─────────────────────────────────────────────────────────┤
│ payments table → Entry milni chahiye (pending)          │
│ payment_holds table → NO ENTRY (expected)               │
└─────────────────────────────────────────────────────────┘
         ↓
┌─────────────────────────────────────────────────────────┐
│ Step 5: Payment Complete                                │
├─────────────────────────────────────────────────────────┤
│ Stripe Test Card Use Karo                               │
│ OR Stripe Dashboard se manually test karo               │
└─────────────────────────────────────────────────────────┘
         ↓
┌─────────────────────────────────────────────────────────┐
│ Step 6: Webhook Receive                                 │
├─────────────────────────────────────────────────────────┤
│ Stripe automatically webhook send karega                │
│ OR Stripe CLI se manually trigger karo                  │
└─────────────────────────────────────────────────────────┘
         ↓
┌─────────────────────────────────────────────────────────┐
│ Step 7: Database Check (After Webhook)                  │
├─────────────────────────────────────────────────────────┤
│ payments table → Status: succeeded                      │
│ payment_holds table → ENTRY MILNI CHAHIYE ✅            
│ stripe_webhook_events table → Event record              │
└─────────────────────────────────────────────────────────┘
```

---

### Step 1: XAMPP Setup

1. XAMPP Control Panel open karo
2. Apache aur MySQL start karo
3. phpMyAdmin open karo: `http://localhost/phpmyadmin`
4. Database verify karo (migrations run ho chuki hain)
5. Laravel app accessible hai ya nahi check karo:
   - `http://localhost/time_walt_app/public/api/up` (health check)
   - Expected: `{"status":"ok"}`

---

### Step 2: ngrok Tunnel Start

1. Command prompt/Terminal open karo
2. ngrok start karo:
   ```bash
   ngrok http localhost:80 --host-header="localhost/time_walt_app/public"
   ```
   Ya agar virtual host use kar rahe ho:
   ```bash
   ngrok http time_walt_app.test:80
   ```

3. ngrok dashboard mein forward URL copy karo:
   ```
   https://abc123xyz.ngrok-free.app
   ```

4. Yeh URL Stripe webhook endpoint mein use hogi:
   ```
   https://abc123xyz.ngrok-free.app/api/stripe/webhook
   ```

**Important:** ngrok URL har baar restart par change hota hai, to Stripe webhook URL update karna padega.

---

### Step 3: Stripe Webhook Configure

1. Stripe Dashboard: https://dashboard.stripe.com/test/webhooks
2. "Add endpoint" click karo
3. Endpoint URL: `https://abc123xyz.ngrok-free.app/api/stripe/webhook`
4. Select events:
   - `payment_intent.succeeded`
   - `payment_intent.payment_failed`
   - `account.updated`
   - `transfer.created`
   - `transfer.failed`
5. "Add endpoint" save karo
6. **Signing secret** copy karo (`whsec_xxxxx`)
7. `.env` file mein update karo:
   ```env
   STRIPE_WEBHOOK_SECRET=whsec_xxxxx
   ```

---

### Step 4: Postman Setup

1. Postman install karo ya open karo
2. New Collection create karo: "Payment Holds Testing"
3. Environment create karo:
   - Variable: `base_url`
   - Value: `http://localhost/time_walt_app/public/api` (ya virtual host URL)
   - Variable: `token`
   - Value: (empty - step 5 se fill hoga)
   - Variable: `payment_intent_id`
   - Value: (empty - step 6 se fill hoga)
   - Variable: `ngrok_url`
   - Value: `https://abc123xyz.ngrok-free.app/api/stripe/webhook`

---

### Step 5: User Login (API 1)

**Postman Request:**

1. Method: `POST`
2. URL: `{{base_url}}/auth/login`
3. Headers:
   ```
   Content-Type: application/json
   Accept: application/json
   ```
4. Body (raw JSON):
   ```json
   {
       "email": "test@example.com",
       "password": "password123"
   }
   ```
5. Send karo
6. Response se `token` copy karo
7. Postman environment mein `token` variable update karo

**Expected Response:**
```json
{
    "success": true,
    "message": "Login successful.",
    "data": {
        "user": { ... },
        "token": "1|xxxxxxxxxxxxx"
    }
}
```

**Verification:**
- Response status: `200`
- `success: true`
- `token` field present hai
- Token copy karo environment mein save karo

---

### Step 6: Create Payment Intent (API 4)

**Postman Request:**

1. Method: `POST`
2. URL: `{{base_url}}/stripe/payment-intent`
3. Headers:
   ```
   Content-Type: application/json
   Accept: application/json
   Authorization: Bearer {{token}}
   ```
4. Body (raw JSON):
   ```json
   {
       "amount": 5000,
       "currency": "usd",
       "hold_period_type": "1_month"
   }
   ```
5. Send karo

**Expected Response:**
```json
{
    "success": true,
    "message": "Payment intent created successfully.",
    "data": {
        "payment_intent_id": "pi_3ABC123xyz",
        "client_secret": "pi_3ABC123xyz_secret_xyz",
        "hold_period": {
            "type": "1_month",
            "start_at": "2026-01-15T10:00:00Z",
            "end_at": "2026-02-14T10:00:00Z",
            "days": 30
        }
    }
}
```

**Verification:**
- Response status: `200`
- `success: true`
- `payment_intent_id` present hai
- `hold_period` data sahi hai
- `payment_intent_id` copy karo environment mein save karo

**Database Check (phpMyAdmin):**
```sql
SELECT * FROM payments 
WHERE payment_intent_id = 'pi_3ABC123xyz';
```

**Expected:**
- ✅ Entry milni chahiye
- ✅ Status: `pending`
- ✅ Amount: `50.00`
- ✅ User ID sahi hai

```sql
SELECT * FROM payment_holds 
WHERE payment_id = (SELECT id FROM payments WHERE payment_intent_id = 'pi_3ABC123xyz');
```

**Expected:**
- ❌ **NO ENTRY** (kyunki abhi webhook nahi aaya)

---

### Step 7: Payment Complete Karein

**Option 1: Stripe Dashboard Se (Easiest)**

1. Stripe Dashboard: https://dashboard.stripe.com/test/payments
2. Payment intent ID se search karo: `pi_3ABC123xyz`
3. "Confirm payment" click karo (agar pending hai)
4. Test card use karo: `4242 4242 4242 4242`

**Option 2: Stripe CLI Se**

```bash
stripe payment_intents confirm pi_3ABC123xyz --payment-method=pm_card_visa
```

**Option 3: Frontend Se (Agar hai)**

Frontend mein `client_secret` use karke payment complete karo.

---

### Step 8: Webhook Receive (Automatic)

**Stripe automatically webhook send karega** jab payment succeed hoga.

**Manual Testing (Stripe CLI):**

```bash
stripe trigger payment_intent.succeeded
```

Ya specific payment intent ke liye:
```bash
stripe events create --type=payment_intent.succeeded --data-object=payment_intent --data-object=pi_3ABC123xyz
```

**Verification:**

1. **ngrok Dashboard:**
   - https://dashboard.ngrok.com/
   - Requests tab mein webhook request dikhega

2. **Laravel Logs:**
   ```bash
   tail -f storage/logs/laravel.log
   ```
   Webhook processing logs dikhenge

3. **Database Check:**
   ```sql
   SELECT * FROM stripe_webhook_events 
   ORDER BY created_at DESC 
   LIMIT 1;
   ```
   - ✅ Webhook event record create honi chahiye
   - ✅ Status: `processed`

---

### Step 9: Database Verification (Final)

**payments Table:**
```sql
SELECT * FROM payments 
WHERE payment_intent_id = 'pi_3ABC123xyz';
```

**Expected:**
- ✅ Status: `succeeded` (pending se update ho gaya)
- ✅ `paid_at` field set ho gaya
- ✅ `stripe_data` field mein full payment data

**payment_holds Table (Yeh Most Important Hai!):**
```sql
SELECT * FROM payment_holds 
WHERE payment_id = (SELECT id FROM payments WHERE payment_intent_id = 'pi_3ABC123xyz');
```

**Expected:**
- ✅ **ENTRY MILNI CHAHIYE!** ✅
- ✅ `payment_id` sahi hai
- ✅ `user_id` sahi hai
- ✅ `amount` sahi hai (50.00)
- ✅ `hold_start_at` set hai
- ✅ `hold_end_at` set hai (30 days baad)
- ✅ `hold_days` = 30
- ✅ `hold_period_type` = `1_month`
- ✅ `status` = `holding`
- ✅ `ready_at` = NULL (abhi NULL hoga)
- ✅ `transferred_at` = NULL (abhi NULL hoga)

**Complete Join Query (All Details):**
```sql
SELECT 
    ph.id as hold_id,
    ph.amount as hold_amount,
    ph.hold_start_at,
    ph.hold_end_at,
    ph.hold_days,
    ph.hold_period_type,
    ph.status as hold_status,
    p.id as payment_id,
    p.payment_intent_id,
    p.status as payment_status,
    p.amount as payment_amount,
    u.id as user_id,
    u.email as user_email,
    u.full_name as user_name
FROM payment_holds ph
JOIN payments p ON ph.payment_id = p.id
JOIN users u ON ph.user_id = u.id
WHERE p.payment_intent_id = 'pi_3ABC123xyz';
```

---

## All Scenarios Explained

### Scenario 1: Basic Flow (1 Month Hold)

**Flow:**
1. Login → Token
2. Payment Intent Create → `hold_period_type: "1_month"`
3. Payment Complete
4. Webhook Receive
5. Payment Hold Create → `hold_days: 30`, `status: holding`

**Expected Result:**
- Payment Hold created with 30 days hold period
- Status: `holding`
- Hold ends 30 days from start date

---

### Scenario 2: 2 Months Hold

**Flow:**
1. Login → Token
2. Payment Intent Create → `hold_period_type: "2_months"`
3. Payment Complete
4. Webhook Receive
5. Payment Hold Create → `hold_days: 60`, `status: holding`

**Expected Result:**
- Payment Hold created with 60 days hold period
- Status: `holding`
- Hold ends 60 days from start date

---

### Scenario 3: 6 Months Hold

**Flow:**
1. Login → Token
2. Payment Intent Create → `hold_period_type: "6_months"`
3. Payment Complete
4. Webhook Receive
5. Payment Hold Create → `hold_days: 180`, `status: holding`

**Expected Result:**
- Payment Hold created with 180 days hold period
- Status: `holding`
- Hold ends 180 days from start date

---

### Scenario 4: 1 Year Hold

**Flow:**
1. Login → Token
2. Payment Intent Create → `hold_period_type: "1_year"`
3. Payment Complete
4. Webhook Receive
5. Payment Hold Create → `hold_days: 365`, `status: holding`

**Expected Result:**
- Payment Hold created with 365 days hold period
- Status: `holding`
- Hold ends 365 days from start date

---

### Scenario 5: Custom Hold Period

**Flow:**
1. Login → Token
2. Payment Intent Create → `hold_period_type: "custom"`, custom dates
3. Payment Complete
4. Webhook Receive
5. Payment Hold Create → Custom dates ke according

**Request Example:**
```json
{
    "amount": 5000,
    "currency": "usd",
    "hold_period_type": "custom",
    "hold_start_at": "2026-01-15",
    "hold_end_at": "2026-04-15",
    "hold_days": 90
}
```

**Expected Result:**
- Payment Hold created with custom dates
- `hold_start_at`: 2026-01-15
- `hold_end_at`: 2026-04-15
- `hold_days`: 90 (calculated automatically)

**Validation:**
- Minimum 30 days required
- End date must be after start date

---

### Scenario 6: Payment Without Hold Period

**Flow:**
1. Login → Token
2. Payment Intent Create → **NO** `hold_period_type` field
3. Payment Complete
4. Webhook Receive
5. Payment Hold Create → **Default 30 days**

**Request Example:**
```json
{
    "amount": 5000,
    "currency": "usd"
}
```

**Expected Result:**
- Payment Hold still created (default behavior)
- Default hold period: 30 days
- `hold_period_type`: `1_month` (default)

---

### Scenario 7: Payment Failure

**Flow:**
1. Login → Token
2. Payment Intent Create
3. Payment **FAILED** (declined card use karo)
4. Webhook Receive → `payment_intent.payment_failed`
5. Payment Hold **NOT CREATED**

**Request Example (Failed Card):**
- Card: `4000 0000 0000 0002` (declined card)

**Expected Result:**
- ❌ Payment Hold **NOT CREATED**
- Payment status: `failed`
- `failure_reason` set ho jayega

**Database Check:**
```sql
SELECT * FROM payments WHERE status = 'failed';
```

---

### Scenario 8: Duplicate Webhook (Idempotency)

**Flow:**
1. Payment Intent Create
2. Payment Complete
3. Webhook Receive → Payment Hold Create
4. **Same Webhook Again Receive** (Stripe sometimes retry karta hai)
5. Payment Hold **NOT CREATED AGAIN** (idempotency)

**Expected Result:**
- First webhook: Payment Hold created ✅
- Second webhook (same event): Already processed, ignored
- No duplicate payment holds

**Database Check:**
```sql
SELECT * FROM stripe_webhook_events 
WHERE stripe_event_id = 'evt_xxxxx';
```

**Expected:**
- Status: `processed`
- Event processed only once

---

### Scenario 9: Multiple Payments Same User

**Flow:**
1. Login → Token
2. Payment Intent 1 Create → Hold 1
3. Payment Intent 2 Create → Hold 2
4. Payment Intent 3 Create → Hold 3
5. All payments complete
6. All webhooks receive
7. **3 Separate Payment Holds Created**

**Expected Result:**
- 3 separate payment records
- 3 separate payment holds
- Each hold independent hai
- Different hold periods bhi set kar sakte ho

**Database Check:**
```sql
SELECT COUNT(*) as total_holds 
FROM payment_holds 
WHERE user_id = 1;
```

---

### Scenario 10: Hold Period Expiry Check

**Flow:**
1. Payment Hold Create → `hold_end_at`: Future date
2. **Wait** until `hold_end_at` pass ho jaye
3. Cron job run karo (ya manually check karo)
4. Status update → `ready_for_transfer`

**Note:** Actual cron job code check karo ya manually status update karo.

**Expected Result:**
- When `hold_end_at` <= current time
- Status should update to `ready_for_transfer`
- `ready_at` field set ho jayega

**Database Check:**
```sql
SELECT * FROM payment_holds 
WHERE status = 'holding' 
AND hold_end_at <= NOW();
```

---

## Database Verification

### Useful SQL Queries

**1. All Payment Holds With Details:**
```sql
SELECT 
    ph.id as hold_id,
    ph.amount as hold_amount,
    ph.hold_start_at,
    ph.hold_end_at,
    ph.hold_days,
    ph.hold_period_type,
    ph.status as hold_status,
    p.payment_intent_id,
    p.status as payment_status,
    u.email as user_email,
    ph.created_at as hold_created_at
FROM payment_holds ph
JOIN payments p ON ph.payment_id = p.id
JOIN users u ON ph.user_id = u.id
ORDER BY ph.created_at DESC;
```

**2. Specific User Ke Holds:**
```sql
SELECT * FROM payment_holds 
WHERE user_id = 1
ORDER BY created_at DESC;
```

**3. Pending Payments (Hold Not Created Yet):**
```sql
SELECT p.* 
FROM payments p
LEFT JOIN payment_holds ph ON ph.payment_id = p.id
WHERE p.status = 'pending' 
AND ph.id IS NULL;
```

**4. Success Payments Without Holds (Problem Check):**
```sql
SELECT p.* 
FROM payments p
LEFT JOIN payment_holds ph ON ph.payment_id = p.id
WHERE p.status = 'succeeded' 
AND ph.id IS NULL;
```

**5. Ready For Transfer Holds:**
```sql
SELECT * FROM payment_holds 
WHERE status = 'ready_for_transfer'
ORDER BY hold_end_at ASC;
```

**6. Holding Status (Active Holds):**
```sql
SELECT * FROM payment_holds 
WHERE status = 'holding'
ORDER BY hold_end_at ASC;
```

**7. Hold Period Expiring Soon (Next 7 Days):**
```sql
SELECT * FROM payment_holds 
WHERE status = 'holding'
AND hold_end_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
ORDER BY hold_end_at ASC;
```

**8. Webhook Events (Recent):**
```sql
SELECT * FROM stripe_webhook_events 
ORDER BY created_at DESC 
LIMIT 10;
```

**9. Failed Webhooks (Check Errors):**
```sql
SELECT * FROM stripe_webhook_events 
WHERE status = 'failed'
ORDER BY created_at DESC;
```

**10. Payment With Hold Details (Complete Info):**
```sql
SELECT 
    p.id as payment_id,
    p.payment_intent_id,
    p.amount as payment_amount,
    p.status as payment_status,
    p.created_at as payment_created,
    ph.id as hold_id,
    ph.amount as hold_amount,
    ph.status as hold_status,
    ph.hold_start_at,
    ph.hold_end_at,
    ph.hold_days,
    ph.hold_period_type,
    CASE 
        WHEN ph.status = 'holding' AND ph.hold_end_at <= NOW() 
        THEN 'READY (But not updated)'
        WHEN ph.status = 'holding' 
        THEN CONCAT(DATEDIFF(ph.hold_end_at, NOW()), ' days remaining')
        WHEN ph.status = 'ready_for_transfer' 
        THEN 'Ready for transfer'
        WHEN ph.status = 'transferred' 
        THEN 'Transferred'
        ELSE ph.status
    END as hold_info
FROM payments p
LEFT JOIN payment_holds ph ON ph.payment_id = p.id
ORDER BY p.created_at DESC;
```

---

## Troubleshooting

### Issue 1: Payment Holds Table Mein Data Nahi Hai

**Symptoms:**
- Payment intent create ho gaya
- Payment succeed ho gaya
- But `payment_holds` table mein entry nahi hai

**Possible Causes:**
1. Webhook nahi aaya
2. Webhook signature verify nahi hua
3. Webhook processing fail ho gaya
4. Hold period data missing thi

**Solutions:**

**Check 1: Webhook Aaya Ya Nahi**
```sql
SELECT * FROM stripe_webhook_events 
WHERE event_type = 'payment_intent.succeeded'
ORDER BY created_at DESC;
```

**Check 2: Laravel Logs**
```bash
tail -f storage/logs/laravel.log
```
Look for webhook errors.

**Check 3: ngrok Dashboard**
- Check karo webhook request aaya ya nahi
- https://dashboard.ngrok.com/

**Check 4: Stripe Dashboard**
- Webhook events tab mein check karo
- Delivery status check karo

**Fix:**
1. ngrok tunnel verify karo (running hai ya nahi)
2. Webhook URL sahi hai ya nahi check karo
3. `.env` mein `STRIPE_WEBHOOK_SECRET` sahi hai ya nahi
4. Webhook manually trigger karo Stripe CLI se:
   ```bash
   stripe events resend evt_xxxxx
   ```

---

### Issue 2: Webhook Signature Error

**Symptoms:**
- Webhook receive ho raha hai
- But response: `400 Invalid signature`

**Possible Causes:**
1. Wrong webhook secret in `.env`
2. ngrok URL changed but Stripe webhook URL not updated
3. Webhook secret from wrong webhook endpoint

**Solutions:**
1. Stripe Dashboard mein webhook endpoint ka signing secret copy karo
2. `.env` file mein update karo:
   ```env
   STRIPE_WEBHOOK_SECRET=whsec_xxxxx
   ```
3. Application restart karo
4. Test karo again

---

### Issue 3: Hold Period Data Missing

**Symptoms:**
- Payment Hold create ho gaya
- But hold period data missing (dates NULL)

**Possible Causes:**
1. Payment intent create karte waqt hold period data nahi bheji
2. Cache expire ho gaya (7 days)
3. Stripe metadata missing

**Solutions:**
1. Check payment intent metadata:
   ```sql
   SELECT stripe_data->>'$.metadata' as metadata 
   FROM payments 
   WHERE payment_intent_id = 'pi_xxxxx';
   ```
2. Payment intent create karte waqt `hold_period_type` field bhejo
3. Default hold period apply hoga (30 days) agar data missing hai

---

### Issue 4: ngrok URL Changed

**Symptoms:**
- ngrok restart kiya
- Webhooks ab nahi aa rahe

**Reason:**
- ngrok free version mein URL har baar restart par change hota hai

**Solutions:**
1. New ngrok URL copy karo
2. Stripe Dashboard mein webhook endpoint update karo
3. New webhook secret copy karo (agar endpoint recreate kiya)
4. `.env` file mein update karo

**Better Solution:**
- Paid ngrok version use karo (static domain)
- Ya localtunnel use karo (alternative)

---

### Issue 5: Payment Succeeded But Hold Not Created

**Symptoms:**
- Payment status: `succeeded`
- Webhook event: `processed`
- But payment hold record missing

**Possible Causes:**
1. `PaymentHoldService::createFromPayment()` fail ho gaya
2. Database transaction rollback ho gaya
3. Exception catch ho gaya but logged nahi hua

**Solutions:**
1. Laravel logs check karo:
   ```bash
   grep -i "payment hold" storage/logs/laravel.log
   ```
2. Database manually check karo:
   ```sql
   SELECT * FROM payments WHERE status = 'succeeded' 
   AND id NOT IN (SELECT payment_id FROM payment_holds);
   ```
3. WebhookService code check karo - exception handling

---

## Quick Reference

### API Endpoints Summary

| API | Method | Endpoint | Auth | Purpose |
|-----|--------|----------|------|---------|
| Login | POST | `/api/auth/login` | ❌ | User authentication |
| Connect Create | POST | `/api/stripe/connect/create` | ✅ | Create Connect account |
| Onboarding Link | POST | `/api/stripe/connect/onboarding-link` | ✅ | Get onboarding URL |
| Payment Intent | POST | `/api/stripe/payment-intent` | ✅ | Create payment + hold period |
| Webhook | POST | `/api/stripe/webhook` | ❌ | Stripe webhook handler |

### Hold Period Types

| Type | Days | Description |
|------|------|-------------|
| `1_month` | 30 | 1 month hold |
| `2_months` | 60 | 2 months hold |
| `6_months` | 180 | 6 months hold |
| `1_year` | 365 | 1 year hold |
| `custom` | User defined | Custom dates (min 30 days) |

### Payment Hold Statuses

| Status | Description | When |
|--------|-------------|------|
| `holding` | Hold period active | Created until `hold_end_at` |
| `ready_for_transfer` | Ready to transfer | When `hold_end_at` passed |
| `transferred` | Transfer completed | After successful transfer |

### Database Tables

| Table | Purpose | Key Fields |
|-------|---------|------------|
| `payments` | Payment records | `payment_intent_id`, `status` |
| `payment_holds` | Hold records | `payment_id`, `hold_end_at`, `status` |
| `stripe_webhook_events` | Webhook logs | `stripe_event_id`, `event_type`, `status` |
| `stripe_connect_accounts` | Connect accounts | `connect_account_id`, `status` |
| `transfers` | Transfer records | `hold_id`, `stripe_transfer_id`, `status` |

---

## Final Checklist

Before testing, ensure:

- [ ] XAMPP running (Apache + MySQL)
- [ ] Laravel app accessible
- [ ] Database migrations run ho chuki hain
- [ ] `.env` file properly configured (Stripe keys)
- [ ] ngrok tunnel running
- [ ] Stripe webhook endpoint configured with ngrok URL
- [ ] Postman setup with environment variables
- [ ] Test user account created
- [ ] User logged in, token received

During testing:

- [ ] Payment intent created successfully
- [ ] `payments` table mein entry created (`status: pending`)
- [ ] Payment completed (Stripe)
- [ ] Webhook received (check ngrok dashboard)
- [ ] Webhook processed (check logs)
- [ ] `payments` status updated to `succeeded`
- [ ] **`payment_holds` table mein entry created** ✅
- [ ] Hold period data correct
- [ ] Hold status: `holding`

---

**End of Complete Guide**

Agar koi aur question hai ya specific scenario test karni hai, to batayein!
