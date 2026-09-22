#!/usr/bin/env bash
# Why did a support/feedback submission succeed but send no email?
# Run from the project root on the production server. Read-only - changes nothing.

PHP=${PHP_BIN:-php}

echo "=== 1. Keys present in .env? ==="
grep -E '^(ADMIN_EMAIL|SUPPORT_EMAIL|QUEUE_CONNECTION|MAIL_MAILER|MAIL_HOST|MAIL_PORT|MAIL_USERNAME|MAIL_FROM_ADDRESS)=' .env \
  || echo "  !! none of those keys are in .env"

echo
echo "=== 2. What Laravel actually reads (catches a stale config cache) ==="
$PHP artisan tinker --execute="
echo '  admin_email   : '.(config('mail.admin_email') ?: '*** NOT SET ***').PHP_EOL;
echo '  support_email : '.(config('mail.support_email') ?: '*** NOT SET ***').PHP_EOL;
echo '  queue driver  : '.config('queue.default').PHP_EOL;
echo '  mailer        : '.config('mail.default').' via '.config('mail.mailers.smtp.host').PHP_EOL;
"

echo
echo "=== 3. Is a queue worker alive? (0 = nothing is sending mail) ==="
echo "  queue:work processes: $(ps aux | grep -c '[q]ueue:work')"
command -v supervisorctl >/dev/null && supervisorctl status 2>/dev/null | head

echo
echo "=== 4. Queue backlog ==="
$PHP artisan tinker --execute="
echo '  jobs waiting : '.DB::table('jobs')->count().PHP_EOL;
echo '  jobs failed  : '.DB::table('failed_jobs')->count().PHP_EOL;
\$f = DB::table('failed_jobs')->latest('failed_at')->first();
if (\$f) { echo '  last failure : '.substr(\$f->exception, 0, 300).PHP_EOL; }
"

echo
echo "=== 5. Did the rows actually save? ==="
$PHP artisan tinker --execute="
echo '  support_requests : '.DB::table('support_requests')->count().PHP_EOL;
echo '  feedbacks        : '.DB::table('feedbacks')->count().PHP_EOL;
"

echo
echo "=== 6. What the log says ==="
grep -E 'notification skipped|acknowledgement skipped|notification email (sent|failed)|acknowledgement email (sent|failed)' \
  storage/logs/laravel.log 2>/dev/null | tail -20 \
  || echo "  (no matching lines - the jobs never ran)"
