<?php

/**
 * API DES FÉDÉRATIONS — liste filtrable, fiche avec arborescence, contacts et
 * événements, démarche « partenariat » (chantier 3, 2026-09-29). Fixtures
 * FICTIVES (dépôt public).
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
    $this->seed(PermissionsAndRolesSeeder::class);

    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-fed-api', 'name' => 'WS', 'settings' => [],
    ]);
    $this->user = fedApiCompte($this->workspace->id, 'admin');
    $this->actingAs($this->user);

    $ws = $this->workspace->id;
    $this->nationale = fedApiFiche($ws, 'ZZ Nationale du batiment', [
        'famille' => 'federation_syndicat_pro', 'niveau' => 'national', 'secteurs' => '{btp}', 'tailles_adherents' => '{tpe,pme}',
    ], ['region_code' => '11', 'department_code' => '75', 'email_generic' => 'contact@zz-nationale.example.invalid', 'phone' => '+33600000001']);
    $this->antenne = fedApiFiche($ws, 'ZZ Antenne du Rhone', [
        'famille' => 'federation_syndicat_pro', 'niveau' => 'departemental', 'secteurs' => '{btp}', 'tailles_adherents' => '{tpe}',
        'pertinence' => 'moyenne', 'contactabilite' => 'telephone_seulement',
    ], ['region_code' => '84', 'department_code' => '69']);
    DB::table('federations')->where('company_id', $this->antenne)->update(['parent_company_id' => $this->nationale]);
    $this->ordre = fedApiFiche($ws, 'ZZ Ordre fictif', [
        'famille' => 'ordre', 'niveau' => 'regional', 'secteurs' => '{sante}', 'tailles_adherents' => '{}', 'pertinence' => 'faible',
    ], ['region_code' => '84', 'department_code' => '38']);

    // Un événement à venir organisé par l'antenne.
    $evt = (int) DB::table('events')->insertGetId([
        'workspace_id' => $ws, 'external_ref' => 'zz-fed-api-ag', 'nom' => 'ZZ Assemblée générale',
        'type' => 'conference', 'date_debut' => now()->addDays(20)->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('event_organizers')->insert(['event_id' => $evt, 'company_id' => $this->antenne, 'workspace_id' => $ws, 'created_at' => now()]);

    DB::table('contacts')->insert([
        'workspace_id' => $ws, 'company_id' => $this->nationale, 'first_name' => 'Zoe', 'last_name' => 'ZZPRESIDENTE',
        'role' => 'Présidente', 'email' => 'zoe.zz@zz-nationale.example.invalid', 'created_at' => now(), 'updated_at' => now(),
    ]);
});

function fedApiCompte(string $workspaceId, string $role): User
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

/**
 * @param  array<string, mixed>  $federation
 * @param  array<string, mixed>  $fiche
 */
function fedApiFiche(string $workspaceId, string $nom, array $federation, array $fiche = []): int
{
    static $seq = 0;
    $seq++;
    $id = (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $workspaceId, 'siren' => str_pad((string) (940000000 + $seq), 9, '0', STR_PAD_LEFT),
        'entity_nature' => 'federation', 'denomination' => $nom, 'created_at' => now(), 'updated_at' => now(),
    ], $fiche));
    DB::table('federations')->insert(array_merge([
        'company_id' => $id, 'workspace_id' => $workspaceId, 'pertinence' => 'haute', 'contactabilite' => 'email_verifie',
    ], $federation));

    return $id;
}

/** @return list<int> */
function fedApiIds(TestCase $t, string $query): array
{
    return collect($t->getJson('/api/v1/federations' . $query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
}

test('la liste filtre par famille, niveau, secteur represente, region, departement, taille, pertinence, contactabilite', function () {
    $tous = [$this->nationale, $this->antenne, $this->ordre];
    sort($tous);

    expect(fedApiIds($this, ''))->toBe($tous)
        ->and(fedApiIds($this, '?famille=ordre'))->toBe([$this->ordre])
        ->and(fedApiIds($this, '?niveau=departemental'))->toBe([$this->antenne])
        ->and(fedApiIds($this, '?secteur=sante'))->toBe([$this->ordre])
        ->and(fedApiIds($this, '?region=84'))->toBe([$this->antenne, $this->ordre])
        ->and(fedApiIds($this, '?departement=69'))->toBe([$this->antenne])
        ->and(fedApiIds($this, '?taille_adherents=pme'))->toBe([$this->nationale])
        ->and(fedApiIds($this, '?pertinence=faible'))->toBe([$this->ordre])
        ->and(fedApiIds($this, '?contactabilite=telephone_seulement'))->toBe([$this->antenne])
        ->and(fedApiIds($this, '?evenement_a_venir=1'))->toBe([$this->antenne])
        ->and(fedApiIds($this, '?tete=' . $this->nationale))->toBe([$this->antenne])
        ->and(fedApiIds($this, '?q=Rhone'))->toBe([$this->antenne]);
});

test('une valeur hors referentiel est refusee', function () {
    $this->getJson('/api/v1/federations?famille=club_de_foot')->assertStatus(422);
    $this->getJson('/api/v1/federations?secteur=non_classe')->assertStatus(422);
});

test('la liste ne montre ni un autre espace ni une fiche a la corbeille, et ne rend aucune coordonnee', function () {
    $autre = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'ws-fed-api-autre', 'name' => 'Autre', 'settings' => []]);
    $ailleurs = fedApiFiche($autre->id, 'ZZ Ailleurs', ['famille' => 'ordre', 'niveau' => 'national']);
    DB::table('companies')->where('id', $this->ordre)->update(['deleted_at' => now()]);

    $r = $this->getJson('/api/v1/federations')->assertOk();
    $ids = collect($r->json('data'))->pluck('id')->all();

    expect($ids)->not->toContain($ailleurs)->not->toContain($this->ordre)
        ->and((string) $r->getContent())->not->toContain('zz-nationale.example.invalid');
    $this->getJson('/api/v1/federations/' . $ailleurs)->assertNotFound();
});

test('la ligne de liste porte la tete, le nombre d antennes et l evenement a venir', function () {
    $lignes = collect($this->getJson('/api/v1/federations')->assertOk()->json('data'))->keyBy('id');

    expect($lignes[$this->antenne]['tete'])->toBe(['id' => $this->nationale, 'denomination' => 'ZZ Nationale du batiment'])
        ->and($lignes[$this->antenne]['evenement_a_venir'])->toBeTrue()
        ->and($lignes[$this->nationale]['nb_antennes'])->toBe(1)
        ->and($lignes[$this->nationale]['secteurs'])->toBe(['btp'])
        ->and($lignes[$this->nationale]['tailles_adherents'])->toBe(['tpe', 'pme']);
});

test('la fiche rend l arborescence, les contacts et les evenements', function () {
    $antenne = $this->getJson('/api/v1/federations/' . $this->antenne)->assertOk();
    expect($antenne->json('ascendants.0.id'))->toBe($this->nationale)
        ->and($antenne->json('evenements.0.nom'))->toBe('ZZ Assemblée générale');

    $nationale = $this->getJson('/api/v1/federations/' . $this->nationale)->assertOk();
    expect($nationale->json('antennes.0.id'))->toBe($this->antenne)
        ->and($nationale->json('contacts.0.last_name'))->toBe('ZZPRESIDENTE')
        ->and($nationale->json('contacts.0.email'))->toBe('zoe.zz@zz-nationale.example.invalid')
        ->and($nationale->json('contacts.0.informe'))->toBeFalse()
        ->and($nationale->json('email_generic'))->toBe('contact@zz-nationale.example.invalid');
});

test('un compte en lecture seule lit la fiche avec les coordonnees masquees et sans la note', function () {
    DB::table('federations')->where('company_id', $this->nationale)->update(['partenariat_note' => 'ZZ rappeler au 0600000001']);
    $this->actingAs(fedApiCompte($this->workspace->id, 'viewer'));

    $r = $this->getJson('/api/v1/federations/' . $this->nationale)->assertOk();
    $corps = (string) $r->getContent();

    expect($r->json('contacts.0.email'))->toBe('z***@zz-nationale.example.invalid')
        ->and($r->json('email_generic'))->toBe('c***@zz-nationale.example.invalid')
        ->and($r->json('partenariat_note'))->toBeNull()
        ->and($corps)->not->toContain('zoe.zz@')
        ->and($corps)->not->toContain('+33600000001')
        ->and($corps)->not->toContain('0600000001');
});

test('faire avancer le partenariat ecrit l etat ET une ligne d historique ; la note seule n en ecrit pas', function () {
    $this->patchJson('/api/v1/federations/' . $this->nationale . '/demarche', ['partenariat_note' => 'ZZ premier contact'])->assertOk();
    expect(DB::table('activities')->where('subject_type', 'company')->where('subject_id', $this->nationale)->count())->toBe(0);

    $r = $this->patchJson('/api/v1/federations/' . $this->nationale . '/demarche', [
        'partenariat' => 'propose', 'partenariat_relance_at' => now()->addDays(7)->toDateString(),
    ])->assertOk();

    expect($r->json('partenariat'))->toBe('propose')
        ->and($r->json('historique.0.kind'))->toBe('partenariat_propose')
        ->and(DB::table('federations')->where('company_id', $this->nationale)->value('partenariat'))->toBe('propose')
        ->and(DB::table('activities')->where('subject_type', 'company')->where('subject_id', $this->nationale)->value('kind'))->toBe('partenariat_propose');

    $this->patchJson('/api/v1/federations/' . $this->nationale . '/demarche', ['partenariat' => 'kermesse'])->assertStatus(422);
});

test('un compte en lecture seule ne peut pas modifier la demarche, un autre espace non plus', function () {
    $this->actingAs(fedApiCompte($this->workspace->id, 'viewer'));
    $this->patchJson('/api/v1/federations/' . $this->nationale . '/demarche', ['partenariat' => 'propose'])->assertForbidden();

    $this->actingAs($this->user);
    $autre = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'ws-fed-api-b', 'name' => 'B', 'settings' => []]);
    $etrangere = fedApiFiche($autre->id, 'ZZ Etrangere', ['famille' => 'ordre', 'niveau' => 'national']);
    $this->patchJson('/api/v1/federations/' . $etrangere . '/demarche', ['partenariat' => 'propose'])->assertNotFound();

    expect(DB::table('federations')->where('company_id', $this->nationale)->value('partenariat'))->toBe('aucun')
        ->and(DB::table('federations')->where('company_id', $etrangere)->value('partenariat'))->toBe('aucun');
});

test('R5 — un compte en lecture seule ne recoit ni le LinkedIn des personnes ni l adresse postale ; l admin, si (temoin)', function () {
    DB::table('companies')->where('id', $this->nationale)->update([
        'address' => '1 RUE ZZ FICTIVE', 'postcode' => '75001',
        // S2 : des canaux remplis, de chaque TYPE de donnée.
        'signals' => json_encode(['contact_channels' => [
            'emails' => ['jean.zzcanal@zz-nationale.example.invalid'],
            'details' => ['jean.zzcanal@zz-nationale.example.invalid' => ['type' => 'nominatif', 'verifie_le' => '2026-09-28']],
            'phones' => ['06 00 00 00 55'],
            'linkedin' => ['https://www.linkedin.com/in/zz-jean-canal', 'https://www.linkedin.com/company/zz-nationale'],
            'sites' => ['https://zz-nationale-bis.example.invalid'],
        ]]),
    ]);
    DB::table('contacts')->where('company_id', $this->nationale)->update(['linkedin_url' => 'https://www.linkedin.com/in/zz-presidente']);

    $admin = $this->getJson('/api/v1/federations/' . $this->nationale)->assertOk();
    expect($admin->json('address'))->toBe('1 RUE ZZ FICTIVE')
        ->and($admin->json('contacts.0.linkedin_url'))->toBe('https://www.linkedin.com/in/zz-presidente');

    $this->actingAs(fedApiCompte($this->workspace->id, 'viewer'));
    $r = $this->getJson('/api/v1/federations/' . $this->nationale)->assertOk();
    $corps = (string) $r->getContent();

    expect($r->json('address'))->toBeNull()
        ->and($r->json('postcode'))->toBeNull()
        ->and($r->json('contacts.0.linkedin_url'))->toBeNull()
        ->and($corps)->not->toContain('zz-presidente')
        ->and($corps)->not->toContain('RUE ZZ FICTIVE')
        // S2 : les canaux, masqués par type.
        ->and($corps)->not->toContain('jean.zzcanal@')
        ->and($corps)->not->toContain('00 00 00 55')
        ->and($corps)->not->toContain('zz-jean-canal')
        ->and($r->json('canaux.emails.0.email'))->toBe('j***@zz-nationale.example.invalid')
        ->and($r->json('canaux.linkedin'))->toBe(['https://www.linkedin.com/company/zz-nationale'])
        ->and($r->json('canaux.sites'))->toBe(['https://zz-nationale-bis.example.invalid']);

    // Témoin : l'admin lit les canaux en clair.
    $this->actingAs($this->user);
    $clair = (string) $this->getJson('/api/v1/federations/' . $this->nationale)->assertOk()->getContent();
    expect($clair)->toContain('jean.zzcanal@')->toContain('zz-jean-canal');
});

test('D7 — un compte qui ne voit pas les coordonnees ne peut pas effacer la note en renvoyant la valeur masquee', function () {
    DB::table('federations')->where('company_id', $this->nationale)->update(['partenariat_note' => 'ZZ note de Will']);

    // Droit de modifier la démarche, SANS le droit de voir les coordonnées.
    $compte = User::create([
        'id' => (string) Str::uuid(), 'email' => 'modif-' . Str::random(6) . '@example.invalid', 'name' => 'ZZ modif',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $this->workspace->id,
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($this->workspace->id);
    $compte->assignRole('viewer');
    $compte->givePermissionTo('companies.update');
    $this->actingAs($compte);

    // Il a lu la fiche : la note lui arrive vide. Son formulaire la renvoie vide.
    expect($this->getJson('/api/v1/federations/' . $this->nationale)->assertOk()->json('partenariat_note'))->toBeNull();
    $this->patchJson('/api/v1/federations/' . $this->nationale . '/demarche', [
        'partenariat' => 'propose', 'partenariat_note' => null,
    ])->assertOk();

    expect(DB::table('federations')->where('company_id', $this->nationale)->value('partenariat_note'))->toBe('ZZ note de Will')
        ->and(DB::table('federations')->where('company_id', $this->nationale)->value('partenariat'))->toBe('propose');
});

test('un organisme SANS SIREN est dans la liste, et sa fiche rend son identifiant ; une fiche a SIREN n en rend pas', function () {
    $section = fedApiFiche($this->workspace->id, 'ZZ Union departementale', [
        'famille' => 'confederation', 'niveau' => 'departemental', 'secteurs' => '{interprofessionnel}',
    ], ['siren' => null, 'country_code' => 'FR', 'foreign_id' => 'section:zz-api:69', 'department_code' => '69']);

    expect(fedApiIds($this, '?famille=confederation'))->toBe([$section]);

    $fiche = $this->getJson('/api/v1/federations/' . $section)->assertOk();
    expect($fiche->json('siren'))->toBeNull()
        ->and($fiche->json('identifiant'))->toBe('section:zz-api:69');

    $temoin = $this->getJson('/api/v1/federations/' . $this->nationale)->assertOk();
    expect($temoin->json('siren'))->not->toBeNull()
        ->and($temoin->json('identifiant'))->toBeNull();
});
