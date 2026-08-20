<?php

use App\Console\Commands\AuditVerifyChain;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// --- Scheduled jobs ---------------------------------------------------------
Schedule::command('coverage:refresh-matrix')->hourly();
Schedule::command('blacklists:check')->hourly();
// 🔴 B16-006 / F39-006 / B17-003 (S1), mesure le 2026-08-20. Cette ligne etait
// `Schedule::command('audit:verify-chain')->dailyAt('03:00');` — rien d'autre.
// Le planificateur de Laravel N'INTERPRETE PAS le code de sortie d'une commande
// planifiee : le `return self::FAILURE` de `AuditVerifyChain` disparaissait, et
// sa sortie `$this->error(...)` partait sur un flux sans lecteur (ni
// `sendOutputTo`, ni `emailOutputOnFailure` ici). Resultat mesure : une chaine
// d'audit rompue restait rompue en silence jusqu'a ce que quelqu'un ouvre la
// route de verification a la main. Une preuve dont la rupture n'alerte personne
// n'est pas une preuve.
//
// `onFailure()` est le seul crochet du planificateur qui lise le code de sortie.
// Il double l'alerte que la commande emet deja : la commande couvre l'appel
// manuel, ce rappel couvre le cas ou elle meurt avant d'alerter (fatale, OOM,
// conteneur tue) — dans ce cas elle n'a rien journalise, mais le planificateur
// voit tout de meme un code de sortie non nul.
Schedule::command('audit:verify-chain')
    ->dailyAt('03:00')
    ->onFailure(function (): void {
        Log::critical(
            AuditVerifyChain::PREFIXE_ALERTE . " : la tache planifiee de 03:00 "
            . "s'est terminee en echec. La chaine d'audit est rompue ou "
            . 'inverifiable — voir les lignes precedentes du journal.'
        );
    });
// 🔴 B11-003 (S1) — la portee de `retention:purge` est desormais OBLIGATOIRE :
// sans `--workspace=` ni `--all-workspaces`, la commande REFUSE (un `artisan`
// lance sans y penser purgeait auparavant tous les locataires a la fois). La
// tache PLANIFIEE, elle, veut bel et bien tous les espaces : on l'ecrit.
// `--force` est requis par le trait `RefuseUneSuppressionMassive` en contexte
// non interactif — c'est le meme aveu ecrit que pour les purges prospection.
Schedule::command('retention:purge --all-workspaces --force')->dailyAt('04:00');
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

// Héritage émission→chaîne : les émissions TV/radio héritent site/email/tél de leur
// chaîne parente. Tourne APRÈS media:sync-from-companies (05:15) pour que les chaînes
// aient déjà hérité de leur entreprise avant que les émissions n'héritent d'elles.
Schedule::command('media:sync-emissions-from-parent')
    ->dailyAt('05:20')
    ->withoutOverlapping()
    ->onOneServer();

// Rattachement SÛR des médias autonomes à leur entreprise éditrice (SIREN/nom exact
// unique). Hebdo (dimanche tôt) : opération ensembliste sur ~4,3M companies.
Schedule::command('media:link-to-companies')
    ->weeklyOn(0, '04:00')
    ->withoutOverlapping()
    ->onOneServer();

// Statut actuel/disparu des émissions Wikidata (date de fin P582). Hebdo, borné en
// mémoire (--limit) + reprenable : les runs successifs balaient tout le stock.
Schedule::command('media:tag-emissions-status --limit=20000')
    ->weeklyOn(0, '04:15')
    ->withoutOverlapping()
    ->runInBackground();

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

// ── Correctifs audit 2026-07-14 ────────────────────────────────────────────────

// Acquisition des JOURNALISTES (extraction LLM Mistral des pages ours/mentions légales).
// Ciblé sur la presse ÉDITORIALE (pas les boîtes de prod). Gaté par MEDIA_JOURNALISTS_ENABLED.
Schedule::command('journalists:scrape-ours --editorial --limit=300')->dailyAt('05:40')->withoutOverlapping()->runInBackground();

// Confiance email A/B/C des médias (même barème que les contacts) — quotidien.
Schedule::command('media:score-confidence')->dailyAt('05:25')->withoutOverlapping()->onOneServer();

// Rattachement des émissions TV orphelines à leur chaîne (fallback nom normalisé) — hebdo.
Schedule::command('media:link-emissions-to-channels')->weeklyOn(0, '04:30')->withoutOverlapping()->runInBackground();

// Backfill périodicité médias (no-op tant qu'aucune source fiable n'est branchée) — hebdo.
Schedule::command('media:backfill-periodicity')->weeklyOn(1, '03:15')->withoutOverlapping()->onOneServer();

// Blogs curés (media_type=blog) — hebdo.
Schedule::command('media:import-blogs')->weeklyOn(1, '03:30')->withoutOverlapping()->onOneServer();

// Scoring de confiance email A/B/C (déterministe, sans SMTP) — quotidien.
Schedule::command('prospection:score-email-confidence')->dailyAt('04:45')->withoutOverlapping()->onOneServer();

// Rétention : purge des scraper_runs de plus de 90 jours — quotidien.
Schedule::command('retention:prune-scraper-runs --days=90')->dailyAt('04:20')->withoutOverlapping()->onOneServer();

// Enrichissement direct des médias (scrape site → emails/tél) — rattrapage continu borné.
// Le gros run initial se lance en systemd shardé ; ici on rattrape les nouveaux médias.
Schedule::command('media:enrich --limit=5000')->everyThreeHours()->withoutOverlapping()->runInBackground();

// Purge des emails médias parasites/sur-partagés (plateformes/parking) — quotidien.
Schedule::command('media:clean-emails --threshold=10')->dailyAt('05:05')->withoutOverlapping()->onOneServer();

// Lot L4 (2026-08-14) — purges RGPD par univers (plan §2.8.3). Construites
// INERTES : le skip() saute le run tant que CRM_PURGE_ENABLED n'est pas à
// true (et la commande elle-même refuse, double verrou). Mensuel vivier
// (CNIL CVthèque 2 ans + refusés J+90), mensuel business (prospection 3 ans).
//
// 🔴 B17-009 (S0), mesuré le 2026-08-20 — « LES DEUX SEULES PURGES RGPD
// CORRECTEMENT CONSTRUITES NE S'EXÉCUTENT JAMAIS ».
//
// `CRM_PURGE_ENABLED` vaut `false` aux deux seuls endroits où il est écrit
// (`config/crm.php:143` par défaut, `.env.example:258`) et n'est écrit NULLE
// PART ailleurs : ni `docker-compose.prod.yml`, ni `infra/`, ni `.github/`.
// Recherche jouée sur tout le dépôt : 2 occurrences, toutes deux à `false`.
// Ces deux purges sont donc sautées à CHAQUE passage mensuel depuis leur
// écriture, et l'échéance CNIL (CVthèque 2 ans, prospection 3 ans) n'est tenue
// par AUCUN automatisme.
//
// CE QUI EST RÉPARÉ ICI, ET CE QUI NE L'EST PAS.
// Ouvrir le drapeau déclencherait en production la suppression mensuelle de
// fiches candidats : c'est une décision d'exploitant, pas un correctif d'audit.
// Ce qui EST un défaut réparable, en revanche, c'est que le saut était
// **silencieux** : `->skip()` de Laravel n'écrit rien nulle part. Une échéance
// légale suspendue sans trace est indistinguable d'une échéance tenue — c'est
// exactement pour cela que personne ne s'en est aperçu. Le saut se JOURNALISE
// désormais, en nommant le drapeau qui le retient.
$purgeRgpdRetenue = function (string $commande): bool {
    $ferme = ! filter_var(config('crm.purges_enabled', false), FILTER_VALIDATE_BOOLEAN);

    if ($ferme) {
        Log::warning(
            "[RGPD] Purge « {$commande} » SAUTEE : CRM_PURGE_ENABLED n'est pas a true. "
            . "L'echeance CNIL correspondante n'est tenue par aucun automatisme tant que "
            . 'ce drapeau reste ferme (config crm.purges_enabled).'
        );
    }

    return $ferme;
};

Schedule::command('rgpd:purge-vivier')
    ->monthlyOn(2, '03:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->skip(fn (): bool => $purgeRgpdRetenue('rgpd:purge-vivier'));

Schedule::command('rgpd:purge-business-prospects')
    ->monthlyOn(2, '04:15')
    ->withoutOverlapping()
    ->onOneServer()
    ->skip(fn (): bool => $purgeRgpdRetenue('rgpd:purge-business-prospects'));

// Lot L5 (2026-08-14) — mini-outbox CRM → site : les oppositions nées dans la
// console convergent vers le site (sinon les deux systèmes se réécrivent à des
// opposés). Construite INERTE : le skip() saute le run tant que
// CRM_OUTBOUND_ENABLED n'est pas à true, et la commande elle-même refuse
// (double verrou, comme les purges). withoutOverlapping : deux passages
// concurrents rejoueraient les mêmes lignes dues.
Schedule::command('crm:flush-outbound')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->skip(fn (): bool => ! filter_var(config('crm.outbound_enabled', false), FILTER_VALIDATE_BOOLEAN));
