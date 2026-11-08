<?php

namespace App\Envoi\Serveur\Contrat;

use DateTimeImmutable;

/** Tirage par curseur (rattrapage). Interface NOUVELLE : `ServeurEnvoi` n'est pas touchée. */
interface TireurEvenements
{
    public function tirer(string $workspaceId, string $source, ?string $curseur, int $limite, DateTimeImmutable $depuis): LotSuivi;
}
