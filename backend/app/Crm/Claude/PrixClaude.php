<?php

namespace App\Crm\Claude;

use InvalidArgumentException;

/**
 * LA TABLE DE PRIX DE CLAUDE HAIKU 5.5, DANS LE CODE.
 *
 * Seul `claude-haiku-5-5` a un prix. Tout autre modèle est REFUSÉ : un modèle
 * absent de la table ne peut pas valoir 0 et rendre le plafond aveugle.
 *
 * Tout est en micro-dollars (entiers). Les tarifs sont en micro-dollars par
 * million de jetons, donc 1 000 000 = 1 micro-dollar par jeton.
 * À revérifier sur la grille officielle avant la mise en service.
 */
final class PrixClaude
{
    public const MODELE = 'claude-haiku-5-5';

    /** Plafond codé en dur du chantier, en micro-dollars. La config ne peut que le baisser. */
    public const PLAFOND_TOTAL_MICRO = 200_000_000;

    /** Au-delà de ce nombre de jetons d'entrée : tarif fort et drapeau d'anomalie. */
    public const SEUIL_ENTREE_FORT = 100_000;

    /** Une recherche web : 10 000 micro-dollars. */
    public const RECHERCHE_MICRO = 10_000;

    private const NORMAL = ['entree' => 1_000_000, 'sortie' => 5_000_000, 'cache_ecriture' => 1_250_000, 'cache_lecture' => 100_000];

    private const FORT = ['entree' => 2_000_000, 'sortie' => 7_500_000, 'cache_ecriture' => 2_500_000, 'cache_lecture' => 200_000];

    public static function verifierModele(string $modele): void
    {
        if ($modele !== self::MODELE) {
            throw new InvalidArgumentException('Modèle refusé : seul claude-haiku-5-5 a un prix dans le code.');
        }
    }

    /**
     * @param  array<string,mixed>  $usage  le bloc `usage` de la réponse
     * @return array{micro:int, anomalie:bool}
     */
    public static function cout(string $modele, array $usage): array
    {
        self::verifierModele($modele);

        $entree = (int) ($usage['input_tokens'] ?? 0);
        $anomalie = $entree > self::SEUIL_ENTREE_FORT;
        $t = $anomalie ? self::FORT : self::NORMAL;
        $recherches = (int) ($usage['server_tool_use']['web_search_requests'] ?? 0);

        $micro = self::jetons($entree, $t['entree'])
            + self::jetons((int) ($usage['output_tokens'] ?? 0), $t['sortie'])
            + self::jetons((int) ($usage['cache_creation_input_tokens'] ?? 0), $t['cache_ecriture'])
            + self::jetons((int) ($usage['cache_read_input_tokens'] ?? 0), $t['cache_lecture'])
            + $recherches * self::RECHERCHE_MICRO;

        return ['micro' => $micro, 'anomalie' => $anomalie];
    }

    /** Coût MAXIMAL à réserver avant l'appel : entrée estimée, sortie au plafond, recherches permises. */
    public static function coutMaximal(int $entreeEstimee, int $maxTokens, int $recherches): int
    {
        return self::cout(self::MODELE, [
            'input_tokens' => $entreeEstimee,
            'output_tokens' => $maxTokens,
            'server_tool_use' => ['web_search_requests' => $recherches],
        ])['micro'];
    }

    /** Le plafond total en micro-dollars : la config (en dollars) ne peut que baisser la constante. */
    public static function plafondTotalMicro(?float $configUsd): int
    {
        if ($configUsd === null || $configUsd <= 0) {
            return self::PLAFOND_TOTAL_MICRO;
        }

        return min(self::PLAFOND_TOTAL_MICRO, (int) round($configUsd * 1_000_000));
    }

    private static function jetons(int $nombre, int $tarif): int
    {
        return intdiv($nombre * $tarif + 999_999, 1_000_000);
    }
}
