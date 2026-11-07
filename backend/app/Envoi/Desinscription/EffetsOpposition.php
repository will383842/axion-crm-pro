<?php

namespace App\Envoi\Desinscription;

/** Écouteur de `OppositionEnregistree` : retrait de la file puis arrêt par entreprise. */
final class EffetsOpposition
{
    public function __construct(
        private readonly RetraitEnvoisEnFile $retrait,
        private readonly ArretParEntreprise $arret,
    ) {}

    public function handle(OppositionEnregistree $e): void
    {
        $this->retrait->retirerPour($e->workspaceId, $e->empreinteAdresse);
        $this->arret->declencher($e->workspaceId, $e->empreinteAdresse);
    }
}
