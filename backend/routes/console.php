<?php

use App\Console\Commands\AuditVerifyChain;
use App\Console\Commands\CoverageRefreshMatrix;
use App\Console\Commands\CrmSondeCleDePersonne;
use App\Console\Commands\PartmanMaintenir;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// --- Scheduled jobs ---------------------------------------------------------
//
// 🔴 B17-002 (S1), mesure le 2026-08-21 — LE TTL DES VERROUS `withoutOverlapping`.
//
// Les 29 taches verrouillees de ce fichier portaient toutes `->withoutOverlapping()`
// SANS argument. Le cadre (Laravel 12.62, mesure dans le vendor du conteneur)
// donne alors a `$expiresAt` sa valeur par defaut :
//
//   Scheduling/ManagesAttributes.php:145   withoutOverlapping($expiresAt = 1440)
//   Scheduling/ManagesAttributes.php:70    public $expiresAt = 1440;
//   Scheduling/CacheEventMutex.php:44      ->lock($event->mutexName(), $event->expiresAt * 60)
//
// 1 440 minutes x 60 = 86 400 secondes : VINGT-QUATRE HEURES.
//
// Ce verrou n'est retire que par `Event::removeMutex()`, appele depuis
// `finish()` — c'est-a-dire seulement si le processus VA AU BOUT. Or :
//   - `docker-compose.yml:245` lance le planificateur par `php artisan
//     schedule:work`, et AUCUN des quatre fichiers Compose du depot ne declare
//     de `stop_grace_period` : Docker applique son defaut, SIGTERM puis SIGKILL
//     au bout de 10 secondes ;
//   - `.github/workflows/deploy-direct-ssh.yml:200` ET
//     `.github/workflows/deploy-staging.yml:189` recreent `scheduler` a CHAQUE
//     deploiement (`up -d --build --force-recreate --no-deps ... scheduler`) ;
//   - `backend/config/cache.php:4` place le verrou dans `redis`, et `redis`
//     n'est PAS recree par ce deploiement (`--no-deps`, services nommes) : le
//     verrou SURVIT au processus qu'on vient de tuer.
//
// Consequence : une tache tuee en plein travail par un redeploiement ne repart
// pas avant 24 heures. Sur `retention:prune-scraper-runs` et les purges RGPD,
// c'est une echeance manquee, et personne ne l'apprend.
//
// CE QUI EST FAIT ICI. Chaque verrou porte desormais un TTL EXPLICITE,
// dimensionne sur la duree plausible de SA tache et jamais au-dela de
// 360 minutes. La perte maximale d'un verrou orphelin passe de 24 h a 6 h — et
// a 10 minutes pour les taches infra-horaires (`campaigns:start-scheduled`,
// `crm:flush-outbound`). Le plafond de 6 h laisse passer la tache quotidienne
// suivante, qui est a 24 h d'ecart.
//
// CE QUI EST FAIT AILLEURS. `infra/docker/entrypoint-prod.sh` joue
// `php artisan schedule:clear-cache` quand la commande du conteneur est
// `schedule:work` — c'est-a-dire au redemarrage du planificateur, le seul
// instant ou l'on sait qu'aucune tache planifiee ne tourne. Le TTL est le
// filet ; cette liberation est la porte de sortie normale. Les deux
// deploiements (production et preproduction) construisent leur `scheduler` sur
// la cible `prod`, donc sur CET entrypoint : un seul geste couvre les deux.
//
// CE QUI N'EST PAS FAIT. Les sept taches planifiees SANS `withoutOverlapping`
// (`coverage:refresh-matrix`, `blacklists:check`, `audit:verify-chain`,
// `retention:purge`, `rgpd:anonymize-ips`, `anomaly:detect`,
// `signals:nightly-scan`) ne sont pas concernees par ce constat : sans verrou,
// il n'y a pas de verrou orphelin. Leur absence de verrou est un autre sujet.
//
// Garde : `backend/tests/Feature/Console/VerrousDuPlanificateurTest.php`
// (plafond 360 min, plancher 5 min, temoin de couverture chiffre a 25 taches,
// et la mecanique mesuree : a 1440 le verrou tient encore 30 minutes apres la
// mort de son porteur, a 10 il est tombe).
//
// 🔴 A08-001 (S1), mesure le 2026-08-20. Cette ligne etait
// `Schedule::command('coverage:refresh-matrix')->hourly();` — rien d'autre.
// La commande echouait a CHAQUE passage depuis l'armement du role applicatif
// (`must be owner of materialized view coverage_matrix_cells` : REFRESH est
// reserve au proprietaire, aucun GRANT ne l'accorde), et le planificateur de
// Laravel N'INTERPRETE PAS le code de sortie d'une commande planifiee : 71
// passages, 71 echecs, zero alerte, une matrice de couverture figee.
// Le geste lui-meme est repare cote commande (fonction SECURITY DEFINER) ;
// `onFailure()` est le seul crochet du planificateur qui lise le code de
// sortie — il couvre le cas ou la commande meurt AVANT d'avoir pu journaliser.
Schedule::command('coverage:refresh-matrix')
    ->hourly()
    ->onFailure(function (): void {
        Log::critical(
            CoverageRefreshMatrix::PREFIXE_ALERTE . ' : la tache planifiee horaire s\'est '
            . 'terminee en echec. La matrice de couverture est figee — voir les lignes '
            . 'precedentes du journal.',
        );
    });
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
            AuditVerifyChain::PREFIXE_ALERTE . ' : la tache planifiee de 03:00 '
            . "s'est terminee en echec. La chaine d'audit est rompue ou "
            . 'inverifiable — voir les lignes precedentes du journal.',
        );
    });
// 🔴 B11-003 (S1) — la portee de `retention:purge` est desormais OBLIGATOIRE :
// sans `--workspace=` ni `--all-workspaces`, la commande REFUSE (un `artisan`
// lance sans y penser purgeait auparavant tous les locataires a la fois). La
// tache PLANIFIEE, elle, veut bel et bien tous les espaces : on l'ecrit.
// `--force` est requis par le trait `RefuseUneSuppressionMassive` en contexte
// non interactif — c'est le meme aveu ecrit que pour les purges prospection.
Schedule::command('retention:purge --all-workspaces --force')->dailyAt('04:00');
// 🔴 B10-003 (S1), mesure le 2026-08-20 — « le partitionnement d'audit_logs
// n'est entretenu par personne ». `Dockerfile.postgres:49-51` compile pg_partman
// en `NO_BGW=1` et designe explicitement « le cron de partition mgmt sera
// Laravel scheduler » : CETTE LIGNE-CI est ce remplacant, qui n'avait jamais ete
// ecrit. Avant elle, `run_maintenance` n'apparaissait qu'a UN endroit du depot :
// un runbook d'incident MANUEL (infra/runbooks/02-disk-full.md).
// Mesure : les partitions s'arretaient a `audit_logs_p20270201` (fevrier 2027),
// et la retention de 24 mois — que pg_partman n'applique QUE dans
// `run_maintenance` — n'etait declenchee par rien.
// 01:30 : aucun autre travail planifie a cette heure, et la maintenance passe
// avant `audit:verify-chain` (03:00) et `retention:purge` (04:00).
// `onFailure()` : le planificateur de Laravel N'INTERPRETE PAS le code de sortie
// d'une commande planifiee (patron A08-001 / B16-006 ci-dessus). Sans ce
// crochet, une maintenance de partitions qui echoue reproduirait exactement le
// defaut qu'on repare : une piece qui ne tourne plus, et personne pour le voir.
Schedule::command(PartmanMaintenir::SIGNATURE_PLANIFIEE)
    ->dailyAt('01:30')
    ->withoutOverlapping(60)
    ->onOneServer()
    ->onFailure(function (): void {
        Log::critical(
            PartmanMaintenir::PREFIXE_ALERTE . " : la tache planifiee de 01:30 s'est terminee en "
            . "echec. Les partitions a venir d'audit_logs ne sont peut-etre plus creees, et la "
            . "retention de 24 mois n'est plus appliquee — voir les lignes precedentes du journal.",
        );
    });
// ── SONDE A05-001 : la fiche 360 est-elle atteignable ? ─────────────────────
//
// 🔴 LE DEFAUT QU'ELLE FERME N'EST PAS L'ABSENCE DU SECRET, C'EST LE SILENCE.
//
// `contacts.person_key` se calcule avec un secret venu du site
// (`CRM_PERSON_KEY_SECRET`), dont le defaut est la chaine VIDE. Sans lui, la
// fiche 360 n'est offerte pour AUCUNE personne. Mesure du 2026-08-21 :
//
//   - la migration de remplissage s'AJOURNE proprement, en le journalisant UNE
//     fois, au milieu d'un deploiement ;
//   - `crm:remplir-cle-personne` n'etait PLANIFIEE NULLE PART ;
//   - aucune sonde ne verifiait ce secret.
//
// Le produit tournait donc sans erreur, et une fonctionnalite entiere n'existait
// pas. C'est le patron A08-001 / B16-006 : une piece qui ne tourne plus, et
// personne pour le voir. La sonde ne POSE pas le secret — un secret de
// production ne se pose pas depuis le depot — elle rend son absence AUDIBLE et
// dit le geste qui la fait taire.
Schedule::command(CrmSondeCleDePersonne::SIGNATURE_PLANIFIEE)
    ->dailyAt('06:10')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->onFailure(function (): void {
        Log::critical(
            CrmSondeCleDePersonne::PREFIXE_ALERTE . ' : la sonde de 06:10 est sortie en echec. '
            . 'Soit `CRM_PERSON_KEY_SECRET` est absent, soit des fiches attendent encore leur cle '
            . '— voir la ligne precedente du journal, qui nomme le cas et le geste.',
        );
    });

Schedule::command('rgpd:anonymize-ips')->dailyAt('04:30');
Schedule::command('anomaly:detect')->everyFifteenMinutes();
Schedule::command('signals:nightly-scan')->dailyAt('02:00');

// Sprint 19.7 — Campagnes de scraping
Schedule::command('campaigns:start-scheduled')->everyMinute()->withoutOverlapping(10);

// Sprint Pipeline 360° — Refresh quotidien des audiences actives
Schedule::command('audiences:full-refresh')
    ->dailyAt('04:00')
    ->withoutOverlapping(120)
    ->onOneServer();

// Sprint Pipeline 360° — Re-scrape mensuel companies archivées sans email
// La commande `companies:rescrape-archives` est codée dans le Sprint Hardening (H6).
// En attendant, le schedule est posé mais s'auto-skip si la commande n'existe pas
// (pas d'erreur dans schedule:list).
Schedule::command('companies:rescrape-archives --limit=200')
    ->monthlyOn(1, '02:00')
    ->withoutOverlapping(120)
    ->onOneServer()
    ->skip(function (): bool {
        // True = skip ce run. Skip si la commande artisan n'existe pas encore.
        return ! array_key_exists('companies:rescrape-archives', Artisan::all());
    });

// Sprint H12 — Retry Google Places pour les companies pending (quota mensuel atteint)
// Tourne le 1er de chaque mois à 03:00 (1h après rescrape-archives).
Schedule::command('companies:retry-google-places --limit=500')
    ->monthlyOn(1, '03:00')
    ->withoutOverlapping(120)
    ->onOneServer()
    ->skip(function (): bool {
        return ! array_key_exists('companies:retry-google-places', Artisan::all());
    });

// --- Chantier base médias : rafraîchissement automatique (« set & forget ») ---
// Extraction des nouveaux médias (par NAF) depuis companies — idempotent, quotidien.
Schedule::command('media:extract-from-companies')
    ->dailyAt('05:00')
    ->withoutOverlapping(120)
    ->onOneServer();

// Anti-divergence : les médias liés à une entreprise héritent de son site/email/tél
// (l'entreprise = source de vérité). Tourne après l'extraction.
Schedule::command('media:sync-from-companies')
    ->dailyAt('05:15')
    ->withoutOverlapping(120)
    ->onOneServer();

// Héritage émission→chaîne : les émissions TV/radio héritent site/email/tél de leur
// chaîne parente. Tourne APRÈS media:sync-from-companies (05:15) pour que les chaînes
// aient déjà hérité de leur entreprise avant que les émissions n'héritent d'elles.
Schedule::command('media:sync-emissions-from-parent')
    ->dailyAt('05:20')
    ->withoutOverlapping(60)
    ->onOneServer();

// Rattachement SÛR des médias autonomes à leur entreprise éditrice (SIREN/nom exact
// unique). Hebdo (dimanche tôt) : opération ensembliste sur ~4,3M companies.
Schedule::command('media:link-to-companies')
    ->weeklyOn(0, '04:00')
    ->withoutOverlapping(360)
    ->onOneServer();

// Statut actuel/disparu des émissions Wikidata (date de fin P582). Hebdo, borné en
// mémoire (--limit) + reprenable : les runs successifs balaient tout le stock.
Schedule::command('media:tag-emissions-status --limit=20000')
    ->weeklyOn(0, '04:15')
    ->withoutOverlapping(180)
    ->runInBackground();

// Recherche des sites web manquants — toutes les 30 min, BORNÉE en mémoire (--limit
// évite la fuite du DomainFinderService sur de gros volumes), withoutOverlapping (pas
// d'empilement) + runInBackground (process isolé). Le conteneur `scheduler` relance
// le job à l'heure suivante → SURVIT aux redéploiements (robustesse sans systemd).
Schedule::command('media:find-websites --limit=20000')
    ->everyThirtyMinutes()
    ->withoutOverlapping(30)
    ->runInBackground()
    ->onOneServer();

// Rafraîchissement hebdomadaire des registres officiels CPPAP (lundi tôt).
Schedule::command('media:import-opendatasoft cppap')->weeklyOn(1, '02:15')->withoutOverlapping(120)->onOneServer();
Schedule::command('media:import-opendatasoft spel')->weeklyOn(1, '02:30')->withoutOverlapping(120)->onOneServer();
Schedule::command('media:import-opendatasoft agences')->weeklyOn(1, '02:45')->withoutOverlapping(120)->onOneServer();

// Émissions TV/radio FR + présentateurs via Wikidata SPARQL (hebdo, dimanche tôt).
Schedule::command('media:import-emissions-wikidata')->weekly()->sundays()->at('03:00')->withoutOverlapping(180)->runInBackground();

// Radios FM + chaînes TV autorisées par l'ARCOM (niveau station, zone géo) — hebdo.
Schedule::command('media:import-arcom')->weekly()->sundays()->at('03:30')->withoutOverlapping(180)->runInBackground();

// Emails rédaction déterministes (redaction@/contact@) validés MX pour les médias sans email.
// Reprenable + borné en mémoire (--limit) ; toutes les 2h pour rattraper le backlog.
Schedule::command('media:generate-redaction-emails --limit=20000')->everyTwoHours()->withoutOverlapping(120)->runInBackground();

// ── Correctifs audit 2026-07-14 ────────────────────────────────────────────────

// Acquisition des JOURNALISTES (extraction LLM Mistral des pages ours/mentions légales).
// Ciblé sur la presse ÉDITORIALE (pas les boîtes de prod). Gaté par MEDIA_JOURNALISTS_ENABLED.
Schedule::command('journalists:scrape-ours --editorial --limit=300')->dailyAt('05:40')->withoutOverlapping(180)->runInBackground();

// Confiance email A/B/C des médias (même barème que les contacts) — quotidien.
Schedule::command('media:score-confidence')->dailyAt('05:25')->withoutOverlapping(60)->onOneServer();

// Rattachement des émissions TV orphelines à leur chaîne (fallback nom normalisé) — hebdo.
Schedule::command('media:link-emissions-to-channels')->weeklyOn(0, '04:30')->withoutOverlapping(120)->runInBackground();

// Backfill périodicité médias (no-op tant qu'aucune source fiable n'est branchée) — hebdo.
Schedule::command('media:backfill-periodicity')->weeklyOn(1, '03:15')->withoutOverlapping(60)->onOneServer();

// Blogs curés (media_type=blog) — hebdo.
Schedule::command('media:import-blogs')->weeklyOn(1, '03:30')->withoutOverlapping(60)->onOneServer();

// Scoring de confiance email A/B/C (déterministe, sans SMTP) — quotidien.
Schedule::command('prospection:score-email-confidence')->dailyAt('04:45')->withoutOverlapping(60)->onOneServer();

// Rétention : purge des scraper_runs de plus de 90 jours — quotidien.
Schedule::command('retention:prune-scraper-runs --days=90')->dailyAt('04:20')->withoutOverlapping(120)->onOneServer();

// Enrichissement direct des médias (scrape site → emails/tél) — rattrapage continu borné.
// Le gros run initial se lance en systemd shardé ; ici on rattrape les nouveaux médias.
Schedule::command('media:enrich --limit=5000')->everyThreeHours()->withoutOverlapping(180)->runInBackground();

// Purge des emails médias parasites/sur-partagés (plateformes/parking) — quotidien.
Schedule::command('media:clean-emails --threshold=10')->dailyAt('05:05')->withoutOverlapping(60)->onOneServer();

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
            . 'ce drapeau reste ferme (config crm.purges_enabled).',
        );
    }

    return $ferme;
};

Schedule::command('rgpd:purge-vivier')
    ->monthlyOn(2, '03:30')
    ->withoutOverlapping(240)
    ->onOneServer()
    ->skip(fn (): bool => $purgeRgpdRetenue('rgpd:purge-vivier'));

Schedule::command('rgpd:purge-business-prospects')
    ->monthlyOn(2, '04:15')
    ->withoutOverlapping(240)
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
    ->withoutOverlapping(10)
    ->onOneServer()
    ->skip(fn (): bool => ! filter_var(config('crm.outbound_enabled', false), FILTER_VALIDATE_BOOLEAN));
