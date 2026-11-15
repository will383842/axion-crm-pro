<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class);

test('envoi-chercher : 10 par minute et par compte, et le commentaire le dit', function () {
    $limite = RateLimiter::limiter('envoi-chercher')(Request::create('/x', 'GET'));
    expect($limite->maxAttempts)->toBe(10);

    $src = (string) file_get_contents(app_path('Providers/RouteServiceProvider.php'));
    preg_match('~// R04 :[^\n]*~', $src, $m);
    expect($m[0] ?? '')->toContain('10 par minute')->not->toContain('30 par minute');
});
