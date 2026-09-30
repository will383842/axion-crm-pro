<?php

/**
 * LISTES MANUELLES — créer, renommer, cocher, retirer, corbeille ; les droits
 * et le masquage (2026-09-30). Fixtures FICTIVES (dépôt public).
 *
 * Ce que ces tests gardent, en plus du fonctionnement :
 *  - rien n'est jamais SUPPRIMÉ : retirer une fiche d'une liste ne touche ni
 *    la fiche ni la ligne d'appartenance (elle reçoit `retire_le`) ; une liste
 *    va à la corbeille (`deleted_at`) et en revient ;
 *  - une fiche d'un AUTRE espace n'entre jamais dans une liste ;
 *  - un compte en lecture seule lit les listes, sans les adresses en clair, et
 *    ne modifie rien.
 */

use App\Models\User;
use App\Services\Audit\AuditHashChain;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Database\QueryException;
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
    $this->ws = F::espace('zz-listes-api');
    $this->autreEspace = F::espace('zz-listes-autre');

    $this->salon = F::fiche($this->ws, 'ZZ Salon', ['email_generic' => 'accueil@zz-salon.example.invalid']);
    $this->club = F::fiche($this->ws, 'ZZ Club');
    $this->personne = F::contact($this->ws, $this->club, 'Zia', 'ZZPRESIDENTE', ['email' => 'zia@zz-club.example.invalid', 'role' => 'Présidente']);
    $this->etrangere = F::fiche($this->autreEspace, 'ZZ Ailleurs');
});

function lmCompte(string $ws, string $role): User
{
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => $role . '-' . Str::random(6) . '@example.invalid', 'name' => 'ZZ ' . $role,
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $ws, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($ws);
    $user->assignRole($role);

    return $user;
}

function lmCreer(object $t, string $nom = 'Invités salon ZZ'): int
{
    $t->actingAs(lmCompte($t->ws, 'operator'));

    return (int) $t->postJson('/api/v1/listes-manuelles', ['nom' => $nom])->assertCreated()->json('data.id');
}

test('un opérateur crée une liste, la renomme ; deux listes vivantes ne portent pas le même nom', function () {
    $id = lmCreer($this);

    $this->postJson('/api/v1/listes-manuelles', ['nom' => '  invités SALON zz '])->assertStatus(422);
    $this->putJson('/api/v1/listes-manuelles/' . $id, ['nom' => 'Invités GOFAB ZZ', 'description' => 'Salon d’octobre'])
        ->assertOk()->assertJsonPath('data.nom', 'Invités GOFAB ZZ');

    expect(DB::table('listes_manuelles')->where('id', $id)->value('nom'))->toBe('Invités GOFAB ZZ')
        ->and(DB::table('listes_manuelles')->where('id', $id)->value('workspace_id'))->toBe($this->ws);
});

test('cocher des fiches : organisation et personne ; une fiche d un autre espace est introuvable, jamais ajoutée', function () {
    $id = lmCreer($this);

    $r = $this->postJson("/api/v1/listes-manuelles/{$id}/membres", [
        'company_ids' => [$this->salon, $this->etrangere, 999999999],
        'contact_ids' => [$this->personne],
    ])->assertOk();

    $r->assertJsonPath('data.ajoutes', 2)->assertJsonPath('data.introuvables', 2)->assertJsonPath('data.deja_presents', 0);
    expect(DB::table('listes_manuelles_membres')->where('liste_id', $id)->count())->toBe(2)
        ->and(DB::table('listes_manuelles_membres')->where('liste_id', $id)->where('company_id', $this->etrangere)->exists())->toBeFalse();

    // Recocher ne duplique rien.
    $this->postJson("/api/v1/listes-manuelles/{$id}/membres", ['company_ids' => [$this->salon]])
        ->assertOk()->assertJsonPath('data.deja_presents', 1)->assertJsonPath('data.ajoutes', 0);

    // Les effectifs de la liste.
    $this->getJson("/api/v1/listes-manuelles/{$id}")->assertOk()
        ->assertJsonPath('data.organisations', 1)->assertJsonPath('data.personnes', 1);
    // « Cette fiche est-elle dans la liste ? » (écran de la fiche)
    $this->getJson('/api/v1/listes-manuelles?company_id=' . $this->salon)->assertOk()->assertJsonPath('data.0.contient', true);
    $this->getJson('/api/v1/listes-manuelles?company_id=' . $this->club)->assertOk()->assertJsonPath('data.0.contient', false);
});

test('RETIRER ne supprime rien : ni la fiche, ni la ligne d appartenance — et recocher la réactive', function () {
    $id = lmCreer($this);
    $this->postJson("/api/v1/listes-manuelles/{$id}/membres", ['company_ids' => [$this->salon], 'contact_ids' => [$this->personne]])->assertOk();
    $lignesAvant = DB::table('listes_manuelles_membres')->count();

    $this->postJson("/api/v1/listes-manuelles/{$id}/membres/retirer", ['company_ids' => [$this->salon], 'contact_ids' => [$this->personne]])
        ->assertOk()->assertJsonPath('data.retires', 2);

    expect(DB::table('listes_manuelles_membres')->count())->toBe($lignesAvant)
        ->and(DB::table('listes_manuelles_membres')->where('liste_id', $id)->whereNull('retire_le')->count())->toBe(0)
        ->and(DB::table('companies')->where('id', $this->salon)->whereNull('deleted_at')->exists())->toBeTrue()
        ->and(DB::table('contacts')->where('id', $this->personne)->whereNull('deleted_at')->exists())->toBeTrue();
    $this->getJson("/api/v1/listes-manuelles/{$id}/membres")->assertOk()->assertJsonPath('meta.total', 0);

    $this->postJson("/api/v1/listes-manuelles/{$id}/membres", ['company_ids' => [$this->salon]])
        ->assertOk()->assertJsonPath('data.reactives', 1);
    expect(DB::table('listes_manuelles_membres')->count())->toBe($lignesAvant);
});

test('un geste sans fiche est refusé, et jamais plus de 500 fiches à la fois', function () {
    $id = lmCreer($this);

    $this->postJson("/api/v1/listes-manuelles/{$id}/membres", [])->assertStatus(422);
    $this->postJson("/api/v1/listes-manuelles/{$id}/membres", ['company_ids' => range(1, 501)])->assertStatus(422);
});

test('la CORBEILLE, jamais la suppression : un opérateur ne peut pas, un admin met à la corbeille et restaure', function () {
    $id = lmCreer($this);
    $this->postJson("/api/v1/listes-manuelles/{$id}/membres", ['company_ids' => [$this->salon]])->assertOk();

    $this->deleteJson("/api/v1/listes-manuelles/{$id}")->assertForbidden();

    $this->actingAs(lmCompte($this->ws, 'admin'));
    $this->deleteJson("/api/v1/listes-manuelles/{$id}")->assertOk()->assertJsonPath('corbeille', true);

    // La ligne est LÀ, avec ses membres ; seule `deleted_at` a changé.
    expect(DB::table('listes_manuelles')->where('id', $id)->whereNotNull('deleted_at')->exists())->toBeTrue()
        ->and(DB::table('listes_manuelles_membres')->where('liste_id', $id)->count())->toBe(1);
    $this->getJson("/api/v1/listes-manuelles/{$id}")->assertNotFound();
    $this->getJson('/api/v1/listes-manuelles?corbeille=1')->assertOk()->assertJsonPath('data.0.id', $id);

    $this->postJson("/api/v1/listes-manuelles/{$id}/restaurer")->assertOk();
    expect(DB::table('listes_manuelles')->where('id', $id)->value('deleted_at'))->toBeNull();
});

test('une liste qui sert à une audience ne part pas à la corbeille', function () {
    $id = lmCreer($this);
    DB::table('email_audiences')->insert([
        'workspace_id' => $this->ws, 'name' => 'ZZ Audience GOFAB',
        'criteria' => json_encode(['all' => [['field' => 'liste_manuelle', 'op' => 'in', 'value' => [$id]]]]),
    ]);

    $this->actingAs(lmCompte($this->ws, 'admin'));
    $this->deleteJson("/api/v1/listes-manuelles/{$id}")->assertStatus(409)->assertJsonPath('audiences.0.nom', 'ZZ Audience GOFAB');

    expect(DB::table('listes_manuelles')->where('id', $id)->value('deleted_at'))->toBeNull();
});

test('la lecture seule lit les listes SANS les adresses en clair, et ne modifie rien', function () {
    $id = lmCreer($this);
    $this->postJson("/api/v1/listes-manuelles/{$id}/membres", ['company_ids' => [$this->salon], 'contact_ids' => [$this->personne]])->assertOk();

    // TÉMOIN : l'opérateur (qui a `contacts.view_pii`) lit les adresses.
    $clair = $this->getJson("/api/v1/listes-manuelles/{$id}/membres")->assertOk()->getContent();
    expect($clair)->toContain('zia@zz-club.example.invalid')->toContain('accueil@zz-salon.example.invalid');

    $this->actingAs(lmCompte($this->ws, 'viewer'));
    $masque = $this->getJson("/api/v1/listes-manuelles/{$id}/membres")->assertOk();
    expect($masque->getContent())->not->toContain('zia@zz-club.example.invalid')->not->toContain('accueil@zz-salon.example.invalid')
        ->and($masque->json('meta.total'))->toBe(2);

    $this->postJson('/api/v1/listes-manuelles', ['nom' => 'ZZ interdite'])->assertForbidden();
    $this->postJson("/api/v1/listes-manuelles/{$id}/membres", ['company_ids' => [$this->club]])->assertForbidden();
    $this->postJson("/api/v1/listes-manuelles/{$id}/membres/retirer", ['company_ids' => [$this->salon]])->assertForbidden();
    $this->postJson("/api/v1/listes-manuelles/{$id}/import", ['contenu' => "siren\n123456789"])->assertForbidden();
});

test('une liste d un autre espace est introuvable (404, jamais 403)', function () {
    $etrangere = (int) DB::table('listes_manuelles')->insertGetId([
        'workspace_id' => $this->autreEspace, 'nom' => 'ZZ Ailleurs', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs(lmCompte($this->ws, 'operator'));

    $this->getJson("/api/v1/listes-manuelles/{$etrangere}")->assertNotFound();
    $this->getJson("/api/v1/listes-manuelles/{$etrangere}/membres")->assertNotFound();
    $this->postJson("/api/v1/listes-manuelles/{$etrangere}/membres", ['company_ids' => [$this->salon]])->assertNotFound();
    expect(DB::table('listes_manuelles_membres')->where('liste_id', $etrangere)->count())->toBe(0);
});

test('la base refuse un membre dont la fiche est d un autre espace (déclencheur)', function () {
    $liste = (int) DB::table('listes_manuelles')->insertGetId([
        'workspace_id' => $this->ws, 'nom' => 'ZZ Base', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => DB::transaction(fn () => DB::table('listes_manuelles_membres')->insert([
        'workspace_id' => $this->ws, 'liste_id' => $liste, 'company_id' => $this->etrangere, 'origine' => 'coche',
    ])))->toThrow(QueryException::class, 'n est pas de cet espace');

    // Et un membre ne désigne JAMAIS à la fois une organisation et une personne.
    expect(fn () => DB::transaction(fn () => DB::table('listes_manuelles_membres')->insert([
        'workspace_id' => $this->ws, 'liste_id' => $liste, 'company_id' => $this->club, 'contact_id' => $this->personne, 'origine' => 'coche',
    ])))->toThrow(QueryException::class);
});
