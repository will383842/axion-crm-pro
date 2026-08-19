<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Correctif de latence des COMPTEURS du hub de contacts (`GET
 * /api/v1/crm/contacts-hub/counts`) — étape 1a, première pièce.
 *
 * La requête du contrôleur est :
 *
 *   SELECT relation_type, lifecycle_stage, count(*)
 *     FROM companies
 *    WHERE workspace_id = ? AND deleted_at IS NULL
 *    GROUP BY relation_type, lifecycle_stage
 *
 * Aucun index ne la sert : PostgreSQL balaye la table ENTIÈRE. Ce n'est pas une
 * hypothèse, c'est mesuré (`_REPORTS/2026-08-18_MESURE-PERFORMANCE-REFERENCE.md`
 * §3-§4, rejoué le 2026-08-19 sur `axion_crm_perf`, 300 000 fiches) :
 *
 *   -- 300 000 fiches
 *   Parallel Seq Scan on companies      Buffers: shared hit=378 read=9830
 *   Execution Time: 337 à 476 ms
 *
 *   -- 2 800 000 fiches (jeu `seed_volume_production_4m.sql`)
 *   Parallel Seq Scan on companies      Buffers: shared hit=1199 read=78639
 *   Execution Time: 17 504 ms (cache froid) · 2 416 à 2 949 ms (cache chaud)
 *
 * La production porte **4,29 M de fiches dans le même workspace**, soit encore
 * 1,5 fois ce volume — à chaque rendu de la navigation, et ça grandit avec la
 * collecte. Détail : `_REPORTS/2026-08-19_MESURE-COMPTEURS-HUB.md`.
 *
 * Après l'index, sur le même jeu de 2 800 000 :
 *
 *   Parallel Index Only Scan using idx_companies_ws_counts
 *     Heap Fetches: 0                   Buffers: shared hit=2481
 *   Execution Time: 648 à 972 ms        (÷ 32 en tampons, ÷ 24 en temps à froid)
 *
 * La raison tient en deux nombres : le tas de `companies` pèse **624 Mo** à ce
 * volume, cet index en pèse **20**.
 *
 * ⚠️ L'index NE SUFFIT PAS À LUI SEUL — c'est la mesure qui l'a montré, pas le
 * plan initial. Un peu moins d'une seconde à chaque rendu de navigation reste
 * inacceptable ; le correctif principal est le **cache court** de
 * `App\Crm\Console\CompteursHub`, et cet index en est le PLANCHER : il borne
 * ce que coûte le remplissage du cache (17,5 s sans lui).
 *
 * Pose mesurée : `CREATE INDEX CONCURRENTLY` sur 2 800 000 lignes = **13 s**,
 * sans verrou d'écriture. Compter ~20 s sur les 4,29 M de la production.
 *
 * L'index ci-dessous porte EXACTEMENT les trois colonnes de la requête, dans
 * l'ordre `workspace_id` (l'égalité) puis les deux colonnes de regroupement :
 *
 *   - `workspace_id` en tête → la borne du workspace devient une recherche,
 *     plus un filtre ligne à ligne ;
 *   - `relation_type, lifecycle_stage` ensuite → les lignes arrivent DÉJÀ
 *     triées, l'agrégation se fait au fil de l'eau, sans tri ni table de
 *     hachage ;
 *   - aucune autre colonne n'est lue par la requête → **Index Only Scan** avec
 *     `Heap Fetches: 0` : PostgreSQL ne touche pas le tas DU TOUT. L'index ne
 *     porte que trois colonnes étroites là où le tas porte cinquante colonnes,
 *     dont du `jsonb` et de la géométrie — mesuré : 20 Mo contre 624 Mo.
 *
 * Partiel sur `deleted_at IS NULL` : les compteurs ne comptent jamais la
 * corbeille, donc les lignes supprimées n'ont pas à peser dans l'index.
 *
 * ── Pourquoi pas une table de compteurs entretenue par déclencheur ──────────
 * C'est la solution qui donne le meilleur temps de lecture (O(1)), et c'est
 * pour cela qu'elle est TENTANTE. Elle est refusée ici : la collecte insère des
 * fiches par centaines de milliers, et un déclencheur ferait converger toutes
 * ces écritures sur les quelques dizaines de lignes de la table de compteurs —
 * contention de verrou et amplification d'écriture sur le chemin le plus chaud
 * du produit, pour un chiffre décoratif rafraîchi toutes les cinq minutes.
 * L'index + le cache court (`App\Crm\Console\CompteursHub`) tiennent le même
 * budget sans toucher au chemin d'écriture.
 *
 * Additif et réversible : aucune donnée touchée, `down()` rend l'état d'avant.
 * `CONCURRENTLY` : la production ne peut pas s'offrir un verrou d'écriture sur
 * `companies` le temps de bâtir un index de 4,29 M de lignes.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'idx_companies_ws_counts';

    private const SQL = 'CREATE INDEX CONCURRENTLY IF NOT EXISTS ' . self::INDEX . '
        ON companies (workspace_id, relation_type, lifecycle_stage)
        WHERE deleted_at IS NULL';

    public function up(): void
    {
        // Un CONCURRENTLY interrompu laisse un index `indisvalid = false` que
        // `IF NOT EXISTS` tient pourtant pour existant : sans ce ménage, une
        // migration rejouée « réussirait » en laissant un index inutilisable.
        // (Idiome posé par `2026_08_15_000002` / `2026_08_17_000001`, repris
        // à l'identique — on étend, on ne réinvente pas.)
        $invalid = DB::select(
            'SELECT c.relname AS name
               FROM pg_class c
               JOIN pg_index i ON i.indexrelid = c.oid
              WHERE c.relname = ?
                AND NOT i.indisvalid',
            [self::INDEX],
        );

        foreach ($invalid as $row) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . $row->name);
        }

        DB::statement(self::SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . self::INDEX);
    }
};
