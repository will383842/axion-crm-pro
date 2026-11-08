<?php

namespace App\Envoi;

use App\Envoi\Exceptions\EnvoiRefuse;

/**
 * Empreinte d'une adresse témoin : HMAC-SHA256 de l'adresse normalisée
 * (minuscules, sans espace) avec la clé d'application `envoi.cle_empreinte`
 * (ENVOI_CLE_EMPREINTE, jamais dans le dépôt). Sans clé : refus (fermé).
 */
final class EmpreinteTemoin
{
    public static function normaliser(string $adresse): string
    {
        return (string) preg_replace('/\s+/u', '', mb_strtolower($adresse));
    }

    public static function calculer(string $adresse, ?string $cle = null): string
    {
        $cle ??= (string) config('envoi.cle_empreinte');
        if ($cle === '') {
            throw EnvoiRefuse::cleEmpreinteAbsente();
        }

        return hash_hmac('sha256', self::normaliser($adresse), $cle);
    }
}
