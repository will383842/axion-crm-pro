<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Services\Email\EmailConfidenceService;
use App\Services\Email\HunterEmailVerifier;
use App\Services\Email\MxEmailValidator;
use App\Services\Scraping\GooglePlacesClient;
use App\Support\RelocalisationPartman;
use App\Support\WorkspaceContext;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // `WaterfallOrchestrator` déclare `?GooglePlacesClient $googlePlaces = null`.
        // Depuis Laravel 11, `Container::resolveClass()` rend la VALEUR PAR DÉFAUT
        // dès qu'un défaut existe et que la classe n'est pas explicitement bindée —
        // il n'auto-résout plus (avant, il tentait `make()` d'abord et ne retombait
        // sur le défaut qu'en cas de BindingResolutionException). Résultat : la
        // dépendance valait TOUJOURS null, et `step3d_google_places()` sortait à la
        // première ligne — l'enrichissement Google Places (Sprint H9, qui remplace
        // le scrape Google Maps Node) était mort en production, en silence.
        // Ce binding explicite rétablit l'injection. Ne pas le retirer sans rendre
        // la dépendance non-nullable dans le constructeur de l'orchestrateur.
        $this->app->singleton(GooglePlacesClient::class);

        // ── LE MÊME PIÈGE, À TROIS AUTRES ENDROITS (constaté le 2026-08-16) ──
        //
        // Google Places n'était pas un cas isolé. Sonde exécutée EN PRODUCTION,
        // par réflexion sur les instances résolues par le conteneur :
        //
        //   MentionsLegalesScraperService::$emailValidator   NULL
        //   EmailFinderService::$hunterVerifier              NULL
        //   MediaEnrich::$mx                                 NULL
        //   MediaEnrich::$confidence                         NULL
        //
        // Ce que ces `null` coûtaient réellement :
        //
        // · `MentionsLegalesScraperService:141-148` — le bloc de validation MX
        //   est gardé par `if ($this->emailValidator !== null)`. Il n'a JAMAIS
        //   été exécuté : chaque contact issu du scraping mentions-légales est
        //   persisté avec `email_status = 'unknown'` figé, sans filtrage des
        //   adresses invalides ou jetables. La doctrine « 0 email douteux »,
        //   citée dans `config/services.php` et dans les docblocks, n'était pas
        //   appliquée sur le chemin d'enrichissement principal.
        //
        // · `MediaEnrich` tourne toutes les 3 h sur 5 000 médias
        //   (`routes/console.php`). Sans `$mx` : aucun rejet des jetables.
        //   Sans `$confidence` : `email_confidence` jamais écrit. Et
        //   `pickBestEmail()` retombait toujours sur le pool brut, la priorité
        //   « même domaine que le site » — celle qui écarte les agences web et
        //   les domaines de parking — ne s'appliquant jamais.
        //
        // ⚠️ Aucun de ces trois services n'appelle d'API facturée :
        // `MxEmailValidator` fait des résolutions DNS, `EmailConfidenceService`
        // est du calcul pur. La leçon de Google Places — « rallumer un appel
        // FACTURÉ ne doit jamais être l'effet de bord d'une réparation » — a
        // été revérifiée ici avant de poser ces bindings.
        //
        // ⚠️ `HunterEmailVerifier`, lui, EST facturé. Il est bindé pour la
        // cohérence du conteneur, mais reste sans effet opérationnel :
        // `EmailFinderService::verifyEmail()` n'a AUCUN appelant applicatif
        // (le chemin Hunter réellement emprunté passe par `HunterSmtpProber`,
        // dont la dépendance est non-nullable et donc déjà injectée).
        $this->app->singleton(MxEmailValidator::class);
        $this->app->singleton(EmailConfidenceService::class);
        $this->app->singleton(HunterEmailVerifier::class);
    }

    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // ── B10-001 (S1) : LE CORRECTIF DE RECONSTRUCTION ETAIT INATTEIGNABLE ──
        //
        // `migrate:fresh` fait, dans cet ordre : (1) `db:wipe`, qui emet UN SEUL
        // `DROP TABLE … CASCADE` sur tout le `search_path` ; (2) les migrations.
        // Sur une base ou pg_partman vit dans `public`, l'etape 1 echoue
        // (SQLSTATE 2BP01, « cannot drop table part_config ») et l'etape 2 n'a
        // JAMAIS lieu : la migration `2026_08_18_100001` qui relocalise
        // l'extension ne peut pas s'executer, precisement sur les bases qui en
        // ont besoin. Une migration ne repare pas ce qui empeche les migrations
        // de tourner.
        //
        // `CommandStarting` est le seul crochet qui tombe AVANT `db:wipe`. Il
        // couvre les deux chemins : `php artisan migrate:fresh` (Makefile,
        // deploiement) ET `RefreshDatabase`, qui appelle `migrate:fresh` et
        // n'ouvre jamais le Makefile — donc toute la suite Pest.
        //
        // Le geste est idempotent, ne leve jamais, et s'abstient des que
        // pg_partman gere reellement des partitions (cf. RelocalisationPartman).
        Event::listen(CommandStarting::class, function (CommandStarting $evenement): void {
            if ($evenement->command !== 'migrate:fresh') {
                return;
            }

            $connexion = $evenement->input->hasParameterOption('--database')
                ? (string) $evenement->input->getParameterOption('--database')
                : '';

            RelocalisationPartman::jouer($connexion === '' ? null : $connexion);
        });

        // ── LE REFUS DE DEBOGAGE NE DOIT PAS ETRE SILENCIEUX — F37-003 (S1) ──
        //
        // `config/app.php` neutralise `APP_DEBUG=true` hors `local`/`testing`.
        // Sans ce signal, un opérateur qui pose `APP_DEBUG=true` sur la
        // préproduction pour voir une trace ne verrait RIEN et ne comprendrait
        // pas pourquoi : il chercherait la panne ailleurs, ou il « réparerait »
        // la garde. Un refus muet est la moitié d'un défaut.
        //
        // Le drapeau est lu dans la CONFIGURATION et non par `env()` : en
        // production `config:cache` est actif et `env()` hors configuration rend
        // `null`, donc un signal bâti sur `env('APP_DEBUG')` ne se déclencherait
        // jamais là où il sert.
        if (config('app.debug_refuse') === true) {
            Log::critical(
                'Debogage REFUSE hors poste de developpement : APP_DEBUG est a vrai en '
                . 'environnement « ' . $this->app->environment() . ' ». Constat F37-003 (S1) : '
                . "une page de debogage Laravel affiche chaque variable d'environnement du "
                . 'processus (DB_PASSWORD, APP_KEY, jetons tiers), la configuration resolue et '
                . "le code source du chemin d'appel — et la preproduction est servie "
                . "PUBLIQUEMENT par le Caddy de production, sans aucun filtre d'acces. "
                . 'Le debogage reste desactive. Corrigez la configuration du conteneur '
                . '(docker inspect), pas le .env : `docker compose restart` ne relit pas '
                . 'env_file.',
                ['environnement' => $this->app->environment()],
            );
        }

        // Sanctum : tokenable_id UUID (migration 000002) → custom PAT model
        // qui force `morphTo()` à utiliser User UUID.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Lot L0 — un worker Horizon garde sa connexion Postgres ouverte entre
        // deux jobs. Sans ce nettoyage, le contexte workspace d'un job fuirait
        // sur le suivant (un job « vivier » lirait la base business, ou
        // l'inverse). On repart donc systématiquement d'un contexte VIDE :
        // chaque job doit poser le sien explicitement (trait RunsInWorkspace).
        Queue::looping(function (): void {
            try {
                WorkspaceContext::clear();
            } catch (\Throwable) {
                // Une connexion DB indisponible ne doit pas tuer la boucle du worker.
            }
        });
    }
}
