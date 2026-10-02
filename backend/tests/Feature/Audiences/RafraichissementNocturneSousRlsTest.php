<?php

use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * LE RAFRAÎCHISSEMENT NOCTURNE DES AUDIENCES, REJOUÉ SOUS LE RÔLE DE
 * PRODUCTION (lot 3, 2026-10-02).
 *
 * Constat : « Prospects contactables 676 » et « Dernier rafraîchissement :
 * jamais ». `audiences:full-refresh` tourne chaque nuit à 04:00 et sort en
 * succès — mais lisait `email_audiences` (RLS forcée) SANS contexte d'espace,
 * sous `axion_app` : zéro audience vue, zéro rafraîchie. Les trois audiences
 * de production portaient `refreshed_at = NULL` depuis leur création.
 *
 * Ce test joue la commande sur la connexion `pgsql_app` (le rôle de
 * production) ; données semées et nettoyées par le propriétaire. Sans le
 * correctif, `refreshed_at` reste NULL et `member_count` à 0.
 *
 * Fixtures FICTIVES (dépôt public).
 */
function l3AudProprio(): Connection
{
    return DB::connection('pgsql_owner');
}

afterEach(function () {
    DB::connection('pgsql_app')->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    DB::connection('pgsql_app')->disconnect();
    l3AudProprio()->disconnect();
});

test('sous axion_app, la commande nocturne rafraichit vraiment les audiences de chaque espace', function () {
    $owner = l3AudProprio();
    $espace = (string) Str::uuid();
    $owner->table('workspaces')->insert([
        'id' => $espace, 'slug' => 'zz-aud-rls-' . substr(str_replace('-', '', $espace), 0, 8), 'name' => 'ZZ audiences RLS',
        'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    try {
        foreach ([1, 2] as $i) {
            $owner->table('companies')->insert([
                'workspace_id' => $espace,
                'siren' => '97' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
                'denomination' => 'ZZ AUDIENCE RLS ' . $i, 'region_code' => '84', 'postcode' => '38000',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $audience = (int) $owner->table('email_audiences')->insertGetId([
            'workspace_id' => $espace, 'name' => 'ZZ prospects RLS',
            'criteria' => json_encode(['all' => [['field' => 'region_code', 'op' => 'eq', 'value' => '84']]]),
            'is_active' => true, 'auto_refresh' => true, 'member_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $precedente = DB::getDefaultConnection();
        try {
            DB::setDefaultConnection('pgsql_app');
            $code = Artisan::call('audiences:full-refresh', ['--workspace' => $espace]);
        } finally {
            DB::setDefaultConnection($precedente);
        }

        $ligne = $owner->table('email_audiences')->where('id', $audience)->first();
        expect($code)->toBe(0)
            ->and($ligne->refreshed_at)->not->toBeNull()
            ->and((int) $ligne->member_count)->toBe(2)
            ->and($owner->table('audience_members')->where('audience_id', $audience)->count())->toBe(2);
    } finally {
        $owner->table('audience_members')->where('workspace_id', $espace)->delete();
        $owner->table('email_audiences')->where('workspace_id', $espace)->delete();
        $owner->table('business_events')->where('workspace_id', $espace)->delete();
        $owner->table('companies')->where('workspace_id', $espace)->delete();
        $owner->table('workspaces')->where('id', $espace)->delete();
    }
});
