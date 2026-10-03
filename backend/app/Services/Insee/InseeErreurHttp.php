<?php

namespace App\Services\Insee;

/**
 * Une réponse Sirene en échec, dite par son STATUT et son CHEMIN seulement
 * (relecture sécurité #313, réserve 7) : jamais le corps de la réponse, qui
 * finit dans `insee_mises_a_jour.erreur` et dans les journaux.
 */
final class InseeErreurHttp extends \RuntimeException
{
    /**
     * @param  bool  $tropVolumineuse  corps au-delà de `HttpInseeClient::REPONSE_MAX_OCTETS`
     *                                 (non lu au-delà) : le flux redemande la page plus petite
     */
    public function __construct(
        public readonly int $statut,
        public readonly string $chemin,
        string $precision = '',
        public readonly bool $tropVolumineuse = false,
    ) {
        parent::__construct(trim("INSEE {$statut} sur {$chemin} {$precision}"));
    }
}
