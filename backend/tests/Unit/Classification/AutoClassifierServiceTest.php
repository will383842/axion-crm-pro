<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Workspace;
use App\Services\Classification\AutoClassifierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->workspace = Workspace::create([
        'id'   => Str::uuid()->toString(),
        'name' => 'Test WS',
        'slug' => 'test-ws-' . uniqid(),
    ]);
    $this->service = new AutoClassifierService();
});

function makeCompany(array $attrs): Company
{
    return Company::create(array_merge([
        'workspace_id' => test()->workspace->id,
        'siren'        => (string) random_int(100000000, 999999999),
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

it('classifies effectif_range 52 as grande entreprise', function () {
    $c = makeCompany(['effectif_range' => '52']);
    $this->service->classify($c);
    $c->refresh();
    expect($c->size_category)->toBe('grande');
});

it('maps NAF 6201Z to it_saas', function () {
    $c = makeCompany(['naf' => '6201Z']);
    $this->service->classify($c);
    $c->refresh();
    expect($c->sector_main)->toBe('it_saas');
});

it('maps NAF 4321A to btp', function () {
    $c = makeCompany(['naf' => '4321A']);
    $this->service->classify($c);
    $c->refresh();
    expect($c->sector_main)->toBe('btp');
});

it('falls back to "autre" for unknown NAF prefix', function () {
    $c = makeCompany(['naf' => '9999Z']);
    $this->service->classify($c);
    $c->refresh();
    expect($c->sector_main)->toBe('autre');
});

it('extracts commune code and city name from signals.ban', function () {
    $c = makeCompany([
        'postcode' => '69003',
        'signals'  => ['ban' => ['insee_commune' => '69383', 'city' => 'Lyon 3e Arrondissement']],
    ]);
    $this->service->classify($c);
    $c->refresh();
    expect($c->commune_code)->toBe('69383');
    expect($c->city_name)->toBe('Lyon 3e Arrondissement');
});
