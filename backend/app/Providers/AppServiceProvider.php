<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Services\Email\EmailConfidenceService;
use App\Services\Email\HunterEmailVerifier;
use App\Services\Email\MxEmailValidator;
use App\Services\Scraping\GooglePlacesClient;
use App\Support\WorkspaceContext;
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
