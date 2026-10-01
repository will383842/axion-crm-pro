<?php

/**
 * FÉDÉRATIONS — protégées comme les organisateurs (chantier 3, 2026-09-29).
 *
 * Will a INTERDIT de supprimer les contacts des organisateurs d'événements
 * (27/09) ; la même règle vaut pour les fédérations. Chaque chemin est prouvé
 * avec le tag `src:scraping-federations-2026`, face à un TÉMOIN ordinaire :
 * sans lui, une commande qui ne fait plus rien passerait pour une garde.
 *
 * Et la purge de rétention (`rgpd:purge-business-prospects`), qui visait les
 * contacts des fiches protégées sans le savoir (constat de l'audit du 28/09),
 * est prouvée pour les DEUX tags.
 */

use App\Crm\Campagnes\Segments;
use App\Crm\FichesProtegees;
use App\Models\Workspace;
use App\Services\Audiences\AudienceBuilderService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function fedpEspace(): string
{
    $id = (string) Str::uuid();
    Workspace::create(['id' => $id, 'slug' => 'fedp-' . Str::random(8), 'name' => 'Espace federations']);

    return $id;
}

/** @param  array<string, mixed>  $attrs */
function fedpOrdinaire(string $espace, array $attrs = []): int
{
    static $seq = 0;
    $seq++;

    return (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $espace,
        'siren' => str_pad((string) (910000000 + $seq), 9, '0', STR_PAD_LEFT),
        'denomination' => 'ZZ Entreprise ordinaire ' . $seq,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));
}

/** @param  array<string, mixed>  $attrs */
function fedpProtegee(string $espace, array $attrs = [], string $slug = FichesProtegees::TAG_FEDERATIONS): int
{
    static $seq = 0;
    $seq++;

    $id = (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $espace,
        'siren' => str_pad((string) (920000000 + $seq), 9, '0', STR_PAD_LEFT),
        'entity_nature' => 'federation',
        'denomination' => 'ZZ Federation fictive ' . $seq,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));

    $tagId = DB::table('tags')->where('workspace_id', $espace)->where('slug', $slug)->value('id')
        ?? DB::table('tags')->insertGetId([
            'workspace_id' => $espace, 'slug' => $slug, 'name' => 'Collecte — fédérations',
            'category' => 'intent', 'kind' => 'auto', 'rules' => '{}', 'is_locked' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

    DB::table('company_tag')->insert([
        'company_id' => $id, 'tag_id' => (int) $tagId, 'workspace_id' => $espace,
        'assigned_at' => now(), 'assigned_by' => 'auto-rule',
    ]);

    return $id;
}

function fedpContact(string $espace, int $companyId, string $nom, int $ageEnAnnees): int
{
    return (int) DB::table('contacts')->insertGetId([
        'workspace_id' => $espace, 'company_id' => $companyId, 'last_name' => $nom,
        'legal_basis' => 'legitimate_interest_b2b',
        'created_at' => now()->subYears($ageEnAnnees), 'updated_at' => now()->subYears($ageEnAnnees),
    ]);
}

test('le tag des federations est protege, et chaque segment designe son tag par son NOM', function () {
    expect(FichesProtegees::TAGS)->toContain(FichesProtegees::TAG_FEDERATIONS, FichesProtegees::TAG_ORGANISATEURS)
        ->and(Segments::ouverts())->toContain(Segments::FEDERATIONS)
        ->and(Segments::tag(Segments::FEDERATIONS))->toBe('src:scraping-federations-2026')
        ->and(Segments::tag(Segments::ORGANISATEURS_EVENEMENTS))->toBe('src:scraping-evenements-pro')
        ->and(Segments::tagOrganisateurs())->toBe('src:scraping-evenements-pro');

    // Un segment ouvert ne vise QUE des fiches protégées : sa levée de
    // protection est la décision explicite que `FichesProtegees` exige.
    foreach (Segments::CONNUS as $segment) {
        expect(FichesProtegees::TAGS)->toContain(Segments::tag($segment));
    }
});

test('purge-non-commercial --force epargne la federation et supprime le temoin', function () {
    $espace = fedpEspace();
    $protegee = fedpProtegee($espace); // legal_form NULL : visée par la condition
    $temoin = fedpOrdinaire($espace, ['legal_form' => '9220']);
    for ($i = 0; $i < 8; $i++) {
        fedpOrdinaire($espace, ['legal_form' => '5710']);
    }

    Artisan::call('prospection:purge-non-commercial', ['--force' => true]);

    expect(DB::table('companies')->where('id', $protegee)->exists())->toBeTrue()
        ->and(DB::table('companies')->where('id', $temoin)->exists())->toBeFalse();
});

test('find-websites ne ramasse pas la federation, mais traite le temoin', function () {
    Http::fake(['*' => Http::response('', 404)]);

    $espace = fedpEspace();
    $protegee = fedpProtegee($espace, ['website_status' => 'pending', 'website' => null]);
    $temoin = fedpOrdinaire($espace, ['website_status' => 'pending', 'website' => null]);

    Artisan::call('prospection:find-websites', ['--limit' => 10]);

    expect(DB::table('companies')->where('id', $protegee)->value('website_status'))->toBe('pending')
        ->and(DB::table('companies')->where('id', $temoin)->value('website_status'))->not->toBe('pending');
});

test('le reclassement de masse garde le secteur REPRESENTE de la federation, et classe le temoin', function () {
    $espace = fedpEspace();
    $insee = ['naf' => '62.01Z', 'effectif_range' => '21'];
    $protegee = fedpProtegee($espace, $insee + ['sector_main' => 'btp']);
    $temoin = fedpOrdinaire($espace, $insee + ['sector_main' => 'btp']);

    $slug = (string) DB::table('workspaces')->where('id', $espace)->value('slug');
    Artisan::call('crm:referentiels:reclasser', ['--workspace' => $slug]);

    expect(DB::table('companies')->where('id', $protegee)->value('sector_main'))->toBe('btp')
        ->and(DB::table('companies')->where('id', $protegee)->value('size_category'))->toBeNull()
        ->and(DB::table('companies')->where('id', $temoin)->value('sector_main'))->toBe('numerique_telecoms');
});

test('une audience par defaut n accueille pas la federation, mais accueille le temoin', function () {
    $espace = fedpEspace();
    $protegee = fedpProtegee($espace);
    $temoin = fedpOrdinaire($espace);

    $ids = (new AudienceBuilderService)->buildPublicQuery($espace, [])->pluck('id')->map(fn ($id) => (int) $id)->all();

    expect($ids)->toContain($temoin)->not->toContain($protegee);
});

test('la base refuse la suppression physique d une federation', function () {
    $espace = fedpEspace();
    $protegee = fedpProtegee($espace);

    expect(fn () => DB::transaction(fn () => DB::table('companies')->where('id', $protegee)->delete()))
        ->toThrow(QueryException::class, 'fiche_protegee');
});

test('la purge RGPD de 3 ans epargne les contacts des fiches protegees (federations ET organisateurs), et purge le temoin', function () {
    config(['crm.purges_enabled' => true]);

    $espace = fedpEspace();
    $federation = fedpProtegee($espace);
    $organisateur = fedpProtegee($espace, [], FichesProtegees::TAG_ORGANISATEURS);
    $ordinaire = fedpOrdinaire($espace);

    $cFederation = fedpContact($espace, $federation, 'ZZ Presidente', 4);
    $cOrganisateur = fedpContact($espace, $organisateur, 'ZZ Organisateur', 4);
    $cTemoin = fedpContact($espace, $ordinaire, 'ZZ Temoin', 4);

    Artisan::call('rgpd:purge-business-prospects', ['--force' => true]);

    expect(DB::table('contacts')->where('id', $cFederation)->exists())->toBeTrue()
        ->and(DB::table('contacts')->where('id', $cOrganisateur)->exists())->toBeTrue()
        ->and(DB::table('contacts')->where('id', $cTemoin)->exists())->toBeFalse();
});
