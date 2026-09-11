# TODO — Time Vault

**Last updated:** 2026-09-12

One line per item. Keep it honest: an item is only done when it has been verified working, not when it compiles.

---

## IN PROGRESS

- [ ] **Deploy the pushed commits to production** (`api.timevaultapp.co`). Must be run from the VPS root shell — no SSH credentials for `srv1017557` exist on the dev machine. Both `main` and `development` point at the same commit, so either branch is safe to deploy.

```bash
cd /home/timevaultapp-api/htdocs/api.timevaultapp.co

# 1. See what the server is actually on, and whether anything was hand-edited there
git branch --show-current
git status --short          # MUST be clean; stash or commit local edits first, do not blow them away
git log --oneline -3

# 2. Pull
git fetch origin
git pull origin "$(git branch --show-current)"

# 3. Dependencies (production flags — never install dev deps on the live box)
composer install --no-dev --optimize-autoloader --no-interaction

# 4. Migrations — check first, only run if something is pending
php artisan migrate:status
php artisan migrate --force          # skip entirely if nothing is pending

# 5. Rebuild caches
php artisan config:clear && php artisan config:cache
php artisan route:clear  && php artisan route:cache
php artisan view:clear   && php artisan view:cache

# 6. Restart the queue so workers pick up new code
php artisan queue:restart

# 7. Permissions (CloudPanel site user)
chown -R timevaultapp-api:timevaultapp-api storage bootstrap/cache
chmod -R ug+rw storage bootstrap/cache

# 8. Verify
curl -i https://api.timevaultapp.co/up
tail -n 50 storage/logs/laravel.log
```

> **NEVER run `php artisan key:generate` on this server.** `APP_KEY` encrypts `email`, `phone`, `full_name` and other PII at rest — regenerating it corrupts every existing record with `DecryptException: The MAC is invalid`.
> **NEVER run `migrate:fresh`, `migrate:refresh` or `db:wipe`** against the production database.

## NEXT UP

- [ ] Record the deploy evidence (HTTP status of `/up`, log tail, commit SHA on the server) in `DEPLOYMENT_STATUS.md`.
- [ ] Run `vendor/bin/pint` to fix the 6 style failures, then re-run `php artisan test`.
- [ ] Rewrite `deployeement.md` — it still targets the retired `stripe-sheet-and-admin-panel` branch and the `devonlinetestserver` test box, not `api.timevaultapp.co`.

## BACKLOG

- [ ] Real test coverage for the payment → hold → withdrawal flow (currently 5 placeholder `example` tests).
- [ ] Feature tests for the partial-withdrawal `remaining_amount` accounting, including the failed-transfer restore path in `VerifyPendingTransfers`.
- [ ] Decide whether `.claude/settings.local.json` should stay tracked — it is machine-local permission state and creates a diff on every machine that opens the repo.

## BLOCKED (and what unblocks it)

- **Production deploy from this machine** — blocked on SSH access. `~/.ssh/config` has no entry for `srv1017557` / `api.timevaultapp.co`, and the only configured host is `emp-ionos`. Unblocked either by adding a host entry + key, or by the user running the block above themselves.

## DONE

- [x] 2026-09-12 — Committed the outstanding docs/config work and pushed `development` to origin
- [x] 2026-09-12 — Fast-forwarded `main` to `development` and pushed; both branches identical on GitHub
- [x] 2026-09-12 — Filled in all four `progress/` handover files from their seeded templates
- [x] 2026-09-07 — Production engineering rules installed in `CLAUDE.md`, `progress/` folder created
