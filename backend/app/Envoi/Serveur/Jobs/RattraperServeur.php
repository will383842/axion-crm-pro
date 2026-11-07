<?php

namespace App\Envoi\Serveur\Jobs;

use App\Envoi\Serveur\Contrat\TireurEvenements;
use App\Envoi\Serveur\Rattrapage;
use App\Envoi\Serveur\RecepteurEvenements;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Trou détecté dans un lot poussé : tirage par curseur. */
final class RattraperServeur implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $workspaceId, public readonly string $source)
    {
        $this->afterCommit();
    }

    public function handle(TireurEvenements $tireur, RecepteurEvenements $recepteur): void
    {
        (new Rattrapage($tireur, $recepteur))->executer($this->workspaceId, $this->source);
    }
}
