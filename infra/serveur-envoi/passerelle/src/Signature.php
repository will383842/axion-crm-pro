<?php

declare(strict_types=1);

namespace Passerelle;

/**
 * HMAC-SHA256 du canal CRM -> passerelle. La charge signée contient le workspace_id,
 * l'horodatage, le nonce, la méthode, le chemin AVEC requête et l'empreinte du corps.
 */
final class Signature
{
    public static function charge(string $ws, string $ts, string $nonce, string $methode, string $chemin, string $corps): string
    {
        return implode("\n", ['v1', $ws, $ts, $nonce, strtoupper($methode), $chemin, hash('sha256', $corps)]);
    }

    public static function signer(string $cle, string $ws, string $ts, string $nonce, string $methode, string $chemin, string $corps): string
    {
        return hash_hmac('sha256', self::charge($ws, $ts, $nonce, $methode, $chemin, $corps), $cle);
    }

    /** Clé vide = refus (jamais de porte ouverte). Comparaison en temps constant. */
    public static function verifier(string $cle, string $recue, string $ws, string $ts, string $nonce, string $methode, string $chemin, string $corps): bool
    {
        if ($cle === '' || $recue === '' || preg_match('/^[0-9a-f]{64}$/', $recue) !== 1) {
            return false;
        }

        return hash_equals(self::signer($cle, $ws, $ts, $nonce, $methode, $chemin, $corps), $recue);
    }

    /** La signature de la réponse reprend le nonce de la requête. */
    public static function signerReponse(string $cle, string $ws, string $nonce, int $statut, string $corps): string
    {
        return hash_hmac('sha256', implode("\n", ['v1-reponse', $ws, $nonce, (string) $statut, hash('sha256', $corps)]), $cle);
    }
}
