<?php

/**
 * PR 0 — couper la dépense dormante.
 *
 * Par défaut (interrupteurs fermés) : `enrich()` ne touche ni Google Places,
 * ni le LLM, ne pousse aucun DispatchScrapeJob ; Pages Jaunes n'est pas lu ;
 * le plafond Google Places vaut 0. Interrupteur ouvert : l'ancien comportement
 * revient (tests témoins).
 *
 * Fixtures FICTIVES (dépôt public) : SIREN 95xxxxxxx, noms « ZZ ».
 */

use App\Contracts\AnnuaireEntreprisesClient;
use App\Contracts\BanGeocoder;
use App\Contracts\BodaccClient;
use App\Contracts\InseeClient;
use App\Contracts\LLMClient;
use App\Data\LLM\LLMRequestData;
use App\Data\LLM\LLMResponseData;
use App\Data\Sources\AnnuaireEntrepriseData;
use App\Data\Sources\GeocodeResult;
use App\Data\Sources\InseeCompanyData;
use App\Jobs\DispatchScrapeJob;
use App\Models\Company;
use App\Models\Workspace;
use App\Services\Audiences\AudienceBuilderService;
use App\Services\Domain\DomainFinderService;
use App\Services\Email\EmailFinderService;
use App\Services\Legal\MentionsLegalesScraperService;
use App\Services\Scraping\GooglePlacesClient;
use App\Services\Triage\TriageAutoService;
use App\Services\Waterfall\WaterfallOrchestrator;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Compteurs des doublures. */
/** Doublure d'AudienceBuilderService : compte les appels, sans toucher la base. */
final class DdAudienceCompteur extends AudienceBuilderService
{
    public function __construct() {}

    public function evaluateForCompany(Company $company): array
    {
        DdCompteurs::inc('audience');

        return [];
    }
}

final class DdCompteurs
{
    /** @var array<string, int> */
    public static array $n = [];

    public static function inc(string $k): void
    {
        self::$n[$k] = (self::$n[$k] ?? 0) + 1;
    }

    public static function get(string $k): int
    {
        return self::$n[$k] ?? 0;
    }
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-26 10:00:00', 'UTC'));
    DdCompteurs::$n = [];
    $this->espace = (string) Str::uuid();
    Workspace::create(['id' => $this->espace, 'slug' => 'zz-dd-' . Str::random(6), 'name' => 'ZZ depense dormante']);
    config()->set('services.google.places.smart_skip', false);
    Http::preventStrayRequests();

    $this->app->instance(InseeClient::class, new class implements InseeClient
    {
        public function fetchBySiren(string $siren): ?InseeCompanyData
        {
            return null;
        }

        public function searchByCriteria(array $criteria): array
        {
            return [];
        }

        public function iterateByCriteria(array $criteria): Generator
        {
            yield from [];
        }
    });
    $this->app->instance(AnnuaireEntreprisesClient::class, new class implements AnnuaireEntreprisesClient
    {
        public function fetchBySiren(string $siren): ?AnnuaireEntrepriseData
        {
            return null;
        }
    });
    $this->app->instance(BodaccClient::class, new class implements BodaccClient
    {
        public function fetchAnnouncementsBySiren(string $siren): array
        {
            return [];
        }
    });
    $this->app->instance(BanGeocoder::class, new class implements BanGeocoder
    {
        public function geocode(string $address, ?string $postcode = null): ?GeocodeResult
        {
            return null;
        }
    });
    $this->app->instance(LLMClient::class, new class implements LLMClient
    {
        public function complete(LLMRequestData $request): LLMResponseData
        {
            DdCompteurs::inc('llm');

            return new LLMResponseData(text: '{"priority":"haute"}', providerUsed: 'faux', modelUsed: 'faux');
        }
    });
    $this->app->instance(GooglePlacesClient::class, new class extends GooglePlacesClient
    {
        public function searchText(string $query, ?string $regionCode = 'FR', ?string &$reason = null): ?array
        {
            DdCompteurs::inc('places');
            $reason = 'not_found';

            return null;
        }
    });
    $this->app->instance(DomainFinderService::class, new class extends DomainFinderService
    {
        public function __construct() {}

        public function findAvecMethode(Company $company, bool $sansBrave = false): ?array
        {
            return null;
        }
    });
    $this->app->instance(MentionsLegalesScraperService::class, new class extends MentionsLegalesScraperService
    {
        public function __construct() {}

        public function scrape(Company $company): bool
        {
            return false;
        }
    });
    $this->app->instance(EmailFinderService::class, new class extends EmailFinderService
    {
        public function __construct() {}

        public function find(string $firstName, string $lastName, string $domain, ?string $knownPattern = null): array
        {
            DdCompteurs::inc('hunter');

            return [];
        }
    });
    $this->app->instance(TriageAutoService::class, new class extends TriageAutoService
    {
        public function __construct() {}

        public function triage(Company $company): array
        {
            DdCompteurs::inc('triage');

            return [];
        }
    });
});

afterEach(function () {
    Carbon::setTestNow();
});

function ddEnrichir(string $espace): int
{
    $id = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $espace,
        'siren' => '950000001',
        'denomination' => 'ZZ dormante',
        'naf' => '47.73Z',
        'address' => '1 rue ZZ',
        'postcode' => '75001',
        'prospection_status' => 'ready_for_outreach',
        'discovery_source' => 'insee',
        'website' => 'https://zz.example/',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('contacts')->insert(['workspace_id' => $espace, 'company_id' => $id, 'last_name' => 'ZZ', 'created_at' => now(), 'updated_at' => now()]);
    WorkspaceContext::run($espace, function () use ($id): void {
        app(WaterfallOrchestrator::class)->enrich(Company::findOrFail($id));
    });

    return $id;
}

/** Ouvre les scrapers « réels » : sans l'interrupteur, ils partiraient. */
function ddScrapersReels(): void
{
    putenv('MOCK_MODE=false');
    putenv('MOCK_SCRAPERS=false');
    $_ENV['MOCK_MODE'] = $_SERVER['MOCK_MODE'] = 'false';
    $_ENV['MOCK_SCRAPERS'] = $_SERVER['MOCK_SCRAPERS'] = 'false';
}

function ddRemettreMocks(): void
{
    putenv('MOCK_MODE=true');
    putenv('MOCK_SCRAPERS=true');
    $_ENV['MOCK_MODE'] = $_SERVER['MOCK_MODE'] = 'true';
    $_ENV['MOCK_SCRAPERS'] = $_SERVER['MOCK_SCRAPERS'] = 'true';
}

it('enrich() par défaut : ni Google Places, ni LLM, ni e-mail payant, aucun DispatchScrapeJob', function () {
    Bus::fake();
    ddScrapersReels();

    try {
        ddEnrichir($this->espace);
    } finally {
        ddRemettreMocks();
    }

    expect(DdCompteurs::get('places'))->toBe(0)
        ->and(DdCompteurs::get('llm'))->toBe(0)
        ->and(DdCompteurs::get('hunter'))->toBe(0);
    Bus::assertNotDispatched(DispatchScrapeJob::class);
});

it('témoin : payant_actif ouvert, les étapes payantes repartent', function () {
    Bus::fake();
    config()->set('crm.enrichissement.payant_actif', true);

    ddEnrichir($this->espace);

    expect(DdCompteurs::get('places'))->toBe(1)
        ->and(DdCompteurs::get('llm'))->toBeGreaterThan(0)
        ->and(DdCompteurs::get('hunter'))->toBeGreaterThan(0);
});

it('enrich() par défaut : une entreprise radiée est toujours archivée', function () {
    $this->app->instance(InseeClient::class, new class implements InseeClient
    {
        public function fetchBySiren(string $siren): ?InseeCompanyData
        {
            return new InseeCompanyData(siren: $siren, denomination: 'ZZ dormante', naf: '47.73Z', legalForm: '9999', effectifRange: '53', etatAdministratif: 'C');
        }

        public function searchByCriteria(array $criteria): array
        {
            return [];
        }

        public function iterateByCriteria(array $criteria): Generator
        {
            yield from [];
        }
    });

    $id = ddEnrichir($this->espace);

    $ligne = DB::table('companies')->where('id', $id)->first();
    expect($ligne->prospection_status)->toBe('archived_no_email')
        ->and($ligne->archive_reason)->toBe('entreprise_radiee');
});

it('enrich() par défaut : le triage tourne', function () {
    ddEnrichir($this->espace);

    expect(DdCompteurs::get('triage'))->toBeGreaterThanOrEqual(1);
});

it('enrich() par défaut : l\'auto-segment tourne', function () {
    $this->app->instance(AudienceBuilderService::class, new DdAudienceCompteur);

    ddEnrichir($this->espace);

    expect(DdCompteurs::get('audience'))->toBeGreaterThanOrEqual(1);
});

it('témoin : annuaires_actifs ouvert et scrapers réels, les scrapes Node sont lancés', function () {
    Bus::fake();
    config()->set('crm.enrichissement.annuaires_actifs', true);
    ddScrapersReels();

    try {
        ddEnrichir($this->espace);
    } finally {
        ddRemettreMocks();
    }

    Bus::assertDispatched(DispatchScrapeJob::class);
});

it('DomainFinder : interrupteur fermé, aucune requête vers pagesjaunes.fr', function () {
    config()->set('services.brave.api_key', null);
    config()->set('services.scrapers.mock', false);
    Http::preventStrayRequests();
    Http::fake();
    $company = new Company(['denomination' => 'ZZ Dormante', 'city_name' => 'Lille']);

    expect((new DomainFinderService)->find($company))->toBeNull();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'pagesjaunes.fr'));
});

it('le plafond Google Places vaut 0 par défaut', function () {
    $services = (static function (): array {
        putenv('GOOGLE_PLACES_MONTHLY_QUOTA_LIMIT');
        unset($_ENV['GOOGLE_PLACES_MONTHLY_QUOTA_LIMIT'], $_SERVER['GOOGLE_PLACES_MONTHLY_QUOTA_LIMIT']);

        return require config_path('services.php');
    })();

    expect($services['google']['places']['monthly_quota_limit'])->toBe(0);
});

it('les deux interrupteurs sont fermés par défaut', function () {
    expect(config('crm.enrichissement.payant_actif'))->toBeFalse()
        ->and(config('crm.enrichissement.annuaires_actifs'))->toBeFalse();
});
