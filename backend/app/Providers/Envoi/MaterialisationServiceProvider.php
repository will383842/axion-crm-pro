<?php

namespace App\Providers\Envoi;

use Illuminate\Support\ServiceProvider;

/**
 * Point d'entrée réservé au lot « matérialisation des envois (file d'envois à partir des décisions) ».
 * Vide à dessein (lot V0 préparatoire) : le lot y ajoute ses liaisons sans toucher à EnvoiServiceProvider.
 */
class MaterialisationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Aucune liaison pour l'instant.
    }

    public function boot(): void
    {
        // Rien à démarrer pour l'instant.
    }
}
