<?php

use App\Models\Company;
use App\Models\Workspace;
use App\Services\Waterfall\WaterfallOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Espion : remplace le waterfall réel (HTTP/scraping) par un no-op qui se contente
 * d'enregistrer les id des entreprises que la commande a SÉLECTIONNÉES pour
 * enrichissement. Permet d'assurer le filtrage ($q) sans exécuter d'enrichissement.
 * Le constructeur parent (13 dépendances) est volontairement court-circuité.
 */
function enrichSpy(): WaterfallOrchestrator
{
    return new class extends WaterfallOrchestrator
    {
        /** @var list<int> */
        public array $enrichedIds = [];

        public function __construct() {}

        public function enrich(Company $company): void
        {
            $this->enrichedIds[] = (int) $company->id;
        }
    };
}

function enrichWorkspace(): Workspace
{
    return Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'enr-' . Str::random(6),
        'name' => 'WS Enrich',
    ]);
}

function insertEnrichCompany(string $ws, array $attrs): int
{
    static $seq = 0;
    $seq++;

    return DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $ws,
        'siren' => str_pad((string) $seq, 9, '0', STR_PAD_LEFT),
        'denomination' => 'Société ' . $seq,
        'effectif_range' => '11', // valide (hors NN/00/01) → passe le filtre « a des salariés »
        'website_status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));
}

it('--with-website ne sélectionne que les entreprises à site vivant', function () {
    $ws = enrichWorkspace();
    $withSite = insertEnrichCompany($ws->id, ['website' => 'https://a.example', 'website_status' => 'found']);
    $noSite = insertEnrichCompany($ws->id, ['website' => null]);
    $empty = insertEnrichCompany($ws->id, ['website' => '']);
    $dead = insertEnrichCompany($ws->id, ['website' => 'https://b.example', 'website_status' => 'dead']);

    $spy = enrichSpy();
    $this->app->instance(WaterfallOrchestrator::class, $spy);

    $this->artisan('prospection:enrich', ['--count' => 50, '--with-website' => true])
        ->assertExitCode(0);

    expect($spy->enrichedIds)->toContain($withSite)
        ->not->toContain($noSite)
        ->not->toContain($empty)
        ->not->toContain($dead);
});

it('sans --with-website, la sélection est inchangée (inclut les sans-site)', function () {
    $ws = enrichWorkspace();
    $withSite = insertEnrichCompany($ws->id, ['website' => 'https://a.example', 'website_status' => 'found']);
    $noSite = insertEnrichCompany($ws->id, ['website' => null]);

    $spy = enrichSpy();
    $this->app->instance(WaterfallOrchestrator::class, $spy);

    $this->artisan('prospection:enrich', ['--count' => 50])->assertExitCode(0);

    expect($spy->enrichedIds)->toContain($withSite)->toContain($noSite);
});

it('--shards=2 --shard=0 ne prend que les id pairs', function () {
    $ws = enrichWorkspace();
    $ids = [];
    for ($i = 0; $i < 6; $i++) {
        $ids[] = insertEnrichCompany($ws->id, []);
    }
    $even = array_values(array_filter($ids, fn ($id) => $id % 2 === 0));
    $odd = array_values(array_filter($ids, fn ($id) => $id % 2 === 1));

    $spy = enrichSpy();
    $this->app->instance(WaterfallOrchestrator::class, $spy);

    $this->artisan('prospection:enrich', ['--count' => 50, '--shards' => 2, '--shard' => 0])
        ->assertExitCode(0);

    foreach ($even as $id) {
        expect($spy->enrichedIds)->toContain($id);
    }
    foreach ($odd as $id) {
        expect($spy->enrichedIds)->not->toContain($id);
    }
});

it('--shard hors plage échoue proprement', function () {
    $this->artisan('prospection:enrich', ['--count' => 1, '--shards' => 2, '--shard' => 2])
        ->assertExitCode(1);
});
