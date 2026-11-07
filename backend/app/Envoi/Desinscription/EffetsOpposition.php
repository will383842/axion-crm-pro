<?php

namespace App\Envoi\Desinscription;

/** Écouteur de `OppositionEnregistree` : retrait de la file puis arrêt par entreprise. */
final class EffetsOpposition
{
    public function __construct(
        private readonly RetraitEnvoisEnFile $retrait,
        private readonly ArretParEntreprise $arret,
    ) {}

    /** Les DEUX effets sont tentés même si le premier échoue ; la première erreur est relancée ensuite (l'opposition reste « à terminer »). */
    public function handle(OppositionEnregistree $e): void
    {
        $premiere = null;
        try {
            $this->retrait->retirerPour($e->workspaceId, $e->empreinteAdresse);
        } catch (\Throwable $t) {
            $premiere = $t;
        }
        try {
            $this->arret->declencher($e->workspaceId, $e->empreinteAdresse);
        } catch (\Throwable $t) {
            $premiere ??= $t;
        }
        if ($premiere !== null) {
            throw $premiere;
        }
    }
}
