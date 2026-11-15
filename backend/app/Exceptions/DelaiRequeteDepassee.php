<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Délai SQL dépassé (SQLSTATE 57014), SANS la QueryException d'origine.
 *
 * Le message d'une QueryException embarque le SQL ET les valeurs liées (empreintes, adresses chiffrées, auteur) :
 * relancée, elle fuirait vers laravel.log, stderr et Sentry. Cette exception porte un message fixe et n'a
 * JAMAIS d'exception précédente. Rendue en 503 « requete_trop_longue » par bootstrap/app.php.
 */
final class DelaiRequeteDepassee extends RuntimeException
{
    /** @param  ?string  $messageEcran  texte rendu au client (fixe, sans valeur) ; par défaut celui des recherches */
    public function __construct(public readonly ?string $messageEcran = null)
    {
        parent::__construct('Délai de requête dépassé.');
    }
}
