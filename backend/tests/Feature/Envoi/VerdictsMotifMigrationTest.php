<?php

/**
 * VERDICTS-MIGRATION — colonne `motif` des verdicts d'adresse et droits du cache DNS.
 *
 * M1 colonne nullable ; M2 liste fermée de 10 motifs ; M3 `up()` rejouable ;
 * M4 rôle applicatif : `email_domaines` (INSERT + ON CONFLICT DO UPDATE) autorisé,
 * INSERT d'un verdict avec motif sous RLS.
 *
 * Pas de `RefreshDatabase` : `pgsql_app` est une AUTRE session Postgres. Le jeu d'essai
 * est validé puis nettoyé par le propriétaire dans un `finally`. Fixtures FICTIVES.
 */

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

const VMM_MOTIFS = ['syntaxe', 'jetable', 'rebond_dur', 'role_technique', 'generique',
    'mx', 'a', 'mx_nul', 'sans_courrier', 'inexistant'];

function vmmEspace(): string
{
    $ws = (string) Str::uuid();
    DB::connection('pgsql_owner')->table('workspaces')->insert([
        'id' => $ws, 'slug' => 'zz-vmm-' . substr($ws, 0, 8), 'name' => 'ZZ verdicts motif', 'settings' => '{}',
        'cost_cap_eur' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $ws;
}

function vmmNettoyer(string $ws): void
{
    $o = DB::connection('pgsql_owner');
    $o->table('envoi_verdicts_adresse')->where('workspace_id', $ws)->delete();
    $o->table('workspaces')->where('id', $ws)->delete();
    $o->disconnect();
    DB::connection('pgsql_app')->disconnect();
}

function vmmLigne(string $ws, ?string $motif, string $verdict = 'valide'): array
{
    return [
        'workspace_id' => $ws, 'empreinte_adresse' => hash('sha256', 'zz@societe-a.example.test'),
        'verdict' => $verdict, 'verifie_le' => now(), 'source' => 'test', 'motif' => $motif,
    ];
}

test('M1 la colonne motif existe, est nullable, et une ligne sans motif s’insère', function () {
    $col = DB::selectOne(
        "SELECT is_nullable, data_type FROM information_schema.columns
         WHERE table_name = 'envoi_verdicts_adresse' AND column_name = 'motif'",
    );
    expect($col)->not->toBeNull()
        ->and($col->is_nullable)->toBe('YES')
        ->and($col->data_type)->toBe('text');

    $ws = vmmEspace();
    try {
        $o = DB::connection('pgsql_owner');
        $ligne = vmmLigne($ws, null);
        unset($ligne['motif']);
        $o->table('envoi_verdicts_adresse')->insert($ligne);
        expect($o->table('envoi_verdicts_adresse')->where('workspace_id', $ws)->whereNull('motif')->count())->toBe(1);
    } finally {
        vmmNettoyer($ws);
    }
});

test('M2 un motif hors liste est refusé, les 10 motifs passent', function () {
    $ws = vmmEspace();
    try {
        $o = DB::connection('pgsql_owner');
        foreach (VMM_MOTIFS as $motif) {
            $o->table('envoi_verdicts_adresse')->insert(vmmLigne($ws, $motif));
        }
        expect($o->table('envoi_verdicts_adresse')->where('workspace_id', $ws)->count())->toBe(10);

        expect(fn () => $o->table('envoi_verdicts_adresse')->insert(vmmLigne($ws, 'inconnu_du_cahier')))
            ->toThrow(QueryException::class);
        expect($o->table('envoi_verdicts_adresse')->where('workspace_id', $ws)->count())->toBe(10);
    } finally {
        vmmNettoyer($ws);
    }
});

test('M3 up() joué deux fois ne lève pas', function () {
    $migration = require database_path('migrations/2026_12_05_000500_envoi_verdicts_adresse_motif.php');
    $migration->up();
    $migration->up();

    $n = DB::selectOne(
        "SELECT count(*) AS n FROM pg_constraint WHERE conname = 'envoi_verdicts_adresse_motif_ck'",
    );
    expect((int) $n->n)->toBe(1);
});

test('M4 sous pgsql_app : cache DNS inscriptible, verdict avec motif inséré sous RLS', function () {
    $ws = vmmEspace();
    $domaine = 'zz-vmm-' . substr($ws, 0, 8) . '.example.test';
    $o = DB::connection('pgsql_owner');
    $o->table('envoi_verdicts_adresse')->insert(vmmLigne($ws, 'mx'));

    try {
        config(['database.default' => 'pgsql_app']);
        DB::purge('pgsql_app');
        try {
            $role = DB::selectOne('SELECT (SELECT rolbypassrls FROM pg_roles WHERE rolname = current_user) AS b');
            expect($role->b)->toBeFalse();

            DB::insert(
                'INSERT INTO email_domaines (domaine, verdict, mx, resolveur, resolu_le) VALUES (?, ?, ?, ?, now())',
                [$domaine, 'mx', 'mx.example.test', 'test'],
            );
            DB::insert(
                'INSERT INTO email_domaines (domaine, verdict, mx, resolveur, resolu_le) VALUES (?, ?, ?, ?, now())
                ON CONFLICT (domaine) DO UPDATE SET verdict = EXCLUDED.verdict, mx = EXCLUDED.mx,
                resolveur = EXCLUDED.resolveur, resolu_le = EXCLUDED.resolu_le',
                [$domaine, 'inexistant', null, 'test'],
            );

            // Table des verdicts : le rôle applicatif lit et insère (RLS : espace posé), avec le motif.
            // NB : le cahier annonce « UPDATE refusé » ; FAUX sur le schéma réel — les privilèges par
            // défaut de 2026_08_14_000001 donnent déjà SELECT/INSERT/UPDATE/DELETE. Voir la PR (ESCALADE).
            DB::select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $ws]);
            DB::insert('INSERT INTO envoi_verdicts_adresse (workspace_id, empreinte_adresse, verdict, verifie_le, source, motif)
                VALUES (?, ?, ?, now(), ?, ?)', [$ws, hash('sha256', 'zz2@societe-a.example.test'), 'invalide', 'test', 'syntaxe']);
            expect(DB::selectOne('SELECT count(*) AS n FROM envoi_verdicts_adresse WHERE motif = ?', ['syntaxe'])->n)->toBe(1);
        } finally {
            config(['database.default' => 'pgsql']);
            DB::purge('pgsql_app');
        }

        expect($o->table('email_domaines')->where('domaine', $domaine)->value('verdict'))->toBe('inexistant')
            ->and($o->table('envoi_verdicts_adresse')->where('workspace_id', $ws)->count())->toBe(2);
    } finally {
        $o->table('email_domaines')->where('domaine', $domaine)->delete();
        vmmNettoyer($ws);
    }
});
