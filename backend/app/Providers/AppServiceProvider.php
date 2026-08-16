<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
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
