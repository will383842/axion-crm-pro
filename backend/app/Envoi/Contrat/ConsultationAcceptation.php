<?php

namespace App\Envoi\Contrat;

/**
 * Optionnel : un serveur qui sait dire, SANS rien envoyer, si un message (même clé) a déjà été accepté.
 * Sert à la reprise après plantage : un message déjà parti se conclut « envoyé », même si une garde se ferme entre-temps.
 */
interface ConsultationAcceptation
{
    public function dejaAccepte(string $workspaceId, string $cleIdempotence): ?ResultatInjection;
}
