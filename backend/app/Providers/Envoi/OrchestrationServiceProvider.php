<?php

namespace App\Providers\Envoi;

use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\FeuVert\ListeFeuVert;
use App\Envoi\Orchestration\ChefOrchestre;
use App\Envoi\Orchestration\EssaiABlanc;
use App\Envoi\Orchestration\PorteEnvoiReelle;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Envoi\Sequence\Sequenceur;
use App\Envoi\Sequence\SourceExpeditionDb;
use Illuminate\Support\ServiceProvider;

/** Chef d'orchestre (L8-ORCH) et essai à blanc (L8-ESSAI) : un `Sequenceur` par espace, jamais un singleton (les paramètres d'expédition sont propres à l'espace). */
class OrchestrationServiceProvider extends ServiceProvider
{
    public const FLUX = 'prospection';

    public function register(): void
    {
        $this->app->bind(ChefOrchestre::class, fn ($app) => new ChefOrchestre(
            $app->make(PorteEnvoiReelle::class),
            $app->make(DepotSocle::class),
            $app->make(ListeFeuVert::class),
            fn (string $ws): Sequenceur => $app->make(SourceExpeditionDb::class)->sequenceurPour($ws, $app->make(ServeurEnvoi::class), new LecteurFicheDb, self::FLUX),
        ));
        // L8-ESSAI : jamais un singleton ; la chaîne de l'essai est construite par l'essai lui-même (jamais le `ServeurEnvoi` du conteneur).
        $this->app->bind(EssaiABlanc::class, fn () => new EssaiABlanc);
    }
}
