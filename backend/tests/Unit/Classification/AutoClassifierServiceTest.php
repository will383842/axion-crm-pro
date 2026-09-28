<?php

declare(strict_types=1);

use App\Crm\Referentiels\NomenclatureNaf;
use App\Models\Company;
use App\Models\Workspace;
use App\Services\Classification\AutoClassifierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->workspace = Workspace::create([
        'id' => Str::uuid()->toString(),
        'name' => 'Test WS',
        'slug' => 'test-ws-' . uniqid(),
    ]);
    $this->service = new AutoClassifierService;
});

function makeCompany(array $attrs): Company
{
    return Company::create(array_merge([
        'workspace_id' => test()->workspace->id,
        'siren' => (string) random_int(100000000, 999999999),
    ], $attrs));
}

it('extracts dept 75 from postcode 75008', function () {
    $c = makeCompany(['postcode' => '75008']);
    $this->service->classify($c);
    $c->refresh();
    expect($c->department_code)->toBe('75');
    expect($c->region_code)->toBe('11');
});

it('extracts dept 2A for Corse-du-Sud postcode 20000', function () {
    $c = makeCompany(['postcode' => '20000']);
    $this->service->classify($c);
    $c->refresh();
    expect($c->department_code)->toBe('2A');
    expect($c->region_code)->toBe('94');
});

it('extracts dept 2B for Haute-Corse postcode 20200', function () {
    $c = makeCompany(['postcode' => '20200']);
    $this->service->classify($c);
    $c->refresh();
    expect($c->department_code)->toBe('2B');
});

it('extracts dept 971 for DOM postcode 97110', function () {
    $c = makeCompany(['postcode' => '97110']);
    $this->service->classify($c);
    $c->refresh();
    expect($c->department_code)->toBe('971');
    expect($c->region_code)->toBe('01');
});

it('classifies effectif_range 21 as PME', function () {
    $c = makeCompany(['effectif_range' => '21']);
    $this->service->classify($c);
    $c->refresh();
    expect($c->size_category)->toBe('pme');
});

it('classifies effectif_range 52 as grand groupe', function () {
    $c = makeCompany(['effectif_range' => '52']);
    $this->service->classify($c);
    $c->refresh();
    expect($c->size_category)->toBe('grand_groupe');
});

it('classe 10-49 salariés en PME, au sens INSEE (l ancien calcul disait « tpe »)', function () {
    $c = makeCompany(['effectif_range' => '12']);
    $this->service->classify($c);
    $c->refresh();
    expect($c->size_category)->toBe('pme');
});

it('traduit une ancienne taille sans effectif (micro → tpe), sans en inventer une', function () {
    $micro = makeCompany(['size_category' => 'micro']);
    $sans = makeCompany([]);
    $this->service->classify($micro);
    $this->service->classify($sans);

    expect($micro->refresh()->size_category)->toBe('tpe')
        ->and($sans->refresh()->size_category)->toBeNull();
});

it('maps NAF 6201Z to numerique_telecoms, et garde le code d origine', function () {
    $c = makeCompany(['naf' => '6201Z']);
    $this->service->classify($c);
    $c->refresh();
    expect($c->sector_main)->toBe('numerique_telecoms')
        ->and($c->naf)->toBe('6201Z')
        ->and($c->naf_nomenclature)->toBe('naf_rev2')
        ->and($c->naf_rev2)->toBe('62.01Z');
});

it('maps NAF 4321A to btp', function () {
    $c = makeCompany(['naf' => '4321A']);
    $this->service->classify($c);
    $c->refresh();
    expect($c->sector_main)->toBe('btp');
});

it('lit un code de l ancienne nomenclature dans SA table (52.1D = commerce de détail)', function () {
    // L'ancien calcul lisait « 52 » comme la rév. 2 : transport.
    $c = makeCompany(['naf' => '52.1D']);
    $this->service->classify($c);
    $c->refresh();
    expect($c->sector_main)->toBe('commerce_detail')
        ->and($c->naf_nomenclature)->toBe('naf_rev1')
        ->and($c->naf_rev2)->toBe('47.11D');
});

it('range un code sans activité (00.00Z) en non classé', function () {
    $c = makeCompany(['naf' => '00.00Z']);
    $this->service->classify($c);
    $c->refresh();
    expect($c->sector_main)->toBe('non_classe');
});

it('ne rebascule plus le secteur posé par la collecte : même calcul, même résultat', function () {
    // Avant le 2026-09-28, la collecte rangeait 77.xx en `services_pro` et
    // l'enrichissement en `services_aux_entreprises`, à chaque passage.
    $c = makeCompany([
        'naf' => '78.20Z',
        'sector_main' => NomenclatureNaf::secteur('78.20Z'),
    ]);
    $this->service->classify($c);
    $c->refresh();
    expect($c->sector_main)->toBe('services_entreprises');
});

it('pose la nature entreprise sur une fiche INSEE qui n en a pas', function () {
    $insee = makeCompany(['discovery_source' => 'insee']);
    $site = makeCompany(['discovery_source' => 'site']);
    $this->service->classify($insee);
    $this->service->classify($site);

    expect($insee->refresh()->entity_nature)->toBe('entreprise')
        ->and($site->refresh()->entity_nature)->toBeNull();
});

it('extracts commune code and city name from signals.ban', function () {
    $c = makeCompany([
        'postcode' => '69003',
        'signals' => ['ban' => ['insee_commune' => '69383', 'city' => 'Lyon 3e Arrondissement']],
    ]);
    $this->service->classify($c);
    $c->refresh();
    expect($c->commune_code)->toBe('69383');
    expect($c->city_name)->toBe('Lyon 3e Arrondissement');
});

it('garde un secteur valide pose autrement (interprofessionnel) quand le code NAF ne dit rien', function () {
    $federation = makeCompany(['naf' => '94.11Z', 'sector_main' => 'interprofessionnel']);
    $ancien = makeCompany(['naf' => '94.11Z', 'sector_main' => 'associatif']);
    $this->service->classify($federation);
    $this->service->classify($ancien);

    expect($federation->refresh()->sector_main)->toBe('interprofessionnel')
        // Témoin : une valeur hors référentiel, elle, devient non classé.
        ->and($ancien->refresh()->sector_main)->toBe('non_classe');
});

it('remplace une chaîne vide par NULL au lieu de la garder', function () {
    $c = makeCompany(['size_category' => '', 'region_code' => '']);
    $this->service->classify($c);
    $c->refresh();

    expect($c->size_category)->toBeNull()
        ->and($c->region_code)->toBeNull();
});
