<?php

namespace App\Providers;

use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Chauffe\GardeChauffe;
use App\Envoi\Chauffe\ToutArreter;
use App\Socle\Alertes\Alerte;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\PasserelleLocale;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Support\WorkspaceContext;
use Illuminate\Support\ServiceProvider;

/** Palier 0 : le seul transport branché est le simulateur. */
class EnvoiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DepotSocle::class, DepotSocleDb::class);
        $this->app->singleton(DepotChauffe::class);
        $this->app->singleton(GardeChauffe::class, fn ($app) => new GardeChauffe($app->make(DepotChauffe::class), $app->make(DepotSocle::class), $app->make(Alerte::class)));
        $this->app->singleton(ToutArreter::class, fn ($app) => new ToutArreter($app->make(DepotSocle::class), $app->make(ServeurEnvoi::class), $app->make(Alerte::class)));
        $this->app->singleton(ServeurEnvoi::class, fn ($app) => new PasserelleLocale(
            new FauxServeurEnvoi,
            $app->make(DepotSocle::class),
            fn (): bool => (bool) config('envoi.reel_actif'),
            fn (): int => (int) config('envoi.plafond_jour'),
            null,
            '',
            fn (): int => (int) config('envoi.plafond_global_jour'),
            fn (): ?string => WorkspaceContext::current(),
            $app->make(GardeChauffe::class),
        ));
    }
}
