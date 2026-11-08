<?php

namespace App\Envoi\Contrat;

/** Message à injecter. Aucun suivi d'ouverture : ni pixel, ni lien traqué. */
final class MessageAEnvoyer
{
    public function __construct(
        public readonly string $workspaceId,
        public readonly string $destinataire,
        public readonly string $expediteur,
        public readonly string $sujet,
        public readonly string $corps,
        public readonly string $cleIdempotence,
    ) {}
}
