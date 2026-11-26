<?php

namespace App\Crm\Sites;

use App\Crm\FichesProtegees;
use App\Crm\Insee\MiseAJourMensuelle;
use InvalidArgumentException;

/**
 * PÉRIMÈTRE ET ORDRE DES LOTS de la recherche de site (PR 3a). Lecture seule.
 *
 * ── Les exclusions ───────────────────────────────────────────────────────
 * Une fiche n'est PAS dans le périmètre si :
 *   - `enriched_at` est posé (le waterfall est déjà passé) ;
 *   - elle a un site (`website`), deviné ou non ;
 *   - elle a un `email_generic` ;
 *   - une personne (contact vivant) de la fiche a un e-mail ;
 *   - elle est non diffusible (marquage INSEE ou motif d'archivage) ;
 *   - elle est protégée ({@see FichesProtegees}) ;
 *   - elle est fermée (`insee_ferme_le`) ou radiée (motif d'archivage) ;
 *   - elle est supprimée (`deleted_at`) ;
 *   - elle porte déjà le marqueur `metadata.recherche_site`.
 * Un téléphone seul n'exclut pas.
 *
 * ── Les lots ─────────────────────────────────────────────────────────────
 * Par tranche d'effectif INSEE (`effectif_range`) :
 *   1 : 11 à 53 (10 salariés ou plus) ; 2 : 03 ; 3 : 02 ; 4 : 01 ; 5 : 00 ;
 *   6 : NN ; 7 : tout le reste (tranche absente ou inconnue).
 * Dans un lot : tranche exacte décroissante (53, 52, … 11), puis région
 * Auvergne-Rhône-Alpes (84), puis Île-de-France (11), puis le reste, puis `id`.
 *
 * Aucun argument n'est une donnée utilisateur, hors l'espace et la limite,
 * qui sont liés.
 */
final class PerimetreEnrichissement
{
    /** Clé du marqueur dans `companies.metadata`. */
    public const MARQUEUR = 'recherche_site';

    /** @var list<int> */
    public const LOTS = [1, 2, 3, 4, 5, 6, 7];

    /** Tranches du lot 1, de la plus grande à la plus petite. */
    public const TRANCHES_LOT_1 = ['53', '52', '51', '42', '41', '32', '31', '22', '21', '12', '11'];

    /** Tranche unique des lots 2 à 6. */
    private const TRANCHE_DU_LOT = [2 => '03', 3 => '02', 4 => '01', 5 => '00', 6 => 'NN'];

    /** Régions prioritaires, dans l'ordre. */
    public const REGIONS = ['84', '11'];

    /**
     * Les fiches du lot `$lot`, dans l'ordre de traitement.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function selectionSql(string $workspaceId, int $lot, int $limite): array
    {
        return [
            'SELECT c.id, c.siren, c.denomination, c.enseigne, c.effectif_range, c.region_code
               FROM companies c
              WHERE ' . self::conditionSql($lot) . '
              ORDER BY ' . self::ordreSql() . '
              LIMIT ?',
            [$workspaceId, $limite],
        ];
    }

    /**
     * Le nombre de fiches du lot.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function compteSql(string $workspaceId, int $lot): array
    {
        return [
            'SELECT count(*) AS n FROM companies c WHERE ' . self::conditionSql($lot),
            [$workspaceId],
        ];
    }

    /** Espace (1re liaison), exclusions 1.3 et tranche du lot. */
    private static function conditionSql(int $lot): string
    {
        $marqueur = "(c.metadata -> '" . self::MARQUEUR . "') IS NULL";
        $motifs = "'" . MiseAJourMensuelle::MOTIF_NON_DIFFUSIBLE . "', '" . MiseAJourMensuelle::MOTIF_FERMETURE . "'";

        return "c.workspace_id = ?
                AND c.deleted_at IS NULL
                AND c.enriched_at IS NULL
                AND (c.website IS NULL OR btrim(c.website) = '')
                AND (c.email_generic IS NULL OR btrim(c.email_generic) = '')
                AND NOT EXISTS (SELECT 1 FROM contacts ct WHERE ct.company_id = c.id AND ct.deleted_at IS NULL
                                AND ct.email IS NOT NULL AND btrim(ct.email::text) <> '')
                AND c.insee_non_diffusible_le IS NULL
                AND c.insee_ferme_le IS NULL
                AND COALESCE(c.archive_reason, '') NOT IN ({$motifs})
                AND {$marqueur}
                AND " . FichesProtegees::conditionSql('c.id', null, 'c.workspace_id')
            . ' AND ' . self::trancheSql($lot);
    }

    private static function trancheSql(int $lot): string
    {
        if ($lot === 1) {
            return 'c.effectif_range IN (' . implode(', ', array_map(static fn (string $t): string => "'{$t}'", self::TRANCHES_LOT_1)) . ')';
        }
        if (isset(self::TRANCHE_DU_LOT[$lot])) {
            return "c.effectif_range = '" . self::TRANCHE_DU_LOT[$lot] . "'";
        }
        if ($lot === 7) {
            $connues = [...self::TRANCHES_LOT_1, ...array_values(self::TRANCHE_DU_LOT)];

            return '(c.effectif_range IS NULL OR c.effectif_range NOT IN (' . implode(', ', array_map(static fn (string $t): string => "'{$t}'", $connues)) . '))';
        }

        throw new InvalidArgumentException("Lot inconnu : {$lot}.");
    }

    /** Tranche exacte décroissante, puis région 84, 11, reste, puis id. */
    private static function ordreSql(): string
    {
        return "c.effectif_range DESC NULLS LAST,
                CASE c.region_code WHEN '" . self::REGIONS[0] . "' THEN 0 WHEN '" . self::REGIONS[1] . "' THEN 1 ELSE 2 END,
                c.id";
    }
}
