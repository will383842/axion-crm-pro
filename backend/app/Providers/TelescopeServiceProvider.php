<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    public function register(): void
    {
        // Telescope chargé seulement en local / testing ; .env TELESCOPE_ENABLED=false coupe en prod.
        if (! $this->app->environment('local', 'testing')) {
            return;
        }
        parent::register();
    }

    protected function gate(): void
    {
        Gate::define('viewTelescope', function ($user) {
            return $user && $user->hasRole('owner');
        });
    }
}
