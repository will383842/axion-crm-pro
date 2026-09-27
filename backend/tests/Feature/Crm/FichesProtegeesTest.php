<?php

/**
 * FICHES PROTÉGÉES — chaque garde prouvée par son EFFET, face à un TÉMOIN.
 *
 * Les organisateurs d'événements (tag `src:scraping-evenements-pro`) : pas de
 * SIREN, pas de forme juridique, `website_status = pending` par défaut. Sur
 * `main` au 2026-09-27, la purge non commerciale les supprimait tous, les
 * chemins d'enrichissement les ramassaient, le reclassement les rangeait en
 * « TPE » et les audiences les accueillaient.
 *
 * Chaque test pose une fiche protégée ET une fiche ordinaire : sans le témoin,
 * une commande qui ne fait plus rien du tout passerait pour une garde réussie.
 */

use App\Crm\FichesProtegees;
use App\Models\Company;
use App\Models\Workspace;
use App\Services\Audiences\AudienceBuilderService;
use App\Services\Waterfall\WaterfallOrchestrator;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function fpEspace(): string
{
    $id = (string) Str::uuid();
    Workspace::create(['id' => $id, 'slug' => 'fp-' . Str::random(8), 'name' => 'Espace fiches protegees']);

    return $id;
}

/** @param  array<string, mixed>  $attrs */
function fpOrdinaire(string $espace, array $attrs = []): int
{
    static $seq = 0;
    $seq++;

    return (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $espace,
        'siren' => str_pad((string) (700000000 + $seq), 9, '0', STR_PAD_LEFT),
        'denomination' => 'Entreprise ordinaire ' . $seq,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));
}

/** @param  array<string, mixed>  $attrs */
function fpProtegee(string $espace, array $attrs = [], string $slug = 'src:scraping-evenements-pro'): int
{
    static $seq = 0;
    $seq++;

    $id = (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $espace,
        'siren' => null,
        'country_code' => 'FR',
        'foreign_id' => 'evt:club-exemple-' . $seq,
        'entity_nature' => 'association',
        'denomination' => 'Club exemple ' . $seq,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));

    $tagId = DB::table('tags')->where('workspace_id', $espace)->where('slug', $slug)->value('id')
        ?? DB::table('tags')->insertGetId([
            'workspace_id' => $espace,
            'slug' => $slug,
            'name' => 'Collecte — organisateurs',
            'category' => 'intent',
            'kind' => 'auto',
            'rules' => '{}',
            'is_locked' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

    DB::table('company_tag')->insert([
        'company_id' => $id,
        'tag_id' => (int) $tagId,
        'workspace_id' => $espace,
        'assigned_at' => now(),
        'assigned_by' => 'auto-rule',
    ]);

    return $id;
}

function fpExiste(int $id): bool
{
    return DB::table('companies')->where('id', $id)->exists();
}

test('le predicat reconnait la fiche protegee et pas le temoin', function () {
    $espace = fpEspace();
    $protegee = fpProtegee($espace);
    $temoin = fpOrdinaire($espace);

    expect(FichesProtegees::estProtegee($protegee))->toBeTrue()
        ->and(FichesProtegees::estProtegee($temoin))->toBeFalse();
});

test('purge-non-commercial --force epargne la fiche protegee et supprime le temoin', function () {
    $espace = fpEspace();
    $protegee = fpProtegee($espace); // legal_form NULL : visée par la condition
    $temoin = fpOrdinaire($espace, ['legal_form' => '9220']);
    for ($i = 0; $i < 8; $i++) {
        fpOrdinaire($espace, ['legal_form' => '5710']);
    }

    Artisan::call('prospection:purge-non-commercial', ['--force' => true]);

    expect(fpExiste($protegee))->toBeTrue()
        ->and(fpExiste($temoin))->toBeFalse();
});

test('purge-non-diffusible --force epargne la fiche protegee et supprime le temoin', function () {
    $espace = fpEspace();
    $protegee = fpProtegee($espace, ['denomination' => '[ND]']);
    $temoin = fpOrdinaire($espace, ['denomination' => '[ND]']);
    for ($i = 0; $i < 8; $i++) {
        fpOrdinaire($espace);
    }

    Artisan::call('prospection:purge-non-diffusible', ['--force' => true]);

    expect(fpExiste($protegee))->toBeTrue()
        ->and(fpExiste($temoin))->toBeFalse();
});

test('la base refuse la suppression physique de chaque tag protege, et laisse passer le temoin', function () {
    $espace = fpEspace();
    $temoin = fpOrdinaire($espace);

    foreach (FichesProtegees::TAGS as $slug) {
        $protegee = fpProtegee($espace, [], $slug);

        expect(fn () => DB::transaction(fn () => DB::table('companies')->where('id', $protegee)->delete()))
            ->toThrow(QueryException::class, 'fiche_protegee');
        expect(fpExiste($protegee))->toBeTrue();
    }

    DB::table('companies')->where('id', $temoin)->delete();
    expect(fpExiste($temoin))->toBeFalse();
});

test('la levee volontaire de la base permet l annulation d un import', function () {
    $espace = fpEspace();
    $protegee = fpProtegee($espace);

    DB::transaction(function () use ($protegee): void {
        DB::statement("SET LOCAL app.autoriser_suppression_protegee = 'on'");
        DB::table('companies')->where('id', $protegee)->delete();
    });

    expect(fpExiste($protegee))->toBeFalse();
});

test('find-websites ne ramasse pas la fiche protegee, mais traite le temoin', function () {
    Http::fake(['*' => Http::response('', 404)]);

    $espace = fpEspace();
    $protegee = fpProtegee($espace, ['website_status' => 'pending', 'website' => null]);
    $temoin = fpOrdinaire($espace, ['website_status' => 'pending', 'website' => null]);

    Artisan::call('prospection:find-websites', ['--limit' => 10]);

    expect(DB::table('companies')->where('id', $protegee)->value('website_status'))->toBe('pending')
        ->and(DB::table('companies')->where('id', $temoin)->value('website_status'))->not->toBe('pending');
});

test('prospection:enrich ne selectionne pas la fiche protegee, mais selectionne le temoin', function () {
    $espace = fpEspace();
    $protegee = fpProtegee($espace);
    $temoin = fpOrdinaire($espace);

    $espion = new class extends WaterfallOrchestrator
    {
        /** @var list<int> */
        public array $ids = [];

        public function __construct() {}

        public function enrich(Company $company): void
        {
            $this->ids[] = (int) $company->id;
        }
    };
    $this->app->instance(WaterfallOrchestrator::class, $espion);

    $this->artisan('prospection:enrich', ['--count' => 50, '--workspace' => $espace])->assertExitCode(0);

    expect($espion->ids)->toContain($temoin)->not->toContain($protegee);
});

test('le waterfall refuse d enrichir une fiche protegee, et enrichit le temoin', function () {
    Http::fake(['*' => Http::response('', 404)]);
    Queue::fake();

    $espace = fpEspace();
    $protegee = fpProtegee($espace);
    $temoin = fpOrdinaire($espace);

    WorkspaceContext::run($espace, function () use ($protegee, $temoin): void {
        $waterfall = app(WaterfallOrchestrator::class);
        $waterfall->enrich(Company::findOrFail($protegee));
        $waterfall->enrich(Company::findOrFail($temoin));
    });

    expect(DB::table('companies')->where('id', $protegee)->value('enriched_at'))->toBeNull()
        ->and(DB::table('companies')->where('id', $temoin)->value('enriched_at'))->not->toBeNull();
});

test('reclassify-size ne range pas la fiche protegee en TPE, mais classe le temoin', function () {
    $espace = fpEspace();
    $protegee = fpProtegee($espace);
    $temoin = fpOrdinaire($espace);

    Artisan::call('prospection:reclassify-size', ['--all' => true]);

    expect(DB::table('companies')->where('id', $protegee)->value('size_category'))->toBeNull()
        ->and(DB::table('companies')->where('id', $temoin)->value('size_category'))->toBe('tpe');
});

test('une audience n accueille pas la fiche protegee, mais accueille le temoin', function () {
    $espace = fpEspace();
    fpProtegee($espace);
    fpOrdinaire($espace);

    $apercu = (new AudienceBuilderService)->preview($espace, []);

    expect($apercu['companies'])->toBe(1);
});
