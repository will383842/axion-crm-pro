<?php

namespace App\Envoi\Sequence;

/** Ce que le composeur reçoit pour UN message de séquence (fil de relance inclus). */
final class DemandeComposition
{
    /** @param  list<string>  $references */
    public function __construct(
        public readonly string $workspaceId,
        public readonly string $destinataire,
        public readonly string $expediteur,
        public readonly string $sujet,
        public readonly string $corps,
        public readonly string $messageId,
        public readonly ?string $inReplyTo,
        public readonly array $references,
    ) {}
}
