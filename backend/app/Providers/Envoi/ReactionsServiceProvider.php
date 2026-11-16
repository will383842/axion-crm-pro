<?php

namespace App\Providers\Envoi;

use Illuminate\Support\ServiceProvider;

/**
 * Point d'entrée réservé au lot « réactions (effets des retours sur les envois et les contacts) ».
 * Vide à dessein (lot V0 préparatoire) : le lot y ajoute ses liaisons sans toucher à EnvoiServiceProvider.
 */
class ReactionsServiceProvider extends ServiceProvider
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
