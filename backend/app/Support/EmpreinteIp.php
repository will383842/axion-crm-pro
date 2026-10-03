<?php

namespace App\Support;

/**
 * Empreinte d'une adresse IP pour les JOURNAUX applicatifs : jamais l'IP en
 * clair, jamais un sha256 nu (l'espace IPv4 se parcourt en quelques minutes).
 *
 *     empreinte = 32 premiers hex de HMAC-SHA256( clé, ip )
 *
 * La même IP donne la même empreinte tant que la clé ne change pas : on peut
 * corréler les refus d'un même appelant sans pouvoir retrouver l'adresse.
 *
 * Clé : `crm.journaux.ip_cle` (`CRM_JOURNAUX_IP_CLE`). Vide → clé DÉRIVÉE de
 * `APP_KEY` (HMAC étiqueté, `APP_KEY` n'est jamais utilisée telle quelle) :
 * l'empreinte reste à clé même sans configuration dédiée. Sans aucune clé,
 * `null` — jamais l'IP.
 */
final class EmpreinteIp
{
    public const LONGUEUR = 32;

    public static function de(?string $ip): ?string
    {
        $cle = self::cle();
        if ($ip === null || $ip === '' || $cle === null) {
            return null;
        }

        return substr(hash_hmac('sha256', $ip, $cle), 0, self::LONGUEUR);
    }

    private static function cle(): ?string
    {
        $dediee = trim((string) config('crm.journaux.ip_cle', ''));
        if ($dediee !== '') {
            return $dediee;
        }

        $appKey = (string) config('app.key', '');

        return $appKey === '' ? null : hash_hmac('sha256', 'crm-journaux-ip-v1', $appKey);
    }
}
