<?php

namespace App\Envoi\Sequence;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Jours ouvrés FRANÇAIS : lundi–vendredi hors jours fériés légaux
 * (1er janvier, lundi de Pâques, 1er mai, 8 mai, Ascension, lundi de Pentecôte,
 * 14 juillet, 15 août, Toussaint, 11 novembre, Noël). Calcul sur le jour civil
 * d'Europe/Paris. Aucune lecture en base, aucun effet de bord.
 */
final class JoursOuvres
{
    public const FUSEAU = 'Europe/Paris';

    /**
     * Dimanche de Pâques (calendrier grégorien), algorithme de Meeus/Butcher.
     * Calcul entier, SANS l'extension PHP `calendar` (absente de l'image : `easter_days()` n'y existe pas).
     */
    public static function paques(int $annee): DateTimeImmutable
    {
        $a = $annee % 19;
        $b = intdiv($annee, 100);
        $c = $annee % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mois = intdiv($h + $l - 7 * $m + 114, 31);
        $jour = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $annee, $mois, $jour), new DateTimeZone('UTC'));
    }

    /** @return list<string> dates Y-m-d des jours fériés de l'année */
    public static function feries(int $annee): array
    {
        $paques = self::paques($annee);
        $liste = [
            sprintf('%04d-01-01', $annee),
            $paques->modify('+1 day')->format('Y-m-d'),
            sprintf('%04d-05-01', $annee),
            sprintf('%04d-05-08', $annee),
            $paques->modify('+39 days')->format('Y-m-d'),
            $paques->modify('+50 days')->format('Y-m-d'),
            sprintf('%04d-07-14', $annee),
            sprintf('%04d-08-15', $annee),
            sprintf('%04d-11-01', $annee),
            sprintf('%04d-11-11', $annee),
            sprintf('%04d-12-25', $annee),
        ];
        sort($liste);

        return $liste;
    }

    public static function estOuvre(DateTimeImmutable $instant): bool
    {
        $jour = $instant->setTimezone(new DateTimeZone(self::FUSEAU));
        if ((int) $jour->format('N') >= 6) {
            return false;
        }

        return ! in_array($jour->format('Y-m-d'), self::feries((int) $jour->format('Y')), true);
    }

    /** Ajoute $n jours ouvrés (n ≥ 0) au jour civil de Paris de $depuis ; l'heure est conservée (heure locale de Paris). */
    public static function ajouter(DateTimeImmutable $depuis, int $n): DateTimeImmutable
    {
        $cur = $depuis->setTimezone(new DateTimeZone(self::FUSEAU));
        while ($n > 0) {
            $cur = $cur->modify('+1 day');
            if (self::estOuvre($cur)) {
                $n--;
            }
        }

        return $cur;
    }

    /** Premier jour ouvré >= $jour (le jour lui-même s'il est ouvré). */
    public static function auPlusTot(DateTimeImmutable $jour): DateTimeImmutable
    {
        $cur = $jour->setTimezone(new DateTimeZone(self::FUSEAU));
        while (! self::estOuvre($cur)) {
            $cur = $cur->modify('+1 day');
        }

        return $cur;
    }
}
