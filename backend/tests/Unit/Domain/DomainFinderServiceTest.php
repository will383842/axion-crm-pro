<?php

use App\Models\Company;
use App\Services\Domain\DomainFinderService;
use Illuminate\Support\Facades\Http;

it('returns signals.legal.siteweb when present', function () {
    Http::preventStrayRequests();
    $company = new Company(['denomination' => 'Acme SA', 'city_name' => 'Paris']);
    $company->signals = ['legal' => ['siteweb' => 'https://www.acme.fr/about']];

    $service = new DomainFinderService();
    $url = $service->find($company);

    expect($url)->toBe('https://acme.fr/');
});

it('returns DuckDuckGo first non-blacklist URL', function () {
    Http::fake([
        'html.duckduckgo.com/html/*' => Http::response(
            '<a class="result__url" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fwww.target.fr%2F">target</a>',
            200
        ),
    ]);
    $company = new Company(['denomination' => 'Target SA', 'city_name' => 'Lyon']);

    $service = new DomainFinderService();
    expect($service->find($company))->toBe('https://target.fr/');
});

it('skips blacklist hosts and falls back to Pages Jaunes', function () {
    Http::fake([
        'html.duckduckgo.com/html/*' => Http::response(
            '<a class="result__url" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fwww.linkedin.com%2Fcompany%2Ffoo">li</a>',
            200
        ),
        'www.pagesjaunes.fr/recherche/*' => Http::response(
            '<a class="company-website" href="https://www.realsite.fr/">site</a>',
            200
        ),
    ]);
    $company = new Company(['denomination' => 'RealCo', 'city_name' => 'Marseille']);

    $service = new DomainFinderService();
    expect($service->find($company))->toBe('https://realsite.fr/');
});

it('returns null when no source matches', function () {
    Http::fake([
        '*' => Http::response('', 200),
    ]);
    $company = new Company(['denomination' => 'GhostCo', 'city_name' => 'Lille']);

    $service = new DomainFinderService();
    expect($service->find($company))->toBeNull();
});

it('returns null when denomination missing', function () {
    $company = new Company([]);
    $service = new DomainFinderService();
    expect($service->find($company))->toBeNull();
});
