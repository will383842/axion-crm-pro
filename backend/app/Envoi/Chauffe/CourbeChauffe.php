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
 * Table étendue P1-P8 : le saut P3 → P4 (50 → 100 par ressource, +100 %) est une HYPOTHÈSE à confirmer en banc
 * (aucune source). « Vert » (jours de probation après un recul) signifie « jour EFFECTIF » (volume au moins égal
 * au seuil), PAS « sans incident » : un jour vert peut avoir eu des retours.
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
     * `quota` = par boîte ; `total` = budget TOTAL de la ressource (IP ou domaine) qui porte les boîtes
     * (20 / 35 / 50 par jour : 26 § 4.2, deux boîtes), utilisé par la chauffe SÉPARÉE de l'IP (K8).
     *
     * @var list<array{jusqua: ?int, palier: string, quota: int, total: int}>
     */
    public const PALIERS = [
        ['jusqua' => 5, 'palier' => 'P1', 'quota' => 10, 'total' => 20],
        ['jusqua' => 10, 'palier' => 'P2', 'quota' => 18, 'total' => 35],
        ['jusqua' => null, 'palier' => 'P3', 'quota' => 25, 'total' => 50],
    ];

    /** Plafond par boîte et par jour (ADR E2) : atteint au dernier palier, P8. */
    public const PLAFOND_BOITE_JOUR = 150;

    /** Jours effectifs « verts » à passer, après un recul, avant que la montée reprenne. */
    public const JOURS_VERTS_APRES_RECUL = 3;

    /**
     * Table COMPLÈTE P1 à P8 (L8-F07a). La spécification 26 § 4.1 n'est pas dans le dépôt : P1-P3 reprennent
     * EXACTEMENT {@see self::PALIERS} ; P4-P8 sont une progression LINÉAIRE documentée, sans autre valeur inventée :
     * le budget de la ressource gagne 50 par palier à partir de P3 (50), pour finir sur le plafond du serveur,
     * 300 en P8 : P4 = 100, P5 = 150, P6 = 200, P7 = 250, P8 = 300. Le quota d'une boîte est la moitié (deux boîtes
     * par ressource, comme P1-P3 : 10/18/25), arrondie au supérieur : 50 / 75 / 100 / 125 / 150, soit
     * {@see self::PLAFOND_BOITE_JOUR} en P8. Chaque palier P4-P7 dure 5 jours effectifs, comme P1-P3.
     * HYPOTHÈSES à confirmer en banc. La table vivante PALIERS (P1-P3, P3 sans fin) n'est PAS modifiée : le
     * branchement de la table étendue sur l'envoi est la fiche F07b.
     *
     * @var list<array{jusqua: ?int, palier: string, quota: int, total: int}>
     */
    public const PALIERS_ETENDUS = [
        ['jusqua' => 5, 'palier' => 'P1', 'quota' => 10, 'total' => 20],
        ['jusqua' => 10, 'palier' => 'P2', 'quota' => 18, 'total' => 35],
        ['jusqua' => 15, 'palier' => 'P3', 'quota' => 25, 'total' => 50],
        ['jusqua' => 20, 'palier' => 'P4', 'quota' => 50, 'total' => 100],
        ['jusqua' => 25, 'palier' => 'P5', 'quota' => 75, 'total' => 150],
        ['jusqua' => 30, 'palier' => 'P6', 'quota' => 100, 'total' => 200],
        ['jusqua' => 35, 'palier' => 'P7', 'quota' => 125, 'total' => 250],
        ['jusqua' => null, 'palier' => 'P8', 'quota' => 150, 'total' => 300],
    ];

    /**
     * Jour de chauffe de $jour avec les RECULS (table P1-P8) : un recul ramène d'UN palier vers le bas (au premier
     * jour du palier précédent ; en P1, il est sans effet), puis les {@see self::JOURS_VERTS_APRES_RECUL} jours
     * effectifs suivants ne font pas avancer le compteur. Un recul daté de $jour s'applique ce jour-là, avant
     * l'éventuel envoi du jour ; un recul postérieur est ignoré. Aucune opération « avancer » : seuls les jours
     * effectifs font monter. Même rejeu que {@see self::jourDeChauffe()} pour l'inactivité (7 jours).
     *
     * @param  list<DateTimeImmutable>  $joursEffectifs
     * @param  list<DateTimeImmutable>  $reculs  dates des reculs de CETTE ressource uniquement
     */
    public static function jourDeChauffeAvecReculs(array $joursEffectifs, array $reculs, DateTimeImmutable $jour): int
    {
        return self::rejouer(self::PALIERS_ETENDUS, $joursEffectifs, $reculs, $jour);
    }

    public static function palierEtendu(int $jourDeChauffe): string
    {
        return self::ligne(max(1, $jourDeChauffe), self::PALIERS_ETENDUS)['palier'];
    }

    /** Budget TOTAL d'une ressource (table P1-P8), jamais au-delà du plafond serveur. */
    public static function quotaRessourceEtendu(int $jourDeChauffe): int
    {
        return min(self::ligne(max(1, $jourDeChauffe), self::PALIERS_ETENDUS)['total'], PasserelleLocale::PLAFOND_MAX);
    }

    public static function quotaBoiteEtendu(int $jourDeChauffe): int
    {
        return min(self::ligne(max(1, $jourDeChauffe), self::PALIERS_ETENDUS)['quota'], self::PLAFOND_BOITE_JOUR);
    }

    /**
     * Jour de chauffe EFFECTIF de $jour, d'après les jours où la boîte a réellement envoyé.
     * Seuls comptent les jours STRICTEMENT avant $jour (l'envoi du jour même n'avance pas
     * le quota du jour).
     *
     * @param  list<DateTimeImmutable>  $joursAvecEnvoi
     */
    public static function jourDeChauffe(array $joursAvecEnvoi, DateTimeImmutable $jour): int
    {
        return self::rejouer(self::PALIERS, $joursAvecEnvoi, [], $jour);
    }

    /**
     * Jours EFFECTIFS à partir des volumes réellement envoyés : un jour ne compte que si le volume atteint
     * `$part` × le quota du palier où la boîte se trouvait CE jour-là (arrondi au supérieur, au moins 1).
     *
     * Avec `$parRessource`, le seuil se mesure sur le budget TOTAL de la ressource (IP : 10 en P1 = 50 % de 20),
     * pas sur le quota d'une seule boîte.
     *
     * `$reculs` (L8-F07a2) : `null` = ancienne règle (table PALIERS P1-P3, sans recul : chemin en service, inchangé).
     * Une liste (même vide) REJOUE, jour par jour, les envois et les reculs de la ressource avec la table étendue
     * P1-P8, comme {@see self::jourDeChauffeAvecReculs()} (recul avant l'envoi du même jour) : le seuil d'un jour
     * suit donc le palier réel (P4 : 50 % de 100 = 50 ; après deux reculs jusqu'en P1 : 50 % de 20 = 10), au lieu
     * d'un seuil figé par l'ancienne table plafonnée à 50.
     *
     * @param  array<string, int>  $volumes  'Y-m-d' (Paris) => messages envoyés ce jour-là
     * @param  list<DateTimeImmutable>|null  $reculs  dates des reculs de CETTE ressource
     * @return list<DateTimeImmutable>
     */
    public static function joursEffectifs(array $volumes, float $part = Seuils::JOUR_EFFECTIF_PART, bool $parRessource = false, ?array $reculs = null): array
    {
        ksort($volumes);
        $effectifs = [];
        foreach ($volumes as $j => $n) {
            $jour = new DateTimeImmutable($j, new \DateTimeZone(JourEnvoi::FUSEAU));
            if ($reculs === null) {
                $base = self::jourDeChauffe($effectifs, $jour);
                $quota = $parRessource ? self::quotaRessource($base) : self::quotaBoite($base);
            } else {
                $base = self::jourDeChauffeAvecReculs($effectifs, $reculs, $jour);
                $quota = $parRessource ? self::quotaRessourceEtendu($base) : self::quotaBoiteEtendu($base);
            }
            $minimum = max(1, (int) ceil(round($part * $quota, 9)));
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

    /** Budget TOTAL d'une ressource (IP ou domaine) pour ce jour de chauffe : 20 / 35 / 50, jamais au-delà du plafond dur. */
    public static function quotaRessource(int $jourDeChauffe): int
    {
        return min(self::ligne(max(1, $jourDeChauffe))['total'], PasserelleLocale::PLAFOND_MAX);
    }

    /**
     * Rejeu chronologique : jours d'envoi et reculs (à date égale, le recul passe d'abord).
     *
     * @param  list<array{jusqua: ?int, palier: string, quota: int, total: int}>  $table
     * @param  list<DateTimeImmutable>  $joursAvecEnvoi
     * @param  list<DateTimeImmutable>  $reculs
     */
    private static function rejouer(array $table, array $joursAvecEnvoi, array $reculs, DateTimeImmutable $jour): int
    {
        $jour = JourEnvoi::de($jour);
        $evenements = [];
        foreach ($joursAvecEnvoi as $j) {
            $civil = JourEnvoi::de($j);
            if ($civil < $jour) {
                $evenements[$civil->format('Y-m-d') . '#1'] = $civil;
            }
        }
        foreach ($reculs as $r) {
            $civil = JourEnvoi::de($r);
            if ($civil <= $jour) {
                $evenements[$civil->format('Y-m-d') . '#0'] = $civil;
            }
        }
        ksort($evenements);
        $effectifs = 0;
        $verts = 0;
        $precedent = null;
        foreach ($evenements as $cle => $civil) {
            if (str_ends_with($cle, '#0')) {
                if ($effectifs !== ($recule = self::reculerUnPalier($table, $effectifs))) {
                    $effectifs = $recule;
                    $verts = self::JOURS_VERTS_APRES_RECUL;
                }

                continue;
            }
            if ($precedent !== null) {
                [$effectifs, $verts] = self::apresInactivite($table, $effectifs, $verts, self::ecart($precedent, $civil));
            }
            if ($verts > 0) {
                $verts--;
            } else {
                $effectifs++;
            }
            $precedent = $civil;
        }
        if ($precedent !== null) {
            [$effectifs] = self::apresInactivite($table, $effectifs, $verts, self::ecart($precedent, $jour));
        }

        return $effectifs + 1;
    }

    /**
     * Inactivité (7 jours civils sans envoi) : recul d'un palier (règle inchangée). CAS NON SPÉCIFIÉ — l'inactivité
     * PENDANT la période d'essai de {@see self::JOURS_VERTS_APRES_RECUL} jours après un recul : option la plus
     * PRUDENTE, la période d'essai REPART à 3 (même si le recul d'inactivité est sans effet en P1). Hors période
     * d'essai, l'inactivité n'en ouvre pas (comportement en service inchangé). À confirmer en banc.
     *
     * @param  list<array{jusqua: ?int, palier: string, quota: int, total: int}>  $table
     * @return array{0: int, 1: int} [jours effectifs, jours d'essai restants]
     */
    private static function apresInactivite(array $table, int $effectifs, int $verts, int $ecart): array
    {
        if ($ecart < self::INACTIVITE_JOURS) {
            return [$effectifs, $verts];
        }

        return [self::reculerUnPalier($table, $effectifs), $verts > 0 ? self::JOURS_VERTS_APRES_RECUL : 0];
    }

    /** Jours civils entre deux jours : 1 = jours consécutifs. */
    private static function ecart(DateTimeImmutable $de, DateTimeImmutable $a): int
    {
        return (int) $de->diff($a)->format('%r%a');
    }

    /**
     * Ramène le prochain jour au premier jour du palier d'avant (P1 : inchangé).
     *
     * @param  list<array{jusqua: ?int, palier: string, quota: int, total: int}>  $table
     */
    private static function reculerUnPalier(array $table, int $effectifs): int
    {
        $prochain = $effectifs + 1;
        $debutPrecedent = null;
        $debut = 1;
        foreach ($table as $p) {
            if ($p['jusqua'] === null || $prochain <= $p['jusqua']) {
                return $debutPrecedent === null ? $effectifs : $debutPrecedent - 1;
            }
            $debutPrecedent = $debut;
            $debut = $p['jusqua'] + 1;
        }

        return $effectifs;
    }

    /**
     * @param  list<array{jusqua: ?int, palier: string, quota: int, total: int}>  $table
     * @return array{jusqua: ?int, palier: string, quota: int, total: int}
     */
    private static function ligne(int $jourDeChauffe, array $table = self::PALIERS): array
    {
        $derniere = $table[0];
        foreach ($table as $p) {
            if ($p['jusqua'] === null || $jourDeChauffe <= $p['jusqua']) {
                return $p;
            }
            $derniere = $p;
        }

        return $derniere;
    }
}
