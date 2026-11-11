<?php

namespace App\Envoi\Reglages;

use RuntimeException;

/** Une demande de réglage qui rendrait l'envoi moins prudent : refusée (422 `assouplissement_refuse`), rien n'est écrit. */
final class AssouplissementRefuse extends RuntimeException
{
    /** @param list<string> $champs */
    public function __construct(public readonly array $champs)
    {
        parent::__construct('Cette modification assouplirait une règle de prudence (' . implode(', ', $champs) . ') : seul un durcissement est accepté ici.');
    }
}
