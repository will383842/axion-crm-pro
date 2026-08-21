<?php

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // Les roles doivent exister AVANT toute attribution.
    $this->seed(PermissionsAndRolesSeeder::class);
});

function makeAuthUser(): array
{
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'ws-' . Str::random(6),
        'name' => 'Test WS',
    ]);
    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'user' . Str::random(4) . '@test.local',
        'name' => 'Test User',
        'password_hash' => Hash::make('SomePass!1234'),
        'current_workspace_id' => $workspace->id,
        'first_login_completed_at' => now(),
    ]);

    // ⚠️ LE ROLE EST OBLIGATOIRE DEPUIS QUE F36-001 EST BRANCHE.
    //
    // Cette suite mesure le METIER, pas les droits, et son utilisateur n'en
    // avait aucun. Tant qu'aucune route ne portait `permission:`, cela ne se
    // voyait pas ; depuis, elle recevait 403. On lui donne `admin` : le geste
    // teste ICI est celui d'un administrateur, et le lui refuser reviendrait a
    // mesurer la garde au lieu du produit. Les droits sont mesures a leur
    // place : `tests/Feature/Rgpd/CoucheAutorisationBrancheeTest.php`.
    setPermissionsTeamId($user->current_workspace_id);
    // ⚠️ `owner` ET NON `admin` : `PUT /workspace` exige `workspaces.manage`,
    // et cette permission n'est attribuee QU'AU PROPRIETAIRE
    // (`PermissionsAndRolesSeeder` : la liste d'`admin` ne la contient pas).
    // Renommer l'espace lui-meme est une prerogative du proprietaire — et ce
    // test le prouve autant qu'il en depend.
    $user->assignRole('owner');

    return [$user, $workspace];
}

test('GET /workspace sans auth → 401', function () {
    $this->getJson('/api/v1/workspace')->assertUnauthorized();
});

test('GET /workspace authentifié retourne le workspace courant', function () {
    [$user, $workspace] = makeAuthUser();
    $this->actingAs($user)
        ->getJson('/api/v1/workspace')
        ->assertOk();
});

test('PUT /workspace retourne 501 (Sprint 3 stub)', function () {
    [$user] = makeAuthUser();
    $this->actingAs($user)
        ->putJson('/api/v1/workspace', ['name' => 'New'])
        ->assertStatus(501);
});

test('GET /users sans auth → 401', function () {
    $this->getJson('/api/v1/users')->assertUnauthorized();
});

test('GET /users authentifié retourne liste vide stub', function () {
    [$user] = makeAuthUser();
    $this->actingAs($user)
        ->getJson('/api/v1/users')
        ->assertOk()
        ->assertJsonStructure(['data']);
});

test('POST /users retourne 501', function () {
    [$user] = makeAuthUser();
    $this->actingAs($user)
        ->postJson('/api/v1/users', ['email' => 'a@b.com'])
        ->assertStatus(501);
});
