<?php

namespace App\Envoi\Imap;

/**
 * Point d'entrée des arrêts de L1 (L1 n'est pas encore là : interface seule,
 * plus `RecepteurRetoursCrm` qui applique les arrêts permanents via le CRM).
 *
 * Doit être IDEMPOTENT pour les oppositions (la relève peut rejouer).
 * Lève une exception si l'arrêt n'a pu être appliqué : la relève annule alors
 * l'enregistrement de l'événement, qui sera relu au passage suivant.
 */
interface RecepteurRetours
{
    public function recevoir(string $workspaceId, RetourDetecte $retour): void;
}
