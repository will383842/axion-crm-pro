<?php

namespace App\Envoi;

use App\Envoi\Exceptions\EnvoiRefuse;

/**
 * Empreinte d'une adresse témoin : HMAC-SHA256 de l'adresse normalisée
 * (minuscules, sans espace, domaine sans point final et en punycode) avec la clé d'application `envoi.cle_empreinte`
 * (ENVOI_CLE_EMPREINTE, jamais dans le dépôt). Sans clé : refus (fermé).
 */
final class EmpreinteTemoin
{
    public static function normaliser(string $adresse): string
    {
        $adresse = (string) preg_replace('/\s+/u', '', mb_strtolower($adresse));
        $arobase = strrpos($adresse, '@');
        if ($arobase === false) {
            return $adresse;
        }
        $domaine = rtrim(substr($adresse, $arobase + 1), '.');
        $ascii = $domaine === '' ? false : idn_to_ascii($domaine, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        // Vecteurs partagés avec le serveur d'envoi : envoi-serveur/contrat/vecteurs-empreintes-temoins.json
        return substr($adresse, 0, $arobase + 1) . ($ascii === false ? $domaine : $ascii);
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
