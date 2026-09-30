<?php

/**
 * LE STATUT DE RELATION POSÉ À LA MAIN — `PUT /companies/{id}/relation`
 * (chantier B). Fixtures fictives.
 */

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-relation-main', 'name' => 'WS', 'settings' => [],
    ]);
    $this->fiche = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->workspace->id, 'siren' => '941700001', 'denomination' => 'ZZ Relation',
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

function rmCompte(string $workspaceId, string $role): User
{
    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => $role . '-' . Str::random(6) . '@example.invalid',
        'name' => 'ZZ ' . $role,
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $workspaceId,
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($workspaceId);
    $user->assignRole($role);

    return $user;
}

test('un operateur pose le type et l etape : marque manuelle, activite et evenement d audit', function () {
    $this->actingAs(rmCompte($this->workspace->id, 'operator'));

    $r = $this->putJson("/api/v1/companies/{$this->fiche}/relation", ['relation_type' => 'client', 'lifecycle_stage' => 'client']);

    $r->assertOk()->assertHeader('ETag');
    $fiche = DB::table('companies')->where('id', $this->fiche)->first();
    $activite = DB::table('activities')->where('subject_id', $this->fiche)->where('kind', 'stage_changed')->first();
    expect($fiche->relation_type)->toBe('client')
        ->and($fiche->lifecycle_stage)->toBe('client')
        ->and($fiche->relation_saisie_manuelle_at)->not->toBeNull()
        ->and($activite)->not->toBeNull()
        ->and(json_decode((string) $activite->payload, true)['source'])->toBe('console:fiche')
        ->and(DB::table('business_events')->where('action', 'company.relation.saisie')->count())->toBe(1);
});

test('la saisie manuelle peut reculer (decision humaine) ; l import, ensuite, ne la touche plus', function () {
    DB::table('companies')->where('id', $this->fiche)->update(['relation_type' => 'client', 'lifecycle_stage' => 'client']);
    $this->actingAs(rmCompte($this->workspace->id, 'admin'));

    $this->putJson("/api/v1/companies/{$this->fiche}/relation", ['lifecycle_stage' => 'perdu'])->assertOk();

    $fichier = tempnam(sys_get_temp_dir(), 'zz-rm-');
    file_put_contents($fichier, json_encode(['source' => 'site-client', 'siren' => '941700001', 'relation_type' => 'client', 'lifecycle_stage' => 'client']) . "\n");
    Artisan::call('crm:relations:importer', ['fichier' => $fichier, '--workspace' => 'ws-relation-main']);

    $fiche = DB::table('companies')->where('id', $this->fiche)->first();
    expect($fiche->relation_type)->toBe('client')
        ->and($fiche->lifecycle_stage)->toBe('perdu')
        ->and(Artisan::output())->toMatch('/verrouillees_a_la_main\s*\|\s*1/');
});

test('un compte en lecture seule est refuse, une valeur hors vocabulaire aussi', function () {
    $this->actingAs(rmCompte($this->workspace->id, 'viewer'));
    $this->putJson("/api/v1/companies/{$this->fiche}/relation", ['relation_type' => 'client'])->assertForbidden();

    $this->actingAs(rmCompte($this->workspace->id, 'operator'));
    $this->putJson("/api/v1/companies/{$this->fiche}/relation", ['relation_type' => 'vip'])->assertStatus(422);
    $this->putJson("/api/v1/companies/{$this->fiche}/relation", [])->assertStatus(422);

    $fiche = DB::table('companies')->where('id', $this->fiche)->first();
    expect($fiche->relation_type)->toBe('prospect')
        ->and($fiche->relation_saisie_manuelle_at)->toBeNull();
});

test('la fiche d un AUTRE espace rend 404 et reste intacte', function () {
    $autre = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'ws-relation-autre', 'name' => 'Autre', 'settings' => []]);
    $ailleurs = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $autre->id, 'siren' => '941700002', 'denomination' => 'ZZ Ailleurs', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs(rmCompte($this->workspace->id, 'admin'));

    $this->putJson("/api/v1/companies/{$ailleurs}/relation", ['relation_type' => 'client'])->assertNotFound();

    expect(DB::table('companies')->where('id', $ailleurs)->value('relation_type'))->toBe('prospect');
});
