<?php

namespace App\Services\Insee;

/**
 * Une réponse Sirene en échec, dite par son STATUT et son CHEMIN seulement
 * (relecture sécurité #313, réserve 7) : jamais le corps de la réponse, qui
 * finit dans `insee_mises_a_jour.erreur` et dans les journaux.
 */
final class InseeErreurHttp extends \RuntimeException
{
    public function __construct(public readonly int $statut, public readonly string $chemin, string $precision = '')
    {
        parent::__construct(trim("INSEE {$statut} sur {$chemin} {$precision}"));
    }
}
