<?php

/**
 * « Enrichies 24h » — constat de production du 2026-10-02 : 1 671 720.
 *
 * Le compteur lisait `updated_at` : un recalcul massif du score qualité
 * (simple modification) passait pour un enrichissement. Il lit désormais
 * `enriched_at`. Ce fichier rougit si l'on revient à `updated_at`.
 *
 * Fixtures FICTIVES (dépôt public).
 */

use App\Models\User;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function enrEspace(string $nom): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $id, 'slug' => 'zz-enr-' . substr(str_replace('-', '', $id), 0, 8), 'name' => $nom,
        'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function enrEntreprise(string $espace, array $champs = []): int
{
    return (int) DB::table('companies')->insertGetId($champs + [
        'workspace_id' => $espace,
        'siren' => '9' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'denomination' => 'ZZ ENRICHIE ' . Str::random(6),
        'created_at' => now()->subDays(10), 'updated_at' => now(),
    ]);
}

beforeEach(function () {
    Cache::flush();
    $this->espace = enrEspace('ZZ enrichies');
    $this->user = User::create([
        'id' => (string) Str::uuid(), 'email' => Str::uuid() . '@example.test', 'name' => 'Console',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $this->espace, 'first_login_completed_at' => now(),
    ]);
    $this->seed(PermissionsAndRolesSeeder::class);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->espace);
    $this->user->assignRole('owner');
    $this->actingAs($this->user);
});

test('enrichies 24h : compte enriched_at recent, pas une simple modification, ni un autre espace', function () {
    // Modifiée à l'instant, jamais enrichie : NE compte PAS (c'était le défaut).
    enrEntreprise($this->espace, ['enriched_at' => null]);
    // Modifiée à l'instant, enrichie il y a 3 jours : NE compte PAS.
    enrEntreprise($this->espace, ['enriched_at' => now()->subDays(3)]);
    // Enrichie il y a 2 h : compte.
    enrEntreprise($this->espace, ['enriched_at' => now()->subHours(2)]);
    // Enrichie il y a 2 h mais supprimée : NE compte PAS.
    enrEntreprise($this->espace, ['enriched_at' => now()->subHours(2), 'deleted_at' => now()]);
    // Enrichie il y a 2 h dans UN AUTRE espace : NE compte PAS.
    enrEntreprise(enrEspace('ZZ autre espace'), ['enriched_at' => now()->subHours(2)]);

    $r = $this->getJson('/api/v1/dashboard/stats')->assertOk();

    expect($r->json('companies_enriched_24h'))->toBe(1);
});

test('index idx_companies_ws_enriched_at present et valide apres migration', function () {
    $index = DB::selectOne(
        'SELECT i.indisvalid AS valide, pg_get_indexdef(i.indexrelid) AS def
           FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
          WHERE c.relname = ?',
        ['idx_companies_ws_enriched_at'],
    );

    expect($index)->not->toBeNull()
        ->and((bool) $index->valide)->toBeTrue()
        ->and($index->def)->toContain('(workspace_id, enriched_at)')
        ->and($index->def)->toContain('enriched_at IS NOT NULL')
        ->and($index->def)->toContain('deleted_at IS NULL');
});
