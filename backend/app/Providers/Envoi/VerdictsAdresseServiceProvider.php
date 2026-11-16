<?php

namespace App\Providers\Envoi;

use Illuminate\Support\ServiceProvider;

/**
 * Point d'entrée réservé au lot « verdicts d'adresse (vérification des adresses avant envoi) ».
 * Vide à dessein (lot V0 préparatoire) : le lot y ajoute ses liaisons sans toucher à EnvoiServiceProvider.
 */
class VerdictsAdresseServiceProvider extends ServiceProvider
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
