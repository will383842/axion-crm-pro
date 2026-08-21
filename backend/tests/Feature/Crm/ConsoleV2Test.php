<?php

use App\Crm\Taxonomy;
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
    // Les roles doivent exister AVANT toute attribution.
    $this->seed(PermissionsAndRolesSeeder::class);
});

/**
 * CONSOLE CRM v2 (lot L6) — plan §2.10/§2.11, conception UX v2.
 *
 * Quatre familles de garanties, dans cet ordre de gravité :
 *
 *   1. INERTIE      — drapeau fermé, la console n'existe pas (404 partout) ;
 *   2. ÉTANCHÉITÉ   — un utilisateur business-only ne lit RIEN du vivier, et
 *                     le sait (403), au lieu de croire que le vivier est vide ;
 *   3. ARBITRAGE    — rattacher un événement orphelin crée la fiche personne
 *                     par le MÊME chemin de déduplication que l'ingestion ;
 *   4. NON-RECUL    — une action de masse ne fait jamais reculer une étape.
 *
 * Les points 1, 2 et 4 ont été vérifiés PAR LA ROUGEUR : la garde neutralisée,
 * le test échoue (sorties dans le rapport de lot). Une garde qui ne rougit pas
 * ne garde rien.
 */
beforeEach(function () {
    config(['crm.console_v2' => true]);

    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'ws-console',
        'name' => 'Console',
        'settings' => [],
    ]);

    $this->vivierId = (string) DB::table('workspaces')
        ->where('slug', Taxonomy::VIVIER_WORKSPACE_SLUG)
        ->value('id');

    $this->user = consoleUser($this->workspace->id);
    $this->actingAs($this->user);
});

// ── Fabriques ────────────────────────────────────────────────────────────────

function consoleUser(string $workspaceId, string $email = 'console@example.invalid'): User
{
    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => $email,
        'name' => 'Opérateur',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $workspaceId,
        'first_login_completed_at' => now(),
    ]);

    // ⚠️ LE ROLE EST OBLIGATOIRE DEPUIS QUE F36-001 EST BRANCHE.
    //
    // Cette suite mesure le METIER, pas les droits, et son utilisateur n'en
    // avait aucun. Tant qu'aucune route ne portait `permission:`, cela ne se
    // voyait pas ; depuis, elle recevait 403. On lui donne `admin` : le geste
    // teste ICI est celui d'un administrateur, et le lui refuser reviendrait a
    // mesurer la garde au lieu du produit. Les droits sont mesures a leur
    // place : `tests/Feature/Rgpd/CoucheAutorisationBrancheeTest.php`.
    setPermissionsTeamId($user->current_workspace_id);
    $user->assignRole('admin');

    consoleMembership($user->id, $workspaceId);

    return $user;
}

function consoleMembership(string $userId, string $workspaceId, string $role = 'owner'): void
{
    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $userId,
        'workspace_id' => $workspaceId,
        'role_slug' => $role,
        'invited_at' => now(),
        'joined_at' => now(),
    ]);
}

/** @param  array<string, mixed>  $overrides */
function consoleCompany(string $workspaceId, string $siren, array $overrides = []): int
{
    return (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $workspaceId,
        'siren' => $siren,
        'denomination' => 'ZZ TEST ' . $siren,
        'discovery_source' => 'site',
        'quality_score' => 0,
        'signals' => '{}',
        'metadata' => '{}',
        'relation_type' => 'prospect',
        'lifecycle_stage' => 'nouveau',
        'legal_basis' => 'legitimate_interest_b2b',
        'field_origins' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/** @param  array<string, mixed>  $overrides */
function consoleCandidate(string $vivierId, string $lastName, array $overrides = []): int
{
    return (int) DB::table('candidates')->insertGetId(array_merge([
        'workspace_id' => $vivierId,
        'last_name' => $lastName,
        'relation_type' => 'candidat_commercial',
        'lifecycle_stage' => 'nouveau',
        'legal_basis' => 'precontractual',
        'attributes' => '{}',
        'experiences' => '[]',
        'derniere_interaction_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/** @param  array<string, mixed>  $pendingMatch */
function consolePendingActivity(string $workspaceId, string $personKey, array $pendingMatch, ?string $ref = null): int
{
    return (int) DB::table('activities')->insertGetId([
        'workspace_id' => $workspaceId,
        'type' => 'form_submission',
        'kind' => 'form_submission',
        'occurred_at' => now()->subDay(),
        'person_key' => $personKey,
        'external_ref' => $ref ?? ('site:event:' . Str::uuid()),
        'subject_type' => null,
        'subject_id' => null,
        'title' => 'Formulaire — audit',
        'payload' => json_encode(['pending_match' => $pendingMatch], JSON_THROW_ON_ERROR),
        'created_at' => now(),
    ]);
}

function consoleTag(string $workspaceId, string $slug): int
{
    return (int) DB::table('tags')->insertGetId([
        'workspace_id' => $workspaceId,
        'slug' => $slug,
        'name' => $slug,
        'category' => 'intent',
        'kind' => 'manual',
        'rules' => '{}',
        'is_locked' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function consolePersonKey(string $seed): string
{
    return hash('sha256', $seed);
}

// ── 1. INERTIE — drapeau fermé, la console n'existe pas ─────────────────────

test('drapeau ferme : toutes les routes de la console repondent 404', function () {
    config(['crm.console_v2' => false]);

    $routes = [
        ['get', '/api/v1/crm/contacts-hub'],
        ['get', '/api/v1/crm/contacts-hub/counts'],
        ['get', '/api/v1/crm/candidates'],
        ['get', '/api/v1/crm/candidates/counts'],
        ['get', '/api/v1/crm/persons/' . consolePersonKey('x') . '/timeline'],
        ['get', '/api/v1/crm/arbitrage'],
        ['post', '/api/v1/crm/arbitrage/1/attach'],
        ['post', '/api/v1/crm/arbitrage/1/dismiss'],
        ['post', '/api/v1/crm/bulk'],
    ];

    foreach ($routes as [$method, $url]) {
        $response = $method === 'get' ? $this->getJson($url) : $this->postJson($url, []);
        expect($response->getStatusCode())->toBe(404, "attendu 404 sur {$method} {$url}");
    }
});

test('drapeau ferme : /config/features reste joignable et annonce console_v2 false', function () {
    config(['crm.console_v2' => false]);

    // C'est CETTE route qui dit au frontend de ne rien afficher. La mettre
    // derrière le drapeau qu'elle annonce serait circulaire.
    $this->getJson('/api/v1/config/features')
        ->assertOk()
        ->assertJsonPath('console_v2', false)
        ->assertJsonPath('universes.business', false)
        ->assertJsonPath('universes.vivier', false);
});

test('drapeau ouvert : /config/features annonce les univers accessibles', function () {
    $this->getJson('/api/v1/config/features')
        ->assertOk()
        ->assertJsonPath('console_v2', true)
        ->assertJsonPath('universes.business', true)
        // Aucune ligne user_workspaces vers le vivier → l'entrée de navigation
        // ne doit même pas exister côté frontend.
        ->assertJsonPath('universes.vivier', false);
});

// ── 2. HUB DE CONTACTS ──────────────────────────────────────────────────────

test('contacts-hub liste les entreprises du workspace et filtre par type', function () {
    consoleCompany($this->workspace->id, '900000201', ['relation_type' => 'client', 'lifecycle_stage' => 'client']);
    consoleCompany($this->workspace->id, '900000202', ['relation_type' => 'prospect', 'lifecycle_stage' => 'qualifie']);

    $all = $this->getJson('/api/v1/crm/contacts-hub?temperature=tous')->assertOk();
    expect($all->json('data'))->toHaveCount(2);

    $clients = $this->getJson('/api/v1/crm/contacts-hub?relation_type=client&temperature=tous')->assertOk();
    expect($clients->json('data'))->toHaveCount(1);
    expect($clients->json('data.0.siren'))->toBe('900000201');
});

test('contacts-hub ne voit jamais les entreprises d un autre workspace', function () {
    $autre = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-autre', 'name' => 'Autre', 'settings' => [],
    ]);
    consoleCompany($autre->id, '900000203', ['lifecycle_stage' => 'qualifie']);
    consoleCompany($this->workspace->id, '900000204', ['lifecycle_stage' => 'qualifie']);

    $response = $this->getJson('/api/v1/crm/contacts-hub?temperature=tous')->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.siren'))->toBe('900000204');
});

test('contacts-hub : la vue par defaut ecarte la base froide', function () {
    // Froide : étape « nouveau » et aucune provenance autre que le scraping.
    consoleCompany($this->workspace->id, '900000205');
    // Active : l'étape a bougé, la personne s'est manifestée.
    consoleCompany($this->workspace->id, '900000206', ['lifecycle_stage' => 'qualifie']);

    $defaut = $this->getJson('/api/v1/crm/contacts-hub')->assertOk();
    expect($defaut->json('data'))->toHaveCount(1);
    expect($defaut->json('data.0.siren'))->toBe('900000206');

    $froids = $this->getJson('/api/v1/crm/contacts-hub?temperature=froids')->assertOk();
    expect($froids->json('data'))->toHaveCount(1);
    expect($froids->json('data.0.siren'))->toBe('900000205');
});

test('contacts-hub/counts agrege par type et par etape', function () {
    consoleCompany($this->workspace->id, '900000207', ['relation_type' => 'client', 'lifecycle_stage' => 'client']);
    consoleCompany($this->workspace->id, '900000208', ['relation_type' => 'client', 'lifecycle_stage' => 'dormant']);
    consoleCompany($this->workspace->id, '900000209', ['relation_type' => 'presse_media', 'lifecycle_stage' => 'qualifie']);

    $this->getJson('/api/v1/crm/contacts-hub/counts')
        ->assertOk()
        ->assertJsonPath('total', 3)
        ->assertJsonPath('by_relation_type.client', 2)
        ->assertJsonPath('by_relation_type.presse_media', 1)
        ->assertJsonPath('by_relation_type.investisseur', 0)
        ->assertJsonPath('by_lifecycle_stage.dormant', 1);
});

// ── 3. ÉTANCHÉITÉ DES UNIVERS (plan §2.10) ──────────────────────────────────

test('etancheite : un utilisateur business-only prend 403 sur le vivier', function () {
    consoleCandidate($this->vivierId, 'ZZ CANDIDAT');

    // 403 et non « liste vide » : une liste vide se confond avec « il n'y a
    // rien », et l'opérateur conclurait que le vivier est vide.
    $this->getJson('/api/v1/crm/candidates')->assertForbidden();
    $this->getJson('/api/v1/crm/candidates/counts')->assertForbidden();
});

test('etancheite : un utilisateur business-only ne peut pas agir en masse sur le vivier', function () {
    $id = consoleCandidate($this->vivierId, 'ZZ CANDIDAT MASSE');

    $this->postJson('/api/v1/crm/bulk', [
        'entity' => 'candidate',
        'ids' => [$id],
        'action' => 'set_lifecycle',
        'params' => ['stage' => 'refuse'],
    ])->assertForbidden();

    expect(DB::table('candidates')->where('id', $id)->value('lifecycle_stage'))->toBe('nouveau');
});

test('etancheite : un membre du vivier lit le vivier', function () {
    consoleMembership($this->user->id, $this->vivierId, 'admin');
    consoleCandidate($this->vivierId, 'ZZ MOREL', ['relation_type' => 'candidat_video']);

    $response = $this->getJson('/api/v1/crm/candidates')->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.last_name'))->toBe('ZZ MOREL');
    expect($response->json('data.0.relation_type'))->toBe('candidat_video');

    $this->getJson('/api/v1/crm/candidates?relation_type=candidat_commercial')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('etancheite : une appartenance REVOQUEE ne donne plus acces au vivier', function () {
    consoleMembership($this->user->id, $this->vivierId, 'admin');
    DB::table('user_workspaces')
        ->where('user_id', $this->user->id)
        ->where('workspace_id', $this->vivierId)
        ->update(['revoked_at' => now()]);

    $this->getJson('/api/v1/crm/candidates')->assertForbidden();
});

test('etancheite : le pointeur current_workspace_id ne suffit PAS a ouvrir le vivier', function () {
    // `users.current_workspace_id` est un pointeur d'AFFICHAGE, que
    // l'utilisateur modifie lui-même via le sélecteur de workspace. Faire
    // reposer une frontière RGPD dessus, c'est n'avoir aucune frontière.
    $this->user->forceFill(['current_workspace_id' => $this->vivierId])->save();

    $this->getJson('/api/v1/crm/candidates')->assertForbidden();
});

test('etancheite : l arbitrage n existe pas dans l univers vivier', function () {
    consoleMembership($this->user->id, $this->vivierId, 'admin');
    $this->user->forceFill(['current_workspace_id' => $this->vivierId])->save();

    // Un candidat n'a pas de SIREN à rapprocher (plan §2.1) : la file n'existe
    // pas de ce côté-ci de la frontière.
    $this->getJson('/api/v1/crm/arbitrage')->assertForbidden();
});

// ── 4. FICHE 360° — timeline ────────────────────────────────────────────────

test('timeline : agrege les activites business de la personne', function () {
    $personKey = consolePersonKey('perret@example.invalid');
    $companyId = consoleCompany($this->workspace->id, '900000210');

    DB::table('activities')->insert([
        'workspace_id' => $this->workspace->id,
        'type' => 'form_submission', 'kind' => 'form_submission',
        'occurred_at' => now()->subDays(2), 'person_key' => $personKey,
        'external_ref' => 'site:event:' . Str::uuid(),
        'subject_type' => 'company', 'subject_id' => $companyId,
        'title' => 'Formulaire — audit', 'payload' => '{}', 'created_at' => now(),
    ]);

    DB::table('contacts')->insert([
        'workspace_id' => $this->workspace->id, 'company_id' => $companyId,
        'first_name' => 'Marc', 'last_name' => 'ZZ PERRET',
        'email' => 'perret@example.invalid', 'person_key' => $personKey,
        'sources' => '["site"]', 'metadata' => '{}', 'discovery_source' => 'site',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $response = $this->getJson('/api/v1/crm/persons/' . $personKey . '/timeline')->assertOk();

    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.universe'))->toBe('business');
    expect($response->json('subjects'))->toHaveCount(1);
    expect($response->json('subjects.0.last_name'))->toBe('ZZ PERRET');
    expect($response->json('universes.business.exists'))->toBeTrue();
});

test('timeline : un business-only voit le BOOLEEN vivier, jamais son contenu', function () {
    $personKey = consolePersonKey('double@example.invalid');

    consoleCandidate($this->vivierId, 'ZZ DOUBLE', ['person_key' => $personKey]);
    DB::table('activities')->insert([
        'workspace_id' => $this->vivierId,
        'type' => 'application_submitted', 'kind' => 'application_submitted',
        'occurred_at' => now(), 'person_key' => $personKey,
        'external_ref' => 'site:event:' . Str::uuid(),
        'title' => 'Candidature — commercial', 'payload' => '{}', 'created_at' => now(),
    ]);

    $response = $this->getJson('/api/v1/crm/persons/' . $personKey . '/timeline')->assertOk();

    // L'indicateur EXISTE (plan §2.4.3) : sans lui l'opérateur créerait un
    // doublon pour quelqu'un qui a déjà une fiche.
    expect($response->json('universes.vivier.exists'))->toBeTrue();
    expect($response->json('universes.vivier.accessible'))->toBeFalse();

    // Mais RIEN du vivier ne sort : pas une activité, pas un nom.
    expect($response->json('data'))->toHaveCount(0);
    expect($response->json('subjects'))->toHaveCount(0);
    expect(json_encode($response->json(), JSON_THROW_ON_ERROR))->not->toContain('ZZ DOUBLE');
});

test('timeline : un membre des deux univers voit les deux', function () {
    consoleMembership($this->user->id, $this->vivierId, 'admin');
    $personKey = consolePersonKey('double2@example.invalid');

    consoleCandidate($this->vivierId, 'ZZ DEUX UNIVERS', ['person_key' => $personKey]);
    DB::table('activities')->insert([
        'workspace_id' => $this->vivierId,
        'type' => 'application_submitted', 'kind' => 'application_submitted',
        'occurred_at' => now(), 'person_key' => $personKey,
        'external_ref' => 'site:event:' . Str::uuid(),
        'title' => 'Candidature', 'payload' => '{}', 'created_at' => now(),
    ]);

    $response = $this->getJson('/api/v1/crm/persons/' . $personKey . '/timeline')->assertOk();

    expect($response->json('universes.vivier.accessible'))->toBeTrue();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.universe'))->toBe('vivier');
    expect($response->json('subjects.0.last_name'))->toBe('ZZ DEUX UNIVERS');
});

test('timeline : une cle malformee repond 404 sans interroger la base', function () {
    $this->getJson('/api/v1/crm/persons/pas-un-sha256/timeline')->assertNotFound();
});

// ── 5. ARBITRAGE — bout en bout ─────────────────────────────────────────────

test('arbitrage : la file liste les evenements orphelins, les plus anciens d abord', function () {
    $personKey = consolePersonKey('orphelin@example.invalid');
    consolePendingActivity($this->workspace->id, $personKey, [
        'denomination' => 'ZZ BATI-ALPES',
        'postcode' => '73000',
        'email' => 'contact@example.invalid',
    ]);

    // Un événement déjà rattaché n'a rien à faire dans la file.
    $companyId = consoleCompany($this->workspace->id, '900000211');
    DB::table('activities')->insert([
        'workspace_id' => $this->workspace->id,
        'type' => 'form_submission', 'kind' => 'form_submission',
        'occurred_at' => now(), 'person_key' => $personKey,
        'external_ref' => 'site:event:' . Str::uuid(),
        'subject_type' => 'company', 'subject_id' => $companyId,
        'title' => 'Déjà rattaché', 'payload' => '{}', 'created_at' => now(),
    ]);

    $response = $this->getJson('/api/v1/crm/arbitrage')->assertOk();

    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.pending_match.denomination'))->toBe('ZZ BATI-ALPES');
});

test('arbitrage : rattacher cree la fiche personne dedupliquee', function () {
    $personKey = consolePersonKey('lang@example.invalid');
    $companyId = consoleCompany($this->workspace->id, '900000212');

    $activityId = consolePendingActivity($this->workspace->id, $personKey, [
        'denomination' => 'ZZ LANG RENOVATION',
        'email' => 'lang@example.invalid',
        'first_name' => 'Sophie',
        'last_name' => 'ZZ LANG',
        'phone' => '+33600000001',
        'legal_basis' => 'precontractual',
        'subject_ref' => 'site:submission:' . Str::uuid(),
    ]);

    $response = $this->postJson("/api/v1/crm/arbitrage/{$activityId}/attach", ['company_id' => $companyId])
        ->assertOk();

    expect($response->json('contact_created'))->toBeTrue();

    $activity = DB::table('activities')->where('id', $activityId)->first();
    expect($activity->subject_type)->toBe('company');
    expect((int) $activity->subject_id)->toBe($companyId);
    expect($activity->contact_id)->not->toBeNull();

    $contact = DB::table('contacts')->where('person_key', $personKey)->first();
    expect($contact)->not->toBeNull();
    expect($contact->last_name)->toBe('ZZ LANG');
    expect((int) $contact->company_id)->toBe($companyId);
    // La base légale enregistrée à l'ingestion l'emporte sur celle d'une fiche
    // collectée : la personne s'est manifestée.
    expect(DB::table('companies')->where('id', $companyId)->value('legal_basis'))->toBe('precontractual');
});

test('arbitrage : rattacher NE DUPLIQUE PAS une personne deja connue', function () {
    $personKey = consolePersonKey('connu@example.invalid');
    $companyId = consoleCompany($this->workspace->id, '900000213');

    DB::table('contacts')->insert([
        'workspace_id' => $this->workspace->id, 'company_id' => $companyId,
        'first_name' => 'Sophie', 'last_name' => 'ZZ DEJA LA',
        'email' => 'connu@example.invalid', 'person_key' => $personKey,
        'sources' => '["scraping"]', 'metadata' => '{}', 'discovery_source' => 'scraping',
        'legal_basis' => 'legitimate_interest_b2b',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $activityId = consolePendingActivity($this->workspace->id, $personKey, [
        'email' => 'connu@example.invalid',
        'first_name' => 'Sophie',
        'last_name' => 'ZZ DEJA LA',
        'legal_basis' => 'precontractual',
    ]);

    $this->postJson("/api/v1/crm/arbitrage/{$activityId}/attach", ['company_id' => $companyId])
        ->assertOk();

    // C'est TOUT l'intérêt de réutiliser `ContactUpserter` : l'écran censé
    // résoudre les doublons ne doit pas en créer.
    expect(DB::table('contacts')->where('person_key', $personKey)->count())->toBe(1);
    expect(DB::table('contacts')->where('person_key', $personKey)->value('legal_basis'))->toBe('precontractual');
});

test('arbitrage : sans nom transmis, aucune fiche personne n est fabriquee', function () {
    $personKey = consolePersonKey('anonyme@example.invalid');
    $companyId = consoleCompany($this->workspace->id, '900000214');

    $activityId = consolePendingActivity($this->workspace->id, $personKey, [
        'denomination' => 'ZZ SANS NOM',
        'email' => 'anonyme@example.invalid',
    ]);

    $response = $this->postJson("/api/v1/crm/arbitrage/{$activityId}/attach", ['company_id' => $companyId])
        ->assertOk();

    // On ne fabrique JAMAIS un patronyme depuis une adresse électronique.
    expect($response->json('contact_created'))->toBeFalse();
    expect(DB::table('contacts')->where('person_key', $personKey)->count())->toBe(0);
    expect((int) DB::table('activities')->where('id', $activityId)->value('subject_id'))->toBe($companyId);
});

test('arbitrage : rattacher deux fois repond 409', function () {
    $personKey = consolePersonKey('double-attach@example.invalid');
    $companyId = consoleCompany($this->workspace->id, '900000215');
    $activityId = consolePendingActivity($this->workspace->id, $personKey, ['last_name' => 'ZZ UNE FOIS']);

    $this->postJson("/api/v1/crm/arbitrage/{$activityId}/attach", ['company_id' => $companyId])->assertOk();
    $this->postJson("/api/v1/crm/arbitrage/{$activityId}/attach", ['company_id' => $companyId])->assertStatus(409);
});

test('arbitrage : une entreprise d un autre workspace est introuvable', function () {
    $autre = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-etranger', 'name' => 'Étranger', 'settings' => [],
    ]);
    $etrangere = consoleCompany($autre->id, '900000216');
    $activityId = consolePendingActivity($this->workspace->id, consolePersonKey('x@example.invalid'), ['last_name' => 'ZZ X']);

    // 404 et non 403 : sinon la réponse devient un oracle d'existence
    // inter-tenants.
    $this->postJson("/api/v1/crm/arbitrage/{$activityId}/attach", ['company_id' => $etrangere])
        ->assertNotFound();

    expect(DB::table('activities')->where('id', $activityId)->value('subject_id'))->toBeNull();
});

test('arbitrage : ecarter sort de la file et exige un motif', function () {
    $activityId = consolePendingActivity($this->workspace->id, consolePersonKey('ecarte@example.invalid'), [
        'denomination' => 'ZZ INTROUVABLE',
    ]);

    // Sans motif, « écarté » ne se distingue pas de « perdu ».
    $this->postJson("/api/v1/crm/arbitrage/{$activityId}/dismiss", [])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('reason');

    $this->postJson("/api/v1/crm/arbitrage/{$activityId}/dismiss", ['reason' => 'Entreprise inexistante au RNE'])
        ->assertOk();

    $this->getJson('/api/v1/crm/arbitrage')->assertOk()->assertJsonPath('meta.total', 0);
    // La ligne n'est PAS rattachée : elle est seulement sortie de la file.
    expect(DB::table('activities')->where('id', $activityId)->value('subject_id'))->toBeNull();
});

// ── 6. ACTIONS DE MASSE ─────────────────────────────────────────────────────

test('bulk set_lifecycle NE RECULE JAMAIS une etape business', function () {
    $client = consoleCompany($this->workspace->id, '900000217', ['lifecycle_stage' => 'client']);
    $nouveau = consoleCompany($this->workspace->id, '900000218', ['lifecycle_stage' => 'nouveau']);

    $response = $this->postJson('/api/v1/crm/bulk', [
        'ids' => [$client, $nouveau],
        'action' => 'set_lifecycle',
        'params' => ['stage' => 'qualifie'],
    ])->assertOk();

    // Le client reste client : « qualifié » serait un recul.
    expect(DB::table('companies')->where('id', $client)->value('lifecycle_stage'))->toBe('client');
    expect(DB::table('companies')->where('id', $nouveau)->value('lifecycle_stage'))->toBe('qualifie');

    expect($response->json('updated'))->toBe(1);
    // Le refus est DIT, pas silencieux : un silence passerait pour un succès.
    expect($response->json('refused_regressions'))->toHaveCount(1);
    expect($response->json('refused_regressions.0.id'))->toBe($client);
    expect($response->json('refused_regressions.0.from'))->toBe('client');
});

test('bulk set_lifecycle NE RECULE JAMAIS une etape vivier', function () {
    consoleMembership($this->user->id, $this->vivierId, 'admin');

    $entretien = consoleCandidate($this->vivierId, 'ZZ ENTRETIEN', ['lifecycle_stage' => 'entretien']);
    $nouveau = consoleCandidate($this->vivierId, 'ZZ NOUVEAU', ['lifecycle_stage' => 'nouveau']);

    $response = $this->postJson('/api/v1/crm/bulk', [
        'entity' => 'candidate',
        'ids' => [$entretien, $nouveau],
        'action' => 'set_lifecycle',
        'params' => ['stage' => 'preselection'],
    ])->assertOk();

    // Revenir à « présélection » après un entretien effacerait un tri humain.
    expect(DB::table('candidates')->where('id', $entretien)->value('lifecycle_stage'))->toBe('entretien');
    expect(DB::table('candidates')->where('id', $nouveau)->value('lifecycle_stage'))->toBe('preselection');
    expect($response->json('updated'))->toBe(1);
    expect($response->json('refused_regressions'))->toHaveCount(1);
});

test('bulk set_lifecycle journalise un stage_changed par fiche modifiee', function () {
    $a = consoleCompany($this->workspace->id, '900000219', ['lifecycle_stage' => 'nouveau']);
    $b = consoleCompany($this->workspace->id, '900000220', ['lifecycle_stage' => 'nouveau']);

    $this->postJson('/api/v1/crm/bulk', [
        'ids' => [$a, $b],
        'action' => 'set_lifecycle',
        'params' => ['stage' => 'opportunite'],
    ])->assertOk();

    $journal = DB::table('activities')
        ->where('workspace_id', $this->workspace->id)
        ->where('kind', 'stage_changed')
        ->get();

    expect($journal)->toHaveCount(2);
    expect($journal->first()->title)->toBe('Étape : nouveau → opportunite');
});

test('bulk set_lifecycle refuse une etape hors de l univers demande', function () {
    $company = consoleCompany($this->workspace->id, '900000221');

    // « preselection » est une étape du VIVIER : elle n'a aucun sens ici, et
    // le CHECK SQL la refuserait de toute façon — autant refuser lisiblement.
    $this->postJson('/api/v1/crm/bulk', [
        'ids' => [$company],
        'action' => 'set_lifecycle',
        'params' => ['stage' => 'preselection'],
    ])->assertStatus(422);

    expect(DB::table('companies')->where('id', $company)->value('lifecycle_stage'))->toBe('nouveau');
});

test('bulk add_tag et remove_tag posent et retirent le tag', function () {
    $company = consoleCompany($this->workspace->id, '900000222');
    $tagId = consoleTag($this->workspace->id, 'svc:audit');

    $this->postJson('/api/v1/crm/bulk', [
        'ids' => [$company], 'action' => 'add_tag', 'params' => ['tag' => 'svc:audit'],
    ])->assertOk();

    expect(DB::table('company_tag')->where('company_id', $company)->where('tag_id', $tagId)->exists())->toBeTrue();

    // Rejouer ne doit jamais dupliquer la ligne de pivot.
    $this->postJson('/api/v1/crm/bulk', [
        'ids' => [$company], 'action' => 'add_tag', 'params' => ['tag' => 'svc:audit'],
    ])->assertOk();
    expect(DB::table('company_tag')->where('company_id', $company)->count())->toBe(1);

    $this->postJson('/api/v1/crm/bulk', [
        'ids' => [$company], 'action' => 'remove_tag', 'params' => ['tag' => 'svc:audit'],
    ])->assertOk();
    expect(DB::table('company_tag')->where('company_id', $company)->count())->toBe(0);
});

test('bulk add_tag refuse un tag hors referentiel du workspace', function () {
    $company = consoleCompany($this->workspace->id, '900000223');

    // Un référentiel qu'on peut étendre pendant une action de masse n'est pas
    // un référentiel (plan §2.2c).
    $this->postJson('/api/v1/crm/bulk', [
        'ids' => [$company], 'action' => 'add_tag', 'params' => ['tag' => 'svc:invente'],
    ])->assertStatus(422);

    expect(DB::table('tags')->where('slug', 'svc:invente')->exists())->toBeFalse();
});

test('bulk n expose AUCUNE action de reclassement de type', function () {
    $company = consoleCompany($this->workspace->id, '900000224');

    // Ce n'est pas un refus à l'exécution, c'est une ABSENCE de l'énumération :
    // une frontière qu'on peut demander à franchir finit par être franchie.
    $this->postJson('/api/v1/crm/bulk', [
        'ids' => [$company],
        'action' => 'set_relation_type',
        'params' => ['relation_type' => 'client'],
    ])->assertStatus(422)->assertJsonValidationErrorFor('action');

    expect(DB::table('companies')->where('id', $company)->value('relation_type'))->toBe('prospect');
});

test('bulk ignore silencieusement les identifiants d un autre workspace', function () {
    $autre = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-bulk-autre', 'name' => 'Autre', 'settings' => [],
    ]);
    $etrangere = consoleCompany($autre->id, '900000225', ['lifecycle_stage' => 'nouveau']);
    $mienne = consoleCompany($this->workspace->id, '900000226', ['lifecycle_stage' => 'nouveau']);

    $response = $this->postJson('/api/v1/crm/bulk', [
        'ids' => [$etrangere, $mienne],
        'action' => 'set_lifecycle',
        'params' => ['stage' => 'qualifie'],
    ])->assertOk();

    expect($response->json('updated'))->toBe(1);
    expect(DB::table('companies')->where('id', $etrangere)->value('lifecycle_stage'))->toBe('nouveau');
    expect(DB::table('companies')->where('id', $mienne)->value('lifecycle_stage'))->toBe('qualifie');
});
