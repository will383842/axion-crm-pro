<?php

namespace App\Providers\Envoi;

use App\Envoi\Desinscription\RetraitEnvoisEnFile;
use App\Envoi\Desinscription\RetraitEnvoisEnFileServeur;
use App\Envoi\Serveur\Kumo\RegistreInjections;
use App\Envoi\Serveur\Kumo\RegistreInjectionsDb;
use Illuminate\Support\ServiceProvider;

/**
 * Point d'entrée réservé au lot « transport (acheminement vers le serveur d'envoi) ».
 * Vide à dessein (lot V0 préparatoire) : le lot y ajoute ses liaisons sans toucher à EnvoiServiceProvider.
 */
class TransportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // C3-2 : registre durable de l'adaptateur Kumo (inerte : aucune fabrique réelle liée, porte fermée).
        $this->app->bind(RegistreInjections::class, RegistreInjectionsDb::class);
        // C3-3 : retrait réel lié (boîte de retraits ; aucun réseau dans la transaction d'opposition). Enregistré APRÈS
        // EnvoiServiceProvider, il remplace la liaison « absente ». La porte d'envoi réel reste fermée (ENVOI_REEL_ACTIF faux).
        $this->app->bind(RetraitEnvoisEnFile::class, RetraitEnvoisEnFileServeur::class);
    }

    public function boot(): void
    {
        // Rien à démarrer pour l'instant.
    }
}
