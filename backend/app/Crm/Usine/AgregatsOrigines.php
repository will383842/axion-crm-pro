<?php

namespace App\Crm\Usine;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/**
 * Agrégats d'origines à destination de l'Usine (L7) — AUCUNE donnée de personne.
 *
 * Sortie à clés FERMÉES : jour, reseau, compte, mot, demandes, guides, accords,
 * mises_a_l_ecart. Jamais d'adresse, de nom, d'identifiant, de `post_ref` ni de
 * `commentaire_ref`. Une cellule inférieure au seuil devient "<3".
 */
final class AgregatsOrigines
{
    public const FONCTION = 'origines.lecture';

    public const CLES = ['jour', 'reseau', 'compte', 'mot', 'demandes', 'guides', 'accords', 'mises_a_l_ecart'];

    private const METRIQUES = ['demandes', 'guides', 'accords', 'mises_a_l_ecart'];

    /**
     * Espaces où l'Usine a le droit de voir les chiffres : le business d'Axion, fonction ouverte.
     *
     * @return list<string>
     */
    public static function espaces(): array
    {
        $slug = (string) config('crm.ingest.business_workspace', 'axion-ia');

        return DB::table('workspaces')
            ->join('fonctions_espace as f', 'f.espace_id', '=', 'workspaces.id')
            ->where('workspaces.slug', $slug)
            ->whereNull('workspaces.deleted_at')
            ->where('f.fonction', self::FONCTION)
            ->where('f.ouverte', true)
            ->pluck('workspaces.id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }

    /**
     * Comptages bruts d'un jour (sans masquage), lecture seule.
     *
     * @return list<array<string, mixed>>
     */
    public static function pourJour(string $espaceId, string $jour): array
    {
        return WorkspaceContext::run($espaceId, static function () use ($espaceId, $jour): array {
            $lignes = DB::select(
                <<<'SQL'
                SELECT e->>'reseau' AS reseau,
                       e->>'compte' AS compte,
                       e->>'mot'    AS mot,
                       COUNT(*)::int AS demandes,
                       COUNT(*) FILTER (WHERE COALESCE(e->>'guide', '') <> '')::int AS guides,
                       COUNT(*) FILTER (WHERE EXISTS (
                           SELECT 1 FROM jsonb_array_elements(COALESCE(p.consentements, '[]'::jsonb)) c
                           WHERE c->>'nature' = 'optin'))::int AS accords,
                       COUNT(*) FILTER (WHERE COALESCE(e->>'masque_le', '') <> '')::int AS mises_a_l_ecart
                FROM personnes p
                CROSS JOIN LATERAL jsonb_array_elements(
                    CASE WHEN jsonb_typeof(p.origines) = 'array' THEN p.origines ELSE '[]'::jsonb END
                ) AS e
                WHERE p.workspace_id = ?
                  AND (e->>'date') IS NOT NULL
                  AND substr(e->>'date', 1, 10) = ?
                  AND COALESCE(e->>'reseau', '') <> ''
                  AND COALESCE(e->>'mot', '') <> ''
                GROUP BY 1, 2, 3
                ORDER BY 1, 2, 3
                SQL,
                [$espaceId, $jour],
            );

            $sortie = [];
            foreach ($lignes as $l) {
                $sortie[] = [
                    'jour' => $jour,
                    'reseau' => (string) $l->reseau,
                    'compte' => (string) ($l->compte ?? ''),
                    'mot' => (string) $l->mot,
                    'demandes' => (int) $l->demandes,
                    'guides' => (int) $l->guides,
                    'accords' => (int) $l->accords,
                    'mises_a_l_ecart' => (int) $l->mises_a_l_ecart,
                ];
            }

            return $sortie;
        });
    }

    /**
     * Seuil + masquage secondaire (anti-différence).
     *
     * 1. une cellule < seuil devient "<3" ;
     * 2. dans un groupe (jour, reseau, compte) où UNE SEULE cellule d'une
     *    métrique est masquée, la plus petite autre cellule du groupe l'est aussi
     *    (sinon la différence avec un total connu la révélerait) ;
     * 3. une ligne dont toutes les cellules sont masquées est retirée.
     *
     * @param  list<array<string, mixed>>  $lignes
     * @return list<array<string, mixed>>
     */
    public static function masquer(array $lignes, int $seuil = 3): array
    {
        $masque = static fn (int|string $v): bool => $v === '<3';

        foreach ($lignes as &$l) {
            foreach (self::METRIQUES as $m) {
                $l[$m] = ((int) $l[$m]) < $seuil ? '<3' : (int) $l[$m];
            }
        }
        unset($l);

        $groupes = [];
        foreach ($lignes as $i => $l) {
            $groupes[$l['jour'] . '|' . $l['reseau'] . '|' . $l['compte']][] = $i;
        }
        foreach ($groupes as $indices) {
            foreach (self::METRIQUES as $m) {
                $masquees = array_filter($indices, static fn (int $i): bool => $masque($lignes[$i][$m]));
                if (count($masquees) !== 1) {
                    continue;
                }
                $visibles = array_filter($indices, static fn (int $i): bool => ! $masque($lignes[$i][$m]));
                if ($visibles === []) {
                    continue;
                }
                usort($visibles, static fn (int $a, int $b): int => $lignes[$a][$m] <=> $lignes[$b][$m]);
                $lignes[$visibles[0]][$m] = '<3';
            }
        }

        $sortie = [];
        foreach ($lignes as $l) {
            $tout = true;
            foreach (self::METRIQUES as $m) {
                if (! $masque($l[$m])) {
                    $tout = false;
                }
            }
            if (! $tout) {
                $sortie[] = array_intersect_key($l, array_flip(self::CLES)) + [];
            }
        }

        return array_map(static fn (array $l): array => array_replace(array_flip(self::CLES), $l), $sortie);
    }
}
