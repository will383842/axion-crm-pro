<?php

namespace App\Envoi\Imap;

use RuntimeException;

/**
 * Au moins un message a échoué pour une raison qui lui est propre pendant la relève. Les autres ont été
 * traités ; le curseur est resté AVANT le premier échec (il sera relu). Après `ReleveImap::MAX_TENTATIVES`
 * échecs, le message est mis à l'écart (« à rattacher », revue humaine) et cette exception ne se lève plus.
 */
final class ReleveIncomplete extends RuntimeException
{
    public function __construct(public readonly BilanReleve $bilan)
    {
        parent::__construct('Relève IMAP incomplète : ' . $bilan->echecs . ' message(s) en échec, relus au prochain passage.');
    }
}
