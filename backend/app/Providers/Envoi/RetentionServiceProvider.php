<?php

namespace App\Providers\Envoi;

use App\Envoi\Depot\RetentionTentatives;
use Illuminate\Support\ServiceProvider;

/** Conservation des tentatives d'envoi (R06-ARCH, D16). */
class RetentionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RetentionTentatives::class);
    }
}
