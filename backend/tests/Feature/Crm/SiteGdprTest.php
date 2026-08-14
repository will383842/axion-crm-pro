<?php

use App\Crm\Taxonomy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * VOLET RGPD BI-SYSTÈME (lot L4) — `POST /api/internal/site-sync/gdpr`
 * + purges automatiques par univers.
 *
 * Garanties :
 *   - INERTIE : drapeau canal OFF ⇒ 503 (la demande sera rejouée), purges
 *     OFF ⇒ refus ;
 *   - art. 17 : l'effacement atteint les fiches nées de la SYNCHRO
 *     (person_key) ET celles nées de la COLLECTE (email, sans person_key),
 *     dans les DEUX univers, timeline comprise, et laisse le hash en
 *     opposition (anti-réinsertion) SANS l'email en clair ;
 *   - art. 15 : l'export agrège les deux univers ;
 *   - purges : 2 ans vivier / refusés J+90 / 3 ans business, et JAMAIS une
 *     personne qui a interagi.
 */
define('GDPR_TEST_SECRET', 'secret-de-test-' . str_repeat('c3d4', 12));

function gdprBusinessWorkspaceId(): string
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

function gdprVivierWorkspaceId(): string
{
    return (string) DB::table('workspaces')->where('slug', Taxonomy::VIVIER_WORKSPACE_SLUG)->value('id');
}

/**
 * @param  array<string, mixed>  $payload
 */
function gdprPost(array $payload): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = (string) time();
    $signature = hash_hmac('sha256', $timestamp . '.' . $body, GDPR_TEST_SECRET);

    return test()->call('POST', '/api/internal/site-sync/gdpr', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SITE_TIMESTAMP' => $timestamp,
        'HTTP_X_SITE_SIGNATURE' => $signature,
    ], $body);
}

const GDPR_EMAIL = 'zz.gdpr@example.invalid';

function gdprPersonKey(): string
{
    // Person_key SALÉ côté site : n'importe quel sha256 fait l'affaire, il ne
    // se DÉRIVE pas de l'email (c'est tout le point).
    return hash('sha256', 'sel-site|' . GDPR_EMAIL);
}

/** Peuple les deux univers : une fiche SYNCHRO (person_key) + une fiche COLLECTE (email seul). */
function gdprSeedPerson(): array
{
    $ws = gdprBusinessWorkspaceId();
    $vivier = gdprVivierWorkspaceId();

    $companyId = DB::table('companies')->insertGetId([
        'workspace_id' => $ws,
        'siren' => '900000303',
        'denomination' => 'ZZ GDPR SAS',
        'signals' => '{}',
        'metadata' => '{}',
        'quality_score' => 0,
        'relation_type' => 'prospect',
        'lifecycle_stage' => 'nouveau',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Fiche née de la SYNCHRO : person_key posé.
    DB::table('contacts')->insert([
        'workspace_id' => $ws,
        'company_id' => $companyId,
        'first_name' => 'Zoé',
        'last_name' => 'ZZ GDPR',
        'email' => GDPR_EMAIL,
        'person_key' => gdprPersonKey(),
        'sources' => '["site"]',
        'metadata' => '{}',
        'legal_basis' => 'precontractual',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Fiche née de la COLLECTE : MÊME email, PAS de person_key (autre company
    // pour contourner l'index unique nom+entreprise).
    $companyB = DB::table('companies')->insertGetId([
        'workspace_id' => $ws,
        'siren' => '900000304',
        'denomination' => 'ZZ GDPR HOLDING',
        'signals' => '{}',
        'metadata' => '{}',
        'quality_score' => 0,
        'relation_type' => 'prospect',
        'lifecycle_stage' => 'nouveau',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('contacts')->insert([
        'workspace_id' => $ws,
        'company_id' => $companyB,
        'first_name' => 'Z',
        'last_name' => 'ZZ GDPR',
        'email' => GDPR_EMAIL,
        'sources' => '["mentions-legales"]',
        'metadata' => '{}',
        'legal_basis' => 'legitimate_interest_b2b',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Fiche vivier + timeline dans les deux univers.
    DB::table('candidates')->insert([
        'workspace_id' => $vivier,
        'person_key' => gdprPersonKey(),
        'last_name' => 'ZZ GDPR',
        'email' => GDPR_EMAIL,
        'relation_type' => 'candidat_commercial',
        'lifecycle_stage' => 'nouveau',
        'legal_basis' => 'consent',
        'consent_version' => 'careers-v2-2026-08-13',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([[$ws, 'form_submission'], [$vivier, 'application_submitted']] as [$wsId, $kind]) {
        DB::table('activities')->insert([
            'workspace_id' => $wsId,
            'type' => $kind,
            'kind' => $kind,
            'occurred_at' => now(),
            'person_key' => gdprPersonKey(),
            'external_ref' => 'site:event:' . Str::uuid(),
            'title' => 'test',
            'payload' => json_encode(['email' => GDPR_EMAIL]),
            'created_at' => now(),
        ]);
    }

    return [$ws, $vivier];
}

beforeEach(function () {
    config([
        'crm.ingest.enabled' => true,
        'crm.ingest.hmac_secret' => GDPR_TEST_SECRET,
        'crm.ingest.business_workspace' => 'axion-ia',
        'crm.ingest.max_clock_skew_seconds' => 300,
    ]);

    gdprBusinessWorkspaceId();
});

// ─────────────────────────────────────────────────────────────────────────────
// 1. INERTIE + AUTH
// ─────────────────────────────────────────────────────────────────────────────

test('canal fermé : 503, la demande sera rejouée à la bascule', function () {
    config(['crm.ingest.enabled' => false]);

    gdprPost(['action' => 'erase', 'person_key' => gdprPersonKey(), 'email' => GDPR_EMAIL])
        ->assertStatus(503)
        ->assertJsonPath('error', 'ingest_disabled');
});

test('signature invalide : 401, et rien n\'est appris de l\'état du système', function () {
    config(['crm.ingest.enabled' => false]);
    config(['crm.ingest.hmac_secret' => 'autre-secret']);

    // Mauvais secret ET canal fermé : c'est le 401 qui doit sortir (la
    // signature se vérifie AVANT le drapeau).
    gdprPost(['action' => 'export', 'person_key' => gdprPersonKey(), 'email' => GDPR_EMAIL])
        ->assertStatus(401);
});

test('contrat strict : champ inconnu, action inconnue, clé invalide → 422', function () {
    gdprPost(['action' => 'erase', 'person_key' => gdprPersonKey(), 'email' => GDPR_EMAIL, 'workspace' => 'x'])
        ->assertStatus(422)->assertJsonPath('error', 'unknown_field');

    gdprPost(['action' => 'purge_all', 'person_key' => gdprPersonKey(), 'email' => GDPR_EMAIL])
        ->assertStatus(422)->assertJsonPath('error', 'invalid_action');

    gdprPost(['action' => 'erase', 'person_key' => 'court', 'email' => GDPR_EMAIL])
        ->assertStatus(422)->assertJsonPath('error', 'invalid_person_key');
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. ART. 17 — effacement bi-clés, bi-univers
// ─────────────────────────────────────────────────────────────────────────────

test('l\'effacement atteint la fiche SYNCHRO (person_key) ET la fiche COLLECTE (email), les deux univers, timeline comprise', function () {
    gdprSeedPerson();

    expect(DB::table('contacts')->count())->toBe(2)
        ->and(DB::table('candidates')->count())->toBe(1)
        ->and(DB::table('activities')->count())->toBe(2);

    $response = gdprPost(['action' => 'erase', 'person_key' => gdprPersonKey(), 'email' => GDPR_EMAIL]);
    $response->assertOk();

    expect(DB::table('contacts')->count())->toBe(0)
        ->and(DB::table('candidates')->count())->toBe(0)
        ->and(DB::table('activities')->count())->toBe(0)
        // Les personnes MORALES restent : une entreprise n'est pas une PII.
        ->and(DB::table('companies')->count())->toBe(2);

    // Journal d'exécution dans les deux workspaces.
    expect(DB::table('rgpd_requests')->where('type', 'erasure')->where('status', 'done')->count())->toBe(2);
});

test('l\'effacement laisse le HASH en opposition par univers, jamais l\'email en clair', function () {
    gdprSeedPerson();

    gdprPost(['action' => 'erase', 'person_key' => gdprPersonKey(), 'email' => GDPR_EMAIL])->assertOk();

    $rows = DB::table('opt_out')->where('email_hash', hash('sha256', GDPR_EMAIL))->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('scope')->sort()->values()->all())->toBe(['business', 'vivier'])
        ->and($rows->pluck('email')->filter()->all())->toBe([]);
});

test('scope=vivier n\'efface QUE le vivier', function () {
    gdprSeedPerson();

    gdprPost(['action' => 'erase', 'person_key' => gdprPersonKey(), 'email' => GDPR_EMAIL, 'scope' => 'vivier'])->assertOk();

    expect(DB::table('candidates')->count())->toBe(0)
        ->and(DB::table('contacts')->count())->toBe(2)
        ->and(DB::table('opt_out')->where('scope', 'vivier')->count())->toBe(1)
        ->and(DB::table('opt_out')->where('scope', 'business')->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. ART. 15 — export agrégé
// ─────────────────────────────────────────────────────────────────────────────

test('l\'export agrège contacts, candidats, timelines et oppositions des deux univers', function () {
    gdprSeedPerson();
    DB::table('opt_out')->insert([
        'email_hash' => hash('sha256', GDPR_EMAIL),
        'scope' => 'business',
        'source' => 'test',
        'created_at' => now(),
    ]);

    $response = gdprPost(['action' => 'export', 'person_key' => gdprPersonKey(), 'email' => GDPR_EMAIL]);
    $response->assertOk();

    $result = $response->json('result');
    expect(count($result['business']['contacts']))->toBe(2)
        ->and(count($result['business']['activities']))->toBe(1)
        ->and(count($result['vivier']['candidates']))->toBe(1)
        ->and(count($result['vivier']['activities']))->toBe(1)
        ->and(count($result['opt_out']))->toBe(1);

    // L'export ne modifie RIEN.
    expect(DB::table('contacts')->count())->toBe(2)
        ->and(DB::table('candidates')->count())->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. PURGES par univers
// ─────────────────────────────────────────────────────────────────────────────

test('les purges refusent tant que CRM_PURGE_ENABLED est à OFF', function () {
    config(['crm.purges_enabled' => false]);

    test()->artisan('rgpd:purge-vivier')->assertFailed();
    test()->artisan('rgpd:purge-business-prospects')->assertFailed();
});

test('purge vivier : 2 ans après la dernière interaction, refusés à J+90 — et personne d\'autre', function () {
    config(['crm.purges_enabled' => true]);
    gdprBusinessWorkspaceId();
    $vivier = gdprVivierWorkspaceId();

    $mk = function (string $lastName, string $stage, $interactionAt, $updatedAt) use ($vivier): void {
        DB::table('candidates')->insert([
            'workspace_id' => $vivier,
            'last_name' => $lastName,
            'relation_type' => 'candidat_autre',
            'lifecycle_stage' => $stage,
            'legal_basis' => 'consent',
            'consent_version' => 'careers-v2-2026-08-13',
            'derniere_interaction_at' => $interactionAt,
            'created_at' => now()->subYears(3),
            'updated_at' => $updatedAt,
        ]);
    };

    $mk('ZZ Périmé', 'vivier', now()->subYears(2)->subDay(), now());          // > 2 ans → purgé
    $mk('ZZ Frais', 'vivier', now()->subMonths(6), now());                    // frais → conservé
    $mk('ZZ Refusé vieux', 'refuse', now()->subMonths(6), now()->subDays(91)); // refusé J+91 → purgé
    $mk('ZZ Refusé récent', 'refuse', now()->subMonths(6), now()->subDays(10)); // refusé J+10 → conservé

    test()->artisan('rgpd:purge-vivier')->assertSuccessful();

    $rest = DB::table('candidates')->pluck('last_name')->sort()->values()->all();
    expect($rest)->toBe(['ZZ Frais', 'ZZ Refusé récent']);
});

test('purge business : 3 ans sans interaction — jamais une personne qui a interagi', function () {
    config(['crm.purges_enabled' => true]);
    $ws = gdprBusinessWorkspaceId();

    $companyId = DB::table('companies')->insertGetId([
        'workspace_id' => $ws,
        'siren' => '900000305',
        'denomination' => 'ZZ PURGE SAS',
        'signals' => '{}',
        'metadata' => '{}',
        'quality_score' => 0,
        'relation_type' => 'prospect',
        'lifecycle_stage' => 'nouveau',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $mk = function (string $lastName, ?string $legalBasis, $createdAt, ?string $personKey = null) use ($ws, $companyId): void {
        DB::table('contacts')->insert([
            'workspace_id' => $ws,
            'company_id' => $companyId,
            'last_name' => $lastName,
            'person_key' => $personKey,
            'sources' => '[]',
            'metadata' => '{}',
            'legal_basis' => $legalBasis,
            'created_at' => $createdAt,
            'updated_at' => now(),
        ]);
    };

    $interacted = hash('sha256', 'interacted');
    $mk('ZZ Vieux froid', 'legitimate_interest_b2b', now()->subYears(4));            // → purgé
    $mk('ZZ Récent froid', 'legitimate_interest_b2b', now()->subYears(1));           // → conservé
    $mk('ZZ Vieux entrant', 'precontractual', now()->subYears(4));                   // base entrante → conservé
    $mk('ZZ Vieux actif', 'legitimate_interest_b2b', now()->subYears(4), $interacted); // a une timeline → conservé
    DB::table('activities')->insert([
        'workspace_id' => $ws,
        'type' => 'form_submission',
        'kind' => 'form_submission',
        'occurred_at' => now()->subYears(2),
        'person_key' => $interacted,
        'external_ref' => 'site:event:' . Str::uuid(),
        'title' => 'test',
        'payload' => '{}',
        'created_at' => now(),
    ]);

    test()->artisan('rgpd:purge-business-prospects')->assertSuccessful();

    $rest = DB::table('contacts')->pluck('last_name')->sort()->values()->all();
    expect($rest)->toBe(['ZZ Récent froid', 'ZZ Vieux actif', 'ZZ Vieux entrant']);
});
