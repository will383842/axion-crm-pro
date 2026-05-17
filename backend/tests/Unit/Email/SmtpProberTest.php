<?php

use App\Services\Smtp\Mocks\MockSmtpProber;

beforeEach(function () {
    if (! is_dir(base_path('tests/fixtures/smtp'))) {
        mkdir(base_path('tests/fixtures/smtp'), 0755, true);
    }
});

test('mock prober returns valid for known email', function () {
    $prober = new MockSmtpProber();
    $r = $prober->probe('marie.dupont@example.com');
    expect($r->email)->toBe('marie.dupont@example.com');
    expect($r->score)->toBeGreaterThan(0);
});

test('mock prober detects role email', function () {
    $prober = new MockSmtpProber();
    $r = $prober->probe('contact@example.com');
    expect($r->isRole)->toBeTrue();
    expect($r->status)->toBe('role');
});

test('mock prober detects disposable domain', function () {
    $prober = new MockSmtpProber();
    $r = $prober->probe('test@mailinator.com');
    expect($r->isDisposable)->toBeTrue();
    expect($r->score)->toBe(0);
});

test('mock prober defaults to valid for unknown email in fixture', function () {
    $prober = new MockSmtpProber();
    $r = $prober->probe('random@unknown-domain.com');
    expect($r->email)->toBe('random@unknown-domain.com');
});

test('email normalization (trim + lowercase)', function () {
    $prober = new MockSmtpProber();
    $r = $prober->probe('  MARIE.Dupont@EXAMPLE.COM  ');
    expect($r->email)->toBe('marie.dupont@example.com');
});
