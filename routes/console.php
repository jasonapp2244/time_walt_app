<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule Cron Jobs
Schedule::command('check:payment-holds')
    ->everyMinute()
    ->description('Check payment holds where hold period has ended and mark as ready for transfer');
