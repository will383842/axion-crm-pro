<?php

namespace App\Crm\Usine;

use InvalidArgumentException;

/**
 * Signature HMAC-SHA256 des échanges CRM → Usine (L7).
 *
 * Chaîne canonique, cinq lignes séparées par "\n" :
 *   METHODE \n CHEMIN (sans query) \n HORODATAGE \n NONCE \n SHA256(corps)
 */
final class SignatureAgregats
{
    public const SECRET_MIN = 32;

    public static function chaineCanonique(string $methode, string $chemin, string $horodatage, string $nonce, string $corps): string
    {
        $chemin = explode('?', $chemin, 2)[0];

        return strtoupper($methode) . "\n" . $chemin . "\n" . $horodatage . "\n" . $nonce . "\n" . hash('sha256', $corps);
    }

    public static function signer(string $secret, string $methode, string $chemin, string $horodatage, string $nonce, string $corps): string
    {
        if (strlen($secret) < self::SECRET_MIN) {
            throw new InvalidArgumentException('Secret de signature absent ou trop court.');
        }

        return hash_hmac('sha256', self::chaineCanonique($methode, $chemin, $horodatage, $nonce, $corps), $secret);
    }

    public static function nonce(): string
    {
        return bin2hex(random_bytes(16));
    }
}
