# PROJECT STATE — Time Vault

**Last updated:** 2026-09-12
**Git:** `development` and `main` both level with `origin` (see GIT below)

This file is the handover document. Read it first at the start of every session, and update it before finishing one. It must let another engineer continue tomorrow without reading any conversation history.

---

## CURRENT STATE

Laravel 12 fintech backend (Stripe Payment Sheet + Custom Connect + Transfers) with a Blade admin panel. Two deployment targets exist; the live API is the first one:

| Target | Path | Notes |
|---|---|---|
| **Production API** | `/home/timevaultapp-api/htdocs/api.timevaultapp.co` | Hostinger VPS `srv1017557`, CloudPanel. Git checkout of `origin`. This is the live backend the Flutter app talks to. |
| Test server | `/home/devonlinetestserver-timevaultapp/htdocs/timevaultapp.devonlinetestserver.com` | Documented in `deployeement.md`; that guide still names branch `stripe-sheet-and-admin-panel`, which no longer exists on origin. |

Repo: https://github.com/jasonapp2244/time_walt_app.git — branches `main` and `development` only.

Local verification baseline (run 2026-09-12):
- `php artisan test` → 5 passed / 5 assertions. All five are placeholder `example` tests; green here proves the app boots, nothing about feature correctness.
- `php artisan route:list` → 61 routes, no boot errors.
- `vendor/bin/pint --test` → **fail** on 6 files (pre-existing, cosmetic only). See KNOWN ISSUES.

## COMPLETED

- 2026-09-12 — Committed the outstanding documentation/config work and pushed it to `origin/development`; fast-forwarded `main` to the same commit and pushed. Both branches on GitHub now carry identical trees.
- Earlier (from git history): TimeVault rename across admin panel + email templates, auto-recovery from stale Stripe Connect accounts, token auto-expiry disabled, profile image upload debug logging added and removed.

## REMAINING

1. **Deploy to production** — pull the new commits on `api.timevaultapp.co`. Commands are in TODO.md. Not run from this machine: no SSH credentials for `srv1017557` are configured here, so the deploy has to be executed from the user's own root shell.
2. Fix the 6 Pint style failures (`vendor/bin/pint` fixes them all automatically).
3. Replace the 5 placeholder tests with real coverage of the payment/hold/withdrawal flow — the highest-value gap in the project.
4. Correct `deployeement.md`: it targets the retired `stripe-sheet-and-admin-panel` branch and the test server, not `api.timevaultapp.co`.

## CURRENT ERRORS / KNOWN ISSUES

- `vendor/bin/pint --test` fails on: `app/Console/Commands/FundTestBalance.php`, `app/Http/Controllers/Api/ProfileController.php` (also has unused imports), `app/Providers/AppServiceProvider.php`, `routes/api.php`, `routes/web.php`, `tests/Feature/SendTransferCompletedNotificationTest.php`. Formatting only — no behavioural impact.
- Test suite is placeholder-only (5 `example` tests). Do not treat a green suite as proof a feature works.
- Tests run against sqlite `:memory:` per `phpunit.xml`, never against the real `time_walt` MySQL schema.
- `.claude/settings.local.json.bak-20260909185555` is left untracked on purpose (editor backup artifact, not project content).

## IMPORTANT DECISIONS

- 2026-09-07 — Installed the production engineering rules and this progress folder. Rules live at the end of `CLAUDE.md`, together with a project-specific TECHNOLOGY section.
- 2026-09-08 — Added the Autonomous AI Engineering Operating System charter to `CLAUDE.md`, between the production engineering rules and the TECHNOLOGY section. Both documents are in force.
- 2026-09-12 — `main` is kept as a fast-forward of `development` rather than a merge commit, so the two branches stay byte-identical and either can be deployed safely.

## FILES CHANGED (most recent session)

- `CLAUDE.md` — +403 lines, the autonomous engineering charter
- `progress/PROJECT_STATE.md`, `progress/TODO.md`, `progress/TEST_STATUS.md`, `progress/DEPLOYMENT_STATUS.md` — filled in from the seeded templates
- `FLUTTER_API_INTEGRATION_GUIDE.md` — new, Flutter ↔ API integration reference
- `TimeVault_Complete_Postman_Collection.json` — new, full Postman collection
- `.claude/settings.local.json` — permission list updated

## EXACT NEXT TASK

Run the production deploy on `api.timevaultapp.co` (exact command block in `TODO.md` → IN PROGRESS), then confirm `GET https://api.timevaultapp.co/up` returns 200 and record the evidence in `DEPLOYMENT_STATUS.md`.
