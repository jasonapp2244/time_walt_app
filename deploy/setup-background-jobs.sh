#!/usr/bin/env bash
#
# Sets up and verifies both background processes Time Vault needs in production:
#
#   1. The queue worker  - systemd service `timevault-queue`. Without it every
#      notification email is dropped in silence: the API writes its row, queues
#      the job, returns success, and nothing ever sends. No exception, no
#      failed_jobs row, no bounce. This is how the support and feedback mail
#      went missing on 2026-09-22.
#
#   2. The scheduler     - one cron entry running `schedule:run` every minute.
#      Without it `check:payment-holds` never flips a matured hold to
#      ready_for_transfer, so a user's money matures and stays unwithdrawable,
#      and `verify:pending-transfers` never restores remaining_amount after a
#      failed transfer. Same silent shape as the email, but on the money path.
#
# Run once, as root. Safe to re-run - it converges on the correct state and
# re-verifies, so it doubles as a health check.
#
#     sudo ./deploy/setup-background-jobs.sh
#
# Exits non-zero if anything could not be verified.

set -euo pipefail

SERVICE_NAME="timevault-queue"
UNIT="/etc/systemd/system/${SERVICE_NAME}.service"
MARKER="TV_QUEUE_PROBE"

if [ -t 1 ]; then
    C_RED=$(printf '\033[31m'); C_GRN=$(printf '\033[32m')
    C_YEL=$(printf '\033[33m'); C_BLD=$(printf '\033[1m'); C_OFF=$(printf '\033[0m')
else
    C_RED=""; C_GRN=""; C_YEL=""; C_BLD=""; C_OFF=""
fi
step() { printf '\n%s==> %s%s\n' "$C_BLD" "$*" "$C_OFF"; }
ok()   { printf '%s  ok%s    %s\n' "$C_GRN" "$C_OFF" "$*"; }
warn() { printf '%s  warn%s  %s\n' "$C_YEL" "$C_OFF" "$*"; }
bad()  { printf '%s  FAIL%s  %s\n' "$C_RED" "$C_OFF" "$*"; FAILED=1; }
die()  { printf '\n%s  ABORTED%s  %s\n\n' "$C_RED" "$C_OFF" "$*" >&2; exit 1; }

FAILED=0

# --------------------------------------------------------------- discover ----
step "Locating the app"

[ "$(id -u)" -eq 0 ] || die "run with sudo - this writes a systemd unit and a crontab"
command -v systemctl >/dev/null || die "no systemd. Use deploy/timevault-worker.conf with Supervisor instead."

# Prefer the directory this script sits in; fall back to the known live path so
# the script still works when pasted somewhere else.
APP_DIR="$(cd "$(dirname "$0")/.." 2>/dev/null && pwd || true)"
if [ ! -f "${APP_DIR}/artisan" ]; then
    APP_DIR="/home/timevaultapp-api/htdocs/api.timevaultapp.co"
fi
[ -f "${APP_DIR}/artisan" ] || die "no artisan found. Pass the app path: APP_DIR=/path $0"

# Never run either process as root: a root-owned log or cache file breaks
# PHP-FPM the next time it writes there.
OWNER="$(stat -c '%U' "${APP_DIR}/artisan")"
PHP_BIN="$(command -v php || true)"
[ -n "$PHP_BIN" ] || die "php not on PATH"

ART="sudo -u ${OWNER} ${PHP_BIN} artisan"

printf '    app      %s\n' "$APP_DIR"
printf '    user     %s\n' "$OWNER"
printf '    php      %s (%s)\n' "$PHP_BIN" "$("$PHP_BIN" -r 'echo PHP_VERSION;')"

if [ "$OWNER" = "root" ]; then
    warn "the code is owned by root, so both processes will run as root."
    warn "that will eventually leave root-owned files in storage/ that PHP-FPM cannot write."
fi

cd "$APP_DIR"

step "Checking the app can talk to its database"
QUEUE_DRIVER="$($ART tinker --execute="echo config('queue.default');" 2>/dev/null | tr -cd 'a-z' || true)"
[ -n "$QUEUE_DRIVER" ] || die "could not read config - check .env and the DB connection before continuing"
ok "queue driver: ${QUEUE_DRIVER}"

# ------------------------------------------------------------ 1. worker ------
step "1/2  Queue worker (notification email)"

# Create it only if absent - never truncate, this script is re-runnable.
WORKER_LOG="${APP_DIR}/storage/logs/queue-worker.log"
[ -f "$WORKER_LOG" ] || touch "$WORKER_LOG"
chown "$OWNER" "$WORKER_LOG"
chmod 664 "$WORKER_LOG"

cat > "$UNIT" <<UNITFILE
[Unit]
Description=Time Vault queue worker
After=network.target mysql.service mariadb.service

[Service]
Type=simple
User=${OWNER}
WorkingDirectory=${APP_DIR}
# --sleep=3   idle poll, so mail leaves within ~3s of being queued
# --tries=3   retry a transient SMTP failure before giving up to failed_jobs
# --max-time  recycle hourly so a slow leak cannot grow unbounded; systemd
#             restarts it immediately, and deploy.sh's queue:restart relies on
#             the same Restart=always to pick up new job code
ExecStart=${PHP_BIN} ${APP_DIR}/artisan queue:work --sleep=3 --tries=3 --max-time=3600 --backoff=10
Restart=always
RestartSec=5
StandardOutput=append:${WORKER_LOG}
StandardError=append:${WORKER_LOG}

[Install]
WantedBy=multi-user.target
UNITFILE

systemctl daemon-reload
systemctl enable "$SERVICE_NAME" >/dev/null 2>&1
systemctl restart "$SERVICE_NAME"
sleep 4

if systemctl is-active --quiet "$SERVICE_NAME"; then
    ok "$SERVICE_NAME active, enabled at boot, running as ${OWNER}"
else
    bad "$SERVICE_NAME did not stay up"
    systemctl status "$SERVICE_NAME" --no-pager -l | head -20
fi

# --------------------------------------------------------- 2. scheduler ------
step "2/2  Scheduler cron (payment holds, transfer reconciliation)"

CRON_LINE="* * * * * cd ${APP_DIR} && ${PHP_BIN} artisan schedule:run >> /dev/null 2>&1"

if crontab -u "$OWNER" -l 2>/dev/null | grep -qF "artisan schedule:run"; then
    ok "cron entry already present for ${OWNER}"
else
    { crontab -u "$OWNER" -l 2>/dev/null || true; echo "$CRON_LINE"; } | crontab -u "$OWNER" -
    if crontab -u "$OWNER" -l 2>/dev/null | grep -qF "artisan schedule:run"; then
        ok "cron entry installed for ${OWNER}"
    else
        bad "could not install the cron entry"
    fi
fi

# A crontab entry is not proof the command works, so run it once. This does
# execute any task that is currently due - which is exactly what cron will do a
# minute from now, so it changes nothing that was not about to happen anyway.
if $ART schedule:run >/dev/null 2>&1; then
    ok "schedule:run executes cleanly"
else
    bad "schedule:run exits non-zero - run it by hand to see why: sudo -u ${OWNER} ${PHP_BIN} artisan schedule:run"
fi

SCHEDULED="$($ART schedule:list 2>/dev/null | grep -cE 'check:payment-holds|verify:pending-transfers' || true)"
if [ "${SCHEDULED:-0}" -ge 2 ]; then
    ok "both money-path commands are registered"
else
    warn "expected check:payment-holds and verify:pending-transfers in schedule:list, found ${SCHEDULED:-0}"
fi

# ------------------------------------------------------------- verify --------
step "Proving the worker actually consumes jobs"

# The probe closure has to live in a real file. serializable-closure reads a
# closure's source with reflection, and one typed into `tinker --execute` has no
# source file, so it throws instead of queueing - which would report a healthy
# worker as broken. Confirmed both behaviours locally before shipping this.
PROBE_FILE="${APP_DIR}/storage/app/tv-queue-probe.php"
cat > "$PROBE_FILE" <<'PROBE'
<?php
dispatch(function () {
    \Illuminate\Support\Facades\Log::info('TV_QUEUE_PROBE');
});
PROBE
chown "$OWNER" "$PROBE_FILE" 2>/dev/null || true

$ART tinker --execute="require '${PROBE_FILE}';" >/dev/null 2>&1 || true

CONSUMED=0
for _ in 1 2 3 4 5 6 7 8 9 10; do
    if grep -q "$MARKER" "${APP_DIR}/storage/logs/laravel.log" 2>/dev/null; then
        CONSUMED=1
        break
    fi
    sleep 2
done

rm -f "$PROBE_FILE"

if [ "$CONSUMED" -eq 1 ]; then
    ok "test job was picked up and executed - email now sends with no command to run"
    # Leave the log as we found it.
    sed -i "/${MARKER}/d" "${APP_DIR}/storage/logs/laravel.log" 2>/dev/null || true
else
    bad "test job was not executed within 20s"
    warn "check: tail -30 ${APP_DIR}/storage/logs/queue-worker.log"
    warn "and:   sudo -u ${OWNER} ${PHP_BIN} artisan queue:failed"
fi

step "Queue state"
PENDING="$($ART tinker --execute="echo DB::table('jobs')->count();" 2>/dev/null | tr -cd '0-9' || true)"
FAILED_JOBS="$($ART tinker --execute="echo DB::table('failed_jobs')->count();" 2>/dev/null | tr -cd '0-9' || true)"
printf '    pending  %s\n' "${PENDING:-?}"
printf '    failed   %s\n' "${FAILED_JOBS:-?}"
if [ "${PENDING:-0}" != "0" ] && [ -n "${PENDING:-}" ]; then
    warn "backlog is not empty - it should drain within seconds now"
fi
if [ "${FAILED_JOBS:-0}" != "0" ] && [ -n "${FAILED_JOBS:-}" ]; then
    warn "inspect with: sudo -u ${OWNER} ${PHP_BIN} artisan queue:failed"
fi

# ------------------------------------------------------------ summary --------
printf '\n%s----------------------------------------------------%s\n' "$C_BLD" "$C_OFF"
if [ "$FAILED" -eq 0 ]; then
    printf '%s  BACKGROUND JOBS READY%s\n' "$C_GRN" "$C_OFF"
    printf '    email       sends within ~3s of submission, automatically\n'
    printf '    holds       mature on the 5-minute cron, automatically\n'
    printf '    on reboot   both come back on their own\n'
else
    printf '%s  SOMETHING IS NOT VERIFIED - see the FAIL lines above%s\n' "$C_RED" "$C_OFF"
fi
printf '\n    worker      systemctl status %s\n' "$SERVICE_NAME"
printf '    worker log  tail -f %s/storage/logs/queue-worker.log\n' "$APP_DIR"
printf '    cron        crontab -u %s -l\n' "$OWNER"
printf '    re-check    sudo %s\n' "$(readlink -f "$0" 2>/dev/null || echo "$0")"
printf '%s----------------------------------------------------%s\n\n' "$C_BLD" "$C_OFF"

exit "$FAILED"
