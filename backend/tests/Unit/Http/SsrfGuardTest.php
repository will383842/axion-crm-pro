<?php

use App\Services\Http\SsrfGuard;

test('blocks AWS metadata endpoint', function () {
    $r = SsrfGuard::check('http://169.254.169.254/latest/meta-data/');
    expect($r['ok'])->toBeFalse();
    // L'IP metadata AWS figure aussi dans DENY_HOSTS, évaluée AVANT les plages
    // CIDR : le motif rendu est donc `deny_host:…` et non plus `deny_cidr:…`.
    // La liste de noms est un raccourci qui S'AJOUTE au filtre CIDR — elle ne le
    // remplace pas ; le test suivant verrouille ce point (une liste de noms se
    // contourne, un CIDR non).
    expect($r['reason'])->toBe('deny_host:169.254.169.254');
});

test('blocks the whole link-local range 169.254/16 by CIDR (not by hostname list)', function () {
    // 169.254.1.1 n'est PAS dans DENY_HOSTS : seule la règle CIDR 169.254.0.0/16
    // peut le bloquer. Ce test rougit si quelqu'un remplace la plage par des
    // entrées nominatives — un contournement trivial (169.254.169.254 a des
    // milliers d'alias décimaux/octaux et de rebinding DNS possibles).
    $r = SsrfGuard::check('http://169.254.1.1/latest/meta-data/');
    expect($r['ok'])->toBeFalse();
    expect($r['reason'])->toStartWith('deny_cidr');
});

test('blocks GCP metadata hostname', function () {
    $r = SsrfGuard::check('http://metadata.google.internal/');
    expect($r['ok'])->toBeFalse();
});

test('blocks localhost', function () {
    $r = SsrfGuard::check('http://localhost:8080/admin');
    expect($r['ok'])->toBeFalse();
});

test('blocks 127.0.0.1', function () {
    $r = SsrfGuard::check('http://127.0.0.1/');
    expect($r['ok'])->toBeFalse();
});

test('blocks RFC 1918 192.168/16', function () {
    $r = SsrfGuard::check('http://192.168.1.1/');
    expect($r['ok'])->toBeFalse();
});

test('blocks RFC 1918 10/8', function () {
    $r = SsrfGuard::check('http://10.0.0.1/');
    expect($r['ok'])->toBeFalse();
});

test('blocks RFC 1918 172.16/12', function () {
    $r = SsrfGuard::check('http://172.20.5.1/');
    expect($r['ok'])->toBeFalse();
});

test('blocks CGNAT 100.64/10', function () {
    $r = SsrfGuard::check('http://100.64.1.1/');
    expect($r['ok'])->toBeFalse();
});

test('blocks ftp scheme', function () {
    $r = SsrfGuard::check('ftp://example.com/file');
    expect($r['ok'])->toBeFalse();
    expect($r['reason'])->toBe('invalid_url');
});

test('blocks file scheme', function () {
    $r = SsrfGuard::check('file:///etc/passwd');
    expect($r['ok'])->toBeFalse();
});

test('ensure throws when blocked', function () {
    expect(fn () => SsrfGuard::ensure('http://169.254.169.254/'))
        ->toThrow(RuntimeException::class, 'SSRF guard');
});

test('invalid URL is rejected', function () {
    $r = SsrfGuard::check('not-a-url');
    expect($r['ok'])->toBeFalse();
});
