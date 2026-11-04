<?php

/**
 * L7 — Signature HMAC du push agrégé CRM → Usine (sans base de données).
 *
 * Chaîne canonique : METHODE \n CHEMIN \n HORODATAGE \n NONCE \n SHA256(corps).
 */

use App\Crm\Usine\SignatureAgregats;
use Tests\TestCase;

uses(TestCase::class);

const L7_SECRET_TEST = 'secret-de-test-0123456789-0123456789-abcdef';

test('la chaîne canonique a exactement 5 lignes dans l’ordre prévu, sans query', function () {
    $corps = '{"a":1}';
    $chaine = SignatureAgregats::chaineCanonique('POST', '/api/agregats-crm?x=1', '1793800000', 'abcd', $corps);

    expect($chaine)->toBe("POST\n/api/agregats-crm\n1793800000\nabcd\n" . hash('sha256', $corps));
});

test('vecteur fixe : le HMAC-SHA256 attendu', function () {
    $corps = '{"a":1}';
    $attendu = hash_hmac('sha256', "POST\n/api/agregats-crm\n1793800000\nabcd\n" . hash('sha256', $corps), L7_SECRET_TEST);

    expect(SignatureAgregats::signer(L7_SECRET_TEST, 'POST', '/api/agregats-crm', '1793800000', 'abcd', $corps))
        ->toBe($attendu)
        ->and($attendu)->toMatch('/^[0-9a-f]{64}$/');
});

test('un octet de corps, de chemin, d’horodatage ou de nonce change la signature', function () {
    $base = SignatureAgregats::signer(L7_SECRET_TEST, 'POST', '/api/agregats-crm', '1', 'n', 'x');

    expect(SignatureAgregats::signer(L7_SECRET_TEST, 'POST', '/api/agregats-crm', '1', 'n', 'y'))->not->toBe($base)
        ->and(SignatureAgregats::signer(L7_SECRET_TEST, 'POST', '/api/autre', '1', 'n', 'x'))->not->toBe($base)
        ->and(SignatureAgregats::signer(L7_SECRET_TEST, 'POST', '/api/agregats-crm', '2', 'n', 'x'))->not->toBe($base)
        ->and(SignatureAgregats::signer(L7_SECRET_TEST, 'POST', '/api/agregats-crm', '1', 'm', 'x'))->not->toBe($base)
        ->and(SignatureAgregats::signer(L7_SECRET_TEST, 'GET', '/api/agregats-crm', '1', 'n', 'x'))->not->toBe($base);
});

test('le nonce fait 32 caractères hexadécimaux et change à chaque appel', function () {
    $a = SignatureAgregats::nonce();
    $b = SignatureAgregats::nonce();

    expect($a)->toMatch('/^[0-9a-f]{32}$/')->and($b)->not->toBe($a);
});

test('un secret de moins de 32 caractères est refusé (échec fermé)', function () {
    expect(fn () => SignatureAgregats::signer('court', 'POST', '/p', '1', 'n', ''))
        ->toThrow(InvalidArgumentException::class);
});
