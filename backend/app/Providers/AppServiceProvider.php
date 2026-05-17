<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use App\Models\PersonalAccessToken;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Sanctum : tokenable_id UUID (migration 000002) → custom PAT model
        // qui force `morphTo()` à utiliser User UUID.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }
}
