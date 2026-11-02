<?php

namespace App\Crm\Rgpd;

use Illuminate\Support\Facades\DB;

/**
 * L3c-0 (ADR contrat v3 § 6) — MISE À L'ÉCART : le seul geste que le CRM
 * accomplit sur une personne qui s'oppose ou demande l'effacement.
 *
 * Aucune ligne n'est supprimée. La personne n'est plus jamais contactée
 * (`opposition_at`, abonnements désabonnés, contacts à la corbeille douce,
 * candidats marqués) ; les données sont GARDÉES, c'est Will qui décide ensuite.
 * À appeler dans le contexte d'espace et la transaction de l'appelant.
 */
final class MiseAEcart
{
    /**
     * @param  list<int|string>  $ids  identifiants `personnes`
     * @return array{personnes: int, abonnements: int}
     */
    public static function personnes(array $ids, string $motif): array
    {
        if ($ids === []) {
            return ['personnes' => 0, 'abonnements' => 0];
        }

        $abonnements = DB::table('abonnements')
            ->whereIn('personne_id', $ids)
            ->where('statut', '!=', 'desabonne')
            ->update([
                'statut' => 'desabonne',
                'desabonne_at' => now(),
                'motif_desabonnement' => $motif,
                'dernier_evenement_at' => now(),
                'updated_at' => now(),
            ]);

        // `opposition_at` déjà posée : elle fait foi, on ne la repousse pas.
        DB::table('personnes')->whereIn('id', $ids)->whereNull('opposition_at')
            ->update(['opposition_at' => now(), 'opposition_motif' => $motif, 'updated_at' => now()]);

        return ['personnes' => count($ids), 'abonnements' => $abonnements];
    }

    /**
     * Contacts : corbeille douce (`deleted_at`) + trace dans `metadata`.
     *
     * @param  list<int|string>  $ids
     */
    public static function contacts(array $ids, string $motif): int
    {
        if ($ids === []) {
            return 0;
        }

        return DB::table('contacts')->whereIn('id', $ids)->whereNull('deleted_at')->update([
            'deleted_at' => now(),
            'metadata' => DB::raw(
                "COALESCE(metadata, '{}'::jsonb) || jsonb_build_object('mis_a_l_ecart_le', to_char(now(), 'YYYY-MM-DD'), 'motif', "
                . DB::getPdo()->quote($motif) . ')',
            ),
        ]);
    }

    /**
     * Candidats : trace dans `attributes` (la table n'a pas de corbeille).
     *
     * @param  list<int|string>  $ids
     */
    public static function candidats(array $ids, string $motif): int
    {
        if ($ids === []) {
            return 0;
        }

        return DB::table('candidates')->whereIn('id', $ids)->update([
            'attributes' => DB::raw(
                "COALESCE(attributes, '{}'::jsonb) || jsonb_build_object('mis_a_l_ecart_le', to_char(now(), 'YYYY-MM-DD'), 'motif', "
                . DB::getPdo()->quote($motif) . ')',
            ),
            'updated_at' => now(),
        ]);
    }
}
