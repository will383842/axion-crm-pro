<?php

namespace App\Envoi\Chauffe;

use App\Envoi\JourEnvoi;
use App\Envoi\PasserelleLocale;
use DateTimeImmutable;

/**
 * LA courbe de chauffe — UNE SEULE table de vérité, par boîte.
 *
 * Le « jour de chauffe » compte les jours EFFECTIFS d'envoi de la boîte : un jour civil
 * (Europe/Paris) sans aucun envoi ne compte pas. Le jour de chauffe d'aujourd'hui est
 * donc « nombre de jours d'envoi passés + 1 », après le recul éventuel ci-dessous.
 *
 *   P1 : jours  1 à  5 — 10 par boîte (20 au total à deux boîtes)
 *   P2 : jours  6 à 10 — 18 par boîte (35 au total)
 *   P3 : jours 11 à 15 — 25 par boîte (50 au total) ; au-delà, on reste en P3
 *
 * Recul (26 § 4.3) : après {@see self::INACTIVITE_JOURS} jours civils sans envoi, la boîte
 * recule d'UN palier (elle repart du premier jour du palier précédent ; en P1, elle reste en P1).
 * Les valeurs sont des HYPOTHÈSES à confirmer en banc ; les changer = changer CE tableau.
 *
 * Quota par BOÎTE. Le plafond dur de 300 par jour et par espace n'est PAS dans la courbe :
 * `PasserelleLocale` l'applique avant tout (porte 6), et la courbe ne peut pas le dépasser.
 */
final class CourbeChauffe
{
    /** Jours civils sans envoi à partir desquels la boîte recule d'un palier. */
    public const INACTIVITE_JOURS = 7;

    /**
     * Paliers : [dernier jour de chauffe couvert (null = sans fin), nom, quota/jour/boîte].
     *
     * @var list<array{jusqua: ?int, palier: string, quota: int}>
     */
    public const PALIERS = [
        ['jusqua' => 5, 'palier' => 'P1', 'quota' => 10],
        ['jusqua' => 10, 'palier' => 'P2', 'quota' => 18],
        ['jusqua' => null, 'palier' => 'P3', 'quota' => 25],
    ];

    /**
     * Jour de chauffe EFFECTIF de $jour, d'après les jours où la boîte a réellement envoyé.
     * Seuls comptent les jours STRICTEMENT avant $jour (l'envoi du jour même n'avance pas
     * le quota du jour).
     *
     * @param  list<DateTimeImmutable>  $joursAvecEnvoi
     */
    public static function jourDeChauffe(array $joursAvecEnvoi, DateTimeImmutable $jour): int
    {
        $jour = JourEnvoi::de($jour);
        $civils = [];
        foreach ($joursAvecEnvoi as $j) {
            $civil = JourEnvoi::de($j);
            if ($civil < $jour) {
                $civils[$civil->format('Y-m-d')] = $civil;
            }
        }
        ksort($civils);
        $effectifs = 0;
        $precedent = null;
        foreach ($civils as $civil) {
            if ($precedent !== null) {
                $effectifs = self::reculSiInactif($effectifs, self::ecart($precedent, $civil));
            }
            $effectifs++;
            $precedent = $civil;
        }
        if ($precedent !== null) {
            $effectifs = self::reculSiInactif($effectifs, self::ecart($precedent, $jour));
        }

        return $effectifs + 1;
    }

    /**
     * Jours EFFECTIFS à partir des volumes réellement envoyés : un jour ne compte que si le volume atteint
     * `$part` × le quota du palier où la boîte se trouvait CE jour-là (arrondi au supérieur, au moins 1).
     *
     * @param  array<string, int>  $volumes  'Y-m-d' (Paris) => messages envoyés ce jour-là
     * @return list<DateTimeImmutable>
     */
    public static function joursEffectifs(array $volumes, float $part = Seuils::JOUR_EFFECTIF_PART): array
    {
        ksort($volumes);
        $effectifs = [];
        foreach ($volumes as $j => $n) {
            $jour = new DateTimeImmutable($j, new \DateTimeZone(JourEnvoi::FUSEAU));
            $minimum = max(1, (int) ceil(round($part * self::quotaBoite(self::jourDeChauffe($effectifs, $jour)), 9)));
            if ($n >= $minimum) {
                $effectifs[] = $jour;
            }
        }

        return $effectifs;
    }

    public static function palier(int $jourDeChauffe): string
    {
        return self::ligne($jourDeChauffe)['palier'];
    }

    /** Dernier jour de chauffe du palier qui couvre ce jour (null = palier sans fin). */
    public static function finDuPalier(int $jourDeChauffe): ?int
    {
        return self::ligne($jourDeChauffe)['jusqua'];
    }

    /** Quota d'une boîte pour ce jour de chauffe. */
    public static function quotaBoite(int $jourDeChauffe): int
    {
        return min(self::ligne(max(1, $jourDeChauffe))['quota'], PasserelleLocale::PLAFOND_MAX);
    }

    /** Jours civils entre deux jours : 1 = jours consécutifs. */
    private static function ecart(DateTimeImmutable $de, DateTimeImmutable $a): int
    {
        return (int) $de->diff($a)->format('%r%a');
    }

    /** Écart de $ecart jours civils depuis le dernier envoi : à 7 ou plus, on recule d'un palier. */
    private static function reculSiInactif(int $effectifs, int $ecart): int
    {
        if ($ecart < self::INACTIVITE_JOURS) {
            return $effectifs;
        }
        // Prochain jour = $effectifs + 1 ; on le ramène au premier jour du palier d'avant (P1 : inchangé).
        $prochain = $effectifs + 1;
        $debutPrecedent = null;
        $debut = 1;
        foreach (self::PALIERS as $p) {
            if ($p['jusqua'] === null || $prochain <= $p['jusqua']) {
                return $debutPrecedent === null ? $effectifs : $debutPrecedent - 1;
            }
            $debutPrecedent = $debut;
            $debut = $p['jusqua'] + 1;
        }

        return $effectifs;
    }

    /** @return array{jusqua: ?int, palier: string, quota: int} */
    private static function ligne(int $jourDeChauffe): array
    {
        foreach (self::PALIERS as $p) {
            if ($p['jusqua'] === null || $jourDeChauffe <= $p['jusqua']) {
                return $p;
            }
        }

        return self::PALIERS[array_key_last(self::PALIERS)];
    }
}
