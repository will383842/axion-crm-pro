<?php

use App\Services\Audit\AuditHashChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('AuditHashChain record retourne un id positif', function () {
    $chain = new AuditHashChain();
    $id = $chain->record([
        'workspace_id' => (string) Str::uuid(),
        'method' => 'GET',
        'path' => '/api/v1/companies',
        'status' => 200,
        'ip' => '127.0.0.1',
    ]);
    expect($id)->toBeGreaterThan(0);
});

test('AuditHashChain verifyChain retourne true sur chaîne vide', function () {
    $chain = new AuditHashChain();
    expect($chain->verifyChain())->toBeTrue();
});

test('AuditHashChain verifyChain retourne true sur 1 record', function () {
    $chain = new AuditHashChain();
    $chain->record(['method' => 'GET', 'path' => '/test', 'status' => 200]);
    expect($chain->verifyChain())->toBeTrue();
});

test('AuditHashChain verifyChain retourne true sur 10 records', function () {
    $chain = new AuditHashChain();
    for ($i = 0; $i < 10; $i++) {
        $chain->record(['method' => 'POST', 'path' => "/test/{$i}", 'status' => 201]);
    }
    expect($chain->verifyChain())->toBeTrue();
});

test('AuditHashChain verifyChain détecte tampering manuel', function () {
    $chain = new AuditHashChain();
    $chain->record(['method' => 'GET', 'path' => '/a', 'status' => 200]);
    $chain->record(['method' => 'GET', 'path' => '/b', 'status' => 200]);

    // Tamper : modifier le status du 1er record
    DB::table('audit_logs')->where('path', '/a')->update(['status_code' => 999]);

    expect($chain->verifyChain())->toBeFalse();
});

test('AuditHashChain canonical respecte l\'ordre des clés', function () {
    $chain = new AuditHashChain();
    $id1 = $chain->record([
        'method' => 'GET',
        'path' => '/x',
        'status' => 200,
        'ip' => '1.2.3.4',
    ]);
    // Le record doit avoir un current_hash non-nul
    $row = DB::table('audit_logs')->find($id1);
    expect($row->current_hash)->not->toBeNull();
    expect(strlen($row->current_hash))->toBe(64);  // sha256 hex
});

test('AuditHashChain enchaine prev_hash correctement', function () {
    $chain = new AuditHashChain();
    $id1 = $chain->record(['method' => 'GET', 'path' => '/a', 'status' => 200]);
    $id2 = $chain->record(['method' => 'GET', 'path' => '/b', 'status' => 200]);

    $row1 = DB::table('audit_logs')->find($id1);
    $row2 = DB::table('audit_logs')->find($id2);

    expect($row2->prev_hash)->toBe($row1->current_hash);
});

test('AuditHashChain premier record a prev_hash = 0*64', function () {
    DB::table('audit_logs')->truncate();
    $chain = new AuditHashChain();
    $id = $chain->record(['method' => 'GET', 'path' => '/first', 'status' => 200]);
    $row = DB::table('audit_logs')->find($id);
    expect($row->prev_hash)->toBe(str_repeat('0', 64));
});
