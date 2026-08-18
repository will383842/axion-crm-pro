<?php

/**
 * GARDE n°1 — aucune table d'extension ne doit se trouver sur le chemin du
 * `DROP TABLE … CASCADE` global qu'émettent `migrate:fresh` et `RefreshDatabase`.
 *
 * Panne historique (reproduite le 2026-08-18, cf.
 * `_REPORTS/2026-08-18_RECONSTRUCTION-BASE.md`) :
 *
 *     Dropping all tables ................................... 1 s FAIL
 *     SQLSTATE[2BP01]: Dependent objects still exist: 7 ERROR:
 *       cannot drop table part_config because extension pg_partman requires it
 *     HINT:  You can drop extension pg_partman instead.
 *
 * Mécanique : `Illuminate\Database\Schema\PostgresBuilder::dropAllTables()`
 * énumère les tables des schémas du `search_path`, retire celles listées dans
 * la clé de connexion `dont_drop` (par défaut `['spatial_ref_sys']`), et émet
 * UN SEUL `DROP TABLE … CASCADE` pour tout le reste. `pg_partman` était installé
 * dans `public` : ses tables internes `part_config` / `part_config_sub` entraient
 * dans le lot, PostgreSQL refusait la commande ENTIÈRE, et donc AUCUNE migration
 * ne s'exécutait — sur toute base déjà migrée, c'est-à-dire à partir de la
 * deuxième reconstruction.
 *
 * Ce test ne dépend PAS de `RefreshDatabase` : c'est justement `RefreshDatabase`
 * qui mourait. Il se contente d'interroger le catalogue, après s'être assuré que
 * les migrations sont passées.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it("n'expose aucune table d'extension au DROP TABLE global de migrate:fresh", function () {
    // Garantit que le schéma existe et que les extensions ont été créées, sans
    // quoi le test serait VERT À VIDE sur une base non migrée.
    Artisan::call('migrate', ['--force' => true]);

    $partmanInstalle = DB::selectOne(
        "SELECT extversion, (SELECT nspname FROM pg_namespace WHERE oid = extnamespace) AS schema
         FROM pg_extension WHERE extname = 'pg_partman'",
    );

    expect($partmanInstalle)->not->toBeNull(
        "pg_partman n'est pas installé : ce test ne garde alors plus rien. "
        . "L'image attendue est ghcr.io/will383842/axion-crm-pro-postgres:16-3.5-vector-partman.",
    );

    $lignes = DB::select(<<<'SQL'
        SELECT n.nspname AS schema_extension,
               c.relname AS table_extension,
               e.extname AS extension
        FROM pg_depend d
        JOIN pg_class c
          ON c.oid = d.objid
         AND d.classid = 'pg_class'::regclass
        JOIN pg_namespace n ON n.oid = c.relnamespace
        JOIN pg_extension e
          ON e.oid = d.refobjid
         AND d.refclassid = 'pg_extension'::regclass
        WHERE d.deptype = 'e'
          AND c.relkind IN ('r', 'p')
          AND n.nspname = ANY (current_schemas(false))
        ORDER BY 1, 2
    SQL);

    // Même liste d'exclusion que PostgresBuilder::dropAllTables().
    $exclues = Schema::getConnection()->getConfig('dont_drop') ?? ['spatial_ref_sys'];

    $bloquantes = [];
    foreach ($lignes as $ligne) {
        if (in_array($ligne->table_extension, $exclues, true)) {
            continue;
        }
        $bloquantes[] = $ligne->schema_extension . '.' . $ligne->table_extension
            . ' (extension ' . $ligne->extension . ')';
    }

    expect($bloquantes)->toBe([], implode("\n", [
        'LA RECONSTRUCTION DE LA BASE EST REDEVENUE IMPOSSIBLE.',
        'Ces tables appartiennent à une extension et sont visibles dans le search_path :',
        '  - ' . implode("\n  - ", $bloquantes),
        'PostgreSQL refusera le `DROP TABLE … CASCADE` global de `migrate:fresh` /',
        '`RefreshDatabase` (SQLSTATE 2BP01) et AUCUNE migration ne tournera.',
        "Remède : installer l'extension dans son propre schéma, hors du search_path",
        '(cf. 2026_08_18_100001_partman_dans_son_propre_schema.php), ou déclarer la',
        'table dans la clé `dont_drop` de la connexion pgsql.',
    ]));
});
