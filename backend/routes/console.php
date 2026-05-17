<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// --- Scheduled jobs ---------------------------------------------------------
Schedule::command('coverage:refresh-matrix')->hourly();
Schedule::command('blacklists:check')->hourly();
Schedule::command('audit:verify-chain')->dailyAt('03:00');
Schedule::command('retention:purge')->dailyAt('04:00');
Schedule::command('rgpd:anonymize-ips')->dailyAt('04:30');
Schedule::command('anomaly:detect')->everyFifteenMinutes();
Schedule::command('signals:nightly-scan')->dailyAt('02:00');
