# DEPLOYMENT STATUS — Time Vault

**Last updated:** 2026-09-17

A command completing is not a successful deployment. Nothing goes in the VERIFIED column below without evidence.

---

## ENVIRONMENTS

| Environment | URL / target | Last deployed | Verified |
|---|---|---|---|
| Local | `http://127.0.0.1:8000` (XAMPP, db `time_walt`; tests use `time_walt_test`) | 2026-09-17 | Yes — 30 tests pass, 63 routes resolve, `/up` 200, `/admin/feedback` 302 to login |
| Production API | `https://api.timevaultapp.co` → `/home/timevaultapp-api/htdocs/api.timevaultapp.co` on VPS `srv1017557` (Hostinger CloudPanel) | **not yet — pending** | **No** |
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

### Tranche 2 — feedback feature, 2026-09-17, NOT YET COMMITTED

Still in the working tree. See `PROJECT_STATE.md` → FILES CHANGED. It adds: a user feedback API endpoint, an admin feedback page, a queued admin notification email, and one migration.

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
