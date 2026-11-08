<?php

namespace App\Envoi\Desinscription;

/** Retire de la file les envois non partis d'une personne (cible : < 5 minutes). Idempotent. */
interface RetraitEnvoisEnFile
{
    /** @return int nombre d'envois retirés */
    public function retirerPour(string $workspaceId, string $empreinteAdresse): int;
}
