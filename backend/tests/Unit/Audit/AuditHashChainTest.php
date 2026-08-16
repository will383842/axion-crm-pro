<?php

use App\Services\Audit\AuditHashChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Sentinelle du maillon zéro : 64 zéros hex, PAS la chaîne 'GENESIS'.
// Ce test attendait 'GENESIS' (le DEFAULT SQL de la colonne `prev_hash`), mais
// ce DEFAULT est inatteignable : `AuditHashChain::record()` fournit toujours
// `prev_hash` explicitement, et `verifyChain()` amorce la chaîne sur 64 zéros.
// Les deux sites du service sont d'accord entre eux depuis le premier commit ;
// c'était l'attente du test qui était fausse (et le DEFAULT SQL qui est mort).
// On garde 64 zéros : même largeur qu'un SHA-256, donc « prev_hash est toujours
// un digest 64-hex » reste vrai — et les hashs déjà en base restent vérifiables.
test('first record uses the 64-zero genesis prev hash', function () {
    $chain = new AuditHashChain;
    $id = $chain->record([
        'workspace_id' => null,
        'user_id' => null,
        'method' => 'GET',
        'path' => '/api/v1/test',
        'status' => 200,
        'ip' => '127.0.0.1',
        'user_agent' => 'test',
        'payload_hash' => hash('sha256', 'payload'),
    ]);
    expect($id)->toBeGreaterThan(0);

    $row = DB::table('audit_logs')->where('id', $id)->first();
    expect($row->prev_hash)->toBe(AuditHashChain::GENESIS_PREV_HASH);
    expect($row->prev_hash)->toBe(str_repeat('0', 64));
    expect($row->current_hash)->toHaveLength(64);
});

test('subsequent record chains to previous hash', function () {
    $chain = new AuditHashChain;
    $a = $chain->record(['method' => 'POST', 'path' => '/a', 'status' => 200, 'ip' => '127.0.0.1', 'payload_hash' => 'x']);
    $b = $chain->record(['method' => 'POST', 'path' => '/b', 'status' => 200, 'ip' => '127.0.0.1', 'payload_hash' => 'y']);

    $rowA = DB::table('audit_logs')->where('id', $a)->first();
    $rowB = DB::table('audit_logs')->where('id', $b)->first();
    expect($rowB->prev_hash)->toBe($rowA->current_hash);
});

test('verifyChain returns true for untampered chain', function () {
    $chain = new AuditHashChain;
    for ($i = 0; $i < 10; $i++) {
        $chain->record(['method' => 'POST', 'path' => "/test/{$i}", 'status' => 200, 'ip' => '127.0.0.1', 'payload_hash' => "h{$i}"]);
    }
    expect($chain->verifyChain())->toBeTrue();
});

test('verifyChain detects tampering', function () {
    $chain = new AuditHashChain;
    $chain->record(['method' => 'POST', 'path' => '/x', 'status' => 200, 'ip' => '127.0.0.1', 'payload_hash' => 'x']);
    $id = $chain->record(['method' => 'POST', 'path' => '/y', 'status' => 200, 'ip' => '127.0.0.1', 'payload_hash' => 'y']);

    // Tamper : modifie le path d'une ligne sans recalculer le hash.
    DB::table('audit_logs')->where('id', $id)->update(['path' => '/TAMPERED']);

    expect($chain->verifyChain())->toBeFalse();
});

// Garde de non-régression du défaut réparé le 2026-08-16 : `record()` hashait la
// forme APPELANT (clé `method`) tandis que `verifyChain()` relisait la forme
// COLONNE (`event_type`). Résultat : le verbe n'entrait pas dans le hash relu et
// `verifyChain()` rendait false sur toute chaîne intacte. Aucun test existant ne
// falsifiait `event_type` — une réparation qui se contenterait de retirer le
// verbe du hash passait donc tous les tests. Celui-ci l'interdit.
test('verifyChain detects tampering on event_type (the HTTP verb)', function () {
    $chain = new AuditHashChain;
    $chain->record(['method' => 'POST', 'path' => '/x', 'status' => 200, 'ip' => '127.0.0.1', 'payload_hash' => 'x']);
    $id = $chain->record(['method' => 'DELETE', 'path' => '/y', 'status' => 200, 'ip' => '127.0.0.1', 'payload_hash' => 'y']);

    // Un DELETE maquillé en GET : le cas d'effacement de traces par excellence.
    DB::table('audit_logs')->where('id', $id)->update(['event_type' => 'GET']);

    expect($chain->verifyChain())->toBeFalse();
});

test('verifyChain detects a rewritten prev_hash link', function () {
    $chain = new AuditHashChain;
    $chain->record(['method' => 'POST', 'path' => '/x', 'status' => 200, 'ip' => '127.0.0.1', 'payload_hash' => 'x']);
    $id = $chain->record(['method' => 'POST', 'path' => '/y', 'status' => 200, 'ip' => '127.0.0.1', 'payload_hash' => 'y']);

    // Casser le maillon déclaré (p. ex. après suppression d'une ligne gênante).
    DB::table('audit_logs')->where('id', $id)->update(['prev_hash' => str_repeat('0', 64)]);

    expect($chain->verifyChain())->toBeFalse();
});
