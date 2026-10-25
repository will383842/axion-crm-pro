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

    /** Colonnes dont l'absence compte comme « champ manquant ». */
    private const MANQUANTS_SQL = [
        "(phone IS NULL OR phone = '')",
        "(website IS NULL OR website = '')",
        "(email_generic IS NULL OR email_generic = '')",
        "(address IS NULL OR address = '')",
        '(lat IS NULL)',
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

    public static function estPersonnePhysique(mixed $metadata): bool
    {
        $m = is_string($metadata) ? json_decode($metadata, true) : $metadata;

        return is_array($m) && ($m['personne_physique'] ?? false) === true;
    }
}
