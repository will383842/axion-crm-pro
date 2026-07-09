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

// --- Chantier base médias : rafraîchissement automatique (« set & forget ») ---
// Extraction des nouveaux médias (par NAF) depuis companies — idempotent, quotidien.
Schedule::command('media:extract-from-companies')
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->onOneServer();

// Anti-divergence : les médias liés à une entreprise héritent de son site/email/tél
// (l'entreprise = source de vérité). Tourne après l'extraction.
Schedule::command('media:sync-from-companies')
    ->dailyAt('05:15')
    ->withoutOverlapping()
    ->onOneServer();

// Recherche des sites web manquants — toutes les 30 min, BORNÉE en mémoire (--limit
// évite la fuite du DomainFinderService sur de gros volumes), withoutOverlapping (pas
// d'empilement) + runInBackground (process isolé). Le conteneur `scheduler` relance
// le job à l'heure suivante → SURVIT aux redéploiements (robustesse sans systemd).
Schedule::command('media:find-websites --limit=20000')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->onOneServer();

// Rafraîchissement hebdomadaire des registres officiels CPPAP (lundi tôt).
Schedule::command('media:import-opendatasoft cppap')->weeklyOn(1, '02:15')->withoutOverlapping()->onOneServer();
Schedule::command('media:import-opendatasoft spel')->weeklyOn(1, '02:30')->withoutOverlapping()->onOneServer();
Schedule::command('media:import-opendatasoft agences')->weeklyOn(1, '02:45')->withoutOverlapping()->onOneServer();

// Émissions TV/radio FR + présentateurs via Wikidata SPARQL (hebdo, dimanche tôt).
Schedule::command('media:import-emissions-wikidata')->weekly()->sundays()->at('03:00')->withoutOverlapping()->runInBackground();

// Radios FM + chaînes TV autorisées par l'ARCOM (niveau station, zone géo) — hebdo.
Schedule::command('media:import-arcom')->weekly()->sundays()->at('03:30')->withoutOverlapping()->runInBackground();

// Emails rédaction déterministes (redaction@/contact@) validés MX pour les médias sans email.
// Reprenable + borné en mémoire (--limit) ; toutes les 2h pour rattraper le backlog.
Schedule::command('media:generate-redaction-emails --limit=20000')->everyTwoHours()->withoutOverlapping()->runInBackground();
