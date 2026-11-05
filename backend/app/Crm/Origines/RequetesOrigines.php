<?php

namespace App\Crm\Origines;

use App\Crm\Ingest\ContenusAccords;
use App\Support\MasquageCoordonnees;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQL unique de LECTURE des origines (L2-back). Lecture seule : aucun
 * INSERT/UPDATE/DELETE. Toutes les valeurs passent par des BINDINGS, jamais
 * par concaténation. Les entrées mises à l'écart (`masque_le`) sont exclues
 * (règle ADR § 7) ; l'export art. 15 (autre chemin) les inclut.
 *
 * Source des entrées : `personnes.origines` (fiche, ≤ 50) UNION ALL
 * `personne_origines_archive` (au-delà). Le cloisonnement est filtré
 * explicitement (`workspace_id`) ET garanti par la RLS.
 *
 * @phpstan-type Filtres array{famille: ?string, detail: ?string, reseau: ?string, mot: ?string, oppose: string, du: ?string, au: ?string}
 */
final class RequetesOrigines
{
    /** Jeton de réseau, de mot (même forme que `ContenusAccords`). */
    public const JETON = '/^[a-z0-9][a-z0-9._-]{0,63}$/';

    /** @return Filtres */
    public static function filtresVides(): array
    {
        return ['famille' => null, 'detail' => null, 'reseau' => null, 'mot' => null, 'oppose' => 'tous', 'du' => null, 'au' => null];
    }

    /**
     * Entrées visibles à l'écran d'un espace (fiche + archive), une ligne par entrée.
     *
     * @param  list<int>|null  $personnes  restreint à ces personnes
     * @return array{0: string, 1: list<mixed>}
     */
    private static function entrees(string $espace, ?array $personnes = null): array
    {
        $opposition = Schema::hasColumn('personnes', 'opposition_at') ? 'p.opposition_at' : 'NULL::timestamptz';
        $accord = Schema::hasColumn('personnes', 'consentements')
            ? "(jsonb_typeof(p.consentements) = 'array' AND jsonb_array_length(p.consentements) > 0)"
            : 'false';
        $ids = $personnes === null ? '' : ' AND p.id = ANY(?::bigint[])';
        $liste = $personnes === null ? null : '{' . implode(',', array_map('intval', $personnes)) . '}';

        $sql = "SELECT p.id AS personne_id, e.v AS o, {$opposition} AS opposition_at, {$accord} AS a_accord
                  FROM personnes p
                  CROSS JOIN LATERAL jsonb_array_elements(CASE WHEN jsonb_typeof(p.origines) = 'array' THEN p.origines ELSE '[]'::jsonb END) AS e(v)
                 WHERE p.workspace_id = ?::uuid AND p.origines IS NOT NULL AND (e.v -> 'masque_le') IS NULL{$ids}
                UNION ALL
                SELECT a.personne_id, a.origine, {$opposition}, {$accord}
                  FROM personne_origines_archive a
                  JOIN personnes p ON p.id = a.personne_id AND p.workspace_id = a.workspace_id
                 WHERE a.workspace_id = ?::uuid AND a.masque_le IS NULL AND (a.origine -> 'masque_le') IS NULL" . str_replace('p.id', 'a.personne_id', $ids);

        $bindings = $personnes === null ? [$espace, $espace] : [$espace, $liste, $espace, $liste];

        return [$sql, $bindings];
    }

    /**
     * @param  Filtres  $f
     * @return array{0: string, 1: list<mixed>}
     */
    private static function conditions(array $f): array
    {
        $sql = [];
        $b = [];
        foreach (['famille', 'detail', 'reseau', 'mot'] as $cle) {
            if ($f[$cle] !== null) {
                $sql[] = "x.o ->> '{$cle}' = ?";
                $b[] = $f[$cle];
            }
        }
        if ($f['du'] !== null) {
            $sql[] = "substr(x.o ->> 'date', 1, 10) >= ?";
            $b[] = $f['du'];
        }
        if ($f['au'] !== null) {
            $sql[] = "substr(x.o ->> 'date', 1, 10) <= ?";
            $b[] = $f['au'];
        }
        if ($f['oppose'] === 'oui') {
            $sql[] = 'x.opposition_at IS NOT NULL';
        } elseif ($f['oppose'] === 'non') {
            $sql[] = 'x.opposition_at IS NULL';
        }

        return [$sql === [] ? 'true' : implode(' AND ', $sql), $b];
    }

    /**
     * @param  Filtres  $f
     * @return array{personnes: int, demandes: int, accords: int, opposees: int, par_famille: list<array{famille: ?string, demandes: int, personnes: int}>}
     */
    public static function apercu(string $espace, array $f): array
    {
        [$entrees, $be] = self::entrees($espace);
        [$cond, $bc] = self::conditions($f);

        $t = DB::selectOne(
            "WITH x AS ({$entrees}) SELECT count(*) AS demandes, count(DISTINCT x.personne_id) AS personnes,
                    count(DISTINCT x.personne_id) FILTER (WHERE x.opposition_at IS NOT NULL) AS opposees,
                    count(DISTINCT x.personne_id) FILTER (WHERE x.a_accord) AS accords
               FROM x WHERE {$cond}",
            array_merge($be, $bc),
        );
        $familles = DB::select(
            "WITH x AS ({$entrees}) SELECT x.o ->> 'famille' AS famille, count(*) AS demandes, count(DISTINCT x.personne_id) AS personnes
               FROM x WHERE {$cond} GROUP BY 1 ORDER BY demandes DESC, famille",
            array_merge($be, $bc),
        );

        return [
            'personnes' => (int) ($t->personnes ?? 0),
            'demandes' => (int) ($t->demandes ?? 0),
            'accords' => (int) ($t->accords ?? 0),
            'opposees' => (int) ($t->opposees ?? 0),
            'par_famille' => array_values(array_map(static fn ($r): array => ['famille' => $r->famille, 'demandes' => (int) $r->demandes, 'personnes' => (int) $r->personnes], $familles)),
        ];
    }

    /**
     * @param  Filtres  $f
     * @return list<array{reseau: ?string, canal: ?string, compte: ?string, demandes: int, personnes: int}>
     */
    public static function parReseau(string $espace, array $f): array
    {
        [$entrees, $be] = self::entrees($espace);
        [$cond, $bc] = self::conditions($f);
        $rows = DB::select(
            "WITH x AS ({$entrees}) SELECT x.o ->> 'reseau' AS reseau, x.o ->> 'canal' AS canal, x.o ->> 'compte' AS compte,
                    count(*) AS demandes, count(DISTINCT x.personne_id) AS personnes
               FROM x WHERE {$cond} GROUP BY 1, 2, 3 ORDER BY demandes DESC, reseau, canal, compte",
            array_merge($be, $bc),
        );

        return array_values(array_map(static fn ($r): array => ['reseau' => $r->reseau, 'canal' => $r->canal, 'compte' => $r->compte, 'demandes' => (int) $r->demandes, 'personnes' => (int) $r->personnes], $rows));
    }

    /**
     * @param  Filtres  $f
     * @return array{data: list<array{mot: ?string, famille: ?string, detail: ?string, demandes: int, personnes: int}>, total: int}
     */
    public static function parMot(string $espace, array $f, int $page, int $parPage): array
    {
        [$entrees, $be] = self::entrees($espace);
        [$cond, $bc] = self::conditions($f);
        $b = array_merge($be, $bc);
        $groupe = "SELECT x.o ->> 'mot' AS mot, x.o ->> 'famille' AS famille, x.o ->> 'detail' AS detail, count(*) AS demandes, count(DISTINCT x.personne_id) AS personnes
                     FROM x WHERE {$cond} GROUP BY 1, 2, 3";
        $total = (int) (DB::selectOne("WITH x AS ({$entrees}), g AS ({$groupe}) SELECT count(*) AS n FROM g", $b)->n ?? 0);
        $rows = DB::select(
            "WITH x AS ({$entrees}), g AS ({$groupe}) SELECT * FROM g ORDER BY demandes DESC, mot, famille, detail LIMIT ? OFFSET ?",
            array_merge($b, [$parPage, max(0, ($page - 1) * $parPage)]),
        );

        return [
            'data' => array_values(array_map(static fn ($r): array => ['mot' => $r->mot, 'famille' => $r->famille, 'detail' => $r->detail, 'demandes' => (int) $r->demandes, 'personnes' => (int) $r->personnes], $rows)),
            'total' => $total,
        ];
    }

    /**
     * Série quotidienne : EXACTEMENT `$jours` points, jours vides à 0.
     *
     * @param  Filtres  $f
     * @return list<array{jour: string, demandes: int}>
     */
    public static function tendance(string $espace, array $f, int $jours): array
    {
        [$entrees, $be] = self::entrees($espace);
        [$cond, $bc] = self::conditions($f);
        // UTC, comme les dates enregistrées : pas de décalage à minuit (fuseau de l'application = Paris).
        $fin = now('UTC')->toDateString();
        $debut = now('UTC')->subDays($jours - 1)->toDateString();
        $rows = DB::select(
            "WITH x AS ({$entrees}),
                  c AS (SELECT substr(x.o ->> 'date', 1, 10) AS j, count(*) AS n FROM x WHERE {$cond} GROUP BY 1)
             SELECT to_char(d::date, 'YYYY-MM-DD') AS jour, COALESCE(c.n, 0) AS demandes
               FROM generate_series(?::date, ?::date, interval '1 day') AS d
               LEFT JOIN c ON c.j = to_char(d::date, 'YYYY-MM-DD')
              ORDER BY 1",
            array_merge($be, $bc, [$debut, $fin]),
        );

        return array_values(array_map(static fn ($r): array => ['jour' => (string) $r->jour, 'demandes' => (int) $r->demandes], $rows));
    }

    /**
     * Condition « la personne a au moins une entrée visible qui correspond », pour
     * brancher la liste nominative sur `PersonnesController::requete()`.
     *
     * @param  Filtres  $f
     * @return array{0: string, 1: list<mixed>}
     */
    public static function conditionPersonnes(string $espace, array $f): array
    {
        [$entrees, $be] = self::entrees($espace);
        [$cond, $bc] = self::conditions($f);

        return ["personnes.id IN (WITH x AS ({$entrees}) SELECT x.personne_id FROM x WHERE {$cond})", array_merge($be, $bc)];
    }

    /**
     * Entrées (filtrées) de ces personnes, présentées par liste blanche, de la plus récente à la plus ancienne.
     *
     * @param  list<int>  $personnes
     * @param  Filtres  $f
     * @return array<int, list<array<string, ?string>>>
     */
    public static function entreesDesPersonnes(string $espace, array $personnes, array $f): array
    {
        if ($personnes === []) {
            return [];
        }
        [$entrees, $be] = self::entrees($espace, $personnes);
        [$cond, $bc] = self::conditions($f);
        $rows = DB::select(
            "WITH x AS ({$entrees}) SELECT x.personne_id, x.o FROM x WHERE {$cond} ORDER BY (x.o ->> 'date') COLLATE \"C\" DESC NULLS LAST, md5(x.o::text)",
            array_merge($be, $bc),
        );
        $sortie = [];
        foreach ($rows as $r) {
            $o = json_decode((string) $r->o, true);
            if (is_array($o)) {
                $sortie[(int) $r->personne_id][] = ContenusAccords::presenterOrigine($o);
            }
        }

        return $sortie;
    }

    /** Longueur maximale (octets) d'une date dans un curseur ; le contrôleur borne le curseur en conséquence. */
    public const DATE_MAX = 255;

    /**
     * Curseur opaque de l'historique : (date, rang) du DERNIER élément rendu.
     * Le rang est unique par personne : `archive_id` (positif) pour l'archive,
     * `-position` (négatif) pour la fiche. Deux entrées identiques ont donc
     * deux rangs différents : le tri est total et la page suivante ne perd ni
     * ne double aucune entrée.
     */
    public static function encoderCurseur(string $date, int $rang): string
    {
        // JSON : toute date (longueur, octets de contrôle, UTF-8 invalide compris) reste encodable.
        $json = json_encode([$date, $rang], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);

        return rtrim(strtr(base64_encode($json === false ? '[\"\",0]' : $json), '+/', '-_'), '=');
    }

    /** @return array{0: string, 1: int}|null null si le curseur est illisible */
    public static function decoderCurseur(string $curseur): ?array
    {
        $brut = base64_decode(strtr($curseur, '-_', '+/'), true);
        if ($brut === false) {
            return null;
        }
        $v = json_decode($brut, true);
        if (! is_array($v) || count($v) !== 2 || ! array_is_list($v) || ! is_string($v[0]) || strlen($v[0]) > self::DATE_MAX || ! is_int($v[1])) {
            return null;
        }

        return [$v[0], $v[1]];
    }

    /**
     * Historique COMPLET d'une personne (fiche + archive), du plus récent au plus ancien,
     * paginé par curseur (pas de OFFSET).
     *
     * @param  array{0: string, 1: int}|null  $apres  curseur décodé
     * @return array{total: int, data: list<array<string, ?string>>, suivant: ?string}
     */
    public static function historique(string $espace, int $personneId, ?array $apres, int $limite): array
    {
        $source = "SELECT e.v AS o, COALESCE(e.v ->> 'date', '') AS d, (-e.n)::bigint AS c
                     FROM personnes p
                     CROSS JOIN LATERAL jsonb_array_elements(CASE WHEN jsonb_typeof(p.origines) = 'array' THEN p.origines ELSE '[]'::jsonb END) WITH ORDINALITY AS e(v, n)
                    WHERE p.workspace_id = ?::uuid AND p.id = ? AND p.origines IS NOT NULL AND (e.v -> 'masque_le') IS NULL
                   UNION ALL
                   SELECT a.origine, COALESCE(a.origine ->> 'date', ''), a.id::bigint
                     FROM personne_origines_archive a
                    WHERE a.workspace_id = ?::uuid AND a.personne_id = ? AND a.masque_le IS NULL AND (a.origine -> 'masque_le') IS NULL";
        $b = [$espace, $personneId, $espace, $personneId];

        $total = (int) (DB::selectOne("WITH h AS ({$source}) SELECT count(*) AS n FROM h", $b)->n ?? 0);

        $filtre = '';
        $bp = $b;
        if ($apres !== null) {
            $filtre = ' WHERE (h.d COLLATE "C", h.c) < (?::text COLLATE "C", ?::bigint)';
            $bp = array_merge($b, [$apres[0], $apres[1]]);
        }
        $rows = DB::select(
            "WITH h AS ({$source}) SELECT h.o, h.d, h.c FROM h{$filtre} ORDER BY h.d COLLATE \"C\" DESC, h.c DESC LIMIT ?",
            array_merge($bp, [$limite + 1]),
        );

        $plus = count($rows) > $limite;
        $rows = array_slice($rows, 0, $limite);
        $data = [];
        foreach ($rows as $r) {
            $o = json_decode((string) $r->o, true);
            if (is_array($o)) {
                $data[] = ContenusAccords::presenterOrigine($o, ! MasquageCoordonnees::requis());
            }
        }
        $dernier = $rows === [] ? null : $rows[count($rows) - 1];

        return [
            'total' => $total,
            'data' => $data,
            'suivant' => ($plus && $dernier !== null) ? self::encoderCurseur((string) $dernier->d, (int) $dernier->c) : null,
        ];
    }
}
