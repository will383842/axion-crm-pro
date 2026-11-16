<?php

namespace App\Providers\Envoi;

use App\Envoi\FeuVert\ListeFeuVert;
use App\Envoi\Orchestration\PorteEnvoiReelle;
use App\Envoi\Serveur\AiguilleurServeurEnvoi;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use Illuminate\Support\ServiceProvider;

/**
 * K17-AIG (D11) : relie SEULEMENT le service `envoi.serveur_interne` à l'aiguilleur par domaine. `FauxServeurEnvoi` reste le seul
 * adaptateur construit tant que la porte est fermée ; aucun adaptateur réel n'est lié ici (les fabriques sont vides : un domaine
 * `kumo` ou `smtp` est refusé « adaptateur_indisponible » tant qu'une PR ultérieure, avec audit, ne les branche pas).
 */
class AiguillageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('envoi.serveur_interne', fn ($app) => new AiguilleurServeurEnvoi(
            $app->make(FauxServeurEnvoi::class),
            [], // array<string, Closure(string $workspaceId, string $domaine): ServeurEnvoi> : vide, aucune fabrique réelle liée
            fn (): bool => $app->make(PorteEnvoiReelle::class)->ouverte(),
            fn (): ListeFeuVert => $app->make(ListeFeuVert::class),
        ));
    }
}
