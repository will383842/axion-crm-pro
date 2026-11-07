<?php

namespace App\Envoi\Chauffe;

/**
 * Famille de fournisseur du DESTINATAIRE, déduite du domaine de l'adresse.
 * LIMITE ASSUMÉE : un domaine d'entreprise hébergé chez Google ou Microsoft
 * (MX) est vu « autre » ; seule une résolution MX saurait le distinguer.
 */
final class Fournisseur
{
    public const GMAIL = 'gmail';

    public const MICROSOFT = 'microsoft';

    public const AUTRE = 'autre';

    public const TOUS = [self::GMAIL, self::MICROSOFT, self::AUTRE];

    private const MICROSOFT_DOMAINES = ['outlook.com', 'outlook.fr', 'hotmail.com', 'hotmail.fr', 'live.com', 'live.fr', 'msn.com'];

    public static function de(string $adresse): string
    {
        $domaine = mb_strtolower(trim(substr($adresse, (int) strrpos($adresse, '@') + 1)));
        if (in_array($domaine, ['gmail.com', 'googlemail.com'], true)) {
            return self::GMAIL;
        }
        if (in_array($domaine, self::MICROSOFT_DOMAINES, true)) {
            return self::MICROSOFT;
        }

        return self::AUTRE;
    }
}
