<?php

/**
 * API « DOUBLONS À VÉRIFIER » (chantier 5) — la file, les deux gestes, les
 * droits. Fixtures FICTIVES (dépôt public).
 */

use App\Crm\Doublons\Rapprochement;
use App\Models\User;
use App\Services\Audit\AuditHashChain;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->mock(AuditHashChain::class)->shouldReceive('record')->andReturn(1);
    $this->ws = F::espace('zz-doublons-api');
    $this->garde = F::fiche($this->ws, 'ZZ Api', ['postcode' => '69200', 'city' => 'ZZVILLE']);
    $this->absorbee = F::sansSiren($this->ws, 'ZZ Api', ['postcode' => '69200', 'foreign_id' => 'evt:zz-api']);
    F::contact($this->ws, $this->absorbee, 'Zoe', 'ZZAPI', ['email' => 'zoe@zz-api.example.invalid']);
    $this->paire = (int) DB::table('duplicate_flags')->insertGetId([
        'workspace_id' => $this->ws, 'entity_type' => 'company', 'entity_a_id' => $this->garde, 'entity_b_id' => $this->absorbee,
        'similarity' => 0.85, 'motif' => Rapprochement::NOM_CP, 'fusion_auto' => false,
    ]);
    DB::table('adresses_partagees')->insert([
        'workspace_id' => $this->ws, 'email_empreinte' => hash('sha256', 'zz'), 'nb_fiches' => 4, 'nature' => Rapprochement::DOMICILIATION,
    ]);
});

function dapiCompte(string $ws, string $role): User
{
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => $role . '-' . Str::random(6) . '@example.invalid', 'name' => 'ZZ ' . $role,
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $ws, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($ws);
    $user->assignRole($role);

    return $user;
}

test('la file montre la paire, les deux fiches côte à côte, et les compteurs', function () {
    $this->actingAs(dapiCompte($this->ws, 'viewer'));

    $r = $this->getJson('/api/v1/doublons')->assertOk();

    $r->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.par_motif.nom_cp', 1)
        ->assertJsonPath('meta.adresses_partagees.domiciliation', 1)
        ->assertJsonPath('data.0.id', $this->paire)
        ->assertJsonPath('data.0.motif_libelle', Rapprochement::libelle(Rapprochement::NOM_CP))
        ->assertJsonPath('data.0.garde.id', $this->garde)
        ->assertJsonPath('data.0.garde.ville', 'ZZVILLE')
        ->assertJsonPath('data.0.absorbee.id', $this->absorbee)
        ->assertJsonPath('data.0.absorbee.siren', null)
        ->assertJsonPath('data.0.absorbee.identifiant', 'evt:zz-api')
        ->assertJsonPath('data.0.absorbee.nb_contacts', 1);
    // Aucune coordonnée nominative dans la file.
    expect($r->getContent())->not->toContain('zoe@');
    // Un filtre de motif part bien au serveur.
    $this->getJson('/api/v1/doublons?motif=' . Rapprochement::NOM_CP_SITE)->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/doublons?motif=nimporte')->assertStatus(422);
});

test('une paire traitée, ou dont une fiche est à la corbeille, quitte la file', function () {
    $this->actingAs(dapiCompte($this->ws, 'viewer'));
    DB::table('companies')->where('id', $this->absorbee)->update(['deleted_at' => now()]);

    $this->getJson('/api/v1/doublons')->assertOk()->assertJsonPath('meta.total', 0);
});

test('les droits : lire (lecteur), écarter (opérateur), fusionner (qui peut supprimer)', function () {
    $this->actingAs(dapiCompte($this->ws, 'viewer'));
    $this->postJson("/api/v1/doublons/{$this->paire}/ignorer")->assertForbidden();
    $this->postJson("/api/v1/doublons/{$this->paire}/fusionner")->assertForbidden();

    $this->actingAs(dapiCompte($this->ws, 'operator'));
    $this->postJson("/api/v1/doublons/{$this->paire}/fusionner")->assertForbidden();
    expect(DB::table('companies')->where('id', $this->absorbee)->value('deleted_at'))->toBeNull();

    $this->actingAs(dapiCompte($this->ws, 'admin'));
    $r = $this->postJson("/api/v1/doublons/{$this->paire}/fusionner")->assertOk();

    expect($r->json('fusion_id'))->toBeInt()
        ->and(DB::table('companies')->where('id', $this->absorbee)->value('deleted_at'))->not->toBeNull()
        ->and(DB::table('fusions_fiches')->where('id', $r->json('fusion_id'))->value('mode'))->toBe('manuel')
        ->and(DB::table('duplicate_flags')->where('id', $this->paire)->value('resolution'))->toBe('merge');
});

test('« ce ne sont pas des doublons » écarte la paire, une fois', function () {
    $user = dapiCompte($this->ws, 'operator');
    $this->actingAs($user);

    $this->postJson("/api/v1/doublons/{$this->paire}/ignorer")->assertOk();
    $paire = DB::table('duplicate_flags')->where('id', $this->paire)->first();
    expect($paire->resolution)->toBe('keep_both')
        ->and($paire->reviewed_by)->toBe($user->id)
        ->and(DB::table('companies')->where('id', $this->absorbee)->value('deleted_at'))->toBeNull();

    $this->postJson("/api/v1/doublons/{$this->paire}/ignorer")->assertStatus(409)->assertJsonPath('error', 'deja_traitee');
});

test('un refus de fusion rend 409 et son motif, sans rien écrire', function () {
    $this->actingAs(dapiCompte($this->ws, 'admin'));
    DB::table('companies')->where('id', $this->absorbee)->update(['siren' => '949999992']);

    $this->postJson("/api/v1/doublons/{$this->paire}/fusionner")
        ->assertStatus(409)
        ->assertJsonPath('error', 'sirens_differents');
    expect(DB::table('fusions_fiches')->count())->toBe(0)
        ->and(DB::table('companies')->where('id', $this->absorbee)->value('deleted_at'))->toBeNull();
});

test('la paire d un autre espace : 404, jamais 403', function () {
    $ailleurs = F::espace('zz-doublons-ailleurs');
    $this->actingAs(dapiCompte($ailleurs, 'admin'));

    $this->postJson("/api/v1/doublons/{$this->paire}/fusionner")->assertNotFound();
    $this->postJson("/api/v1/doublons/{$this->paire}/ignorer")->assertNotFound();
    $this->getJson('/api/v1/doublons')->assertOk()->assertJsonPath('meta.total', 0);
    expect(DB::table('companies')->where('id', $this->absorbee)->value('deleted_at'))->toBeNull();
});
