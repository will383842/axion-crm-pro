<?php

namespace App\Envoi\Desinscription;

/** Liaison par défaut tant que L1 n'est pas livré : ne fait rien. */
final class ArretParEntrepriseAbsent implements ArretParEntreprise
{
    public function declencher(string $workspaceId, string $empreinteAdresse): void {}
}
