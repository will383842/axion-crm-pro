<?php

namespace App\Envoi\Imap;

/**
 * Une page de la boîte. La pagination se fait sur les UID BRUTS : `dernierUid` est le plus grand UID
 * EXAMINÉ (même si le message a été filtré par la date), `suite` dit s'il reste des UID au-delà.
 * Un lot entièrement filtré n'est donc jamais pris pour la fin de la boîte.
 */
final class LotBoite
{
    /** @param list<MessageBrut> $messages */
    public function __construct(
        public readonly array $messages,
        public readonly int $dernierUid,
        public readonly bool $suite,
    ) {}
}
