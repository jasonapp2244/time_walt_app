# Time Vault — Flutter prompt for Claude Code

**How to use:** open a terminal in the Flutter project folder, run `claude`, and paste everything below the line. Claude will read the app, build the missing features, and report back.

---

You are working in the **Time Vault** Flutter app (Android + iOS, one codebase). The Laravel backend is **live and finished** at `https://api.timevaultapp.co`. Your job is to build the app side of the features listed below so they work end to end against that API.

## Rules

1. **Read the app first.** Find the existing HTTP client (Dio or http), how the auth token is stored and sent, the state management in use (Provider/Riverpod/Bloc/GetX), the routing, the theme, and the **existing deposit (lock) flow that already uses the Stripe Payment Sheet** (`flutter_stripe`). Reuse all of them. Match the existing folder structure, naming and UI style.
2. **Skip what already exists.** If a feature below is already built and matches the contract, leave it alone and say so in your report. Only fix what differs.
3. **No new packages** except `url_launcher` (only if missing). Everything else is already in the app.
4. **Never hard-code** prices, plan names, free limits or currencies. Read them from the API.
5. **Handle every status code listed** for each endpoint. Show the server's `message` to the user unless a specific text is given here. Never show a raw exception.
6. **Every request** sends `Authorization: Bearer <token>` (except login/signup) and **`Accept: application/json`**. Without `Accept`, validation errors come back as HTML.
7. Loading, empty and error states on every new screen. Disable buttons while a request is running (prevents double charges).
8. When done, run `flutter analyze` (must be clean) and `flutter test` if tests exist. Fix what you broke.
9. Do not ask me questions. If something is unclear, pick the safest option, build it, and note the assumption in your report.

## API basics

- Base URL: `https://api.timevaultapp.co`
- Normal success/error body: `{ "success": true|false, "message": "...", "data": { ... } }`
- **422 validation** has a different shape (no `success`):
  `{ "message": "First error (and 2 more errors)", "errors": { "field": ["msg"] } }` → show `errors[field][0]` under each field.
- **401** `{ "message": "Unauthenticated." }` → token is gone: clear it and go to login. Tokens never expire on their own.
- **429** `{ "message": "Too Many Attempts." }` → show "Please wait a moment and try again."
- Dates are ISO-8601, already in the user's own timezone. Parse with `DateTime.parse`; do not add offsets.

---

## Feature A — Free tier and the 402 paywall

Every account gets a free allowance: **2 locks (deposits) + 2 withdrawals, for life**. The admin can change both numbers or switch the withdrawal limit off. After that the user must subscribe.

These endpoints can return **HTTP 402** *before* any card is charged or money moves:

| Endpoint | `action` |
|---|---|
| `POST /api/stripe/create-payment-intent` | `lock` |
| `POST /api/payment-holds/withdraw` | `withdraw` |
| `POST /api/payment-holds/{hold_id}/request-payout` | `withdraw` |

```json
{
  "success": false,
  "code": "subscription_required",
  "action": "lock",
  "used": 2,
  "limit": 2,
  "message": "You have used your 2 free locks. Subscribe to continue."
}
```

**Build:** one global interceptor (in the existing HTTP client) that catches `402` with `code == "subscription_required"`, opens the **Plans screen** (Feature B) with `action` and `message`, and if the user subscribes, **replays the original request automatically** and returns its result to the caller. If they close the plans screen, return the original error. Retrying after subscribing is always safe.

Notes:
- `POST /api/stripe/confirm-payment` is never blocked.
- A withdraw that fails validation (422) or has no funds (400) does **not** use up a free withdrawal.

## Feature B — Subscription plans screen

### Plans
`GET /api/subscription/plans`
```json
{
  "success": true,
  "message": "Subscription plans retrieved successfully.",
  "data": {
    "plans": [
      { "id": 1, "name": "Monthly", "description": "No commitments. Cancel anytime.",
        "features": ["Unlimited time locks", "Unlimited withdrawals", "Renews automatically", "No commitments. Cancel anytime."],
        "price": 4.99, "currency": "USD", "interval": "month" },
      { "id": 2, "name": "Yearly", "description": "No commitments. Cancel anytime.",
        "features": ["Unlimited time locks", "Unlimited withdrawals", "Renews automatically", "No commitments. Cancel anytime."],
        "price": 49.99, "currency": "USD", "interval": "year" }
    ]
  }
}
```
- Show plans in the order given, as cards with name, price, interval, features.
- Empty list → "Plans are not available right now."
- Required copy (client + app store rules), visible without scrolling near the Subscribe button:
  - **"No commitments. Cancel anytime."** (show even while loading)
  - Under each price: **"Renews automatically at $4.99/month until cancelled."** — build it from `price`, `currency`, `interval`.
- Never promise refunds anywhere on this screen.

### Subscribe
`POST /api/subscription/subscribe` body `{ "plan_id": 1 }` (limit 10/min)

200:
```json
{
  "success": true,
  "message": "Subscription created. Complete the payment to activate it.",
  "data": {
    "subscription_id": 12, "status": "incomplete",
    "client_secret": "pi_..._secret_...", "customer_id": "cus_...",
    "ephemeral_key": "ek_...", "publishable_key": "pk_...",
    "amount": 4.99, "currency": "usd",
    "plan": { "id": 1, "name": "Monthly", "price": 4.99, "currency": "USD", "interval": "month" }
  }
}
```
These are the **same keys as the deposit Payment Sheet** — reuse that code: `initPaymentSheet` (`paymentIntentClientSecret`, `customerId`, `customerEphemeralKeySecret`, `merchantDisplayName: 'Time Vault'`, Apple Pay / Google Pay `merchantCountryCode: 'US'`, the same `returnURL` the deposit uses for 3-D Secure) → `presentPaymentSheet()`.
- If `client_secret` is `null`, show "Please try again" (do not open the sheet).

| Status | Meaning | Do |
|---|---|---|
| 409 | "You already have an active subscription." (`data` = the `/status` payload) | Close the plans screen as **subscribed** (interceptor replays the request) |
| 422 | `errors.plan_id` e.g. "This plan is no longer available." | Show it, reload plans |
| 500 | "Unable to start the subscription. Please try again later." | Show it |
| Sheet cancelled (`StripeException` `FailureCode.Canceled`) | Nothing charged | Stay on the screen, no error |

Tapping Subscribe twice returns the same unpaid subscription — no double charge — but still disable the button while busy.

### Confirm (instant activation)
Right after `presentPaymentSheet()` succeeds: `POST /api/subscription/confirm` (no body, limit 30/min). Returns the same `data` as `/status` (Feature C).
- `data.is_subscribed == true` ("Your subscription is active.") → close plans screen as subscribed.
- `false` ("Payment is still processing. Check again in a few seconds.") → poll `GET /api/subscription/status` every 2 s for up to 10 s; if still false show "Payment received, activating…" with a Retry button.
- 404 "No subscription found." / 500 "Unable to confirm the subscription. Please try again." → show message, Retry.

## Feature C — Subscription status and free-use hints

`GET /api/subscription/status`
```json
{
  "success": true,
  "message": "Subscription status retrieved successfully.",
  "data": {
    "is_subscribed": false,
    "subscription": null,
    "locks_used": 1, "lock_limit": 2, "locks_left": 1,
    "withdrawals_used": 0, "withdraw_limit": 2, "withdrawals_left": 2
  }
}
```
When subscribed, `subscription` is:
```json
{ "id": 12, "status": "active", "is_active": true,
  "plan": { "id": 1, "name": "Monthly", "price": 4.99, "currency": "USD", "interval": "month" },
  "current_period_end": "2026-11-28T10:00:00-04:00", "cancel_at_period_end": false }
```
- Gate everything on **`is_subscribed`**. `subscription.status` can be `active`, `trialing` or `past_due` (all have access). On `past_due` show a small banner "Payment problem — update your card" linking to Manage payment method.
- **`*_left: null` = unlimited** (subscribed, or the admin switched that limit off) → hide the hint.
- Show hints on the lock and withdraw screens: "1 free lock left" / "No free withdrawals left". When the value is `0` you may open the Plans screen directly instead of waiting for the 402.
- Refresh status on app start, after login, after subscribe/cancel, and when returning from the portal.

## Feature D — My Subscription screen

Reachable from Profile/Settings. Shows plan, price, renewal date (`current_period_end`), status. If not subscribed: free usage + "See plans" button.

| Action | Call | Behaviour |
|---|---|---|
| Cancel | `POST /api/subscription/cancel` | Confirm dialog first. 200 "Your subscription will end at the close of the current period." → show "Ends on {date}". Calling twice is fine. 404 "You do not have an active subscription." 500 → show message. |
| Manage payment method / invoices | `POST /api/subscription/portal` | 200 `data.url` → open with `url_launcher` `LaunchMode.inAppBrowserView`. Refresh `/status` when the user returns. 500 → show message. |
| Renew (only while `cancel_at_period_end == true`) | `POST /api/subscription/portal` | Same as above — the user renews in the portal. (`/subscribe` returns 409 during this time.) |

Renewals are automatic; if a renewal fails, the server emails the user a payment link. Nothing to build for that.

## Feature E — Deposit (lock) — check the existing flow matches

`POST /api/stripe/create-payment-intent`

| Field | Rules |
|---|---|
| `amount` | required, number, min 1 |
| `currency` | required: `usd`, `eur` or `gbp` |
| `title` | optional, max 255 |
| `hold_period_type` | required, must be **`"custom"`** |
| `hold_start_at` | required date, today or later (user's timezone) |
| `hold_end_at` | required date, after `hold_start_at` |
| `hold_hours` / `hold_minutes` | optional ints 0–23 / 0–59 |

200 `data`: `payment_intent_id, client_secret, customer_id, ephemeral_key, publishable_key, amount, currency` → Payment Sheet → then `POST /api/stripe/confirm-payment` `{ "payment_intent_id": "pi_..." }`.
- **Never reuse an old `client_secret`**: each new intent cancels the user's earlier unpaid ones. Always create a fresh intent per attempt.
- create errors: 402 (Feature A), 503 / 500 "Failed to create payment intent…" → show message.
- confirm 200 `data.payment` + `data.hold` (hold may be `null`). Errors: 400 "Payment not completed yet. Status: …", 403 "Payment does not belong to this user.", 400 "Failed to confirm payment."

## Feature F — Withdraw

### Balance
`GET /api/payment-holds/summary` (**no `data` wrapper**):
```json
{ "success": true, "message": "Balance summary retrieved successfully.",
  "summary": { "total_balance": 25.0, "total_locked_amount": 6.0, "total_ready_for_transfer_amount": 19.0, "currency": "USD" } }
```
Show "Available to withdraw" = `total_ready_for_transfer_amount`.

### Withdraw an amount
`POST /api/payment-holds/withdraw` body `{ "amount": 7 }` — amount 0.50 to 999,999.99.
The server takes the money from several matured locks (oldest first). **It counts as one withdrawal** even when it uses several locks.

200 `data` keys: `requested_amount, processed_amount, total_transfers, transfers[], status, all_completed, partial, summary{requested,processed,difference}`.
- `partial: true` → show the server message ("Only $X of $Y could be withdrawn right now…").
- Otherwise show success with `processed_amount`, then refresh the balance and history.

| Status | Message | Do |
|---|---|---|
| 402 | subscription_required | Feature A |
| 400 | "No funds available for withdrawal. Hold period not completed yet." | Show it |
| 400 | "Insufficient available funds. Available: $X, Requested: $Y" (`data.available_amount`) | Show it; offer "Withdraw $available" |
| 400 | "No bank account found. Please add your bank details first." | Show it + button to **Add Bank Account** |
| 400 | "No holds could be processed. Please check your available balance." | Show it |
| 500 | "Unable to process withdrawal request…" | Show it |

### Single-lock payout (if the app has it)
`POST /api/payment-holds/{hold_id}/request-payout` (no body) sends that lock's whole remaining amount. 200 `data.status` is `pending`. Errors: 404 "Payment hold not found.", 400 "Payout already completed…", 400 "Payout request already exists…", 400 "Hold period not completed yet…" (`data.days_remaining/hours_remaining/minutes_remaining`), 500 generic (also happens when no bank account is set — check the bank list first).

### History
`GET /api/payment-holds?page=1&per_page=15` → `summary`, `transactions[]`, `pagination{current_page,last_page,has_more_pages,...}` (no `data` wrapper). Rows have `transaction_type` `checkout` (lock, with `hold_duration` countdown and `payment_details.can_request_payout`) or `transfer` (withdrawal). Hold statuses: `holding`, `ready_for_transfer`, `partial_transferred`, `transferred`, `canceled`.

## Feature G — Bank accounts (several per user)

### List
`GET /api/bank-account`
- none: `data: { "has_bank_account": false, "bank_accounts": [] }`
- else: `data: { "has_bank_account": true, "total": 2, "bank_accounts": [ { "id", "account_holder_name", "bank_name", "account_number": "****6789", "account_type", "country", "currency", "is_primary", "created_at" } ] }` — primary first. Show a "Primary" badge.

### Add
`POST /api/bank-account` → **201**

| Field | Rules |
|---|---|
| `dob` | required date, before today |
| `account_number` | required, 5–34 chars |
| `bank_name` | required, max 100 |
| `account_type` | `savings`, `checking` or `current` |
| `country` | **UPPERCASE** 2 letters: US, GB, CA, AU, DE, FR, IE, NL, AT, BE, ES, IT, PT, DK, FI, NO, SE, CH, NZ, SG, HK, JP |
| `currency` | **lowercase**: usd, eur, gbp, cad, aud, nzd, sgd, hkd, jpy, chf, dkk, nok, sek |
| `address_line1`, `city`, `state`, `postal_code`, `phone` | required |
| US only | `routing_number` (exactly 9 digits) + `ssn_last_4` (exactly 4) |
| non-US | `iban` (15–34) required, optional `id_number`. **Also send the IBAN as `account_number`.** |

- Build the form so US shows Routing + SSN last 4, other countries show IBAN (+ ID number). Default currency from country (US→usd, GB→gbp, euro countries→eur, …).
- 201 `data`: the new account (masked). The first account becomes primary automatically.
- 422 → field errors. 500 "Failed to setup bank account. Please try again." → show it (the bank rejected the details).

### Set primary / delete
- `POST /api/bank-account/{id}/set-primary` → 200 "Bank account set as primary." (or "…already primary."), 404 "Bank account not found."
- `DELETE /api/bank-account/{id}` → confirm dialog → 200 "Bank account removed successfully.", 404, **400 "Cannot delete bank while a withdrawal is pending."**, 500. If the primary is deleted, the oldest remaining becomes primary — reload the list.

## Feature H — Support and Feedback

Full contract: `docs/FLUTTER_SUPPORT_FEEDBACK_BRIEF.md` in the backend repo. Summary:
- `POST /api/profile/feedback` `{ "rating": 1-5, "feedback": "max 1000 chars" }` → 200 `data.feedback`.
- `POST /api/profile/support` `{ "subject": "max 150", "message": "max 2000" }` → 200 `data.support_request.id` — show it as the reference number, zero-padded to 5 digits (`00002`).
- Limit 10/min each. Server trims text and emails the user a confirmation.

## Feature I — Delete account

`POST /api/auth/delete-account` (no body, max 5 per hour).
- Before calling, show a confirm dialog: "This permanently deletes your account. Any money still locked is forfeited and your subscription is cancelled. This cannot be undone." Require typing DELETE or a second confirm.
- 200 → clear token and local data, go to the login screen, show the server message.
- 500 "Failed to delete account. Please try again." → show it.

---

## Test (Stripe test mode — the server is in test mode now)

Cards: `4242 4242 4242 4242` (works), `4000 0025 0000 3155` (3-D Secure), `4000 0000 0000 9995` (declined). Any future expiry, any CVC, any ZIP. Test bank (US): routing `110000000`, account `000123456789`, SSN last 4 `0000`.

Checklist — run on a real device or emulator, report pass/fail for each:
1. New account → 2 locks work → 3rd lock opens Plans with "No commitments. Cancel anytime." and the renew line visible without scrolling.
2. Subscribe Monthly with 4242 → plans close → the 3rd lock continues straight into its Payment Sheet.
3. Subscribe with 3155 → 3-D Secure screen → returns to the app → subscribed.
4. Subscribe with 9995 → error shown, stays on Plans, not subscribed.
5. Status hints update ("1 free lock left", hidden when subscribed).
6. Add a US bank → appears as primary, `****6789`. Add a second → set it primary → delete one.
7. Withdraw more than available → "Insufficient…" with the available amount. Withdraw a valid amount → success, balance updates.
8. Free user: 2 withdrawals → 3rd opens Plans.
9. My Subscription → Cancel → "Ends on {date}"; Manage payment method opens the portal and status refreshes on return.
10. Support + Feedback submit and show the reference/success.
11. Delete account → confirm → logged out.
12. Turn off internet mid-request → friendly error, no crash.

## iOS note

Apple rule 3.1.1 may reject subscriptions sold through Stripe for app features. Build it as described; if App Review rejects it, the backend is ready for an Apple/RevenueCat path later.

## When you finish, report

1. Features: built / already existed / changed (per feature A–I).
2. Files added and changed.
3. `flutter analyze` and `flutter test` results.
4. Checklist results (1–12) and anything not tested.
5. Assumptions you made.
