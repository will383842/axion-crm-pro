<?php

namespace App\Support;

use RuntimeException;

/**
 * Validation de la fenêtre de tolérance de l'horodatage signé des canaux
 * internes (`crm.ingest.max_clock_skew_seconds`, variable
 * `CRM_INGEST_MAX_CLOCK_SKEW`).
 *
 * Règle :
 *   - variable ABSENTE de l'environnement → défaut de `config/crm.php` (300) ;
 *   - variable POSÉE → entier strictement positif, au plus 3600 secondes.
 *     Toute autre valeur (vide, non numérique, nulle, négative, décimale,
 *     déraisonnable) fait REFUSER LE DÉMARRAGE de l'application : une erreur de
 *     configuration sur un canal signé se corrige avant de servir, elle ne se
 *     découvre pas en production.
 */
final class FenetreHorodatage
{
    public const DEFAUT_SECONDES = 300;

    public const MAX_SECONDES = 3600;

    /**
     * @throws RuntimeException si la valeur n'est pas une fenêtre acceptable
     */
    public static function valider(mixed $brut): int
    {
        if (is_int($brut)) {
            $valeur = $brut;
        } elseif (is_string($brut) && preg_match('/^\s*\d{1,6}\s*$/', $brut) === 1) {
            $valeur = (int) trim($brut);
        } else {
            throw self::refus($brut);
        }

        if ($valeur <= 0 || $valeur > self::MAX_SECONDES) {
            throw self::refus($brut);
        }

        return $valeur;
    }

    private static function refus(mixed $brut): RuntimeException
    {
        $vu = is_scalar($brut) ? var_export($brut, true) : get_debug_type($brut);

        return new RuntimeException(sprintf(
            'Configuration invalide : CRM_INGEST_MAX_CLOCK_SKEW (crm.ingest.max_clock_skew_seconds) vaut %s. '
            . 'Attendu : un nombre entier de secondes entre 1 et %d (défaut %d si la variable est absente). '
            . "L'application refuse de démarrer tant que la valeur n'est pas corrigée.",
            $vu,
            self::MAX_SECONDES,
            self::DEFAUT_SECONDES,
        ));
    }
}
