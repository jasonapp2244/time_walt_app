# Subscription Plans & Free Tier — Implementation Plan

**Status:** Approved and implemented 2026-10-07 (local only, see below). Supersedes the 2026-10-02 draft (its three open decisions are resolved below; its Stripe calls were written for an older API version and would not work on the installed `2025-12-15.clover`).
**Scope:** Laravel backend + admin panel (this repo) and the Flutter app (separate repo, see `docs/FLUTTER_SUBSCRIPTION_BRIEF.md`).

---

## Implementation status (2026-10-07)

**Built and tested locally; not committed, not deployed.** 55 new tests under `tests/Feature/Subscription/`; full suite 188 green.

Where the build differs from the plan below, the build wins:
- **Two services instead of one.** `app/Services/FreeTierService.php` holds the limits and counters and has no dependencies, so `PaymentHoldService` can use it without a constructor cycle. `app/Services/SubscriptionService.php` holds the Stripe side, including plan sync (`syncPlanToStripe()`), which was planned for `StripeService`.
- **Withdrawal counting lives in the middleware, not the controllers.** `quota:withdraw` claims a free withdrawal with `lockForUpdate()` before the controller runs, and gives it back if the response is not a success. Two parallel requests cannot share the last free withdrawal, and `withdraw()` / `requestPayout()` are unchanged.
- **Admin routes:** `/admin/subscription-plans`, `/admin/subscriptions`, `/admin/settings/free-tier`, and `POST /admin/users/{user}/reset-free-usage`.
- **The Postman collection** has a new folder `11. Subscription`.

**Added 2026-10-07 (second pass):**
- **Payment emails to both sides.**
  - On every paid subscription invoice (first payment and each automatic renewal), the user gets a receipt and the admin inbox (`ADMIN_EMAIL`) gets a notice.
  - Recorded in a new `subscription_invoices` table; its unique Stripe invoice index makes each payment email exactly once, even if Stripe redelivers the webhook.
  - Failed and 3-D Secure renewals now also reach the admin.
  - The user copy follows the user's transaction + email alert switches, like deposit emails; the admin copy is always sent.
- **New admin page Subscription Payments** (`/admin/subscription-payments`): payment history, revenue per currency.
- **Account deletion cancels the subscription in Stripe.** Before this, a deleted account kept renewing. If Stripe is down, the deletion still completes and an error is logged for manual cancellation.
- **Every admin list** (users, payment holds, payments, transfers, feedback, support, subscriptions, subscription plans, subscription payments):
  - is paginated;
  - auto-refreshes (polls every minute, shows a reload banner on change);
  - downloads as **Excel (.xlsx)** or **PDF** with the page's current filters (`App\Support\ListExport`; PDF capped at 1,000 rows and says so, Excel has every row).

What a person still has to do: the Stripe Dashboard steps in §6 (test mode, then live), create the first plan in the admin panel, run the deploy (4 migrations, so `deploy.sh` takes a `mysqldump` first), and hand `docs/FLUTTER_SUBSCRIPTION_BRIEF.md` to the app team.

---

## Context

The client wants every user to get **2 free locks** (deposit + hold) and **2 free withdrawals**. On the **3rd attempt** the app opens a **subscription plans screen**. After the user subscribes, the blocked action must **work straight away** (no logout, no waiting), and the subscription must **renew automatically** (recurring). Plans and prices are managed from the **admin panel** and synced to Stripe automatically. It must work on **both Android and iOS**.

The 2026-10-02 draft of this file left three decisions open and has errors against the current code (verified 2026-10-07):
- `PaymentSheetService::recordPayment()` does not exist. A hold is created only in `PaymentHoldService::createFromPayment()` (`app/Services/PaymentHoldService.php:19`), reached from `PaymentSheetService::confirmPayment()` (`:201`) and two webhook handlers (`WebhookService.php:133`, `:213`).
- stripe-php is **v19, API version `2025-12-15.clover`** (`vendor/stripe/stripe-php/lib/Util/ApiVersion.php:9`). On this version, `latest_invoice.payment_intent` and `subscription.current_period_end` no longer exist; the old plan's subscribe flow would not work as written.
- `payment_intent.succeeded` fires for subscription invoices too. `WebhookService::handlePaymentIntentSucceeded` then logs a false "Payment not found and user_id missing" error on every renewal.

**Decisions (made 2026-10-07; change them here if the client decides otherwise):**
1. **Provider: Stripe on both Android and iOS**, using the Stripe Payment Sheet the app already uses for deposits. This is what the client's spec needs (admin-set prices synced to Stripe), it gives one code path, and it activates instantly. **Risk:** Apple rule 3.1.1 can reject an iOS build that sells a feature-unlocking subscription outside Apple in-app purchase. Mitigation built in now: a `provider` column on `subscriptions` lets an Apple/RevenueCat path be added later with no migration and no change to the gating logic.
2. **Withdrawals are gated, as the client asked**, with an admin on/off switch (`withdraw_limit_enabled`). The client can stop gating users' own money without a deploy if it causes complaints.
3. **Lifetime limit** (2 per account, never resets). Both limits are admin-editable.

---

## 1. How it works for the user (the full journey)

1. **Lock 1 and 2:** work normally. The lock screen shows "1 free lock left" from `/api/subscription/status`.
2. **Lock 3:** the app calls `POST /api/stripe/create-payment-intent` as usual. The server returns **HTTP 402** `subscription_required` **before any card is charged**. A central Dio interceptor opens `SubscriptionPlansScreen`, remembering the blocked request.
3. **Plans screen:** shows the active plans from `GET /api/subscription/plans` (name, price/interval, features).
4. **Subscribe:** the app calls `POST /api/subscription/subscribe {plan_id}` → the server creates a Stripe Subscription (`default_incomplete`) for the user's existing Stripe customer → returns `client_secret` + `customer_id` + `ephemeral_key` + `publishable_key` (**same shape as create-payment-intent**) → the app presents the **same Payment Sheet** (card / Apple Pay / Google Pay).
5. **Instant activation:** after Payment Sheet success, the app calls `POST /api/subscription/confirm`. The server **retrieves the subscription from Stripe** (never trusts the client) and saves it as `active`. No waiting on the webhook. The webhook arrives later and is idempotent.
6. **Back to what they were doing:** the app closes the plans screen and **re-sends the original blocked request** automatically. It is safe to replay because the 402 happened before any money moved. The lock opens the deposit Payment Sheet; the withdrawal just goes through.
7. **Renewal:** Stripe charges the saved card each month/year automatically. `invoice.paid` extends `current_period_end`. If a renewal fails, Stripe retries (Smart Retries). The user stays subscribed while `past_due`. When Stripe gives up, the subscription is `canceled` and the user is back on the free limits (already used, so the next action shows the plans screen).
8. **Manage:** a "My Subscription" screen shows the plan and renewal date, a **Cancel** button (cancel at period end, keeps access until then), and **Manage payment method**, which opens the Stripe Customer Portal in the browser.

Withdrawals follow the same steps 2–6 on `POST /api/payment-holds/withdraw` and `POST /api/payment-holds/{id}/request-payout` while `withdraw_limit_enabled` is on.

---

## 2. Database (4 migrations)

- **`users`**: add `free_locks_used`, `free_withdrawals_used` (unsigned int, default 0). Counters, not row counts: existing users already have many holds and must not be blocked on launch day.
- **`subscription_plans`**:
  - `name`, `description`, `features` (JSON)
  - `price` decimal(10,2), `currency` (usd/eur/gbp from `config('services.stripe.supported_currencies')`), `interval` (month/year)
  - `stripe_product_id`, `stripe_price_id`
  - `is_active`, `sort_order`, timestamps, soft deletes
- **`subscriptions`**:
  - `user_id` (FK, cascade), `subscription_plan_id` (FK)
  - `provider` (default `stripe`)
  - `stripe_subscription_id` (encrypted) + `stripe_subscription_id_index` (blind index, same pattern as `Transfer` / `StripeCustomer`)
  - `status` (incomplete / active / past_due / canceled / unpaid / incomplete_expired)
  - `current_period_end`, `cancel_at_period_end`, `canceled_at`, timestamps
- **`app_settings`** (key/value): `free_lock_limit`=2, `free_withdraw_limit`=2, `withdraw_limit_enabled`=1. `.env` fallbacks `FREE_LOCK_LIMIT`, `FREE_WITHDRAW_LIMIT` via `config/app.php`.

**Subscribed** = latest subscription with status `active` or `past_due` and `current_period_end > now()`.

## 3. Backend

House style: thin controllers, logic in `app/Services/`, Form Requests, constructor promotion, encrypted Stripe IDs with blind indexes.

**New**
- `app/Models/SubscriptionPlan.php`, `Subscription.php`, `AppSetting.php`; `User::subscriptions()`, `User::activeSubscription()`, `User::isSubscribed()`.
- `app/Services/SubscriptionService.php`:
  - `quota(User)`: used, limit, left, subscribed
  - `assertCanLock()`, `assertCanWithdraw()`
  - `subscribe(User, Plan)`, `syncFromStripe(string $subId)` (shared by `/confirm` and the webhooks), `cancel()`, `portalUrl()`
- `app/Http/Middleware/EnsureFreeQuotaOrSubscribed.php` (`quota:lock` / `quota:withdraw`), returning 402:
  ```json
  {"success":false,"code":"subscription_required","action":"lock","used":2,"limit":2,
   "message":"You have used your 2 free locks. Subscribe to continue."}
  ```
- `app/Http/Controllers/Api/SubscriptionController.php` + Form Requests, under the `auth:sanctum` group in `routes/api.php`:
  - `GET  /api/subscription/plans`
  - `GET  /api/subscription/status`: is_subscribed, plan, status, current_period_end, cancel_at_period_end, locks_left, withdrawals_left
  - `POST /api/subscription/subscribe` `{plan_id}`: Payment Sheet keys. Refuses if already subscribed. Reuses an existing `incomplete` subscription for the same plan instead of creating duplicates.
  - `POST /api/subscription/confirm`: pulls from Stripe and activates
  - `POST /api/subscription/cancel`: `cancel_at_period_end=true`
  - `POST /api/subscription/portal`: Customer Portal URL

**Stripe calls (API `2025-12-15.clover`)**
- Subscribe:
  ```
  Subscription::create([
    customer, items => [[price]],
    payment_behavior => 'default_incomplete',
    payment_settings => ['save_default_payment_method' => 'on_subscription'],
    expand => ['latest_invoice.confirmation_secret'],
    metadata => ['user_id', 'plan_id'],
  ])
  ```
  The client secret is `latest_invoice.confirmation_secret.client_secret`. The customer comes from `PaymentSheetService::getOrCreateCustomer()` (`:23`) and the ephemeral key from `createEphemeralKey()` (`:49`); bump its pinned `stripe_version` (`:53`) only if the Payment Sheet SDK requires it.
- **Period end:** read from `items.data[0].current_period_end` (no longer on the subscription).
- **Invoice → subscription:** read from `invoice.parent.subscription_details.subscription`.
- **Plan sync** (`StripeService::syncPlan()`):
  - Create the Product, and the Price with `recurring.interval`.
  - On a price, currency or interval change, create a new Price and archive the old one (`active=false`). Existing subscribers keep their price.
  - Deactivating a plan sets Product `active=false`.

**Counting (one place each, race-safe)**
- **Locks:** gate in `quota:lock` on `create-payment-intent` (`routes/api.php:70-73`). Increment inside `PaymentHoldService::createFromPayment()` when the user is not subscribed. That one spot covers `confirmPayment` and both webhook paths. **Never block** `confirm-payment` or webhooks, because money is already taken.
- **Withdrawals:** gate in `quota:withdraw` on `withdraw` and `request-payout` (`routes/api.php:87-88`). Inside the controller's existing DB transaction, re-check with `lockForUpdate()` on the user row, and increment once per call that produced at least one non-failed transfer. Admin and cron transfers (`StripeService::createTransfer` from `TransferController` / `CheckPaymentHolds`) are not gated or counted.

**Webhooks** (`StripeController::handleWebhook` switch `:650-669`, logic in `WebhookService`, existing `stripe_webhook_events` idempotency):

| Event | Action |
|---|---|
| `customer.subscription.created` / `.updated` | `syncFromStripe` (upsert by blind index, so arrival order doesn't matter) |
| `customer.subscription.deleted` | status `canceled` |
| `invoice.paid` | sync: active and extend the period |
| `invoice.payment_failed` | `past_due` + queue an email to the user |
| `invoice.payment_action_required` | queue an email with the hosted invoice link (3-D Secure on renewal) |

- Guard `handlePaymentIntentSucceeded`: return quietly (info log) for PaymentIntents that are not deposits. That means no hold metadata and no `user_id`, which is the case for subscription invoices. It stops the false error on every renewal.

## 4. Admin panel

Mirror `Admin/PrivacyPolicyController` + `resources/views/admin/privacy-policy/`, inside the `admin` middleware group (`routes/web.php:26`), with sidebar links in `layouts/partials/sidebar.blade.php`.
- **Subscription Plans** (`/admin/subscription-plans`): list, create, edit, on/off. Save → Stripe sync; on sync failure show the error and keep the plan inactive.
- **Free Tier Settings** (`/admin/settings/free-tier`): lock limit, withdraw limit, withdraw-limit on/off.
- **Subscriptions** (`/admin/subscriptions`):
  - Columns: user, plan, status, renewal date, amount.
  - Filter by status; email search through `email_index`, like `Admin\SupportController`.
  - Actions: cancel at period end / cancel now.
  - `#` serial + padded ID, and `/stats` AJAX polling like `DashboardController::stats`.
- **User detail:** plan card + "Locks 2/2 · Withdrawals 1/2" + a reset-counters button (support tool).
- **Dashboard:** active subscribers + MRR cards.

## 5. Flutter (Android + iOS, same code)

1. **Dio interceptor:** on 402 + `code == subscription_required`, push `SubscriptionPlansScreen(action, retry: originalRequest)`. One place, so no existing screen changes.
2. **Plans screen:** plan cards → subscribe → `PaymentSheet.initPaymentSheet` with the returned keys (`merchantDisplayName`, `applePay` `merchantCountryCode`, `googlePay` the same as the deposit flow) → `presentPaymentSheet`.
3. On success → `POST /subscription/confirm` → when `is_subscribed`, pop and replay the original request. If confirm says it is still `incomplete`, poll `/status` for up to ~10 s.
4. Lock and withdraw screens show remaining free count; "My Subscription" screen with cancel + manage payment method (Customer Portal URL in an in-app browser).
5. Already required for the deposit Payment Sheet; just verify: iOS Apple Pay merchant ID + entitlement + `urlScheme` for 3-D Secure return; Android `FlutterFragmentActivity` + Google Pay enabled.

## 6. Stripe Dashboard setup (do in **test mode** first, then repeat in **live**)

Products and Prices are **not** created by hand; the admin panel creates them.

1. **Developers → Webhooks:** on the existing endpoint `https://api.timevaultapp.co/api/stripe/webhook`, add:
   - `customer.subscription.created`
   - `customer.subscription.updated`
   - `customer.subscription.deleted`
   - `invoice.paid`
   - `invoice.payment_failed`
   - `invoice.payment_action_required`

   Keep the existing events. `STRIPE_WEBHOOK_SECRET` is unchanged.
2. **Settings → Billing → Subscriptions and emails:**
   - Smart Retries on (e.g. 4 tries / 2 weeks).
   - After all retries fail: **Cancel the subscription**.
   - Enable "Send emails when a payment requires 3-D Secure" (Stripe-hosted link).
   - Optional: upcoming renewal reminder emails and failed payment emails.
3. **Settings → Billing → Customer portal:**
   - Allow: update payment method, cancel subscription (at end of period), view invoice history.
   - Plan switching off for v1.
   - Set business name, privacy and terms links.
4. **Settings → Payment methods:** Cards, Apple Pay and Google Pay on, for the platform account. They are probably already on for deposits; confirm.
5. **Settings → Payment methods → Apple Pay → iOS certificates:** the Apple Pay certificate for the app's merchant ID must exist in **both** test and live (already needed for deposits; confirm).
6. **Settings → Business → Public details / Branding:** name, support email (`SUPPORT_EMAIL`), logo, so receipts and invoices look right.
7. **Restricted keys:** if `STRIPE_SECRET` is a restricted key, it needs write access to Products, Prices, Subscriptions, Invoices and Customer portal sessions.
8. Optional, client decision: Stripe Tax, if they must collect VAT/sales tax on the subscription.

## 7. Files touched (representative)

- **New:**
  - 4 migrations
  - `app/Models/{SubscriptionPlan,Subscription,AppSetting}.php`
  - `app/Services/SubscriptionService.php`
  - `app/Http/Middleware/EnsureFreeQuotaOrSubscribed.php` (alias in `bootstrap/app.php`)
  - `app/Http/Controllers/Api/SubscriptionController.php`
  - `app/Http/Controllers/Admin/{SubscriptionPlanController,SubscriptionController,FreeTierSettingsController}.php`
  - `app/Http/Requests/Subscription/*`
  - `resources/views/admin/{subscription-plans,subscriptions,settings}/*`
  - 2 mail jobs (payment failed / action required)
  - factories, tests
- **Edited:**
  - `routes/api.php`, `routes/web.php`
  - `app/Models/User.php`
  - `app/Services/{PaymentHoldService,StripeService,WebhookService}.php`
  - `app/Http/Controllers/Api/StripeController.php` (switch)
  - `app/Http/Controllers/Api/PaymentHoldController.php` (withdraw counting)
  - sidebar, dashboard, user detail view
- **Docs:**
  - regenerate Postman (`php docs/postman/build_collection.php`)
  - progress files
  - a Flutter brief `docs/FLUTTER_SUBSCRIPTION_BRIEF.md` (same style as `FLUTTER_SUPPORT_FEEDBACK_BRIEF.md`), since the Flutter app is a separate repo

## 8. Edge cases

- Concurrent PaymentIntents at 1 lock used can both confirm → 3 locks. Accepted: confirmation is never blocked once money is taken; the counter still records it, so the next attempt is gated.
- Delete account and re-register gives 2 new free actions. Accepted for v1 (noted).
- The subscription ends while funds are on hold: the hold and its withdrawal stay governed by `withdraw_limit_enabled`.
- An abandoned subscribe leaves an `incomplete` subscription; Stripe expires it after 23 h → `incomplete_expired` via webhook; `/subscribe` reuses an open one instead of stacking.
- Already subscribed → `/subscribe` returns 409.

## 9. Verification

- **Feature tests** (MySQL `time_walt_test`, Stripe faked with `tests/Fakes/FakeStripeHttpClient.php`):
  - 1st and 2nd lock pass, 3rd → 402 with no Stripe call
  - 3rd withdraw and 3rd request-payout → 402; the switch off → allowed
  - confirm-payment and webhooks never blocked
  - a subscribed user is not counted or blocked; canceled or expired → limits return
  - `/subscribe` request params (clover fields); `/confirm` activates from the Stripe response
  - each webhook event → correct status, idempotent replay
  - plan create / price change → new Price + old archived
  - admin pages require the admin role
  - concurrent withdraws can't exceed the limit
  - the `payment_intent.succeeded` guard
- `php artisan test`, `vendor/bin/pint --test`, `php artisan route:list` (expect 65 + the new routes).
- **Stripe test mode end to end (local + `stripe listen --forward-to localhost/api/stripe/webhook`):**
  1. Create a plan in admin and confirm the Product/Price appear in Stripe.
  2. Do 2 locks, check the 3rd → 402.
  3. Subscribe with `4242`, then `/confirm` → active.
  4. The 3rd lock passes.
  5. `stripe trigger invoice.payment_failed` → past_due.
  6. A `4000 0027 6000 3184` (3DS) renewal → action-required email.
  7. Cancel → access until period end.
- Load the admin pages in the browser with no 500s.

## 10. Effort

- Backend + webhooks + tests: 2 days
- Admin: 1 day
- Flutter (other repo, via brief): 2 days
- Dashboard setup + end-to-end: 0.5 day

Production deploy and the live-mode dashboard steps are done by a person, not by automation.
