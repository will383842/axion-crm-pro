<?php

use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Lot G0 — deux index partiels (corbeille des contacts, « Mes tâches »). Cahier docs/crm/lots/G1-G2-cahier.md § 2.
 * Chaque test est rejoué SOUS LE RÔLE APPLICATIF (pgsql_app : SET LOCAL ROLE + WorkspaceContext) ET SOUS LE PROPRIÉTAIRE.
 * Données fictives seulement (*.example.test) ; aucune suppression de données.
 */
uses(TestCase::class, RefreshDatabase::class);

const G0_MODES = ['app', 'proprio'];

const G0_INDEX = [
    'idx_contacts_corbeille_console' => ['contacts', "deleted_at IS NOT NULL) AND (metadata ? 'retire_console_le'::text)"],
    'idx_activities_taches' => ['activities', "(kind = 'task'::text)"],
];

/** Requête de la corbeille (cahier § 4.2), prédicat mot pour mot. */
const G0_SQL_CORBEILLE = "SELECT id FROM contacts WHERE workspace_id = ? AND deleted_at IS NOT NULL AND metadata ?? 'retire_console_le' ORDER BY deleted_at DESC, id DESC LIMIT 200";

/** Requête « Mes tâches » (cahier § 8.3). */
const G0_SQL_TACHES = "SELECT a.id, a.title, a.due_at, a.done_at, a.payload, a.subject_type, a.subject_id
    FROM activities a
    WHERE a.workspace_id = ? AND a.kind = 'task'
      AND (a.user_id = ? OR a.user_id IS NULL)
      AND (a.done_at IS NULL OR a.done_at >= now() - interval '7 days')
    ORDER BY a.due_at NULLS LAST, a.id
    LIMIT 500";

function g0Espace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'G0', 'slug' => 'g0-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function g0Sous(string $mode, string $ws, Closure $f): mixed
{
    if ($mode === 'proprio') {
        return $f();
    }
    WorkspaceContext::set($ws);
    DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    try {
        return $f();
    } finally {
        DB::statement('RESET ROLE');
    }
}

/*
 * Cause de l'instabilité (FLAKY-G0, run 44350614770) : tant que `contacts` n'a AUCUNE statistique (relpages = 0),
 * le planificateur suppose 10 pages et choisit l'index partiel. Dès qu'un autre test de la même part a laissé des
 * lignes validées puis qu'un ANALYZE (ou l'autovacuum) est passé, la table pèse 1 page et le plan le moins cher
 * devient « Limit → Sort → Index Scan idx_contacts_workspace » (cost 4.29, rows=1) : l'index de la corbeille n'est
 * plus cité. `enable_seqscan/bitmapscan = off` n'interdisent pas ce plan, car il n'utilise ni l'un ni l'autre.
 * `enable_sort = off` retire le tri : le seul moyen de servir `ORDER BY deleted_at DESC, id DESC` est alors de lire
 * l'index dans l'ordre, ce qui est exactement ce que le test doit prouver. L'index et la requête ne changent pas.
 */
function g0Plan(string $sql, array $bindings): string
{
    DB::statement('SET LOCAL enable_seqscan = off');
    DB::statement('SET LOCAL enable_bitmapscan = off');
    DB::statement('SET LOCAL enable_sort = off');
    $lignes = DB::select('EXPLAIN ' . $sql, $bindings);

    return implode("\n", array_map(fn ($l) => (string) ((array) $l)['QUERY PLAN'], $lignes));
}

test('G0 les deux index existent, sont valides et portent le bon prédicat', function () {
    foreach (G0_INDEX as $nom => [$table, $attendu]) {
        $ligne = DB::selectOne(
            'SELECT i.indisvalid AS valide, pg_get_indexdef(i.indexrelid) AS def FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?',
            [$nom],
        );
        expect($ligne)->not->toBeNull("index $nom absent");
        expect((bool) $ligne->valide)->toBeTrue();
        expect($ligne->def)->toContain("ON public.$table USING btree");
        expect($ligne->def)->toContain($attendu);
    }
    $def = DB::selectOne("SELECT pg_get_indexdef('idx_contacts_corbeille_console'::regclass) AS d")->d;
    expect($def)->toContain('workspace_id, deleted_at DESC, id DESC');
    $def = DB::selectOne("SELECT pg_get_indexdef('idx_activities_taches'::regclass) AS d")->d;
    expect($def)->toContain('(workspace_id, due_at, id)');
});

test('G0 rejouer up() est sans effet, down() puis up() recrée les index (aucune donnée touchée)', function () {
    foreach (['2026_12_08_000100_contacts_index_corbeille_console', '2026_12_08_000110_activities_index_taches'] as $fichier) {
        $m = require database_path("migrations/$fichier.php");
        $m->up();
        $m->up();
        $m->down();
        $m->down();
        $m->up();
    }
    foreach (array_keys(G0_INDEX) as $nom) {
        $valide = DB::selectOne('SELECT i.indisvalid AS v FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?', [$nom]);
        expect($valide)->not->toBeNull();
        expect((bool) $valide->v)->toBeTrue();
    }
});

test('G0 le plan de la corbeille cite idx_contacts_corbeille_console (rôle applicatif ET propriétaire)', function () {
    foreach (G0_MODES as $mode) {
        $ws = g0Espace();
        $plan = g0Sous($mode, $ws, fn () => g0Plan(G0_SQL_CORBEILLE, [$ws]));
        expect($plan)->toContain('idx_contacts_corbeille_console');
    }
});

test('G0 le plan de « Mes tâches » cite idx_activities_taches (rôle applicatif ET propriétaire)', function () {
    foreach (G0_MODES as $mode) {
        $ws = g0Espace();
        $plan = g0Sous($mode, $ws, fn () => g0Plan(G0_SQL_TACHES, [$ws, (string) Str::uuid()]));
        expect($plan)->toContain('idx_activities_taches');
    }
});
