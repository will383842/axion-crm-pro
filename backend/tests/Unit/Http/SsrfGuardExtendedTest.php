<?php

use App\Services\Http\SsrfGuard;

test('SsrfGuard rejette URL invalide', function () {
    $r = SsrfGuard::check('not-a-url');
    expect($r['ok'])->toBeFalse();
    expect($r['reason'])->toBe('invalid_url');
});

test('SsrfGuard rejette scheme non-http(s)', function () {
    $r = SsrfGuard::check('file:///etc/passwd');
    expect($r['ok'])->toBeFalse();
});

test('SsrfGuard rejette ftp', function () {
    $r = SsrfGuard::check('ftp://example.com/file');
    expect($r['ok'])->toBeFalse();
});

test('SsrfGuard rejette URL vide', function () {
    $r = SsrfGuard::check('');
    expect($r['ok'])->toBeFalse();
});

test('SsrfGuard rejette metadata AWS', function () {
    $r = SsrfGuard::check('http://169.254.169.254/latest/meta-data/');
    expect($r['ok'])->toBeFalse();
});

test('SsrfGuard rejette metadata GCP', function () {
    $r = SsrfGuard::check('http://metadata.google.internal/');
    expect($r['ok'])->toBeFalse();
});

test('SsrfGuard rejette metadata Azure', function () {
    $r = SsrfGuard::check('http://metadata.azure.com/');
    expect($r['ok'])->toBeFalse();
});

test('SsrfGuard rejette metadata Alibaba', function () {
    $r = SsrfGuard::check('http://100.100.100.200/latest/meta-data/');
    expect($r['ok'])->toBeFalse();
});

test('SsrfGuard rejette localhost', function () {
    $r = SsrfGuard::check('http://localhost/admin');
    expect($r['ok'])->toBeFalse();
});

test('SsrfGuard rejette 127.0.0.1', function () {
    $r = SsrfGuard::check('http://127.0.0.1/');
    expect($r['ok'])->toBeFalse();
});

test('SsrfGuard rejette 0.0.0.0', function () {
    $r = SsrfGuard::check('http://0.0.0.0/');
    expect($r['ok'])->toBeFalse();
});

test('SsrfGuard rejette 10.0.0.0/8', function () {
    $r = SsrfGuard::check('http://10.5.5.5/');
    expect($r['ok'])->toBeFalse();
});

test('SsrfGuard rejette 192.168.0.0/16', function () {
    $r = SsrfGuard::check('http://192.168.1.1/');
    expect($r['ok'])->toBeFalse();
});

test('SsrfGuard rejette 172.16-31', function () {
    $r = SsrfGuard::check('http://172.20.10.5/');
    expect($r['ok'])->toBeFalse();
});

test('SsrfGuard rejette 169.254.x.x link-local', function () {
    $r = SsrfGuard::check('http://169.254.0.5/');
    expect($r['ok'])->toBeFalse();
});

test('SsrfGuard ensure() lance exception si denied', function () {
    expect(fn () => SsrfGuard::ensure('http://localhost/'))
        ->toThrow(RuntimeException::class);
});
