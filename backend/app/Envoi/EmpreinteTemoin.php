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

    /**
     * Empreinte pour la DÉCISION « est-ce un témoin ? » : null (jamais témoin) pour toute adresse qui n'est pas
     * entièrement ASCII (hors espaces), comme `empreinte_temoin` côté serveur d'envoi. Sans cela, le punycode
     * transitoire ferait de a@faß.de le même témoin que a@fass.de. Le contrat partagé (`calculer`) est inchangé.
     */
    public static function pourDecision(string $adresse, ?string $cle = null): ?string
    {
        $sansEspaces = preg_replace('/\s+/u', '', $adresse);
        // UTF-8 invalide (preg_replace = null) : non ASCII par prudence.
        if ($sansEspaces === null || preg_match('/[^\x00-\x7F]/', $sansEspaces) === 1) {
            return null;
        }

        return self::calculer($adresse, $cle);
    }
}
