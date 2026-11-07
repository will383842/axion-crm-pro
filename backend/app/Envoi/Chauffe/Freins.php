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
    /** Rebonds durs : taux strictement supérieur au seuil, échantillon minimum atteint. */
    public static function rebondsDurs(int $envoyes, int $rebonds, float $tauxMax = Seuils::REBONDS_DURS_TAUX_MAX): ?DecisionFrein
    {
        return $envoyes >= Seuils::REBONDS_DURS_ENVOIS_MIN && $rebonds / $envoyes > $tauxMax
            ? new DecisionFrein(DecisionFrein::REBONDS) : null;
    }

    public static function plaintes(int $envoyes, int $plaintes, float $tauxMax = Seuils::PLAINTES_TAUX_MAX): ?DecisionFrein
    {
        return $envoyes >= Seuils::PLAINTES_ENVOIS_MIN && $plaintes / $envoyes > $tauxMax
            ? new DecisionFrein(DecisionFrein::PLAINTES) : null;
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

        return $courante['reponses'] / $courante['envoyes'] < $moyenne * $ratioMin
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

    /** Homme mort : jours CIVILS de Paris depuis la dernière validation (jamais validé = pause). */
    public static function hommeMort(?DateTimeImmutable $derniereValidation, DateTimeImmutable $maintenant, int $joursMax = Seuils::HOMME_MORT_JOURS): ?DecisionFrein
    {
        if ($derniereValidation === null) {
            return new DecisionFrein(DecisionFrein::HOMME_MORT);
        }
        $jours = (int) JourEnvoi::de($derniereValidation)->diff(JourEnvoi::de($maintenant))->format('%r%a');

        return $jours >= $joursMax ? new DecisionFrein(DecisionFrein::HOMME_MORT) : null;
    }
}
