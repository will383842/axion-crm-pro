<?php

/**
 * VERDICTS-CODE-1 (ajout du pilotage, relecture #610) — migration 2026_12_05_000510 : le rôle applicatif
 * n'a plus UPDATE ni DELETE sur `envoi_verdicts_adresse` ; INSERT et SELECT restent permis.
 *
 * Pas de `RefreshDatabase` : `pgsql_app` est une AUTRE session Postgres. Nettoyage par le propriétaire
 * dans un `finally`. Fixtures FICTIVES.
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

test('sous pgsql_app : INSERT passe, UPDATE et DELETE sont refusés', function () {
    $o = DB::connection('pgsql_owner');
    $ws = (string) Str::uuid();
    $o->table('workspaces')->insert([
        'id' => $ws, 'slug' => 'zz-vrv-' . substr($ws, 0, 8), 'name' => 'ZZ verdicts revoke', 'settings' => '{}',
        'cost_cap_eur' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $empreinte = hash('sha256', 'zz@societe-a.example.test');

    try {
        config(['database.default' => 'pgsql_app']);
        DB::purge('pgsql_app');
        try {
            DB::select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $ws]);
            DB::insert('INSERT INTO envoi_verdicts_adresse (workspace_id, empreinte_adresse, verdict, verifie_le, source, motif)
                VALUES (?, ?, ?, now(), ?, ?)', [$ws, $empreinte, 'valide', 'test', 'mx']);
            expect((int) DB::selectOne('SELECT count(*) AS n FROM envoi_verdicts_adresse')->n)->toBe(1);

            foreach ([
                "UPDATE envoi_verdicts_adresse SET verdict = 'invalide' WHERE empreinte_adresse = ?",
                'DELETE FROM envoi_verdicts_adresse WHERE empreinte_adresse = ?',
            ] as $sql) {
                try {
                    DB::statement($sql, [$empreinte]);
                    $refuse = false;
                } catch (QueryException $e) {
                    $refuse = str_contains($e->getMessage(), 'permission denied');
                }
                expect($refuse)->toBeTrue($sql);
            }
        } finally {
            config(['database.default' => 'pgsql']);
            DB::purge('pgsql_app');
        }
        expect($o->table('envoi_verdicts_adresse')->where('workspace_id', $ws)->where('verdict', 'valide')->count())->toBe(1);
    } finally {
        $o->table('envoi_verdicts_adresse')->where('workspace_id', $ws)->delete();
        $o->table('workspaces')->where('id', $ws)->delete();
        $o->disconnect();
        DB::connection('pgsql_app')->disconnect();
    }
});

test('up() de 000510 est rejouable', function () {
    $migration = require database_path('migrations/2026_12_05_000510_envoi_verdicts_adresse_revoke_ud.php');
    $migration->up();
    $migration->up();
    $droits = DB::selectOne(
        "SELECT has_table_privilege(?, 'public.envoi_verdicts_adresse', 'UPDATE') AS u,
                has_table_privilege(?, 'public.envoi_verdicts_adresse', 'DELETE') AS d,
                has_table_privilege(?, 'public.envoi_verdicts_adresse', 'INSERT') AS i",
        [config('database.connections.pgsql_app.username'), config('database.connections.pgsql_app.username'), config('database.connections.pgsql_app.username')],
    );
    expect($droits->u)->toBeFalse()->and($droits->d)->toBeFalse()->and($droits->i)->toBeTrue();
});
