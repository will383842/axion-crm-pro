<?php

namespace App\Providers;

use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Desinscription\ArretParEntreprise;
use App\Envoi\Desinscription\ArretParEntrepriseAbsent;
use App\Envoi\Desinscription\EffetsOpposition;
use App\Envoi\Desinscription\OppositionEnregistree;
use App\Envoi\Desinscription\RetraitEnvoisEnFile;
use App\Envoi\Desinscription\RetraitEnvoisEnFileAbsent;
use App\Envoi\PasserelleLocale;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Palier 0 : le seul transport branché est le simulateur. */
class EnvoiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DepotSocle::class, DepotSocleDb::class);
        $this->app->singleton(ServeurEnvoi::class, fn ($app) => new PasserelleLocale(
            new FauxServeurEnvoi,
            $app->make(DepotSocle::class),
            fn (): bool => (bool) config('envoi.reel_actif'),
            fn (): int => (int) config('envoi.plafond_jour'),
            null,
            '',
            fn (): int => (int) config('envoi.plafond_global_jour'),
            fn (): ?string => WorkspaceContext::current(),
        ));

        // L5 désinscription : liaisons par défaut, remplacées par la file (retrait) et par L1 (arrêt par entreprise).
        $this->app->bind(RetraitEnvoisEnFile::class, RetraitEnvoisEnFileAbsent::class);
        $this->app->bind(ArretParEntreprise::class, ArretParEntrepriseAbsent::class);
    }

    public function boot(): void
    {
        Event::listen(OppositionEnregistree::class, EffetsOpposition::class);
    }
}
