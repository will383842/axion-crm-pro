<?php

use App\Services\Auth\HibpChecker;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

test('HibpChecker returns 0 quand suffix absent', function () {
    // sha1('CorrectHorseBatteryStaple') = 'BB7DF04E1B0A2570657527A7E108AE23EB6E7EF7'
    // → prefix 'BB7DF', suffix '04E1B0A2570657527A7E108AE23EB6E7EF7'
    $body = "DEADBEEF1234567890ABCDEF0123456789ABCDEF:1\r\nANOTHERSUFFIX1234567890ABCDEF0123456789AB:42\r\n";

    $mock = new MockHandler([new Response(200, [], $body)]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $checker = new HibpChecker($client);

    expect($checker->getBreachCount('CorrectHorseBatteryStaple'))->toBe(0);
});

test('HibpChecker retourne le count si suffix trouvé', function () {
    // password = 'password' → sha1 = '5BAA61E4C9B93F3F0682250B6CF8331B7EE68FD8'
    // prefix '5BAA6', suffix '1E4C9B93F3F0682250B6CF8331B7EE68FD8'
    $body = "1E4C9B93F3F0682250B6CF8331B7EE68FD8:9659365\r\nOTHERSUFFIX:1\r\n";

    $mock = new MockHandler([new Response(200, [], $body)]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $checker = new HibpChecker($client);

    expect($checker->getBreachCount('password'))->toBe(9659365);
});

test('HibpChecker isBreached respecte le threshold', function () {
    $body = "1E4C9B93F3F0682250B6CF8331B7EE68FD8:10\r\n";

    $mock = new MockHandler([
        new Response(200, [], $body),
        new Response(200, [], $body),
    ]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $checker = new HibpChecker($client);

    expect($checker->isBreached('password', threshold: 5))->toBeTrue();
    expect($checker->isBreached('password', threshold: 20))->toBeFalse();
});

test('HibpChecker fail-open en cas d\'erreur réseau', function () {
    $mock = new MockHandler([
        new \GuzzleHttp\Exception\ConnectException(
            'connect timeout',
            new \GuzzleHttp\Psr7\Request('GET', 'https://api.pwnedpasswords.com/range/12345')
        ),
    ]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $checker = new HibpChecker($client);

    // Pas d'exception, retourne 0 (fail-open)
    expect($checker->getBreachCount('anything'))->toBe(0);
});

test('HibpChecker fail-open sur status non-200', function () {
    $mock = new MockHandler([new Response(503, [], 'service unavailable')]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $checker = new HibpChecker($client);

    expect($checker->getBreachCount('anything'))->toBe(0);
});

test('HibpChecker retourne 0 pour password vide sans appel API', function () {
    $mock = new MockHandler([]);  // any call would throw
    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $checker = new HibpChecker($client);

    expect($checker->getBreachCount(''))->toBe(0);
});

test('HibpChecker met en cache le résultat 24h', function () {
    $body = "1E4C9B93F3F0682250B6CF8331B7EE68FD8:42\r\n";

    // Une seule réponse mockée → 2nd appel doit hit le cache
    $mock = new MockHandler([new Response(200, [], $body)]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $checker = new HibpChecker($client);

    expect($checker->getBreachCount('password'))->toBe(42);
    // Si le cache fonctionne, 2nd call ne fait pas d'appel et retourne le même résultat
    expect($checker->getBreachCount('password'))->toBe(42);
});

test('HibpChecker parse correctement les newlines mixtes', function () {
    // HIBP renvoie parfois \r\n, parfois \n
    $body = "1E4C9B93F3F0682250B6CF8331B7EE68FD8:5\nOTHERSUFFIX:1\n";

    $mock = new MockHandler([new Response(200, [], $body)]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $checker = new HibpChecker($client);

    expect($checker->getBreachCount('password'))->toBe(5);
});
