<?php

namespace App\Envoi\Exceptions;

use RuntimeException;

/** Refus de la passerelle locale : rien n'est parti. */
final class EnvoiRefuse extends RuntimeException
{
    public static function envoiReelInactif(): self
    {
        return new self('Envoi réel inactif (ENVOI_REEL_ACTIF éteint) : aucune connexion tentée.');
    }

    public static function toutArreter(): self
    {
        return new self('Tout arrêter est actif : aucune injection.');
    }

    public static function horsTemoins(): self
    {
        return new self('Mode témoins : destinataire hors de la liste des témoins.');
    }
}
