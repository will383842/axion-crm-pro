<?php

/**
 * Lot G1-D (cahier #619, § 6) — import de contacts : `POST /api/v1/crm/contacts/import/controle`,
 * `POST /api/v1/crm/contacts/import`, `GET /api/v1/crm/contacts/import/{id}`. Le contrôle n'écrit rien ; le bilan et le
 * journal ne portent que des numéros de ligne, des codes et des compteurs ; une personne opposée ou retirée du registre
 * ne revient jamais (échec fermé). Données fictives (example.test).
 */

use App\Crm\Contacts\ImportContacts;
use App\Crm\Identite\CleDePersonne;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Rgpd\GdprErasureService;
use App\Support\ListeSuppression;
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
    $this->espaceA = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'ws-im-a', 'name' => 'Espace A', 'settings' => []]);
    $this->espaceB = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'ws-im-b', 'name' => 'Espace B', 'settings' => []]);
    $this->entrepriseA = imEntreprise($this->espaceA->id, '900000701', 'Alpha Import');
    $this->entrepriseB = imEntreprise($this->espaceB->id, '900000702', 'Beta Import');
    $this->contactA = imContact($this->espaceA->id, $this->entrepriseA, 'Durand', 'Alice', 'alice@example.test');
    imContact($this->espaceB->id, $this->entrepriseB, 'Martin', 'Bob', 'bob@example.test');
});

function imActeur(string $espaceId, string $role): User
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

function imEntreprise(string $espaceId, string $siren, string $nom): int
{
    return (int) DB::table('companies')->insertGetId([
        'workspace_id' => $espaceId, 'siren' => $siren, 'denomination' => $nom,
        'discovery_source' => 'site', 'quality_score' => 0, 'signals' => '{}', 'metadata' => '{}',
        'relation_type' => 'prospect', 'lifecycle_stage' => 'nouveau',
        'legal_basis' => 'legitimate_interest_b2b', 'field_origins' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function imContact(string $espaceId, int $entrepriseId, string $nom, ?string $prenom, ?string $email): int
{
    return (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $espaceId, 'company_id' => $entrepriseId, 'first_name' => $prenom, 'last_name' => $nom,
        'email' => $email, 'person_key' => CleDePersonne::pour($email), 'discovery_source' => 'site',
        'sources' => '["site"]', 'metadata' => '{}', 'legal_basis' => 'legitimate_interest_b2b',
        'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
    ]);
}

/** @return array<string, mixed> */
function imLigne(int $n, array $surcharge = []): array
{
    return $surcharge + ['ligne' => $n, 'prenom' => 'Claire', 'nom' => 'Nouvelle', 'email' => 'claire@example.test', 'organisation' => 'Alpha Import', 'fonction' => 'Directrice', 'provenance' => 'salon'];
}

/** Six lignes : 1 créable, 1 entreprise introuvable, 1 opposition, 1 doublon, 1 sans provenance, 1 au registre des retraits. */
function imSixLignes(): array
{
    return [
        imLigne(2),
        imLigne(3, ['prenom' => 'Denis', 'nom' => 'Perdu', 'email' => 'denis@example.test', 'organisation' => 'Inconnue SA']),
        imLigne(4, ['prenom' => 'Olga', 'nom' => 'Opposee', 'email' => 'olga@example.test']),
        imLigne(5, ['prenom' => 'Alice', 'nom' => 'Durand', 'email' => 'alice@example.test']),
        imLigne(6, ['prenom' => 'Sam', 'nom' => 'Sansorigine', 'email' => 'sam@example.test', 'provenance' => '']),
        imLigne(7, ['prenom' => 'Zoe', 'nom' => 'Oubliee', 'email' => 'zoe.autre@example.test']),
    ];
}

function imPreparerSix(): void
{
    DB::table('email_suppressions')->insert(['scope' => 'business', 'email_hash' => ListeSuppression::empreinte('olga@example.test'), 'reason' => 'manual', 'source' => 'test']);
    $ws = DB::table('workspaces')->where('slug', 'ws-im-a')->value('id');
    $e = (int) DB::table('companies')->where('workspace_id', $ws)->value('id');
    $retire = imContact($ws, $e, 'Oubliee', 'Zoe', 'zoe@example.test');
    DB::table('contacts')->where('id', $retire)->update(['sources' => '["federations-2026"]']);
    DB::table('contacts')->where('id', $retire)->delete(); // mise en place : le déclencheur alimente `contacts_retires`
}

test('1. viewer → 403 sur les trois routes ; aucune ligne créée ; operator → 200', function () {
    $avant = DB::table('contacts')->count();
    $this->actingAs(imActeur($this->espaceA->id, 'viewer'));
    $this->postJson('/api/v1/crm/contacts/import/controle', ['lignes' => [imLigne(2)]])->assertForbidden();
    $this->postJson('/api/v1/crm/contacts/import', ['lignes' => [imLigne(2)]])->assertForbidden();
    $this->getJson('/api/v1/crm/contacts/import/' . Str::uuid())->assertForbidden();
    expect(DB::table('contacts')->count())->toBe($avant);

    $this->actingAs(imActeur($this->espaceA->id, 'operator'));
    $this->postJson('/api/v1/crm/contacts/import/controle', ['lignes' => [imLigne(2)]])->assertOk()->assertJsonPath('a_creer', 1);
    expect(DB::table('contacts')->count())->toBe($avant);
});

test('2. le contrôle n’écrit rien (contacts, journal) — sous rôle applicatif ET propriétaire', function (string $mode) {
    imPreparerSix();
    $ws = $this->espaceA->id;
    DB::beginTransaction();
    try {
        WorkspaceContext::set($ws);
        if ($mode === 'app') {
            DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
        }
        $nb = DB::table('contacts')->count();
        $ev = DB::table('business_events')->count();
        $bilan = (new ImportContacts)->controle($ws, imSixLignes());
        expect($bilan['a_creer'])->toBe(1);
        expect(DB::table('contacts')->count())->toBe($nb)->and(DB::table('business_events')->count())->toBe($ev);
    } finally {
        DB::rollBack();
    }
})->with(['app', 'proprio']);

test('3. bilan exact sur six lignes', function () {
    imPreparerSix();
    $this->actingAs(imActeur($this->espaceA->id, 'operator'));
    $r = $this->postJson('/api/v1/crm/contacts/import/controle', ['lignes' => imSixLignes()])->assertOk();
    expect($r->json('total'))->toBe(6)->and($r->json('a_creer'))->toBe(1)
        ->and($r->json('refusees'))->toBe([
            ['ligne' => 3, 'raison' => 'organisation_introuvable'],
            ['ligne' => 4, 'raison' => 'opposition'],
            ['ligne' => 7, 'raison' => 'retire_auparavant'],
        ])
        ->and($r->json('doublons'))->toBe([['ligne' => 5, 'existant' => (string) $this->contactA]])
        ->and($r->json('sans_provenance'))->toBe([6]);
});

test('4. import : crée seulement la ligne créable, rapport terminé, relancer → 0 création', function () {
    imPreparerSix();
    $this->actingAs(imActeur($this->espaceA->id, 'operator'));
    $avant = DB::table('contacts')->where('workspace_id', $this->espaceA->id)->count();
    $r = $this->postJson('/api/v1/crm/contacts/import', ['lignes' => imSixLignes()])->assertOk();
    expect($r->json('statut'))->toBe('termine')->and($r->json('total'))->toBe(6)->and($r->json('traitees'))->toBe(6)
        ->and($r->json('rapport.crees'))->toBe(1)->and(Str::isUuid($r->json('id')))->toBeTrue();
    expect(DB::table('contacts')->where('workspace_id', $this->espaceA->id)->count())->toBe($avant + 1);
    $l = (array) DB::table('contacts')->where('workspace_id', $this->espaceA->id)->where('email', 'claire@example.test')->first();
    expect($l['company_id'])->toBe($this->entrepriseA)->and($l['discovery_source'])->toBe('console')->and($l['sources'])->toBe('["console:salon"]');
    // Aucune des lignes refusées ou doublons n'a été écrite.
    expect(DB::table('contacts')->where('email', 'olga@example.test')->count())->toBe(0)
        ->and(DB::table('contacts')->where('email', 'zoe.autre@example.test')->count())->toBe(0)
        ->and(DB::table('contacts')->where('email', 'sam@example.test')->count())->toBe(0);

    $r2 = $this->postJson('/api/v1/crm/contacts/import', ['lignes' => imSixLignes()])->assertOk();
    expect($r2->json('rapport.crees'))->toBe(0)
        ->and(DB::table('contacts')->where('workspace_id', $this->espaceA->id)->count())->toBe($avant + 1);
});

test('4b. import sous rôle applicatif ET propriétaire : même résultat, jamais l’espace B', function (string $mode) {
    imPreparerSix();
    $ws = $this->espaceA->id;
    DB::beginTransaction();
    try {
        WorkspaceContext::set($ws);
        if ($mode === 'app') {
            DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
        }
        $lignes = imSixLignes();
        $lignes[] = imLigne(8, ['prenom' => 'Eve', 'nom' => 'EspaceB', 'email' => 'eve@example.test', 'organisation' => 'Beta Import']);
        $rapport = (new ImportContacts)->importer($ws, $lignes, 'u1');
        expect($rapport['crees'])->toBe(1)->and($rapport['a_creer'])->toBe(1);
        expect(array_column($rapport['refusees'], 'raison', 'ligne')[8])->toBe('organisation_introuvable');
        expect(DB::table('contacts')->where('workspace_id', $ws)->where('email', 'claire@example.test')->count())->toBe(1)
            ->and(DB::table('contacts')->where('email', 'eve@example.test')->count())->toBe(0);
    } finally {
        DB::rollBack();
    }
})->with(['app', 'proprio']);

test('4c. deux lignes identiques dans le même fichier : une seule création', function () {
    $this->actingAs(imActeur($this->espaceA->id, 'operator'));
    $r = $this->postJson('/api/v1/crm/contacts/import', ['lignes' => [imLigne(2), imLigne(3)]])->assertOk();
    expect($r->json('rapport.crees'))->toBe(1)->and(DB::table('contacts')->where('email', 'claire@example.test')->count())->toBe(1);
});

test('5. bilan, rapport et journal : aucune adresse, aucun nom', function () {
    imPreparerSix();
    $this->actingAs(imActeur($this->espaceA->id, 'operator'));
    $c = $this->postJson('/api/v1/crm/contacts/import/controle', ['lignes' => imSixLignes()])->assertOk()->getContent();
    $i = $this->postJson('/api/v1/crm/contacts/import', ['lignes' => imSixLignes()])->assertOk();
    $suivi = $this->getJson('/api/v1/crm/contacts/import/' . $i->json('id'))->assertOk()->getContent();
    $journal = json_encode(DB::table('business_events')->where('workspace_id', $this->espaceA->id)->get()->all());
    $brut = $c . $i->getContent() . $suivi . $journal;
    foreach (['@example.test', 'claire', 'Nouvelle', 'Perdu', 'Opposee', 'olga', 'Durand', 'Alice', 'Sansorigine', 'Oubliee', 'Alpha Import'] as $valeur) {
        expect(stripos($brut, $valeur))->toBeFalse($valeur);
    }
    expect($journal)->toContain('contacts.import');
});

test('6. 1 001 lignes → 422 ; champ de plus de 300 caractères → 422 ; rien n’est écrit', function () {
    $this->actingAs(imActeur($this->espaceA->id, 'operator'));
    $nb = DB::table('contacts')->count();
    $trop = array_map(fn ($n) => imLigne($n + 2), range(1, 1001));
    $this->postJson('/api/v1/crm/contacts/import/controle', ['lignes' => $trop])->assertStatus(422);
    $this->postJson('/api/v1/crm/contacts/import', ['lignes' => $trop])->assertStatus(422);
    $this->postJson('/api/v1/crm/contacts/import', ['lignes' => [imLigne(2, ['nom' => str_repeat('x', 301)])]])->assertStatus(422);
    $this->postJson('/api/v1/crm/contacts/import', ['lignes' => []])->assertStatus(422);
    expect(DB::table('contacts')->count())->toBe($nb);
});

test('7. suivi : autre utilisateur ou autre espace → 404 ; identifiant inconnu → 404 ; le bon utilisateur lit son rapport', function () {
    $moi = imActeur($this->espaceA->id, 'operator');
    $this->actingAs($moi);
    $id = (string) $this->postJson('/api/v1/crm/contacts/import', ['lignes' => [imLigne(2)]])->assertOk()->json('id');
    $this->getJson('/api/v1/crm/contacts/import/' . $id)->assertOk()->assertJsonPath('statut', 'termine')->assertJsonPath('rapport.crees', 1);
    $this->getJson('/api/v1/crm/contacts/import/' . Str::uuid())->assertNotFound();

    $this->actingAs(imActeur($this->espaceA->id, 'operator'));
    $this->getJson('/api/v1/crm/contacts/import/' . $id)->assertNotFound();
    $this->actingAs(imActeur($this->espaceB->id, 'operator'));
    $this->getJson('/api/v1/crm/contacts/import/' . $id)->assertNotFound();
});

test('8. personne ayant demandé l’effacement (RGPD) : refusée au contrôle et à l’import', function () {
    app(GdprErasureService::class)->erase('alice@example.test');
    $this->actingAs(imActeur($this->espaceA->id, 'operator'));
    $ligne = imLigne(2, ['prenom' => 'Alice', 'nom' => 'Durand', 'email' => 'alice@example.test']);
    $nb = DB::table('contacts')->count();
    $c = $this->postJson('/api/v1/crm/contacts/import/controle', ['lignes' => [$ligne]])->assertOk();
    expect($c->json('a_creer'))->toBe(0)->and($c->json('refusees.0.ligne'))->toBe(2);
    $this->postJson('/api/v1/crm/contacts/import', ['lignes' => [$ligne]])->assertOk()->assertJsonPath('rapport.crees', 0);
    expect(DB::table('contacts')->count())->toBe($nb);
});

test('9. test de source : ImportContacts ne supprime rien', function () {
    $src = (string) file_get_contents(base_path('app/Crm/Contacts/ImportContacts.php'));
    expect(preg_match('/forceDelete|->delete\(|DELETE\s+FROM/i', $src))->toBe(0);
});
