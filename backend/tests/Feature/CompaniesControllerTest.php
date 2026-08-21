<?php

use App\Jobs\EnrichCompanyJob;
use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * 🔴 CE FICHIER A ETE CASSE PAR `d58d75c`, ET NON MIS A JOUR PAR LUI.
 *
 * Le compte cree ici ne portait AUCUN role. C'etait sans consequence tant que
 * les routes d'ecriture des entreprises n'exigeaient rien : le constat F36-003
 * etablissait justement qu'« un compte en lecture seule cree, modifie et
 * supprime definitivement des entreprises ». Depuis le correctif, `store`,
 * `update`, `destroy`, `enrich` et `recompute-score` exigent une permission,
 * et sept tests de ce fichier ont vire au 403 sans avoir ete touches.
 *
 * **Ce rouge est la preuve que la garde marche.** C'est la realisation exacte
 * de `P5-ROLES-001`, ecrit le matin du 2026-08-19 : « le jour ou l'on cable les
 * policies, ces fichiers passeront au rouge, et ce rouge sera la preuve que le
 * correctif marche. Le risque est humain : la pente naturelle est d'assouplir
 * la garde pour les faire passer. »
 *
 * On donne donc un role au test. Spatie tourne en mode « teams » : le role est
 * attribue PAR espace de travail, d'ou `setPermissionsTeamId()`.
 *
 * ⚠️ ET ON AJOUTE LE PENDANT QUI MANQUAIT. Reverdir ces sept tests avec un
 * compte `admin` ne prouverait rien sur le defaut d'origine : il faut le cas
 * ou le compte N'A PAS le droit. C'est le dernier test du fichier, et sans lui
 * un futur relachement des routes reverdirait tout en silence.
 */
beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);

    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-tst', 'name' => 'WS', 'settings' => [],
    ]);
    $this->user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'u@example.com', 'name' => 'U',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $this->workspace->id,
        'first_login_completed_at' => now(),
    ]);

    setPermissionsTeamId($this->workspace->id);
    $this->user->assignRole('admin');

    $this->actingAs($this->user);
});

/** Un compte en LECTURE SEULE dans le meme espace, pour le pendant negatif. */
function compteLectureSeuleEntreprises(string $workspaceId): User
{
    $viewer = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'viewer-' . Str::random(6) . '@example.com',
        'name' => 'Viewer',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $workspaceId,
        'first_login_completed_at' => now(),
    ]);

    setPermissionsTeamId($workspaceId);
    $viewer->assignRole('viewer');

    return $viewer;
}

test('index returns paginated empty list', function () {
    $r = $this->getJson('/api/v1/companies?per_page=10');
    $r->assertOk();
    $r->assertJsonStructure(['data', 'meta' => ['total', 'per_page', 'current_page', 'last_page']]);
});

test('store validates siren format 9 digits', function () {
    $this->postJson('/api/v1/companies', ['siren' => '12345'])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('siren');
});

test('store creates company with valid siren', function () {
    $r = $this->postJson('/api/v1/companies', [
        'siren' => '123456789',
        'denomination' => 'Test Corp',
    ]);
    $r->assertStatus(201);
    expect(Company::where('siren', '123456789')->exists())->toBeTrue();
});

test('update validates priority enum', function () {
    $c = Company::create([
        'workspace_id' => $this->workspace->id, 'siren' => '111111111',
        'denomination' => 'X', 'signals' => [], 'metadata' => [],
    ]);
    $this->putJson("/api/v1/companies/{$c->id}", ['priority' => 'invalid-value'])
        ->assertStatus(422);
});

test('update with valid priority succeeds', function () {
    $c = Company::create([
        'workspace_id' => $this->workspace->id, 'siren' => '222222222',
        'denomination' => 'X', 'signals' => [], 'metadata' => [],
    ]);
    $this->putJson("/api/v1/companies/{$c->id}", ['priority' => 'haute'])
        ->assertOk();
    expect($c->fresh()->priority)->toBe('haute');
});

test('destroy soft-deletes company', function () {
    $c = Company::create([
        'workspace_id' => $this->workspace->id, 'siren' => '333333333',
        'denomination' => 'X', 'signals' => [], 'metadata' => [],
    ]);
    $this->deleteJson("/api/v1/companies/{$c->id}")->assertNoContent();
});

test('recompute-score endpoint calls SQL function', function () {
    $c = Company::create([
        'workspace_id' => $this->workspace->id, 'siren' => '444444444',
        'denomination' => 'X', 'signals' => [], 'metadata' => [],
    ]);
    $r = $this->postJson("/api/v1/companies/{$c->id}/recompute-score");
    $r->assertOk();
});

test('bulkEnrich queues jobs', function () {
    $c = Company::create([
        'workspace_id' => $this->workspace->id, 'siren' => '555555555',
        'denomination' => 'X', 'signals' => [], 'metadata' => [],
    ]);
    Queue::fake();
    $this->postJson('/api/v1/companies/bulk-enrich', ['ids' => [$c->id]])->assertOk();
    Queue::assertPushed(EnrichCompanyJob::class);
});

/**
 * F36-003 — LE PENDANT NEGATIF, et la raison pour laquelle on ne relache rien.
 *
 * Sans ce test, les sept tests ci-dessus prouveraient seulement qu'un admin
 * peut ecrire. Ils ne diraient RIEN du defaut d'origine : qu'un compte en
 * lecture seule le pouvait aussi. C'est ce cas-la, et lui seul, qui garde le
 * correctif.
 */
test('F36-003 — un compte en LECTURE SEULE ne cree, ne modifie ni ne supprime une entreprise', function () {
    $viewer = compteLectureSeuleEntreprises($this->workspace->id);

    $entreprise = Company::create([
        'workspace_id' => $this->workspace->id,
        'siren' => '987654321',
        'denomination' => 'Cible',
    ]);

    $this->actingAs($viewer)
        ->postJson('/api/v1/companies', ['siren' => '123456789', 'denomination' => 'Interdit'])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->putJson('/api/v1/companies/' . $entreprise->id, ['denomination' => 'Renomme'])
        ->assertForbidden();

    $this->actingAs($viewer)
        ->deleteJson('/api/v1/companies/' . $entreprise->id)
        ->assertForbidden();

    // TEMOIN : la fiche est toujours la. Un 403 qui aurait quand meme ecrit
    // serait le pire des cas -- refuser en facade et agir en coulisse.
    expect(Company::find($entreprise->id))->not->toBeNull();
    expect(Company::find($entreprise->id)->denomination)->toBe('Cible');
});
