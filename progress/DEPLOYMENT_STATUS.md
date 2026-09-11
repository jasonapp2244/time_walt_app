# DEPLOYMENT STATUS — Time Vault

**Last updated:** 2026-09-12

A command completing is not a successful deployment. Nothing goes in the VERIFIED column below without evidence.

---

## ENVIRONMENTS

| Environment | URL / target | Last deployed | Verified |
|---|---|---|---|
| Local | `http://127.0.0.1:8000` (XAMPP, db `time_walt`) | 2026-09-12 | Yes — suite green, 61 routes resolve |
| Production API | `https://api.timevaultapp.co` → `/home/timevaultapp-api/htdocs/api.timevaultapp.co` on VPS `srv1017557` (Hostinger CloudPanel) | **not yet — pending** | **No** |
| Test server | `https://timevaultapp.devonlinetestserver.com` → `/home/devonlinetestserver-timevaultapp/htdocs/...` | unknown | No |

## PENDING RELEASE

Commits pushed to `origin/main` and `origin/development` on 2026-09-12 but **not yet pulled onto the production server**:

- Autonomous engineering charter added to `CLAUDE.md` (+403 lines)
- `progress/` handover files filled in
- `FLUTTER_API_INTEGRATION_GUIDE.md` added
- `TimeVault_Complete_Postman_Collection.json` added
- `.claude/settings.local.json` permission list updated

**Risk assessment: documentation and tooling only.** No `app/`, `routes/`, `config/`, `database/` or `resources/` changes, and no new migrations. Pulling this on production cannot change runtime behaviour — which is exactly why it is a safe first deploy to re-establish the pipeline.

Deploy command block: `progress/TODO.md` → IN PROGRESS.

## POST-DEPLOY CHECKLIST (tick only with evidence)

- [ ] Build succeeded (`composer install --no-dev` exits 0)
- [ ] Environment / config values correct for the target (`APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` **untouched**)
- [ ] Migrations ran and schema matches expectations (`php artisan migrate:status` — expect nothing pending this release)
- [ ] Logs clean (`tail -n 50 storage/logs/laravel.log`, no new errors after deploy)
- [ ] Health check responds (`curl -i https://api.timevaultapp.co/up` → 200)
- [ ] API reachable and returning the expected contract
- [ ] Admin panel loads and login works
- [ ] Background workers / scheduled jobs running (`php artisan queue:restart` issued; confirm Supervisor workers came back)

## ROLLBACK PLAN

The release is docs-only, so rollback is a checkout of the previous SHA — no data migration to reverse.

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
