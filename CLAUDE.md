# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Time Vault is a Laravel 12 fintech application where users make payments, hold funds for configurable durations, and withdraw/transfer funds upon maturity. It has a Blade-based admin panel for oversight. The mobile client communicates via a Sanctum-authenticated REST API; Stripe handles payments (Payment Sheet + Custom Connect + Transfers).

## Common Commands

```bash
composer setup          # Full initial setup (install, key:generate, migrate, npm install/build)
composer dev            # Start dev servers concurrently (artisan serve + queue:listen + vite dev)
composer test           # Clear config cache + run PHPUnit

php artisan test                                    # Run all tests
php artisan test tests/Feature/Auth/ChangePasswordTest.php  # Single test file
php artisan test --filter=testMethodName            # Single test method

php artisan migrate         # Run migrations
php artisan db:seed         # Seed admin user + privacy policy

npm run dev                 # Vite dev server (hot reload)
npm run build               # Production frontend build

vendor/bin/pint --dirty     # Format only changed PHP files (run before committing)
```

## Architecture

### Dual Interface
- **REST API** (`routes/api.php`) — Sanctum token auth, serves the mobile app. Public auth routes are rate-limited (3-10 req/min); protected routes at 1000 req/min.
- **Admin Panel** (`routes/web.php`) — Session-based auth behind `AdminMiddleware` (checks `role === 'admin'`). Blade views in `resources/views/admin/`.

### Service Layer
Business logic lives in `app/Services/`, not controllers:
- **PaymentSheetService** — PaymentIntent + Customer + EphemeralKey creation/confirmation, saves card details (brand, last4, exp, funding, country)
- **StripeService** — Stripe API wrapper, Connect account management, transfer execution
- **PaymentHoldService** — Hold period lifecycle and payout logic
- **WebhookService** — Stripe webhook event processing, saves card details on payment creation/update

### Encryption & Blind Indexes
PII fields (email, phone, full_name, provider_id, fcm_token, device_id, otp_code) are encrypted at rest. Searchable fields use HMAC-SHA256 blind indexes (`email_index`, `phone_index`, `provider_id_index`). The `User` model auto-computes blind indexes on save via a boot observer.

**Critical:** Never regenerate `APP_KEY` after data has been seeded/created — it will corrupt all encrypted fields.

### Async Notifications
8 queued jobs in `app/Jobs/` handle email notifications asynchronously via database-backed queue. The queue worker runs as part of `composer dev` or via Supervisor in production.

### Payment Flow
Stripe Payment Sheet flow: create Customer → create EphemeralKey → create PaymentIntent → confirm on device → webhook confirms → create PaymentHold with configurable hold duration. Users manually request withdrawals when holds mature (no auto-transfer).

**Card data is saved in all 5 Payment::create flows:**
1. `PaymentSheetService::recordPayment()` — Payment Sheet verification
2. `StripeController::verifyPayment()` — API verify endpoint
3. `StripeController::handlePaymentReturn()` — Redirect return handler
4. `WebhookService::handleCheckoutSessionCompleted()` — Checkout webhook
5. `WebhookService::handlePaymentIntentSucceeded()` — PaymentIntent webhook

Card fields saved: `card_brand`, `card_last4`, `card_exp_month`, `card_exp_year`, `card_funding`, `card_country`, `payment_method_type`.

### PaymentHold Statuses
`holding` → `ready_for_transfer` → `transferred` (or `partial_transferred`). Also: `abandoned`, `canceled`.

### PaymentHold Relationships
- `transfers()` — HasMany, returns all transfers for a hold (used for partial withdrawals)
- `transfer()` — HasOne latestOfMany, returns the most recent transfer (backward-compatible)

### Withdrawal Flow
- User requests withdrawal via `PaymentHoldController::withdraw()` with a specific amount
- System distributes across multiple eligible holds (oldest first), creating one Transfer per hold
- `remaining_amount` is deducted for `completed` and `pending` transfers — NOT deducted for `failed` transfers
- Cron jobs must NOT deduct `remaining_amount` again (already done in `withdraw()`)
- If a pending transfer later fails, `VerifyPendingTransfers` restores `remaining_amount`
- Partial withdrawals supported: hold status becomes `partial_transferred`
- Each Transfer stores `bank_account_id` to track which bank was used

### Transaction History API
`TransactionHistoryController` provides transaction data in 5 categories:
- `GET /api/transactions/all` — All categories with summary (checkout, withdraw, hold_amount, ready_for_transfer, transferred)
- `GET /api/transactions/checkouts` — Paginated checkouts
- `GET /api/transactions/withdraws` — Paginated withdrawals (grouped by request)
- `GET /api/transactions/hold-amounts` — Currently locked holds
- `GET /api/transactions/ready-for-transfer` — Available for withdrawal

Every transaction item includes hold time fields: `hold_start`, `hold_end`, `total_days`, `days_remaining`, `hours_remaining`, `minutes_remaining`. Calculated by shared `getHoldTimeFields()` helper. Withdraw items have null hold fields.

### Bank Accounts
- Multiple bank accounts per user, first auto-set as primary
- Stripe Custom Connect account created silently on first bank add
- Stripe does NOT support updating bank details — must delete old + add new
- `UserBankAccount` model has `getMaskedAccountNumberAttribute()` for ****XXXX display
- `setPrimary()` syncs Stripe `default_for_currency` so withdrawals go to the correct bank
- `destroy()` also syncs Stripe default when a deleted primary bank is replaced by fallback

### Scheduled Commands (Cron)
- `check:payment-holds` — Every 5 min. Marks matured holds as `ready_for_transfer`, sends notification. Must exclude abandoned holds (`whereNull('abandoned_at')`).
- `verify:pending-transfers` — Every 5 min. Verifies pending transfers with Stripe API, updates status, sends batched emails. Only updates hold status — does NOT deduct `remaining_amount` (already done in `withdraw()`). Restores `remaining_amount` if a transfer fails.
- `users:cleanup-unverified` — Daily at midnight ET. Hard-deletes unverified pending users older than 24 hours.
- `fix:hold-remaining-amount` — Manual only. One-time data fix for remaining_amount inconsistencies.
- `stripe:fund-test-balance` — Manual only. Test utility, guards against production.

## Admin Panel

### Live Auto-Refresh (AJAX Polling)
All admin data pages use AJAX polling with the same pattern:
- Each page has a `stats()` JSON endpoint polled every 1 minute
- Stat cards update live with fade animation
- Tables auto-reload (5s countdown banner) when record counts change
- `withoutOverlapping()` on all scheduled commands

Pages with auto-refresh:
- **Dashboard** (`admin.dashboard.stats`) — 8 stat cards, 3 recent tables
- **Users List** (`admin.users.stats`) — total, active, inactive, pending, verified counts
- **User Detail** (`admin.users.stats.show`) — amount cards (held/ready/withdrawn), holds/transfers/bank counts
- **Payments** (`admin.payments.stats`) — revenue, status counts (maps `completed` filter → DB `succeeded`)
- **Payment Holds** (`admin.payment-holds.stats`) — amount totals, status counts
- **Transfers** (`admin.transfers.stats`) — total transferred, status counts

### List Pages
All admin list pages (`users`, `payments`, `payment-holds`, `transfers`) have:
- `#` serial number column (pagination-aware)
- Padded ID column (00001 format) — each list shows its own entity ID (User ID, Payment ID, Hold ID, Transfer ID)

### User Detail Page (`/admin/users/{id}`)
Three data tables:
- **Payment Holds** — `#`, Hold ID, Title, Amount, Hold Period, Unlock Date, Status
- **Bank Accounts** — `#`, Bank Name, Account (masked), Type, Country, Status, Action (view modal)
- **Transfers** — `#`, Transfer ID, Amount, To (Account) with bank name + last 4 digits, Type, Status, Date

All three tables are paginated (10 per page) with independent page params (`holds_page`, `transfers_page`, `banks_page`).
Controller loads paginated queries separately; amount summaries use `COALESCE(remaining_amount, amount)` for NULL safety.

## Conventions

- PHP 8.2+ with constructor property promotion
- Explicit return type declarations on all methods
- Use Form Request classes (`app/Http/Requests/`) for validation — don't validate in controllers
- Use Eloquent relationships over raw queries
- Use `php artisan make:*` commands to scaffold new files
- Follow patterns in sibling files for consistency
- Tests use SQLite in-memory database (configured in `phpunit.xml`)

## Timezone

- `APP_TIMEZONE=UTC` — all dates stored in UTC in the database
- `ADMIN_TIMEZONE=America/New_York` — admin panel displays dates in ET
- `PaymentHoldService` parses user input dates in user's timezone (`$user->timezone`), converts to UTC for storage
- API responses return ISO-8601 UTC dates; Flutter converts to local timezone for display
- Cron compares UTC vs UTC — no DST issues
- Validation `after_or_equal:today` uses user's timezone so late-night users aren't rejected

## Deployment (Hostinger VPS)

- **Domain:** `time-vault.devonlinetestserver.com`
- **Path:** `/home/devonlinetestserver-time-vault/htdocs/time-vault.devonlinetestserver.com`
- **Nginx:** Uses `location ^~ /storage/` with `alias` to serve files from `storage/app/public/` — do NOT use `php artisan storage:link` (symlinks cause "Too many levels" error on this VPS)
- **PHP-FPM** runs as user `devonlinetestserver-time-vault` (not `www-data`) — profiles directory needs `chmod 777`
- **Cron:** `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1`
- **Queue:** Needs a persistent worker or cron-based queue processing

## Key Config

- Admin credentials: `ADMIN_PANEL_EMAIL` / `ADMIN_PANEL_PASSWORD` in `.env` (read via `config/app.php`)
- Admin timezone: `ADMIN_TIMEZONE` in `.env` (read via `config/app.admin_timezone`)
- Sanctum tokens expire after 24 hours (`config/sanctum.php`)
- Stripe keys: `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`
- Session, queue, and cache all use the `database` driver
