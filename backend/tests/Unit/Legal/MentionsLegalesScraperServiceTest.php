<?php

use App\Models\Company;
use App\Services\Legal\MentionsLegalesScraperService;
use Illuminate\Support\Facades\Http;

it('extracts email and phone from mentions-legales page', function () {
    $body = str_repeat('Lorem ipsum dolor sit amet ', 50)
        . ' contact@acme.fr et 01 23 45 67 89 et autre texte';

    Http::fake([
        'acme.fr/mentions-legales' => Http::response($body, 200),
        '*' => Http::response('', 404),
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
    Http::fake([
        'foo.fr/mentions-legales' => Http::response($body, 200),
        '*' => Http::response('', 404),
    ]);

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
    // Le pool tape les 8 paths en parallèle : /contact 404, mais /contact.html
    // répond → l'email de secours est bien capturé. (Http::fake par URL car le
    // pool concurrent n'a pas d'ordre déterministe pour fakeSequence.)
    Http::fake([
        'bar.fr/contact' => Http::response('', 404),
        'bar.fr/contact.html' => Http::response(str_repeat('Lorem ipsum ', 100) . ' info@bar.fr', 200),
        '*' => Http::response('', 404),
    ]);

    $c = new Company(['website' => 'https://bar.fr', 'denomination' => 'Bar']);
    $c->id = 4;
    $c->workspace_id = '00000000-0000-0000-0000-000000000000';

    expect((new MentionsLegalesScraperService())->scrape($c))->toBeTrue();
    expect($c->email_generic)->toBe('info@bar.fr');
});

it('captures ALL emails and ALL phones (not just the first)', function () {
    $body = str_repeat('Lorem ipsum dolor ', 60)
        . ' Nos services : commercial@acme.fr, compta@acme.fr, contact@acme.fr '
        . ' Tel 01 23 45 67 89 ou 04.11.22.33.44 ou +33 6 12 34 56 78 ';

    Http::fake([
        'acme.fr/contact' => Http::response($body, 200),
        '*' => Http::response('', 404),
    ]);

    $c = new Company(['website' => 'https://acme.fr/', 'denomination' => 'Acme']);
    $c->id = 10;
    $c->workspace_id = '00000000-0000-0000-0000-000000000000';

    expect((new MentionsLegalesScraperService())->scrape($c))->toBeTrue();

    $channels = $c->signals['contact_channels'] ?? [];
    // Les 3 emails sont conservés (aucun perdu).
    expect($channels['emails'])->toContain('commercial@acme.fr')
        ->toContain('compta@acme.fr')
        ->toContain('contact@acme.fr');
    // Les 3 téléphones (national x2 + international normalisé) sont conservés.
    expect($channels['phones'])->toContain('0123456789')
        ->toContain('0411223344')
        ->toContain('0612345678');
});

it('deduces service roles and picks a service email as generic', function () {
    $body = str_repeat('Lorem ipsum ', 80) . ' rh@corp.fr et commercial@corp.fr ';
    Http::fake([
        'corp.fr/contact' => Http::response($body, 200),
        '*' => Http::response('', 404),
    ]);

    $c = new Company(['website' => 'https://corp.fr', 'denomination' => 'Corp']);
    $c->id = 11;
    $c->workspace_id = '00000000-0000-0000-0000-000000000000';

    expect((new MentionsLegalesScraperService())->scrape($c))->toBeTrue();
    // email_generic = une boîte service (le 1er accepté), pas vide.
    expect($c->email_generic)->not->toBeNull();
    expect($c->signals['contact_channels']['emails'])->toContain('rh@corp.fr')
        ->toContain('commercial@corp.fr');
});
