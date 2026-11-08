<?php

namespace App\Envoi\Desinscription;

/**
 * Point d'accroche du lot L1 : l'opposition d'une personne déclenche l'arrêt
 * des envois à son entreprise. Idempotent. Implémenté par L1.
 */
interface ArretParEntreprise
{
    public function declencher(string $workspaceId, string $empreinteAdresse): void;
}
