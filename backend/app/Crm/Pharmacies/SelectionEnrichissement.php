<?php

namespace App\Crm\Pharmacies;

use App\Crm\FichesProtegees;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Quelles pharmacies enrichir, et dans quel ordre — UNE définition, partagée
 * par `crm:pharmacies:enrichir` et `crm:pharmacies:rapport-enrichissement`.
 *
 * OUVERTE = NAF 47.73Z, ni supprimée, ni fermée à l'INSEE, ni marquée
 * `non_officine` ni écartée (`metadata.ecartee_motif`), ni fiche protégée.
 */
final class SelectionEnrichissement
{
    /** Clé de `metadata` qui porte la date et les sources du dernier passage. */
    public const CLE_PASSAGE = 'enrichissement_pharmacie';

    /**
     * Personne physique côté SQL : forme juridique « 1xxx », VIDE ou NULL (par prudence),
     * ou marque `metadata.personne_physique`. Même critère que `estPersonnePhysique`.
     */
    public const PHYSIQUE_SQL = "(COALESCE(btrim(legal_form), '') = '' OR btrim(legal_form) LIKE '1%' OR COALESCE(metadata->>'personne_physique', '') = 'true')";

    /**
     * Colonnes dont l'absence compte comme « champ manquant ». Pour une personne
     * physique, seuls le site et le téléphone comptent (l'adresse, l'e-mail et les
     * coordonnées ne sont jamais enrichis pour elle).
     */
    private const MANQUANTS_SQL = [
        "(phone IS NULL OR phone = '')",
        "(website IS NULL OR website = '')",
        '(NOT ' . self::PHYSIQUE_SQL . " AND (email_generic IS NULL OR email_generic = ''))",
        '(NOT ' . self::PHYSIQUE_SQL . " AND (address IS NULL OR address = ''))",
        '(NOT ' . self::PHYSIQUE_SQL . ' AND lat IS NULL)',
    ];

    public static function ouvertes(string $workspaceId): Builder
    {
        $q = DB::table('companies')
            ->where('workspace_id', $workspaceId)
            ->whereNull('deleted_at')
            ->whereNull('insee_ferme_le')
            ->whereIn('naf', ['47.73Z', '4773Z'])
            ->whereRaw("COALESCE(metadata->>'type_etablissement', '') <> 'non_officine'")
            ->whereRaw("NOT jsonb_exists(COALESCE(metadata, '{}'::jsonb), 'ecartee_motif')");
        FichesProtegees::exclure($q);

        return $q;
    }

    /** À enrichir : au moins un champ manquant et pas de passage depuis `$jours` jours. */
    public static function aEnrichir(string $workspaceId, int $jours): Builder
    {
        return self::ouvertes($workspaceId)
            ->whereRaw('(' . implode(' OR ', self::MANQUANTS_SQL) . ')')
            ->whereRaw(
                "NOT (jsonb_exists(COALESCE(metadata, '{}'::jsonb), ?) AND (metadata->?->>'le')::timestamptz > now() - make_interval(days => ?))",
                [self::CLE_PASSAGE, self::CLE_PASSAGE, max(0, $jours)],
            );
    }

    /** Grandes d'abord, puis les plus lacunaires, puis id (ordre stable). */
    public static function classer(Builder $q): Builder
    {
        $manquants = implode(' + ', array_map(fn (string $c): string => "CASE WHEN {$c} THEN 1 ELSE 0 END", self::MANQUANTS_SQL));

        return $q->orderByRaw('COALESCE(effectif_max, effectif_min, 0) DESC')
            ->orderByRaw("({$manquants}) DESC")
            ->orderBy('id');
    }

    /**
     * Personne physique = forme juridique INSEE « 1xxx » (même critère que
     * `PersonnesController` : `COALESCE(btrim(legal_form), '') LIKE '1%'`),
     * forme VIDE ou NULL (traitée comme personne physique par prudence : le
     * waterfall complet écrirait des données nominatives), ou, en complément,
     * la marque `metadata.personne_physique`.
     */
    public static function estPersonnePhysique(mixed $metadata, mixed $legalForm = null): bool
    {
        $forme = trim((string) $legalForm);
        if ($forme === '' || str_starts_with($forme, '1')) {
            return true;
        }
        $m = is_string($metadata) ? json_decode($metadata, true) : $metadata;

        return is_array($m) && ($m['personne_physique'] ?? false) === true;
    }
}
