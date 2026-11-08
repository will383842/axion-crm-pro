<?php

namespace App\Envoi\Desinscription;

/**
 * Liaison par défaut tant que la file d'envoi n'existe pas (aucun message en
 * file à retirer). La garde `EnvoiAutorise` + la liste de suppression bloquent
 * déjà tout départ ; le lot qui crée la file la remplace dans le conteneur.
 */
final class RetraitEnvoisEnFileAbsent implements RetraitEnvoisEnFile
{
    public function retirerPour(string $workspaceId, string $empreinteAdresse): int
    {
        return 0;
    }
}
