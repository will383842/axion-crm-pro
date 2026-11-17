<?php

namespace App\Providers\Envoi;

use App\Envoi\FeuVert\ListeFeuVert;
use App\Envoi\Orchestration\PorteEnvoiReelle;
use App\Envoi\Serveur\AiguilleurServeurEnvoi;
use App\Envoi\Serveur\FabriqueServeurKumo;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use Illuminate\Support\ServiceProvider;

/**
 * K17-AIG (D11) : relie SEULEMENT le service `envoi.serveur_interne` à l'aiguilleur par domaine. `FauxServeurEnvoi` reste le seul
 * adaptateur construit tant que la porte est fermée. C3-5 : la fabrique `kumo` est liée, inerte (aucune connexion à la construction ;
 * jamais appelée porte fermée, sauf `pause()`) ; `smtp` n'est pas lié (refusé « adaptateur_indisponible »).
 */
class AiguillageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('envoi.serveur_interne', fn ($app) => new AiguilleurServeurEnvoi(
            $app->make(FauxServeurEnvoi::class),
            // C3-5 : seule la fabrique `kumo` est liée (inerte : appelée seulement porte ouverte + feu vert + DNS vert, sauf `pause()`,
            // sens sûr). `smtp` reste NON lié (décision E4) : un domaine smtp est refusé « adaptateur_indisponible ».
            ['kumo' => $app->make(FabriqueServeurKumo::class)(...)],
            fn (): bool => $app->make(PorteEnvoiReelle::class)->ouverte(),
            fn (): ListeFeuVert => $app->make(ListeFeuVert::class),
        ));
    }
}
