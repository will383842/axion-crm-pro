<?php

use App\Envoi\Serveur\VerificateurHmacEnvoi;
use App\Support\HmacSignature;

beforeEach(fn () => config(['crm.ingest.replay_store' => 'array']));

function envoiSigner(string $secret, string $ts, string $nonce, string $corps): string
{
    return HmacSignature::sign($secret, HmacSignature::signedPayload($ts, $nonce . '.' . $corps));
}

test('signature valide : accepté', function () {
    $ts = (string) time();
    expect((new VerificateurHmacEnvoi)->verifier('s3cret', 'srv', $ts, 'n1', envoiSigner('s3cret', $ts, 'n1', '{}'), '{}'))->toBe(200);
});

test('signature fausse : 401', function () {
    $ts = (string) time();
    expect((new VerificateurHmacEnvoi)->verifier('s3cret', 'srv', $ts, 'n1', envoiSigner('autre', $ts, 'n1', '{}'), '{}'))->toBe(401);
});

test('horodatage : 6 min tolérées, au-delà 401', function () {
    $v = new VerificateurHmacEnvoi;
    $ok = (string) (time() - 350);
    $vieux = (string) (time() - 361);
    expect($v->verifier('s', 'srv', $ok, 'a', envoiSigner('s', $ok, 'a', 'x'), 'x'))->toBe(200)
        ->and($v->verifier('s', 'srv', $vieux, 'b', envoiSigner('s', $vieux, 'b', 'x'), 'x'))->toBe(401);
});

test('nonce rejoué : 409, et un nonce n’est consommé que par une requête authentique', function () {
    $v = new VerificateurHmacEnvoi;
    $ts = (string) time();
    expect($v->verifier('s', 'srv', $ts, 'n', 'faux', 'x'))->toBe(401)
        ->and($v->verifier('s', 'srv', $ts, 'n', envoiSigner('s', $ts, 'n', 'x'), 'x'))->toBe(200)
        ->and($v->verifier('s', 'srv', $ts, 'n', envoiSigner('s', $ts, 'n', 'x'), 'x'))->toBe(409);
});

test('secret vide : refus, même avec la signature « calculée » avec un secret vide', function () {
    $ts = (string) time();
    expect((new VerificateurHmacEnvoi)->verifier('', 'srv', $ts, 'n', envoiSigner('', $ts, 'n', 'x'), 'x'))->toBe(401);
});

test('en-têtes absents : 401', function () {
    expect((new VerificateurHmacEnvoi)->verifier('s', 'srv', null, null, null, 'x'))->toBe(401);
});
