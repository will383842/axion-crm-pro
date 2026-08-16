<?php

use App\Services\Email\HunterEmailVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
});

it('returns unknown when API key is missing', function () {
    Config::set('services.hunter.api_key', null);
    $verifier = new HunterEmailVerifier;
    $result = $verifier->verify('test@example.com');

    expect($result['status'])->toBe('unknown')
        ->and($result['reason'])->toBe('no_api_key');
});

it('returns deliverable result when Hunter returns deliverable', function () {
    Config::set('services.hunter.api_key', 'fake-key');
    Http::fake([
        'api.hunter.io/v2/email-verifier*' => Http::response([
            'data' => [
                'status' => 'deliverable',
                'score' => 95,
                'mx_records' => true,
                'smtp_check' => true,
                'webmail' => false,
                'disposable' => false,
            ],
        ], 200),
    ]);

    $verifier = new HunterEmailVerifier;
    $result = $verifier->verify('hi@acme.fr');

    expect($result['status'])->toBe('deliverable')
        ->and($result['score'])->toBe(95)
        ->and($result['mx_records'])->toBeTrue()
        ->and($result['smtp_check'])->toBeTrue();
});

it('returns unknown with http_error when API responds 5xx', function () {
    Config::set('services.hunter.api_key', 'fake-key');
    Http::fake([
        'api.hunter.io/v2/email-verifier*' => Http::response([], 503),
    ]);

    $verifier = new HunterEmailVerifier;
    $result = $verifier->verify('hi@acme.fr');

    expect($result['status'])->toBe('unknown')
        ->and($result['reason'])->toBe('http_error');
});

it('does NOT cache a transport failure — un 5xx doit rester réessayable', function () {
    // Corollaire du test précédent : classer le 5xx en `http_error` ne sert à rien
    // s'il est mémorisé 30 jours comme un verdict. Une panne Hunter de 5 minutes
    // gèlerait alors la vérification de cet email pendant un mois.
    Config::set('services.hunter.api_key', 'fake-key');
    Http::fake([
        'api.hunter.io/v2/email-verifier*' => Http::sequence()
            ->push([], 503)
            ->push(['data' => ['status' => 'deliverable', 'score' => 91]], 200),
    ]);

    $verifier = new HunterEmailVerifier;
    expect($verifier->verify('hi@acme.fr')['reason'])->toBe('http_error');
    // 2e appel : l'échec n'a pas été mis en cache → Hunter est bien réinterrogé.
    expect($verifier->verify('hi@acme.fr')['status'])->toBe('deliverable');
    Http::assertSentCount(2);
});

it('caches result for 30 days to avoid quota waste', function () {
    Config::set('services.hunter.api_key', 'fake-key');
    Http::fake([
        'api.hunter.io/v2/email-verifier*' => Http::response([
            'data' => ['status' => 'undeliverable', 'score' => 0],
        ], 200),
    ]);

    $verifier = new HunterEmailVerifier;
    $first = $verifier->verify('hi@acme.fr');
    $second = $verifier->verify('hi@acme.fr');

    expect($first)->toBe($second);
    Http::assertSentCount(1);
});
