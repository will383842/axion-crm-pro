<?php

namespace App\Envoi\Chauffe;

use App\Envoi\JourEnvoi;
use DateTimeImmutable;

/**
 * Freins automatiques : fonctions PURES (aucune base, aucune horloge cachée), donc
 * testables aux bornes. Aucune mesure d'ouverture, nulle part.
 */
final class Freins
{
    /**
     * Rebonds durs — deux règles, la plus sévère gagne :
     *  - taux > seuil (2 %) sur 7 jours glissants, échantillon minimum de 100 envois sur ces 7 jours ;
     *  - règle ABSOLUE : 3 rebonds durs ou plus dans la journée, quel que soit l'échantillon.
     */
    public static function rebondsDurs(int $envoyes7j, int $rebonds7j, int $rebondsDuJour, float $tauxMax = Seuils::REBONDS_DURS_TAUX_MAX, int $jourMax = Seuils::REBONDS_DURS_JOUR_MAX): ?DecisionFrein
    {
        if ($rebondsDuJour >= $jourMax) {
            return new DecisionFrein(DecisionFrein::REBONDS);
        }

        return $envoyes7j >= Seuils::REBONDS_DURS_ENVOIS_MIN && $rebonds7j / $envoyes7j > $tauxMax
            ? new DecisionFrein(DecisionFrein::REBONDS) : null;
    }

    /**
     * Plaintes sur 7 jours glissants : 1 = palier tenu + alerte de relecture (aucune pause) ;
     * 2 ou plus = arrêt de 48 h.
     */
    public static function plaintes(int $plaintes7j): ?DecisionFrein
    {
        if ($plaintes7j >= Seuils::PLAINTES_ARRET) {
            return new DecisionFrein(DecisionFrein::PLAINTES_ARRET, null, Seuils::PLAINTES_ARRET_HEURES);
        }

        return $plaintes7j >= Seuils::PLAINTES_RELECTURE ? new DecisionFrein(DecisionFrein::PLAINTE_RELECTURE) : null;
    }

    /** Frein de remplacement Gmail/Microsoft : un message en indésirables chez un témoin → ce fournisseur seulement. */
    public static function indesirableTemoin(string $fournisseur): DecisionFrein
    {
        return new DecisionFrein(DecisionFrein::INDESIRABLE_TEMOIN, $fournisseur);
    }

    /**
     * Taux de réponse sous la moitié de la moyenne des 3 fournées précédentes.
     * Minimum 100 envois comparés : fournée courante ET total des 3 précédentes.
     * Sans exactement 3 fournées précédentes, on ne compare pas.
     *
     * @param  array{envoyes: int, reponses: int}  $courante
     * @param  list<array{envoyes: int, reponses: int}>  $precedentes  les plus récentes d'abord
     */
    public static function reponsesEnBaisse(string $fournisseur, array $courante, array $precedentes, float $ratioMin = Seuils::REPONSE_RATIO_MIN): ?DecisionFrein
    {
        $precedentes = array_slice($precedentes, 0, Seuils::REPONSE_FOURNEES_COMPAREES);
        if (count($precedentes) < Seuils::REPONSE_FOURNEES_COMPAREES || $courante['envoyes'] < 1) {
            return null;
        }
        $envoisPrec = array_sum(array_column($precedentes, 'envoyes'));
        if ($courante['envoyes'] < Seuils::REPONSE_ENVOIS_MIN || $envoisPrec < Seuils::REPONSE_ENVOIS_MIN) {
            return null;
        }
        $taux = array_map(fn (array $f): float => $f['envoyes'] > 0 ? $f['reponses'] / $f['envoyes'] : 0.0, $precedentes);
        $moyenne = array_sum($taux) / count($taux);

        // arrondi : 5 % pile n'est PAS sous la moitié de 10 % (les flottants ne doivent pas en décider)
        return round($courante['reponses'] / $courante['envoyes'], 9) < round($moyenne * $ratioMin, 9)
            ? new DecisionFrein(DecisionFrein::REPONSES_EN_BAISSE, $fournisseur) : null;
    }

    /** Frein humain : 0 = normal ; 1 = ralenti (au-delà de 10) ; 2 = pause (à partir de 15). */
    public const NORMAL = 0;

    public const RALENTI = 1;

    public const PAUSE = 2;

    public static function freinHumain(int $reponsesEnAttente, int $pauseA = Seuils::HUMAIN_PAUSE_A): int
    {
        if ($reponsesEnAttente >= $pauseA) {
            return self::PAUSE;
        }

        return $reponsesEnAttente > Seuils::HUMAIN_RALENTIR_AU_DELA ? self::RALENTI : self::NORMAL;
    }

    /** Tableau de bord non validé : jours CIVILS de Paris depuis la dernière validation (jamais validé = oui). ALERTE seulement. */
    public static function hommeMort(?DateTimeImmutable $derniereValidation, DateTimeImmutable $maintenant, int $joursMax = Seuils::HOMME_MORT_JOURS): ?DecisionFrein
    {
        if ($derniereValidation === null) {
            return new DecisionFrein(DecisionFrein::HOMME_MORT);
        }
        $jours = (int) JourEnvoi::de($derniereValidation)->diff(JourEnvoi::de($maintenant))->format('%r%a');

        return $jours >= $joursMax ? new DecisionFrein(DecisionFrein::HOMME_MORT) : null;
    }

    /** Homme mort INF-025 : silence de sante() depuis 15 minutes ou plus (jamais appelé = silence). */
    public static function santeSilencieuse(?DateTimeImmutable $dernierBattement, DateTimeImmutable $maintenant): bool
    {
        return $dernierBattement === null
            || $maintenant->getTimestamp() - $dernierBattement->getTimestamp() >= Seuils::SANTE_SILENCE_MINUTES * 60;
    }

    /** Fenêtre d'envoi : [08:00, 19:00[ heure de Paris. */
    public static function dansFenetreEnvoi(DateTimeImmutable $maintenant): bool
    {
        $h = (int) $maintenant->setTimezone(new \DateTimeZone(JourEnvoi::FUSEAU))->format('G');

        return $h >= Seuils::FENETRE_ENVOI_DEBUT && $h < Seuils::FENETRE_ENVOI_FIN;
    }
}
