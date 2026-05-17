<?php

use App\Services\Dedup\DeduplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('isOptedOut returns false when no opt_out entry', function () {
    $svc = new DeduplicationService();
    expect($svc->isOptedOut('clean@example.com'))->toBeFalse();
});

test('isOptedOut returns true after addOptOut', function () {
    $svc = new DeduplicationService();
    $svc->addOptOut('subject@example.com', null, 'gdpr_request');
    expect($svc->isOptedOut('subject@example.com'))->toBeTrue();
});

test('isOptedOut normalizes email (trim + lowercase)', function () {
    $svc = new DeduplicationService();
    $svc->addOptOut('Test@Example.com', null, 'manual');
    expect($svc->isOptedOut('  test@example.com  '))->toBeTrue();
});

test('isOptedOut normalizes phone (strip separators)', function () {
    $svc = new DeduplicationService();
    $svc->addOptOut(null, '+33 1 23 45 67 89', 'manual');
    expect($svc->isOptedOut(null, '0123456789'))->toBeFalse(); // FR variant
    expect($svc->isOptedOut(null, '+33 1.23.45.67.89'))->toBeTrue();
});

test('isOptedOut cross-workspace (table sans workspace_id)', function () {
    $svc = new DeduplicationService();
    $svc->addOptOut('blocked@example.com', null, 'rgpd_erasure');
    // Vérifie que la table opt_out est globale (pas scoped)
    expect(DB::table('opt_out')->where('email', 'blocked@example.com')->exists())->toBeTrue();
});
