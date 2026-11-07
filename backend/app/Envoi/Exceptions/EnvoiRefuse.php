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

    public static function cleEmpreinteAbsente(): self
    {
        return new self('Clé d\'empreinte des témoins absente (ENVOI_CLE_EMPREINTE) : refus.');
    }

    public static function autreEspace(): self
    {
        return new self('Le message appartient à un autre espace que le contexte courant : refus.');
    }

    public static function plafondInvalide(int $valeur, int $max): self
    {
        return new self("Plafond d'injection invalide ({$valeur}) : attendu entre 0 et {$max}.");
    }
}
