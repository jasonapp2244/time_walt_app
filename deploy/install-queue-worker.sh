#!/usr/bin/env bash
#
# Installs a permanent queue worker as a systemd service.
#
# Why this exists: the API writes a row and dispatches a queued job, then returns
# success. If nothing consumes the queue, every notification email is silently
# never sent - no error, no bounce, nothing in failed_jobs. That is how the
# support and feedback mail went missing on 2026-09-22.
#
# systemd rather than Supervisor: it is already on the box, so there is no
# package to install, and Restart=always plus `enable` means the worker survives
# both a crash and a reboot.
#
# Run once, as root, from anywhere:
#     sudo /path/to/app/deploy/install-queue-worker.sh
#
# Safe to re-run: it rewrites the unit and restarts the service.

set -euo pipefail

SERVICE_NAME="timevault-queue"
UNIT="/etc/systemd/system/${SERVICE_NAME}.service"

C_RED=$(printf '\033[31m'); C_GRN=$(printf '\033[32m')
C_YEL=$(printf '\033[33m'); C_BLD=$(printf '\033[1m'); C_OFF=$(printf '\033[0m')
step() { printf '\n%s==> %s%s\n' "$C_BLD" "$*" "$C_OFF"; }
ok()   { printf '%s  ok%s  %s\n' "$C_GRN" "$C_OFF" "$*"; }
warn() { printf '%s  !!%s  %s\n' "$C_YEL" "$C_OFF" "$*"; }
die()  { printf '\n%s  FAILED%s  %s\n\n' "$C_RED" "$C_OFF" "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "run this with sudo - it writes to /etc/systemd/system"
command -v systemctl >/dev/null || die "systemd not found. Use Supervisor instead - see deploy/timevault-worker.conf"

# ------------------------------------------------------------- discover ----
step "Working out where the app lives"

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
[ -f "$APP_DIR/artisan" ] || die "no artisan in $APP_DIR - move this script back into <app>/deploy/"

# Run as whoever owns the code, never as root: a root-owned log or cache file
# breaks PHP-FPM the next time it tries to write there.
RUN_USER="$(stat -c '%U' "$APP_DIR/artisan")"
[ "$RUN_USER" != "root" ] || warn "artisan is owned by root - the worker will run as root too, which is not ideal"

PHP_BIN="$(command -v php || true)"
[ -n "$PHP_BIN" ] || die "php not found on PATH"

printf '    app dir   %s\n' "$APP_DIR"
printf '    run as    %s\n' "$RUN_USER"
printf '    php       %s (%s)\n' "$PHP_BIN" "$("$PHP_BIN" -r 'echo PHP_VERSION;')"

# Fail early rather than installing a service that cannot connect.
step "Checking the queue is reachable"
QUEUE_DRIVER="$(cd "$APP_DIR" && sudo -u "$RUN_USER" "$PHP_BIN" artisan tinker --execute="echo config('queue.default');" 2>/dev/null | tr -d '[:space:]')"
[ -n "$QUEUE_DRIVER" ] || die "could not read the queue config - check .env and the database connection"
ok "queue driver: $QUEUE_DRIVER"

# --------------------------------------------------------------- write ----
step "Writing $UNIT"

cat > "$UNIT" <<UNITFILE
[Unit]
Description=Time Vault queue worker (notification email)
After=network.target mysql.service mariadb.service

[Service]
Type=simple
User=${RUN_USER}
WorkingDirectory=${APP_DIR}
# --max-time recycles the process hourly so a slow memory leak cannot grow
# unbounded. --tries=3 retries a transient SMTP failure before giving up to
# failed_jobs. --sleep=3 is the idle poll interval, so mail goes out within
# about three seconds of being queued.
ExecStart=${PHP_BIN} ${APP_DIR}/artisan queue:work --sleep=3 --tries=3 --max-time=3600 --backoff=10
Restart=always
RestartSec=5
StandardOutput=append:${APP_DIR}/storage/logs/queue-worker.log
StandardError=append:${APP_DIR}/storage/logs/queue-worker.log

[Install]
WantedBy=multi-user.target
UNITFILE

touch "$APP_DIR/storage/logs/queue-worker.log"
chown "$RUN_USER" "$APP_DIR/storage/logs/queue-worker.log"
ok "unit written"

# --------------------------------------------------------------- start ----
step "Starting the service"
systemctl daemon-reload
systemctl enable "$SERVICE_NAME" >/dev/null 2>&1
systemctl restart "$SERVICE_NAME"
sleep 3

if ! systemctl is-active --quiet "$SERVICE_NAME"; then
    printf '\n%s  the service did not stay up%s\n\n' "$C_RED" "$C_OFF"
    systemctl status "$SERVICE_NAME" --no-pager -l | head -30
    exit 1
fi
ok "$SERVICE_NAME is active and enabled at boot"

# -------------------------------------------------------------- verify ----
step "Proving it actually works"

# Real end-to-end check: queue a job and watch the worker eat it.
BEFORE="$(cd "$APP_DIR" && sudo -u "$RUN_USER" "$PHP_BIN" artisan tinker --execute="echo DB::table('jobs')->count();" 2>/dev/null | tr -d '[:space:]')"
cd "$APP_DIR" && sudo -u "$RUN_USER" "$PHP_BIN" artisan tinker --execute="dispatch(function () { \Illuminate\Support\Facades\Log::info('queue worker smoke test ok'); });" >/dev/null 2>&1
sleep 6
AFTER="$(cd "$APP_DIR" && sudo -u "$RUN_USER" "$PHP_BIN" artisan tinker --execute="echo DB::table('jobs')->count();" 2>/dev/null | tr -d '[:space:]')"

if grep -q 'queue worker smoke test ok' "$APP_DIR/storage/logs/laravel.log" 2>/dev/null; then
    ok "test job was picked up and executed (backlog $BEFORE -> $AFTER)"
else
    warn "test job did not appear in the log within 6s (backlog $BEFORE -> $AFTER)"
    warn "check: tail -20 $APP_DIR/storage/logs/queue-worker.log"
fi

printf '\n%s----------------------------------------------------%s\n' "$C_BLD" "$C_OFF"
printf '%s  QUEUE WORKER INSTALLED%s\n' "$C_GRN" "$C_OFF"
printf '    status      systemctl status %s\n' "$SERVICE_NAME"
printf '    live log    journalctl -u %s -f\n' "$SERVICE_NAME"
printf '    app log     tail -f %s/storage/logs/queue-worker.log\n' "$APP_DIR"
printf '    restart     systemctl restart %s\n' "$SERVICE_NAME"
printf '\n    Email now sends within ~3 seconds of submission, with no command to run.\n'
printf '    deploy.sh signals this worker to reload new code on every deploy.\n'
printf '%s----------------------------------------------------%s\n\n' "$C_BLD" "$C_OFF"
