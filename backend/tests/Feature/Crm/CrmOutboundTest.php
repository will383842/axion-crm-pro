<?php

use App\Crm\Outbound\ConsentOutboundRecorder;
use App\Crm\Outbound\OutboundRejection;
use App\Crm\Rgpd\SiteGdprService;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // Les roles doivent exister AVANT toute attribution.
    $this->seed(PermissionsAndRolesSeeder::class);
});

/**
 * MINI-OUTBOX CRM → SITE (lot L5) — convergence BIDIRECTIONNELLE des
 * consentements.
 *
 * Garanties couvertes :
 *   - ANTI-BOUCLE : un événement d'origine « site » n'est jamais mis en file
 *     (refus BRUYANT), et les services qui ingèrent les oppositions VENUES du
 *     site (SiteGdprService, SiteSyncIngestService) n'alimentent pas l'outbox ;
 *   - INERTIE : drapeau OFF ⇒ le producteur écrit sa ligne locale mais
 *     l'émetteur REFUSE et aucun POST ne part ;
 *   - RETRY : 422 = abandon immédiat, 503 = attente SANS consommer d'essai,
 *     autre échec = essai consommé + backoff, plafond = `gave_up` ;
 *   - SIGNATURE : `<timestamp>.<corps>` en HMAC-SHA256 avec le secret PARTAGÉ ;
 *   - OBSERVABILITÉ : compteur de réceptions + backlog dans /observability/summary.
 */
define('OUTBOUND_TEST_SECRET', 'secret-de-test-' . str_repeat('e5f6', 12));

const OUTBOUND_TEST_URL = 'https://site.test/api/internal/crm-webhook';

function outboundHash(string $email): string
{
    return hash('sha256', mb_strtolower(trim($email)));
}

/** Active le canal (drapeau + destination + secret) pour les tests d'émission. */
function outboundEnable(): void
{
    config([
        'crm.outbound_enabled' => true,
        'crm.outbound.site_webhook_url' => OUTBOUND_TEST_URL,
        'crm.ingest.hmac_secret' => OUTBOUND_TEST_SECRET,
    ]);
}

/** Pose une ligne d'outbox directement (état de départ d'un scénario de retry). */
function outboundSeed(int $attempts = 0, string $status = 'pending'): string
{
    $eventId = (string) Str::uuid();

    DB::table('crm_outbound_events')->insert([
        'event_id' => $eventId,
        'event_type' => 'consent_optout',
        'person_key' => null,
        'email_hash' => outboundHash('cible@example.test'),
        'scope' => 'business',
        'origin' => 'crm',
        'payload' => '{}',
        'status' => $status,
        'attempts' => $attempts,
        'next_attempt_at' => now()->subMinute(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $eventId;
}

function outboundBusinessWorkspaceId(): string
{
    $existing = DB::table('workspaces')->where('slug', 'axion-ia')->value('id');
    if ($existing !== null) {
        return (string) $existing;
    }

    $id = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $id,
        'slug' => 'axion-ia',
        'name' => 'Axion-IA',
        'settings' => '{}',
        'cost_cap_eur' => 100,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

// ── ANTI-BOUCLE ─────────────────────────────────────────────────────────────

test('un événement d\'origine SITE est REFUSÉ et ne laisse aucune ligne', function () {
    $recorder = new ConsentOutboundRecorder;

    // Le refus doit être bruyant : un no-op silencieux laisserait une boucle
    // s'installer sans que rien ne la signale.
    try {
        $recorder->record('consent_optout', outboundHash('a@example.test'), 'business', origin: 'site');
        $this->fail('Un événement d\'origine « site » aurait dû être refusé.');
    } catch (OutboundRejection $e) {
        expect($e->errorCode)->toBe('origin_not_crm');
    }

    expect(DB::table('crm_outbound_events')->count())->toBe(0);
});

test('les oppositions VENUES du site n\'alimentent jamais l\'outbox', function () {
    outboundBusinessWorkspaceId();

    // SiteGdprService inscrit une opposition NÉE DU SITE (art. 17 self-service).
    app(SiteGdprService::class)->erase('pk-site-' . Str::random(8), 'venu-du-site@example.test', 'business');

    expect(DB::table('opt_out')->where('scope', 'business')->count())->toBe(1)
        ->and(DB::table('crm_outbound_events')->count())->toBe(0);
});

test('la base elle-même refuse une origine autre que crm', function () {
    // Garde-fou STRUCTUREL doublant le garde-fou applicatif : une régression
    // future qui contournerait le recorder heurterait Postgres.
    //
    // L'insertion est isolée dans un SAVEPOINT : en Postgres, une violation de
    // contrainte AVORTE la transaction courante — sans point de reprise, tout
    // le reste du test (et le rollback de RefreshDatabase) partirait en
    // « current transaction is aborted ».
    $rejected = false;

    DB::beginTransaction();
    try {
        DB::table('crm_outbound_events')->insert([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'consent_optout',
            'email_hash' => outboundHash('x@example.test'),
            'scope' => 'business',
            'origin' => 'site',
            'payload' => '{}',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException $e) {
        $rejected = true;
    } finally {
        DB::rollBack();
    }

    expect($rejected)->toBeTrue()
        ->and(DB::table('crm_outbound_events')->count())->toBe(0);
});

// ── INERTIE ─────────────────────────────────────────────────────────────────

test('drapeau OFF : le producteur écrit, l\'émetteur REFUSE, rien ne part', function () {
    Http::fake();
    config([
        'crm.outbound_enabled' => false,
        'crm.outbound.site_webhook_url' => OUTBOUND_TEST_URL,
        'crm.ingest.hmac_secret' => OUTBOUND_TEST_SECRET,
    ]);

    (new ConsentOutboundRecorder)->recordForEmail('consent_optout', 'inerte@example.test', 'business');

    expect(DB::table('crm_outbound_events')->where('status', 'pending')->count())->toBe(1);

    $this->artisan('crm:flush-outbound')->assertExitCode(1);

    Http::assertNothingSent();
    expect(DB::table('crm_outbound_events')->where('status', 'pending')->count())->toBe(1);
});

test('drapeau ON mais destination vide : l\'émetteur refuse plutôt que de deviner', function () {
    Http::fake();
    config([
        'crm.outbound_enabled' => true,
        'crm.outbound.site_webhook_url' => '',
        'crm.ingest.hmac_secret' => OUTBOUND_TEST_SECRET,
    ]);
    outboundSeed();

    $this->artisan('crm:flush-outbound')->assertExitCode(1);
    Http::assertNothingSent();
});

// ── ÉMISSION + SIGNATURE ────────────────────────────────────────────────────

test('un 2xx solde la ligne et le corps est signé <timestamp>.<corps>', function () {
    outboundEnable();
    Http::fake([OUTBOUND_TEST_URL => Http::response(['ok' => true], 200)]);

    $eventId = (new ConsentOutboundRecorder)->recordForEmail(
        'consent_optout',
        'Signee@Example.test',
        'business',
        personKey: 'pk-42',
    );

    $this->artisan('crm:flush-outbound')->assertExitCode(0);

    Http::assertSent(function ($request) use ($eventId): bool {
        $body = $request->body();
        $timestamp = $request->header('X-Crm-Timestamp')[0] ?? '';
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, OUTBOUND_TEST_SECRET);

        $decoded = json_decode($body, true);

        return hash_equals($expected, $request->header('X-Crm-Signature')[0] ?? '')
            && $decoded['event_id'] === $eventId
            && $decoded['event_type'] === 'consent_optout'
            && $decoded['origin'] === 'crm'
            && $decoded['scope'] === 'business'
            && $decoded['person_key'] === 'pk-42'
            // La casse de l'email n'entre pas dans le hash : les deux systèmes
            // doivent le calculer à l'identique.
            && $decoded['email_hash'] === outboundHash('signee@example.test')
            && is_string($decoded['occurred_at']);
    });

    $row = DB::table('crm_outbound_events')->where('event_id', $eventId)->first();
    expect($row->status)->toBe('sent')
        ->and((int) $row->attempts)->toBe(1)
        ->and($row->sent_at)->not->toBeNull();
});

test('le corps ne transporte JAMAIS l\'email en clair', function () {
    outboundEnable();
    Http::fake([OUTBOUND_TEST_URL => Http::response(['ok' => true], 200)]);

    (new ConsentOutboundRecorder)->recordForEmail('erasure', 'secret-pii@example.test', 'vivier');
    $this->artisan('crm:flush-outbound')->assertExitCode(0);

    Http::assertSent(fn ($request): bool => ! str_contains($request->body(), 'secret-pii@example.test'));
    expect(DB::table('crm_outbound_events')->whereNotNull('email_hash')->count())->toBe(1);
});

// ── RETRY ───────────────────────────────────────────────────────────────────

test('un 422 abandonne IMMÉDIATEMENT (le rejeu échouerait éternellement)', function () {
    outboundEnable();
    Http::fake([OUTBOUND_TEST_URL => Http::response(['error' => 'invalid'], 422)]);
    $eventId = outboundSeed();

    $this->artisan('crm:flush-outbound')->assertExitCode(0);

    $row = DB::table('crm_outbound_events')->where('event_id', $eventId)->first();
    expect($row->status)->toBe('gave_up')
        ->and((int) $row->attempts)->toBe(1)
        ->and($row->next_attempt_at)->toBeNull();
});

test('un 503 fait ATTENDRE sans consommer de tentative', function () {
    outboundEnable();
    Http::fake([OUTBOUND_TEST_URL => Http::response(['error' => 'unavailable'], 503)]);
    $eventId = outboundSeed(attempts: 3);

    $this->artisan('crm:flush-outbound')->assertExitCode(0);

    $row = DB::table('crm_outbound_events')->where('event_id', $eventId)->first();

    // LE point : `attempts` est strictement inchangé. Une maintenance de
    // quelques heures ne doit pas brûler le budget de retry et abandonner des
    // oppositions parfaitement valides.
    expect((int) $row->attempts)->toBe(3)
        ->and($row->status)->toBe('pending')
        ->and($row->next_attempt_at)->not->toBeNull()
        ->and(strtotime((string) $row->next_attempt_at))->toBeGreaterThan(time());
});

test('un 500 consomme une tentative et programme un backoff', function () {
    outboundEnable();
    Http::fake([OUTBOUND_TEST_URL => Http::response('boom', 500)]);
    $eventId = outboundSeed(attempts: 1);

    $this->artisan('crm:flush-outbound')->assertExitCode(0);

    $row = DB::table('crm_outbound_events')->where('event_id', $eventId)->first();
    expect((int) $row->attempts)->toBe(2)
        ->and($row->status)->toBe('failed')
        ->and(strtotime((string) $row->next_attempt_at))->toBeGreaterThan(time());
});

test('au plafond d\'essais, la ligne passe en gave_up (état terminal visible)', function () {
    outboundEnable();
    Http::fake([OUTBOUND_TEST_URL => Http::response('boom', 500)]);
    $eventId = outboundSeed(attempts: 7, status: 'failed');

    $this->artisan('crm:flush-outbound')->assertExitCode(0);

    $row = DB::table('crm_outbound_events')->where('event_id', $eventId)->first();
    expect($row->status)->toBe('gave_up')->and((int) $row->attempts)->toBe(8);
});

test('une ligne non encore due n\'est pas rejouée', function () {
    outboundEnable();
    Http::fake();

    DB::table('crm_outbound_events')->insert([
        'event_id' => (string) Str::uuid(),
        'event_type' => 'consent_optout',
        'email_hash' => outboundHash('plus-tard@example.test'),
        'scope' => 'business',
        'origin' => 'crm',
        'payload' => '{}',
        'status' => 'failed',
        'attempts' => 2,
        'next_attempt_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('crm:flush-outbound')->assertExitCode(0);
    Http::assertNothingSent();
});

test('deux oppositions identiques encore en attente ne font qu\'un message', function () {
    $recorder = new ConsentOutboundRecorder;

    $first = $recorder->recordForEmail('consent_optout', 'double@example.test', 'business');
    $second = $recorder->recordForEmail('consent_optout', 'double@example.test', 'business');

    expect($second)->toBe($first)
        ->and(DB::table('crm_outbound_events')->count())->toBe(1);
});

// ── BRANCHEMENTS CONSOLE ────────────────────────────────────────────────────

test('une opposition décidée dans la console met un événement en file', function () {
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-outbound', 'name' => 'WS', 'settings' => [],
    ]);
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'console@example.test', 'name' => 'C',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $workspace->id,
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
    $this->actingAs($user);

    $journalistId = DB::table('journalists')->insertGetId([
        'workspace_id' => $workspace->id,
        'last_name' => 'Durand',
        'email' => 'redaction@example.test',
        'source' => 'ours',
        'opt_out' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson("/api/v1/journalists/{$journalistId}/opt-out")->assertOk();

    $row = DB::table('crm_outbound_events')->first();
    expect($row)->not->toBeNull()
        ->and($row->event_type)->toBe('consent_optout')
        ->and($row->origin)->toBe('crm')
        ->and($row->email_hash)->toBe(outboundHash('redaction@example.test'));
});

// ── OBSERVABILITÉ (§2.9) ────────────────────────────────────────────────────

test('le résumé d\'observabilité expose les réceptions et le backlog', function () {
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-obs', 'name' => 'WS', 'settings' => [],
    ]);
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'obs@example.test', 'name' => 'O',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $workspace->id,
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
    $this->actingAs($user);

    foreach (['aujourdhui-1', 'aujourdhui-2'] as $ref) {
        DB::table('activities')->insert([
            'workspace_id' => $workspace->id,
            'type' => 'site_sync',
            'external_ref' => 'site:event:' . $ref,
            'created_at' => now(),
        ]);
    }
    // Hors fenêtre du jour, mais dans les 7 jours.
    DB::table('activities')->insert([
        'workspace_id' => $workspace->id,
        'type' => 'site_sync',
        'external_ref' => 'site:event:hier',
        'created_at' => now()->subDays(2),
    ]);
    // Une activité SANS external_ref de synchro ne doit pas être comptée.
    DB::table('activities')->insert([
        'workspace_id' => $workspace->id,
        'type' => 'note',
        'created_at' => now(),
    ]);

    outboundSeed();
    $abandonne = outboundSeed(attempts: 8, status: 'gave_up');

    $response = $this->getJson('/api/v1/observability/summary')->assertOk();

    expect($response->json('data.site_sync.ingested_today'))->toBe(2)
        ->and($response->json('data.site_sync.ingested_7d'))->toBe(3)
        ->and($response->json('data.site_sync.last_ingested_at'))->not->toBeNull()
        ->and($response->json('data.outbound.pending'))->toBe(1)
        ->and($response->json('data.outbound.gave_up'))->toBe(1)
        ->and($abandonne)->not->toBeEmpty();
});
