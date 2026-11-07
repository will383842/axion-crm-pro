<?php

namespace App\Providers;

use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\PasserelleLocale;
use App\Envoi\Serveur\ClientServeurEnvoiHmac;
use App\Envoi\Serveur\Contrat\CommandeurServeur;
use App\Envoi\Serveur\Contrat\TireurEvenements;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Envoi\Smtp\ResolveurDns;
use App\Envoi\Smtp\ResolveurHote;
use App\Support\WorkspaceContext;
use Illuminate\Support\ServiceProvider;

/** Palier 0 : le seul transport branché est le simulateur. */
class EnvoiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DepotSocle::class, DepotSocleDb::class);
        // K1 — client signé HMAC : tirage par curseur et commandes (interfaces NOUVELLES ;
        // `ServeurEnvoi` n'a reçu aucune méthode).
        $this->app->bind(ResolveurHote::class, ResolveurDns::class);
        $this->app->bind(ClientServeurEnvoiHmac::class);
        $this->app->bind(TireurEvenements::class, ClientServeurEnvoiHmac::class);
        $this->app->bind(CommandeurServeur::class, ClientServeurEnvoiHmac::class);
        $this->app->singleton(ServeurEnvoi::class, fn ($app) => new PasserelleLocale(
            new FauxServeurEnvoi,
            $app->make(DepotSocle::class),
            fn (): bool => (bool) config('envoi.reel_actif'),
            fn (): int => (int) config('envoi.plafond_jour'),
            null,
            '',
            fn (): int => (int) config('envoi.plafond_global_jour'),
            fn (): ?string => WorkspaceContext::current(),
        ));
    }
}
