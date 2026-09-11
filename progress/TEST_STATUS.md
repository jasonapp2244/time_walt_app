# TEST STATUS — Time Vault

**Last updated:** 2026-09-12

## How to test this project

```
composer test; vendor/bin/pint --test
```

## Known coverage reality

5 PHPUnit test files, and every one of them is a placeholder. Four are named `example` and the fifth asserts `true is true`.

A green suite here is **not** proof a feature works — it proves the application boots. Verify the real flow as well: functionality, validation, authentication, authorization, API contract, database behaviour, frontend behaviour, error handling and edge cases.

Tests run against sqlite `:memory:` (`phpunit.xml`), never against the real `time_walt` MySQL schema, so schema drift will not be caught here.

---

## LAST RUN

**Date:** 2026-09-12
**Command:** `php artisan test` / `php artisan route:list` / `vendor/bin/pint --test`
**Result:**
- `php artisan test` → **5 passed**, 5 assertions, 8.56s
- `php artisan route:list` → **61 routes**, no boot errors
- `vendor/bin/pint --test` → **FAIL**, 6 files (formatting only)

## FAILING TESTS

No failing tests. Pint style failures, which are not tests but are part of the verifier:

| File | Fixers needed |
|---|---|
| `app/Console/Commands/FundTestBalance.php` | concat_space, unary_operator_spaces, not_operator_with_successor_space |
| `app/Http/Controllers/Api/ProfileController.php` | array_indentation, concat_space, unary_operator_spaces, no_unused_imports, not_operator_with_successor_space |
| `app/Providers/AppServiceProvider.php` | list_syntax, blank_line_before_statement |
| `routes/api.php` | ordered_imports, no_whitespace_in_blank_line |
| `routes/web.php` | no_trailing_whitespace, no_whitespace_in_blank_line |
| `tests/Feature/SendTransferCompletedNotificationTest.php` | no_unused_imports |

All six are fixed by `vendor/bin/pint`. Left unfixed deliberately: this session's scope was push + deploy, and reformatting six files would have put unrelated noise into the deploy commit.

When a test fails: find the root cause, fix the root cause, re-run the failing test, run related tests, then check for regressions. Never edit a test just to make it pass.

## MANUAL VERIFICATION LOG

- 2026-09-12 — Verified locally only: suite green, route table resolves, style check fails as above. **No production verification performed** — the deploy to `api.timevaultapp.co` has not been run from this machine (no SSH access). `GET https://api.timevaultapp.co/up` has not been checked post-deploy; record it here once it has.
