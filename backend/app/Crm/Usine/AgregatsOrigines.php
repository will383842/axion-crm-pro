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

        return array_values(DB::table('workspaces')
            ->join('fonctions_espace as f', 'f.espace_id', '=', 'workspaces.id')
            ->where('workspaces.slug', $slug)
            ->whereNull('workspaces.deleted_at')
            ->where('f.fonction', self::FONCTION)
            ->where('f.ouverte', true)
            ->pluck('workspaces.id')
            ->map(static fn ($id): string => (string) $id)
            ->all());
    }

    /**
     * Comptages bruts d'un jour (sans masquage), lecture seule.
     *
     * Chaque métrique compte des PERSONNES DISTINCTES (COUNT(DISTINCT p.id)), jamais des
     * entrées : une personne qui commente 3 fois le même jour vaut 1, pas 3. « accords » =
     * `consentements` non vide, comme RequetesOrigines (PR-A). Le jour est le jour UTC de
     * `date` (ISO 8601, suffixe Z).
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
                       COUNT(DISTINCT p.id)::int AS demandes,
                       COUNT(DISTINCT p.id) FILTER (WHERE COALESCE(e->>'guide', '') <> '')::int AS guides,
                       COUNT(DISTINCT p.id) FILTER (WHERE jsonb_typeof(p.consentements) = 'array'
                                                      AND jsonb_array_length(p.consentements) > 0)::int AS accords,
                       COUNT(DISTINCT p.id) FILTER (WHERE COALESCE(e->>'masque_le', '') <> '')::int AS mises_a_l_ecart
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
     * Normalise le compte au format exigé par le récepteur de l'Usine (^[a-z][a-z0-9_]{1,39}$),
     * ou null s'il reste invalide : la ligne est alors exclue du push, jamais envoyée.
     */
    public static function normaliserCompte(string $compte): ?string
    {
        $c = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($compte))) ?? '';
        if ($c === '' || trim($c, '_') === '') {
            return null;
        }
        if (! preg_match('/^[a-z]/', $c)) {
            $c = 'c' . $c;
        }
        $c = substr($c, 0, 40);

        return preg_match('/^[a-z][a-z0-9_]{1,39}$/', $c) === 1 ? $c : null;
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @return list<array<string, mixed>>
     */
    private static function compteValide(array $lignes): array
    {
        $sortie = [];
        foreach ($lignes as $l) {
            $compte = self::normaliserCompte((string) ($l['compte'] ?? ''));
            if ($compte !== null) {
                $l['compte'] = $compte;
                $sortie[] = $l;
            }
        }

        return $sortie;
    }

    /**
     * Seuil + masquage secondaire (anti-différence), sur DEUX axes.
     *
     * 1. une cellule < seuil devient "<3" ;
     * 2. pour chaque métrique, dans chaque LIGNE (jour, reseau, compte → les mots) et chaque
     *    COLONNE (jour, mot → les réseaux/comptes), si UNE SEULE cellule est masquée et qu'il en
     *    reste de visibles, la plus petite visible l'est aussi (sinon la différence avec un total
     *    connu la révélerait) ; répété jusqu'à stabilité, car un masquage peut en créer un seul
     *    dans l'autre axe ;
     * 3. une ligne dont toutes les cellules sont masquées est retirée.
     *
     * @param  list<array<string, mixed>>  $lignes
     * @return list<array<string, mixed>>
     */
    public static function masquer(array $lignes, int $seuil = 3): array
    {
        $lignes = self::compteValide($lignes);

        $masque = static fn (int|string $v): bool => $v === '<3';

        foreach ($lignes as &$l) {
            foreach (self::METRIQUES as $m) {
                $l[$m] = ((int) $l[$m]) < $seuil ? '<3' : (int) $l[$m];
            }
        }
        unset($l);

        $axes = [];
        foreach ($lignes as $i => $l) {
            $axes['L'][$l['jour'] . '|' . $l['reseau'] . '|' . $l['compte']][] = $i;
            $axes['C'][$l['jour'] . '|' . $l['mot']][] = $i;
        }

        do {
            $change = false;
            foreach ($axes as $groupes) {
                foreach ($groupes as $indices) {
                    foreach (self::METRIQUES as $m) {
                        $masquees = array_filter($indices, static fn (int $i): bool => $masque($lignes[$i][$m]));
                        if (count($masquees) !== 1) {
                            continue;
                        }
                        $visibles = array_values(array_filter($indices, static fn (int $i): bool => ! $masque($lignes[$i][$m])));
                        if ($visibles === []) {
                            continue;
                        }
                        usort($visibles, static fn (int $a, int $b): int => $lignes[$a][$m] <=> $lignes[$b][$m]);
                        $lignes[$visibles[0]][$m] = '<3';
                        $change = true;
                    }
                }
            }
        } while ($change);

        $sortie = [];
        foreach ($lignes as $l) {
            $tout = true;
            foreach (self::METRIQUES as $m) {
                if (! $masque($l[$m])) {
                    $tout = false;
                }
            }
            if (! $tout) {
                $sortie[] = array_replace(array_flip(self::CLES), array_intersect_key($l, array_flip(self::CLES)));
            }
        }

        return $sortie;
    }
}
