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

// ── B14-008 — CONTRAT SORTANT : VERSION + VOCABULAIRE ───────────────────────
//
// Le contrat était versionné à l'ENTRÉE (SiteSyncEvent::SCHEMA_VERSION, refus
// dur `unsupported_schema_version`) et pas à la SORTIE, et aucun test ne
// confrontait les deux vocabulaires — mesure du 2026-08-22 :
// `grep -rln "EVENT_TYPES|SCHEMA_VERSION" backend/tests/` ne rendait aucun
// fichier. Les deux gardes ci-dessous ferment ce trou.

test('B14-008 — le corps émis porte schema_version, et exactement les clés du contrat', function () {
    outboundEnable();
    Http::fake([OUTBOUND_TEST_URL => Http::response(['ok' => true], 200)]);

    (new ConsentOutboundRecorder)->recordForEmail(
        'consent_optout',
        'Versionne@Example.test',
        'business',
        personKey: 'pk-8',
    );
    $this->artisan('crm:flush-outbound')->assertExitCode(0);

    $corps = null;
    Http::assertSent(function ($request) use (&$corps): bool {
        $corps = json_decode($request->body(), true);

        return true;
    });

    expect(($corps['schema_version'] ?? null) === ConsentOutboundRecorder::SCHEMA_VERSION)->toBeTrue(
        'Le corps émis vers le site ne porte plus la version du contrat (attendu schema_version = '
        . ConsentOutboundRecorder::SCHEMA_VERSION . ', reçu ' . var_export($corps['schema_version'] ?? null, true)
        . '). Geste : rétablir `\'schema_version\' => ConsentOutboundRecorder::SCHEMA_VERSION` en tête du '
        . 'tableau de CrmFlushOutbound::dispatchOne(). Sans version, le site ne peut pas savoir à quelle '
        . 'génération du contrat il parle, et une clé qui change de sens casse en silence.',
    );

    // ⚠️ Cette liste est une TRANSCRIPTION À LA MAIN du parseur du site
    // (`parseInboundPayload`, dépôt Axion-IA : src/server/crm-sync/inbound.ts) :
    // ce dépôt-ci ne peut pas lire l'autre, la garde ne certifie donc PAS l'état
    // réel du site — elle certifie que le CRM ne s'en écarte pas sans que
    // quelqu'un ait à toucher cette ligne, et donc à aller vérifier en face.
    $clesEmises = array_keys($corps);
    sort($clesEmises);
    $clesDuContrat = [
        'schema_version', 'event_id', 'event_type', 'person_key',
        'email_hash', 'scope', 'origin', 'occurred_at',
    ];
    sort($clesDuContrat);

    expect($clesEmises === $clesDuContrat)->toBeTrue(
        'Le jeu de clés du corps CRM → site a changé (émis : ' . implode(', ', $clesEmises)
        . ' / contrat : ' . implode(', ', $clesDuContrat) . '). Geste : propager le changement dans '
        . '`parseInboundPayload` du dépôt site (src/server/crm-sync/inbound.ts) AVANT de le livrer ici, '
        . 'puis mettre cette liste à jour. Une clé ajoutée d\'un seul côté est une donnée que le CRM croit '
        . 'transmettre et que le site jette.',
    );
});

test('B14-008 — chaque type du vocabulaire sortant est réellement émettable, et le vocabulaire est celui du site', function () {
    outboundEnable();
    Http::fake([OUTBOUND_TEST_URL => Http::response(['ok' => true], 200)]);

    // ⚠️ Transcription à la main, même réserve que ci-dessus : le vocabulaire
    // accepté par le site est l'ensemble `EVENT_TYPES` de inbound.ts:133 et
    // `SCOPES` de inbound.ts:134.
    $typesDuSite = ['consent_optout', 'consent_optin', 'erasure'];
    $scopesDuSite = ['business', 'vivier'];

    expect(ConsentOutboundRecorder::EVENT_TYPES === $typesDuSite)->toBeTrue(
        'Le vocabulaire émis (ConsentOutboundRecorder::EVENT_TYPES = '
        . implode(', ', ConsentOutboundRecorder::EVENT_TYPES) . ') ne correspond plus à celui que le site '
        . 'accepte (' . implode(', ', $typesDuSite) . '). Geste : ajouter le type des DEUX côtés — ici, dans '
        . 'la contrainte CHECK de la migration crm_outbound_events, et dans `EVENT_TYPES` de '
        . 'src/server/crm-sync/inbound.ts — sinon le site répond 422 et la ligne passe en `gave_up` : '
        . 'l\'opposition RGPD n\'arrive jamais.',
    );
    expect(ConsentOutboundRecorder::SCOPES === $scopesDuSite)->toBeTrue(
        'Les portées émises (' . implode(', ', ConsentOutboundRecorder::SCOPES) . ') ne correspondent plus '
        . 'à celles que le site accepte (' . implode(', ', $scopesDuSite) . '). Même geste : les deux côtés '
        . 'ensemble, ou le message est refusé 422.',
    );

    // La constante PHP n'est pas la seule source : la table porte une contrainte
    // CHECK. Émettre RÉELLEMENT chaque type fait rougir l'ajout d'une valeur à
    // la constante sans la migration correspondante (QueryException), et
    // l'inverse (une valeur retirée de la constante mais toujours en base).
    $emis = [];
    foreach (ConsentOutboundRecorder::EVENT_TYPES as $type) {
        (new ConsentOutboundRecorder)->recordForEmail($type, $type . '@example.test', 'business');
        $this->artisan('crm:flush-outbound')->assertExitCode(0);
        $emis[] = $type;
    }

    expect($emis === ConsentOutboundRecorder::EVENT_TYPES)->toBeTrue(
        'Un type du vocabulaire sortant n\'a pas pu être mis en file ni émis. Geste : vérifier que la '
        . 'contrainte CHECK de `crm_outbound_events.event_type` liste exactement '
        . 'ConsentOutboundRecorder::EVENT_TYPES.',
    );
    expect(DB::table('crm_outbound_events')->where('status', 'sent')->count())
        ->toBe(count(ConsentOutboundRecorder::EVENT_TYPES));
});

// ── B14-001 / I49-001 — un type déclaré a un producteur, ou l'assume ─────────

test('B14-001 : chaque type du vocabulaire sortant a un producteur dans app/, ou est déclaré sans producteur', function () {
    // ⚠️ scandir récursif et NON RecursiveDirectoryIterator : mesuré dans ce
    // dépôt, l'itérateur TRONQUE le parcours sur ce montage Docker (14 fichiers
    // rendus sur 56 réels). Une garde qui ne voit pas les fichiers certifie le
    // vide — c'est exactement la faute que ce constat reproche au vocabulaire.
    $lister = function (string $racine) use (&$lister): array {
        $trouves = [];
        foreach (scandir($racine) ?: [] as $entree) {
            if ($entree === '.' || $entree === '..') {
                continue;
            }
            $chemin = $racine . DIRECTORY_SEPARATOR . $entree;
            if (is_dir($chemin)) {
                $trouves = array_merge($trouves, $lister($chemin));
            } elseif (str_ends_with($entree, '.php')) {
                $trouves[] = $chemin;
            }
        }

        return $trouves;
    };

    $fichiers = $lister(base_path('app'));

    // Le parcours se prouve AVANT de servir de preuve : un balayage tronqué
    // rendrait « aucun producteur » pour tous les types — un rouge illisible,
    // ou pire un vert si la garde était écrite à l'envers.
    expect(count($fichiers) >= 50)->toBeTrue(
        'Le balayage de app/ n\'a rendu que ' . count($fichiers) . ' fichiers PHP, ce qui est trop peu pour '
        . 'que cette garde prouve quoi que ce soit. Geste : vérifier que `scandir` traverse bien le montage '
        . '(le dépôt en compte plusieurs centaines) avant de croire le verdict ci-dessous.',
    );

    $declaration = realpath(base_path('app/Crm/Outbound/ConsentOutboundRecorder.php'));

    // ── CE QUE CETTE GARDE INSPECTE, ET CE QU'ELLE N'INSPECTE PAS ───────────
    // Elle cherche le LITTÉRAL du type dans les fichiers de app/ qui nomment le
    // recorder. Elle NE prouve PAS que ce littéral est bien l'argument passé à
    // `record()` : le faire demanderait un analyseur syntaxique, et
    // `RgpdRequestsController` passe d'ailleurs par une variable
    // (`queueOutbound($eventType, …)`). Elle attrape le défaut MESURÉ le
    // 2026-08-22 : un type que pas un seul fichier du produit ne nomme.
    $contenus = [];
    foreach ($fichiers as $fichier) {
        if (realpath($fichier) === $declaration) {
            continue; // la déclaration ne se compte pas comme productrice d'elle-même
        }
        $contenus[$fichier] = file_get_contents($fichier) ?: '';
    }

    $utilisateurs = array_filter($contenus, fn (string $c) => str_contains($c, 'ConsentOutboundRecorder'));

    expect(count($utilisateurs) >= 1)->toBeTrue(
        'Aucun fichier de app/ ne nomme ConsentOutboundRecorder : soit le canal sortant a été débranché, '
        . 'soit le balayage est faux. Geste : rechercher `ConsentOutboundRecorder` dans app/ et, s\'il n\'y a '
        . 'vraiment plus de producteur, retirer le canal plutôt que le laisser en façade.',
    );

    $reserves = ConsentOutboundRecorder::EVENT_TYPES_SANS_PRODUCTEUR;
    $horsVocabulaire = array_diff($reserves, ConsentOutboundRecorder::EVENT_TYPES);

    expect($horsVocabulaire === [])->toBeTrue(
        'EVENT_TYPES_SANS_PRODUCTEUR contient une valeur absente de EVENT_TYPES : '
        . implode(', ', $horsVocabulaire) . '. Geste : une réserve se réserve DANS le vocabulaire, pas à côté.',
    );

    foreach (ConsentOutboundRecorder::EVENT_TYPES as $type) {
        $porte = fn (string $c) => str_contains($c, "'" . $type . "'") || str_contains($c, '"' . $type . '"');

        if (in_array($type, $reserves, true)) {
            // Une réserve qui trouve un producteur n'est plus une réserve : la
            // liste doit suivre, sinon elle se met à mentir dans l'autre sens.
            $partout = array_keys(array_filter($contenus, $porte));

            expect($partout === [])->toBeTrue(
                'Le type « ' . $type . ' » est déclaré SANS producteur '
                . '(ConsentOutboundRecorder::EVENT_TYPES_SANS_PRODUCTEUR) mais il apparaît maintenant dans : '
                . implode(', ', $partout) . '. Geste : s\'il est réellement émis, le retirer de '
                . 'EVENT_TYPES_SANS_PRODUCTEUR et effacer le paragraphe qui le donne pour une réserve ; '
                . 'sinon renommer le littéral trouvé — constat B14-001 / I49-001.',
            );

            continue;
        }

        $producteurs = array_keys(array_filter($utilisateurs, $porte));

        expect($producteurs !== [])->toBeTrue(
            'Le type « ' . $type . ' » est déclaré dans ConsentOutboundRecorder::EVENT_TYPES (et dans la '
            . 'contrainte CHECK de crm_outbound_events) mais AUCUN fichier de app/ utilisant le recorder ne '
            . 'le nomme : le vocabulaire promet un événement que le produit ne sait pas fabriquer. '
            . 'Geste : soit écrire le producteur, soit inscrire le type dans '
            . 'ConsentOutboundRecorder::EVENT_TYPES_SANS_PRODUCTEUR avec le motif de la réserve juste '
            . 'au-dessus de la constante — constat B14-001 / I49-001.',
        );
    }
});

// ── B14-014 — le doublon enrichit, il n'écrase pas ──────────────────────────

test('B14-014 : un second geste sur une opposition déjà en attente enrichit son contexte au lieu de le jeter', function () {
    $recorder = new ConsentOutboundRecorder;

    $premier = $recorder->recordForEmail(
        'consent_optout',
        'fusion@example.test',
        'business',
        payload: ['surface' => 'console:journalists', 'journalist_id' => 7],
    );

    // Le temps doit AVANCER : sans cela `updated_at` vaudrait `created_at` par
    // construction, et l'assertion de fraîcheur ne mesurerait rien.
    $this->travel(2)->minutes();

    $second = $recorder->recordForEmail(
        'consent_optout',
        'fusion@example.test',
        'business',
        payload: ['surface' => 'console:rgpd_requests', 'rgpd_request_id' => 42],
    );

    expect($second)->toBe($premier)
        ->and(DB::table('crm_outbound_events')->count())->toBe(1);

    $ligne = DB::table('crm_outbound_events')->where('event_id', $premier)->first();
    $contexte = json_decode((string) $ligne->payload, true);

    expect(is_array($contexte) && ($contexte['journalist_id'] ?? null) === 7)->toBeTrue(
        'Le contexte du PREMIER geste a disparu du journal : ' . (string) $ligne->payload . '. Geste : dans '
        . 'la branche de doublon de ConsentOutboundRecorder::record(), fusionner l\'ancien payload avec le '
        . 'nouveau (array_merge) au lieu de le remplacer — constat B14-014.',
    );

    expect(is_array($contexte) && ($contexte['rgpd_request_id'] ?? null) === 42)->toBeTrue(
        'Le contexte du SECOND geste a été JETÉ : ' . (string) $ligne->payload . '. La ligne reste vraie sur '
        . 'QUI est opposé et fausse sur POURQUOI. Geste : dans la branche de doublon de '
        . 'ConsentOutboundRecorder::record(), écrire le payload fusionné avant le retour anticipé — '
        . 'constat B14-014.',
    );

    expect(is_array($contexte) && ($contexte['surface'] ?? null) === 'console:rgpd_requests')->toBeTrue(
        'Sur une clé présente des deux côtés, c\'est la décision la PLUS RÉCENTE qui doit gagner ; ici '
        . '`surface` vaut ' . var_export($contexte['surface'] ?? null, true) . '. Geste : '
        . 'array_merge($ancien, $nouveau) et non l\'inverse — constat B14-014.',
    );

    expect(strtotime((string) $ligne->updated_at) > strtotime((string) $ligne->created_at))->toBeTrue(
        'La ligne n\'a pas été rafraîchie : updated_at=' . (string) $ligne->updated_at . ', créée à '
        . (string) $ligne->created_at . '. Un doublon re-décidé deux minutes plus tard laisse un journal qui '
        . 'ment sur l\'âge de la décision. Geste : mettre `updated_at` à `now()` dans la branche de doublon — '
        . 'constat B14-014.',
    );
});

test('B14-014 : le doublon n\'efface PAS le backoff d\'une ligne déjà en échec', function () {
    // Une ligne en échec attend son prochain essai. Si un clic de plus dans la
    // console remettait `next_attempt_at` à maintenant, un doublon re-cliqué en
    // boucle rejouerait le site sans jamais attendre — le backoff ne servirait
    // plus à rien précisément le jour où le site est en panne.
    $eventId = (string) Str::uuid();
    $prochainEssai = now()->addHour()->startOfSecond();

    DB::table('crm_outbound_events')->insert([
        'event_id' => $eventId,
        'event_type' => 'consent_optout',
        'person_key' => null,
        'email_hash' => outboundHash('backoff@example.test'),
        'scope' => 'business',
        'origin' => 'crm',
        'payload' => '{"surface":"console:journalists"}',
        'status' => 'failed',
        'attempts' => 3,
        'next_attempt_at' => $prochainEssai,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new ConsentOutboundRecorder)->recordForEmail(
        'consent_optout',
        'backoff@example.test',
        'business',
        payload: ['surface' => 'console:rgpd_requests'],
    );

    $ligne = DB::table('crm_outbound_events')->where('event_id', $eventId)->first();

    expect(strtotime((string) $ligne->next_attempt_at) === $prochainEssai->getTimestamp())->toBeTrue(
        'Le doublon a déplacé next_attempt_at (' . (string) $ligne->next_attempt_at . ' au lieu de '
        . $prochainEssai->toDateTimeString() . ') : le backoff de la ligne en échec a été remis à zéro. '
        . 'Geste : dans la branche de doublon de ConsentOutboundRecorder::record(), ne mettre à jour que '
        . '`payload` et `updated_at` — jamais `next_attempt_at` ni `attempts` — constat B14-014.',
    );

    expect((int) $ligne->attempts)->toBe(3);
});
