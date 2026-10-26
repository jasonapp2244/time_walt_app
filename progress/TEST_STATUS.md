# TEST STATUS — Time Vault

**2026-10-27:** 311/1173 green again right before commit `a799a90`.

**Re-run 2026-10-26 (after the user edited `.env`):**
- **Results:** 311/1173 green, Pint pass. E2E 80/80 with new users #58–60, all 72 webhooks 200, admin pages and exports 15/15.
- **Webhook secret:** webhooks signed with the `.env` `STRIPE_WEBHOOK_SECRET` → 200; a wrong signature → 400.
- **`.env` state:** `STRIPE_KEY` is now absent; it is unused by the code (only `STRIPE_SECRET` is read). `STRIPE_WEBHOOK_SECRET` still appears twice, with identical values.

**Last updated:** 2026-10-26. Latest run: `php artisan test` → **311 passed / 1173 assertions**, `pint --test` pass, `route:list` 100 routes, `composer audit` 0.
- **New tests:**
  - `SubscriptionApiTest` (+6):
    - an unsynced paid attempt → 409;
    - a plan switch cancels the other unpaid attempt;
    - `/confirm` finds an older paid attempt;
    - `/status` hides a dead subscription;
    - a busy app is not rate-limited at Subscribe;
    - updated reuse test.
  - `AdminSubscriptionTest` (+1): no price change while a plan has subscribers.
  - `FreeTierGateTest` (+2): open deposits of any age are checked; a withdrawal that fails after Stripe paid is still counted.
  - New `tests/Feature/Auth/AuthRateLimitTest.php` (2).
- **Real Stripe test mode, through the local API** (scratch script `e2e_journeys.php`; 3 users signed up via signup → OTP → login; `stripe listen`; test clocks):
  - 80/80 checks, all 39 webhooks 200;
  - free locks 2 then 402;
  - declined card;
  - plan switch on the paywall;
  - Monthly $4.99 and Yearly $49.99 bought;
  - locks 3–5 allowed;
  - withdraw paywall 402 / lifted;
  - renewals recorded at $4.99 and $49.99;
  - cancel at period end → ends, no charge → back to 402;
  - `subscriptions:sync` repairs a missed renewal.
- **Admin panel, Playwright:** all subscription pages and user pages show matching data. Reset free usage, a free-tier limit change seen by the API, a refused price edit, cancel-now (Stripe = canceled) and all 6 exports work.
- **Not testable yet:** a real bank add / payout (the Stripe Connect settings block it).

Previous: 2026-10-23. Latest run: `php artisan test` → **301 passed / 1131 assertions**, `pint --test` pass, `route:list` 100 routes, `composer audit` 0.
- **New test files:**
  - `SubscriptionPlanSeederTest` (7): creates the 2 recurring plans; a re-run is a no-op; adopts a price by lookup key; a failure leaves drafts a re-run completes; re-links plans from another Stripe account; refuses without a key.
  - `SyncSubscriptionsCommandTest` (6).
- **New tests in existing files:**
  - `SubscriptionWebhookTest`: a late "active" event cannot undo a cancel; a price switch moves the plan.
  - `FreeTierGateTest` (+3): an open deposit is cancelled; a charged-unrecorded deposit counts; a recorded one is not double-counted.
  - `CreateTransferTest` (+2): the idempotency key is reused after an unrecorded transfer and is fresh after a rejection.
  - `WithdrawTest`: key format, `partial: true`.
  - `ListExportTest`: `=HYPERLINK(...)` is exported as text, with no `<f>` formula cell.
- **Real Stripe test-mode run on 2026-10-23** (scratch scripts driving the local API on :8020, with `stripe listen` and test clocks; not part of the suite):
  - monthly first payment + automatic month-2 renewal recorded once;
  - declined renewal → `past_due` with access kept and the issue email queued;
  - cancel → canceled at period end, no further charge;
  - yearly first payment + automatic year-2 renewal;
  - free tier: locks 1-2 OK, the abandoned deposit cancelled, lock 3 → 402, after subscribing lock 3 OK;
  - admin pages and plan exports 200.
  - **Bank add FAILED on the new account:** a Stripe platform-profile requirement, not code (see PROJECT_STATE). The withdrawal-limit E2E is still pending on it; it is covered by `FreeTierGateTest`.

Previous (2026-10-14) run: `php artisan test` → **280 passed / 1062 assertions** (111.5s), `pint --test` pass, `route:list` 100 routes. New: `tests/Feature/Stripe/CreateTransferTest.php` (14) covering `StripeService::createTransfer()` directly and through the admin panel execute, `request-payout` and cron auto-transfer callers (the admin API calls the same service method). The two stale-hold race tests and the reconciliation-log test failed before the fix (the stale model reached `POST /v1/transfers` a second time).

Previous (2026-10-13, 2nd pass) run: `php artisan test` → **266 passed / 997 assertions** (100.1s), `pint --test` pass. `BankAccountStoreTest` 12 → 15 for the orphaned-Connect-account fix: the bank-rejection test now requires `DELETE /v1/accounts/acct_new`; new tests for a wrong-country first attempt followed by a corrected retry, a failed cleanup (still the generic 500, `warning` logged with user_id + account suffix), and a bank rejected on a pre-existing Connect account (no DELETE). The two cleanup tests failed before the fix. No routes changed.

Previous (2026-10-13, 1st pass) run: `php artisan test` → **263 passed / 985 assertions** (103.4s), `pint --test` pass. New: `tests/Feature/Stripe/BankAccountStoreTest.php` (12 — first US bank creates the Custom Connect account with the right individual/dob/ssn params and becomes primary, account number encrypted at rest; details_submitted → verified; non-US sends id_number not ssn, no routing number; second bank reuses the Connect account and is not primary; stale account (404 resource_missing and 403 other-key) replaced and its banks discarded; Stripe rejecting the bank or the account rolls back both rows and leaks neither the account number nor the Stripe message; validation for US routing/ssn, non-US IBAN, unsupported country, future dob; guest 401). All passed first run — no production code changed. No routes changed.

Previous (2026-10-12) run: `php artisan test` → **251 passed / 908 assertions** (93.3s), `pint --test` pass. New: `tests/Feature/Stripe/PaymentSheetTest.php` (12 — create-payment-intent params/cents/customer reuse/validation/auth/Stripe 500; confirm-payment record + titled hold, idempotent re-confirm, unfinished, other user's PI 403, malformed id, notification settings) and `DepositWebhookTest.php` (8 — webhook after confirm sends no 2nd receipt, webhook-first keeps the title then confirm says "already recorded", failed→succeeded retry is receipted, non-deposit PI ignored, replayed event once, bad signature 400, payment_failed, canceled cancels the hold). The two regression tests failed before the fix (2 receipts; `null` title). No routes changed.

Previous (2026-10-10) run (after the dependency security update): `php artisan test` → **231 passed / 788 assertions** (91.2s) on MySQL `time_walt_test`, `pint --test` pass, `route:list` **100 routes** (+1 is the framework's signed `PUT storage/{path}` upload route, new in 12.69; an unsigned PUT returns 403), `composer audit` **0 advisories**. XAMPP MySQL must be running first: without it the suite hangs on connect instead of failing.

## How to test this project

```
composer test; vendor/bin/pint --test
```

**Prerequisite:** the suite runs against a real MySQL database, `time_walt_test`. Create it once per machine:

```sql
CREATE DATABASE time_walt_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

`phpunit.xml` overrides only `DB_CONNECTION` and `DB_DATABASE`; host, user and password come from `.env`. `RefreshDatabase` migrates and rolls back per test, so the database is disposable — but point it at `time_walt_test`, never `time_walt`.

## Why not sqlite

The suite used to run on sqlite `:memory:`. It could not work. Five migrations use raw MySQL-only SQL (`UPDATE ... alias`, `CONVERT_TZ`, ENUM `MODIFY COLUMN`), so sqlite cannot build the schema:

```
2026_01_30_184024_add_remaining_amount_to_payment_holds_table ... FAIL
SQLSTATE[HY000]: General error: 1 near "ph": syntax error
```

Any test using `RefreshDatabase` died there, which is why every pre-existing test was a placeholder. Migrations were deliberately **not** patched for sqlite compatibility — that would let the test schema drift from production.

## Known coverage reality

110 tests, 300 assertions (run 2026-09-22). Honest split:

| Area | Tests | Real? |
|---|---|---|
| `tests/Feature/Feedback/SubmitFeedbackTest.php` | 13 | Yes — API contract, validation, auth, persistence, queueing, timezone |
| `tests/Feature/Feedback/AdminFeedbackIndexTest.php` | 7 | Yes — rendering, summary maths, filtering, pagination, authorization |
| `tests/Feature/Feedback/SendFeedbackNotificationTest.php` | 5 | Yes — recipient, reply-to, skip path, failure logging, rendered body |
| `tests/Feature/Support/SubmitSupportTest.php` | 10 | Yes — API contract, validation bounds (150 / 2000), auth, persistence, queueing, timezone |
| `tests/Feature/Support/AdminSupportIndexTest.php` | 10 | Yes — rendering, today / 7-day counts in `ADMIN_TIMEZONE`, text search, blind-index email search, pagination, authorization |
| `tests/Feature/Support/SendSupportNotificationTest.php` | 7 | Yes — recipient, reply-to, mail subject, skip path, failure logging, rendered body |
| `tests/Feature/Support/UndecryptableUserNotificationTest.php` | 5 | Yes — regression: both notification mails must still reach the admin when the user's row cannot be decrypted |
| `tests/Feature/Support/SendSupportAcknowledgementTest.php` | 6 | Yes — user is the recipient, support inbox is not, reply-to, subject, rendered body, undecryptable skip, failure logging |
| `tests/Feature/Feedback/SendFeedbackAcknowledgementTest.php` | 6 | Yes — same shape for feedback, reply-to the admin inbox |
| `tests/Feature/Mail/ConfiguredRecipientsTest.php` | 6 | Yes — env fallback chain, no placeholder default, deletion-email contact line, and a guard that no template hardcodes an address |
| `tests/Feature/UndecryptableUserRenderingTest.php`, `Admin/AdminAccountFromEnvTest.php`, `CleanupUnverifiedAccountsTest.php` | remainder | Yes — added between 2026-09-17 and 2026-09-21 |
| `AppConfigTest`, `Auth/ChangePasswordTest`, `SendTransferCompletedNotificationTest`, `Feature/ExampleTest`, `Unit/ExampleTest` | 5 | **No — placeholders** |

**2026-10-06:** `tests/Feature/Stripe/WithdrawTest.php`, `VerifyPendingTransfersTest.php` and `CheckPaymentHoldsTest.php` now cover the hold → withdrawal path for real, with Stripe faked at the SDK HTTP layer (`tests/Fakes/FakeStripeHttpClient.php`). The paragraph below is superseded for those three; it still holds for Payment Sheet, webhooks, admin transfers and bank-account setup.

Everything outside the feedback feature is still unverified by tests. A green suite proves the feedback feature works and that the application boots — nothing more. For payments, holds, transfers and withdrawals, verify the real flow by hand: functionality, validation, authentication, authorization, API contract, database behaviour, error handling and edge cases.

---

## LAST RUN

**2026-10-07 (2nd pass):** `php artisan test` → **231 passed / 788 assertions**.
- New: `tests/Feature/Subscription/SubscriptionPaymentEmailTest.php` (11: recording, once-per-invoice, both recipients, subjects, rendering, user opt-out, admin copy of failures, account deletion cancels / survives Stripe outage).
- New: `tests/Feature/Admin/ListExportTest.php` (32: every list as xlsx — rows parsed with OpenSpout — and pdf, toolbar links, filters carried, admin-only, stats endpoints, plans pagination).

Real dev DB through the HTTP kernel: 9 list pages, 9 xlsx and 9 pdf downloads and the 4 new stats endpoints all 200.

Pint pass. 99 routes.
**2026-10-07:** `php artisan test` → **188 passed / 584 assertions**:
- 132 from before, plus 55 in `tests/Feature/Subscription/`: `FreeTierGateTest` 15, `SubscriptionApiTest` 14, `SubscriptionWebhookTest` 11, `AdminSubscriptionTest` 15.
- Plus 1 regression test for a damaged bank account on the admin user page.

Stripe is faked at the HTTP layer (`tests/Fakes/FakesStripe.php` + `FakeStripeHttpClient`); webhooks go through the real signature-checked endpoint.

Other checks:
- `vendor/bin/pint --test` → pass.
- `route:list` → 85 routes.
- Dev DB: migrate, rollback and re-migrate of the 4 new migrations all succeeded.
- Every new admin page plus the dashboard and a user detail page was rendered through the HTTP kernel against the dev DB → all 200, after the bank-account fix.
**2026-10-06:** `php artisan test` → **132 passed / 392 assertions** (115 + 17 new under `tests/Feature/Stripe/`: `WithdrawTest` 8, `VerifyPendingTransfersTest` 6, `CheckPaymentHoldsTest` 3). `vendor/bin/pint --test` → **pass** (the 6 old failures fixed). `route:list` → 65 routes. `WithdrawTest::test_money_already_sent_stays_recorded_when_a_later_transfer_is_rejected` was written first and failed (500, records rolled back) before the controller fix.

**2026-09-24:** `php artisan test` → **115 passed / 307 assertions** (110 + 5 new in `tests/Feature/Stripe/TransferSourceTransactionTest.php`). Pint clean on changed files.

**2026-10-03:** re-run, unchanged → **115 passed / 307 assertions** (75s). `route:list` 65 routes; `migrate:status` shows nothing pending on the dev database (the two migrations previously listed as pending have been applied).

**Date:** 2026-09-17
**Command:** `php artisan test` / `php artisan route:list` / `vendor/bin/pint --test`
**Result:**
- `php artisan test` → **30 passed**, 95 assertions, 49.14s
- `php artisan route:list` → **63 routes**, no boot errors
- `vendor/bin/pint --test` → **FAIL**, 6 files (formatting only; every new feedback file is clean)

## FAILING TESTS

No failing tests, and Pint passes as of 2026-10-06. The table below is the historical list of Pint failures, all fixed:

| File | Fixers needed |
|---|---|
| `app/Console/Commands/FundTestBalance.php` | concat_space, unary_operator_spaces, not_operator_with_successor_space |
| `app/Http/Controllers/Api/ProfileController.php` | array_indentation, concat_space, unary_operator_spaces, not_operator_with_successor_space |
| `app/Providers/AppServiceProvider.php` | list_syntax, blank_line_before_statement |
| `routes/api.php` | ordered_imports, no_whitespace_in_blank_line |
| `routes/web.php` | no_whitespace_in_blank_line |
| `tests/Feature/SendTransferCompletedNotificationTest.php` | no_unused_imports |

All six are pre-existing and fixed by `vendor/bin/pint`. Left unfixed deliberately so the feedback changeset does not carry unrelated reformatting noise.

When a test fails: find the root cause, fix the root cause, re-run the failing test, run related tests, then check for regressions. Never edit a test just to make it pass.

## MANUAL VERIFICATION LOG

- 2026-09-24 — Stripe **test mode**: created a $1,000 PaymentIntent with `pm_card_visa` (funds pending), then `Transfer::create(withSourceTransaction(...))` of $900 to a test connected account → accepted immediately (`tr_3UJ0skBFi9L3amxX1SproDig`, `source_transaction=ch_3UJ0skBFi9L3amxX1rxyQMu0`). Fallback lookup via `PaymentIntent::retrieve` resolved the same charge. The failure without `source_transaction` was not reproduced locally because the local test platform has $72k available; it was seen on production's test account ($0 available).
- 2026-09-17 — Feedback feature verified locally. `php artisan serve --port=8078`: `GET /up` → 200; `GET /admin/feedback` as a guest → 302 to `/admin/login` (no fatal); `GET /api/profile/feedback` → 405 (POST-only route resolves). Authenticated admin rendering of `/admin/feedback`, including the sidebar entry, is covered by `AdminFeedbackIndexTest`. Route middleware confirmed via `route:list -v`: `auth:sanctum` + `throttle:1000,1` + `throttle:10,1`. `feedbacks` table indexes confirmed in MySQL: PRIMARY, `feedbacks_user_id_created_at_index`, `feedbacks_rating_index`.
- 2026-09-17 — **Not verified:** real SMTP delivery of the feedback email. `Mail::fake()` proves the recipient, reply-to and body; it does not prove Gmail SMTP accepts it. `QUEUE_CONNECTION=database` locally, so a `queue:work` worker must be running for the email to leave at all.
- 2026-09-12 — Verified locally only: suite green, route table resolves, style check fails as above. **No production verification performed** — the deploy to `api.timevaultapp.co` has not been run from this machine (no SSH access). `GET https://api.timevaultapp.co/up` has not been checked post-deploy; record it here once it has.