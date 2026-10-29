<?php

/**
 * TÂCHES PROPRES AU CRM sur une entreprise (lot A4) : même modèle que les
 * tâches par personne (`activities`, nature `task`), sujet `company`.
 */

use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);

    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-taches', 'name' => 'WS', 'settings' => [],
    ]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->id);

    $this->user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'taches@example.com', 'name' => 'U',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $this->workspace->id,
        'first_login_completed_at' => now(),
    ]);
    $this->user->assignRole('owner');
    $this->actingAs($this->user);

    $this->fiche = Company::create([
        'workspace_id' => $this->workspace->id,
        'siren' => (string) random_int(100000000, 999999999),
        'denomination' => 'ACME', 'signals' => [], 'metadata' => [],
    ]);
});

test('ajouter une tâche CRM sur une entreprise la consigne en activité de sujet company', function () {
    $r = $this->postJson("/api/v1/companies/{$this->fiche->id}/taches", [
        'title' => 'Rappeler la direction', 'due_at' => '2026-11-15',
    ])->assertCreated();

    expect($r->json('deja_consignee'))->toBeFalse();

    $ligne = DB::table('activities')->where('id', $r->json('activity_id'))->first();
    expect($ligne->kind)->toBe('task')
        ->and($ligne->subject_type)->toBe('company')
        ->and((int) $ligne->subject_id)->toBe($this->fiche->id)
        ->and($ligne->done_at)->toBeNull();
});

test('un double clic ne crée qu’une tâche', function () {
    $corps = ['title' => 'Envoyer le devis'];
    $this->postJson("/api/v1/companies/{$this->fiche->id}/taches", $corps)->assertCreated();
    $this->postJson("/api/v1/companies/{$this->fiche->id}/taches", $corps)->assertOk()
        ->assertJson(['deja_consignee' => true]);

    expect(DB::table('activities')->where('kind', 'task')->count())->toBe(1);
});

test('la liste rend les tâches de cette entreprise seulement, les faites en dernier', function () {
    $autre = Company::create([
        'workspace_id' => $this->workspace->id, 'siren' => '999888777',
        'denomination' => 'AUTRE', 'signals' => [], 'metadata' => [],
    ]);
    $a = $this->postJson("/api/v1/companies/{$this->fiche->id}/taches", ['title' => 'Première tâche'])->json('activity_id');
    $this->postJson("/api/v1/companies/{$this->fiche->id}/taches", ['title' => 'Seconde tâche']);
    $this->postJson("/api/v1/companies/{$autre->id}/taches", ['title' => 'Tâche ailleurs']);
    $this->postJson("/api/v1/companies/{$this->fiche->id}/taches/{$a}/terminer")->assertOk();

    $r = $this->getJson("/api/v1/companies/{$this->fiche->id}/taches")->assertOk();

    expect(array_column($r->json('data'), 'title'))->toBe(['Seconde tâche', 'Première tâche']);
    expect($r->json('data.1.done'))->toBeTrue();
});

test('marquer faite garde la ligne ; une seconde fois renvoie 404', function () {
    $id = $this->postJson("/api/v1/companies/{$this->fiche->id}/taches", ['title' => 'À clore'])->json('activity_id');

    $this->postJson("/api/v1/companies/{$this->fiche->id}/taches/{$id}/terminer")->assertOk();
    expect(DB::table('activities')->where('id', $id)->whereNotNull('done_at')->exists())->toBeTrue();

    $this->postJson("/api/v1/companies/{$this->fiche->id}/taches/{$id}/terminer")->assertNotFound();
    expect(DB::table('activities')->where('id', $id)->exists())->toBeTrue();
});

test('une tâche d’une autre entreprise ne se clôt pas par l’URL de celle-ci', function () {
    $autre = Company::create([
        'workspace_id' => $this->workspace->id, 'siren' => '111222333',
        'denomination' => 'AUTRE', 'signals' => [], 'metadata' => [],
    ]);
    $id = $this->postJson("/api/v1/companies/{$autre->id}/taches", ['title' => 'Pas la vôtre'])->json('activity_id');

    $this->postJson("/api/v1/companies/{$this->fiche->id}/taches/{$id}/terminer")->assertNotFound();
});

test('l’intitulé est exigé', function () {
    $this->postJson("/api/v1/companies/{$this->fiche->id}/taches", ['title' => 'a'])->assertUnprocessable();
});

test('un compte sans droit de modification ne peut ni ajouter ni clore', function () {
    $lecteur = User::create([
        'id' => (string) Str::uuid(), 'email' => 'lecteur@example.com', 'name' => 'L',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $this->workspace->id,
        'first_login_completed_at' => now(),
    ]);
    $lecteur->assignRole('viewer');
    $this->actingAs($lecteur);

    $this->postJson("/api/v1/companies/{$this->fiche->id}/taches", ['title' => 'Interdit'])->assertForbidden();
    $this->getJson("/api/v1/companies/{$this->fiche->id}/taches")->assertOk();

    // Une tâche existante ne se clôt pas non plus.
    $id = DB::table('activities')->insertGetId([
        'workspace_id' => $this->workspace->id, 'type' => 'task', 'kind' => 'task', 'occurred_at' => now(),
        'subject_type' => 'company', 'subject_id' => $this->fiche->id, 'title' => 'Déjà là', 'created_at' => now(),
    ]);
    $this->postJson("/api/v1/companies/{$this->fiche->id}/taches/{$id}/terminer")->assertForbidden();
    expect(DB::table('activities')->where('id', $id)->whereNull('done_at')->exists())->toBeTrue();
});

test('une entreprise d’un AUTRE espace de travail donne 404 en lecture, ajout et clôture', function () {
    $autreEspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-ailleurs', 'name' => 'Ailleurs', 'settings' => [],
    ]);
    $etrangere = Company::create([
        'workspace_id' => $autreEspace->id, 'siren' => '444555666',
        'denomination' => 'ETRANGERE', 'signals' => [], 'metadata' => [],
    ]);
    $id = DB::table('activities')->insertGetId([
        'workspace_id' => $autreEspace->id, 'type' => 'task', 'kind' => 'task', 'occurred_at' => now(),
        'subject_type' => 'company', 'subject_id' => $etrangere->id, 'title' => 'Chez l’autre', 'created_at' => now(),
    ]);

    $this->getJson("/api/v1/companies/{$etrangere->id}/taches")->assertNotFound();
    $this->postJson("/api/v1/companies/{$etrangere->id}/taches", ['title' => 'Intrusion'])->assertNotFound();
    $this->postJson("/api/v1/companies/{$etrangere->id}/taches/{$id}/terminer")->assertNotFound();

    expect(DB::table('activities')->where('id', $id)->whereNull('done_at')->exists())->toBeTrue()
        ->and(DB::table('activities')->where('workspace_id', $autreEspace->id)->count())->toBe(1);
});
