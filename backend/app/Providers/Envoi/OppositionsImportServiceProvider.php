<?php

namespace App\Providers\Envoi;

use App\Envoi\Conformite\ImportOppositions;
use Illuminate\Support\ServiceProvider;

/** P1-LN-B : import des listes de refus (D8). */
class OppositionsImportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ImportOppositions::class);
    }
}
