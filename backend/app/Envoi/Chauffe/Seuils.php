<?php

namespace App\Envoi\Chauffe;

/**
 * SEUILS DES FREINS — constantes nommées, TOUTES à confirmer en banc.
 * Un réglage par espace (`envoi_seuils`) ne peut que RESSERRER un seuil, jamais
 * le relâcher : `Seuils::resserre()` ignore toute valeur plus lâche.
 */
final class Seuils
{
    /** Rebonds durs : pause GLOBALE si taux > 3 % sur la fournée, à partir de 50 envois. */
    public const REBONDS_DURS_TAUX_MAX = 0.03;

    public const REBONDS_DURS_ENVOIS_MIN = 50;

    /** Plaintes : pause GLOBALE si taux > 0,1 % sur la fournée, à partir de 100 envois. */
    public const PLAINTES_TAUX_MAX = 0.001;

    public const PLAINTES_ENVOIS_MIN = 100;

    /** Taux de réponse d'un fournisseur sous la moitié de la moyenne des 3 fournées précédentes. */
    public const REPONSE_RATIO_MIN = 0.5;

    public const REPONSE_FOURNEES_COMPAREES = 3;

    /** Minimum d'envois comparés (fournée courante ET total des précédentes). */
    public const REPONSE_ENVOIS_MIN = 100;

    /** Frein humain : au-delà de 10 réponses non traitées on ralentit (11 et plus) ; à 15, pause. */
    public const HUMAIN_RALENTIR_AU_DELA = 10;

    public const HUMAIN_PAUSE_A = 15;

    /** Quota multiplié par ce facteur quand le frein humain ralentit. */
    public const HUMAIN_FACTEUR_RALENTI = 0.5;

    /** Homme mort : sans validation du tableau de bord depuis N jours civils (Paris), pause. */
    public const HOMME_MORT_JOURS = 7;

    public const NOMS = [
        'rebonds_durs_taux_max', 'plaintes_taux_max', 'homme_mort_jours',
        'humain_pause_a', 'reponse_ratio_min',
    ];

    /** Valeur effective : celle de l'espace si elle est PLUS STRICTE, sinon la constante. */
    public static function resserre(string $nom, float $constante, ?float $reglage): float
    {
        if ($reglage === null) {
            return $constante;
        }
        // « plus petit = plus strict » sauf le ratio de réponse minimal (plus grand = plus strict).
        return $nom === 'reponse_ratio_min' ? max($constante, $reglage) : min($constante, $reglage);
    }
}
