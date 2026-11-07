<?php

namespace App\Envoi\Chauffe;

use App\Envoi\PasserelleLocale;
use DateTimeImmutable;

/**
 * LA courbe de chauffe — UNE SEULE table de vérité, par boîte et par jour de chauffe.
 *
 * Jour de chauffe 1 = premier jour d'envoi de la boîte (jour civil Europe/Paris).
 *   P1 : jours  1 à 14 — démarrage prudent
 *   P2 : jours 15 à 28 — montée
 *   P3 : jours 29 et suivants — régime de croisière (palier haut plafonné)
 * Les valeurs sont des HYPOTHÈSES à confirmer en banc (spec 26) ; les changer =
 * changer CE tableau, et lui seul. Quota par BOÎTE ; le total d'un espace ne dépasse
 * jamais le plafond dur V0 (`PasserelleLocale::PLAFOND_MAX`, 300 par jour et par
 * espace) : `quotaEspace()` applique cette borne, quoi que dise le tableau.
 */
final class CourbeChauffe
{
    /**
     * Paliers : [dernier jour de chauffe couvert (null = sans fin), nom, quota/jour/boîte].
     * Dans un palier, le quota monte linéairement du `depart` au `quota` indiqué.
     *
     * @var list<array{jusqua: ?int, palier: string, depart: int, quota: int}>
     */
    public const PALIERS = [
        ['jusqua' => 14, 'palier' => 'P1', 'depart' => 5, 'quota' => 20],
        ['jusqua' => 28, 'palier' => 'P2', 'depart' => 25, 'quota' => 50],
        ['jusqua' => null, 'palier' => 'P3', 'depart' => 60, 'quota' => 80],
    ];

    /** Jour de chauffe (1 = premier jour d'envoi), en jours CIVILS de Paris. */
    public static function jourDeChauffe(DateTimeImmutable $premierJour, DateTimeImmutable $jour): int
    {
        $a = \App\Envoi\JourEnvoi::de($premierJour);
        $b = \App\Envoi\JourEnvoi::de($jour);

        return max(1, (int) $a->diff($b)->format('%r%a') + 1);
    }

    public static function palier(int $jourDeChauffe): string
    {
        return self::ligne($jourDeChauffe)['palier'];
    }

    /** Quota d'une boîte pour ce jour de chauffe. Jamais au-dessus du plafond V0. */
    public static function quotaBoite(int $jourDeChauffe): int
    {
        $jourDeChauffe = max(1, $jourDeChauffe);
        $ligne = self::ligne($jourDeChauffe);
        $debut = 1;
        foreach (self::PALIERS as $p) {
            if ($p === $ligne) {
                break;
            }
            $debut = $p['jusqua'] + 1;
        }
        if ($ligne['jusqua'] === null) {
            return min($ligne['quota'], PasserelleLocale::PLAFOND_MAX);
        }
        $longueur = $ligne['jusqua'] - $debut + 1;
        $pas = $longueur > 1 ? ($ligne['quota'] - $ligne['depart']) / ($longueur - 1) : 0;

        return min((int) round($ligne['depart'] + $pas * ($jourDeChauffe - $debut)), PasserelleLocale::PLAFOND_MAX);
    }

    /**
     * Quota d'un espace = somme des quotas de ses boîtes, bornée au plafond dur V0.
     *
     * @param  list<int>  $joursDeChauffeDesBoites
     */
    public static function quotaEspace(array $joursDeChauffeDesBoites): int
    {
        return min(PasserelleLocale::PLAFOND_MAX, array_sum(array_map(self::quotaBoite(...), $joursDeChauffeDesBoites)));
    }

    /** @return array{jusqua: ?int, palier: string, depart: int, quota: int} */
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
