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

// Sprint 19.7 — Campagnes de scraping
Schedule::command('campaigns:start-scheduled')->everyMinute()->withoutOverlapping();

// Sprint Pipeline 360° — Refresh quotidien des audiences actives
Schedule::command('audiences:full-refresh')
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->onOneServer();

// Sprint Pipeline 360° — Re-scrape mensuel companies archivées sans email
// La commande `companies:rescrape-archives` est codée dans le Sprint Hardening (H6).
// En attendant, le schedule est posé mais s'auto-skip si la commande n'existe pas
// (pas d'erreur dans schedule:list).
Schedule::command('companies:rescrape-archives --limit=200')
    ->monthlyOn(1, '02:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->skip(function (): bool {
        // True = skip ce run. Skip si la commande artisan n'existe pas encore.
        return ! array_key_exists('companies:rescrape-archives', Artisan::all());
    });

// Sprint H12 — Retry Google Places pour les companies pending (quota mensuel atteint)
// Tourne le 1er de chaque mois à 03:00 (1h après rescrape-archives).
Schedule::command('companies:retry-google-places --limit=500')
    ->monthlyOn(1, '03:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->skip(function (): bool {
        return ! array_key_exists('companies:retry-google-places', Artisan::all());
    });
