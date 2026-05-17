<?php

use App\Models\Company;
use App\Services\Legal\MentionsLegalesScraperService;
use Illuminate\Support\Facades\Http;

it('extracts email and phone from mentions-legales page', function () {
    $body = str_repeat('Lorem ipsum dolor sit amet ', 50)
        . ' contact@acme.fr et 01 23 45 67 89 et autre texte';

    Http::fake([
        'acme.fr/mentions-legales' => Http::response($body, 200),
    ]);

    $c = new Company(['website' => 'https://acme.fr/', 'denomination' => 'Acme']);
    $c->id = 1;
    $c->workspace_id = '00000000-0000-0000-0000-000000000000';

    $service = new MentionsLegalesScraperService();
    $result = $service->scrape($c);

    expect($result)->toBeTrue();
    expect($c->email_generic)->toBe('contact@acme.fr');
    expect($c->phone)->toBe('0123456789');
});

it('skips technical email prefixes', function () {
    $body = str_repeat('Lorem ipsum ', 100)
        . ' no-reply@foo.fr et hello@foo.fr';
    Http::fake(['foo.fr/mentions-legales' => Http::response($body, 200)]);

    $c = new Company(['website' => 'https://foo.fr', 'denomination' => 'Foo']);
    $c->id = 2;
    $c->workspace_id = '00000000-0000-0000-0000-000000000000';

    $service = new MentionsLegalesScraperService();
    expect($service->scrape($c))->toBeTrue();
    expect($c->email_generic)->toBe('hello@foo.fr');
});

it('returns false when body too short on all paths', function () {
    Http::fake(['*' => Http::response('short', 200)]);
    $c = new Company(['website' => 'https://tiny.fr', 'denomination' => 'Tiny']);
    $c->id = 3;
    $c->workspace_id = '00000000-0000-0000-0000-000000000000';

    expect((new MentionsLegalesScraperService())->scrape($c))->toBeFalse();
});

it('returns false when website missing', function () {
    $c = new Company(['denomination' => 'NoSite']);
    expect((new MentionsLegalesScraperService())->scrape($c))->toBeFalse();
});

it('tries fallback path when first 404', function () {
    Http::fakeSequence()
        ->push('', 404)
        ->push(str_repeat('Lorem ipsum ', 100) . ' info@bar.fr', 200);

    $c = new Company(['website' => 'https://bar.fr', 'denomination' => 'Bar']);
    $c->id = 4;
    $c->workspace_id = '00000000-0000-0000-0000-000000000000';

    expect((new MentionsLegalesScraperService())->scrape($c))->toBeTrue();
    expect($c->email_generic)->toBe('info@bar.fr');
});
