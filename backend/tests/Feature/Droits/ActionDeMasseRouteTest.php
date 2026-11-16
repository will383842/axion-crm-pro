<?php

/**
 * L'action de masse `POST /api/v1/crm/bulk` exige `companies.update`, MESURÉE
 * PAR HTTP RÉEL sur la route (et non par la seule politique, voir
 * `ActionDeMasseAutoriseeTest`). Un refus ne doit modifier AUCUNE ligne.
 */

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config(['crm.console_v2' => true]);
    $this->seed(PermissionsAndRolesSeeder::class);

    $this->espaceA = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'ws-masse-a', 'name' => 'Espace A', 'settings' => []]);
    $this->espaceB = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'ws-masse-b', 'name' => 'Espace B', 'settings' => []]);

    $this->ficheA = masseFiche($this->espaceA->id, '900000301');
    $this->ficheB = masseFiche($this->espaceB->id, '900000302');
    $this->etiquetteA = masseEtiquette($this->espaceA->id);
    $this->etiquetteB = masseEtiquette($this->espaceB->id);
});

function masseActeur(string $espaceId, string $role): User
{
    $u = User::create([
        'id' => (string) Str::uuid(),
        'email' => Str::uuid() . '@example.test',
        'name' => 'Personne factice',
        'password_hash' => Hash::make('MotDePasseDeTest12345!'),
        'current_workspace_id' => $espaceId,
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($espaceId);
    $u->assignRole($role);
    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $u->id, 'workspace_id' => $espaceId, 'role_slug' => $role,
        'invited_at' => now(), 'joined_at' => now(),
    ]);

    return $u;
}

function masseFiche(string $espaceId, string $siren): int
{
    return (int) DB::table('companies')->insertGetId([
        'workspace_id' => $espaceId, 'siren' => $siren, 'denomination' => 'ZZ TEST ' . $siren,
        'discovery_source' => 'site', 'quality_score' => 0, 'signals' => '{}', 'metadata' => '{}',
        'relation_type' => 'prospect', 'lifecycle_stage' => 'nouveau',
        'legal_basis' => 'legitimate_interest_b2b', 'field_origins' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function masseEtiquette(string $espaceId): int
{
    return (int) DB::table('tags')->insertGetId([
        'workspace_id' => $espaceId, 'slug' => 'etiquette-masse', 'name' => 'Étiquette masse',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function masseEtat(int $ficheId): array
{
    return [
        'etape' => DB::table('companies')->where('id', $ficheId)->value('lifecycle_stage'),
        'liens' => DB::table('company_tag')->where('company_id', $ficheId)->count(),
    ];
}

test('un lecteur reçoit 403 sur chaque action de masse et rien n’est modifié', function (string $action, array $params) {
    DB::table('company_tag')->insert([
        'company_id' => $this->ficheA, 'tag_id' => $this->etiquetteA,
        'workspace_id' => $this->espaceA->id, 'assigned_at' => now(), 'assigned_by' => 'user',
    ]);
    $avant = masseEtat($this->ficheA);
    $this->actingAs(masseActeur($this->espaceA->id, 'viewer'));

    $this->postJson('/api/v1/crm/bulk', ['ids' => [$this->ficheA], 'action' => $action, 'params' => $params])
        ->assertForbidden();

    expect(masseEtat($this->ficheA))->toBe($avant)
        ->and(DB::table('activities')->where('kind', 'stage_changed')->count())->toBe(0);
})->with([
    'poser une étiquette' => ['add_tag', ['tag' => 'etiquette-masse']],
    'retirer une étiquette' => ['remove_tag', ['tag' => 'etiquette-masse']],
    'changer l’étape' => ['set_lifecycle', ['stage' => 'qualifie']],
]);

test('un lecteur ne pose aucune étiquette (vérifié en base)', function () {
    $this->actingAs(masseActeur($this->espaceA->id, 'viewer'));

    $this->postJson('/api/v1/crm/bulk', [
        'ids' => [$this->ficheA], 'action' => 'add_tag', 'params' => ['tag' => 'etiquette-masse'],
    ])->assertForbidden();

    expect(DB::table('company_tag')->count())->toBe(0);
});

test('un utilisateur autorisé réussit', function () {
    $this->actingAs(masseActeur($this->espaceA->id, 'operator'));

    $this->postJson('/api/v1/crm/bulk', [
        'ids' => [$this->ficheA], 'action' => 'add_tag', 'params' => ['tag' => 'etiquette-masse'],
    ])->assertOk()->assertJsonPath('updated', 1);

    $this->postJson('/api/v1/crm/bulk', [
        'ids' => [$this->ficheA], 'action' => 'set_lifecycle', 'params' => ['stage' => 'qualifie'],
    ])->assertOk()->assertJsonPath('updated', 1);

    expect(masseEtat($this->ficheA))->toBe(['etape' => 'qualifie', 'liens' => 1]);
});

test('un utilisateur d’un autre espace ne touche rien', function () {
    $this->actingAs(masseActeur($this->espaceA->id, 'admin'));

    $this->postJson('/api/v1/crm/bulk', [
        'ids' => [$this->ficheB], 'action' => 'set_lifecycle', 'params' => ['stage' => 'qualifie'],
    ])->assertOk()->assertJsonPath('updated', 0);

    $this->postJson('/api/v1/crm/bulk', [
        'ids' => [$this->ficheB], 'action' => 'add_tag', 'params' => ['tag' => 'etiquette-masse'],
    ])->assertOk()->assertJsonPath('updated', 0);

    expect(masseEtat($this->ficheB))->toBe(['etape' => 'nouveau', 'liens' => 0]);
});
