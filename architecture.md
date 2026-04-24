# Time Vault - Architecture Document

> **Last updated:** 2026-04-24

---

## 1. Project Overview

**Time Vault** is a production-grade Laravel fintech web application that allows users to make payments, hold funds for configurable durations, and withdraw/transfer funds upon maturity. It includes a full-featured admin panel for operations management and integrates with **Stripe Payment Sheet** (native in-app payments via PaymentIntent + Customer + EphemeralKey) for payment processing, **Stripe Custom Connect** (silent account creation — no onboarding redirect) for user bank account linking, and **Stripe Transfer** for automated payouts.

---

## 2. Tech Stack

| Layer           | Technology                          |
|-----------------|-------------------------------------|
| **Backend**     | PHP 8.2+, Laravel 12.0             |
| **API Auth**    | Laravel Sanctum 4.0 (token-based)  |
| **Payments**    | Stripe PHP SDK 19.1, Payment Sheet, Custom Connect |
| **Database**    | MySQL (Eloquent ORM)               |
| **Frontend**    | Blade Templates, Tailwind CSS 4.0  |
| **Build Tool**  | Vite 7.0.7                         |
| **Queue**       | Database driver                    |
| **Session**     | Database driver                    |
| **Cache**       | Database driver                    |
| **Testing**     | PHPUnit 11.5                       |

### Frontend Dependencies (package.json)

| Package              | Version  |
|----------------------|----------|
| tailwindcss          | ^4.0.0   |
| @tailwindcss/vite    | ^4.0.0   |
| laravel-vite-plugin  | ^2.0.0   |
| vite                 | ^7.0.7   |
| axios                | ^1.11.0  |
| concurrently         | ^9.0.1   |

### Backend Dependencies (composer.json)

| Package              | Version  |
|----------------------|----------|
| laravel/framework    | ^12.0    |
| laravel/sanctum      | ^4.0     |
| laravel/tinker       | ^2.10.1  |
| stripe/stripe-php    | ^19.1    |

### Dev Dependencies

| Package              | Version  |
|----------------------|----------|
| fakerphp/faker       | ^1.23    |
| laravel/boost        | ^1.8     |
| laravel/pail         | ^1.2.2   |
| laravel/pint         | ^1.24    |
| laravel/sail         | ^1.41    |
| mockery/mockery      | ^1.6     |
| nunomaduro/collision  | ^8.6     |
| phpunit/phpunit      | ^11.5.3  |

---

## 3. Architecture Pattern

**Hybrid MVC + Service-Oriented Architecture**

```
HTTP Request
    |
    v
[Middleware] --> SecurityHeaders, SanitizeInput, LogApiRequests, AdminMiddleware
    |
    v
[Routes] --> api.php (REST API) / web.php (Admin Panel)
    |
    v
[Form Requests] --> Validation layer (24 request classes)
    |
    v
[Controllers] --> Thin controllers, delegate to services
    |
    v
[Services] --> PaymentSheetService, StripeService, PaymentHoldService, WebhookService
    |
    v
[Models] --> Eloquent ORM with encrypted casts + blind indexes (11 models)
    |
    v
[Jobs/Mail] --> Queued async notifications (8 jobs, 11 mailables)
    |
    v
[API Resources] --> JSON response formatting (4 resource classes)
```

### Exception Handling

- **404 (Non-API requests)**: Redirects to `admin.login` route (configured in `bootstrap/app.php`)
- **Health Check**: `GET /up` endpoint for monitoring

---

## 4. Directory Structure

```
time_vault_with_admin_panel/
|
+-- app/
|   +-- Console/Commands/          # Artisan CLI commands (cron tasks + utilities)
|   |   +-- CheckPaymentHolds.php
|   |   +-- CleanupUnverifiedAccounts.php
|   |   +-- FixHoldRemainingAmount.php
|   |   +-- FundTestBalance.php          # Test mode: add funds to Stripe available balance
|   |   +-- VerifyPendingTransfers.php
|   +-- Http/
|   |   +-- Controllers/
|   |   |   +-- Admin/             # Admin panel controllers (web, session-based)
|   |   |   |   +-- Auth/AdminLoginController.php
|   |   |   |   +-- DashboardController.php
|   |   |   |   +-- PaymentController.php
|   |   |   |   +-- PaymentHoldController.php
|   |   |   |   +-- PrivacyPolicyController.php
|   |   |   |   +-- ProfileController.php
|   |   |   |   +-- TransferController.php
|   |   |   |   +-- UserController.php
|   |   |   +-- Api/               # REST API controllers (Sanctum token-based)
|   |   |   |   +-- Admin/TransferController.php  # Admin transfer via API
|   |   |   |   +-- AppConfigController.php
|   |   |   |   +-- AuthController.php
|   |   |   |   +-- BankAccountController.php      # Bank details + silent Stripe Custom Connect
|   |   |   |   +-- DeviceController.php           (not routed)
|   |   |   |   +-- NotificationController.php
|   |   |   |   +-- PaymentHoldController.php
|   |   |   |   +-- PaymentSheetController.php     # Payment Sheet (create-intent, confirm)
|   |   |   |   +-- PrivacyPolicyController.php
|   |   |   |   +-- ProfileController.php
|   |   |   |   +-- StripeController.php           # Webhooks only
|   |   |   |   +-- TransactionHistoryController.php
|   |   +-- Middleware/            # Security, auth, logging, sanitization
|   |   |   +-- AdminMiddleware.php
|   |   |   +-- LogApiRequests.php      (disabled)
|   |   |   +-- SanitizeInput.php       (disabled)
|   |   |   +-- SecurityHeaders.php     (disabled)
|   |   +-- Requests/             # Form request validation classes (24 total)
|   |   |   +-- Admin/TransferRequest.php
|   |   |   +-- Auth/ (7 files: Login, Signup, VerifyOtp, ResendOtp, ForgotPassword, ResetPassword, ChangePassword)
|   |   |   +-- Bank/ (StoreBankAccountRequest)  # Bank details validation
|   |   |   +-- Device/ (RegisterDeviceRequest, UpdateDeviceTokenRequest)  (not routed)
|   |   |   +-- Notification/UpdateNotificationSettingsRequest.php
|   |   |   +-- Payment/ (CreatePaymentIntentRequest, ConfirmPaymentRequest)  # Payment Sheet
|   |   |   +-- PrivacyPolicy/ (Store, Update)
|   |   |   +-- Profile/ (UpdateProfile, UpdateLanguage, UpdateTimezone)
|   |   |   +-- Stripe/ (CreateConnectAccount, GetOnboardingLink, CreatePaymentIntent, TestPayment, WithdrawPayout)
|   |   +-- Resources/            # API response resource formatters
|   |       +-- NotificationSettingsResource.php
|   |       +-- PrivacyPolicyResource.php
|   |       +-- TransactionResource.php
|   |       +-- UserResource.php
|   +-- Jobs/                     # Queued background jobs (8 notification jobs)
|   |   +-- SendHoldPeriodEndedNotification.php
|   |   +-- SendPaymentFailedNotification.php
|   |   +-- SendPaymentSuccessNotification.php
|   |   +-- SendPayoutRequestNotification.php
|   |   +-- SendTransferCompletedNotification.php
|   |   +-- SendTransferCompletedSummaryNotification.php
|   |   +-- SendTransferFailedNotification.php
|   |   +-- SendWithdrawalSummaryNotification.php
|   +-- Mail/                     # Mailable classes + Blade email templates
|   |   +-- OtpMail.php
|   |   +-- AccountDeletionConfirmationMail.php
|   |   +-- AdminAccountDeletionNotificationMail.php
|   |   +-- Stripe/               # Stripe-related email templates (8 mailables)
|   |       +-- HoldPeriodEndedMail.php
|   |       +-- PaymentFailedMail.php
|   |       +-- PaymentSuccessMail.php
|   |       +-- PayoutRequestMail.php
|   |       +-- TransferCompletedMail.php
|   |       +-- TransferCompletedSummaryMail.php
|   |       +-- TransferFailedMail.php
|   |       +-- WithdrawalSummaryMail.php
|   +-- Models/                   # Eloquent models (11 models)
|   |   +-- PasswordResetToken.php       (empty placeholder)
|   |   +-- Payment.php                  # + card detail fields (brand, last4, exp, funding, country, method)
|   |   +-- PaymentHold.php
|   |   +-- PrivacyPolicy.php
|   |   +-- StripeConnectAccount.php     # Stripe Custom Connect (silent, no onboarding)
|   |   +-- StripeCustomer.php           # Stripe Customer for Payment Sheet (encrypted + blind index)
|   |   +-- StripeWebhookEvent.php
|   |   +-- Transfer.php
|   |   +-- User.php
|   |   +-- UserBankAccount.php          # User bank details (encrypted + blind index)
|   |   +-- UserNotificationSetting.php
|   +-- Services/                 # Business logic layer (4 services)
|   |   +-- PaymentHoldService.php
|   |   +-- PaymentSheetService.php      # Payment Sheet (Customer + EphemeralKey + PaymentIntent + confirm)
|   |   +-- StripeService.php
|   |   +-- WebhookService.php
|   +-- Providers/                # Service providers
|       +-- AppServiceProvider.php
|
+-- bootstrap/
|   +-- app.php                   # Application bootstrap, middleware config, 404 handler
|
+-- config/                       # Laravel configuration files (11 files)
|   +-- app.php, auth.php, cache.php, database.php, filesystems.php,
|   +-- logging.php, mail.php, queue.php, sanctum.php, services.php, session.php
|
+-- database/
|   +-- migrations/               # Schema migrations (24 migrations including Payment Sheet + Bank Accounts)
|   +-- factories/                # Model factories (User, PrivacyPolicy)
|   +-- seeders/                  # Database seeders (Admin, PrivacyPolicy, Database)
|
+-- routes/
|   +-- api.php                   # REST API routes (35 endpoints)
|   +-- web.php                   # Admin panel routes
|   +-- console.php               # Scheduled command definitions (3 cron jobs)
|
+-- resources/
|   +-- views/
|   |   +-- admin/                # Admin panel Blade views (9 files)
|   |   |   +-- auth/login.blade.php
|   |   |   +-- dashboard.blade.php
|   |   |   +-- payment-holds/index.blade.php
|   |   |   +-- payments/index.blade.php
|   |   |   +-- privacy-policy/index.blade.php
|   |   |   +-- profile.blade.php
|   |   |   +-- transfers/index.blade.php
|   |   |   +-- users/ (index, show)
|   |   +-- emails/               # Email templates (11 templates)
|   |   |   +-- otp.blade.php
|   |   |   +-- account-deletion-confirmation.blade.php
|   |   |   +-- admin-account-deletion-notification.blade.php
|   |   |   +-- stripe/ (8 templates: payment-success, payment-failed, hold-ended,
|   |   |   |          transfer-completed, transfer-completed-summary,
|   |   |   |          transfer-failed, payout-request, withdrawal-summary)
|   |   +-- layouts/              # Admin layout template
|   |   |   +-- admin.blade.php
|   |   |   +-- partials/ (footer, footer_script, header, header_script, sidebar)
|   |   +-- home.blade.php
|   |   +-- welcome.blade.php
|   +-- css/app.css               # Tailwind CSS entry
|   +-- js/app.js                 # JS entry (Axios)
|
+-- public/                       # Web root
|   +-- index.php                 # Entry point
|   +-- admin/                    # Admin panel static assets
|       +-- css/                  # Bootstrap, custom CSS, pace, icons
|       +-- js/                   # jQuery, Bootstrap bundle, pace, app.js
|       +-- fonts/                # Boxicons, LineIcons
|       +-- images/               # Admin images
|       +-- plugins/              # metismenu, simplebar, perfect-scrollbar, vectormap
|
+-- tests/                        # PHPUnit test suite
|   +-- TestCase.php
|   +-- Feature/
|   |   +-- ExampleTest.php
|   |   +-- AppConfigTest.php
|   |   +-- Auth/ChangePasswordTest.php
|   |   +-- SendTransferCompletedNotificationTest.php
|   +-- Unit/
|       +-- ExampleTest.php
|
+-- storage/                      # Logs, cache, compiled views
+-- vendor/                       # Composer dependencies
```

---

## 5. Data Models & Relationships

### Entity Relationship Diagram

```
User (1)----(*)  PaymentHold
User (1)----(*)  Transfer
User (1)----(*)  Payment
User (1)----(1)  StripeConnectAccount    # Created silently when user adds bank details
User (1)----(1)  StripeCustomer          # Payment Sheet customer
User (1)----(*)  UserBankAccount         # User's bank details for withdrawals (multiple allowed)
User (1)----(1)  UserNotificationSetting

Payment (1)----(1) PaymentHold

PaymentHold (1)----(1) Transfer (via hold_id FK)
```

### Core Models - Detailed Schema

#### User (`users` table)

| Field               | Type           | Cast              | Notes                          |
|---------------------|----------------|-------------------|--------------------------------|
| role                | string         | -                 | 'user' or 'admin'             |
| full_name           | text           | encrypted         | PII                            |
| email               | text           | encrypted         | PII, unique via blind index    |
| email_index         | string         | hidden            | HMAC-SHA256 blind index        |
| phone               | text           | encrypted         | PII, unique via blind index    |
| phone_index         | string         | hidden            | HMAC-SHA256 blind index        |
| password            | string         | hashed            |                                |
| profile             | string         | -                 | Profile image path             |
| otp_code            | text           | encrypted         |                                |
| otp_expires_at      | datetime       | datetime          | 15-minute OTP expiry           |
| is_verified         | boolean        | boolean           | OTP verification status        |
| status              | string         | -                 | active, deleted                |
| two_factor_enabled  | boolean        | boolean           |                                |
| provider            | string         | -                 | google, apple, facebook        |
| provider_id         | text           | encrypted         | Social login ID                |
| provider_id_index   | string         | hidden            | HMAC-SHA256 blind index        |
| timezone            | string         | -                 | User timezone preference       |
| language            | string         | -                 | Language preference             |
| fcm_token           | text           | encrypted         | Firebase Cloud Messaging token |
| device_id           | text           | encrypted         | Device identifier              |
| device_type         | string         | -                 | ios, android                   |
| token               | text           | encrypted         |                                |
| expires_at          | datetime       | datetime          |                                |
| last_active_at      | datetime       | datetime          |                                |
| deleted_at          | datetime       | datetime          | Soft delete                    |

**Relationships:** `notificationSettings()` hasOne, `paymentHolds()` hasMany, `transfers()` hasMany, `stripeCustomer()` hasOne, `bankAccounts()` hasMany, `primaryBankAccount()` hasOne (filtered by is_primary)

**Auto Blind Indexing:** `booted()` method auto-syncs blind indexes for email, phone, provider_id on model save.

---

#### Payment (`payments` table)

| Field                    | Type      | Cast              | Notes                        |
|--------------------------|-----------|-------------------|------------------------------|
| user_id                  | FK        | -                 | References users             |
| payment_intent_id        | text      | encrypted         | Stripe PaymentIntent ID      |
| payment_intent_id_index  | string    | hidden            | HMAC-SHA256 blind index      |
| amount                   | decimal   | decimal:2         |                              |
| currency                 | string    | -                 | USD, EUR, GBP               |
| status                   | string    | string            | pending, succeeded, failed, canceled |
| paid_at                  | datetime  | datetime          |                              |
| stripe_data              | text      | encrypted:array   | Full Stripe response data    |
| failure_reason           | text      | -                 |                              |
| card_brand               | string    | -                 | visa, mastercard, amex, etc. |
| card_last4               | string(4) | -                 | Last 4 digits of card        |
| card_exp_month           | tinyint   | integer           | Card expiry month            |
| card_exp_year            | smallint  | integer           | Card expiry year             |
| card_funding             | string    | -                 | credit, debit, prepaid       |
| card_country             | string(2) | -                 | Card issuing country (US, GB)|
| payment_method_type      | string    | -                 | card, apple_pay, google_pay  |

**Relationships:** `user()` belongsTo, `hold()` hasOne(PaymentHold)

**Auto Blind Indexing:** payment_intent_id synced on save.

---

#### PaymentHold (`payment_holds` table)

| Field              | Type      | Cast        | Notes                                          |
|--------------------|-----------|-------------|-------------------------------------------------|
| payment_id         | FK        | -           | References payments                             |
| user_id            | FK        | -           | References users                                |
| title              | string    | -           | User-defined hold title                         |
| amount             | decimal   | decimal:2   | Original hold amount                            |
| remaining_amount   | decimal   | decimal:2   | Amount not yet transferred                      |
| hold_start_at      | datetime  | datetime    |                                                 |
| hold_end_at        | datetime  | datetime    | When hold period expires                        |
| hold_days          | integer   | integer     | Duration in days                                |
| hold_period_type   | string    | -           | 1_month, 2_months, 6_months, 1_year, custom    |
| status             | string    | string      | holding, ready_for_transfer, transferred, partial_transferred, canceled |
| ready_at           | datetime  | datetime    | When marked ready for transfer                  |
| transferred_at     | datetime  | datetime    | When fully transferred                          |
| abandoned_at       | datetime  | datetime    | When abandoned (account deletion)               |

**Relationships:** `payment()` belongsTo, `user()` belongsTo, `transfer()` hasOne(Transfer, 'hold_id')

**Scopes:** `abandoned()`, `notAbandoned()`

**Accessor:** `is_abandoned` (boolean via abandoned_at)

---

#### Transfer (`transfers` table)

| Field                        | Type      | Cast              | Notes                          |
|------------------------------|-----------|-------------------|--------------------------------|
| hold_id                      | FK        | -                 | References payment_holds       |
| user_id                      | FK        | -                 | References users               |
| stripe_transfer_id           | text      | encrypted         | Stripe Transfer ID             |
| stripe_transfer_id_index     | string    | hidden            | HMAC-SHA256 blind index        |
| stripe_connect_account_id    | text      | encrypted         | Destination Connect account    |
| amount                       | decimal   | decimal:2         |                                |
| currency                     | string    | -                 |                                |
| status                       | string    | string            | pending, completed, failed, canceled |
| transferred_at               | datetime  | datetime          |                                |
| failure_reason               | text      | -                 |                                |
| stripe_data                  | text      | encrypted:array   | Full Stripe response data      |
| admin_id                     | FK        | -                 | Admin who executed transfer    |
| transfer_type                | string    | -                 | manual, auto, payout, account_deletion_forfeited |
| abandoned_at                 | datetime  | datetime          | When abandoned                 |
| email_sent_at                | datetime  | datetime          | Email notification tracking    |
| email_status                 | string    | -                 | Email delivery status          |
| email_failure_reason         | text      | -                 | Email failure details          |

**Relationships:** `hold()` belongsTo(PaymentHold, 'hold_id'), `user()` belongsTo, `admin()` belongsTo(User, 'admin_id')

**Scopes:** `abandoned()`, `notAbandoned()`, `forfeited()` (where transfer_type = 'account_deletion_forfeited')

**Accessor:** `is_abandoned` (boolean via abandoned_at)

---

#### StripeConnectAccount (`stripe_connect_accounts` table)

| Field                     | Type      | Cast              | Notes                      |
|---------------------------|-----------|-------------------|----------------------------|
| user_id                   | FK        | -                 | References users           |
| connect_account_id        | text      | encrypted         | Stripe Connect account ID  |
| connect_account_id_index  | string    | hidden            | HMAC-SHA256 blind index    |
| status                    | string    | -                 | pending, verified, restricted |
| payouts_enabled           | boolean   | boolean           |                            |
| onboarding_url            | text      | encrypted         | Cached onboarding URL      |
| stripe_data               | text      | encrypted:array   | Full Stripe account data   |
| verified_at               | datetime  | datetime          | First verification date    |

**Relationships:** `user()` belongsTo

**Auto Blind Indexing:** connect_account_id synced on save.

---

#### StripeCustomer (`stripe_customers` table)

| Field                     | Type      | Cast              | Notes                      |
|---------------------------|-----------|-------------------|----------------------------|
| user_id                   | FK        | -                 | References users (unique)  |
| stripe_customer_id        | text      | encrypted         | Stripe Customer ID         |
| stripe_customer_id_index  | string    | hidden            | HMAC-SHA256 blind index    |
| stripe_data               | text      | encrypted:array   | Full Stripe customer data  |

**Relationships:** `user()` belongsTo

**Auto Blind Indexing:** stripe_customer_id synced on save.

> **Purpose:** Required by Stripe Payment Sheet SDK. Created once per user (first payment). Enables saved cards, Apple Pay, Google Pay.

---

#### UserBankAccount (`user_bank_accounts` table)

| Field                        | Type      | Cast              | Notes                            |
|------------------------------|-----------|-------------------|----------------------------------|
| user_id                      | FK        | -                 | References users (unique)        |
| account_holder_name          | text      | encrypted         | Name on bank account             |
| bank_name                    | text      | encrypted         | Bank name (HBL, Chase etc.)      |
| account_number               | text      | encrypted         | Full account number              |
| account_number_index         | string    | hidden            | HMAC-SHA256 blind index          |
| routing_number               | text      | encrypted         | US routing number (nullable)     |
| iban                         | text      | encrypted         | International bank number (nullable) |
| swift_code                   | string    | -                 | SWIFT/BIC code (nullable)        |
| account_type                 | enum      | -                 | savings, checking, current       |
| country                      | string(2) | -                 | ISO country code (US, PK etc.)   |
| currency                     | string(3) | -                 | Currency code (usd, pkr etc.)    |
| stripe_bank_account_id       | text      | encrypted         | Stripe external bank ID (ba_xxx) |
| stripe_bank_account_id_index | string    | hidden            | HMAC-SHA256 blind index          |
| dob                          | text      | encrypted         | Date of birth (for Connect KYC)  |
| is_primary                   | boolean   | boolean           | Default withdrawal account       |

**Relationships:** `user()` belongsTo

**Auto Blind Indexing:** account_number, stripe_bank_account_id synced on save.

**Accessor:** `masked_account_number` → returns "****7890" (last 4 digits only)

> **Purpose:** Stores user's bank details for withdrawals. When first bank is saved, backend silently creates a Stripe Custom Connect account (no onboarding redirect) and adds the bank as an external account. Multiple bank accounts per user supported (no limit). First bank auto-set as primary. Stripe does not support updating bank details — user must delete and add new.

---

#### UserNotificationSetting (`user_notification_settings` table)

| Field                       | Type    | Cast    | Notes          |
|-----------------------------|---------|---------|----------------|
| user_id                     | FK      | -       | References users |
| password_alert              | boolean | boolean | Default: true  |
| transaction_alert           | boolean | boolean | Default: true  |
| push_notification_alert     | boolean | boolean | Default: true  |
| email_alert                 | boolean | boolean | Default: true  |
| lock_alert                  | boolean | boolean | Hold lock alerts |
| unlock_alert                | boolean | boolean | Hold unlock alerts |

**Relationships:** `user()` belongsTo

---

#### StripeWebhookEvent (`stripe_webhook_events` table)

| Field           | Type      | Cast      | Notes                        |
|-----------------|-----------|-----------|------------------------------|
| stripe_event_id | string    | -         | Stripe event ID (idempotency)|
| event_type      | string    | -         | Stripe event type            |
| status          | string    | string    | processed, failed            |
| payload         | text      | array     | Raw webhook payload          |
| processed_at    | datetime  | datetime  |                              |
| error_message   | text      | -         |                              |
| retry_count     | integer   | integer   |                              |
| last_retry_at   | datetime  | datetime  |                              |

---

#### PrivacyPolicy (`privacy_policies` table)

| Field          | Type     | Cast     | Notes          |
|----------------|----------|----------|----------------|
| title          | string   | -        |                |
| content        | text     | -        | HTML content   |
| is_active      | boolean  | boolean  |                |
| effective_date | datetime | datetime |                |

---

#### PasswordResetToken (`password_reset_tokens` table)

| Field      | Type     | Notes           |
|------------|----------|-----------------|
| email      | string   | Indexed         |
| token      | string   | Hashed          |
| created_at | datetime |                 |

> **Note:** Model file is an empty placeholder - uses default Laravel behavior.

---

## 6. Authentication & Authorization

### Dual Auth System

| Context         | Method                | Middleware            | Details                                     |
|-----------------|----------------------|----------------------|---------------------------------------------|
| **Mobile API**  | Sanctum Token        | `auth:sanctum`       | Bearer token in Authorization header        |
| **Admin Panel** | Session-based        | `admin` (custom)     | Checks `Auth::check()` + `role === 'admin'` |

### Security Features

- **OTP Verification**: 4-digit code, 15-minute expiry, rate-limited resend (3/min)
- **Social Login**: Google, Apple, Facebook via provider_id with blind-indexed lookups
- **Blind Indexing**: HMAC-SHA256 indexes on encrypted fields for secure lookups
  - `users.email_index`, `users.phone_index`, `users.provider_id_index`
  - `payments.payment_intent_id_index`
  - `transfers.stripe_transfer_id_index`
  - `stripe_connect_accounts.connect_account_id_index`
- **Rate Limiting**: Per-endpoint throttling (3-10 req/min on auth, 1000 req/min general, 5/hour on delete-account)

---

## 7. API Endpoints

### Public Routes (No Auth)

| Method | Endpoint                       | Description              | Rate Limit |
|--------|-------------------------------|--------------------------|------------|
| POST   | `/api/auth/check-email`       | Check email existence    | 10/min     |
| POST   | `/api/auth/signup`            | Register + send OTP      | 5/min      |
| POST   | `/api/auth/verify-otp`        | Verify OTP code          | 5/min      |
| POST   | `/api/auth/resend-otp`        | Resend OTP               | 3/min      |
| POST   | `/api/auth/login`             | Login (email or social)  | 5/min      |
| POST   | `/api/auth/forgot-password`   | Request password reset   | 5/min      |
| POST   | `/api/auth/reset-password`    | Confirm password reset   | 5/min      |
| GET    | `/api/privacy-policy`         | Get active policy        | 60/min     |

### Protected Routes (Sanctum Auth, 1000/min)

| Method | Endpoint                                    | Description                    |
|--------|---------------------------------------------|--------------------------------|
| POST   | `/api/auth/logout`                          | Logout user                    |
| POST   | `/api/auth/delete-account`                  | Delete account (5/hour)        |
| GET    | `/api/profile`                              | Get user profile               |
| POST   | `/api/profile/update`                       | Update profile (file upload)   |
| POST   | `/api/profile/change-password`              | Change password (5/min)        |
| POST   | `/api/profile/language`                     | Set language preference        |
| POST   | `/api/profile/timezone`                     | Set timezone                   |
| GET    | `/api/notification-settings`                | Get notification settings      |
| POST   | `/api/notification-settings/update`         | Update notification settings   |
| GET    | `/api/refer-friend-url`                     | Get referral link              |
| POST   | `/api/stripe/create-payment-intent`         | Create PaymentIntent for Payment Sheet (returns client_secret, customer_id, ephemeral_key, publishable_key) |
| POST   | `/api/stripe/confirm-payment`               | Confirm payment after Payment Sheet succeeds (creates Payment + PaymentHold) |
| POST   | `/api/bank-account`                         | Add bank account + silent Stripe Custom Connect (multiple allowed) |
| GET    | `/api/bank-account`                         | List all user's bank accounts (masked) |
| DELETE | `/api/bank-account/{id}`                    | Remove specific bank account from Stripe + DB |
| POST   | `/api/bank-account/{id}/set-primary`        | Set a bank account as primary for withdrawals |
| GET    | `/api/payment-holds`                        | List user holds (paginated)    |
| GET    | `/api/payment-holds/summary`                | Hold summary statistics        |
| POST   | `/api/payment-holds/{id}/request-payout`    | Request payout for a hold      |
| POST   | `/api/payment-holds/withdraw`               | Withdraw funds                 |
| GET    | `/api/transactions/all`                     | All transactions               |
| GET    | `/api/transactions/checkouts`               | Checkout history               |
| GET    | `/api/transactions/withdraws`               | Withdrawal history             |
| GET    | `/api/transactions/hold-amounts`            | Held amounts                   |
| GET    | `/api/transactions/ready-for-transfer`      | Ready for transfer             |
| POST   | `/api/admin/transfer/{hold_id}`             | Execute transfer (admin)       |

### Stripe Webhook Route (No Auth, No CSRF)

| Method | Endpoint                        | Description                    |
|--------|---------------------------------|--------------------------------|
| POST   | `/api/stripe/webhook`           | Stripe webhook receiver        |

### System Routes

| Method | Endpoint | Description        |
|--------|----------|--------------------|
| GET    | `/up`    | Health check       |
| GET    | `/`      | Welcome page       |

---

## 8. Admin Panel

### Routes (Session Auth + Admin Middleware)

| Method | Endpoint                              | Description                  |
|--------|---------------------------------------|------------------------------|
| GET    | `/admin/login`                        | Login form                   |
| POST   | `/admin/login`                        | Authenticate                 |
| POST   | `/admin/logout`                       | Logout                       |
| GET    | `/admin/dashboard`                    | Dashboard with real-time stats|
| GET    | `/admin/dashboard/stats`              | AJAX stats endpoint          |
| GET    | `/admin/users`                        | User list                    |
| GET    | `/admin/users/{user}`                 | User detail                  |
| GET    | `/admin/users/stats`                  | User statistics              |
| GET    | `/admin/payments`                     | Payment list                 |
| GET    | `/admin/payments/stats`               | Payment statistics           |
| GET    | `/admin/payment-holds`                | Hold list                    |
| GET    | `/admin/payment-holds/stats`          | Hold statistics              |
| GET    | `/admin/transfers`                    | Transfer list                |
| GET    | `/admin/transfers/stats`              | Transfer statistics          |
| POST   | `/admin/transfers/{hold}/execute`     | Execute a transfer           |
| GET    | `/admin/privacy-policy`               | Manage privacy policy        |
| POST   | `/admin/privacy-policy`               | Create/update policy         |
| GET    | `/admin/profile`                      | Admin profile                |
| POST   | `/admin/profile/update`               | Update admin profile         |
| POST   | `/admin/profile/change-password`      | Change admin password        |

### Dashboard Metrics

- Total active users / new users today
- Total revenue (successful payments)
- Hold counts & amounts by status (holding, ready, transferred)
- Pending transfers count
- Recent transaction activity

### Admin Panel Frontend Stack

- **Layout**: Blade template (`layouts/admin.blade.php`) with partials (header, sidebar, footer)
- **CSS**: Bootstrap (minified) + custom `bootstrap-extended.css` + pace + icons (Boxicons, LineIcons)
- **JS**: jQuery + Bootstrap bundle + metismenu + simplebar + perfect-scrollbar + vectormap + custom `app.js`

---

## 9. Stripe Integration (Payment Sheet + Custom Connect)

### Payment Flow

```
1. Flutter calls backend to create PaymentIntent
   POST /api/stripe/create-payment-intent
       |
       v
   Backend creates: Stripe Customer (first time) + EphemeralKey + PaymentIntent
   Returns: client_secret, customer_id, ephemeral_key, publishable_key
       |
       v
2. Flutter shows native Payment Sheet (in-app, no browser redirect)
   Stripe.instance.initPaymentSheet(clientSecret, customerId, ephemeralKey)
   Stripe.instance.presentPaymentSheet()
   Supports: Card, Apple Pay, Google Pay, Saved Cards
       |
       v
3. Payment confirmed by Flutter calling backend
   POST /api/stripe/confirm-payment { payment_intent_id: "pi_xxx" }
   Backend: Retrieves PaymentIntent → verifies succeeded → creates Payment + PaymentHold
       |
       v
4. Hold period elapses (checked by cron: check:payment-holds every 5min)
   Status: holding --> ready_for_transfer
       |
       v
4.5 User adds bank details (one-time setup before first withdrawal)
    POST /api/bank-account { dob, account_number, bank_name, country, currency }
    Backend silently: Creates Stripe Custom Connect account + adds external bank
    No redirect, no onboarding page — user stays in app
       |
       v
5. User requests withdrawal (or admin executes transfer)
   POST /api/payment-holds/withdraw --> Stripe Transfer to Connect account
   POST /api/payment-holds/{id}/request-payout
       |
       v
6. Transfer status verified (cron: verify:pending-transfers every 5min)
   Webhook: transfer.created / transfer.updated --> Updates Transfer status
```

### Stripe Webhook Events Handled

| Event                           | Handler Method                    | Action                                    |
|---------------------------------|-----------------------------------|-------------------------------------------|
| `checkout.session.completed`    | handleCheckoutSessionCompleted    | Create Payment + PaymentHold              |
| `payment_intent.succeeded`      | handlePaymentIntentSucceeded      | Update Payment status, create hold        |
| `payment_intent.payment_failed` | handlePaymentIntentFailed         | Mark Payment failed + notify              |
| `payment_intent.canceled`       | handlePaymentIntentCanceled       | Cancel Payment + PaymentHold              |
| `account.updated`               | handleAccountUpdated              | Update Connect account status             |
| `transfer.created`              | handleTransferCreated             | Mark Transfer completed + notify          |
| `transfer.failed`               | handleTransferFailed              | Mark Transfer failed + notify (future)    |
| `transfer.canceled`             | handleTransferCanceled            | Cancel Transfer, revert hold (future)     |

> **Note:** `transfer.created`, `transfer.failed`, and `transfer.canceled` webhook handlers exist but transfers are primarily handled by the `verify:pending-transfers` cron job.

### Bank Account & Custom Connect Flow (No Onboarding)

```
1. User fills bank details in app (DOB, account number, bank name)
   POST /api/bank-account
       |
       v
2. Backend creates Stripe Custom Connect account (silent, no redirect)
   \Stripe\Account::create(['type' => 'custom', ...])
   Returns: acct_xxxxxxxxxxxx
       |
       v
3. Backend adds bank as external account on Stripe
   \Stripe\Account::createExternalAccount(acct_xxx, bank_details)
   Returns: ba_xxxxxxxxxxxx
       |
       v
4. Records saved to DB:
   stripe_connect_accounts: acct_xxx (encrypted)
   user_bank_accounts: bank details (encrypted)
       |
       v
5. User can now withdraw — existing withdraw API works as-is
   POST /api/payment-holds/withdraw
   Uses Connect account (acct_xxx) for Stripe Transfer destination
```

**Bank Account Operations:**
- **Add**: First bank creates Connect account + external bank. Additional banks add to same Connect account.
- **Set Primary**: Marks one bank as default for withdrawals (only one primary at a time)
- **Delete**: Removes bank from Stripe. If deleted bank was primary, next bank auto-promoted.
- **List**: Returns all banks with masked account numbers (****7890), never full number

### Supported Currencies

USD, EUR, GBP

### Hold Period Options

`custom` (user specifies hold_start_at and hold_end_at)

---

## 10. Services Layer

### PaymentSheetService (Primary Payment Service)

| Method                                    | Description                                                              |
|-------------------------------------------|--------------------------------------------------------------------------|
| `getOrCreateCustomer(User)`               | Gets existing or creates new Stripe Customer (one per user, encrypted)   |
| `createEphemeralKey(string $customerId)`   | Creates short-lived EphemeralKey for Payment Sheet SDK                   |
| `createPaymentIntent(User, array $data)`  | Creates Customer + EphemeralKey + PaymentIntent, returns 4 values for Flutter |
| `confirmPayment(string $piId, User)`      | Retrieves PaymentIntent, verifies succeeded, extracts card details, creates Payment + PaymentHold, dispatches notification |

### StripeService

| Method                              | Description                                                              |
|-------------------------------------|--------------------------------------------------------------------------|
| `createConnectAccount(User, data)`  | Creates Stripe Custom Connect account silently (no onboarding redirect)  |
| `getOnboardingLink(StripeConnectAccount)` | Generates Stripe AccountLink URL, caches for 24h (legacy, not used with Custom) |
| `createTransfer(PaymentHold, type)` | Executes Stripe Transfer to Connect account, handles partial transfers   |
| `getHoldPeriodData(paymentIntentId)` | Retrieves hold data from cache or Stripe metadata                       |

### PaymentHoldService

| Method                              | Description                                                              |
|-------------------------------------|--------------------------------------------------------------------------|
| `createFromPayment(Payment, data)`  | Creates PaymentHold from Payment with hold period configuration          |
| `checkAndMarkReady()`               | Marks expired holds as `ready_for_transfer`, returns count               |

### WebhookService

| Method                              | Description                                                              |
|-------------------------------------|--------------------------------------------------------------------------|
| `handleCheckoutSessionCompleted()`  | Processes successful checkout, creates Payment + Hold, dispatches notification |
| `handlePaymentIntentSucceeded()`    | Updates Payment status, creates hold if missing                          |
| `handlePaymentIntentFailed()`       | Marks Payment failed, dispatches failure notification                    |
| `handlePaymentIntentCanceled()`     | Cancels Payment + Hold, dispatches notification                          |
| `handleAccountUpdated()`            | Updates Connect account status (pending/verified/restricted)             |
| `handleTransferCreated()`           | Marks Transfer completed, batches notifications (2-min window)           |
| `handleTransferFailed()`            | Marks Transfer failed, dispatches notification                           |
| `handleTransferCanceled()`          | Cancels Transfer, reverts hold to ready_for_transfer                     |

**Dependencies:** WebhookService injects PaymentHoldService and StripeService.

**Transfer Batching:** When multiple transfers complete within 2 minutes, sends a summary notification instead of individual ones.

---

## 11. Background Jobs & Scheduling

### Queued Jobs (Database Driver)

| Job                                         | Trigger                        | Action                        |
|---------------------------------------------|--------------------------------|-------------------------------|
| SendPaymentSuccessNotification              | Payment succeeds               | Email + push notification     |
| SendPaymentFailedNotification               | Payment fails / canceled       | Email + push notification     |
| SendHoldPeriodEndedNotification             | Hold period expires            | Email + push notification     |
| SendTransferCompletedNotification           | Single transfer completes      | Email + push notification     |
| SendTransferCompletedSummaryNotification    | Batch transfers complete       | Summary email + push          |
| SendTransferFailedNotification              | Transfer fails                 | Email + push notification     |
| SendPayoutRequestNotification               | User requests payout           | Email + push notification     |
| SendWithdrawalSummaryNotification           | Withdrawal summary             | Email + push notification     |

### Scheduled Commands (Cron via `console.php`)

| Command                          | Schedule            | Options              | Purpose                                              |
|----------------------------------|---------------------|----------------------|------------------------------------------------------|
| `check:payment-holds`            | Every 5 minutes     | withoutOverlapping   | Mark expired holds as `ready_for_transfer`           |
| `verify:pending-transfers`       | Every 5 minutes     | withoutOverlapping   | Check Stripe for pending transfer status updates     |
| `users:cleanup-unverified`       | Daily at 00:00 ET   | withoutOverlapping   | Remove accounts that never completed OTP verification|

### One-Time / Maintenance Commands

| Command                          | Purpose                                              |
|----------------------------------|------------------------------------------------------|
| `fix:hold-remaining-amount`      | Data integrity fix for remaining amounts             |
| `stripe:fund-test-balance {amt}` | Test mode only: add funds to Stripe available balance via `tok_bypassPending` |

---

## 12. Middleware Stack

| Middleware          | Scope      | Status       | Purpose                                                  |
|---------------------|-----------|-------------|----------------------------------------------------------|
| **AdminMiddleware** | Admin web | **Active**  | Verify `Auth::check()` and `role === 'admin'`           |
| **auth:sanctum**    | API       | **Active**  | Token-based authentication (Laravel Sanctum)             |
| **throttle**        | Per-route | **Active**  | Rate limiting with configurable thresholds               |
| **SecurityHeaders** | Global    | **Disabled**| HSTS, X-Frame-Options: DENY, CSP, Referrer-Policy, Permissions-Policy. Also removes X-Powered-By and Server headers |
| **SanitizeInput**   | Global    | **Disabled**| Strips null bytes, trims whitespace, removes `<script>` tags recursively |
| **LogApiRequests**  | API       | **Disabled**| Logs method, URL, IP, user_agent, user_id, status, duration (ms) to 'api' log channel |

> **Note:** SecurityHeaders, SanitizeInput, and LogApiRequests are commented out in `bootstrap/app.php`. Enable before production deployment.

---

## 13. Data Security & Encryption

### Field-Level Encryption (AES-256-CBC via APP_KEY)

| Model                | Encrypted Fields                                                  |
|----------------------|-------------------------------------------------------------------|
| User                 | email, phone, full_name, provider_id, fcm_token, device_id, otp_code, token |
| Payment              | payment_intent_id, stripe_data (array)                            |
| Transfer             | stripe_transfer_id, stripe_connect_account_id, stripe_data (array)|
| StripeConnectAccount | connect_account_id, onboarding_url, stripe_data (array)          |
| StripeCustomer       | stripe_customer_id, stripe_data (array)                          |
| UserBankAccount      | account_holder_name, bank_name, account_number, routing_number, iban, stripe_bank_account_id, dob |

### Blind Indexes (HMAC-SHA256)

Searchable indexes on encrypted fields without exposing plaintext. Auto-synced via `booted()` model events:

| Table                    | Index Column               | Source Field          |
|--------------------------|----------------------------|-----------------------|
| users                    | email_index                | email                 |
| users                    | phone_index                | phone                 |
| users                    | provider_id_index          | provider_id           |
| payments                 | payment_intent_id_index    | payment_intent_id     |
| transfers                | stripe_transfer_id_index   | stripe_transfer_id    |
| stripe_connect_accounts  | connect_account_id_index   | connect_account_id    |
| stripe_customers         | stripe_customer_id_index   | stripe_customer_id    |
| user_bank_accounts       | account_number_index       | account_number        |
| user_bank_accounts       | stripe_bank_account_id_index | stripe_bank_account_id |

### Blind Index Implementation

```php
public static function blindIndex(string $value): string
{
    return hash_hmac('sha256', strtolower($value), config('app.key'));
}
```

### Security Headers (when enabled)

```
Strict-Transport-Security: max-age=31536000; includeSubDomains
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 1; mode=block
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(), microphone=(), camera=()
```

Also removes: `X-Powered-By`, `Server` headers.

---

## 14. Email Notifications

| Mailable Class                          | Trigger                     | Template                              |
|-----------------------------------------|-----------------------------|---------------------------------------|
| OtpMail                                 | Signup / password reset     | emails/otp.blade.php                  |
| AccountDeletionConfirmationMail         | User deletes account        | emails/account-deletion-confirmation  |
| AdminAccountDeletionNotificationMail    | Admin notified of deletion  | emails/admin-account-deletion-notification |
| PaymentSuccessMail                      | Payment succeeds            | emails/stripe/payment-success         |
| PaymentFailedMail                       | Payment fails               | emails/stripe/payment-failed          |
| HoldPeriodEndedMail                     | Hold matures                | emails/stripe/hold-ended              |
| TransferCompletedMail                   | Transfer succeeds           | emails/stripe/transfer-completed      |
| TransferFailedMail                      | Transfer fails              | emails/stripe/transfer-failed         |
| TransferCompletedSummaryMail            | Batch transfer summary      | emails/stripe/transfer-completed-summary |
| PayoutRequestMail                       | User requests payout        | emails/stripe/payout-request          |
| WithdrawalSummaryMail                   | Withdrawal summary          | emails/stripe/withdrawal-summary      |

---

## 15. Unrouted / Unused Files

The following files exist in the codebase but are **not currently wired to any route**:

| File | Notes |
|------|-------|
| `Api/DeviceController.php` | Device registration & FCM token updates - not routed |
| `Requests/Device/RegisterDeviceRequest.php` | Validation for device registration - not routed |
| `Requests/Device/UpdateDeviceTokenRequest.php` | Validation for FCM token update - not routed |
| `Requests/Stripe/TestPaymentRequest.php` | Test payment validation - not routed |
| `Requests/Stripe/CreateConnectAccountRequest.php` | Old Connect account validation - not routed |
| `Requests/Stripe/GetOnboardingLinkRequest.php` | Old onboarding link validation - not routed |
| `Requests/Stripe/CreatePaymentIntentRequest.php` | Old Checkout Session validation - not routed (replaced by Payment/CreatePaymentIntentRequest) |
| `Requests/Stripe/WithdrawPayoutRequest.php` | Withdraw payout validation - not routed |
| `Models/PasswordResetToken.php` | Empty placeholder model |

---

## 16. Database Migrations (24 total)

### Core Migrations

| Migration | Table/Action |
|-----------|-------------|
| `0001_01_01_000000` | Create users table |
| `0001_01_01_000001` | Create cache table |
| `0001_01_01_000002` | Create jobs table |
| `2026_01_07_165319` | Create user_notification_settings table |
| `2026_01_07_171642` | Create personal_access_tokens table (Sanctum) |
| `2026_01_08_000001` | Create stripe_connect_accounts table |
| `2026_01_08_000002` | Create payments table |
| `2026_01_08_000003` | Create payment_holds table |
| `2026_01_08_000004` | Create transfers table |
| `2026_01_08_000005` | Create stripe_webhook_events table |
| `2026_01_30_173801` | Create privacy_policies table |

### Incremental Migrations

| Migration | Change |
|-----------|--------|
| `2026_01_30_184024` | Add remaining_amount to payment_holds |
| `2026_01_30_185403` | Add partial_transferred status to payment_holds |
| `2026_02_09_164906` | Add abandoned_at to payment_holds and transfers |
| `2026_02_09_181129` | Fix deleted_at column in users table |
| `2026_02_24_211358` | Add title to payment_holds |
| `2026_02_27_211438` | Add email tracking fields to transfers |
| `2026_03_05_144518` | Add encryption support (blind index columns) |
| `2026_03_05_155351` | Encrypt transfer stripe IDs |
| `2026_03_05_160104` | Encrypt payment_intent_id in payments |
| `2026_04_22_000001` | Create stripe_customers table (Payment Sheet) |
| `2026_04_22_000002` | Add card detail columns to payments table |
| `2026_04_24_000001` | Create user_bank_accounts table (bank details + blind indexes) |
| `2026_04_24_000002` | Drop unique constraint on user_id (allow multiple banks per user) |

### Seeders

| Seeder | Purpose |
|--------|---------|
| `DatabaseSeeder` | Creates test user (`test@example.com` / `Test@123`), calls AdminSeeder + PrivacyPolicySeeder |
| `AdminSeeder` | Creates admin user from `config('app.admin_panel_email')` (default: `admin@timevault.com`) |
| `PrivacyPolicySeeder` | Seeds default privacy policy content |

### Factories

| Factory | Purpose |
|---------|---------|
| `UserFactory` | Generates fake users with unique email/phone. Methods: `unverified()` |
| `PrivacyPolicyFactory` | Generates fake privacy policies |

---

## 17. Testing

### Test Files

| File | Type | Description |
|------|------|-------------|
| `tests/TestCase.php` | Base | Base test case class |
| `tests/Feature/ExampleTest.php` | Feature | Default example test |
| `tests/Feature/AppConfigTest.php` | Feature | App configuration tests |
| `tests/Feature/Auth/ChangePasswordTest.php` | Feature | Password change flow tests |
| `tests/Feature/SendTransferCompletedNotificationTest.php` | Feature | Transfer notification tests |
| `tests/Unit/ExampleTest.php` | Unit | Default example unit test |

### Running Tests

```bash
composer test        # Clears config cache + runs PHPUnit
php artisan test     # Direct test runner
```

---

## 18. Development & Deployment

### Quick Start

```bash
# Setup
composer setup          # Install deps, generate key, migrate, build frontend

# Development (runs server + queue + vite concurrently)
composer dev            # Starts: php artisan serve, queue:listen, npm run dev

# Testing
composer test
```

### Environment Configuration

- **Database**: MySQL (configurable in .env)
- **Session/Cache/Queue**: All use database driver (no Redis/external dependencies needed)
- **Mail**: Log driver for dev, configurable SMTP/Postmark/Resend for production
- **Stripe**: Requires `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PUBLISHABLE_KEY` in .env

### Build Pipeline

- **Vite 7.0** with `@tailwindcss/vite` plugin
- Entry points: `resources/css/app.css`, `resources/js/app.js`
- Production build: `npm run build`
- Vite ignores: `storage/framework/views/**`

### Composer Scripts

| Script | Action |
|--------|--------|
| `composer setup` | Install, generate key, migrate, npm install, npm build |
| `composer dev` | Concurrently runs server, queue listener, vite dev |
| `composer test` | Clear config cache + run tests |

### Testing Resources

- **Postman Collection**: `postman_collection_stripe.json` (pre-configured API endpoints)
- **Testing Guide**: `POSTMAN_STRIPE_TESTING_GUIDE.md` (Stripe payment flow testing)
- **Payment Flow Doc**: `stripe_payment_sheet_flow.md` (Stripe PaymentSheet integration)
- **PHPUnit**: `phpunit.xml` configuration with `tests/` directory

---

## 19. File Counts Summary

| Category | Count |
|----------|-------|
| Models | 11 |
| Controllers (Admin) | 8 |
| Controllers (API) | 12 |
| Middleware | 4 |
| Form Requests | 24 |
| API Resources | 4 |
| Services | 4 |
| Console Commands | 5 |
| Jobs | 8 |
| Mailables | 11 |
| Blade Views (Admin) | 9 |
| Email Templates | 11 |
| Migrations | 24 |
| Test Files | 6 |
| **Total PHP Files (app/)** | **~97** |

---

## 20. Payment Sheet — Postman Testing Guide

### Prerequisites

1. **Stripe Test API Keys** in `.env`:
   ```
   STRIPE_KEY=sk_test_xxx
   STRIPE_SECRET=sk_test_xxx
   STRIPE_PUBLISHABLE_KEY=pk_test_xxx
   STRIPE_WEBHOOK_SECRET=whsec_xxx
   ```
2. **Run migration**: `php artisan migrate`
3. **Start server + queue**: `composer dev`
4. **Get Sanctum token**: Login via `POST /api/auth/login` → copy `token` from response

### Test 1: Create Payment Intent

```
POST {{base_url}}/api/stripe/create-payment-intent

Headers:
  Authorization: Bearer {{token}}
  Content-Type: application/json
  Accept: application/json

Body (JSON):
{
  "amount": 50.00,
  "currency": "usd",
  "hold_period_type": "custom",
  "hold_start_at": "2026-04-22",
  "hold_end_at": "2026-05-22",
  "title": "Test Savings Hold"
}

Expected Response (200):
{
  "success": true,
  "message": "Payment intent created. Use client_secret to present Payment Sheet.",
  "data": {
    "payment_intent_id": "pi_3xxx...",
    "client_secret": "pi_3xxx..._secret_xxx...",
    "customer_id": "cus_xxx...",
    "ephemeral_key": "ek_test_xxx...",
    "publishable_key": "pk_test_xxx...",
    "amount": 50.0,
    "currency": "usd"
  }
}

Verify in database:
  ✓ stripe_customers table → new row with encrypted stripe_customer_id
  ✓ Stripe Dashboard → Customers → cus_xxx exists
  ✓ Stripe Dashboard → Payments → pi_xxx with status "requires_payment_method"

Save these values for next steps:
  → payment_intent_id
  → customer_id (for second payment test — should reuse same customer)
```

### Test 2: Simulate Payment (Since Payment Sheet is Flutter-side)

Payment Sheet is a native Flutter UI — you can't trigger it from Postman.
Simulate a successful payment using one of these methods:

```
Option A: PHP Artisan Tinker
  php artisan tinker
  \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
  \Stripe\PaymentIntent::retrieve('pi_3xxx...')->confirm([
    'payment_method' => 'pm_card_visa'
  ]);

Option B: Stripe CLI
  stripe payment_intents confirm pi_3xxx... --payment-method=pm_card_visa

Option C: cURL
  curl https://api.stripe.com/v1/payment_intents/pi_3xxx.../confirm \
    -u sk_test_xxx: \
    -d payment_method=pm_card_visa

After confirming → PaymentIntent status becomes "succeeded"
Check: Stripe Dashboard → Payments → pi_xxx → Status: Succeeded ✓
```

### Test 3: Confirm Payment

```
POST {{base_url}}/api/stripe/confirm-payment

Headers:
  Authorization: Bearer {{token}}
  Content-Type: application/json
  Accept: application/json

Body (JSON):
{
  "payment_intent_id": "pi_3xxx..."
}

Expected Response (200):
{
  "success": true,
  "message": "Payment confirmed and records created successfully.",
  "data": {
    "payment": {
      "id": 1,
      "payment_intent_id": "pi_3xxx...",
      "amount": 50.0,
      "currency": "usd",
      "status": "succeeded",
      "paid_at": "2026-04-22T...",
      "card_brand": "visa",
      "card_last4": "4242",
      "card_exp_month": 12,
      "card_exp_year": 2028,
      "card_funding": "credit",
      "card_country": "US",
      "payment_method_type": "card"
    },
    "hold": {
      "id": 1,
      "amount": 50.0,
      "status": "holding",
      "hold_start_at": "2026-04-22T00:00:00+00:00",
      "hold_end_at": "2026-05-22T00:00:00+00:00",
      "hold_days": 30,
      "title": "Test Savings Hold"
    }
  }
}

Verify in database:
  ✓ payments table → new row with card_brand="visa", card_last4="4242"
  ✓ payment_holds table → new row with status="holding"
  ✓ Check laravel.log → "Payment success email dispatched"
```

### Test 4: Idempotency Check (Call confirm-payment again with same pi_xxx)

```
POST {{base_url}}/api/stripe/confirm-payment
Body: { "payment_intent_id": "pi_3xxx..." }

Expected Response (200):
{
  "success": true,
  "message": "Payment already recorded.",
  "data": { "payment": {...}, "hold": {...} }
}

✓ No duplicate rows created in payments or payment_holds tables
```

### Test 5: Verify Crons Still Work

```
# After hold_end_at passes (or manually update hold_end_at in DB to past date):

php artisan check:payment-holds
  → Output: "X holds marked as ready for transfer"
  → payment_holds.status: holding → ready_for_transfer ✓

php artisan verify:pending-transfers
  → Checks pending transfers with Stripe ✓
```

### Test 6: Error Cases

```
A) Missing fields:
  POST /api/stripe/create-payment-intent
  Body: { "amount": 50.00 }
  → 422: Validation errors for missing currency, hold_period_type, dates

B) Invalid payment_intent_id:
  POST /api/stripe/confirm-payment
  Body: { "payment_intent_id": "invalid" }
  → 422: "Invalid payment intent ID format."

C) Payment not yet succeeded:
  POST /api/stripe/confirm-payment
  Body: { "payment_intent_id": "pi_xxx..." }  (before simulating payment)
  → 400: "Payment not completed yet. Status: requires_payment_method"

D) Wrong user:
  Login as different user, try to confirm another user's payment_intent_id
  → 403: "Payment does not belong to this user."
```

### Test Cards (Stripe Test Mode)

| Card Number          | Brand      | Result           |
|----------------------|------------|------------------|
| `4242424242424242`   | Visa       | Success          |
| `5555555555554444`   | Mastercard | Success          |
| `378282246310005`    | Amex       | Success          |
| `4000000000003220`   | Visa       | 3D Secure popup  |
| `4000000000000002`   | Visa       | Declined         |
| `4000000000009995`   | Visa       | Insufficient funds |

### Postman Environment Variables

```
base_url: http://127.0.0.1:8000
token: (from login response)
payment_intent_id: (from create-payment-intent response)
customer_id: (from create-payment-intent response)
```
