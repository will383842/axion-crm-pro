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

    /**
     * Un « jour effectif » de chauffe n'est compté que si la boîte a RÉELLEMENT envoyé au moins cette part du quota
     * de son palier ce jour-là (un seul message par jour ne fait plus avancer la montée de chauffe).
     */
    public const JOUR_EFFECTIF_PART = 0.5;

    /** Reports (4xx différés) : au-delà de 20 % des envois vers un fournisseur sur 1 h => pause de ce fournisseur (26 § 4.3). */
    public const REPORTS_TAUX_MAX = 0.20;

    /** Échantillon minimum sur l'heure (HYPOTHÈSE à confirmer en banc) : sous ce volume, un taux n'a pas de sens. */
    public const REPORTS_ENVOIS_MIN = 10;

    /** Quota du DOMAINE d'envoi par jour = ce facteur × le quota d'une boîte (créer des boîtes ne contourne pas la montée). */
    public const DOMAINE_FACTEUR = 3;

    public const NOMS = [
        'rebonds_durs_taux_max', 'rebonds_durs_jour_max', 'homme_mort_jours',
        'humain_pause_a', 'reponse_ratio_min', 'jour_effectif_part',
    ];

    /** Valeur effective : celle de l'espace si elle est PLUS STRICTE, sinon la constante. */
    public static function resserre(string $nom, float $constante, ?float $reglage): float
    {
        if ($reglage === null || ! is_finite($reglage)) {   // NaN / infini : réglage ignoré (jamais plus lâche)
            return $constante;
        }

        // « plus petit = plus strict » sauf le ratio de réponse minimal et la part de jour effectif (plus grand = plus strict).
        return in_array($nom, ['reponse_ratio_min', 'jour_effectif_part'], true) ? max($constante, $reglage) : min($constante, $reglage);
    }
}
