<?php

namespace App\Envoi\Chauffe;

/**
 * SEUILS DES FREINS — constantes nommées, TOUTES à confirmer en banc.
 * Un réglage par espace (`envoi_seuils`) ne peut que RESSERRER un seuil, jamais
 * le relâcher : `Seuils::resserre()` ignore toute valeur plus lâche.
 */
final class Seuils
{
    /** Rebonds durs : pause GLOBALE si taux > 2 % sur 7 jours glissants, à partir de 100 envois sur ces 7 jours. */
    public const REBONDS_DURS_TAUX_MAX = 0.02;

    public const REBONDS_DURS_ENVOIS_MIN = 100;

    public const FENETRE_JOURS = 7;

    /** Règle ABSOLUE : 3 rebonds durs (ou plus) dans la journée = pause, quel que soit l'échantillon. */
    public const REBONDS_DURS_JOUR_MAX = 3;

    /** Plaintes : 1 plainte (7 jours glissants) = palier tenu + alerte de relecture. */
    public const PLAINTES_RELECTURE = 1;

    /** 2 plaintes (ou plus) sur 7 jours glissants = arrêt de 48 h. */
    public const PLAINTES_ARRET = 2;

    public const PLAINTES_ARRET_HEURES = 48;

    /** Homme mort (INF-025) : sans appel à sante() pendant 15 minutes en fenêtre d'envoi, toutes les files passent en pause. */
    public const SANTE_SILENCE_MINUTES = 15;

    /** Fenêtre d'envoi (heure de Paris) : [début, fin[. */
    public const FENETRE_ENVOI_DEBUT = 8;

    public const FENETRE_ENVOI_FIN = 19;

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

    /** Tableau de bord non validé depuis N jours civils (Paris) : ALERTE seulement (plus une pause). */
    public const HOMME_MORT_JOURS = 7;

    public const NOMS = [
        'rebonds_durs_taux_max', 'rebonds_durs_jour_max', 'homme_mort_jours',
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
