<?php

namespace App\Envoi\Resultats;

use App\Envoi\Chauffe\DepotChauffe;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/**
 * Lecture des résultats d'envoi (lot R04) à partir des compteurs `envoi_chauffe_jours` : les MÊMES que ceux des freins.
 *
 * Définition UNIQUE (écran, contrat, freins) : remis = envoyés − rebonds durs − rebonds mous, jamais négatif ; le taux de
 * réponse se calcule sur les envoyés. Les rebonds mous ne sont pas comptés dans cette table : ils valent 0 ici (noté).
 * Les retours (rebonds, plaintes, réponses) sont inscrits sous la pseudo-boîte `(retours)` : ils forment un groupe à part.
 * Les compteurs d'IP (`ressource_type = 'ip'`) ne sont jamais lus. Lecture seule.
 */
final class DepotResultats
{
    public const PARS_CHAUFFE = ['boite', 'domaine'];

    /** Clé de groupe : la boîte telle quelle, ou la partie après « @ » pour le domaine. */
    private static function cleSql(string $par): string
    {
        return $par === 'domaine' ? "CASE WHEN position('@' in boite) > 0 THEN split_part(boite, '@', 2) ELSE boite END" : 'boite';
    }

    /** @return list<array<string, mixed>> lignes de résultat, une par groupe, bornes de jour incluses */
    public function parChauffe(string $ws, string $par, string $du, string $au): array
    {
        $cle = self::cleSql($par);
        /** @var list<object{cle: string, e: int, d: int, p: int, r: int}> $lignes */
        $lignes = WorkspaceContext::run($ws, fn () => DB::select(
            "SELECT {$cle} AS cle, SUM(envoyes)::int AS e, SUM(rebonds_durs)::int AS d, SUM(plaintes)::int AS p, SUM(reponses)::int AS r
             FROM envoi_chauffe_jours
             WHERE workspace_id = ? AND ressource_type = 'domaine' AND jour BETWEEN ? AND ?
             GROUP BY 1 ORDER BY 1",
            [$ws, $du, $au],
        ));

        return array_map(fn (object $l): array => self::ligne((string) $l->cle, (int) $l->e, (int) $l->d, (int) $l->p, (int) $l->r), $lignes);
    }

    /**
     * Totaux de la période (toutes boîtes, retours compris).
     *
     * @return array<string, mixed>
     */
    public function totaux(string $ws, string $du, string $au): array
    {
        /** @var object{e: int, d: int, p: int, r: int} $l */
        $l = WorkspaceContext::run($ws, fn () => DB::selectOne(
            "SELECT COALESCE(SUM(envoyes),0)::int AS e, COALESCE(SUM(rebonds_durs),0)::int AS d, COALESCE(SUM(plaintes),0)::int AS p, COALESCE(SUM(reponses),0)::int AS r
             FROM envoi_chauffe_jours WHERE workspace_id = ? AND ressource_type = 'domaine' AND jour BETWEEN ? AND ?",
            [$ws, $du, $au],
        ));

        return self::ligne('total', (int) $l->e, (int) $l->d, (int) $l->p, (int) $l->r, 'Total');
    }

    /** @return array<string, array{remis: int, rebonds: int, reponses: int}> par jour (Y-m-d) */
    public function parJour(string $ws, string $du, string $au): array
    {
        /** @var list<object{jour: string, e: int, d: int, r: int}> $lignes */
        $lignes = WorkspaceContext::run($ws, fn () => DB::select(
            "SELECT jour::text AS jour, SUM(envoyes)::int AS e, SUM(rebonds_durs)::int AS d, SUM(reponses)::int AS r
             FROM envoi_chauffe_jours WHERE workspace_id = ? AND ressource_type = 'domaine' AND jour BETWEEN ? AND ?
             GROUP BY jour",
            [$ws, $du, $au],
        ));
        $v = [];
        foreach ($lignes as $l) {
            $v[(string) $l->jour] = ['remis' => max(0, (int) $l->e - (int) $l->d), 'rebonds' => (int) $l->d, 'reponses' => (int) $l->r];
        }

        return $v;
    }

    /** @return array<string, mixed> */
    private static function ligne(string $cle, int $envoyes, int $durs, int $plaintes, int $reponses, ?string $libelle = null): array
    {
        $mous = 0;

        return [
            'cle' => $cle,
            'libelle' => $libelle ?? ($cle === DepotChauffe::BOITE_RETOURS ? 'Retours non rattachés à une boîte' : $cle),
            'envoyes' => $envoyes,
            'remis' => max(0, $envoyes - $durs - $mous),
            'rebonds_durs' => $durs,
            'rebonds_mous' => $mous,
            'plaintes' => $plaintes,
            'desinscriptions' => 0,
            'reponses' => $reponses,
            'reponses_interessees' => 0,
        ];
    }
}
