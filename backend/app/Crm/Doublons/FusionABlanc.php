<?php

namespace App\Crm\Doublons;

use RuntimeException;

/**
 * L'essai à blanc passe par EXACTEMENT le même chemin, puis annule sa
 * transaction en levant cette exception : ce qu'il annonce est ce que la base
 * a accepté, déclencheurs compris.
 */
final class FusionABlanc extends RuntimeException
{
    /** @param  array<string, int>  $bilan */
    public function __construct(public readonly int $fusionId, public readonly array $bilan = [])
    {
        parent::__construct('essai à blanc');
    }
}
