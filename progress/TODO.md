# TODO — Time Vault

**Last updated:** 2026-09-12

One line per item. Keep it honest: an item is only done when it has been verified working, not when it compiles.

---

## IN PROGRESS

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

- [ ] Record the deploy evidence (`/up` status, log tail, server-side SHA) in `DEPLOYMENT_STATUS.md`.
- [ ] **Local database is 2 migrations behind** — `2026_05_22_000001_add_bank_account_id_to_transfers_table` and `2026_05_25_000001_convert_timestamps_from_et_to_utc` show as Pending on the dev machine. Confirm whether production has them; if not, the first `./deploy.sh` run will apply them (and will take a `mysqldump` first).
- [ ] Run `vendor/bin/pint` to fix the 6 style failures, then re-run `php artisan test`.
- [ ] Rewrite `deployeement.md` — it still targets the retired `stripe-sheet-and-admin-panel` branch and the `devonlinetestserver` test box, not `api.timevaultapp.co`. `deploy.sh` supersedes most of it.

## BACKLOG

- [ ] Real test coverage for the payment → hold → withdrawal flow (currently 5 placeholder `example` tests).
- [ ] Feature tests for the partial-withdrawal `remaining_amount` accounting, including the failed-transfer restore path in `VerifyPendingTransfers`.
- [ ] Decide whether `.claude/settings.local.json` should stay tracked — it is machine-local permission state and creates a diff on every machine that opens the repo.

## BLOCKED (and what unblocks it)

- **Running the deploy from the dev machine** — blocked on SSH access. `~/.ssh/config` has no entry for `srv1017557` / `api.timevaultapp.co`; the only configured host is `emp-ionos`. Unblocked by adding a host entry + key, or by the user running the one-liner above.

## DONE

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
