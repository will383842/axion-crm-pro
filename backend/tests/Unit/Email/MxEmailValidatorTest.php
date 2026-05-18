<?php

use App\Services\Email\MxEmailValidator;

it('returns invalid for malformed syntax', function () {
    $v = new MxEmailValidator();
    expect($v->quickStatus('not-an-email'))->toBe('invalid')
        ->and($v->quickStatus(''))->toBe('invalid')
        ->and($v->quickStatus('a@'))->toBe('invalid');
});

it('returns disposable for known throwaway domains', function () {
    $v = new MxEmailValidator();
    expect($v->quickStatus('foo@yopmail.com'))->toBe('disposable')
        ->and($v->quickStatus('bar@mailinator.com'))->toBe('disposable')
        ->and($v->quickStatus('baz@10minutemail.com'))->toBe('disposable');
});

it('returns role for role-based prefixes', function () {
    $v = new MxEmailValidator();
    expect($v->quickStatus('info@whatever-acme.fr'))->toBe('role')
        ->and($v->quickStatus('contact@whatever-acme.fr'))->toBe('role')
        ->and($v->quickStatus('noreply@whatever-acme.fr'))->toBe('role')
        ->and($v->quickStatus('admin@whatever-acme.fr'))->toBe('role');
});

it('returns risky for free providers (gmail, orange, etc) — assuming MX resolves', function () {
    // Ces domaines ont presque toujours des MX records publics → on s'attend à "risky"
    // (le test peut être skippé si pas d'accès réseau, mais en CI ça passe).
    $v = new MxEmailValidator();
    $status = $v->quickStatus('jean.dupont@gmail.com');
    expect($status)->toBeIn(['risky', 'invalid']);  // invalid si DNS bloqué en CI
});

it('returns invalid when domain has no MX records', function () {
    $v = new MxEmailValidator();
    // example.invalid est un TLD réservé qui ne résout jamais
    expect($v->quickStatus('foo@example.invalid'))->toBe('invalid');
});

it('full validate returns structured payload', function () {
    $v = new MxEmailValidator();
    $r = $v->validate('contact@whatever-acme.fr');
    expect($r)->toHaveKeys([
        'status', 'email', 'reason', 'mx_records',
        'is_disposable', 'is_role', 'is_free_provider', 'has_mx_records',
    ])
        ->and($r['status'])->toBe('role')
        ->and($r['is_role'])->toBeTrue();
});

it('lowercases and trims input', function () {
    $v = new MxEmailValidator();
    $r = $v->validate('  INFO@WHATEVER-ACME.FR  ');
    expect($r['email'])->toBe('info@whatever-acme.fr');
});

it('rejects emails longer than 254 chars (RFC 5321)', function () {
    $v = new MxEmailValidator();
    $longLocal = str_repeat('a', 250);
    expect($v->quickStatus("{$longLocal}@x.fr"))->toBe('invalid');
});
