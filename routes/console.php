<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule Cron Jobs
// Cron 1: Check hold periods and mark as ready for transfer
Schedule::command('check:payment-holds')
    ->hourly()
    ->description('Check payment holds where hold period has ended and mark as ready for transfer');

// Cron 2: Verify pending transfers with Stripe and send success emails
Schedule::command('verify:pending-transfers')
    ->everyMinute()
    ->description('Verify pending transfers with Stripe and send payout success emails');

// Cron 3: Cleanup old unverified accounts
Schedule::command('users:cleanup-unverified')
    ->daily()
    ->description('Delete unverified accounts older than 24 hours to keep database clean');
