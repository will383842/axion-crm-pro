<?php

/**
 * Mode AJOUT SEULEMENT du waterfall (`enrichSansPayant`) et job restreint
 * `EnrichirSiteOfficineJob`. Faux clients COMPTEURS : ils prouvent qu'une étape
 * n'est jamais appelée en mode sans payant, et l'est en mode normal.
 *
 * Fixtures FICTIVES (dépôt public) : SIREN 96xxxxxxx, noms « ZZ ».
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
use App\Jobs\EnrichirSiteOfficineJob;
use App\Models\Company;
use App\Models\Workspace;
use App\Services\Domain\DomainFinderService;
use App\Services\Email\EmailFinderService;
use App\Services\Legal\MentionsLegalesScraperService;
use App\Services\Scraping\GooglePlacesClient;
use App\Services\Triage\TriageAutoService;
use App\Services\Waterfall\WaterfallOrchestrator;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Compteurs partagés par les faux clients. */
final class PoCompteurs
{
    /** @var array<string, int> */
    public static array $n = [];

    /** @var list<array{0: string, 1: bool}> */
    public static array $domaines = [];

    public static array $telephones = ['0100000077'];

    /** Numéros lus hors des pages de mentions légales (null = les mêmes que `$telephones`). */
    public static ?array $horsMentions = null;

    /** Vrai : aucune page du site n'a répondu (panne réseau). */
    public static bool $aucunePage = false;

    /** Vrai : la BAN ne trouve rien (déclenche le repli Lambert). */
    public static bool $banVide = false;

    public static function reset(): void
    {
        self::$n = [];
        self::$domaines = [];
        self::$telephones = ['0100000077'];
        self::$horsMentions = null;
        self::$aucunePage = false;
        self::$banVide = false;
    }

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
    PoCompteurs::reset();
    $this->espace = (string) Str::uuid();
    Workspace::create(['id' => $this->espace, 'slug' => 'zz-po-' . Str::random(6), 'name' => 'ZZ orchestrateur']);
    config()->set('services.google.places.smart_skip', false);
    config()->set('services.scrapers.mock', true);

    $this->app->instance(InseeClient::class, new class implements InseeClient
    {
        public function fetchBySiren(string $siren): ?InseeCompanyData
        {
            PoCompteurs::inc('insee');

            return new InseeCompanyData(siren: $siren, denomination: 'ZZ AUTRE NOM', naf: '99.99Z', legalForm: '9999', effectifRange: '53', etatAdministratif: 'A');
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
            if (PoCompteurs::$banVide) {
                return null;
            }

            return new GeocodeResult(address: $address, lat: 48.5, lon: 2.5, insee: '75101', postcode: $postcode, city: 'ZZ-Ville');
        }
    });
    $this->app->instance(LLMClient::class, new class implements LLMClient
    {
        public function complete(LLMRequestData $request): LLMResponseData
        {
            PoCompteurs::inc('llm');

            return new LLMResponseData(text: '{"priority":"haute"}', providerUsed: 'faux', modelUsed: 'faux');
        }
    });
    $this->app->instance(GooglePlacesClient::class, new class extends GooglePlacesClient
    {
        public function searchText(string $query, ?string $regionCode = 'FR', ?string &$reason = null): ?array
        {
            PoCompteurs::inc('places');
            $reason = 'not_found';

            return null;
        }
    });
    $this->app->instance(DomainFinderService::class, new class extends DomainFinderService
    {
        public function __construct() {}

        public function findAvecMethode(Company $company, bool $sansBrave = false): ?array
        {
            PoCompteurs::$domaines[] = [(string) $company->denomination, $sansBrave];

            return ['url' => 'https://zz-trouve.example/', 'methode' => 'brave'];
        }
    });
    $this->app->instance(MentionsLegalesScraperService::class, new class extends MentionsLegalesScraperService
    {
        public function __construct() {}

        public function scrape(Company $company): bool
        {
            return false;
        }

        public function harvestFromWebsite(string $website): array
        {
            PoCompteurs::inc('moisson');

            return ['emails' => [], 'phones' => PoCompteurs::$telephones];
        }

        public function harvestTelephones(string $website): ?array
        {
            PoCompteurs::inc('moisson');

            return PoCompteurs::$aucunePage ? null : ['phones' => PoCompteurs::$telephones, 'hors_mentions' => PoCompteurs::$horsMentions ?? PoCompteurs::$telephones];
        }
    });
    $this->app->instance(EmailFinderService::class, new class extends EmailFinderService
    {
        public function __construct() {}

        public function find(string $firstName, string $lastName, string $domain, ?string $knownPattern = null): array
        {
            PoCompteurs::inc('hunter');

            return [];
        }
    });
    $this->app->instance(TriageAutoService::class, new class extends TriageAutoService
    {
        public function __construct() {}

        public function triage(Company $company): array
        {
            PoCompteurs::inc('triage');
            DB::table('companies')->where('id', $company->id)->update(['prospection_status' => 'archived_no_email', 'archive_reason' => 'no_email']);

            return [];
        }
    });
});

/** @param  array<string, mixed>  $attrs */
function poFiche(string $espace, array $attrs = []): int
{
    static $seq = 0;
    $seq++;
    if (isset($attrs['metadata']) && is_array($attrs['metadata'])) {
        $attrs['metadata'] = json_encode($attrs['metadata']);
    }

    return (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $espace,
        'siren' => (string) (960000000 + $seq),
        'denomination' => 'ZZ officine ' . $seq,
        'naf' => '47.73Z',
        'legal_form' => '5499',
        'effectif_range' => '11',
        'address' => '1 rue ZZ',
        'postcode' => '75001',
        'prospection_status' => 'ready_for_outreach',
        'discovery_source' => 'insee',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));
}

function poLigne(int $id): object
{
    return DB::table('companies')->where('id', $id)->first();
}

function poEnrichir(string $espace, int $id, bool $sansPayant): void
{
    WorkspaceContext::run($espace, function () use ($id, $sansPayant): void {
        $w = app(WaterfallOrchestrator::class);
        $c = Company::findOrFail($id);
        $sansPayant ? $w->enrichSansPayant($c) : $w->enrich($c);
    });
}

it('mode sans payant : aucun appel payant, rien d écrasé, lat/lon/insee posés car NULL', function () {
    $id = poFiche($this->espace, ['website' => 'https://zz-site.example/']);
    DB::table('contacts')->insert(['workspace_id' => $this->espace, 'company_id' => $id, 'last_name' => 'ZZ', 'created_at' => now(), 'updated_at' => now()]);

    poEnrichir($this->espace, $id, true);

    $l = poLigne($id);
    expect(PoCompteurs::get('places'))->toBe(0)
        ->and(PoCompteurs::get('llm'))->toBe(0)
        ->and(PoCompteurs::get('hunter'))->toBe(0)
        ->and(PoCompteurs::get('triage'))->toBe(0)
        ->and($l->prospection_status)->toBe('ready_for_outreach')
        ->and($l->archive_reason)->toBeNull()
        ->and($l->denomination)->toStartWith('ZZ officine')
        ->and($l->naf)->toBe('47.73Z')
        ->and($l->legal_form)->toBe('5499')
        ->and($l->effectif_range)->toBe('11')
        ->and((float) $l->lat)->toBe(48.5)
        ->and($l->insee)->toBe('75101');
});

it('mode sans payant : lat/lon/insee déjà posés ne sont pas réécrits', function () {
    $id = poFiche($this->espace, ['lat' => 43.1, 'lon' => 5.1, 'insee' => '13055']);

    poEnrichir($this->espace, $id, true);

    $l = poLigne($id);
    expect((float) $l->lat)->toBe(43.1)->and((float) $l->lon)->toBe(5.1)->and($l->insee)->toBe('13055');
});

it('mode sans payant : aucune étiquette AutoTagger retirée', function () {
    $id = poFiche($this->espace, ['department_code' => '75']);
    $tag = WorkspaceContext::run($this->espace, function () use ($id): int {
        $tag = DB::table('tags')->insertGetId(['workspace_id' => $this->espace, 'slug' => 'dept-99', 'name' => 'ZZ', 'category' => 'geo', 'kind' => 'auto', 'rules' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_tag')->insert(['company_id' => $id, 'tag_id' => $tag, 'workspace_id' => $this->espace, 'assigned_at' => now(), 'assigned_by' => 'auto-rule']);

        return $tag;
    });

    poEnrichir($this->espace, $id, true);

    expect(WorkspaceContext::run($this->espace, fn () => DB::table('company_tag')->where('company_id', $id)->where('tag_id', $tag)->exists()))->toBeTrue();
});

it('mode sans payant : la recherche de site se fait avec Brave plafond 0', function () {
    $id = poFiche($this->espace);

    poEnrichir($this->espace, $id, true);

    expect(PoCompteurs::$domaines)->toHaveCount(1)->and(PoCompteurs::$domaines[0][1])->toBeTrue();
});

it('mode normal : les mêmes étapes sont appelées et la fiche est réécrite', function () {
    $id = poFiche($this->espace, ['website' => 'https://zz-site.example/']);
    DB::table('contacts')->insert(['workspace_id' => $this->espace, 'company_id' => $id, 'last_name' => 'ZZ', 'created_at' => now(), 'updated_at' => now()]);

    poEnrichir($this->espace, $id, false);

    $l = poLigne($id);
    expect(PoCompteurs::get('places'))->toBe(1)
        ->and(PoCompteurs::get('llm'))->toBe(1)
        ->and(PoCompteurs::get('hunter'))->toBe(1)
        ->and(PoCompteurs::get('triage'))->toBe(1)
        ->and($l->prospection_status)->toBe('archived_no_email')
        ->and($l->denomination)->toBe('ZZ AUTRE NOM')
        ->and($l->naf)->toBe('99.99Z')
        ->and($l->legal_form)->toBe('9999')
        ->and($l->effectif_range)->toBe('53');
});

it('mode normal : Brave n est pas mis à plafond 0', function () {
    $id = poFiche($this->espace);

    poEnrichir($this->espace, $id, false);

    expect(PoCompteurs::$domaines[0][1])->toBeFalse();
});

it('EnrichirSiteOfficineJob : site et téléphone posés quand ils manquent, recherche par enseigne', function () {
    $id = poFiche($this->espace, ['denomination' => 'ZZ PERSONNE NOM', 'enseigne' => 'Pharmacie ZZ Enseigne', 'legal_form' => '1000']);

    (new EnrichirSiteOfficineJob($id))->pourEspace($this->espace)->handle(app(DomainFinderService::class), app(MentionsLegalesScraperService::class));

    $l = poLigne($id);
    expect($l->website)->toBe('https://zz-trouve.example/')
        ->and($l->phone)->toBe('0100000077')
        ->and($l->denomination)->toBe('ZZ PERSONNE NOM')
        ->and(PoCompteurs::$domaines)->toBe([['Pharmacie ZZ Enseigne', true]]);
});

it('EnrichirSiteOfficineJob : sans enseigne, le nom de la personne n est jamais cherché', function () {
    $id = poFiche($this->espace, ['denomination' => 'ZZ PERSONNE NOM', 'legal_form' => '1000']);

    (new EnrichirSiteOfficineJob($id))->pourEspace($this->espace)->handle(app(DomainFinderService::class), app(MentionsLegalesScraperService::class));

    expect(PoCompteurs::$domaines)->toBe([])->and(poLigne($id)->website)->toBeNull();
});

it('EnrichirSiteOfficineJob : téléphone et site déjà présents ne sont pas écrasés', function () {
    $id = poFiche($this->espace, ['legal_form' => '1000', 'enseigne' => 'ZZ', 'phone' => '0100000001', 'website' => 'https://zz-existant.example/']);

    (new EnrichirSiteOfficineJob($id))->pourEspace($this->espace)->handle(app(DomainFinderService::class), app(MentionsLegalesScraperService::class));

    $l = poLigne($id);
    expect($l->phone)->toBe('0100000001')->and($l->website)->toBe('https://zz-existant.example/')
        ->and(PoCompteurs::$domaines)->toBe([])->and(PoCompteurs::get('moisson'))->toBe(0);
});

it('EnrichirSiteOfficineJob : une fiche protégée n est pas touchée', function () {
    $id = poFiche($this->espace, ['legal_form' => '1000', 'enseigne' => 'ZZ']);
    $tag = WorkspaceContext::run($this->espace, function () use ($id): int {
        $tag = DB::table('tags')->insertGetId(['workspace_id' => $this->espace, 'slug' => 'src:scraping-evenements-pro', 'name' => 'ZZ', 'category' => 'intent', 'kind' => 'auto', 'rules' => '{}', 'is_locked' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_tag')->insert(['company_id' => $id, 'tag_id' => $tag, 'workspace_id' => $this->espace, 'assigned_at' => now(), 'assigned_by' => 'auto-rule']);

        return $tag;
    });

    (new EnrichirSiteOfficineJob($id))->pourEspace($this->espace)->handle(app(DomainFinderService::class), app(MentionsLegalesScraperService::class));

    $l = poLigne($id);
    expect($l->website)->toBeNull()->and($l->phone)->toBeNull()->and(PoCompteurs::$domaines)->toBe([]);
});

it('EnrichirSiteOfficineJob : un numéro en opposition est écarté, le suivant est posé', function () {
    PoCompteurs::$telephones = ['0100000066', '0100000077'];
    DB::table('opt_out')->insert(['email' => null, 'phone' => '0100000066', 'scope' => 'business', 'source' => 'test', 'created_at' => now()]);
    $id = poFiche($this->espace, ['legal_form' => '1000', 'website' => 'https://zz-site.example/']);

    (new EnrichirSiteOfficineJob($id))->pourEspace($this->espace)->handle(app(DomainFinderService::class), app(MentionsLegalesScraperService::class));

    expect(poLigne($id)->phone)->toBe('0100000077');
});

it('EnrichirSiteOfficineJob : tous les numéros en opposition, aucun n est posé', function () {
    PoCompteurs::$telephones = ['0100000066'];
    DB::table('opt_out')->insert(['email' => null, 'phone' => '0100000066', 'scope' => 'business', 'source' => 'test', 'created_at' => now()]);
    $id = poFiche($this->espace, ['legal_form' => '1000', 'website' => 'https://zz-site.example/']);

    (new EnrichirSiteOfficineJob($id))->pourEspace($this->espace)->handle(app(DomainFinderService::class), app(MentionsLegalesScraperService::class));

    expect(poLigne($id)->phone)->toBeNull();
});

it('EnrichirSiteOfficineJob : un numéro hors page de mentions légales est préféré', function () {
    PoCompteurs::$telephones = ['0100000066', '0100000077'];
    PoCompteurs::$horsMentions = ['0100000077'];
    $id = poFiche($this->espace, ['legal_form' => '1000', 'website' => 'https://zz-site.example/']);

    (new EnrichirSiteOfficineJob($id))->pourEspace($this->espace)->handle(app(DomainFinderService::class), app(MentionsLegalesScraperService::class));

    expect(poLigne($id)->phone)->toBe('0100000077');
});

it('EnrichirSiteOfficineJob : aucune page n a répondu, la fiche n est pas marquée passée', function () {
    PoCompteurs::$aucunePage = true;
    $id = poFiche($this->espace, ['legal_form' => '1000', 'website' => 'https://zz-site.example/', 'metadata' => ['enrichissement_pharmacie' => ['le' => now()->toDateTimeString(), 'mode' => 'physique'], 'autre' => 'garde']]);

    (new EnrichirSiteOfficineJob($id))->pourEspace($this->espace)->handle(app(DomainFinderService::class), app(MentionsLegalesScraperService::class));

    $meta = json_decode(poLigne($id)->metadata, true);
    expect($meta)->not->toHaveKey('enrichissement_pharmacie')->and($meta['autre'])->toBe('garde')->and(poLigne($id)->phone)->toBeNull();
});

it('EnrichirSiteOfficineJob : pages lues sans numéro, la fiche reste marquée passée', function () {
    PoCompteurs::$telephones = [];
    $id = poFiche($this->espace, ['legal_form' => '1000', 'website' => 'https://zz-site.example/', 'metadata' => ['enrichissement_pharmacie' => ['le' => now()->toDateTimeString(), 'mode' => 'physique']]]);

    (new EnrichirSiteOfficineJob($id))->pourEspace($this->espace)->handle(app(DomainFinderService::class), app(MentionsLegalesScraperService::class));

    expect(json_decode(poLigne($id)->metadata, true))->toHaveKey('enrichissement_pharmacie');
});

it('repli Lambert en mode ajout seulement : pose lat/lon quand ils manquent', function () {
    PoCompteurs::$banVide = true;
    $id = poFiche($this->espace, ['metadata' => ['gps_lambert_x' => 652000, 'gps_lambert_y' => 6862000]]);

    poEnrichir($this->espace, $id, true);

    $l = poLigne($id);
    expect($l->lat)->not->toBeNull()->and((float) $l->lat)->toBeGreaterThan(48.0)->toBeLessThan(49.5)
        ->and((float) $l->lon)->toBeGreaterThan(1.0)->toBeLessThan(4.0);
});

it('repli Lambert en mode ajout seulement : un lat/lon déjà posé n est pas réécrit', function () {
    PoCompteurs::$banVide = true;
    $id = poFiche($this->espace, ['lat' => 43.1, 'lon' => 5.1, 'metadata' => ['gps_lambert_x' => 652000, 'gps_lambert_y' => 6862000]]);

    poEnrichir($this->espace, $id, true);

    $l = poLigne($id);
    expect((float) $l->lat)->toBe(43.1)->and((float) $l->lon)->toBe(5.1);
});
