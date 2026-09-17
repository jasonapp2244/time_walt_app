# DEPLOYMENT STATUS — Time Vault

**Last updated:** 2026-09-18

A command completing is not a successful deployment. Nothing goes in the VERIFIED column below without evidence.

---

## ENVIRONMENTS

| Environment | URL / target | Last deployed | Verified |
|---|---|---|---|
| Local | `http://127.0.0.1:8000` (XAMPP, db `time_walt`; tests use `time_walt_test`) | 2026-09-17 | Yes — 30 tests pass, 63 routes resolve, `/up` 200, `/admin/feedback` 302 to login |
| Production API | `https://api.timevaultapp.co` → `/home/timevaultapp-api/htdocs/api.timevaultapp.co` on VPS `srv1017557` (Hostinger CloudPanel) | 2026-09-17, `1c15798` → `28087e6` | Partial — `/up` 200, but see FIRST SUCCESSFUL DEPLOY below |
| Test server | `https://timevaultapp.devonlinetestserver.com` → `/home/devonlinetestserver-timevaultapp/htdocs/...` | unknown | No |

## PENDING RELEASE

Two tranches now sit between production and `main`.

### Tranche 1 — pushed 2026-09-12, not yet pulled

- Autonomous engineering charter added to `CLAUDE.md` (+403 lines)
- `progress/` handover files filled in
- `FLUTTER_API_INTEGRATION_GUIDE.md` added
- `TimeVault_Complete_Postman_Collection.json` added
- `.claude/settings.local.json` permission list updated

**Risk: documentation and tooling only.** No runtime change.

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
- [ ] Migrations ran and schema matches expectations (`php artisan migrate:status` — expect `create_feedbacks_table` to go from Pending to Ran)
- [ ] `ADMIN_EMAIL` is set in the production `.env` (otherwise feedback notifications are skipped silently)
- [ ] Submit one feedback from the app and confirm the row lands in `feedbacks` and the admin email arrives
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

3. **`verify:pending-transfers` is failing with exit code 1.** The stack bottoms out at `VerifyPendingTransfers.php:40` → `Builder->get()` → `Builder->runSelect()`, i.e. the query itself throws, not the Stripe call. The exception message was above the captured tail, so the cause is still unknown. Get it with:
   ```bash
   grep -n "verify:pending-transfers\|VerifyPendingTransfers" storage/logs/laravel.log | head
   grep -B 5 "VerifyPendingTransfers.php(40)" storage/logs/laravel.log | head -20
   ```