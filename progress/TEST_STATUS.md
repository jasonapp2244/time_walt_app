# TEST STATUS — Time Vault

**Last updated:** 2026-09-22

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

Everything outside the feedback feature is still unverified by tests. A green suite proves the feedback feature works and that the application boots — nothing more. For payments, holds, transfers and withdrawals, verify the real flow by hand: functionality, validation, authentication, authorization, API contract, database behaviour, error handling and edge cases.

---

## LAST RUN

**2026-09-24:** `php artisan test` → **115 passed / 307 assertions** (110 + 5 new in `tests/Feature/Stripe/TransferSourceTransactionTest.php`). Pint clean on changed files.

**Date:** 2026-09-17
**Command:** `php artisan test` / `php artisan route:list` / `vendor/bin/pint --test`
**Result:**
- `php artisan test` → **30 passed**, 95 assertions, 49.14s
- `php artisan route:list` → **63 routes**, no boot errors
- `vendor/bin/pint --test` → **FAIL**, 6 files (formatting only; every new feedback file is clean)

## FAILING TESTS

No failing tests. Pint style failures, which are not tests but are part of the verifier:

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