<?php

namespace App\Crm\Ingest;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fusion de l'historique d'origine de deux fiches `personnes` en doublon
 * (ADR-CONTRAT-V3-ORIGINE, I4). À appeler par tout chemin qui rapproche deux
 * fiches de personne, dans la transaction de la fusion.
 *
 * Règle de Will — AUCUNE suppression de données de personne :
 *   - `origines` de la fiche gardée = UNION SANS RETRAIT de ses entrées et de
 *     celles de la fiche absorbée (même dédoublonnage et même tri que
 *     l'ingestion), plafonnée à 50 ;
 *   - ce qui dépasse le plafond passe dans `personne_origines_archive` (ajout
 *     seul), jamais perdu ;
 *   - l'archive de la fiche absorbée est COPIÉE vers la fiche gardée ;
 *   - la fiche absorbée, son tableau et son archive ne sont pas touchés : rien
 *     n'est effacé ni modifié. Idempotente (rejouable).
 */
final class FusionOriginesPersonnes
{
    public function fusionner(string $workspaceId, int $gardeId, int $absorbeeId): void
    {
        if ($gardeId === $absorbeeId
            || ! Schema::hasColumn('personnes', 'origines')
            || ! Schema::hasTable('personne_origines_archive')) {
            return;
        }

        DB::transaction(function () use ($workspaceId, $gardeId, $absorbeeId): void {
            // Verrou dans l'ordre des identifiants : pas d'interblocage entre deux fusions croisées.
            $lignes = DB::table('personnes')
                ->where('workspace_id', $workspaceId)
                ->whereIn('id', [$gardeId, $absorbeeId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'origines'])
                ->keyBy('id');
            if (! $lignes->has($gardeId) || ! $lignes->has($absorbeeId)) {
                return;
            }

            $fusion = ContenusAccords::fusionnerOrigines(
                $this->liste($lignes[$gardeId]->origines ?? null),
                $this->liste($lignes[$absorbeeId]->origines ?? null),
            );

            // L'archive déjà acquise de la fiche absorbée suit la fiche gardée.
            foreach (DB::table('personne_origines_archive')->where('workspace_id', $workspaceId)->where('personne_id', $absorbeeId)->get(['cle', 'origine', 'archive_le', 'masque_le']) as $a) {
                DB::table('personne_origines_archive')->insertOrIgnore([
                    'workspace_id' => $workspaceId, 'personne_id' => $gardeId,
                    'cle' => $a->cle, 'origine' => $a->origine, 'archive_le' => $a->archive_le, 'masque_le' => $a->masque_le,
                ]);
            }
            foreach ($fusion['archive'] as $entree) {
                DB::table('personne_origines_archive')->insertOrIgnore([
                    'workspace_id' => $workspaceId, 'personne_id' => $gardeId,
                    'cle' => ContenusAccords::cleOrigine($entree),
                    'origine' => json_encode($entree, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'archive_le' => now(),
                    'masque_le' => $entree['masque_le'] ?? null,
                ]);
            }

            DB::table('personnes')->where('workspace_id', $workspaceId)->where('id', $gardeId)->update([
                'origines' => json_encode($fusion['garde'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
        });
    }

    /** @return list<array<string, mixed>> */
    private function liste(mixed $brut): array
    {
        $d = is_array($brut) ? $brut : (is_string($brut) && $brut !== '' ? json_decode($brut, true) : null);

        return is_array($d) ? array_values(array_filter($d, 'is_array')) : [];
    }
}
