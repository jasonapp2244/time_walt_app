# Stripe Payment APIs - Step-by-Step Implementation Guide

## 📋 Overview
Yeh guide tables ke hisab se complete API implementation ka step-by-step process batata hai.

---

## 🗂️ Step 1: Create Models

### 1.1 StripeConnectAccount Model
**File:** `app/Models/StripeConnectAccount.php`

**Steps:**
1. Run: `php artisan make:model StripeConnectAccount`
2. Add fillable fields:
   - `user_id`, `connect_account_id`, `status`, `payouts_enabled`
   - `onboarding_url`, `stripe_data`, `verified_at`
3. Add relationships:
   - `belongsTo(User::class)`
4. Add casts:
   - `payouts_enabled` => boolean
   - `stripe_data` => array
   - `verified_at` => datetime

**Fields from table:**
- All fields from `stripe_connect_accounts` migration

---

### 1.2 Payment Model
**File:** `app/Models/Payment.php`

**Steps:**
1. Run: `php artisan make:model Payment`
2. Add fillable fields:
   - `user_id`, `payment_intent_id`, `amount`, `currency`, `status`
   - `paid_at`, `stripe_data`, `failure_reason`
3. Add relationships:
   - `belongsTo(User::class)`
   - `hasOne(PaymentHold::class)`
4. Add casts:
   - `amount` => decimal
   - `status` => enum
   - `paid_at` => datetime
   - `stripe_data` => array

**Fields from table:**
- All fields from `payments` migration

---

### 1.3 PaymentHold Model
**File:** `app/Models/PaymentHold.php`

**Steps:**
1. Run: `php artisan make:model PaymentHold`
2. Add fillable fields:
   - `payment_id`, `user_id`, `amount`, `hold_start_at`, `hold_end_at`
   - `hold_days`, `hold_period_type`, `status`
   - `ready_at`, `transferred_at`
3. Add relationships:
   - `belongsTo(Payment::class)`
   - `belongsTo(User::class)`
   - `hasOne(Transfer::class)`
4. Add casts:
   - `amount` => decimal
   - `hold_start_at`, `hold_end_at`, `ready_at`, `transferred_at` => datetime
   - `status` => enum

**Fields from table:**
- All fields from `payment_holds` migration

---

### 1.4 Transfer Model
**File:** `app/Models/Transfer.php`

**Steps:**
1. Run: `php artisan make:model Transfer`
2. Add fillable fields:
   - `hold_id`, `user_id`, `stripe_transfer_id`, `stripe_connect_account_id`
   - `amount`, `currency`, `status`, `transferred_at`
   - `failure_reason`, `stripe_data`, `admin_id`, `transfer_type`
3. Add relationships:
   - `belongsTo(PaymentHold::class, 'hold_id')`
   - `belongsTo(User::class)`
   - `belongsTo(User::class, 'admin_id')` (for admin)
4. Add casts:
   - `amount` => decimal
   - `status` => enum
   - `transferred_at` => datetime
   - `stripe_data` => array

**Fields from table:**
- All fields from `transfers` migration

---

### 1.5 StripeWebhookEvent Model
**File:** `app/Models/StripeWebhookEvent.php`

**Steps:**
1. Run: `php artisan make:model StripeWebhookEvent`
2. Add fillable fields:
   - `stripe_event_id`, `event_type`, `status`
   - `payload`, `processed_at`, `error_message`
   - `retry_count`, `last_retry_at`
3. Add casts:
   - `status` => enum
   - `payload` => array
   - `processed_at`, `last_retry_at` => datetime

**Fields from table:**
- All fields from `stripe_webhook_events` migration

---

## 📝 Step 2: Create Form Request Classes

### 2.1 Create Stripe Connect Account Request
**File:** `app/Http/Requests/Stripe/CreateConnectAccountRequest.php`

**Steps:**
1. Run: `php artisan make:request Stripe/CreateConnectAccountRequest`
2. Add validation rules:
   - No validation needed (just authenticated user)

**Purpose:** Validate create connect account request

---

### 2.2 Get Onboarding Link Request
**File:** `app/Http/Requests/Stripe/GetOnboardingLinkRequest.php`

**Steps:**
1. Run: `php artisan make:request Stripe/GetOnboardingLinkRequest`
2. Add validation rules:
   - No validation needed (just authenticated user)

**Purpose:** Validate onboarding link request

---

### 2.3 Create Payment Intent Request
**File:** `app/Http/Requests/Stripe/CreatePaymentIntentRequest.php`

**Steps:**
1. Run: `php artisan make:request Stripe/CreatePaymentIntentRequest`
2. Add validation rules:
   ```php
   'amount' => 'required|integer|min:100', // minimum $1.00
   'currency' => 'required|string|size:3|in:usd,eur,gbp',
   'hold_period_type' => 'nullable|string|in:1_month,2_months,6_months,1_year,custom',
   'hold_start_at' => 'nullable|date|after_or_equal:today',
   'hold_end_at' => 'nullable|date|after:hold_start_at',
   'hold_days' => 'nullable|integer|min:30'
   ```
3. Add custom validation:
   - If `hold_period_type` is `custom`, `hold_start_at` and `hold_end_at` required
   - `hold_end_at` must be at least 30 days after `hold_start_at`
   - Calculate `hold_days` if not provided

**Purpose:** Validate payment intent creation with hold period

---

### 2.4 Admin Transfer Request
**File:** `app/Http/Requests/Admin/TransferRequest.php`

**Steps:**
1. Run: `php artisan make:request Admin/TransferRequest`
2. Add validation rules:
   - Check if user is admin (middleware/policy)
   - No additional validation (hold_id from route parameter)

**Purpose:** Validate admin transfer request

---

## 🎮 Step 3: Create Controllers

### 3.1 StripeController
**File:** `app/Http/Controllers/Api/StripeController.php`

**Steps:**
1. Run: `php artisan make:controller Api/StripeController`
2. Create methods:

#### Method 1: `createConnectAccount()`
**Purpose:** Create Stripe Connect Express account for user

**Logic:**
1. Check if user already has connect account
2. If exists, return existing account
3. If not, call Stripe API:
   ```php
   \Stripe\Account::create([
       'type' => 'express',
       'country' => 'US', // or from user profile
       'email' => $user->email,
   ]);
   ```
4. Create `StripeConnectAccount` record:
   - `user_id` = authenticated user
   - `connect_account_id` = Stripe account ID
   - `status` = 'pending'
   - `payouts_enabled` = false
   - `stripe_data` = full Stripe response
5. Return response with `connect_account_id`

**Response:**
```json
{
  "success": true,
  "data": {
    "connect_account_id": "acct_xxx",
    "status": "pending",
    "onboarding_url": null
  }
}
```

---

#### Method 2: `getOnboardingLink()`
**Purpose:** Get Stripe onboarding link for bank details

**Logic:**
1. Get user's connect account
2. If not exists, return error
3. Call Stripe API:
   ```php
   \Stripe\Account::createLoginLink($connectAccountId);
   ```
4. Or create account link:
   ```php
   \Stripe\AccountLink::create([
       'account' => $connectAccountId,
       'refresh_url' => config('app.url') . '/stripe/reauth',
       'return_url' => config('app.url') . '/stripe/return',
       'type' => 'account_onboarding',
   ]);
   ```
5. Update `onboarding_url` in database
6. Return onboarding URL

**Response:**
```json
{
  "success": true,
  "data": {
    "onboarding_url": "https://connect.stripe.com/..."
  }
}
```

---

#### Method 3: `createPaymentIntent()`
**Purpose:** Create Stripe PaymentIntent with hold period data

**Logic:**
1. Validate request (Form Request)
2. Get user's connect account (optional check)
3. Prepare hold period data:
   - If `hold_period_type` provided, calculate dates
   - If custom, use provided dates
   - Validate minimum 30 days
4. Call Stripe API:
   ```php
   \Stripe\PaymentIntent::create([
       'amount' => $request->amount,
       'currency' => $request->currency,
       'metadata' => [
           'user_id' => $user->id,
           'hold_period_type' => $request->hold_period_type,
           'hold_start_at' => $holdStartAt,
           'hold_end_at' => $holdEndAt,
           'hold_days' => $holdDays,
       ],
   ]);
   ```
5. Store hold period data temporarily (cache or database)
   - Key: `payment_intent_hold_{payment_intent_id}`
   - Store: hold period data
6. Create `Payment` record:
   - `user_id` = authenticated user
   - `payment_intent_id` = Stripe PaymentIntent ID
   - `amount` = request amount
   - `currency` = request currency
   - `status` = 'pending'
   - `stripe_data` = full Stripe response
7. Return `client_secret` for Flutter

**Response:**
```json
{
  "success": true,
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

---

#### Method 4: `handleWebhook()`
**Purpose:** Handle Stripe webhook events

**Logic:**
1. Verify webhook signature:
   ```php
   $event = \Stripe\Webhook::constructEvent(
       $request->getContent(),
       $request->header('Stripe-Signature'),
       config('services.stripe.webhook_secret')
   );
   ```
2. Check if event already processed:
   - Query `StripeWebhookEvent` by `stripe_event_id`
   - If exists and status = 'processed', return 200 (idempotency)
3. Create `StripeWebhookEvent` record:
   - `stripe_event_id` = event ID
   - `event_type` = event type
   - `status` = 'pending'
   - `payload` = full event data
4. Process event based on type:
   - `payment_intent.succeeded` → Handle payment success
   - `payment_intent.failed` → Handle payment failure
   - `account.updated` → Handle account update
   - `transfer.created` → Handle transfer created
   - `transfer.failed` → Handle transfer failed
5. Update `StripeWebhookEvent` status:
   - On success: `status` = 'processed', `processed_at` = now()
   - On failure: `status` = 'failed', `error_message` = error
6. Return 200 OK

**Response:** Always return 200 OK (Stripe expects this)

---

### 3.2 AdminTransferController
**File:** `app/Http/Controllers/Api/Admin/TransferController.php`

**Steps:**
1. Run: `php artisan make:controller Api/Admin/TransferController`
2. Add admin middleware/authorization
3. Create method:

#### Method: `transfer()`
**Purpose:** Manually trigger transfer for a hold

**Logic:**
1. Check if user is admin (middleware/policy)
2. Get `PaymentHold` by `hold_id`
3. Validate:
   - Hold status must be 'ready_for_transfer' or 'holding' (if admin override)
   - Hold must have valid connect account
4. Call transfer service method (see Step 4)
5. Return response

**Response:**
```json
{
  "success": true,
  "data": {
    "transfer_id": 1,
    "stripe_transfer_id": "tr_xxx",
    "status": "pending",
    "amount": 10000
  }
}
```

---

## 🔧 Step 4: Create Services

### 4.1 StripeService
**File:** `app/Services/StripeService.php`

**Steps:**
1. Create service class manually
2. Add methods:

#### Method 1: `createConnectAccount(User $user)`
**Purpose:** Create Stripe Connect account

**Logic:**
- Call Stripe API
- Create database record
- Return account data

---

#### Method 2: `createPaymentIntent(array $data)`
**Purpose:** Create PaymentIntent with metadata

**Logic:**
- Call Stripe API
- Store hold period in metadata
- Return PaymentIntent

---

#### Method 3: `createTransfer(PaymentHold $hold, string $type = 'manual')`
**Purpose:** Create Stripe Transfer

**Logic:**
1. Get user's connect account
2. Validate account is ready
3. Call Stripe API:
   ```php
   \Stripe\Transfer::create([
       'amount' => $hold->amount * 100, // convert to cents
       'currency' => 'usd',
       'destination' => $hold->user->connectAccount->connect_account_id,
   ]);
   ```
4. Create `Transfer` record:
   - `hold_id` = hold ID
   - `user_id` = hold user ID
   - `stripe_transfer_id` = Stripe Transfer ID
   - `stripe_connect_account_id` = destination account
   - `amount` = hold amount
   - `currency` = 'usd'
   - `status` = 'pending'
   - `transfer_type` = $type ('manual' or 'cron')
   - `admin_id` = admin user ID (if manual)
   - `stripe_data` = full Stripe response
5. Update `PaymentHold`:
   - `status` = 'transferred' (after webhook confirms)
   - `transferred_at` = now() (after webhook confirms)
6. Return transfer data

---

### 4.2 PaymentHoldService
**File:** `app/Services/PaymentHoldService.php`

**Steps:**
1. Create service class manually
2. Add methods:

#### Method 1: `createFromPayment(Payment $payment, array $holdPeriodData)`
**Purpose:** Create payment hold after payment success

**Logic:**
1. Calculate hold dates:
   - If custom dates provided, use them
   - If period type provided, calculate from payment time
   - Default: 30 days from payment time
2. Create `PaymentHold` record:
   - `payment_id` = payment ID
   - `user_id` = payment user ID
   - `amount` = payment amount
   - `hold_start_at` = calculated start date
   - `hold_end_at` = calculated end date
   - `hold_days` = calculated days
   - `hold_period_type` = period type
   - `status` = 'holding'
3. Return hold record

---

#### Method 2: `checkAndMarkReady()`
**Purpose:** Cron job method to check holds ready for transfer

**Logic:**
1. Query holds:
   ```php
   PaymentHold::where('status', 'holding')
       ->where('hold_end_at', '<=', now())
       ->get();
   ```
2. For each hold:
   - Update `status` = 'ready_for_transfer'
   - Update `ready_at` = now()
   - Optionally: Auto create transfer (if enabled)
3. Return count of updated holds

---

### 4.3 WebhookService
**File:** `app/Services/WebhookService.php`

**Steps:**
1. Create service class manually
2. Add methods:

#### Method 1: `handlePaymentIntentSucceeded($event)`
**Purpose:** Handle payment success webhook

**Logic:**
1. Get payment intent ID from event
2. Find `Payment` by `payment_intent_id`
3. Update `Payment`:
   - `status` = 'succeeded'
   - `paid_at` = now()
   - `stripe_data` = event data
4. Retrieve hold period data from metadata or cache
5. Create `PaymentHold` using `PaymentHoldService::createFromPayment()`
6. Return success

---

#### Method 2: `handlePaymentIntentFailed($event)`
**Purpose:** Handle payment failure webhook

**Logic:**
1. Get payment intent ID from event
2. Find `Payment` by `payment_intent_id`
3. Update `Payment`:
   - `status` = 'failed'
   - `failure_reason` = error message from event
   - `stripe_data` = event data
4. Return success

---

#### Method 3: `handleAccountUpdated($event)`
**Purpose:** Handle Stripe Connect account update

**Logic:**
1. Get account ID from event
2. Find `StripeConnectAccount` by `connect_account_id`
3. Update account:
   - `status` = account status (verified/restricted)
   - `payouts_enabled` = account.payouts_enabled
   - `stripe_data` = event data
   - If verified: `verified_at` = now()
4. Return success

---

#### Method 4: `handleTransferCreated($event)`
**Purpose:** Handle transfer success webhook

**Logic:**
1. Get transfer ID from event
2. Find `Transfer` by `stripe_transfer_id`
3. Update `Transfer`:
   - `status` = 'completed'
   - `transferred_at` = now()
   - `stripe_data` = event data
4. Update `PaymentHold`:
   - `status` = 'transferred'
   - `transferred_at` = now()
5. Return success

---

#### Method 5: `handleTransferFailed($event)`
**Purpose:** Handle transfer failure webhook

**Logic:**
1. Get transfer ID from event
2. Find `Transfer` by `stripe_transfer_id`
3. Update `Transfer`:
   - `status` = 'failed'
   - `failure_reason` = error message from event
   - `stripe_data` = event data
4. Return success

---

## ⏰ Step 5: Create Cron Job

### 5.1 Check Payment Holds Command
**File:** `app/Console/Commands/CheckPaymentHolds.php`

**Steps:**
1. Run: `php artisan make:command CheckPaymentHolds`
2. Add logic:
   ```php
   public function handle()
   {
       $holds = PaymentHold::where('status', 'holding')
           ->where('hold_end_at', '<=', now())
           ->get();
       
       foreach ($holds as $hold) {
           // Mark as ready
           $hold->update([
               'status' => 'ready_for_transfer',
               'ready_at' => now(),
           ]);
           
           // Optionally: Auto create transfer
           if (config('stripe.auto_transfer_enabled')) {
               app(StripeService::class)->createTransfer($hold, 'cron');
           }
       }
       
       $this->info("Processed {$holds->count()} holds");
   }
   ```

---

### 5.2 Schedule Cron Job
**File:** `routes/console.php` or `app/Console/Kernel.php`

**Steps:**
1. Add schedule:
   ```php
   $schedule->command('check:payment-holds')
       ->daily()
       ->at('00:00');
   ```

---

## 🛣️ Step 6: Define Routes

### 6.1 API Routes
**File:** `routes/api.php`

**Steps:**
1. Add routes:

```php
// Stripe Connect Routes
Route::middleware('auth:sanctum')->group(function () {
    // User Routes
    Route::post('/stripe/connect/create', [StripeController::class, 'createConnectAccount'])
        ->name('stripe.connect.create');
    
    Route::post('/stripe/connect/onboarding-link', [StripeController::class, 'getOnboardingLink'])
        ->name('stripe.connect.onboarding-link');
    
    // Payment Routes
    Route::post('/stripe/payment-intent', [StripeController::class, 'createPaymentIntent'])
        ->name('stripe.payment-intent');
    
    // Admin Routes
    Route::middleware('admin')->group(function () {
        Route::post('/admin/transfer/{hold_id}', [AdminTransferController::class, 'transfer'])
            ->name('admin.transfer');
    });
});

// Webhook Route (NO AUTH - Stripe calls this)
Route::post('/stripe/webhook', [StripeController::class, 'handleWebhook'])
    ->name('stripe.webhook')
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
```

---

## ⚙️ Step 7: Configuration

### 7.1 Stripe Config
**File:** `config/services.php`

**Steps:**
1. Add Stripe configuration:
   ```php
   'stripe' => [
       'key' => env('STRIPE_KEY'),
       'secret' => env('STRIPE_SECRET'),
       'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
       'auto_transfer_enabled' => env('STRIPE_AUTO_TRANSFER', false),
   ],
   ```

---

### 7.2 Environment Variables
**File:** `.env`

**Steps:**
1. Add:
   ```
   STRIPE_KEY=pk_test_xxx
   STRIPE_SECRET=sk_test_xxx
   STRIPE_WEBHOOK_SECRET=whsec_xxx
   STRIPE_AUTO_TRANSFER=false
   ```

---

## 📦 Step 8: Install Stripe PHP SDK

**Steps:**
1. Run: `composer require stripe/stripe-php`
2. Initialize in service provider or config

---

## 📧 Step 9: Email Notifications (Queue Based)

### 9.1 Create Mail Classes

#### Mail Class 1: PaymentSuccessMail
**File:** `app/Mail/Stripe/PaymentSuccessMail.php`

**Steps:**
1. Run: `php artisan make:mail Stripe/PaymentSuccessMail`
2. Add properties:
   - `public Payment $payment`
   - `public PaymentHold $hold` (optional)
3. Build email content:
   - Subject: "Payment Successful - $amount Received"
   - View: `emails.stripe.payment-success`
   - Data: payment details, hold period info

**Purpose:** Notify user and admin when payment succeeds

---

#### Mail Class 2: HoldPeriodEndedMail
**File:** `app/Mail/Stripe/HoldPeriodEndedMail.php`

**Steps:**
1. Run: `php artisan make:mail Stripe/HoldPeriodEndedMail`
2. Add properties:
   - `public PaymentHold $hold`
   - `public User $user`
3. Build email content:
   - Subject: "Hold Period Ended - Amount Ready for Transfer"
   - View: `emails.stripe.hold-ended`
   - Data: hold details, amount, next steps

**Purpose:** Notify user and admin when hold period ends

---

#### Mail Class 3: TransferCompletedMail
**File:** `app/Mail/Stripe/TransferCompletedMail.php`

**Steps:**
1. Run: `php artisan make:mail Stripe/TransferCompletedMail`
2. Add properties:
   - `public Transfer $transfer`
   - `public User $user`
3. Build email content:
   - Subject: "Transfer Completed - $amount Transferred"
   - View: `emails.stripe.transfer-completed`
   - Data: transfer details, payout timeline

**Purpose:** Notify user and admin when transfer completes

---

#### Mail Class 4: PaymentFailedMail
**File:** `app/Mail/Stripe/PaymentFailedMail.php`

**Steps:**
1. Run: `php artisan make:mail Stripe/PaymentFailedMail`
2. Add properties:
   - `public Payment $payment`
   - `public string $reason`
3. Build email content:
   - Subject: "Payment Failed - Action Required"
   - View: `emails.stripe.payment-failed`
   - Data: failure reason, retry instructions

**Purpose:** Notify user and admin when payment fails

---

#### Mail Class 5: TransferFailedMail
**File:** `app/Mail/Stripe/TransferFailedMail.php`

**Steps:**
1. Run: `php artisan make:mail Stripe/TransferFailedMail`
2. Add properties:
   - `public Transfer $transfer`
   - `public string $reason`
3. Build email content:
   - Subject: "Transfer Failed - Investigation Required"
   - View: `emails.stripe.transfer-failed`
   - Data: failure reason, next steps

**Purpose:** Notify user and admin when transfer fails

---

### 9.2 Create Queue Jobs

#### Job 1: SendPaymentSuccessNotification
**File:** `app/Jobs/SendPaymentSuccessNotification.php`

**Steps:**
1. Run: `php artisan make:job SendPaymentSuccessNotification`
2. Add properties:
   - `public Payment $payment`
3. Handle method logic:
   ```php
   public function handle(): void
   {
       // Send to user
       Mail::to($this->payment->user->email)
           ->send(new PaymentSuccessMail($this->payment));
       
       // Send to admin
       Mail::to(config('mail.admin_email'))
           ->send(new PaymentSuccessMail($this->payment));
   }
   ```

**Purpose:** Queue job to send payment success emails

---

#### Job 2: SendHoldPeriodEndedNotification
**File:** `app/Jobs/SendHoldPeriodEndedNotification.php`

**Steps:**
1. Run: `php artisan make:job SendHoldPeriodEndedNotification`
2. Add properties:
   - `public PaymentHold $hold`
3. Handle method logic:
   ```php
   public function handle(): void
   {
       // Send to user
       Mail::to($this->hold->user->email)
           ->send(new HoldPeriodEndedMail($this->hold, $this->hold->user));
       
       // Send to admin
       Mail::to(config('mail.admin_email'))
           ->send(new HoldPeriodEndedMail($this->hold, $this->hold->user));
   }
   ```

**Purpose:** Queue job to send hold period ended emails

---

#### Job 3: SendTransferCompletedNotification
**File:** `app/Jobs/SendTransferCompletedNotification.php`

**Steps:**
1. Run: `php artisan make:job SendTransferCompletedNotification`
2. Add properties:
   - `public Transfer $transfer`
3. Handle method logic:
   ```php
   public function handle(): void
   {
       // Send to user
       Mail::to($this->transfer->user->email)
           ->send(new TransferCompletedMail($this->transfer, $this->transfer->user));
       
       // Send to admin
       Mail::to(config('mail.admin_email'))
           ->send(new TransferCompletedMail($this->transfer, $this->transfer->user));
   }
   ```

**Purpose:** Queue job to send transfer completed emails

---

#### Job 4: SendPaymentFailedNotification
**File:** `app/Jobs/SendPaymentFailedNotification.php`

**Steps:**
1. Run: `php artisan make:job SendPaymentFailedNotification`
2. Add properties:
   - `public Payment $payment`
   - `public string $reason`
3. Handle method logic:
   ```php
   public function handle(): void
   {
       // Send to user
       Mail::to($this->payment->user->email)
           ->send(new PaymentFailedMail($this->payment, $this->reason));
       
       // Send to admin
       Mail::to(config('mail.admin_email'))
           ->send(new PaymentFailedMail($this->payment, $this->reason));
   }
   ```

**Purpose:** Queue job to send payment failed emails

---

#### Job 5: SendTransferFailedNotification
**File:** `app/Jobs/SendTransferFailedNotification.php`

**Steps:**
1. Run: `php artisan make:job SendTransferFailedNotification`
2. Add properties:
   - `public Transfer $transfer`
   - `public string $reason`
3. Handle method logic:
   ```php
   public function handle(): void
   {
       // Send to user
       Mail::to($this->transfer->user->email)
           ->send(new TransferFailedMail($this->transfer, $this->reason));
       
       // Send to admin
       Mail::to(config('mail.admin_email'))
           ->send(new TransferFailedMail($this->transfer, $this->reason));
   }
   ```

**Purpose:** Queue job to send transfer failed emails

---

### 9.3 Update Services to Send Emails

#### Update WebhookService

**In `handlePaymentIntentSucceeded()` method:**
```php
// After creating payment hold
SendPaymentSuccessNotification::dispatch($payment);
```

**In `handlePaymentIntentFailed()` method:**
```php
// After updating payment status
SendPaymentFailedNotification::dispatch($payment, $failureReason);
```

**In `handleTransferCreated()` method:**
```php
// After updating transfer status
SendTransferCompletedNotification::dispatch($transfer);
```

**In `handleTransferFailed()` method:**
```php
// After updating transfer status
SendTransferFailedNotification::dispatch($transfer, $failureReason);
```

---

#### Update Cron Job Command

**In `CheckPaymentHolds` command:**
```php
public function handle()
{
    $holds = PaymentHold::where('status', 'holding')
        ->where('hold_end_at', '<=', now())
        ->get();
    
    foreach ($holds as $hold) {
        // Mark as ready
        $hold->update([
            'status' => 'ready_for_transfer',
            'ready_at' => now(),
        ]);
        
        // Send email notification
        SendHoldPeriodEndedNotification::dispatch($hold);
        
        // Optionally: Auto create transfer
        if (config('stripe.auto_transfer_enabled')) {
            app(StripeService::class)->createTransfer($hold, 'cron');
        }
    }
    
    $this->info("Processed {$holds->count()} holds");
}
```

---

### 9.4 Create Email Views

**Directory:** `resources/views/emails/stripe/`

**Views to Create:**
1. `payment-success.blade.php` - Payment success email
2. `hold-ended.blade.php` - Hold period ended email
3. `transfer-completed.blade.php` - Transfer completed email
4. `payment-failed.blade.php` - Payment failed email
5. `transfer-failed.blade.php` - Transfer failed email

**Template Structure:**
- Header with logo
- Main content area
- Payment/transfer details table
- Footer with support info

---

### 9.5 Add Admin Email Configuration

**File:** `config/mail.php` or `.env`

**Steps:**
1. Add admin email:
   ```php
   'admin_email' => env('ADMIN_EMAIL', 'admin@example.com'),
   ```
2. Add to `.env`:
   ```
   ADMIN_EMAIL=admin@example.com
   ```

---

### 9.6 Email Notification Summary

| Event | When | Send To | Via Queue |
|-------|------|---------|-----------|
| Payment Success | Payment webhook succeeds | User + Admin | ✅ Yes |
| Payment Failed | Payment webhook fails | User + Admin | ✅ Yes |
| Hold Period Ended | Cron job marks ready | User + Admin | ✅ Yes |
| Transfer Completed | Transfer webhook succeeds | User + Admin | ✅ Yes |
| Transfer Failed | Transfer webhook fails | User + Admin | ✅ Yes |

---

## 💡 Step 10: Email Notification Suggestions

### ✅ Kya Email Bhejna Chahiye (RECOMMENDED):

#### 1. **Payment Success** ✅
- **When:** Payment successfully completed
- **To:** User + Admin
- **Why:** Confirmation, receipt, record keeping
- **Content:** Amount, payment ID, hold period details

#### 2. **Payment Failed** ✅
- **When:** Payment fails (card declined, insufficient funds, etc.)
- **To:** User + Admin
- **Why:** User needs to retry, admin needs to monitor
- **Content:** Failure reason, retry instructions

#### 3. **Hold Period Ended** ✅
- **When:** Hold period expires (cron job detects)
- **To:** User + Admin
- **Why:** User knows amount ready, admin can trigger transfer
- **Content:** Amount, hold period, next steps

#### 4. **Transfer Completed** ✅
- **When:** Transfer successfully completed (webhook)
- **To:** User + Admin
- **Why:** User knows money transferred, admin confirmation
- **Content:** Transfer amount, payout timeline

#### 5. **Transfer Failed** ✅
- **When:** Transfer fails (Stripe error, account issue)
- **To:** User + Admin (CRITICAL for admin)
- **Why:** User needs info, admin needs immediate action
- **Content:** Failure reason, next steps, support contact

#### 6. **Account Status Changed** ✅ (Optional but Recommended)
- **When:** Stripe Connect account status changes
- **To:** User + Admin
- **Why:** Account verified/restricted notifications
- **Content:** New status, action required (if any)

---

### ❌ Kya Email Nahi Bhejna Chahiye:

#### 1. **Payment Intent Created** ❌
- **Why:** Payment not yet confirmed
- **Alternative:** Only send when payment succeeds

#### 2. **Every Webhook Event** ❌
- **Why:** Too many emails, most are internal
- **Alternative:** Only send user-facing events

#### 3. **Hold Created** ❌
- **Why:** Already covered in payment success email
- **Alternative:** Include hold details in payment success email

#### 4. **Cron Job Runs** ❌
- **Why:** Technical/internal event, not user-facing
- **Alternative:** Only email when hold period actually ends

#### 5. **Duplicate Webhooks** ❌
- **Why:** Internal processing, user doesn't need to know
- **Alternative:** Log internally, don't email

---

### 🎯 Best Practices:

#### 1. **Email Frequency:**
- ✅ Important events only (success, failure, status changes)
- ❌ Don't spam users with too many emails

#### 2. **Email Content:**
- ✅ Clear, concise, actionable
- ✅ Include relevant details (amount, dates, IDs)
- ✅ Include support contact info
- ❌ Don't include sensitive data (card numbers, etc.)

#### 3. **Queue Usage:**
- ✅ Always use queue for emails (performance)
- ✅ Don't block webhook processing for emails

#### 4. **User Preferences:**
- ✅ Respect user notification settings
- ✅ Check `UserNotificationSetting.email_alert` before sending
- ✅ Allow users to unsubscribe

#### 5. **Admin Notifications:**
- ✅ Send all critical events to admin
- ✅ Include more details for admin (debugging info)
- ✅ Consider separate admin email template

---

### 📧 Email Template Suggestions:

#### Template Structure:
```php
// Email Template Structure
1. Header (Logo, Company Name)
2. Greeting (User Name)
3. Main Message (Clear explanation)
4. Details Table (Amount, Date, ID, etc.)
5. Action Items (If any)
6. Support Contact
7. Footer (Unsubscribe, Privacy Policy)
```

#### Example: Payment Success Email
```
Subject: Payment Successful - $100.00 Received

Dear John Doe,

Your payment of $100.00 has been successfully processed.

Payment Details:
- Amount: $100.00
- Payment ID: pi_xxx
- Date: January 8, 2026
- Hold Period: 2 months (ends March 8, 2026)

Your amount will be held for 60 days and then transferred to your account.

If you have any questions, please contact support@example.com

Best regards,
Your App Team
```

---

### 🔔 Optional: Additional Notifications

#### 1. **SMS Notifications** (Future Enhancement)
- Send SMS for critical events
- Payment failed, transfer failed
- User preference based

#### 2. **Push Notifications** (If app has push)
- In-app notifications
- Real-time updates
- Less intrusive than email

#### 3. **Admin Dashboard Alerts**
- Real-time dashboard notifications
- Email + dashboard alerts for admin
- Better monitoring

---

## 🧪 Step 11: Testing Checklist

### 9.1 Test Scenarios

**Stripe Connect:**
- [ ] Create connect account
- [ ] Get onboarding link
- [ ] Handle account.updated webhook

**Payment:**
- [ ] Create payment intent with hold period
- [ ] Handle payment_intent.succeeded webhook
- [ ] Handle payment_intent.failed webhook
- [ ] Create payment hold after success

**Hold:**
- [ ] Cron job marks holds as ready
- [ ] Hold status updates correctly

**Transfer:**
- [ ] Admin manual transfer
- [ ] Cron automatic transfer
- [ ] Handle transfer.created webhook
- [ ] Handle transfer.failed webhook

**Webhook:**
- [ ] Webhook signature verification
- [ ] Duplicate webhook handling
- [ ] Failed webhook retry

---

## 📋 Implementation Order

1. **Step 1:** Create Models
2. **Step 2:** Create Form Requests
3. **Step 4:** Create Services (core logic)
4. **Step 3:** Create Controllers (use services)
5. **Step 6:** Define Routes
6. **Step 7:** Configuration
7. **Step 8:** Install Stripe SDK
8. **Step 5:** Create Cron Job
9. **Step 9:** Testing

---

## ✅ Final Checklist

- [ ] All 5 models created
- [ ] All Form Requests created
- [ ] All Controllers created
- [ ] All Services created
- [ ] Routes defined
- [ ] Stripe SDK installed
- [ ] Configuration added
- [ ] Email notifications implemented (5 Mail classes + 5 Queue jobs)
- [ ] Email views created
- [ ] Admin email configured
- [ ] Cron job scheduled (with email notifications)
- [ ] Webhook endpoint configured in Stripe Dashboard
- [ ] Queue worker running (`php artisan queue:work`)
- [ ] Tests written

---

**Note:** Yeh guide sirf structure aur logic batata hai. Actual implementation me Stripe API calls, error handling, logging, aur security measures add karne honge.
