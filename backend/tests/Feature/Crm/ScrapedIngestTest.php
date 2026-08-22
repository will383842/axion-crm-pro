<?php

use App\Crm\Scraping\ScrapedRecord;
use App\Crm\Scraping\ScrapedRecordIngestService;
use App\Crm\Scraping\ScrapeIngestOutcome;
use App\Crm\Scraping\ScrapeIngestRejection;
use App\Models\Company;
use App\Services\Tags\AutoTaggerService;
use App\Support\CompanyQueryFilters;
use Database\Seeders\GovernedTagsSeeder;
use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\QueryBuilder;
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

/**
 * Impose un VRAI secret au canal interne, sur les trois canaux de variables.
 *
 * 🔴 Ces tests signaient auparavant avec `env('WORKER_INTERNAL_HMAC_SECRET', '')`
 * — c'est-a-dire avec la CHAINE VIDE, puisque cette variable n'est definie nulle
 * part dans l'environnement de test. Ils passaient parce que la verification
 * etait FAIL-OPEN : `hash_hmac($body, '')` produit un condense valide, calculable
 * par n'importe qui. Ces tests certifiaient donc, sans le dire, qu'un canal sans
 * secret accepte quand meme les messages (audit 360, F37-001, S0).
 *
 * Le canal refuse desormais un secret vide (503). Les tests posent donc un vrai
 * secret : ils verifient le comportement du FUNNEL, pas l'absence de controle.
 *
 * `putenv()` seul ne suffit pas — le depot de variables de Laravel interroge
 * `ServerConstAdapter` ($_SERVER) en premier, et `variables_order = EGPCS` y
 * place les variables du conteneur. Meme piege que `tests/bootstrap.php`.
 *
 * 🔴 Et depuis P5-HMAC-002, le controleur lit
 * `config('services.worker_internal.hmac_secret')` et non plus `env()` brut :
 * `config/` etant resolu UNE FOIS a l'amorcage, une variable posee apres coup
 * n'y arrive jamais. La ligne `config([...])` est donc indispensable, sinon ces
 * tests reprendraient exactement le defaut qu'ils ferment : signer avec un
 * secret que le controleur ne lit pas.
 */
const SECRET_CANAL_INTERNE_TEST = 'secret-de-test-du-canal-interne-2026';

function poserSecretCanalInterne(): string
{
    $_SERVER['WORKER_INTERNAL_HMAC_SECRET'] = SECRET_CANAL_INTERNE_TEST;
    $_ENV['WORKER_INTERNAL_HMAC_SECRET'] = SECRET_CANAL_INTERNE_TEST;
    putenv('WORKER_INTERNAL_HMAC_SECRET=' . SECRET_CANAL_INTERNE_TEST);
    config(['services.worker_internal.hmac_secret' => SECRET_CANAL_INTERNE_TEST]);

    return SECRET_CANAL_INTERNE_TEST;
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. INERTIE — drapeau OFF, l'endpoint garde le comportement HISTORIQUE
// ─────────────────────────────────────────────────────────────────────────────

test('drapeau à OFF : /internal/scraper-result garde son comportement historique', function () {
    config(['crm.scrape_funnel.enabled' => false]);

    // Le format HISTORIQUE des workers Node (sans schema_version ni company) :
    // il doit continuer de passer tel quel, log-only, 200.
    $legacy = ['run_id' => 'r-1', 'source' => 'google-maps', 'status' => 'success', 'payload' => ['x' => 1]];
    $body = json_encode($legacy, JSON_THROW_ON_ERROR);

    // On pose un VRAI secret et on signe avec lui. Signer avec la chaine vide
    // ne prouvait rien : c'etait le defaut F37-001 qui rendait ce test vert.
    $secret = poserSecretCanalInterne();

    $response = test()->call('POST', '/api/internal/scraper-result', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WORKER_SIGNATURE' => hash_hmac('sha256', $body, $secret),
    ], $body);

    // 🔴 C18-001 (S1) — CETTE ASSERTION CERTIFIAIT LE DEFAUT, ET LES TROIS
    // LIGNES SUIVANTES LE PROUVAIENT DANS LE MEME SOUFFLE.
    //
    // Elle exigeait `ingested: true`. Trois lignes plus bas, le MEME test verifie
    // que `companies`, `scraper_runs` et `activities` sont a ZERO. Le test disait
    // donc, en meme temps : « l'API confirme l'ingestion » et « rien n'a ete
    // ingere ». C'est la lecon IndexNow — un job vert qui ne relaie rien — gravee
    // dans une garde, ce qui la rendait inattaquable.
    //
    // Ce que le drapeau OFF preserve, c'est l'INERTIE DE LA BASE, et elle est
    // toujours verifiee ci-dessous, a l'identique. Ce n'est pas le mensonge de la
    // reponse : celui-la n'a jamais ete un choix de conception.
    // Correctif : `ScraperResultController::store()`, branche funnel ferme.
    // Garde dediee : `tests/Feature/Internal/ReponseVeridiqueIngestionTest.php`.
    // Rouge mesure le 2026-08-20 avant correctif :
    //   « Failed asserting that false is identical to true » a cette ligne meme.
    $response->assertOk()
        ->assertJsonPath('ingested', false)
        ->assertJsonPath('raison', 'funnel_ferme');

    expect(DB::table('companies')->count())->toBe(0)
        ->and(DB::table('scraper_runs')->count())->toBe(0)
        ->and(DB::table('activities')->count())->toBe(0);
});

test('drapeau à ON : le MÊME endpoint valide le pivot et ingère réellement', function () {
    $record = scrapedRecord();
    $body = json_encode($record, JSON_THROW_ON_ERROR);
    $secret = poserSecretCanalInterne();

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

// ─────────────────────────────────────────────────────────────────────────────
// 8. IMPLANTATIONS À L'ÉTRANGER — campagne « entreprises FR implantées en
//    Roumanie » : signals.implantations + tag geo `implantation-<pays>` dérivé
// ─────────────────────────────────────────────────────────────────────────────

test('implantations : un code pays invalide ou une clé inconnue rejettent le message', function () {
    expect(fn () => ingestScrape(scrapedRecord([
        'company' => ['implantations' => [['country' => 'ROU']]],
    ])))->toThrow(ScrapeIngestRejection::class, 'ISO 3166-1');

    expect(fn () => ingestScrape(scrapedRecord([
        'company' => ['implantations' => [['country' => 'RO', 'cui' => 'RO123']]],
    ])))->toThrow(ScrapeIngestRejection::class);
});

test('une implantation est fusionnée dans signals et le tag implantation-<pays> est posé DÈS l\'import', function () {
    $outcome = ingestScrape(scrapedRecord([
        'source' => 'implantations-fr-etranger',
        'company' => [
            'implantations' => [[
                'country' => 'ro',
                'name_local' => 'ZZ Scrape Romania SRL',
                'city' => 'Bucarest',
                'registry_id' => 'RO12345678',
            ]],
        ],
    ]));

    expect($outcome->status)->toBe('created');

    $company = DB::table('companies')->where('siren', '900000202')->first();
    $signals = json_decode((string) $company->signals, true);
    // Le code pays est NORMALISÉ en majuscules à la porte du pivot.
    expect($signals['implantations']['RO']['name_local'])->toBe('ZZ Scrape Romania SRL')
        ->and($signals['implantations']['RO']['city'])->toBe('Bucarest')
        ->and($signals['implantations']['RO']['registry_id'])->toBe('RO12345678');

    $tags = DB::table('company_tag')
        ->join('tags', 'tags.id', '=', 'company_tag.tag_id')
        ->where('company_tag.company_id', $company->id)
        ->select('tags.slug', 'tags.category', 'tags.kind')
        ->get();
    $slugs = $tags->pluck('slug')->all();

    // Classement complet dès l'import : provenance + géographie d'implantation.
    expect($slugs)->toContain('src:scraping-implantations-fr-etranger')
        ->and($slugs)->toContain('implantation-ro');

    $geoTag = $tags->firstWhere('slug', 'implantation-ro');
    expect($geoTag->category)->toBe('geo')
        ->and($geoTag->kind)->toBe('auto');
});

test('BACKFILL-ONLY sur les implantations : une valeur existante n\'est jamais écrasée', function () {
    ingestScrape(scrapedRecord([
        'source' => 'implantations-fr-etranger',
        'company' => ['implantations' => [['country' => 'RO', 'name_local' => 'ZZ Scrape Romania SRL']]],
    ]));

    ingestScrape(scrapedRecord([
        'source' => 'implantations-fr-etranger',
        'run_id' => (string) Str::uuid(),
        'company' => ['implantations' => [['country' => 'RO', 'name_local' => 'AUTRE NOM SRL', 'city' => 'Cluj-Napoca']]],
    ]));

    $signals = json_decode((string) DB::table('companies')->where('siren', '900000202')->value('signals'), true);
    // Le nom existant survit, le trou (ville) est comblé.
    expect($signals['implantations']['RO']['name_local'])->toBe('ZZ Scrape Romania SRL')
        ->and($signals['implantations']['RO']['city'])->toBe('Cluj-Napoca');
});

test('la resync AutoTagger ne supprime NI le tag src: NI le tag implantation- (le tag est dérivé des données)', function () {
    ingestScrape(scrapedRecord([
        'source' => 'implantations-fr-etranger',
        'company' => ['implantations' => [['country' => 'RO']]],
    ]));

    $company = Company::query()->where('siren', '900000202')->firstOrFail();
    // Une passe d'enrichissement rejoue la synchro : rien de ce qui classe la
    // fiche ne doit disparaître (le défaut exact que corrige la garde src:).
    (new AutoTaggerService)->syncTags($company);

    $slugs = DB::table('company_tag')
        ->join('tags', 'tags.id', '=', 'company_tag.tag_id')
        ->where('company_tag.company_id', $company->id)
        ->pluck('tags.slug')->all();

    expect($slugs)->toContain('src:scraping-implantations-fr-etranger')
        ->and($slugs)->toContain('implantation-ro');
});

test('filter[tag] retrouve le segment implantation-ro dans la liste (et donc l\'export, qui partage la query)', function () {
    ingestScrape(scrapedRecord([
        'source' => 'implantations-fr-etranger',
        'company' => ['implantations' => [['country' => 'RO']]],
    ]));
    // Une fiche témoin SANS implantation, qui ne doit pas ressortir.
    ingestScrape(scrapedRecord([
        'run_id' => (string) Str::uuid(),
        'company' => ['siren' => '900000203', 'fields' => ['denomination' => 'ZZ TEMOIN SARL']],
        'persons' => [],
    ]));

    $found = QueryBuilder::for(Company::query()->whereNull('deleted_at'))
        ->allowedFilters(CompanyQueryFilters::allowed())
        ->get();
    expect($found)->toHaveCount(2);

    request()->merge(['filter' => ['tag' => 'implantation-ro']]);
    $filtered = QueryBuilder::for(Company::query()->whereNull('deleted_at'))
        ->allowedFilters(CompanyQueryFilters::allowed())
        ->get();
    expect($filtered)->toHaveCount(1)
        ->and($filtered->first()->siren)->toBe('900000202');
});

// ─────────────────────────────────────────────────────────────────────────────
// 9. ENTITÉS SANS SIREN — prospection internationale (associations, CCI,
//    écoles, cabinets roumains). Le SIREN était une CLÉ DE DÉDUP, pas un
//    besoin métier : on la généralise à (country_code, foreign_id).
// ─────────────────────────────────────────────────────────────────────────────

function orgRecord(array $overrides = []): array
{
    $base = scrapedRecord([
        'source' => 'implantations-fr-etranger',
        'company' => [
            'foreign_id' => 'ccifer:asociatia-zz-test',
            'country' => 'RO',
            'nature' => 'association',
            'fields' => [
                'denomination' => 'ASOCIATIA ZZ TEST',
                'website' => 'https://zz-asociatia.example.invalid',
                'city' => 'Bucarest',
            ],
        ],
        'persons' => [],
    ]);
    // scrapedRecord() pose un siren par défaut : une entité étrangère n'en a pas.
    unset($base['company']['siren']);

    return array_replace_recursive($base, $overrides);
}

test('une entité SANS siren est créée, ancrée sur (pays, foreign_id)', function () {
    $outcome = ingestScrape(orgRecord());

    expect($outcome->status)->toBe('created');

    $company = DB::table('companies')->where('foreign_id', 'ccifer:asociatia-zz-test')->first();
    expect($company)->not->toBeNull()
        ->and($company->siren)->toBeNull()
        ->and($company->country_code)->toBe('RO')
        ->and($company->entity_nature)->toBe('association')
        ->and($company->denomination)->toBe('ASOCIATIA ZZ TEST')
        ->and($company->website)->toBe('https://zz-asociatia.example.invalid')
        // Un scrapé naît FROID, entité étrangère ou non.
        ->and($company->relation_type)->toBe('prospect')
        ->and($company->lifecycle_stage)->toBe('nouveau')
        ->and($company->legal_basis)->toBe('legitimate_interest_b2b');
});

test('les tags pays et nature sont posés : cibler les associations de Roumanie', function () {
    ingestScrape(orgRecord());

    $company = DB::table('companies')->where('foreign_id', 'ccifer:asociatia-zz-test')->first();
    $tags = DB::table('company_tag')
        ->join('tags', 'tags.id', '=', 'company_tag.tag_id')
        ->where('company_tag.company_id', $company->id)
        ->select('tags.slug', 'tags.category')
        ->get();
    $slugs = $tags->pluck('slug')->all();

    expect($slugs)->toContain('pays-ro')
        ->and($slugs)->toContain('nature-association')
        ->and($slugs)->toContain('src:scraping-implantations-fr-etranger')
        ->and($tags->firstWhere('slug', 'pays-ro')->category)->toBe('geo');
});

test('rejouer la MÊME entité ne crée pas de doublon (dédup par foreign_id)', function () {
    ingestScrape(orgRecord());
    // Autre run_id : l'idempotence par run ne joue pas, c'est bien l'ancre
    // (pays, foreign_id) qui doit empêcher le doublon.
    $second = ingestScrape(orgRecord(['run_id' => (string) Str::uuid()]));

    expect($second->status)->toBe('updated')
        ->and(DB::table('companies')->where('foreign_id', 'ccifer:asociatia-zz-test')->count())->toBe(1);
});

test('deux entités étrangères DIFFÉRENTES coexistent (siren NULL ne les confond pas)', function () {
    ingestScrape(orgRecord());
    ingestScrape(orgRecord([
        'run_id' => (string) Str::uuid(),
        'company' => [
            'foreign_id' => 'ccifer:fundatia-zz-test',
            'nature' => 'cci',
            'fields' => ['denomination' => 'FUNDATIA ZZ TEST'],
        ],
    ]));

    expect(DB::table('companies')->whereNull('siren')->where('country_code', 'RO')->count())->toBe(2);
});

test('BACKFILL-ONLY : une nature déjà posée n\'est jamais réécrite', function () {
    ingestScrape(orgRecord());
    ingestScrape(orgRecord([
        'run_id' => (string) Str::uuid(),
        'company' => ['nature' => 'entreprise'],
    ]));

    expect(DB::table('companies')->where('foreign_id', 'ccifer:asociatia-zz-test')->value('entity_nature'))
        ->toBe('association');
});

test('foreign_id sans country est REFUSÉ : un identifiant sans registre est indédupliquable', function () {
    expect(fn () => ingestScrape(orgRecord(['company' => ['country' => null]])))
        ->toThrow(ScrapeIngestRejection::class, 'indédupliquable');
});

test('une nature inventée est REFUSÉE (liste fermée, alignée sur le CHECK SQL)', function () {
    expect(fn () => ingestScrape(orgRecord(['company' => ['nature' => 'startup']])))
        ->toThrow(ScrapeIngestRejection::class, 'nature inconnue');
});

test('un siren annoncé avec un pays non-FR est REFUSÉ (contradiction)', function () {
    expect(fn () => ingestScrape(scrapedRecord([
        'company' => ['country' => 'RO'],
    ])))->toThrow(ScrapeIngestRejection::class, 'contradictoire');
});

test('ni siren ni foreign_id ni match_hint : refusé à la porte', function () {
    expect(fn () => ingestScrape(orgRecord([
        'company' => ['foreign_id' => null, 'country' => null],
    ])))->toThrow(ScrapeIngestRejection::class, 'au moins une clé de rattachement');
});

test('les fiches FRANÇAISES existantes gardent country_code FR et aucun tag pays', function () {
    ingestScrape(scrapedRecord());

    $company = DB::table('companies')->where('siren', '900000202')->first();
    expect($company->country_code)->toBe('FR')
        ->and($company->foreign_id)->toBeNull();

    $slugs = DB::table('company_tag')
        ->join('tags', 'tags.id', '=', 'company_tag.tag_id')
        ->where('company_tag.company_id', $company->id)
        ->pluck('tags.slug')->all();
    // `pays-fr` serait un tag universel sur 4,29 M de fiches : sans pouvoir de tri.
    expect($slugs)->not->toContain('pays-fr');
});

// ─────────────────────────────────────────────────────────────────────────────
// C18-002 — UNE PERSONNE ÉCARTÉE EST COMPTÉE, ET SON MOTIF EST DIT
// ─────────────────────────────────────────────────────────────────────────────

test('C18-002 — une personne sans nom de famille est COMPTÉE comme écartée, avec son motif', function () {
    // 🔴 CE QUI SE PASSAIT AVANT, ET POURQUOI PERSONNE NE LE VOYAIT.
    //
    // `upsertContact()` rend `skipped` quand la personne n'a pas de nom de
    // famille — et le `match` de l'ingestion faisait tomber ce cas dans un
    // `default => null`. La personne disparaissait donc entre le message et le
    // rapport SANS QU'UN SEUL CHIFFRE NE BOUGE : contacts_created = 0,
    // contacts_updated = 0, persons_skipped_opt_out = 0, emails_rejected_mx = 0.
    // Un run « created » avec zéro contact était indiscernable d'un run qui
    // n'apportait aucune personne.
    //
    // Une garde qui ne compterait que les contacts POSÉS resterait aveugle à la
    // récidive : elle serait verte dans les deux cas. C'est le COMPTEUR
    // D'ÉCARTÉS qu'il faut lire, et son MOTIF.
    $outcome = ingestScrape(scrapedRecord([
        'persons' => [[
            'first_name' => 'Sans',
            'last_name' => null,
            'email' => 'sans-nom@zz-scrape.example.invalid',
            'kind' => 'person',
        ]],
    ]));

    expect($outcome->contactsCreated)->toBe(0, 'Le banc ne mesure rien : la personne a été créée, elle ne devait pas l être.');

    expect($outcome->personsSkipped)->toBe(
        ['skipped_no_last_name' => 1],
        'La personne collectée a été écartée SANS être comptée : elle disparaît en silence du rapport '
        . "d'ingestion (constat C18-002). Geste : compter la branche `skipped_*` dans le `match` de "
        . '`ScrapedRecordIngestService::apply()` (le `default` est un fourre-tout volontaire) et la '
        . 'passer à `ScrapeIngestOutcome::$personsSkipped`.',
    );

    // Le rapport JOURNALISÉ (scraper_runs.response_payload, lu par le worker et
    // par la page des runs) doit porter le chiffre, pas seulement l'objet PHP :
    // c'est là que l'exploitant le lit.
    $paye = $outcome->toArray();
    expect($paye['persons_skipped'] ?? null)->toBe(
        1,
        'Le compteur existe en mémoire mais pas dans `toArray()` : `scraper_runs.response_payload` '
        . "reste muet, et l'exploitant n'a toujours aucun moyen de voir la perte. Geste : exposer "
        . '`persons_skipped` + `persons_skipped_reasons` dans `ScrapeIngestOutcome::toArray()`.',
    );
    expect($paye['persons_skipped_reasons'] ?? null)->toBe(
        ['skipped_no_last_name' => 1],
        'Le total est là mais pas le motif. Agrégé, « 1 personne écartée » ne dit pas s il faut '
        . 'corriger le collecteur ou se réjouir : les deux gestes sont opposés (constat C18-002).',
    );
});

test('C18-002 — un re-scrape qui n apporte RIEN est compté sous son propre motif, pas confondu avec une perte', function () {
    // Le même message rejoué (run_id neuf, contenu identique) : la fiche existe,
    // rien de nouveau à écrire. C'est la marche NORMALE d'une re-collecte — et
    // c'est précisément pour cela que le compteur est ventilé par motif :
    // confondre ce cas avec « le collecteur ramène des personnes sans nom »
    // ferait tirer la mauvaise sonnette.
    ingestScrape(scrapedRecord());
    $second = ingestScrape(scrapedRecord());

    expect($second->personsSkipped)->toBe(
        ['skipped_no_change' => 1],
        "Le re-scrape sans apport n'est pas compté sous `skipped_no_change` : soit la branche a "
        . 'disparu du `match`, soit les motifs ont été ré-agrégés en un seul compteur. Geste : garder '
        . 'les trois retours distincts de `upsertContact()` (constat C18-002).',
    );
    expect($second->contactsCreated + $second->contactsUpdated)->toBe(
        0,
        'Le re-scrape a écrit : la mesure ci-dessus ne porte plus sur le cas « rien à apporter ».',
    );
});
