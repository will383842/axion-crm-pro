<?php

use App\Crm\Scraping\ScrapedRecord;
use App\Crm\Scraping\ScrapedRecordIngestService;
use App\Crm\Scraping\ScrapeIngestOutcome;
use App\Crm\Scraping\ScrapeIngestRejection;
use Database\Seeders\GovernedTagsSeeder;
use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * FUNNEL D'INGESTION DE LA COLLECTE (lot L3) — schéma pivot ScrapedRecord.
 *
 * Quatre familles de garanties :
 *   - INERTIE : drapeau OFF, l'endpoint garde son comportement historique ;
 *   - GOUVERNANCE : registre des sources (inconnue = 422, coupée = 503),
 *     tags dérivés du registre ;
 *   - HARMONISATION : un scrapé naît FROID, le froid ne se réchauffe jamais,
 *     le collecté n'écrase JAMAIS ni l'existant ni le déclaré (backfill-only) ;
 *   - RGPD : anti-réinsertion d'une personne opposée, pas de fiche personne
 *     depuis une boîte service, base légale intérêt légitime.
 */
function scrapeWorkspaceId(): string
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

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function scrapedRecord(array $overrides = []): array
{
    return array_replace_recursive([
        'schema_version' => 1,
        'source' => 'mentions-legales',
        'run_id' => (string) Str::uuid(),
        'fetched_at' => '2026-08-14T11:00:00+02:00',
        'status' => 'success',
        'company' => [
            'siren' => '900000202',
            'fields' => [
                'denomination' => 'ZZ SCRAPE SARL',
                'website' => 'https://zz-scrape.example.invalid',
                'phone' => '0476000000',
                'city' => 'Grenoble',
            ],
        ],
        'persons' => [[
            'first_name' => 'Paul',
            'last_name' => 'ZZ SCRAPE',
            'role' => 'gérant',
            'email' => 'paul@zz-scrape.example.invalid',
            'phone' => '0612345678',
            'kind' => 'person',
        ]],
        'evidence' => ['url' => 'https://zz-scrape.example.invalid/mentions-legales'],
        'confidence' => 90,
    ], $overrides);
}

function ingestScrape(array $raw, bool $dryRun = false): ScrapeIngestOutcome
{
    return app(ScrapedRecordIngestService::class)->ingest(ScrapedRecord::fromArray($raw), $dryRun);
}

beforeEach(function () {
    config([
        'crm.scrape_funnel.enabled' => true,
        // Jamais d'appel DNS réel dans la suite (règle MOCK_*).
        'crm.scrape_funnel.validate_mx' => false,
        'crm.ingest.business_workspace' => 'axion-ia',
    ]);

    scrapeWorkspaceId();
    $this->seed(ScrapingSourcesSeeder::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// 1. INERTIE — drapeau OFF, l'endpoint garde le comportement HISTORIQUE
// ─────────────────────────────────────────────────────────────────────────────

test('drapeau à OFF : /internal/scraper-result garde son comportement historique', function () {
    config(['crm.scrape_funnel.enabled' => false]);

    // Le format HISTORIQUE des workers Node (sans schema_version ni company) :
    // il doit continuer de passer tel quel, log-only, 200.
    $legacy = ['run_id' => 'r-1', 'source' => 'google-maps', 'status' => 'success', 'payload' => ['x' => 1]];
    $body = json_encode($legacy, JSON_THROW_ON_ERROR);

    // On signe avec LE secret que l'application voit (dotenv est immuable :
    // un putenv() de test n'écrase pas une variable déjà chargée du .env).
    $secret = (string) env('WORKER_INTERNAL_HMAC_SECRET', '');

    $response = test()->call('POST', '/api/internal/scraper-result', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WORKER_SIGNATURE' => hash_hmac('sha256', $body, $secret),
    ], $body);

    $response->assertOk()->assertJsonPath('ingested', true);

    expect(DB::table('companies')->count())->toBe(0)
        ->and(DB::table('scraper_runs')->count())->toBe(0)
        ->and(DB::table('activities')->count())->toBe(0);
});

test('drapeau à ON : le MÊME endpoint valide le pivot et ingère réellement', function () {
    $record = scrapedRecord();
    $body = json_encode($record, JSON_THROW_ON_ERROR);
    $secret = (string) env('WORKER_INTERNAL_HMAC_SECRET', '');

    $response = test()->call('POST', '/api/internal/scraper-result', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WORKER_SIGNATURE' => hash_hmac('sha256', $body, $secret),
    ], $body);

    $response->assertOk()->assertJsonPath('result.status', 'created');
    expect(DB::table('companies')->where('siren', '900000202')->exists())->toBeTrue();

    // Et un message invalide est ENFIN refusé au lieu d'un « ingested: true »
    // menteur : le producteur sait que son message est mauvais.
    $bad = json_encode(['run_id' => 'r-2', 'source' => 'google-maps', 'status' => 'success'], JSON_THROW_ON_ERROR);
    test()->call('POST', '/api/internal/scraper-result', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WORKER_SIGNATURE' => hash_hmac('sha256', $bad, $secret),
    ], $bad)->assertStatus(422);
});

test('valeur par défaut du drapeau : fermé', function () {
    $defaults = require config_path('crm.php');

    expect($defaults['scrape_funnel']['enabled'])->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. GOUVERNANCE — le registre des sources
// ─────────────────────────────────────────────────────────────────────────────

test('une source hors registre est refusée (422), une source coupée est différée (503)', function () {
    expect(fn () => ingestScrape(scrapedRecord(['source' => 'source-pirate'])))
        ->toThrow(function (ScrapeIngestRejection $e) {
            expect($e->errorCode)->toBe('unknown_source')->and($e->status)->toBe(422);
        });

    DB::table('scraping_sources')->where('slug', 'mentions-legales')->update(['enabled' => false]);

    expect(fn () => ingestScrape(scrapedRecord()))
        ->toThrow(function (ScrapeIngestRejection $e) {
            expect($e->errorCode)->toBe('source_disabled')->and($e->status)->toBe(503);
        });

    expect(DB::table('companies')->count())->toBe(0);
});

test('le re-seed du registre ne RÉACTIVE jamais une source coupée à la main', function () {
    DB::table('scraping_sources')->where('slug', 'insee')->update(['enabled' => false]);

    test()->seed(ScrapingSourcesSeeder::class);

    expect((bool) DB::table('scraping_sources')->where('slug', 'insee')->value('enabled'))->toBeFalse();
});

test('chaque source du registre a son tag gouverné dérivé', function () {
    $referential = GovernedTagsSeeder::referential();
    $orphelins = [];

    foreach (array_keys(ScrapingSourcesSeeder::referential()) as $slug) {
        if (! array_key_exists('src:scraping-' . $slug, $referential)) {
            $orphelins[] = $slug;
        }
    }

    expect($orphelins)->toBe([]);
});

test('un champ inconnu fait REJETER le message (schéma pivot strict)', function () {
    expect(fn () => ingestScrape(scrapedRecord(['relation_type' => 'client'])))
        ->toThrow(fn (ScrapeIngestRejection $e) => expect($e->errorCode)->toBe('unknown_field'));

    expect(fn () => ingestScrape(scrapedRecord(['company' => ['lifecycle_stage' => 'client']])))
        ->toThrow(fn (ScrapeIngestRejection $e) => expect($e->errorCode)->toBe('unknown_field'));
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. HARMONISATION — froid, backfill-only, dédup
// ─────────────────────────────────────────────────────────────────────────────

test('un scrapé naît FROID : prospect / nouveau / intérêt légitime, tagué et daté', function () {
    $outcome = ingestScrape(scrapedRecord());

    expect($outcome->status)->toBe('created')
        ->and($outcome->contactsCreated)->toBe(1);

    $company = DB::table('companies')->where('siren', '900000202')->first();
    expect($company->relation_type)->toBe('prospect')
        ->and($company->lifecycle_stage)->toBe('nouveau')
        ->and($company->legal_basis)->toBe('legitimate_interest_b2b')
        ->and($company->discovery_source)->toBe('mentions-legales')
        ->and($company->last_seen_at)->not->toBeNull();

    $tags = DB::table('company_tag')
        ->join('tags', 'tags.id', '=', 'company_tag.tag_id')
        ->pluck('tags.slug')->all();
    expect($tags)->toContain('src:scraping-mentions-legales');

    $origins = json_decode((string) $company->field_origins, true);
    expect($origins['website'])->toBe('collected');

    expect(DB::table('scraper_runs')->where('source', 'mentions-legales')->where('status', 'success')->count())->toBe(1)
        ->and(DB::table('activities')->where('kind', 'scraped')->count())->toBe(1);
});

test('le TÉLÉPHONE est posé sur la fiche personne (réparation de la perte A.4)', function () {
    ingestScrape(scrapedRecord());

    $contact = DB::table('contacts')->first();
    expect($contact->phone)->toBe('0612345678')
        ->and($contact->email)->toBe('paul@zz-scrape.example.invalid')
        ->and($contact->legal_basis)->toBe('legitimate_interest_b2b')
        ->and($contact->discovery_source)->toBe('mentions-legales');
});

test('le froid ne se réchauffe JAMAIS : un re-scrape ne touche ni cycle de vie ni base légale', function () {
    $ws = scrapeWorkspaceId();
    DB::table('companies')->insert([
        'workspace_id' => $ws,
        'siren' => '900000202',
        'denomination' => 'ZZ SCRAPE SARL',
        'signals' => '{}',
        'metadata' => '{}',
        'quality_score' => 0,
        'relation_type' => 'client',
        'lifecycle_stage' => 'client',
        'legal_basis' => 'precontractual',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $outcome = ingestScrape(scrapedRecord());

    expect($outcome->status)->toBe('updated');

    $company = DB::table('companies')->where('siren', '900000202')->first();
    expect($company->relation_type)->toBe('client')
        ->and($company->lifecycle_stage)->toBe('client')
        ->and($company->legal_basis)->toBe('precontractual');
});

test('BACKFILL-ONLY : le collecté n\'écrase ni une valeur existante ni un champ déclaré', function () {
    $ws = scrapeWorkspaceId();
    $companyId = DB::table('companies')->insertGetId([
        'workspace_id' => $ws,
        'siren' => '900000202',
        'denomination' => 'ZZ SCRAPE SARL',
        'city' => 'Lyon',
        'signals' => '{}',
        'metadata' => '{}',
        'quality_score' => 0,
        'relation_type' => 'prospect',
        'lifecycle_stage' => 'opportunite',
        // `website` vide mais DÉCLARÉ par la personne (événement entrant L2) :
        // même vide-le-lendemain, un champ déclaré n'est pas re-rempli par la
        // collecte sans trace — ici il est non-vide donc doublement protégé.
        'field_origins' => json_encode(['city' => 'declared']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('contacts')->insert([
        'workspace_id' => $ws,
        'company_id' => $companyId,
        'first_name' => 'Paul',
        'last_name' => 'ZZ SCRAPE',
        'email' => 'paul@zz-scrape.example.invalid',
        'phone' => '+33700000000',
        'sources' => '["site"]',
        'metadata' => '{}',
        'field_origins' => json_encode(['phone' => 'declared']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    ingestScrape(scrapedRecord(['company' => ['fields' => ['city' => 'Grenoble']]]));

    $company = DB::table('companies')->where('id', $companyId)->first();
    // city était déclarée ET non vide : intouchée. website était vide : backfillé.
    expect($company->city)->toBe('Lyon')
        ->and($company->website)->toBe('https://zz-scrape.example.invalid')
        ->and($company->lifecycle_stage)->toBe('opportunite');

    $contact = DB::table('contacts')->first();
    // Le téléphone DÉCLARÉ n'est pas écrasé par le téléphone collecté.
    expect($contact->phone)->toBe('+33700000000');
    // La provenance se cumule.
    $sources = json_decode((string) $contact->sources, true);
    expect($sources)->toContain('site')->and($sources)->toContain('mentions-legales');
});

test('rejouer le MÊME run est idempotent', function () {
    $record = scrapedRecord();

    expect(ingestScrape($record)->status)->toBe('created');
    expect(ingestScrape($record)->status)->toBe('noop_idempotent');

    expect(DB::table('companies')->count())->toBe(1)
        ->and(DB::table('contacts')->count())->toBe(1)
        ->and(DB::table('activities')->count())->toBe(1);
});

test('sans SIREN : AUCUNE fiche créée, l\'événement part en arbitrage avec son indice', function () {
    $outcome = ingestScrape(scrapedRecord([
        'company' => [
            'siren' => null,
            'match_hint' => ['denomination' => 'ZZ MYSTERE', 'postcode' => '38000', 'city' => 'Grenoble'],
        ],
    ]));

    expect($outcome->status)->toBe('pending_match');

    expect(DB::table('companies')->count())->toBe(0)
        ->and(DB::table('contacts')->count())->toBe(0)
        ->and(DB::table('activities')->where('kind', 'scraped')->count())->toBe(1);

    $payload = json_decode((string) DB::table('activities')->value('payload'), true);
    expect($payload['pending_match']['denomination'])->toBe('ZZ MYSTERE');
});

test('un run en échec est consigné, aucune donnée écrite', function () {
    $outcome = ingestScrape(scrapedRecord(['status' => 'failed']));

    expect($outcome->status)->toBe('skipped_failed');
    expect(DB::table('companies')->count())->toBe(0)
        ->and(DB::table('scraper_runs')->where('status', 'failed')->count())->toBe(1);
});

test('le dry-run parcourt le vrai funnel puis ne laisse RIEN en base', function () {
    $outcome = ingestScrape(scrapedRecord(), dryRun: true);

    expect($outcome->status)->toBe('created')
        ->and($outcome->contactsCreated)->toBe(1);

    expect(DB::table('companies')->count())->toBe(0)
        ->and(DB::table('contacts')->count())->toBe(0)
        ->and(DB::table('scraper_runs')->count())->toBe(0)
        ->and(DB::table('activities')->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. RGPD — opposition, boîtes service, MX
// ─────────────────────────────────────────────────────────────────────────────

test('ANTI-RÉINSERTION : une personne opposée ne revient jamais par un re-scrape', function () {
    DB::table('opt_out')->insert([
        'email' => 'paul@zz-scrape.example.invalid',
        'email_hash' => hash('sha256', 'paul@zz-scrape.example.invalid'),
        'scope' => 'business',
        'source' => 'test',
        'created_at' => now(),
    ]);

    $outcome = ingestScrape(scrapedRecord());

    // L'entreprise est créée (personne morale), la PERSONNE ne revient pas.
    expect($outcome->status)->toBe('created')
        ->and($outcome->personsSkippedOptOut)->toBe(1)
        ->and(DB::table('contacts')->count())->toBe(0);
});

test('une boîte service ne devient JAMAIS une fiche personne', function () {
    $outcome = ingestScrape(scrapedRecord([
        'persons' => [[
            'last_name' => 'Contact',
            'email' => 'contact@zz-scrape.example.invalid',
            'kind' => 'service_mailbox',
        ]],
    ]));

    expect(DB::table('contacts')->count())->toBe(0);
    // Elle alimente l'email générique de l'entreprise.
    expect(DB::table('companies')->where('id', $outcome->companyId)->value('email_generic'))
        ->toBe('contact@zz-scrape.example.invalid');
});

test('un email au domaine non résolvable est rejeté : pas de fiche sur une adresse morte', function () {
    config(['crm.scrape_funnel.validate_mx' => true]);
    // Domaine .invalid : RFC 2606, ne résout jamais — le test reste hermétique.

    $outcome = ingestScrape(scrapedRecord());

    expect($outcome->emailsRejectedMx)->toBe(1)
        ->and(DB::table('contacts')->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// RÉGRESSION PRODUCTION 2026-08-15 — le backfill a été refusé par le CHECK de
// `company_tag.assigned_by` (vocabulaire fermé). La valeur dédiée
// `backfill-src` est ce qui rend le rollback CHIRURGICAL : sans elle, annuler
// le backfill effacerait aussi les étiquettes posées par l'ingestion.
// ─────────────────────────────────────────────────────────────────────────────

test('le marqueur backfill-src est accepté et rend le rollback CHIRURGICAL', function () {
    $ws = scrapeWorkspaceId();
    [$companyId, $tagId] = zzCheckFixture($ws);

    // Deux tags distincts : la PK de company_tag est (company_id, tag_id),
    // une même paire ne peut pas porter deux provenances.
    $tagIngestion = (int) DB::table('tags')->insertGetId([
        'workspace_id' => $ws,
        'slug' => 'src:site-formulaire-audit',
        'name' => 'Formulaire — audit',
        'category' => 'intent',
        'kind' => 'auto',
        'rules' => '{}',
        'is_locked' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Les deux provenances coexistent…
    DB::table('company_tag')->insert([
        ['company_id' => $companyId, 'tag_id' => $tagId, 'workspace_id' => $ws, 'assigned_by' => 'backfill-src', 'assigned_at' => now()],
        ['company_id' => $companyId, 'tag_id' => $tagIngestion, 'workspace_id' => $ws, 'assigned_by' => 'auto-rule', 'assigned_at' => now()],
    ]);

    // …et annuler le backfill ne touche PAS ce que l'ingestion a posé.
    // C'est TOUTE la raison d'être du marqueur dédié : avec `auto-rule`
    // partout, le rollback du backfill effacerait aussi les étiquettes des
    // fiches réellement ingérées.
    DB::table('company_tag')->where('assigned_by', 'backfill-src')->delete();

    expect(DB::table('company_tag')->where('assigned_by', 'auto-rule')->count())->toBe(1)
        ->and(DB::table('company_tag')->where('assigned_by', 'backfill-src')->count())->toBe(0);
});

test('le vocabulaire de assigned_by reste FERMÉ : une valeur inventée est refusée', function () {
    // ⚠️ Test isolé : une violation de contrainte AVORTE la transaction de
    // RefreshDatabase (SQLSTATE 25P02) — toute assertion qui suivrait dans le
    // même test échouerait pour cette raison, pas pour la bonne.
    $ws = scrapeWorkspaceId();
    [$companyId, $tagId] = zzCheckFixture($ws);

    expect(fn () => DB::table('company_tag')->insert([
        'company_id' => $companyId,
        'tag_id' => $tagId,
        'workspace_id' => $ws,
        'assigned_by' => 'valeur-inventee',
        'assigned_at' => now(),
    ]))->toThrow(QueryException::class);
});

/**
 * @return array{0: int, 1: int} [company_id, tag_id]
 */
function zzCheckFixture(string $ws): array
{
    $companyId = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $ws,
        'siren' => '900000707',
        'denomination' => 'ZZ CHECK SAS',
        'signals' => '{}',
        'metadata' => '{}',
        'quality_score' => 0,
        'relation_type' => 'prospect',
        'lifecycle_stage' => 'nouveau',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $tagId = (int) DB::table('tags')->insertGetId([
        'workspace_id' => $ws,
        'slug' => 'src:scraping-insee',
        'name' => 'Collecte — INSEE',
        'category' => 'intent',
        'kind' => 'auto',
        'rules' => '{}',
        'is_locked' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$companyId, $tagId];
}
