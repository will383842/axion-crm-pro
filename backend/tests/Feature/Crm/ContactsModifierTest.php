<?php

/**
 * Lot G1-A (cahier #619, § 3) — modifier un contact : `PATCH /api/v1/crm/contacts/{id}` et l'ancien
 * `PUT /api/v1/contacts/{id}` (qui rendait 501). HTTP réel pour les droits ; le service est joué sous le
 * rôle applicatif (`SET LOCAL ROLE` + `WorkspaceContext`) ET sous le propriétaire. Données fictives (example.test).
 */

use App\Crm\Contacts\GestesContact;
use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
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
    $this->espaceA = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'ws-cm-a', 'name' => 'Espace A', 'settings' => []]);
    $this->espaceB = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'ws-cm-b', 'name' => 'Espace B', 'settings' => []]);
    $this->entrepriseA = cmEntreprise($this->espaceA->id, '900000401');
    $this->entrepriseB = cmEntreprise($this->espaceB->id, '900000402');
    $this->contactA = cmContact($this->espaceA->id, $this->entrepriseA, 'Durand', 'Alice', 'alice@example.test');
    $this->contactB = cmContact($this->espaceB->id, $this->entrepriseB, 'Martin', 'Bob', 'bob@example.test');
});

function cmActeur(string $espaceId, string $role): User
{
    $u = User::create([
        'id' => (string) Str::uuid(), 'email' => Str::uuid() . '@example.test', 'name' => 'Personne factice',
        'password_hash' => Hash::make('MotDePasseDeTest12345!'), 'current_workspace_id' => $espaceId,
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($espaceId);
    $u->assignRole($role);
    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $u->id, 'workspace_id' => $espaceId, 'role_slug' => $role, 'invited_at' => now(), 'joined_at' => now(),
    ]);

    return $u;
}

function cmEntreprise(string $espaceId, string $siren): int
{
    return (int) DB::table('companies')->insertGetId([
        'workspace_id' => $espaceId, 'siren' => $siren, 'denomination' => 'ZZ TEST ' . $siren,
        'discovery_source' => 'site', 'quality_score' => 0, 'signals' => '{}', 'metadata' => '{}',
        'relation_type' => 'prospect', 'lifecycle_stage' => 'nouveau',
        'legal_basis' => 'legitimate_interest_b2b', 'field_origins' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function cmContact(string $espaceId, int $entrepriseId, string $nom, string $prenom, ?string $email, ?string $supprimeLe = null): int
{
    return (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $espaceId, 'company_id' => $entrepriseId, 'first_name' => $prenom, 'last_name' => $nom,
        'email' => $email, 'discovery_source' => 'site', 'sources' => '["site"]', 'metadata' => '{}',
        'legal_basis' => 'legitimate_interest_b2b', 'deleted_at' => $supprimeLe,
        'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
    ]);
}

function cmLigne(int $id): array
{
    return (array) DB::table('contacts')->where('id', $id)->first();
}

function cmNbJournal(): int
{
    return DB::table('business_events')->count();
}

test('1. un lecteur reçoit 403 sur PATCH et sur PUT, la ligne reste identique, aucun journal', function () {
    $avant = cmLigne($this->contactA);
    $this->actingAs(cmActeur($this->espaceA->id, 'viewer'));

    $this->patchJson("/api/v1/crm/contacts/{$this->contactA}", ['last_name' => 'Pirate'])->assertForbidden();
    $this->putJson("/api/v1/contacts/{$this->contactA}", ['last_name' => 'Pirate'])->assertForbidden();

    expect(cmLigne($this->contactA))->toBe($avant);
    expect(cmNbJournal())->toBe(0);
});

test('2. un opérateur modifie : 200, champs écrits, updated_at bouge, le journal ne porte aucune valeur', function () {
    $avant = cmLigne($this->contactA)['updated_at'];
    $this->actingAs(cmActeur($this->espaceA->id, 'operator'));

    $r = $this->patchJson("/api/v1/crm/contacts/{$this->contactA}", [
        'first_name' => 'Alicia', 'fonction' => 'Directrice', 'phone' => '0102030405', 'email' => 'nouvelle@example.test',
    ])->assertOk();
    $r->assertJsonPath('data.first_name', 'Alicia');

    $l = cmLigne($this->contactA);
    expect($l['first_name'])->toBe('Alicia')->and($l['role'])->toBe('Directrice')->and($l['phone'])->toBe('0102030405')
        ->and($l['email'])->toBe('nouvelle@example.test')->and($l['updated_at'])->not->toBe($avant);
    expect(json_decode($l['field_origins'], true))->toMatchArray(['first_name' => 'declared', 'email' => 'declared']);

    $evt = DB::table('business_events')->where('workspace_id', $this->espaceA->id)->get();
    expect($evt)->toHaveCount(1);
    $brut = json_encode($evt->all());
    expect($brut)->toContain('contact.modifie')->not->toContain('nouvelle@example.test')->not->toContain('Alicia')
        ->not->toContain('0102030405')->not->toContain('Directrice');
    expect($brut)->toContain('first_name');
});

test('2b. l’ancien PUT /contacts/{id} modifie aussi pour un opérateur', function () {
    $this->actingAs(cmActeur($this->espaceA->id, 'operator'));
    $this->putJson("/api/v1/contacts/{$this->contactA}", ['last_name' => 'Dupont'])->assertOk();
    expect(cmLigne($this->contactA)['last_name'])->toBe('Dupont');
});

test('3. un admin de A ne touche pas le contact de B : 404, B intact (PATCH et PUT)', function () {
    $avant = cmLigne($this->contactB);
    $this->actingAs(cmActeur($this->espaceA->id, 'admin'));

    $this->patchJson("/api/v1/crm/contacts/{$this->contactB}", ['last_name' => 'Pirate'])->assertNotFound();
    $this->putJson("/api/v1/contacts/{$this->contactB}", ['last_name' => 'Pirate'])->assertNotFound();
    expect(cmLigne($this->contactB))->toBe($avant);
});

test('4. un contact en corbeille répond 404 et reste inchangé', function () {
    $id = cmContact($this->espaceA->id, $this->entrepriseA, 'Retire', 'Zoe', 'zoe@example.test', now()->toDateTimeString());
    $avant = cmLigne($id);
    $this->actingAs(cmActeur($this->espaceA->id, 'operator'));

    $this->patchJson("/api/v1/crm/contacts/{$id}", ['last_name' => 'Autre'])->assertNotFound();
    expect(cmLigne($id))->toBe($avant);
});

test('5. nom vide → 422 ; company_id envoyé est ignoré ; aucun champ → 422', function () {
    $this->actingAs(cmActeur($this->espaceA->id, 'operator'));
    $avant = cmLigne($this->contactA);

    $this->patchJson("/api/v1/crm/contacts/{$this->contactA}", ['last_name' => ''])->assertStatus(422);
    $this->patchJson("/api/v1/crm/contacts/{$this->contactA}", [])->assertStatus(422);
    expect(cmLigne($this->contactA))->toBe($avant);

    $autre = cmEntreprise($this->espaceA->id, '900000403');
    $this->patchJson("/api/v1/crm/contacts/{$this->contactA}", ['first_name' => 'Zed', 'company_id' => $autre, 'workspace_id' => $this->espaceB->id])->assertOk();
    $l = cmLigne($this->contactA);
    expect($l['company_id'])->toBe($this->entrepriseA)->and($l['workspace_id'])->toBe($this->espaceA->id)->and($l['first_name'])->toBe('Zed');
});

test('5b. lifecycle_stage : ignoré s’il égale l’étape de l’entreprise, sinon 422', function () {
    $this->actingAs(cmActeur($this->espaceA->id, 'operator'));
    $this->patchJson("/api/v1/crm/contacts/{$this->contactA}", ['first_name' => 'Zed', 'lifecycle_stage' => 'nouveau'])->assertOk();
    $avant = cmLigne($this->contactA);
    $this->patchJson("/api/v1/crm/contacts/{$this->contactA}", ['first_name' => 'Yan', 'lifecycle_stage' => 'client'])
        ->assertStatus(422)->assertJsonPath('error', 'champ_non_modifiable');
    expect(cmLigne($this->contactA))->toBe($avant);
});

test('6. nom identique à un contact en corbeille de la même entreprise → 409, rien modifié', function () {
    cmContact($this->espaceA->id, $this->entrepriseA, 'Retire', 'Zoe', null, now()->toDateTimeString());
    $avant = cmLigne($this->contactA);
    $this->actingAs(cmActeur($this->espaceA->id, 'operator'));

    $this->patchJson("/api/v1/crm/contacts/{$this->contactA}", ['first_name' => 'Zoe', 'last_name' => 'Retire'])
        ->assertStatus(409)->assertJsonPath('error', 'doublon');
    expect(cmLigne($this->contactA))->toBe($avant);
    // La base reste utilisable (la transaction n'est pas cassée).
    $this->patchJson("/api/v1/crm/contacts/{$this->contactA}", ['first_name' => 'Ok'])->assertOk();
});

test('7. PUT /contacts/{id} ne rend plus 501', function () {
    $this->actingAs(cmActeur($this->espaceA->id, 'admin'));
    expect($this->putJson("/api/v1/contacts/{$this->contactA}", ['first_name' => 'Ok'])->status())->not->toBe(501);
});

test('8. le service sous rôle applicatif ET propriétaire : même résultat pour A, B introuvable', function (string $mode) {
    $ws = $this->espaceA->id;
    $joue = function (int $id, array $champs) use ($mode, $ws): array {
        DB::beginTransaction();
        try {
            if ($mode === 'app') {
                WorkspaceContext::set($ws);
                DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
            }

            return (new GestesContact)->modifier($ws, $id, $champs);
        } finally {
            DB::rollBack();
        }
    };

    $a = $joue($this->contactA, ['first_name' => 'Alicia']);
    expect($a['etat'])->toBe('ok')->and($a['contact']->first_name)->toBe('Alicia')->and($a['champs'])->toBe(['first_name']);

    $b = $joue($this->contactB, ['first_name' => 'Pirate']);
    expect($b['etat'])->toBe('introuvable');
    expect(cmLigne($this->contactB)['first_name'])->toBe('Bob');

    $retire = cmContact($ws, $this->entrepriseA, 'Retire', 'Zoe', null, now()->toDateTimeString());
    expect($joue($retire, ['first_name' => 'Autre'])['etat'])->toBe('introuvable');

    expect($joue($this->contactA, ['last_name' => 'Durand'])['etat'])->toBe('ok');
})->with(['app', 'proprio']);
