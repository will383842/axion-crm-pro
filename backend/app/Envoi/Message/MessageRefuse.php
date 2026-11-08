<?php

namespace App\Envoi\Message;

use RuntimeException;

/** Message refusé : `motifs` liste, en français lisible, ce qui bloque l'envoi. */
final class MessageRefuse extends RuntimeException
{
    /** @param list<string> $motifs */
    public function __construct(public readonly array $motifs)
    {
        parent::__construct('Message refusé : ' . implode(' ; ', $motifs));
    }
}
