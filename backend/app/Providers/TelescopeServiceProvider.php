<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

/**
 * Telescope est désactivé par défaut en prod via `TELESCOPE_ENABLED=false` (.env).
 * Le provider lui-même reste enregistré pour permettre de l'activer en staging
 * sans redéploiement.
 */
class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    public function register(): void
    {
        // Skip enregistrement complet si désactivé (évite chargement libs lourdes en prod).
        if (! (bool) env('TELESCOPE_ENABLED', false)) {
            return;
        }
        parent::register();
        Telescope::night();
    }

    public function boot(): void
    {
        if (! (bool) env('TELESCOPE_ENABLED', false)) {
            return;
        }
        parent::boot();
    }

    protected function gate(): void
    {
        Gate::define('viewTelescope', function ($user) {
            return $user && $user->hasRole('owner');
        });
    }
}
