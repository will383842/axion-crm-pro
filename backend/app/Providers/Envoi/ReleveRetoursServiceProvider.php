<?php

namespace App\Providers\Envoi;

use App\Envoi\Imap\DepotAccesImap;
use Illuminate\Support\ServiceProvider;

/**
 * Point d'entrée réservé au lot « relève des retours (rebonds, plaintes, réponses reçues) ».
 * Vide à dessein (lot V0 préparatoire) : le lot y ajoute ses liaisons sans toucher à EnvoiServiceProvider.
 */
class ReleveRetoursServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DepotAccesImap::class);
    }

    public function boot(): void
    {
        // Rien à démarrer pour l'instant.
    }
}
