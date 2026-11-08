<?php

declare(strict_types=1);

namespace Passerelle;

/**
 * HMAC-SHA256 du canal CRM <-> passerelle, IDENTIQUE à celui du client K1 (ADR K0 D3, contrat v2 ; source de vérité :
 * backend/app/Envoi/Serveur/SignatureEnvoi.php de K1 et envoi-serveur/contrat/vecteurs-hmac.json).
 * Requête : « METHODE \n chemin \n horodatage \n nonce \n workspace_id \n corps » (chemin SANS requête ; pour un GET,
 * « corps » = la chaîne de requête exacte). Réponse : « REPONSE \n nonce \n workspace_id \n statut \n corps ».
 * En-tête : « k=<id de clé>,sha256=<hex> ».
 */
final class Signature
{
    public static function chargeRequete(string $methode, string $chemin, string $horodatage, string $nonce, string $workspaceId, string $corps): string
    {
        return implode("\n", [strtoupper($methode), $chemin, $horodatage, $nonce, $workspaceId, $corps]);
    }

    public static function chargeReponse(string $nonce, string $workspaceId, int $statut, string $corps): string
    {
        return implode("\n", ['REPONSE', $nonce, $workspaceId, (string) $statut, $corps]);
    }

    public static function signer(#[\SensitiveParameter] string $cle, string $charge): string
    {
        return hash_hmac('sha256', $charge, $cle);
    }

    public static function entete(string $cleId, string $hex): string
    {
        return 'k='.$cleId.',sha256='.$hex;
    }

    /** @return array{0: ?string, 1: string} [identifiant de clé ou null, signature] — mêmes formes lues que K1 */
    public static function analyser(?string $entete): array
    {
        $entete = (string) $entete;
        if (preg_match('/^k=([A-Za-z0-9_-]{1,32}),(.*)$/s', $entete, $m) === 1) {
            return [$m[1], $m[2]];
        }

        return [null, $entete];
    }

    public static function estUuid(string $valeur): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $valeur) === 1;
    }

    /** Clé vide ou signature mal formée = refus (jamais de porte ouverte). Comparaison en temps constant. */
    public static function verifier(#[\SensitiveParameter] string $cle, ?string $entete, string $charge): bool
    {
        [, $recue] = self::analyser($entete);
        $recue = str_starts_with($recue, 'sha256=') ? substr($recue, 7) : $recue;
        if ($cle === '' || preg_match('/^[0-9a-f]{64}$/', $recue) !== 1) {
            return false;
        }

        return hash_equals(self::signer($cle, $charge), $recue);
    }
}
