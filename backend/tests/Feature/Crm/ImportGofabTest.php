<?php

/**
 * IMPORT DES PARTICIPANTS DU SALON GOFAB 2026 PAR LA PORTE COMMUNE (2026-09-29).
 *
 * Ce que Will attend : compléter les fiches existantes sans rien écraser,
 * ajouter les contacts même quand la fiche en porte déjà, et savoir que
 * l'information vient de GOFAB. Fixtures FICTIVES uniquement (dépôt public).
 */

use App\Crm\Scraping\ScrapedRecord;
use App\Crm\Scraping\ScrapedRecordIngestService;
use App\Crm\Scraping\ScrapeIngestOutcome;
use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config([
        'crm.scrape_funnel.enabled' => true,
        'crm.scrape_funnel.validate_mx' => false,
        'crm.ingest.business_workspace' => 'axion-ia',
    ]);

    $this->espace = (string) (DB::table('workspaces')->where('slug', 'axion-ia')->value('id') ?? Str::uuid());
    if (! DB::table('workspaces')->where('id', $this->espace)->exists()) {
        DB::table('workspaces')->insert([
            'id' => $this->espace, 'slug' => 'axion-ia', 'name' => 'Axion-IA', 'settings' => '{}',
            'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // La fiche existe AVANT GOFAB, avec un site et un contact à elle.
    $this->fiche = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace,
        'siren' => '900000951',
        'denomination' => 'ZZ Mecanique',
        'website' => 'https://zz-mecanique.example.invalid',
        'metadata' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('contacts')->insert([
        'workspace_id' => $this->espace,
        'company_id' => $this->fiche,
        'first_name' => 'Ancien',
        'last_name' => 'ZZ Contact',
        'email' => 'ancien@zz-mecanique.example.invalid',
        'discovery_source' => 'mentions-legales',
        'sources' => json_encode(['mentions-legales']),
        'metadata' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

/** @param  array<string, mixed>  $surcharge */
function gofabLigne(array $surcharge = []): array
{
    return array_replace_recursive([
        'schema_version' => 1,
        'source' => 'gofab-2026',
        'run_id' => 'gofab-' . Str::random(8),
        'status' => 'success',
        'company' => [
            'siren' => '900000951',
            'fields' => ['denomination' => 'ZZ Mecanique (nom affiche au salon)', 'website' => 'https://autre.example.invalid'],
        ],
        'persons' => [[
            'first_name' => 'Nouvelle',
            'last_name' => 'ZZ Dirigeante',
            'role' => 'Dirigeant DG',
            'email' => 'nouvelle@zz-mecanique.example.invalid',
            'phone' => '+33600000951',
        ]],
    ], $surcharge);
}

function gofabIngerer(array $brut): ScrapeIngestOutcome
{
    return app(ScrapedRecordIngestService::class)->ingest(ScrapedRecord::fromArray($brut), false);
}

test('la source gofab-2026 est au registre, active, des la migration', function () {
    // Pas de `seed()` : c'est la MIGRATION qui doit l'avoir posée, les seeders
    // ne tournent pas au déploiement.
    expect(DB::table('scraping_sources')->where('slug', 'gofab-2026')->value('enabled'))->toBeTrue();
});

test('le contact GOFAB s ajoute a la fiche existante, a cote de l ancien, sans rien ecraser', function () {
    $this->seed(ScrapingSourcesSeeder::class);

    gofabIngerer(gofabLigne());

    $fiche = DB::table('companies')->where('id', $this->fiche)->first();
    expect($fiche->denomination)->toBe('ZZ Mecanique')
        ->and($fiche->website)->toBe('https://zz-mecanique.example.invalid')
        ->and(DB::table('companies')->where('siren', '900000951')->count())->toBe(1);

    $contacts = DB::table('contacts')->where('company_id', $this->fiche)->orderBy('id')->get();
    expect($contacts)->toHaveCount(2)
        ->and($contacts[0]->email)->toBe('ancien@zz-mecanique.example.invalid')
        ->and($contacts[1]->email)->toBe('nouvelle@zz-mecanique.example.invalid')
        ->and($contacts[1]->role)->toBe('Dirigeant DG')
        ->and($contacts[1]->phone)->toBe('+33600000951')
        ->and($contacts[1]->discovery_source)->toBe('gofab-2026');
});

test('la fiche porte la provenance GOFAB : tag et activite dans la timeline', function () {
    $this->seed(ScrapingSourcesSeeder::class);

    gofabIngerer(gofabLigne());

    $tag = DB::table('tags')->where('workspace_id', $this->espace)->where('slug', 'src:scraping-gofab-2026')->first();
    expect($tag)->not->toBeNull()
        ->and($tag->name)->toContain('GOFAB 2026')
        ->and(DB::table('company_tag')->where('company_id', $this->fiche)->where('tag_id', $tag->id)->exists())->toBeTrue()
        ->and(DB::table('activities')->where('subject_type', 'company')->where('subject_id', $this->fiche)->where('kind', 'scraped')->exists())->toBeTrue();
});

test('un contact deja present garde ses valeurs et gagne la source gofab-2026', function () {
    $this->seed(ScrapingSourcesSeeder::class);

    gofabIngerer(gofabLigne(['persons' => [[
        'first_name' => 'Ancien', 'last_name' => 'ZZ Contact', 'role' => 'Directeur production',
        'email' => 'ancien@zz-mecanique.example.invalid', 'phone' => '+33600000952',
    ]]]));

    $ancien = DB::table('contacts')->where('email', 'ancien@zz-mecanique.example.invalid')->first();
    expect(json_decode($ancien->sources, true))->toBe(['mentions-legales', 'gofab-2026'])
        ->and($ancien->discovery_source)->toBe('mentions-legales')
        ->and($ancien->role)->toBe('Directeur production')
        ->and($ancien->phone)->toBe('+33600000952')
        ->and(DB::table('contacts')->where('company_id', $this->fiche)->count())->toBe(1);
});

test('une fiche GOFAB est protegee : la base refuse sa suppression, le temoin passe', function () {
    $this->seed(ScrapingSourcesSeeder::class);
    gofabIngerer(gofabLigne());

    $temoin = (int) DB::table('companies')->insertGetId([
        'workspace_id' => $this->espace, 'siren' => '900000952', 'denomination' => 'ZZ Temoin',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(App\Crm\FichesProtegees::estProtegee($this->fiche))->toBeTrue()
        ->and(App\Crm\FichesProtegees::estProtegee($temoin))->toBeFalse()
        ->and(fn () => DB::transaction(fn () => DB::table('companies')->where('id', $this->fiche)->delete()))
        ->toThrow(Illuminate\Database\QueryException::class, 'fiche_protegee');

    DB::table('companies')->where('id', $temoin)->delete();
    expect(DB::table('companies')->where('id', $temoin)->exists())->toBeFalse()
        ->and(DB::table('companies')->where('id', $this->fiche)->exists())->toBeTrue();
});

test('la note juridique de la source ne parle plus d echeance', function () {
    expect((string) DB::table('scraping_sources')->where('slug', 'gofab-2026')->value('legal_note'))
        ->not->toContain('art. 14')
        ->toContain('FichesProtegees');
});
