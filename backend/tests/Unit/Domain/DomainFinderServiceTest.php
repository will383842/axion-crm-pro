<?php

use App\Models\Company;
use App\Services\Domain\DomainFinderService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

it('returns signals.legal.siteweb when present', function () {
    Http::preventStrayRequests();
    $company = new Company(['denomination' => 'Acme SA', 'city_name' => 'Paris']);
    $company->signals = ['legal' => ['siteweb' => 'https://www.acme.fr/about']];

    $service = new DomainFinderService();
    $url = $service->find($company);

    expect($url)->toBe('https://acme.fr/');
});

it('returns first non-blacklist URL from Brave Search', function () {
    Config::set('services.brave.api_key', 'fake-brave-key');
    Http::fake([
        'api.search.brave.com/res/v1/web/search*' => Http::response([
            'web' => [
                'results' => [
                    ['url' => 'https://www.linkedin.com/company/foo'],
                    ['url' => 'https://www.target.fr/'],
                ],
            ],
        ], 200),
    ]);
    $company = new Company(['denomination' => 'Target SA', 'city_name' => 'Lyon']);

    $service = new DomainFinderService();
    expect($service->find($company))->toBe('https://target.fr/');
});

it('falls back to Pages Jaunes when MOCK_SCRAPERS=false and Brave empty', function () {
    Config::set('services.brave.api_key', null);
    Config::set('services.scrapers.mock', false);
    Http::fake([
        'www.pagesjaunes.fr/recherche/*' => Http::response(
            '<a class="company-website" href="https://www.realsite.fr/">site</a>',
            200
        ),
    ]);
    $company = new Company(['denomination' => 'RealCo', 'city_name' => 'Marseille']);

    $service = new DomainFinderService();
    expect($service->find($company))->toBe('https://realsite.fr/');
});

it('skips Pages Jaunes when MOCK_SCRAPERS=true', function () {
    Config::set('services.brave.api_key', null);
    Config::set('services.scrapers.mock', true);
    Http::preventStrayRequests();
    $company = new Company(['denomination' => 'GhostCo', 'city_name' => 'Lille']);

    $service = new DomainFinderService();
    expect($service->find($company))->toBeNull();
});

it('returns null when Brave key absent and PJ scrapers disabled', function () {
    Config::set('services.brave.api_key', null);
    Config::set('services.scrapers.mock', true);
    Http::preventStrayRequests();
    $company = new Company(['denomination' => 'GhostCo', 'city_name' => 'Lille']);

    $service = new DomainFinderService();
    expect($service->find($company))->toBeNull();
});

it('returns null when denomination missing', function () {
    $company = new Company([]);
    $service = new DomainFinderService();
    expect($service->find($company))->toBeNull();
});

/**
 * Helper : appelle la méthode privée candidateDomains($tokens, $extended).
 *
 * @param  list<string>  $tokens
 * @return list<string>
 */
function candidateDomains(array $tokens, bool $extended): array
{
    $ref = new ReflectionMethod(DomainFinderService::class, 'candidateDomains');
    $ref->setAccessible(true);

    return $ref->invoke(new DomainFinderService(), $tokens, $extended);
}

it('pass 1 candidates = 4 variantes prioritaires', function () {
    $c = candidateDomains(['cabinet', 'dupont'], false);

    expect($c)->toContain('cabinetdupont.fr')
        ->toContain('cabinet-dupont.fr')
        ->toContain('cabinetdupont.com')
        ->toContain('cabinet.fr')
        // pas de variantes étendues en pass 1
        ->not->toContain('cabinetdupont.net')
        ->not->toContain('cabinetdupont.eu');
});

it('pass 2 candidates ajoute TLD alternatifs, deux premiers mots et acronyme', function () {
    $c = candidateDomains(['cabinet', 'dupont', 'associes'], true);

    // pass 1 conservée
    expect($c)->toContain('cabinetdupontassocies.fr')
        // TLD alternatifs + hyphen.com + premier mot .com
        ->toContain('cabinetdupontassocies.net')
        ->toContain('cabinetdupontassocies.eu')
        ->toContain('cabinet-dupont-associes.com')
        ->toContain('cabinet.com')
        // deux premiers mots collés
        ->toContain('cabinetdupont.fr')
        ->toContain('cabinetdupont.com')
        // acronyme des initiales (≥3 tokens)
        ->toContain('cda.fr')
        ->toContain('cda.com');
});

it('acronyme non généré si moins de 3 tokens', function () {
    $c = candidateDomains(['alpha', 'beta'], true);

    // "ab" ferait 2 lettres < 3 → pas d'acronyme
    expect($c)->not->toContain('ab.fr')
        ->not->toContain('ab.com');
});
