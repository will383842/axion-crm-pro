<?php

namespace App\Envoi\Exceptions;

use RuntimeException;

/** Refus de la passerelle locale : rien n'est parti. */
final class EnvoiRefuse extends RuntimeException
{
    /** Code de l'issue incertaine : le message est peut-être parti, il ne repart JAMAIS tout seul. */
    public const ISSUE_INCERTAINE = 4201;

    /** Code d'un refus d'ATTENTE (porte fermée, qui se rouvre d'elle-même) : report sans compteur ni limite. */
    public const ATTENTE = 4202;

    /** Code d'un refus DÉFINITIF pour opposition (STOP, liste de suppression) : ni attente, ni plafond, jamais de renvoi. */
    public const OPPOSITION = 4203;

    /** Vrai si l'alerte « issue incertaine » a déjà été émise par la passerelle pour ce même incident (réservation périmée). */
    public bool $alerteDejaEmise = false;

    public static function issueIncertaine(bool $alerteDejaEmise = false): self
    {
        $e = new self('Issue incertaine d\'une injection précédente (a_verifier) : aucun renvoi automatique.', self::ISSUE_INCERTAINE);
        $e->alerteDejaEmise = $alerteDejaEmise;

        return $e;
    }

    /** Vrai pour une opposition constatée par la passerelle : refus définitif, le stop gagne toujours. */
    public function estOpposition(): bool
    {
        return $this->getCode() === self::OPPOSITION;
    }

    public static function opposition(): self
    {
        return new self('Opposition constatée sur l\'adresse : aucune injection.', self::OPPOSITION);
    }

    /** Refus d'attente : envoi réel éteint, arrêt général, mode témoins. */
    public function estAttente(): bool
    {
        return $this->getCode() === self::ATTENTE;
    }

    public static function envoiReelInactif(): self
    {
        return new self('Envoi réel inactif (ENVOI_REEL_ACTIF éteint) : aucune connexion tentée.', self::ATTENTE);
    }

    public static function toutArreter(): self
    {
        return new self('Tout arrêter est actif : aucune injection.', self::ATTENTE);
    }

    public static function horsTemoins(): self
    {
        return new self('Mode témoins : destinataire hors de la liste des témoins.', self::ATTENTE);
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
