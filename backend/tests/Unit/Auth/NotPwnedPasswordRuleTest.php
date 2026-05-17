<?php

use App\Rules\NotPwnedPassword;
use App\Services\Auth\HibpChecker;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

function makeHibpChecker(string $bodyResponse): HibpChecker
{
    $mock = new MockHandler([new Response(200, [], $bodyResponse)]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);
    return new HibpChecker($client);
}

test('NotPwnedPassword laisse passer si password non breached', function () {
    $checker = makeHibpChecker("DIFFERENTSUFFIX:1\r\n");
    $rule = new NotPwnedPassword(5, $checker);

    $failed = false;
    $rule->validate('password', 'SomeGoodPassword!42', function () use (&$failed) {
        $failed = true;
    });
    expect($failed)->toBeFalse();
});

test('NotPwnedPassword bloque si count > threshold', function () {
    // sha1 'password' suffix
    $checker = makeHibpChecker("1E4C9B93F3F0682250B6CF8331B7EE68FD8:100\r\n");
    $rule = new NotPwnedPassword(5, $checker);

    $message = null;
    $rule->validate('password', 'password', function (string $m) use (&$message) {
        $message = $m;
    });
    expect($message)->not->toBeNull();
    expect($message)->toContain('100');
});

test('NotPwnedPassword laisse passer si count = threshold', function () {
    $checker = makeHibpChecker("1E4C9B93F3F0682250B6CF8331B7EE68FD8:5\r\n");
    $rule = new NotPwnedPassword(5, $checker);

    $failed = false;
    $rule->validate('password', 'password', function () use (&$failed) {
        $failed = true;
    });
    expect($failed)->toBeFalse();
});

test('NotPwnedPassword threshold custom respecté', function () {
    $checker = makeHibpChecker("1E4C9B93F3F0682250B6CF8331B7EE68FD8:50\r\n");
    $rule = new NotPwnedPassword(100, $checker);  // threshold haut

    $failed = false;
    $rule->validate('password', 'password', function () use (&$failed) {
        $failed = true;
    });
    expect($failed)->toBeFalse();
});

test('NotPwnedPassword ignore les types non-string', function () {
    $rule = new NotPwnedPassword();
    $failed = false;
    $rule->validate('password', 12345, function () use (&$failed) {
        $failed = true;
    });
    expect($failed)->toBeFalse();
});

test('NotPwnedPassword ignore les strings vides', function () {
    $rule = new NotPwnedPassword();
    $failed = false;
    $rule->validate('password', '', function () use (&$failed) {
        $failed = true;
    });
    expect($failed)->toBeFalse();
});
