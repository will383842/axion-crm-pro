<?php

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * LOT L4-C — l'écran « Personnes (lettre et guide) » et ses trois commandes.
 *
 * Ce que Will peut faire d'une personne : la retrouver par segment, ouvrir sa
 * fiche, lui poser une tâche, exporter, la rattacher à une entreprise. Et ce
 * que les commandes font sans lui : rattraper la file d'arbitrage (à blanc par
 * défaut), purger à 3 ans, guetter un flux muet.
 *
 * Adresses en `@example.invalid` uniquement (dépôt PUBLIC).
 */
beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    config(['crm.console_v2' => true, 'crm.ingest.business_workspace' => 'axion-ia']);

    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'axion-ia',
        'name' => 'Axion-IA',
        'settings' => [],
    ]);

    $this->user = l4cConsoleUser($this->workspace->id, 'admin');
    $this->actingAs($this->user);
});

function l4cConsoleUser(string $workspaceId, string $role, string $email = 'l4c.console@example.invalid'): User
{
    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => $email,
        'name' => 'Opérateur L4-C',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $workspaceId,
        'first_login_completed_at' => now(),
    ]);

    setPermissionsTeamId($user->current_workspace_id);
    $user->assignRole($role);

    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $user->id,
        'workspace_id' => $workspaceId,
        'role_slug' => 'owner',
        'invited_at' => now(),
        'joined_at' => now(),
    ]);

    return $user;
}

/** @param  array<string, mixed>  $overrides */
function l4cPersonne(string $workspaceId, string $email, array $overrides = [], ?string $statut = 'abonne'): int
{
    $id = (int) DB::table('personnes')->insertGetId(array_merge([
        'workspace_id' => $workspaceId,
        'person_key' => hash('sha256', 'l4c-console|' . $email),
        'email' => $email,
        'email_hash' => hash('sha256', $email),
        'email_nature' => 'pro',
        'premiere_source' => 'newsletter',
        'premiere_source_at' => now()->subDays(10),
        'derniere_interaction_at' => now()->subDays(10),
        'legal_basis' => 'consent',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    if ($statut !== null) {
        DB::table('abonnements')->insert([
            'workspace_id' => $workspaceId,
            'personne_id' => $id,
            'canal' => 'lettre',
            'statut' => $statut,
            'consent_version' => 'newsletter-v1',
            'consent_at' => now()->subDays(10),
            'dernier_evenement_at' => now()->subDays(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $id;
}

function l4cEntreprise(string $workspaceId, string $siren): int
{
    return (int) DB::table('companies')->insertGetId([
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
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Liste, segments, fiche
// ─────────────────────────────────────────────────────────────────────────────

test('L4-C console — la liste filtre par statut de la lettre, nature et rattachement', function () {
    l4cPersonne($this->workspace->id, 'zz.abonne@example.invalid');
    l4cPersonne($this->workspace->id, 'zz.desabo@example.invalid', [], 'desabonne');
    l4cPersonne($this->workspace->id, 'zz.guide@example.invalid', ['premiere_source' => 'guide-ia', 'legal_basis' => 'legitimate_interest_b2b', 'email_nature' => 'perso'], null);

    $this->getJson('/api/v1/crm/personnes')->assertOk()->assertJsonCount(3, 'data');
    $this->getJson('/api/v1/crm/personnes?statut_lettre=abonne')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/crm/personnes?statut_lettre=aucun')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.premiere_source', 'guide-ia');
    $this->getJson('/api/v1/crm/personnes?nature=perso')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/crm/personnes?source=guide-ia')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/crm/personnes?rattachee=oui')->assertOk()->assertJsonCount(0, 'data');

    $this->getJson('/api/v1/crm/personnes/counts')->assertOk()
        ->assertJsonPath('total', 3)
        ->assertJsonPath('by_statut_lettre.abonne', 1)
        ->assertJsonPath('by_statut_lettre.desabonne', 1)
        ->assertJsonPath('by_statut_lettre.aucun', 1)
        ->assertJsonPath('non_rattachees', 3);
});

test('L4-C console — sans contacts.view_pii, l’adresse est MASQUÉE dans la liste et la fiche', function () {
    $id = l4cPersonne($this->workspace->id, 'zz.masque@example.invalid');

    $viewer = l4cConsoleUser($this->workspace->id, 'viewer', 'l4c.viewer@example.invalid');
    $this->actingAs($viewer);

    $liste = $this->getJson('/api/v1/crm/personnes')->assertOk();
    expect($liste->json('data.0.email'))->not->toBe('zz.masque@example.invalid');

    $fiche = $this->getJson("/api/v1/crm/personnes/{$id}")->assertOk();
    expect($fiche->json('personne.email'))->not->toBe('zz.masque@example.invalid');
});

test('L4-C console — la fiche rend l’abonnement et la timeline par person_key', function () {
    $id = l4cPersonne($this->workspace->id, 'zz.fiche@example.invalid');
    DB::table('activities')->insert([
        'workspace_id' => $this->workspace->id,
        'type' => 'newsletter_optin',
        'kind' => 'newsletter_optin',
        'occurred_at' => now()->subDays(10),
        'person_key' => hash('sha256', 'l4c-console|zz.fiche@example.invalid'),
        'subject_type' => 'personne',
        'subject_id' => $id,
        'title' => 'Inscription à la lettre',
        'payload' => '{}',
        'created_at' => now(),
    ]);

    $this->getJson("/api/v1/crm/personnes/{$id}")->assertOk()
        ->assertJsonPath('abonnement.statut', 'abonne')
        ->assertJsonPath('timeline.0.kind', 'newsletter_optin');

    $this->getJson('/api/v1/crm/personnes/999999')->assertNotFound();
});

test('L4-C console — la fiche 360° par person_key montre la personne sans entreprise', function () {
    l4cPersonne($this->workspace->id, 'zz.360@example.invalid');

    $this->getJson('/api/v1/crm/persons/' . hash('sha256', 'l4c-console|zz.360@example.invalid') . '/timeline')
        ->assertOk()
        ->assertJsonPath('universes.business.exists', true)
        ->assertJsonPath('subjects.0.type', 'personne')
        ->assertJsonPath('subjects.0.statut_lettre', 'abonne');
});

// ─────────────────────────────────────────────────────────────────────────────
// Tâches
// ─────────────────────────────────────────────────────────────────────────────

test('L4-C console — une tâche se pose une fois (double clic = une ligne), puis se termine', function () {
    $id = l4cPersonne($this->workspace->id, 'zz.tache@example.invalid');

    $r1 = $this->postJson("/api/v1/crm/personnes/{$id}/taches", ['title' => 'Relancer après lecture du guide', 'due_at' => now()->addDays(7)->toIso8601String()])
        ->assertCreated();
    $this->postJson("/api/v1/crm/personnes/{$id}/taches", ['title' => 'Relancer après lecture du guide'])
        ->assertOk()->assertJsonPath('deja_consignee', true);

    expect(DB::table('activities')->where('kind', 'task')->where('subject_id', $id)->count())->toBe(1);

    $activityId = (int) $r1->json('activity_id');
    $this->postJson("/api/v1/crm/personnes/{$id}/taches/{$activityId}/terminer")->assertOk();
    expect(DB::table('activities')->where('id', $activityId)->value('done_at'))->not->toBeNull();

    // Une seconde fois : déjà terminée.
    $this->postJson("/api/v1/crm/personnes/{$id}/taches/{$activityId}/terminer")->assertNotFound();
});

test('L4-C console — un compte en lecture seule ne pose pas de tâche', function () {
    $id = l4cPersonne($this->workspace->id, 'zz.ro@example.invalid');
    $this->actingAs(l4cConsoleUser($this->workspace->id, 'viewer', 'l4c.ro@example.invalid'));

    $this->postJson("/api/v1/crm/personnes/{$id}/taches", ['title' => 'Relancer'])->assertForbidden();
});

// ─────────────────────────────────────────────────────────────────────────────
// Rattacher à une entreprise
// ─────────────────────────────────────────────────────────────────────────────

test('L4-C console — rattacher exige un nom : jamais un patronyme fabriqué depuis une adresse', function () {
    $id = l4cPersonne($this->workspace->id, 'zz.sansnom@example.invalid');
    $company = l4cEntreprise($this->workspace->id, '900000501');

    $this->postJson("/api/v1/crm/personnes/{$id}/rattacher", ['company_id' => $company])->assertStatus(422);
    expect(DB::table('contacts')->count())->toBe(0);
});

test('L4-C console — rattacher crée la fiche contact par ContactUpserter, et la personne y est liée', function () {
    $id = l4cPersonne($this->workspace->id, 'zz.rattache@example.invalid');
    $company = l4cEntreprise($this->workspace->id, '900000502');

    $this->postJson("/api/v1/crm/personnes/{$id}/rattacher", [
        'company_id' => $company,
        'first_name' => 'Jeanne',
        'last_name' => 'ZZ TEST',
    ])->assertOk()->assertJsonPath('contact_created', true)->assertJsonPath('company_id', $company);

    $contact = DB::table('contacts')->first();
    $p = DB::table('personnes')->where('id', $id)->first();

    expect($contact->company_id)->toBe($company)
        ->and($contact->email)->toBe('zz.rattache@example.invalid')
        ->and($contact->person_key)->toBe($p->person_key)
        ->and($contact->consent_version)->toBe('newsletter-v1')
        ->and($p->contact_id)->toBe($contact->id)
        ->and($p->last_name)->toBe('ZZ TEST');

    // Une seconde fois : 409, jamais un second contact.
    $this->postJson("/api/v1/crm/personnes/{$id}/rattacher", ['company_id' => $company, 'last_name' => 'ZZ TEST'])->assertStatus(409);
    expect(DB::table('contacts')->count())->toBe(1);

    $this->getJson('/api/v1/crm/personnes?rattachee=oui')->assertOk()->assertJsonCount(1, 'data');
});

// ─────────────────────────────────────────────────────────────────────────────
// Export
// ─────────────────────────────────────────────────────────────────────────────

test('L4-C console — l’export CSV suit le filtre et n’emporte JAMAIS une personne opposée ou à l’adresse morte', function () {
    l4cPersonne($this->workspace->id, 'zz.export@example.invalid');
    l4cPersonne($this->workspace->id, 'zz.oppose@example.invalid');
    l4cPersonne($this->workspace->id, 'zz.rebond@example.invalid');
    l4cPersonne($this->workspace->id, 'zz.autre@example.invalid', [], 'desabonne');

    DB::table('opt_out')->insert(['email' => null, 'email_hash' => hash('sha256', 'zz.oppose@example.invalid'), 'scope' => 'business', 'source' => 'test', 'created_at' => now()]);
    DB::table('email_suppressions')->insert(['scope' => 'business', 'email_hash' => hash('sha256', 'zz.rebond@example.invalid'), 'reason' => 'hard_bounce', 'source' => 'test']);

    $csv = $this->get('/api/v1/crm/personnes/export?statut_lettre=abonne')->assertOk()->streamedContent();

    expect($csv)->toContain('zz.export@example.invalid')
        ->not->toContain('zz.oppose@example.invalid')
        ->not->toContain('zz.rebond@example.invalid')
        ->not->toContain('zz.autre@example.invalid');
});

test('L4-C console — un compte sans droit d’export ne peut pas exporter', function () {
    $this->actingAs(l4cConsoleUser($this->workspace->id, 'viewer', 'l4c.noexport@example.invalid'));

    $this->get('/api/v1/crm/personnes/export')->assertForbidden();
});

// ─────────────────────────────────────────────────────────────────────────────
// Commandes
// ─────────────────────────────────────────────────────────────────────────────

test('L4-C commande — le rattrapage de l’arbitrage est À BLANC par défaut, puis retire pending_match en appliquant', function () {
    $email = 'zz.arbitrage@example.invalid';
    $key = hash('sha256', 'l4c-console|' . $email);
    $personne = l4cPersonne($this->workspace->id, $email);

    $activite = (int) DB::table('activities')->insertGetId([
        'workspace_id' => $this->workspace->id,
        'type' => 'newsletter_optin',
        'kind' => 'newsletter_optin',
        'occurred_at' => now()->subMonth(),
        'person_key' => $key,
        'external_ref' => 'site:event:' . Str::uuid(),
        'title' => 'newsletter optin',
        'payload' => json_encode(['pending_match' => ['email' => $email, 'subject_ref' => 'site:newsletter_subscriber:1']]),
        'created_at' => now(),
    ]);
    // Hors périmètre : un rendez-vous sans SIREN reste dans l'arbitrage.
    DB::table('activities')->insert([
        'workspace_id' => $this->workspace->id,
        'type' => 'calendly_booked',
        'kind' => 'calendly_booked',
        'occurred_at' => now()->subMonth(),
        'person_key' => $key,
        'external_ref' => 'site:event:' . Str::uuid(),
        'title' => 'calendly booked',
        'payload' => json_encode(['pending_match' => ['email' => $email]]),
        'created_at' => now(),
    ]);

    Artisan::call('crm:personnes-depuis-arbitrage', ['--a-blanc' => true]);
    $sortie = Artisan::output();

    expect($sortie)->toContain('À BLANC')->toContain('rattachables à une personne existante : 1')
        ->and($sortie)->not->toContain($email)
        ->and(DB::table('activities')->where('id', $activite)->value('subject_id'))->toBeNull();

    Artisan::call('crm:personnes-depuis-arbitrage', ['--appliquer' => true]);
    expect(Artisan::output())->not->toContain($email);

    $apres = DB::table('activities')->where('id', $activite)->first();
    expect($apres->subject_type)->toBe('personne')
        ->and((int) $apres->subject_id)->toBe($personne)
        ->and(str_contains((string) $apres->payload, $email))->toBeFalse();

    expect(DB::table('activities')->where('kind', 'calendly_booked')->whereNull('subject_id')->count())->toBe(1);
});

test('L4-C commande — le rattrapage refuse un type hors lettre et guide', function () {
    expect(Artisan::call('crm:personnes-depuis-arbitrage', ['--kinds' => 'calendly_booked']))->toBe(1);
});

test('L4-C commande — la sentinelle crie quand le flux reçoit sans rattacher, drapeau ouvert ; se tait drapeau fermé', function () {
    DB::table('activities')->insert([
        'workspace_id' => $this->workspace->id,
        'type' => 'newsletter_optin',
        'kind' => 'newsletter_optin',
        'occurred_at' => now(),
        'person_key' => hash('sha256', 'l4c-sentinelle'),
        'external_ref' => 'site:event:' . Str::uuid(),
        'title' => 'newsletter optin',
        'payload' => '{}',
        'created_at' => now(),
    ]);

    config(['crm.ingest.personnes_enabled' => false]);
    expect(Artisan::call('crm:sonde-personnes'))->toBe(0);

    config(['crm.ingest.personnes_enabled' => true, 'alertes.telegram.token' => '']);
    expect(Artisan::call('crm:sonde-personnes'))->toBe(1);

    // TÉMOIN : dès qu'un événement est rattaché à une personne, elle se tait.
    DB::table('activities')->update(['subject_type' => 'personne', 'subject_id' => 1]);
    expect(Artisan::call('crm:sonde-personnes'))->toBe(0);
});

test('L4-C commande — la purge à 3 ans ne vise que les personnes non rattachées, sans abonnement actif, inactives', function () {
    config(['crm.purges_enabled' => true]);

    $vieille = l4cPersonne($this->workspace->id, 'zz.vieille@example.invalid', ['derniere_interaction_at' => now()->subYears(4)], 'desabonne');
    $vieilleAbonnee = l4cPersonne($this->workspace->id, 'zz.fidele@example.invalid', ['derniere_interaction_at' => now()->subYears(4)]);
    $recente = l4cPersonne($this->workspace->id, 'zz.recente@example.invalid', [], null);

    Artisan::call('rgpd:purge-personnes', ['--dry-run' => true]);
    expect(DB::table('personnes')->count())->toBe(3);

    Artisan::call('rgpd:purge-personnes');

    expect(DB::table('personnes')->pluck('id')->sort()->values()->all())->toBe([$vieilleAbonnee, $recente])
        ->and(DB::table('abonnements')->where('personne_id', $vieille)->count())->toBe(0);
});

test('L4-C commande — la purge est inerte tant que CRM_PURGE_ENABLED est fermé', function () {
    config(['crm.purges_enabled' => false]);
    l4cPersonne($this->workspace->id, 'zz.inerte@example.invalid', ['derniere_interaction_at' => now()->subYears(4)], null);

    expect(Artisan::call('rgpd:purge-personnes'))->toBe(1)
        ->and(DB::table('personnes')->count())->toBe(1);
});
