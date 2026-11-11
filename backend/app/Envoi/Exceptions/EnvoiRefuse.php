<?php

namespace App\Envoi\Exceptions;

use RuntimeException;

/** Refus de la passerelle locale : rien n'est parti. */
final class EnvoiRefuse extends RuntimeException
{
    /** Code de l'issue incertaine : le message est peut-être parti, il ne repart JAMAIS tout seul. */
    public const ISSUE_INCERTAINE = 4201;

    public static function issueIncertaine(): self
    {
        return new self('Issue incertaine d\'une injection précédente (a_verifier) : aucun renvoi automatique.', self::ISSUE_INCERTAINE);
    }

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
