<?php

namespace App\Envoi\Serveur;

use App\Support\HmacSignature;

/**
 * Signature des échanges avec le serveur d'envoi (ADR K0 D3).
 * Requête : « MÉTHODE \n chemin \n horodatage \n nonce \n corps » (pour un GET, « corps » = la chaîne de requête).
 * Réponse : « REPONSE \n nonce \n workspace_id \n statut \n corps » — liée au nonce de la requête.
 * En-tête : « k=<identifiant de clé>,sha256=<hex> » ; l'ancienne forme (hex nu ou « sha256=… ») reste lue.
 */
final class SignatureEnvoi
{
    public const CHEMIN_EVENEMENTS = '/v1/evenements';

    public const PREFIXE_COMMANDES = '/v1/commandes/';

    public static function chargeRequete(string $methode, string $chemin, string $horodatage, string $nonce, string $corps): string
    {
        return implode("\n", [strtoupper($methode), $chemin, $horodatage, $nonce, $corps]);
    }

    public static function chargeReponse(string $nonce, string $workspaceId, int $statut, string $corps): string
    {
        return implode("\n", ['REPONSE', $nonce, $workspaceId, (string) $statut, $corps]);
    }

    public static function signer(#[\SensitiveParameter] string $secret, string $charge): string
    {
        return HmacSignature::sign($secret, $charge);
    }

    public static function entete(string $cleId, string $hex): string
    {
        return 'k=' . $cleId . ',sha256=' . $hex;
    }

    /** @return array{0: ?string, 1: string} [identifiant de clé ou null, signature] */
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
}
