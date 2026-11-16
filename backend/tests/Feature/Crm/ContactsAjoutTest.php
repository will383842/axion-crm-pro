<?php

/**
 * Lot G1-C (cahier #619, § 5) — ajout rapide d'un contact : `GET /api/v1/crm/contacts/doublons` et
 * `POST /api/v1/crm/contacts`. Jamais de création d'entreprise ; une personne opposée ou effacée ne revient jamais
 * (échec fermé). HTTP réel pour les droits ; le service est joué sous le rôle applicatif ET sous le propriétaire.
 * Données fictives (example.test).
 */

use App\Crm\Contacts\AjoutContact;
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
    $this->espaceA = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'ws-aj-a', 'name' => 'Espace A', 'settings' => []]);
    $this->espaceB = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'ws-aj-b', 'name' => 'Espace B', 'settings' => []]);
    $this->entrepriseA = ajEntreprise($this->espaceA->id, '900000601', 'Alpha Test');
    $this->entrepriseB = ajEntreprise($this->espaceB->id, '900000602', 'Beta Test');
    $this->contactA = ajContact($this->espaceA->id, $this->entrepriseA, 'Durand', 'Alice', 'alice@example.test');
    $this->contactB = ajContact($this->espaceB->id, $this->entrepriseB, 'Martin', 'Bob', 'bob@example.test');
});

function ajActeur(string $espaceId, string $role): User
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

function ajEntreprise(string $espaceId, string $siren, string $nom): int
{
    return (int) DB::table('companies')->insertGetId([
        'workspace_id' => $espaceId, 'siren' => $siren, 'denomination' => $nom,
        'discovery_source' => 'site', 'quality_score' => 0, 'signals' => '{}', 'metadata' => '{}',
        'relation_type' => 'prospect', 'lifecycle_stage' => 'nouveau',
        'legal_basis' => 'legitimate_interest_b2b', 'field_origins' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function ajContact(string $espaceId, int $entrepriseId, string $nom, ?string $prenom, ?string $email, ?string $supprimeLe = null, ?string $role = null): int
{
    return (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $espaceId, 'company_id' => $entrepriseId, 'first_name' => $prenom, 'last_name' => $nom,
        'email' => $email, 'role' => $role, 'person_key' => CleDePersonne::pour($email), 'discovery_source' => 'site',
        'sources' => '["site"]', 'metadata' => '{}', 'legal_basis' => 'legitimate_interest_b2b', 'deleted_at' => $supprimeLe,
        'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
    ]);
}

/** @return array<string, mixed> */
function ajLigne(int $id): array
{
    return (array) DB::table('contacts')->where('id', $id)->first();
}

/** @return array<string, mixed> */
function ajSaisie(array $surcharge = []): array
{
    return $surcharge + ['first_name' => 'Claire', 'last_name' => 'Nouvelle', 'email' => 'claire@example.test', 'organisation' => 'Alpha Test', 'provenance' => 'salon'];
}

function ajOppose(string $email): void
{
    DB::table('email_suppressions')->insert(['scope' => 'business', 'email_hash' => ListeSuppression::empreinte($email), 'reason' => 'manual', 'source' => 'test']);
}

test('1. viewer → 403 (aucune ligne créée) sur POST ; operator → 201 ; viewer peut lire les doublons', function () {
    $avant = DB::table('contacts')->count();
    $this->actingAs(ajActeur($this->espaceA->id, 'viewer'));
    $this->postJson('/api/v1/crm/contacts', ajSaisie())->assertForbidden();
    expect(DB::table('contacts')->count())->toBe($avant);

    $this->actingAs(ajActeur($this->espaceA->id, 'operator'));
    $r = $this->postJson('/api/v1/crm/contacts', ajSaisie())->assertCreated()->assertJsonPath('resultat', 'cree');
    expect(DB::table('contacts')->count())->toBe($avant + 1);
    $l = ajLigne((int) $r->json('fiche.id'));
    expect($l['workspace_id'])->toBe($this->espaceA->id)->and($l['company_id'])->toBe($this->entrepriseA)
        ->and($l['discovery_source'])->toBe('console')->and($l['sources'])->toBe('["console:salon"]')
        ->and($l['legal_basis'])->toBe('legitimate_interest_b2b')->and($l['person_key'])->toBe(CleDePersonne::pour('claire@example.test'));
    expect($r->json('fiche'))->toHaveKeys(['id', 'company_id', 'first_name', 'last_name', 'email', 'fonction', 'organisation', 'relation_type', 'lifecycle_stage', 'provenance', 'person_key', 'retire_le', 'ne_plus_ecrire', 'verifie_il_y_a_jours', 'rappel_le', 'rappel_note', 'created_at']);
    expect($r->json('fiche.ne_plus_ecrire'))->toBeFalse()->and($r->json('fiche.organisation'))->toBe('Alpha Test');
});

test('2. entreprise d’un autre espace → 422 organisation_introuvable, rien créé ; jamais de création d’entreprise', function () {
    $this->actingAs(ajActeur($this->espaceA->id, 'operator'));
    $c = DB::table('contacts')->count();
    $e = DB::table('companies')->count();
    $this->postJson('/api/v1/crm/contacts', ajSaisie(['organisation' => null, 'company_id' => $this->entrepriseB]))
        ->assertStatus(422)->assertJsonPath('error', 'organisation_introuvable');
    $this->postJson('/api/v1/crm/contacts', ajSaisie(['organisation' => 'Beta Test']))->assertStatus(422)->assertJsonPath('error', 'organisation_introuvable');
    $this->postJson('/api/v1/crm/contacts', ajSaisie(['organisation' => '900000602']))->assertStatus(422)->assertJsonPath('error', 'organisation_introuvable');
    $this->postJson('/api/v1/crm/contacts', ajSaisie(['organisation' => 'Inconnue SA']))->assertStatus(422)->assertJsonPath('error', 'organisation_introuvable');
    expect(DB::table('contacts')->count())->toBe($c)->and(DB::table('companies')->count())->toBe($e);

    // Par SIREN de l'espace : accepté.
    $this->postJson('/api/v1/crm/contacts', ajSaisie(['organisation' => '900000601']))->assertCreated();
});

test('2b. entreprise en corbeille → organisation_introuvable', function () {
    DB::table('companies')->where('id', $this->entrepriseA)->update(['deleted_at' => now()]);
    $this->actingAs(ajActeur($this->espaceA->id, 'operator'));
    $this->postJson('/api/v1/crm/contacts', ajSaisie(['organisation' => null, 'company_id' => $this->entrepriseA]))->assertStatus(422)->assertJsonPath('error', 'organisation_introuvable');
});

test('3. deux entreprises de même nom → 422 organisation_ambigue', function () {
    ajEntreprise($this->espaceA->id, '900000603', 'alpha  TEST');
    $this->actingAs(ajActeur($this->espaceA->id, 'operator'));
    $c = DB::table('contacts')->count();
    $this->postJson('/api/v1/crm/contacts', ajSaisie())->assertStatus(422)->assertJsonPath('error', 'organisation_ambigue');
    expect(DB::table('contacts')->count())->toBe($c);
});

test('4. adresse en opposition → 422 opposition, aucune ligne', function () {
    ajOppose('claire@example.test');
    $this->actingAs(ajActeur($this->espaceA->id, 'operator'));
    $c = DB::table('contacts')->count();
    $this->postJson('/api/v1/crm/contacts', ajSaisie())->assertStatus(422)->assertJsonPath('error', 'opposition');
    $this->postJson('/api/v1/crm/contacts', ajSaisie(['doublon' => 'garder']))->assertStatus(422)->assertJsonPath('error', 'opposition');
    expect(DB::table('contacts')->count())->toBe($c);
});

test('4b. personne ayant demandé l’effacement (RGPD) ou déjà retirée du registre → ne revient jamais', function () {
    app(GdprErasureService::class)->erase('alice@example.test');
    $this->actingAs(ajActeur($this->espaceA->id, 'operator'));
    $c = DB::table('contacts')->count();
    $this->postJson('/api/v1/crm/contacts', ajSaisie(['first_name' => 'Alice', 'last_name' => 'Durand', 'email' => 'alice@example.test']))
        ->assertStatus(422)->assertJsonPath('error', 'opposition');

    // Registre des retraits : même personne (nom) de la même entreprise, autre adresse.
    $retire = ajContact($this->espaceA->id, $this->entrepriseA, 'Oubliee', 'Zoe', 'zoe@example.test');
    DB::table('contacts')->where('id', $retire)->update(['sources' => '["federations-2026"]']);
    DB::table('contacts')->where('id', $retire)->delete(); // mise en place du test : le déclencheur alimente `contacts_retires` (fiches de fédérations)
    $c = DB::table('contacts')->count();
    $this->postJson('/api/v1/crm/contacts', ajSaisie(['first_name' => 'Zoe', 'last_name' => 'Oubliee', 'email' => 'zoe.autre@example.test']))
        ->assertStatus(422)->assertJsonPath('error', 'opposition');
    expect(DB::table('contacts')->count())->toBe($c);
});

test('5. même adresse sans « doublon » → 409 avec la liste ; fusionner complète les champs vides, jamais les remplis', function () {
    $vide = ajContact($this->espaceA->id, $this->entrepriseA, 'Vide', null, 'vide@example.test');
    $this->actingAs(ajActeur($this->espaceA->id, 'operator'));
    $c = DB::table('contacts')->count();

    $r = $this->postJson('/api/v1/crm/contacts', ajSaisie(['first_name' => 'Prenom', 'last_name' => 'Autre', 'email' => 'vide@example.test', 'fonction' => 'Directrice']))
        ->assertStatus(409);
    expect($r->json('doublons.0'))->toMatchArray(['id' => $vide, 'raison' => 'meme_email'])->and($r->json('doublons.0'))->toHaveKeys(['person_key', 'nom_complet', 'email', 'organisation']);
    expect(DB::table('contacts')->count())->toBe($c);

    $avant = ajLigne($vide);
    $this->postJson('/api/v1/crm/contacts', ajSaisie(['first_name' => 'Prenom', 'last_name' => 'Autre', 'email' => 'vide@example.test', 'fonction' => 'Directrice', 'doublon' => 'fusionner', 'fusionner_avec' => $vide]))
        ->assertOk()->assertJsonPath('resultat', 'fusionne')->assertJsonPath('fiche.id', $vide);
    $l = ajLigne($vide);
    expect(DB::table('contacts')->count())->toBe($c)->and($l['first_name'])->toBe('Prenom')->and($l['role'])->toBe('Directrice')
        ->and($l['last_name'])->toBe('Vide')->and($l['sources'])->toContain('console:salon');

    // Champs remplis : INCHANGÉS.
    $this->postJson('/api/v1/crm/contacts', ajSaisie(['first_name' => 'Ecrase', 'last_name' => 'Ecrase', 'email' => 'vide@example.test', 'fonction' => 'Ecrase', 'doublon' => 'fusionner', 'fusionner_avec' => $vide]))->assertOk();
    $l2 = ajLigne($vide);
    expect($l2['first_name'])->toBe('Prenom')->and($l2['role'])->toBe('Directrice')->and($l2['last_name'])->toBe('Vide')->and($l2['email'])->toBe('vide@example.test');
    expect($avant['id'])->toBe($vide);
});

test('5b. fusionner : cible d’un autre espace ou en corbeille → 404 ; cible opposée → 422 ; garder → créé', function () {
    $this->actingAs(ajActeur($this->espaceA->id, 'operator'));
    $this->postJson('/api/v1/crm/contacts', ajSaisie(['email' => 'alice@example.test', 'doublon' => 'fusionner', 'fusionner_avec' => $this->contactB]))->assertNotFound();
    $corb = ajContact($this->espaceA->id, $this->entrepriseA, 'Corbeille', 'Cora', 'cora@example.test', now()->toDateTimeString());
    $this->postJson('/api/v1/crm/contacts', ajSaisie(['email' => 'alice@example.test', 'doublon' => 'fusionner', 'fusionner_avec' => $corb]))->assertNotFound();
    $this->postJson('/api/v1/crm/contacts', ajSaisie(['email' => 'alice@example.test', 'doublon' => 'fusionner']))->assertStatus(422);

    ajOppose('alice@example.test');
    $this->postJson('/api/v1/crm/contacts', ajSaisie(['email' => 'autre@example.test', 'doublon' => 'fusionner', 'fusionner_avec' => $this->contactA]))
        ->assertStatus(422)->assertJsonPath('error', 'opposition');
    DB::table('email_suppressions')->delete();

    $c = DB::table('contacts')->count();
    $this->postJson('/api/v1/crm/contacts', ajSaisie(['email' => 'alice@example.test', 'doublon' => 'garder']))->assertCreated();
    expect(DB::table('contacts')->count())->toBe($c + 1);
});

test('6. même nom + entreprise qu’un contact en corbeille → 409, aucune exception ; même nom vivant → 409 doublons, garder → 409', function () {
    ajContact($this->espaceA->id, $this->entrepriseA, 'Nouvelle', 'Claire', 'ancienne@example.test', now()->toDateTimeString());
    $this->actingAs(ajActeur($this->espaceA->id, 'operator'));
    $c = DB::table('contacts')->count();
    $this->postJson('/api/v1/crm/contacts', ajSaisie())->assertStatus(409)->assertJsonPath('error', 'doublon');
    $this->postJson('/api/v1/crm/contacts', ajSaisie(['doublon' => 'garder']))->assertStatus(409)->assertJsonPath('error', 'doublon');
    expect(DB::table('contacts')->count())->toBe($c);

    $r = $this->postJson('/api/v1/crm/contacts', ajSaisie(['first_name' => 'Alice', 'last_name' => 'Durand', 'email' => 'autre@example.test']))->assertStatus(409);
    expect($r->json('doublons.0.raison'))->toBe('meme_nom');
    $this->postJson('/api/v1/crm/contacts', ajSaisie(['first_name' => 'Alice', 'last_name' => 'Durand', 'email' => 'autre@example.test', 'doublon' => 'garder']))->assertStatus(409)->assertJsonPath('error', 'doublon');
    expect(DB::table('contacts')->count())->toBe($c);
});

test('7. provenance hors liste ou absente, nom vide, adresse invalide → 422', function () {
    $this->actingAs(ajActeur($this->espaceA->id, 'operator'));
    $c = DB::table('contacts')->count();
    $this->postJson('/api/v1/crm/contacts', ajSaisie(['provenance' => 'inconnue']))->assertStatus(422);
    $this->postJson('/api/v1/crm/contacts', ajSaisie(['provenance' => null]))->assertStatus(422);
    $this->postJson('/api/v1/crm/contacts', ajSaisie(['last_name' => '']))->assertStatus(422);
    $this->postJson('/api/v1/crm/contacts', ajSaisie(['email' => 'pas-une-adresse']))->assertStatus(422);
    expect(DB::table('contacts')->count())->toBe($c);
});

test('8. GET doublons : viewer → adresses masquées, jamais un contact de B ; sans droit → 403', function () {
    ajContact($this->espaceB->id, $this->entrepriseB, 'Durand', 'Alice', 'alice@example.test');
    $this->actingAs(ajActeur($this->espaceA->id, 'viewer'));
    $r = $this->getJson('/api/v1/crm/contacts/doublons?' . http_build_query(['email' => 'alice@example.test', 'prenom' => 'Alice', 'nom' => 'Durand', 'organisation' => 'Alpha Test']))->assertOk();
    expect($r->json('data'))->toHaveCount(1)->and($r->json('data.0.id'))->toBe($this->contactA)->and($r->json('data.0.raison'))->toBe('meme_email')
        ->and($r->json('data.0.email'))->not->toContain('alice@example.test')->and($r->json('data.0.nom_complet'))->toBe('Alice Durand');
    expect($this->getJson('/api/v1/crm/contacts/doublons?email=bob@example.test')->json('data'))->toBe([]);
    // Même nom seulement (organisation de l'espace) : meme_nom.
    $n = $this->getJson('/api/v1/crm/contacts/doublons?' . http_build_query(['email' => 'autre@example.test', 'prenom' => 'Alice', 'nom' => 'Durand', 'organisation' => 'Alpha Test']))->assertOk();
    expect($n->json('data.0.raison'))->toBe('meme_nom');
});

test('9. le service sous rôle applicatif ET propriétaire : cloisonné, entreprise de B refusée, rien d’écrit', function (string $mode) {
    $ws = $this->espaceA->id;
    $joue = function (callable $f) use ($mode, $ws) {
        DB::beginTransaction();
        try {
            if ($mode === 'app') {
                WorkspaceContext::set($ws);
                DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
            } else {
                WorkspaceContext::set($ws);
            }

            return $f(new AjoutContact);
        } finally {
            DB::rollBack();
        }
    };
    $nb = DB::table('contacts')->count();

    $b = $joue(fn (AjoutContact $s) => $s->ajouter($ws, ['last_name' => 'X', 'company_id' => $this->entrepriseB, 'provenance' => 'salon'], 'u1')['etat']);
    expect($b)->toBe('organisation_introuvable');
    $ok = $joue(function (AjoutContact $s) use ($ws) {
        $r = $s->ajouter($ws, ['first_name' => 'Eve', 'last_name' => 'Test', 'email' => 'eve@example.test', 'organisation' => 'Alpha Test', 'provenance' => 'site'], 'u1');

        return [$r['etat'], DB::table('contacts')->where('workspace_id', $ws)->count()];
    });
    expect($ok)->toBe(['cree', 2]);
    $d = $joue(fn (AjoutContact $s) => array_column($s->doublons($ws, 'bob@example.test', null, 'Martin', $this->entrepriseA), 'id'));
    expect($d)->toBe([]);
    expect(DB::table('contacts')->count())->toBe($nb);
})->with(['app', 'proprio']);

test('10. journal : identifiant et provenance seulement, jamais l’adresse ni le nom', function () {
    $this->actingAs(ajActeur($this->espaceA->id, 'operator'));
    $this->postJson('/api/v1/crm/contacts', ajSaisie())->assertCreated();
    $brut = json_encode(DB::table('business_events')->where('workspace_id', $this->espaceA->id)->get()->all());
    expect($brut)->toContain('contact.ajoute')->toContain('salon')->not->toContain('claire@example.test')->not->toContain('Nouvelle');
});

test('11. test de source : AjoutContact ne supprime rien', function () {
    $src = (string) file_get_contents(base_path('app/Crm/Contacts/AjoutContact.php'));
    expect(preg_match('/forceDelete|->delete\(|DELETE\s+FROM/i', $src))->toBe(0);
});

test('12. un contact en corbeille n’est jamais proposé comme doublon (même adresse) et ne bloque pas l’ajout', function () {
    ajContact($this->espaceA->id, $this->entrepriseA, 'Corbeille', 'Cora', 'cora@example.test', now()->toDateTimeString());
    $this->actingAs(ajActeur($this->espaceA->id, 'operator'));
    expect($this->getJson('/api/v1/crm/contacts/doublons?email=cora@example.test')->assertOk()->json('data'))->toBe([]);
    $this->postJson('/api/v1/crm/contacts', ajSaisie(['email' => 'cora@example.test']))->assertCreated();
});

test('13. fusionner vers une fiche dont l’entreprise est en corbeille → 404, fiche inchangée', function () {
    $e2 = ajEntreprise($this->espaceA->id, '900000604', 'Gamma Test');
    $vide = ajContact($this->espaceA->id, $e2, 'Vide', null, 'gamma@example.test');
    DB::table('companies')->where('id', $e2)->update(['deleted_at' => now()]);
    $avant = ajLigne($vide);
    $this->actingAs(ajActeur($this->espaceA->id, 'operator'));
    $this->postJson('/api/v1/crm/contacts', ajSaisie(['first_name' => 'Zed', 'email' => 'gamma@example.test', 'doublon' => 'fusionner', 'fusionner_avec' => $vide]))->assertNotFound();
    expect(ajLigne($vide))->toBe($avant);
});
