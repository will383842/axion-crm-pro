<?php

/**
 * L2-back — l'historique d'origines rejoué SOUS le rôle de production
 * (`axion_app`, RLS forcée) : rien d'un autre espace, même en connaissant l'id.
 */

use App\Crm\Origines\RequetesOrigines;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('OLR 1 — sous axion_app, l’historique d’une personne d’un autre espace est vide', function () {
    $owner = DB::connection('pgsql_owner');
    $ids = [];
    foreach (['a', 'b'] as $k) {
        $ws = (string) Str::uuid();
        $owner->table('workspaces')->insert(['id' => $ws, 'slug' => 'zz-olr-' . $k . substr($ws, 0, 6), 'name' => 'ZZ OLR', 'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $pid = (int) $owner->table('personnes')->insertGetId([
            'workspace_id' => $ws, 'person_key' => hash('sha256', 'olr|' . $k . $ws), 'email' => "olr.$k@example.invalid",
            'email_hash' => hash('sha256', "olr.$k"), 'email_nature' => 'pro', 'premiere_source' => 'newsletter', 'legal_basis' => 'consent',
            'origines' => json_encode([['mot' => 'guide', 'reseau' => 'instagram', 'date' => '2026-11-01T10:00:00Z', 'post_ref' => 'post_ref_olr_' . $k]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $owner->table('personne_origines_archive')->insert(['workspace_id' => $ws, 'personne_id' => $pid, 'cle' => 'k' . $k, 'origine' => json_encode(['mot' => 'a', 'date' => '2026-10-01T10:00:00Z', 'post_ref' => 'post_ref_arch_' . $k])]);
        $ids[$k] = ['ws' => $ws, 'pid' => $pid];
    }

    try {
        $defaut = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_app');
        $propre = WorkspaceContext::run($ids['a']['ws'], fn () => RequetesOrigines::historique($ids['a']['ws'], $ids['a']['pid'], 0, 50));
        $etranger = WorkspaceContext::run($ids['a']['ws'], fn () => RequetesOrigines::historique($ids['a']['ws'], $ids['b']['pid'], 0, 50));
        $agregat = WorkspaceContext::run($ids['a']['ws'], fn () => RequetesOrigines::apercu($ids['a']['ws'], RequetesOrigines::filtresVides()));
        DB::setDefaultConnection($defaut);

        expect($propre['total'])->toBe(2)
            ->and($etranger['total'])->toBe(0)
            ->and($agregat['demandes'])->toBe(2);
    } finally {
        DB::setDefaultConnection($defaut ?? 'pgsql');
        foreach ($ids as $v) {
            $owner->table('personne_origines_archive')->where('workspace_id', $v['ws'])->update(['masque_le' => now()]); // jamais de DELETE sur une donnée de personne
        }
    }
});
