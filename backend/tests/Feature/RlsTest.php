<?php

use App\Models\Company;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Vérifie que la RLS PostgreSQL isole effectivement les données par workspace_id.
 * Setup : 2 workspaces avec 1 company chacun. Set session var → confirme visibilité ciblée.
 */
test('RLS bloque cross-workspace SELECT companies', function () {
    $wsA = (string) Str::uuid();
    $wsB = (string) Str::uuid();

    DB::table('workspaces')->insert([
        ['id' => $wsA, 'slug' => 'ws-a-' . substr($wsA, 0, 6), 'name' => 'WS A', 'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['id' => $wsB, 'slug' => 'ws-b-' . substr($wsB, 0, 6), 'name' => 'WS B', 'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('companies')->insert([
        ['workspace_id' => $wsA, 'siren' => '111111111', 'denomination' => 'AAA', 'signals' => '{}', 'metadata' => '{}', 'quality_score' => 0, 'created_at' => now(), 'updated_at' => now()],
        ['workspace_id' => $wsB, 'siren' => '222222222', 'denomination' => 'BBB', 'signals' => '{}', 'metadata' => '{}', 'quality_score' => 0, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::statement("SET LOCAL app.current_workspace_id = '{$wsA}'");
    $count = DB::table('companies')->count();
    expect($count)->toBe(1);
});

test('RLS bypass quand session var vide (jobs system)', function () {
    $wsA = (string) Str::uuid();
    $wsB = (string) Str::uuid();

    DB::table('workspaces')->insert([
        ['id' => $wsA, 'slug' => 'sys-a', 'name' => 'A', 'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ['id' => $wsB, 'slug' => 'sys-b', 'name' => 'B', 'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('companies')->insert([
        ['workspace_id' => $wsA, 'siren' => '333333333', 'denomination' => 'X', 'signals' => '{}', 'metadata' => '{}', 'quality_score' => 0, 'created_at' => now(), 'updated_at' => now()],
        ['workspace_id' => $wsB, 'siren' => '444444444', 'denomination' => 'Y', 'signals' => '{}', 'metadata' => '{}', 'quality_score' => 0, 'created_at' => now(), 'updated_at' => now()],
    ]);

    // session var '' → NULLIF(...) IS NULL → policy bypass
    DB::statement("RESET app.current_workspace_id");
    $count = DB::table('companies')->count();
    expect($count)->toBeGreaterThanOrEqual(2);
});
