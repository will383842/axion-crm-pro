<?php

declare(strict_types=1);

use App\Http\Middleware\AuditHashChainLogger;

// R05 : l'adresse testée par « puis-je écrire » ne doit pas être retrouvable
// par dictionnaire depuis audit_logs.payload_hash (le journal s'écrit avant les
// gardes de permission et de débit, donc aussi sur un 403 ou un 429).
test('R05 — l empreinte d audit masque le champ adresse', function (): void {
    $a = AuditHashChainLogger::empreinteDuCorps(['adresse' => 'secret@zz-client.example.invalid']);
    $b = AuditHashChainLogger::empreinteDuCorps(['adresse' => 'autre@zz-client.example.invalid']);

    expect($a)->toBe($b);
    expect($a)->not->toBe(hash('sha256', json_encode(['adresse' => 'secret@zz-client.example.invalid'], JSON_THROW_ON_ERROR)));
    expect($a)->toBe(hash('sha256', json_encode(['adresse' => AuditHashChainLogger::VALEUR_MASQUEE], JSON_THROW_ON_ERROR)));
});

test('R05 — adresse_postale et autres champs voisins ne sont pas masqués (comparaison exacte)', function (): void {
    $a = AuditHashChainLogger::empreinteDuCorps(['adresse_postale' => '1 rue A']);
    $b = AuditHashChainLogger::empreinteDuCorps(['adresse_postale' => '2 rue B']);

    expect($a)->not->toBe($b);
});
