<?php

use App\Services\Audit\AuditHashChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('first record uses GENESIS prev hash', function () {
    $chain = new AuditHashChain();
    $id = $chain->record([
        'workspace_id' => null,
        'user_id'      => null,
        'method'       => 'GET',
        'path'         => '/api/v1/test',
        'status'       => 200,
        'ip'           => '127.0.0.1',
        'user_agent'   => 'test',
        'payload_hash' => hash('sha256', 'payload'),
    ]);
    expect($id)->toBeGreaterThan(0);

    $row = DB::table('audit_logs')->where('id', $id)->first();
    expect($row->prev_hash)->toBe('GENESIS');
    expect($row->current_hash)->toHaveLength(64);
});

test('subsequent record chains to previous hash', function () {
    $chain = new AuditHashChain();
    $a = $chain->record(['method' => 'POST', 'path' => '/a', 'status' => 200, 'ip' => '127.0.0.1', 'payload_hash' => 'x']);
    $b = $chain->record(['method' => 'POST', 'path' => '/b', 'status' => 200, 'ip' => '127.0.0.1', 'payload_hash' => 'y']);

    $rowA = DB::table('audit_logs')->where('id', $a)->first();
    $rowB = DB::table('audit_logs')->where('id', $b)->first();
    expect($rowB->prev_hash)->toBe($rowA->current_hash);
});

test('verifyChain returns true for untampered chain', function () {
    $chain = new AuditHashChain();
    for ($i = 0; $i < 10; $i++) {
        $chain->record(['method' => 'POST', 'path' => "/test/{$i}", 'status' => 200, 'ip' => '127.0.0.1', 'payload_hash' => "h{$i}"]);
    }
    expect($chain->verifyChain())->toBeTrue();
});

test('verifyChain detects tampering', function () {
    $chain = new AuditHashChain();
    $chain->record(['method' => 'POST', 'path' => '/x', 'status' => 200, 'ip' => '127.0.0.1', 'payload_hash' => 'x']);
    $id = $chain->record(['method' => 'POST', 'path' => '/y', 'status' => 200, 'ip' => '127.0.0.1', 'payload_hash' => 'y']);

    // Tamper : modifie le path d'une ligne sans recalculer le hash.
    DB::table('audit_logs')->where('id', $id)->update(['path' => '/TAMPERED']);

    expect($chain->verifyChain())->toBeFalse();
});
