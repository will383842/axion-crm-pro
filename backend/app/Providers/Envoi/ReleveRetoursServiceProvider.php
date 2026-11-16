<?php

namespace App\Providers\Envoi;

use App\Envoi\Imap\DepotAccesImap;
use App\Envoi\Imap\FabriqueLecteurs;
use App\Envoi\Imap\FabriqueLecteursBoites;
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
        // Remplace FabriqueLecteursNonFournie (liée avant ce fournisseur) : inerte tant que les drapeaux d'envoi sont éteints.
        $this->app->bind(FabriqueLecteurs::class, FabriqueLecteursBoites::class);
    }

    public function boot(): void
    {
        // Rien à démarrer pour l'instant.
    }
}
