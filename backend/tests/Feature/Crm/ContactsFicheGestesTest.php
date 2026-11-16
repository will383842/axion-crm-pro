<?php

/**
 * Lot G1-E (cahier #619, § 7) — fiche sur la page personne, appel, rappel, annonce `gestes_fiches`, export.
 * Les cas de cloisonnement sont joués sous le rôle applicatif (`SET LOCAL ROLE`) ET sous le propriétaire.
 * Rien ne part : `Mail::fake()`, aucune table `envoi_*`. Données fictives (example.test).
 */

use App\Crm\Identite\CleDePersonne;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ListeSuppression;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    config(['crm.console_v2' => true, 'crm.person_key.secret' => 'secret-de-test-g1e-0123456789abcdef']);
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->espaceA = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'ws-fg-a', 'name' => 'Espace A', 'settings' => []]);
    $this->espaceB = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'ws-fg-b', 'name' => 'Espace B', 'settings' => []]);
    $this->entrepriseA = fgEntreprise($this->espaceA->id, '900000701');
    $this->entrepriseB = fgEntreprise($this->espaceB->id, '900000702');
    $this->contactA = fgContact($this->espaceA->id, $this->entrepriseA, 'Durand', 'Alice', 'alice@example.test');
    $this->contactB = fgContact($this->espaceB->id, $this->entrepriseB, 'Martin', 'Bob', 'bob@example.test');
    $this->cle = CleDePersonne::pour('alice@example.test');
});

function fgActeur(string $espaceId, string $role): User
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

function fgEntreprise(string $espaceId, string $siren): int
{
    return (int) DB::table('companies')->insertGetId([
        'workspace_id' => $espaceId, 'siren' => $siren, 'denomination' => 'ZZ TEST ' . $siren,
        'discovery_source' => 'site', 'quality_score' => 0, 'signals' => '{}', 'metadata' => '{}',
        'relation_type' => 'prospect', 'lifecycle_stage' => 'nouveau',
        'legal_basis' => 'legitimate_interest_b2b', 'field_origins' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function fgContact(string $espaceId, int $entrepriseId, string $nom, string $prenom, ?string $email, ?string $supprimeLe = null, string $metadata = '{}'): int
{
    return (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $espaceId, 'company_id' => $entrepriseId, 'first_name' => $prenom, 'last_name' => $nom,
        'email' => $email, 'person_key' => CleDePersonne::pour($email), 'discovery_source' => 'site', 'sources' => '["site"]',
        'metadata' => $metadata, 'legal_basis' => 'legitimate_interest_b2b', 'deleted_at' => $supprimeLe,
        'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
    ]);
}

/** Joue la suite de la requête sous le rôle applicatif (ou le propriétaire). */
function fgRole(string $mode): void
{
    if ($mode === 'app') {
        DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    }
}

/** Revient au propriétaire (les assertions lisent la base sans contexte d'espace). */
function fgFin(): void
{
    DB::statement('RESET ROLE');
}

/** @return array<string, mixed> */
function fgTimeline(object $t, string $cle): array
{
    return $t->getJson("/api/v1/crm/persons/{$cle}/timeline")->assertOk()->json();
}

test('1. viewer : appels et rappel → 403 sans activité ; export → 403 ; témoin operator → 200', function () {
    $this->actingAs(fgActeur($this->espaceA->id, 'viewer'));
    $this->postJson("/api/v1/crm/persons/{$this->cle}/appels", ['compte_rendu' => 'Appel ok'])->assertForbidden();
    $this->postJson("/api/v1/crm/persons/{$this->cle}/rappel", ['le' => '2027-01-12'])->assertForbidden();
    $this->postJson('/api/v1/crm/contacts/export', ['contact_ids' => [$this->contactA]])->assertForbidden();
    expect(DB::table('activities')->count())->toBe(0);

    $this->actingAs(fgActeur($this->espaceA->id, 'operator'));
    $this->postJson("/api/v1/crm/persons/{$this->cle}/appels", ['compte_rendu' => 'Appel ok'])->assertStatus(201);
    $this->postJson("/api/v1/crm/persons/{$this->cle}/rappel", ['le' => '2027-01-12'])->assertStatus(201);
    $this->post('/api/v1/crm/contacts/export', ['contact_ids' => [$this->contactA]])->assertOk();
});

test('2. fiche : présente (actif, retiré par la console), absente (mis à l’écart) ; jamais celle d’un autre espace', function (string $mode) {
    $this->actingAs(fgActeur($this->espaceA->id, 'operator'));
    fgRole($mode);
    $r = fgTimeline($this, $this->cle);
    expect($r['fiche']['contact_id'])->toBe($this->contactA)->and($r['fiche']['retire_le'])->toBeNull()
        ->and($r['fiche']['envois'])->toBe([])->and($r['fiche']['etape'])->toBe('nouveau')
        ->and($r['fiche']['droit_ecrire'])->toBe(['verdict' => 'prudence', 'raison' => 'verifier_avant_envoi']);

    // La personne de B n'a jamais de fiche pour A.
    expect(fgTimeline($this, CleDePersonne::pour('bob@example.test')))->not->toHaveKey('fiche');

    fgFin();
    DB::update("UPDATE contacts SET deleted_at = now(), metadata = '{\"retire_console_le\":\"2026-11-01T10:00\"}'::jsonb WHERE id = ?", [$this->contactA]);
    fgRole($mode);
    $r = fgTimeline($this, $this->cle);
    expect($r['fiche']['contact_id'])->toBe($this->contactA)->and($r['fiche']['retire_le'])->not->toBeNull();

    fgFin();
    DB::update("UPDATE contacts SET metadata = '{\"mis_a_l_ecart_le\":\"2026-11-01\"}'::jsonb WHERE id = ?", [$this->contactA]);
    fgRole($mode);
    expect(fgTimeline($this, $this->cle))->not->toHaveKey('fiche');
})->with(['app', 'proprio']);

test('2b. personne opposée : droit_ecrire « non / opposition »', function () {
    $this->actingAs(fgActeur($this->espaceA->id, 'operator'));
    DB::table('opt_out')->insert(['email' => 'alice@example.test', 'email_hash' => ListeSuppression::empreinte('alice@example.test'), 'source' => 'test', 'reason' => 'gdpr_art21', 'scope' => 'business', 'created_at' => now()]);
    $r = fgTimeline($this, $this->cle);
    expect($r['fiche']['droit_ecrire'])->toBe(['verdict' => 'non', 'raison' => 'opposition']);
});

test('3. gestes_fiches: true dans les compteurs', function () {
    $this->actingAs(fgActeur($this->espaceA->id, 'viewer'));
    $this->getJson('/api/v1/crm/contacts-hub/counts')->assertOk()->assertJsonPath('gestes_fiches', true);
});

test('4. double clic sur appels → une seule activité ; l’appel est rattaché au contact', function (string $mode) {
    $u = fgActeur($this->espaceA->id, 'operator');
    $this->actingAs($u);
    fgRole($mode);
    $this->postJson("/api/v1/crm/persons/{$this->cle}/appels", ['compte_rendu' => 'Rappeler jeudi'])->assertStatus(201);
    $this->postJson("/api/v1/crm/persons/{$this->cle}/appels", ['compte_rendu' => 'Rappeler jeudi'])->assertOk();
    fgFin();
    $l = DB::table('activities')->where('kind', 'call')->get();
    expect($l)->toHaveCount(1)->and((int) $l[0]->contact_id)->toBe($this->contactA)->and($l[0]->person_key)->toBe($this->cle)
        ->and($l[0]->subject_type)->toBe('contact')->and($l[0]->user_id)->toBe($u->id)->and($l[0]->workspace_id)->toBe($this->espaceA->id);
    $this->postJson("/api/v1/crm/persons/{$this->cle}/appels", ['compte_rendu' => str_repeat('x', 5001)])->assertStatus(422);
})->with(['app', 'proprio']);

test('5. rappel : une tâche kind=task rattachée au contact, visible dans la fiche ; personne d’un autre espace → 404', function (string $mode) {
    $this->actingAs(fgActeur($this->espaceA->id, 'operator'));
    fgRole($mode);
    $this->postJson("/api/v1/crm/persons/{$this->cle}/rappel", ['le' => '2027-01-12', 'note' => 'Envoyer le devis'])->assertStatus(201);
    fgFin();
    $t = DB::table('activities')->where('kind', 'task')->get();
    expect($t)->toHaveCount(1)->and($t[0]->title)->toBe('Envoyer le devis')->and($t[0]->subject_type)->toBe('contact')
        ->and((int) $t[0]->subject_id)->toBe($this->contactA)->and($t[0]->due_at)->not->toBeNull();
    fgRole($mode);
    $r = fgTimeline($this, $this->cle);
    expect($r['fiche']['rappel_le'])->toBe('2027-01-12')->and($r['fiche']['rappel_note'])->toBe('Envoyer le devis');

    $this->postJson('/api/v1/crm/persons/' . CleDePersonne::pour('bob@example.test') . '/rappel', ['le' => '2027-01-12'])->assertNotFound();
    $this->postJson("/api/v1/crm/persons/{$this->cle}/rappel", ['le' => 'pas une date'])->assertStatus(422);
    fgFin();
    expect(DB::table('activities')->where('kind', 'task')->count())->toBe(1);
})->with(['app', 'proprio']);

test('5b. personne opposée ou mise à l’écart : appel et rappel refusés, rien écrit (échec fermé)', function () {
    $this->actingAs(fgActeur($this->espaceA->id, 'operator'));
    DB::update("UPDATE contacts SET deleted_at = now(), metadata = '{\"mis_a_l_ecart_le\":\"2026-11-01\"}'::jsonb WHERE id = ?", [$this->contactA]);
    $this->postJson("/api/v1/crm/persons/{$this->cle}/appels", ['compte_rendu' => 'Appel'])->assertNotFound();
    $this->postJson("/api/v1/crm/persons/{$this->cle}/rappel", ['le' => '2027-01-12'])->assertNotFound();
    expect(DB::table('activities')->count())->toBe(0);
});

test('6. export : CSV des seuls identifiants de l’espace ; id de B → 422 sans fichier ; journal sans valeur', function (string $mode) {
    $this->actingAs(fgActeur($this->espaceA->id, 'operator'));
    $retire = fgContact($this->espaceA->id, $this->entrepriseA, 'Retire', 'Rita', 'rita@example.test', now()->toDateTimeString(), '{"retire_console_le":"2026-11-01T10:00"}');
    fgRole($mode);

    $r = $this->post('/api/v1/crm/contacts/export', ['company_ids' => [$this->entrepriseA]]);
    $r->assertOk();
    $csv = $r->streamedContent();
    expect($csv)->toContain('prenom;nom;email;fonction;organisation')->toContain('alice@example.test')
        ->not->toContain('bob@example.test')->not->toContain('rita@example.test');

    foreach ([['contact_ids' => [$this->contactA, $this->contactB]], ['company_ids' => [$this->entrepriseB]], ['contact_ids' => [$retire]]] as $corps) {
        $x = $this->postJson('/api/v1/crm/contacts/export', $corps);
        $x->assertStatus(422);
        expect($x->headers->get('content-type'))->toContain('json');
    }
    $this->postJson('/api/v1/crm/contacts/export', [])->assertStatus(422);
    $this->postJson('/api/v1/crm/contacts/export', ['contact_ids' => range(1, 501)])->assertStatus(422);

    fgFin();
    $brut = json_encode(DB::table('business_events')->where('workspace_id', $this->espaceA->id)->get()->all());
    expect($brut)->toContain('contacts.export')->not->toContain('alice@example.test')->not->toContain('Durand');
})->with(['app', 'proprio']);

test('6b. export : une personne opposée ne sort pas', function () {
    $this->actingAs(fgActeur($this->espaceA->id, 'operator'));
    DB::table('opt_out')->insert(['email' => 'alice@example.test', 'email_hash' => ListeSuppression::empreinte('alice@example.test'), 'source' => 'test', 'reason' => 'gdpr_art21', 'scope' => 'business', 'created_at' => now()]);
    $r = $this->post('/api/v1/crm/contacts/export', ['contact_ids' => [$this->contactA]]);
    $r->assertOk();
    expect($r->streamedContent())->not->toContain('alice@example.test');
});

test('7. aucune envoi : ni table envoi_* ni courriel', function () {
    $this->actingAs(fgActeur($this->espaceA->id, 'operator'));
    $this->postJson("/api/v1/crm/persons/{$this->cle}/appels", ['compte_rendu' => 'Appel ok']);
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    $src = file_get_contents(base_path('app/Http/Controllers/Api/Crm/ContactsGestesController.php'));
    expect($src)->not->toContain('App\\Envoi')->not->toContain('envoi_');
});

test('5c. personne opposée : appel et rappel refusés (404), rien écrit', function () {
    $this->actingAs(fgActeur($this->espaceA->id, 'operator'));
    DB::table('opt_out')->insert(['email' => 'alice@example.test', 'email_hash' => ListeSuppression::empreinte('alice@example.test'), 'source' => 'test', 'reason' => 'gdpr_art21', 'scope' => 'business', 'created_at' => now()]);
    $this->postJson("/api/v1/crm/persons/{$this->cle}/appels", ['compte_rendu' => 'Appel'])->assertNotFound();
    $this->postJson("/api/v1/crm/persons/{$this->cle}/rappel", ['le' => '2027-01-12'])->assertNotFound();
    expect(DB::table('activities')->count())->toBe(0);
});
