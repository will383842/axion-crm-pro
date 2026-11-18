<?php

namespace App\Envoi\Imap;

/**
 * Point d'entrée des retours détectés par la relève IMAP. Liaison actuelle : `RecepteurRetoursEnChaine`
 * (CRM, freins, séquences, réponses ; voir `EnvoiServiceProvider`).
 *
 * Doit être IDEMPOTENT pour les oppositions (la relève peut rejouer).
 * Lève une exception si l'arrêt n'a pu être appliqué : la relève annule alors
 * l'enregistrement de l'événement, qui sera relu au passage suivant.
 */
interface RecepteurRetours
{
    public function recevoir(string $workspaceId, RetourDetecte $retour): void;
}
