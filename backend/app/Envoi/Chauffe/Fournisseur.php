<?php

namespace App\Envoi\Chauffe;

/**
 * Famille de fournisseur du DESTINATAIRE. Elle ne se déduit PAS du découpage du domaine
 * (un domaine d'entreprise peut être hébergé chez Google ou Microsoft) : seul un
 * {@see ResolveurFournisseur} (résolution MX) la donne.
 */
final class Fournisseur
{
    public const GMAIL = 'gmail';

    public const MICROSOFT = 'microsoft';

    public const AUTRE = 'autre';

    /**
     * Résolution IMPOSSIBLE (échec DNS) : jamais stocké ni compté (hors de TOUS) ; traité comme « peut-être
     * n'importe lequel » — toute pause de fournisseur le bloque (fail-closed).
     */
    public const INCONNU = 'inconnu';

    public const TOUS = [self::GMAIL, self::MICROSOFT, self::AUTRE];

    /** Domaine d'une adresse, en minuscules, sans espaces. */
    public static function domaine(string $adresse): string
    {
        return mb_strtolower(trim(substr($adresse, (int) strrpos($adresse, '@') + 1)));
    }
}
