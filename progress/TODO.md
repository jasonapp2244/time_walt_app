# TODO — Time Vault

**Last updated:** 2026-10-26

One line per item. Keep it honest: an item is only done when it has been verified working, not when it compiles.

---

## IN PROGRESS

- [ ] **BLOCKER, client: Stripe Connect platform profile.**
  - On the new account Stripe refuses to create Custom Connect accounts with: "Please review the responsibilities of collecting requirements for connected accounts at https://dashboard.stripe.com/settings/connect/platform-profile".
  - Until the client completes that page (test mode, then live), no user can add a bank, so **no withdrawal works**.
  - Then re-test the bank add (routing 110000000 / account 000123456789) and 2 withdrawals → the 3rd returns 402.
  - Re-checked 2026-10-24 and 2026-10-26: still refused. Countries: USA only (the app is USD/US-bank only; non-US needs a code change).
  - 2026-10-26: this account ALSO blocks Accounts v1 creation ("Stripe no longer recommends Accounts v1…"). The client must enable **Accounts v1 support** at `dashboard.stripe.com/test/settings/developers/api-policies` (then live), or the app moves to Accounts v2 (a bigger code change).
- [ ] **Commit the 2026-10-26 pre-go-live fixes** (double charge, rate limits, free-tier edges, admin price lock, status shape), when the user says so. Then deploy `main`.
- [ ] Known, low priority (from review 2026-10-26):
  - a "Renewals" count includes `subscription_update` invoices;
  - a Stripe failure in the middle of a plan sync can orphan a Product or Price;
  - `subscriptions:sync` keeps retrying rows that no longer exist in Stripe;
  - the template's PerfectScrollbar console error on every admin page.
- [ ] **Client: Stripe Dashboard edits did not save** (read back over the API on 2026-10-23, again on 2026-10-26: unchanged, so they were made in live mode or a sandbox):
  - Customer portal: privacy `https://timevaultapp.co/privacy-policy/`, terms `https://timevaultapp.co/terms-conditions/`, redirect `https://timevaultapp.co/`, "switch plans" off;
  - Public details: name, support email, website;
  - Billing: Smart Retries → cancel, 3-D Secure emails, renewal reminders;
  - archive `one_month`.
- [ ] **Server: switch `.env` to the new Stripe keys, deploy, seed the plans:** `php artisan db:seed --class=SubscriptionPlanSeeder --force`. Commands are in PROJECT_STATE → EXACT NEXT TASK.
- [ ] **Client decision, recommended:** the new account offers redirect and pay-later methods (Klarna, Affirm, Afterpay, Cash App, Amazon Pay…) in the Payment Sheet.
  - For deposits that are later withdrawn to a bank, cards + Apple Pay + Google Pay only is safer.
  - Turn the others off in Settings → Payment methods. If any stay on, the Flutter Payment Sheet needs a `returnURL`.
- [x] ~~Client plans~~: Monthly $4.99 + Yearly $49.99, auto-renewing, via `SubscriptionPlanSeeder` (2026-10-23).
  - Verified with real Stripe test-mode renewals (test clocks) through the local API.
  - Created on the new account with lookup keys `time_vault_monthly` / `time_vault_yearly`.
- [x] ~~Commit the 2026-10-06…23 work~~: committed and pushed on 2026-10-23.
- [x] ~~New Stripe account (2026-10-23): "The Gold Rose Agency llc", `acct_1TLqQzA6wBqKEqYq`, test mode~~
  - The local `.env` was switched on 2026-10-23.
  - Original note: the client sent its keys; the local `.env` is still on the old account (`acct_…TP4eCBFi9L3amxX`), so existing customers, Connect accounts and holds in the DB will not resolve on the new one. Checked over the API (read-only): `sk` + `pk` valid, `transfers` active, webhook endpoint `https://api.timevaultapp.co/api/stripe/webhook` enabled with all 6 subscription events + 3 `payment_intent.*` (version `2026-03-25.dahlia`; no field our handlers read changed vs clover), Customer Portal configured, card/Apple Pay/Google Pay on. `rk_test` cannot read Connect accounts, so it must not be used as `STRIPE_SECRET`. Still to do in the Dashboard: turn portal plan switching off, add portal privacy/terms links, fill Public details (business name, support email, URL), confirm Smart Retries → cancel and 3-D Secure emails (not readable by API). **Unverified:** whether this new platform may create `type=custom` accounts (the client's one connected account is Express-dashboard, created 2026-10-23). Test one bank add in test mode after switching keys. The `whsec_` secret can only be confirmed by a 200 delivery in the Dashboard after it is in the server `.env`. A hand-made product `one_month` ($4.99/mo) is not linked to any admin plan; create the plan in `/admin/subscription-plans` instead and archive it.

- [ ] **Commit + deploy the 2026-10-12 deposit-webhook fixes** (`WebhookService::handlePaymentIntentSucceeded`, `StripeService::getHoldPeriodData`) and the 20 new tests (`tests/Feature/Stripe/PaymentSheetTest.php`, `DepositWebhookTest.php`). Fixes every deposit sending the user and the admin **two** receipts, and the lock title being lost when the webhook beats `/confirm-payment`. No migration. Verified locally: 251 tests, Pint pass.
- [x] ~~Bank-account Connect creation untested~~ — 12 tests in `tests/Feature/Stripe/BankAccountStoreTest.php` (2026-10-13), uncommitted; commit with the 2026-10-12 work.
- [x] ~~Orphaned Connect account on a failed first bank add~~ — fixed 2026-10-13 (2nd pass): `BankAccountController@store` deletes the Custom account it created if the request then fails. 3 new tests. Uncommitted; commit with the 2026-10-12/13 work.
- [x] ~~`StripeService::createTransfer()` untested~~ — 14 tests in `tests/Feature/Stripe/CreateTransferTest.php` (2026-10-14), which found and fixed a double-payout race (stale hold model, no lock). Uncommitted; commit with the 2026-10-12/13 work.
- [ ] **Still untested money path:** the dead Checkout code in `StripeController` (`verifyPayment`, `handlePaymentReturn`, `checkout.session.completed` handler — no routes point at the first two; decide whether to delete them).
- [ ] **Commit + deploy the 2026-10-07 second pass:**
  - subscription payment emails (user + admin), `subscription_invoices` table (5th migration);
  - account deletion cancels the Stripe subscription;
  - Excel/PDF export, pagination and auto-refresh on all 9 admin lists.
  - Verified locally: 231 tests, every list and export 200 on the dev DB.
- [ ] **Send one real test email pair** (subscription receipt + admin notice) over SMTP before go-live. All mail is faked in tests; nothing real was sent. Needs `ADMIN_EMAIL` to be a real inbox.
- [ ] **Commit + deploy the 2026-10-10 dependency security update** (`composer.json` constraints unchanged, `composer.lock` only). `composer audit` 46 advisories / 15 packages → **0**: laravel/framework 12.45.2→12.69.3, guzzle 7.10.0→7.15.5, psr7 2.8.0→2.13.1, commonmark 2.8.0→2.10.3, flysystem 3.30.2→3.36.0, symfony/* 7.4.x patch releases, psysh, phpunit 11.5.57. Verified: 231 tests green, Pint pass, `/up` 200. On deploy, `deploy.sh` runs `composer install --no-dev` from the lock, so nothing else is needed. Majors deliberately not taken: stripe-php 19→22, tinker 2→3, boost 1→2.
- [ ] **Commit + deploy the subscription / free-tier feature (2026-10-07).** Built and verified locally: 188 tests, Pint clean, 85 routes, admin pages 200 on the dev DB. Uncommitted. Plan and decisions: `docs/SUBSCRIPTION_PLAN.md`.
- [ ] **Stripe Dashboard setup for subscriptions**, test mode then live: webhook events, Smart Retries → cancel, 3-D Secure emails, Customer Portal. Checklist in `docs/SUBSCRIPTION_PLAN.md` §6. Needs the client's Stripe login.
- [ ] **Create the first plan** at `/admin/subscription-plans`. Until one exists, users who hit the limit see an empty plans screen.
- [ ] **Client sign-off on the three decisions** in `docs/SUBSCRIPTION_PLAN.md` (Stripe on iOS vs Apple IAP, gating withdrawals, lifetime limit).
- [ ] **Flutter:** build from `docs/FLUTTER_SUBSCRIPTION_BRIEF.md` (other repo).
- [ ] **Flutter: show "No commitment. Cancel anytime." on the plans screen (paywall after the 2 free locks).** Client request 2026-10-13. Copy and placement in `docs/FLUTTER_SUBSCRIPTION_BRIEF.md` §2; no backend change needed.
- [ ] **Commit + deploy the 2026-10-06 withdrawal double-payout fix** (`PaymentHoldController::withdraw()`), the 17 money-path tests and the Pint reformat. Uncommitted on `development`. Verified locally: 132 tests green, Pint clean.
- [ ] **Deploy the withdrawal `source_transaction` fix (2026-09-24, committed `68d4a3c` on `development`, pushed; `main` one commit behind).** Verified locally in Stripe test mode; not yet on production. After deploy: deposit with 4242 and withdraw immediately; it must succeed.
- [x] ~~**Install the production queue worker.**~~ Done 2026-09-22. systemd service
      `timevault-queue`, installed by hand rather than via the script (the branch carrying
      `deploy/` is still unpushed). Verified on the server: `active (running)`, `enabled`
      at boot, `User=timevaultapp-api` (not root), PID 1993466, and still `active` after
      `php artisan queue:restart` — so `deploy.sh` cannot orphan it.
- [x] ~~**Install the scheduler cron.**~~ Done 2026-09-22. `* * * * * cd <app> && /usr/bin/php
      artisan schedule:run` in **`timevaultapp-api`'s** crontab, not root's. `schedule:run`
      exits 0 and `schedule:list` shows both money-path commands.
- [ ] **Confirm the cron daemon itself is running** (`systemctl is-active cron`). The crontab
      entry is verified present, but an entry does nothing if the daemon is stopped — the same
      silent-failure shape as the missing worker. Also still unconfirmed: `jobs`/`failed_jobs`
      counts on production, and one real app submission arriving by email with no command run.

- [ ] **Deploy the current `main` to production.** The support feature IS already live there — probing `POST /api/profile/support` from outside returns 401 (route exists), not 404 — so `d8e9c8a` or later was deployed at some point on 2026-09-22. The exact SHA on the server has not been read; check with `git -C <app> log --oneline -1`. Still to ship: the `deploy.sh` queue check and the `deploy/` scripts (4 commits, unpushed). Run `./deploy.sh --branch main` after pushing, and remove the stray `deploy.sh.replaced-*` file first.
- [ ] **Hand the Postman collection to whoever tests the API.** `TimeVault_Complete_Postman_Collection.json` plus one of the two environment files. Import both, pick the environment, run Signup → Verify OTP (or Login); the token is captured automatically.

- [ ] **Update the stored privacy policy contact address.** The active row still reads `support@timevaultapp.com` (`.com`, not `.co`) from the original seed. `PrivacyPolicySeeder` now pulls from `SUPPORT_EMAIL`, but it only runs on a fresh seed — fix the live row at `/admin/privacy-policy`.
- [ ] **Check that `admin@timevaultapp.co` and `support@timevaultapp.co` receive mail.** Sending is verified; mailbox existence on that domain is not. A bounce lands in `MAIL_USERNAME` (`tauseefchoohan0401@gmail.com`).
- [ ] **Set `ADMIN_EMAIL` and `SUPPORT_EMAIL` in the production `.env`** before deploying. Missing keys mean the jobs log a warning and send nothing — deploy will still look green.
- [ ] **Point the Flutter support screen at `POST /api/profile/support`.** Body: `subject` (max 150) + `message` (max 2000), Sanctum bearer token, `throttle:10,1`. Success returns `data.support_request`. No client calls it yet.
- [ ] **Push `development` and `main`.** Superseded in part: feedback and support are committed, merged and on origin as of `e25dc2a`, and the API probe shows support live in production. What is *not* pushed is the 2026-09-22 tooling work — `f252a74`, `cdf24b7`, `b772e70`, `2f24292` (deploy.sh queue check, `deploy/` scripts, Postman collection). `main` is still at `e25dc2a`.
- [ ] **Set `APP_ENV=production` in the production `.env`**, then `php artisan config:cache`. Beyond the usual reasons, `local` breaks the deploy script's own error gate: it greps for `production.ERROR` and the log says `local.ERROR`, so it reports 0 errors unconditionally.
- [ ] **Fix the nightly `CleanupUnverifiedAccounts` failure** — user 1 is undecryptable under the current `APP_KEY` (`The MAC is invalid.`). See `DEPLOYMENT_STATUS.md` -> FIRST SUCCESSFUL DEPLOY.
- [x] ~~Diagnose `verify:pending-transfers` exit code 1~~ — resolved. `Table 'time-vault-app-db.transfers' doesn't exist`, confined to 2026-09-14 22:20-22:50, nothing since. Production DB is `time-vault-app-db`. See `DEPLOYMENT_STATUS.md` -> finding 3.

- [ ] **Deploy the pushed commits to production** (`api.timevaultapp.co`). Must be run from the VPS shell — no SSH credentials for `srv1017557` exist on the dev machine. Both `main` and `development` point at the same commit, so either branch is safe to deploy.

**One command, from the server root shell:**

```bash
curl -fsSL https://raw.githubusercontent.com/jasonapp2244/time_walt_app/main/deploy.sh -o /tmp/tv-deploy.sh && APP_DIR=/home/timevaultapp-api/htdocs/api.timevaultapp.co bash /tmp/tv-deploy.sh
```

Every deploy after that one is just:

```bash
cd /home/timevaultapp-api/htdocs/api.timevaultapp.co && ./deploy.sh
```

`deploy.sh` lives in the repo root. It is idempotent and re-runnable, and it does, in order: preflight checks (`.env`, `APP_KEY`, `APP_ENV`, `APP_DEBUG`, site owner) → refuses to run if the server's working tree is dirty → records the current SHA as the rollback point → fetches and fast-forwards → `composer install --no-dev --optimize-autoloader` → backs the database up with `mysqldump` **only if the release adds migrations**, then `migrate --force` **only if something is pending** → rebuilds config/route/view/event caches → fixes `storage` + `bootstrap/cache` ownership → `queue:restart` → leaves maintenance mode → polls `/up` five times for a 200, printing the log tail and the rollback command if it never gets one.

Other modes:

```bash
./deploy.sh --dry-run        # print every step, change nothing
./deploy.sh --branch main    # deploy a specific branch
./deploy.sh --rollback       # return to the SHA recorded by the last deploy
```

> The script never runs `key:generate` (`APP_KEY` decrypts `email`, `phone`, `full_name` at rest — regenerating it makes every existing row throw `DecryptException: The MAC is invalid`), never runs `migrate:fresh` / `migrate:refresh` / `migrate:reset` / `db:wipe`, never installs dev dependencies, and never discards uncommitted work it finds on the server — it stops and shows you instead.

## NEXT UP

- [ ] **Create `time_walt_test` on every machine and CI runner that runs the suite.** `phpunit.xml` no longer uses sqlite — it could never build this schema. `CREATE DATABASE time_walt_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;` Details and the failing sqlite output are in `TEST_STATUS.md`.
- [ ] **Confirm a queue worker runs on production.** `QUEUE_CONNECTION=database`, so with no `queue:work` the feedback AND support notifications are written to `jobs` and never sent. Still unverified on the server.
- [x] ~~Verify mail delivery over real SMTP end to end~~ — done 2026-09-22 locally, for all four mails: support → `SUPPORT_EMAIL`, feedback → `ADMIN_EMAIL`, and a confirmation back to the submitting user for each. `queue:work` drained all four, `failed_jobs` stayed at 0.

- [ ] Record the deploy evidence (`/up` status, log tail, server-side SHA) in `DEPLOYMENT_STATUS.md`.
- [x] ~~**Local database is 2 migrations behind**~~ (2026-10-03: `migrate:status` shows none pending locally; production still unconfirmed) — `2026_05_22_000001_add_bank_account_id_to_transfers_table` and `2026_05_25_000001_convert_timestamps_from_et_to_utc` show as Pending on the dev machine. Confirm whether production has them; if not, the first `./deploy.sh` run will apply them (and will take a `mysqldump` first).
- [x] ~~Run `vendor/bin/pint` to fix the 6 style failures~~ — done 2026-10-06, suite re-run green.
- [x] ~~Rewrite `deployeement.md`~~ — deleted on 2026-09-22 instead. It targeted a branch that no longer exists and a server that is not the live one, and `deploy.sh` supersedes it. Recoverable from git history if ever needed.

## BACKLOG

- [x] ~~Real test coverage for the hold → withdrawal flow~~ — done 2026-10-06 (`tests/Feature/Stripe/`).
- [x] ~~Partial-withdrawal `remaining_amount` accounting + failed-transfer restore path~~ — done 2026-10-06.
- [ ] Tests for the rest of the Stripe surface: Payment Sheet creation, webhooks (`WebhookService`), `StripeService::createTransfer()` (admin/cron), bank-account Connect creation. `tests/Fakes/FakeStripeHttpClient.php` is the seam to reuse.
- [ ] Tell the Flutter team to compare `processed_amount` with `requested_amount` on a withdrawal response — a mid-withdrawal Stripe rejection now returns 200 with a shortfall instead of a 500.
- [ ] Decide whether `.claude/settings.local.json` should stay tracked — it is machine-local permission state and creates a diff on every machine that opens the repo.

## BLOCKED (and what unblocks it)

- **Running the deploy from the dev machine** — blocked on SSH access. `~/.ssh/config` has no entry for `srv1017557` / `api.timevaultapp.co`; the only configured host is `emp-ionos`. Unblocked by adding a host entry + key, or by the user running the one-liner above.

## DONE

- [x] 2026-09-18 — Merged `feature/user-feedback` into `development` and `main` (fast-forward, both at `bff4900`). Not pushed — no non-interactive GitHub credentials on this machine.
- [x] 2026-09-17 — **First production deploy to complete.** `1c15798` -> `28087e6`, `/up` 200. Docs/tooling only; no runtime change and no migrations.

- [x] 2026-09-17 — Feedback feature reviewed end to end (12 files); 9 defects fixed, including a missing rate limit that let one user trigger 1000 admin emails/minute
- [x] 2026-09-17 — Test harness moved from sqlite `:memory:` to MySQL `time_walt_test`; sqlite could never run this schema, which is why every prior test was a placeholder
- [x] 2026-09-17 — 25 feature tests added for the feedback API, admin page and notification job (suite: 30 passed / 95 assertions)

- [x] 2026-09-12 — `deploy.sh` added: one-command, idempotent production deploy with dry-run, rollback and health gate
- [x] 2026-09-12 — Committed the outstanding docs/config work and pushed `development` to origin
- [x] 2026-09-12 — Fast-forwarded `main` to `development` and pushed; both branches identical on GitHub
- [x] 2026-09-12 — Filled in all four `progress/` handover files from their seeded templates
- [x] 2026-09-07 — Production engineering rules installed in `CLAUDE.md`, `progress/` folder created

---

## FIRST PRODUCTION RUN — 2026-09-12, findings

The first `./deploy.sh` on `api.timevaultapp.co` aborted in preflight. Three issues, all now fixed in the script:

1. **`fatal: detected dubious ownership`** — the deploy was run as `root` against a repo owned by `timevaultapp-api`. Fixed by re-exec'ing as the site owner instead of suppressing the warning, which also stops `composer`/`artisan` leaving root-owned files in `vendor/` and `bootstrap/cache`.
2. **Failure banner printed twice** — the `ERR` trap re-entered `die()`. Single-fire guard added.
3. **Untracked `deploy.sh` in the repo root would have aborted the merge** — the bootstrap now writes to `/tmp` instead, and the script moves any conflicting untracked file aside to `<name>.replaced-<timestamp>`.

**Still open, and NOT a deploy problem — `APP_ENV=local` in the production `.env`.** The live API at `api.timevaultapp.co` is running with `APP_ENV=local`. `APP_DEBUG` is correctly `false`, so stack traces are not exposed, but `local` changes error rendering and framework/package behaviour on a payment-handling API. This is a `.env` edit, deliberately not automated — change it on the server, then run `php artisan config:cache` and re-verify `/up`.

### Run 2 — 2026-09-12

Re-exec as the site owner and the `safe.directory` registration both worked. The run then stopped on 11 tracked `.gitignore` files under `storage/` and `bootstrap/cache` showing as modified — their working copies on the server carry CRLF while the stored blobs are LF.

Fixed by classifying dirt rather than ignoring it: each changed file is compared to HEAD with CR stripped from both sides. Identical means line endings only — a checkout artifact — and the file is restored from HEAD. Anything else is a real edit, and the deploy still stops and now prints the diff. Candidates come from both `git status` and `git diff HEAD`, because those two disagree in exactly this case.

Both paths were verified locally by converting a tracked file to CRLF.

### Run 3 — 2026-09-12

Line-ending classification worked: all 11 `.gitignore` files were correctly identified as checkout artifacts. The run then died on `fatal: Unable to create '.git/index.lock': Permission denied` — the checkout's internals are owned by `root` (from whenever it was first cloned or last touched as root), so the site user cannot write the git index.

Fixed by repairing ownership **before** dropping privileges: while still root, the script counts paths under the app directory not owned by the site user and, if there are any, `chown -R`s the whole directory to the site owner. A single root-owned file inside `.git` is enough to break git for the site user, and root-owned files in `vendor/` break php-fpm later.

Also added a preflight write check on `.git`, so running the script directly as the site user against a root-owned checkout fails immediately with instructions instead of halfway through.
