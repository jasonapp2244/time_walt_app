#!/usr/bin/env bash
#
# Time Vault - one-shot production deploy
#
#   ./deploy.sh              deploy the current branch's origin HEAD
#   ./deploy.sh --branch X   deploy branch X
#   ./deploy.sh --rollback   return to the SHA recorded by the last deploy
#   ./deploy.sh --dry-run    show what would happen, change nothing
#
# Safe to re-run. Every step is idempotent.
#
# THIS SCRIPT WILL NEVER:
#   - run key:generate    (APP_KEY decrypts email/phone/full_name at rest;
#                          regenerating it corrupts every existing row)
#   - run migrate:fresh, migrate:refresh, migrate:reset or db:wipe
#   - run composer install with dev dependencies
#   - push, force-push, or discard uncommitted work found on the server

set -Eeuo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
STATE_FILE="$APP_DIR/storage/app/.last-deploy-sha"
BACKUP_DIR="${BACKUP_DIR:-$APP_DIR/../backups}"
HEALTH_PATH="/up"

BRANCH=""
DRY_RUN=0
ROLLBACK=0

export COMPOSER_ALLOW_SUPERUSER=1

# ---------------------------------------------------------------- output ----
if [ -t 1 ]; then
    C_RED=$(printf '\033[31m')
    C_GRN=$(printf '\033[32m')
    C_YEL=$(printf '\033[33m')
    C_BLD=$(printf '\033[1m')
    C_OFF=$(printf '\033[0m')
else
    C_RED=""; C_GRN=""; C_YEL=""; C_BLD=""; C_OFF=""
fi

step() { printf '\n%s==> %s%s\n' "$C_BLD" "$*" "$C_OFF"; }
ok()   { printf '%s  ok%s  %s\n' "$C_GRN" "$C_OFF" "$*"; }
warn() { printf '%s  !!%s  %s\n' "$C_YEL" "$C_OFF" "$*"; }
die()  { printf '\n%s  FAILED%s  %s\n\n' "$C_RED" "$C_OFF" "$*" >&2; exit 1; }
run()  { if [ "$DRY_RUN" -eq 1 ]; then printf '       [dry-run] %s\n' "$*"; else "$@"; fi; }

trap 'die "aborted at line $LINENO - the app may still be in maintenance mode, run: $PHP_BIN artisan up"' ERR

# ------------------------------------------------------------------ args ----
while [ "$#" -gt 0 ]; do
    case "$1" in
        --branch)
            BRANCH="${2:-}"
            [ -n "$BRANCH" ] || die "--branch needs a value"
            shift 2
            ;;
        --rollback) ROLLBACK=1; shift ;;
        --dry-run)  DRY_RUN=1; shift ;;
        -h|--help)  sed -n '2,18p' "${BASH_SOURCE[0]}" | sed 's/^#\{1,\} \{0,1\}//'; exit 0 ;;
        *)          die "unknown option: $1" ;;
    esac
done

cd "$APP_DIR"
[ "$DRY_RUN" -eq 1 ] && warn "DRY RUN - nothing will be changed"

# ----------------------------------------------------------- preflight -----
step "Preflight"

[ -f artisan ] || die "no artisan file here - $APP_DIR is not the Laravel root"
[ -f .env ]    || die ".env is missing. Never deploy without it; the app would fall back to framework defaults."
[ -d .git ]    || die "$APP_DIR is not a git checkout - this script deploys by pulling, it cannot help here"

command -v "$PHP_BIN"      >/dev/null || die "php not found (set PHP_BIN=/path/to/php)"
command -v "$COMPOSER_BIN" >/dev/null || die "composer not found (set COMPOSER_BIN=/path/to/composer)"
command -v git             >/dev/null || die "git not found"

env_get() {
    grep -E "^[[:space:]]*$1[[:space:]]*=" .env 2>/dev/null \
        | tail -n 1 \
        | cut -d= -f2- \
        | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

APP_KEY_VAL="$(env_get APP_KEY)"
[ -n "$APP_KEY_VAL" ] || die "APP_KEY is empty in .env.
       Do NOT run key:generate on a database that already holds rows - it would make
       every encrypted column (email, phone, full_name) permanently undecryptable.
       Restore the correct key from your backup instead."
ok "APP_KEY present (left untouched)"

APP_ENV_VAL="$(env_get APP_ENV)"
[ "$APP_ENV_VAL" = "production" ] || warn "APP_ENV=$APP_ENV_VAL (expected 'production')"
[ "$(env_get APP_DEBUG)" = "false" ] || warn "APP_DEBUG is not false - stack traces are exposed publicly"

APP_URL_VAL="$(env_get APP_URL)"
APP_URL_VAL="${APP_URL_VAL%/}"
[ -n "$APP_URL_VAL" ] || warn "APP_URL is empty - the health check will be skipped"

OWNER="$(stat -c '%U:%G' "$APP_DIR")"
ok "site owner: $OWNER"

# -------------------------------------------------------------- rollback ----
if [ "$ROLLBACK" -eq 1 ]; then
    [ -f "$STATE_FILE" ] || die "no recorded SHA at $STATE_FILE - roll back by hand: git log --oneline -10"
    TARGET="$(cat "$STATE_FILE")"
    step "Rolling back to $TARGET"
    run "$PHP_BIN" artisan down --retry=15 || true
    run git checkout "$TARGET"
    run "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction
    run "$PHP_BIN" artisan config:cache
    run "$PHP_BIN" artisan route:cache
    run "$PHP_BIN" artisan view:cache
    run "$PHP_BIN" artisan queue:restart
    run "$PHP_BIN" artisan up
    ok "rolled back to $TARGET"
    warn "migrations were NOT reversed - if that release changed the schema, check it yourself"
    exit 0
fi

# ------------------------------------------------------ working tree check --
step "Checking the server's working tree"

CURRENT_BRANCH="$(git branch --show-current)"
[ -n "$BRANCH" ] || BRANCH="$CURRENT_BRANCH"
[ -n "$BRANCH" ] || die "detached HEAD and no --branch given"

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    printf '\n'
    git status --short --untracked-files=no
    die "the server has uncommitted changes (listed above). Someone edited files directly on production.
       Deal with them deliberately - 'git stash' to park them, or commit them - then re-run.
       This script will not overwrite them for you."
fi
ok "working tree clean, on branch '$CURRENT_BRANCH'"

PREV_SHA="$(git rev-parse HEAD)"
ok "current SHA: $(git rev-parse --short HEAD)  (this is the rollback point)"

# ------------------------------------------------------------------ fetch ---
step "Fetching origin/$BRANCH"
run git fetch origin "$BRANCH" --prune
NEW_SHA="$(git rev-parse "origin/$BRANCH" 2>/dev/null)" || die "origin/$BRANCH does not exist"

if [ "$PREV_SHA" = "$NEW_SHA" ]; then
    ALREADY_CURRENT=1
    ok "already at origin/$BRANCH - nothing to pull"
else
    ALREADY_CURRENT=0
    printf '\n       Incoming commits:\n'
    git --no-pager log --oneline "$PREV_SHA".."$NEW_SHA" | sed 's/^/         /'
    printf '\n       Files changed:\n'
    git --no-pager diff --stat "$PREV_SHA" "$NEW_SHA" | sed 's/^/         /'
fi

# --------------------------------------------------- pending migrations? ----
HAS_NEW_MIGRATIONS=0
if [ "$ALREADY_CURRENT" -eq 0 ]; then
    if git diff --name-only "$PREV_SHA" "$NEW_SHA" | grep -q '^database/migrations/'; then
        HAS_NEW_MIGRATIONS=1
        warn "this release adds migration files - a database backup will be taken first"
    fi
fi

# ------------------------------------------------------------- db backup ----
if [ "$HAS_NEW_MIGRATIONS" -eq 1 ]; then
    step "Backing up the database before touching the schema"
    DB_CONN="$(env_get DB_CONNECTION)"
    if [ "$DB_CONN" = "mysql" ] && command -v mysqldump >/dev/null; then
        run mkdir -p "$BACKUP_DIR"
        DUMP_FILE="$BACKUP_DIR/pre-deploy-$(date +%Y%m%d-%H%M%S).sql.gz"
        if [ "$DRY_RUN" -eq 1 ]; then
            printf '       [dry-run] mysqldump --single-transaction ... | gzip > %s\n' "$DUMP_FILE"
        else
            MYSQL_PWD="$(env_get DB_PASSWORD)" mysqldump \
                --host="$(env_get DB_HOST)" \
                --port="$(env_get DB_PORT)" \
                --user="$(env_get DB_USERNAME)" \
                --single-transaction --quick --routines --events \
                "$(env_get DB_DATABASE)" | gzip > "$DUMP_FILE"
            ok "backup written: $DUMP_FILE ($(du -h "$DUMP_FILE" | cut -f1))"
        fi
    else
        warn "cannot back up automatically (DB_CONNECTION=$DB_CONN)"
        warn "take a manual backup now - this release changes the schema"
        printf '       Type yes to continue without an automatic backup: '
        read -r CONFIRM
        [ "$CONFIRM" = "yes" ] || die "stopped at your request"
    fi
fi

# ------------------------------------------------------------ maintenance ---
step "Maintenance mode on"
run "$PHP_BIN" artisan down --retry=15 || warn "could not enter maintenance mode (continuing)"
trap 'printf "\n%s  bringing the app back up%s\n" "$C_YEL" "$C_OFF"; "$PHP_BIN" artisan up >/dev/null 2>&1 || true' EXIT

# ------------------------------------------------------------------- pull ---
if [ "$ALREADY_CURRENT" -eq 0 ]; then
    step "Pulling $BRANCH"
    run git merge --ff-only "origin/$BRANCH" || die "cannot fast-forward - the server's branch has diverged from origin. Resolve it by hand."
    ok "now at $(git rev-parse --short HEAD)"
fi

if [ "$DRY_RUN" -eq 0 ]; then
    mkdir -p "$(dirname "$STATE_FILE")"
    printf '%s\n' "$PREV_SHA" > "$STATE_FILE"
fi

# ----------------------------------------------------------- dependencies ---
step "Installing dependencies (production only)"
run "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction
ok "composer done"

# --------------------------------------------------------------- migrate ---
step "Migrations"
if [ "$DRY_RUN" -eq 1 ]; then
    "$PHP_BIN" artisan migrate:status 2>/dev/null | tail -n 15 | sed 's/^/       /' || true
elif "$PHP_BIN" artisan migrate:status 2>/dev/null | grep -qi 'pending'; then
    "$PHP_BIN" artisan migrate:status | grep -i 'pending' | sed 's/^/       /'
    run "$PHP_BIN" artisan migrate --force
    ok "migrations applied"
else
    ok "nothing pending"
fi

# ---------------------------------------------------------------- caches ----
step "Rebuilding caches"
run "$PHP_BIN" artisan config:clear
run "$PHP_BIN" artisan config:cache
run "$PHP_BIN" artisan route:clear
run "$PHP_BIN" artisan route:cache
run "$PHP_BIN" artisan view:clear
run "$PHP_BIN" artisan view:cache
run "$PHP_BIN" artisan event:cache
if [ -L public/storage ] || [ -d public/storage ]; then
    ok "storage symlink present"
else
    run "$PHP_BIN" artisan storage:link
fi
ok "caches rebuilt"

# ------------------------------------------------------------ permissions ---
step "Permissions"
case "$OWNER" in
    *UNKNOWN*|*" "*|"")
        warn "could not determine a usable owner for $APP_DIR (got '$OWNER') - skipping chown"
        warn "if the web user cannot write to storage/, fix it by hand: chown -R <user>:<group> storage bootstrap/cache"
        ;;
    *)
        run chown -R "$OWNER" storage bootstrap/cache
        ok "storage and bootstrap/cache owned by $OWNER"
        ;;
esac
run chmod -R ug+rw storage bootstrap/cache

# ----------------------------------------------------------------- queue ----
step "Restarting queue workers"
run "$PHP_BIN" artisan queue:restart
ok "restart signal sent (Supervisor respawns the workers)"

# -------------------------------------------------------------------- up ----
step "Maintenance mode off"
trap - EXIT
run "$PHP_BIN" artisan up
ok "app is live"

# --------------------------------------------------------------- health -----
step "Health check"
if [ "$DRY_RUN" -eq 1 ]; then
    printf '       [dry-run] curl %s%s\n' "$APP_URL_VAL" "$HEALTH_PATH"
elif [ -z "$APP_URL_VAL" ]; then
    warn "APP_URL empty - skipped. Check the site by hand."
else
    HEALTH_OK=0
    for attempt in 1 2 3 4 5; do
        CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$APP_URL_VAL$HEALTH_PATH" || echo 000)"
        if [ "$CODE" = "200" ]; then
            ok "$APP_URL_VAL$HEALTH_PATH -> 200 (attempt $attempt)"
            HEALTH_OK=1
            break
        fi
        warn "attempt $attempt: HTTP $CODE - retrying in 3s"
        sleep 3
    done
    if [ "$HEALTH_OK" -eq 0 ]; then
        printf '\n%s  HEALTH CHECK FAILED - the site is not returning 200%s\n' "$C_RED" "$C_OFF"
        printf '  Recent log lines:\n'
        tail -n 30 storage/logs/laravel.log 2>/dev/null | sed 's/^/    /' || true
        printf '\n  Roll back with:\n    cd %s && ./deploy.sh --rollback\n\n' "$APP_DIR"
        exit 1
    fi
fi

ERR_COUNT="$(grep -c 'production.ERROR' storage/logs/laravel.log 2>/dev/null || true)"
[ -n "$ERR_COUNT" ] || ERR_COUNT=0

# --------------------------------------------------------------- summary ----
printf '\n%s----------------------------------------------------%s\n' "$C_BLD" "$C_OFF"
printf '%s  DEPLOY COMPLETE%s\n' "$C_GRN" "$C_OFF"
printf '    branch      %s\n' "$BRANCH"
printf '    from        %s\n' "$(git rev-parse --short "$PREV_SHA")"
printf '    to          %s  %s\n' "$(git rev-parse --short HEAD)" "$(git log -1 --pretty=%s)"
printf '    health      %s%s\n' "$APP_URL_VAL" "$HEALTH_PATH"
printf '    log errors  %s lines match production.ERROR (check whether this grew)\n' "$ERR_COUNT"
printf '    rollback    ./deploy.sh --rollback\n'
printf '%s----------------------------------------------------%s\n\n' "$C_BLD" "$C_OFF"
