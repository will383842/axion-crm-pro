<?php

/**
 * VERDICTS-CODE-2 — T2.9 : sous le RÔLE APPLICATIF (`pgsql_app`, RLS forcée), l'écrivain écrit dans l'espace A, visible
 * de A et invisible de B ; un insert sans contexte est refusé ; le propriétaire voit tout.
 *
 * Pas de `RefreshDatabase` : `pgsql_app` est une AUTRE session. Nettoyage par le propriétaire dans un `finally`.
 * Fixtures FICTIVES.
 */

use App\Envoi\Verdicts\EcrivainVerdicts;
use App\Envoi\Verdicts\RafraichissementVerdicts;
use App\Envoi\Verdicts\VerdictAdresse;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

test('T2.9 sous pgsql_app : écriture cloisonnée par espace, insert sans contexte refusé, propriétaire voit tout', function () {
    $o = DB::connection('pgsql_owner');
    $espaces = [];
    foreach (['a', 'b'] as $k) {
        $id = (string) Str::uuid();
        $o->table('workspaces')->insert([
            'id' => $id, 'slug' => 'zz-vra-' . $k . '-' . substr($id, 0, 8), 'name' => 'ZZ verdicts rôle ' . $k, 'settings' => '{}',
            'cost_cap_eur' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $espaces[$k] = $id;
    }
    $emp = hash('sha256', 'zoe@societe-a.example.test');
    $maintenant = new DateTimeImmutable('2026-11-16 10:00:00');

    try {
        config(['database.default' => 'pgsql_app']);
        DB::purge('pgsql_app');
        try {
            WorkspaceContext::set($espaces['a']);
            expect((new EcrivainVerdicts)->ecrire($espaces['a'], [$emp => new VerdictAdresse('valide', 'mx', $maintenant)], 'interne-v1', $maintenant))->toBe(1);
            expect((int) DB::selectOne('SELECT count(*) AS n FROM envoi_verdicts_adresse')->n)->toBe(1);

            // La requête du rafraîchissement elle-même passe sous le rôle applicatif (lectures + écriture du cache de domaines).
            $o->table('workspaces')->where('id', $espaces['a'])->update(['settings' => json_encode(['produits' => ['envoi' => 'actif']])]);
            $b = app(RafraichissementVerdicts::class)->passage($maintenant, $espaces['a']);
            expect($b['erreurs'])->toBe(0)->and($b['espaces'])->toBe(1);
            $droits = DB::selectOne("SELECT has_table_privilege(current_user, 'public.email_domaines', 'INSERT') AS i, has_table_privilege(current_user, 'public.email_domaines', 'UPDATE') AS u");
            expect($droits->i)->toBeTrue()->and($droits->u)->toBeTrue();

            WorkspaceContext::set($espaces['b']);
            expect((int) DB::selectOne('SELECT count(*) AS n FROM envoi_verdicts_adresse')->n)->toBe(0);

            WorkspaceContext::clear();
            DB::select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
            try {
                DB::insert(
                    'INSERT INTO envoi_verdicts_adresse (workspace_id, empreinte_adresse, verdict, verifie_le, source, motif) VALUES (?, ?, ?, now(), ?, ?)',
                    [$espaces['a'], $emp, 'valide', 'test', 'mx'],
                );
                $refuse = false;
            } catch (QueryException) {
                $refuse = true;
            }
            expect($refuse)->toBeTrue();
        } finally {
            WorkspaceContext::clear();
            config(['database.default' => 'pgsql']);
            DB::purge('pgsql_app');
        }
        expect($o->table('envoi_verdicts_adresse')->whereIn('workspace_id', array_values($espaces))->count())->toBe(1);
    } finally {
        $o->table('envoi_verdicts_adresse')->whereIn('workspace_id', array_values($espaces))->delete();
        $o->table('workspaces')->whereIn('id', array_values($espaces))->delete();
        $o->disconnect();
        DB::connection('pgsql_app')->disconnect();
    }
});
