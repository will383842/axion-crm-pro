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
    // Chaque appel est signé avec un horodatage DISTINCT (dans la fenêtre),
    // comme le fait l'émetteur du site à chaque tentative : le CRM refuse une
    // copie exacte d'une requête déjà reçue, et plusieurs appels d'un même test
    // tombent souvent dans la même seconde.
    static $decalage = 0;
    $timestamp = (string) (time() - ($decalage++ % 200));
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

test('L3c-0 : erase ne supprime RIEN — demande enregistrée, fiches SYNCHRO et COLLECTE mises à l\'écart, les deux univers', function () {
    gdprSeedPerson();

    $response = gdprPost(['action' => 'erase', 'person_key' => gdprPersonKey(), 'email' => GDPR_EMAIL]);
    $response->assertOk();

    // Rien n'est supprimé : contacts, candidats, timelines, entreprises.
    expect(DB::table('contacts')->count())->toBe(2)
        ->and(DB::table('candidates')->count())->toBe(1)
        ->and(DB::table('activities')->count())->toBe(2)
        ->and(DB::table('companies')->count())->toBe(2);

    // Les contacts sont masqués (corbeille douce) et le candidat est marqué.
    expect(DB::table('contacts')->whereNull('deleted_at')->count())->toBe(0)
        ->and(DB::table('candidates')->whereRaw("attributes ? 'mis_a_l_ecart_le'")->count())->toBe(1);

    // La demande est ENREGISTRÉE (en attente de Will) dans les deux univers.
    expect(DB::table('rgpd_requests')->where('type', 'erasure')->where('status', 'pending')->count())->toBe(2)
        ->and(DB::table('rgpd_requests')->where('status', 'done')->count())->toBe(0);
});

test('L3c-0 : erase met la personne à l\'écart (opposition_at + motif) et désabonne', function () {
    $ws = gdprBusinessWorkspaceId();
    $id = DB::table('personnes')->insertGetId([
        'workspace_id' => $ws,
        'email' => GDPR_EMAIL,
        'email_hash' => hash('sha256', GDPR_EMAIL),
        'person_key' => gdprPersonKey(),
        'premiere_source' => 'newsletter',
        'premiere_source_at' => now(),
        'legal_basis' => 'consent',
    ]);
    DB::table('abonnements')->insert([
        'workspace_id' => $ws, 'personne_id' => $id, 'canal' => 'lettre', 'statut' => 'abonne',
        'dernier_evenement_at' => now(),
    ]);

    gdprPost(['action' => 'erase', 'person_key' => gdprPersonKey(), 'email' => GDPR_EMAIL])->assertOk();

    $p = DB::table('personnes')->where('id', $id)->first();
    expect($p)->not->toBeNull()
        ->and($p->opposition_at)->not->toBeNull()
        ->and($p->opposition_motif)->toBe('demande_effacement')
        ->and(DB::table('abonnements')->where('personne_id', $id)->value('statut'))->toBe('desabonne');
});

test('l\'effacement laisse le HASH en opposition par univers, jamais l\'email en clair', function () {
    gdprSeedPerson();

    gdprPost(['action' => 'erase', 'person_key' => gdprPersonKey(), 'email' => GDPR_EMAIL])->assertOk();

    $rows = DB::table('opt_out')->where('email_hash', hash('sha256', GDPR_EMAIL))->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('scope')->sort()->values()->all())->toBe(['business', 'vivier'])
        ->and($rows->pluck('email')->filter()->all())->toBe([]);
});

test('scope=vivier ne met à l\'écart QUE le vivier', function () {
    gdprSeedPerson();

    gdprPost(['action' => 'erase', 'person_key' => gdprPersonKey(), 'email' => GDPR_EMAIL, 'scope' => 'vivier'])->assertOk();

    expect(DB::table('candidates')->count())->toBe(1)
        ->and(DB::table('candidates')->whereRaw("attributes ? 'mis_a_l_ecart_le'")->count())->toBe(1)
        ->and(DB::table('contacts')->whereNull('deleted_at')->count())->toBe(2)
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

test('L3c-0 : les commandes de purge refusent, drapeau éteint comme ouvert, et ne suppriment personne', function () {
    gdprSeedPerson();

    foreach ([false, true] as $ouvert) {
        config(['crm.purges_enabled' => $ouvert]);

        test()->artisan('rgpd:purge-vivier', ['--force' => true])->assertFailed();
        test()->artisan('rgpd:purge-business-prospects', ['--force' => true])->assertFailed();
        test()->artisan('rgpd:purge-personnes', ['--force' => true])->assertFailed();
    }

    expect(DB::table('contacts')->count())->toBe(2)
        ->and(DB::table('candidates')->count())->toBe(1);
});
