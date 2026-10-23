# DEPLOYMENT STATUS — Time Vault

**Last updated:** 2026-10-23: the release is committed and pushed and switches to the client's Stripe account (see PENDING RELEASE). Previous: 2026-10-14 — pending release also carries the `StripeService::createTransfer()` double-payout lock fix (code only, no migration, no `.env` change; after deploy, any `Stripe transfer was sent but could not be recorded` log line is a real payout with no DB row and must be reconciled by hand). 2026-10-13 — pending release also carries the `BankAccountController@store` orphaned-Connect-account fix (code only, no migration, no `.env` change). Previous: 2026-10-12

> **2026-10-12 — deposit-webhook fix, not deployed.** Code-only (no migration, no `.env`, no
> package change). After deploy: one test-mode deposit should produce exactly **one**
> "payment successful" email to the user and one to `ADMIN_EMAIL` (the code before this fix sends two of each when the webhook is configured; not observed on production from this machine).

> **2026-10-10 — security dependency update, not deployed.** `composer.lock` now clears all
> 46 `composer audit` advisories (laravel/framework 12.69.3, guzzle 7.15.5, commonmark 2.10.3, …).
> It ships with the next `./deploy.sh` (it runs `composer install --no-dev` from the lock). The lock
> was resolved on PHP 8.2.12 — the `^8.2` floor — so it installs on any newer production PHP.
> After deploy: `composer audit --no-dev` on the server should report 0.

> **2026-09-22 — production email outage, resolved.** Support and feedback submissions
> returned success but sent no mail, because no queue worker had ever been running on
> `api.timevaultapp.co`. Draining the queue by hand delivered all four pending emails.
> The permanent fix is `deploy/install-queue-worker.sh` (systemd service `timevault-queue`),
> **which still has to be run once on the server** — until then, mail only sends when
> someone runs `php artisan queue:work` manually. `deploy.sh` now fails loudly instead of
> quietly if no worker is up.
>
> **Resolved on the server the same day.** `timevault-queue` is installed and verified:
> `active (running)`, `enabled` at boot, `User=timevaultapp-api` (never root, which would
> leave root-owned files PHP-FPM cannot write), and it comes back `active` after
> `queue:restart`, so a deploy cannot orphan it. The scheduler cron was missing too and was
> installed in the same user's crontab — without it `check:payment-holds` never flips a
> matured hold to `ready_for_transfer`, which strands a user's money rather than an email.
> `schedule:run` exits 0 and both money-path commands appear in `schedule:list`.
>
> Still unconfirmed: that the **cron daemon** is running (an entry in a crontab does nothing
> if `cron` is stopped), the production `jobs`/`failed_jobs` counts, and one real app
> submission delivering email with no command run. Until that last one, automatic delivery
> is inferred, not proven.

A command completing is not a successful deployment. Nothing goes in the VERIFIED column below without evidence.

---

## ENVIRONMENTS

| Environment | URL / target | Last deployed | Verified |
|---|---|---|---|
| Local | `http://127.0.0.1:8000` (XAMPP, db `time_walt`; tests use `time_walt_test`) | 2026-09-22 | Yes — 89 tests pass, 65 routes resolve, `/up` 200, `/admin/feedback` and `/admin/support` render for an admin and 302 to login for everyone else, and a real support email was delivered over Gmail SMTP |
| Production API | `https://api.timevaultapp.co` → `/home/timevaultapp-api/htdocs/api.timevaultapp.co` on VPS `srv1017557` (Hostinger CloudPanel) | 2026-09-17, `1c15798` → `28087e6` | Partial — `/up` 200, but see FIRST SUCCESSFUL DEPLOY below |
| Test server | `https://timevaultapp.devonlinetestserver.com` → `/home/devonlinetestserver-timevaultapp/htdocs/...` | unknown | No |

## PENDING RELEASE

- 2026-10-23: **everything below is now committed and pushed** (`development`, and `main` fast-forwarded).
  - **New in this release:**
    - client plans seeder;
    - `subscriptions:sync` (hourly; the server's existing `schedule:run` cron picks it up);
    - the security fixes (export formula injection, free-lock bypass, payout idempotency keys, webhook ordering).
  - **Server `.env` must change to the client's Stripe account before or with the deploy:**
    - `STRIPE_KEY` / `STRIPE_SECRET` (sk_test), `STRIPE_PUBLISHABLE_KEY` (pk_test), `STRIPE_WEBHOOK_SECRET` (the `whsec_` of the endpoint `https://api.timevaultapp.co/api/stripe/webhook` on that account);
    - `STRIPE_PORTAL_RETURN_URL=https://timevaultapp.co/`;
    - keep `STRIPE_AUTO_TRANSFER` off.
  - **After deploy:** run `php artisan db:seed --class=SubscriptionPlanSeeder --force`.
  - **Withdrawals stay broken on the new account** until the client completes Connect → Platform profile in the Stripe Dashboard.

- 2026-10-07 (2nd pass) — **5th migration** `create_subscription_invoices_table`.
  - **Composer adds 7 packages** (barryvdh/laravel-dompdf, dompdf/*, openspout/openspout, masterminds/html5, sabberworm/php-css-parser); they need the PHP extensions `zip`, `gd`, `dom` and `mbstring` on the server.
  - **Check before the deploy:** if `composer install` on the server refuses because of the existing advisories (Composer 2.9 block-insecure), stop and decide on the security update rather than turning the check off silently.
  - **No new `.env` keys.**
  - **Uncommitted.**
- 2026-10-07 — **subscription + free tier.** **5 migrations** (`users` counters, `subscription_plans`, `subscriptions`, `app_settings`, `subscription_invoices`), so `deploy.sh` will take a `mysqldump`.
  - **Optional `.env` keys:** `FREE_LOCK_LIMIT`, `FREE_WITHDRAW_LIMIT`, `WITHDRAW_LIMIT_ENABLED` (defaults 2 / 2 / true) and `STRIPE_PORTAL_RETURN_URL`.
  - **Before announcing it:**
    - add the 6 webhook events in **live** mode;
    - configure the Customer Portal and Smart Retries;
    - create at least one plan in the admin panel.
  - **Once the deploy finishes**, the free limit applies to every user straight away. Everyone starts with 0 used.
  - **Uncommitted.**
- 2026-10-06 — withdrawal double-payout fix in `PaymentHoldController::withdraw()` + 17 tests + Pint reformat (code only, no migration, no `.env` change). **Uncommitted.** Ship it with the `source_transaction` fix below.
- 2026-09-24 — withdrawal `source_transaction` fix (code only, no migration, no `.env` change). Not deployed.

Two tranches now sit between production and `main`.

### Tranche 1 — pushed 2026-09-12, not yet pulled

- Autonomous engineering charter added to `CLAUDE.md` (+403 lines)
- `progress/` handover files filled in
- `FLUTTER_API_INTEGRATION_GUIDE.md` added
- `TimeVault_Complete_Postman_Collection.json` added
- `.claude/settings.local.json` permission list updated

**Risk: documentation and tooling only.** No runtime change.

### Tranche 3 — support feature, 2026-09-22, NOT YET COMMITTED

User support requests: `POST /api/profile/support`, a read-only `/admin/support` page, and a queued admin email to the same `ADMIN_EMAIL` the feedback notification uses. See `PROJECT_STATE.md` → FILES CHANGED.

**Risk: same shape as tranche 2, plus one bug fix.**

1. **A second migration runs.** `2026_09_22_000001_create_support_requests_table` — create-only, additive, alters nothing existing. `deploy.sh` will detect it and `mysqldump` first.
2. **A second queued job.** `SendSupportNotification` on the same `database` queue. No worker on the server means support emails pile up in `jobs` exactly as feedback ones would.
3. **Two recipient keys now, not one.** `ADMIN_EMAIL=admin@timevaultapp.co` receives feedback; `SUPPORT_EMAIL=support@timevaultapp.co` receives support requests. `SUPPORT_EMAIL` falls back to `ADMIN_EMAIL` if unset, but `ADMIN_EMAIL` has no fallback — with neither key set, both jobs log a warning and send nothing, and the deploy still reports success.
   Each submission also sends a confirmation to the user who wrote in, so a production submission now produces **two** emails per feature, not one.
   As of the third session neither key has a built-in default. Previously a missing `ADMIN_EMAIL` meant mail went to `admin@example.com`; now it means no mail at all, with a warning in the log. Check `storage/logs/laravel.log` for `notification skipped` after deploy.
4. **Carries a fix for a live defect:** both notification mailables read the encrypted `email` / `full_name` columns unguarded, so a user row written under an older `APP_KEY` made the queued job throw and the admin email was never sent. Whether production has such rows is unconfirmed — locally 32 of 33 users are affected. After deploy, check `failed_jobs` for `SendFeedbackNotification` entries carrying `The MAC is invalid`; they are that bug.

### Tranche 2 — feedback feature, committed `bff4900`, NOT YET PUSHED OR DEPLOYED

Merged locally into `development` and `main` on 2026-09-18, but `origin` is still at `28087e6` and so is production. **The 2026-09-17 deploy did not include any of this.** See `PROJECT_STATE.md` → FILES CHANGED. It adds: a user feedback API endpoint, an admin feedback page, a queued admin notification email, and one migration.

**Risk: this release is no longer docs-only.** Three things change on deploy:

1. **A migration runs.** `2026_09_17_000001_create_feedbacks_table` creates a new table. It only creates — it alters nothing existing — so it is additive and safe, but `deploy.sh` will correctly detect a pending migration and take a `mysqldump` first. Confirm that backup actually ran before `migrate --force`.
2. **A new queued job.** `SendFeedbackNotification` goes onto the `database` queue. If no Supervisor worker is running on production, feedback emails silently pile up in `jobs` and never send. Verify workers after `queue:restart`.
3. **`ADMIN_EMAIL` must be set in the production `.env`.** Without it the job logs a warning and skips — no email, no error. `config/mail.php` defaults it to `admin@example.com`, which would be worse than skipping.

`phpunit.xml` also changed (sqlite → MySQL `time_walt_test`). That affects CI only, never runtime, but any CI runner will fail until that database exists.

Deploy command block: `progress/TODO.md` → IN PROGRESS.

## POST-DEPLOY CHECKLIST (tick only with evidence)

- [ ] Build succeeded (`composer install --no-dev` exits 0)
- [ ] Environment / config values correct for the target (`APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` **untouched**)
- [ ] `mysqldump` backup taken **before** migrating (tranche 2 adds a migration — check the deploy log for it)
- [ ] Migrations ran and schema matches expectations (`php artisan migrate:status` — expect `create_feedbacks_table` AND `create_support_requests_table` to go from Pending to Ran)
- [ ] `ADMIN_EMAIL` and `SUPPORT_EMAIL` are set in the production `.env` (otherwise the notifications are skipped silently)
- [ ] Submit one feedback from the app and confirm the row lands in `feedbacks` and the admin email arrives
- [ ] Submit one support request and confirm the row lands in `support_requests`, `/admin/support` lists it, the support inbox gets the notification, and the submitting user gets the confirmation
- [ ] `failed_jobs` is empty after both submissions (a `DecryptException` there means the mail hardening did not deploy)
- [ ] Logs clean (`tail -n 50 storage/logs/laravel.log`, no new errors after deploy)
- [ ] Health check responds (`curl -i https://api.timevaultapp.co/up` → 200)
- [ ] API reachable and returning the expected contract
- [ ] Admin panel loads and login works
- [ ] Background workers / scheduled jobs running (`php artisan queue:restart` issued; confirm Supervisor workers came back)

## ROLLBACK PLAN

Tranche 1 is docs-only. Tranche 2 adds a table, so rollback has one extra consideration: `git checkout <previous-sha>` reverts the code but leaves `feedbacks` in place. That is harmless — an orphan table nothing queries — so **do not drop it during a rollback**; any feedback already submitted lives there. Drop it only in a deliberate, separate step once you are sure the feature is not coming back.

Otherwise rollback is a checkout of the previous SHA — no data migration to reverse.

```bash
cd /home/timevaultapp-api/htdocs/api.timevaultapp.co
git log --oneline -5                 # note the SHA that was live before the pull
git checkout <previous-sha>
composer install --no-dev --optimize-autoloader --no-interaction
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
curl -i https://api.timevaultapp.co/up
```

Record the pre-deploy SHA **before** pulling — that is the only thing that makes this plan executable.

---

## FIRST SUCCESSFUL DEPLOY — 2026-09-17

`1c15798` → `28087e6`, tranche 1 only. Ran as `timevaultapp-api` after repairing 7774 root-owned paths. `composer` had nothing to install, no migrations pending, caches rebuilt, `queue:restart` signalled, `/up` → 200 on attempt 1. The ownership, line-ending and `index.lock` fixes from runs 1-3 all held.

**It did not ship the feedback feature.** That work was merged locally but never pushed, so `origin/main` was still `28087e6` when this ran.

### Three things this run exposed

1. **`APP_ENV=local` makes the deploy's own error gate blind.** The script's final line reports `log errors  0 lines match production.ERROR`. With `APP_ENV=local`, Laravel writes `local.ERROR`, so that grep can never match and the gate reports zero no matter how bad the log is. The log in fact contains recurring errors (below). Fixing `APP_ENV=production` fixes the gate as a side effect. Preflight already warns about this on every run.

2. **`CleanupUnverifiedAccounts` has been failing daily since at least 2026-09-15.**
   ```
   local.ERROR: Failed to delete unverified account {"user_id":1,"error":"The MAC is invalid."}
   ```
   `app/Console/Commands/CleanupUnverifiedAccounts.php:57` logs `$account->email` *before* calling `delete()`. Reading that attribute decrypts it, and user 1's encrypted fields were written under a different `APP_KEY` than the one now in production. The read throws, the catch logs, and the account is never deleted — so it retries every night forever. Two ways out, both decisions for the owner: delete user row 1 directly in SQL (it is an unverified account older than 24h, so almost certainly a stale test row), or make the cleanup command tolerate undecryptable rows. Do **not** "fix" this by regenerating `APP_KEY` — that would break every other encrypted row.

3. **`verify:pending-transfers` and `check:payment-holds` failed on 2026-09-14 only — RESOLVED, not an open issue.**
   ```
   SQLSTATE[42S02]: Base table or view not found: 1146
   Table 'time-vault-app-db.transfers' doesn't exist
   Database: time-vault-app-db
   ```
   Every occurrence falls between 22:20 and 22:50 on 2026-09-14 and nothing since, so both scheduled commands recovered on their own. During that window the app was pointed at a database in which `transfers` and `payment_holds` did not exist — consistent with a restore, rename or import being performed at the time.

   **The production database is `time-vault-app-db`**, not `time_walt` (which is the local dev name). Worth recording: nothing else in this repo states it.

   This likely also explains finding 2. If rows were loaded from a dump taken under a different `APP_KEY`, they would be undecryptable now — same date, same cause, and user 1 is exactly such a row.

   Re-confirm before treating it as closed:
   ```bash
   php artisan tinker --execute="echo config('database.connections.mysql.database'), PHP_EOL, DB::table('transfers')->count(), PHP_EOL;"
   php artisan verify:pending-transfers
   php artisan check:payment-holds
   ```