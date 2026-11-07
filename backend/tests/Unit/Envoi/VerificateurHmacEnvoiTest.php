<?php

use App\Envoi\Serveur\SignatureEnvoi;
use App\Envoi\Serveur\VerificateurHmacEnvoi;

beforeEach(fn () => config(['crm.ingest.replay_store' => 'array']));

const ENVOI_N1 = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
const ENVOI_N2 = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2';

function envoiSigner(string $secret, string $ts, string $nonce, string $corps, string $m = 'POST', string $c = '/v1/commandes/pause'): string
{
    return SignatureEnvoi::signer($secret, SignatureEnvoi::chargeRequete($m, $c, $ts, $nonce, $corps));
}

test('signature valide : accepté', function () {
    $ts = (string) time();
    expect((new VerificateurHmacEnvoi)->verifier('s3cret', 'srv', $ts, ENVOI_N1, envoiSigner('s3cret', $ts, ENVOI_N1, '{}'), '{}', 'POST', '/v1/commandes/pause'))->toBe(200);
});

test('l’en-tête avec identifiant de clé k= est lu, l’ancienne forme aussi', function () {
    $ts = (string) time();
    $h = SignatureEnvoi::entete('7', envoiSigner('s', $ts, ENVOI_N1, '{}'));
    $v = new VerificateurHmacEnvoi;
    expect($v->verifier('s', 'srv', $ts, ENVOI_N1, $h, '{}', 'POST', '/v1/commandes/pause'))->toBe(200)
        ->and($v->verifier('s', 'srv', $ts, ENVOI_N2, envoiSigner('s', $ts, ENVOI_N2, '{}'), '{}', 'POST', '/v1/commandes/pause'))->toBe(200);
});

test('la signature couvre la méthode ET le chemin : les changer = 401', function () {
    $ts = (string) time();
    $sig = envoiSigner('s', $ts, ENVOI_N1, '{}');
    $v = new VerificateurHmacEnvoi;
    expect($v->verifier('s', 'srv', $ts, ENVOI_N1, $sig, '{}', 'GET', '/v1/commandes/pause'))->toBe(401)
        ->and($v->verifier('s', 'srv', $ts, ENVOI_N1, $sig, '{}', 'POST', '/v1/commandes/retrait'))->toBe(401);
});

test('signature fausse : 401', function () {
    $ts = (string) time();
    expect((new VerificateurHmacEnvoi)->verifier('s3cret', 'srv', $ts, ENVOI_N1, envoiSigner('autre', $ts, ENVOI_N1, '{}'), '{}', 'POST', '/v1/commandes/pause'))->toBe(401);
});

test('nonce non UUID canonique : 401', function () {
    $ts = (string) time();
    expect((new VerificateurHmacEnvoi)->verifier('s', 'srv', $ts, 'n1', envoiSigner('s', $ts, 'n1', '{}'), '{}', 'POST', '/v1/commandes/pause'))->toBe(401);
});

test('horodatage : 5 min (300 s) tolérées, 350 s REFUSÉ', function () {
    $v = new VerificateurHmacEnvoi;
    $ok = (string) (time() - 290);
    $refuse = (string) (time() - 350);
    expect(VerificateurHmacEnvoi::FENETRE_SECONDES)->toBe(300)
        ->and($v->verifier('s', 'srv', $ok, ENVOI_N1, envoiSigner('s', $ok, ENVOI_N1, 'x'), 'x', 'POST', '/v1/commandes/pause'))->toBe(200)
        ->and($v->verifier('s', 'srv', $refuse, ENVOI_N2, envoiSigner('s', $refuse, ENVOI_N2, 'x'), 'x', 'POST', '/v1/commandes/pause'))->toBe(401);
});

test('nonce rejoué : 409, et un nonce n’est consommé que par une requête authentique', function () {
    $v = new VerificateurHmacEnvoi;
    $ts = (string) time();
    expect($v->verifier('s', 'srv', $ts, ENVOI_N1, 'faux', 'x', 'POST', '/v1/commandes/pause'))->toBe(401)
        ->and($v->verifier('s', 'srv', $ts, ENVOI_N1, envoiSigner('s', $ts, ENVOI_N1, 'x'), 'x', 'POST', '/v1/commandes/pause'))->toBe(200)
        ->and($v->verifier('s', 'srv', $ts, ENVOI_N1, envoiSigner('s', $ts, ENVOI_N1, 'x'), 'x', 'POST', '/v1/commandes/pause'))->toBe(409);
});

test('secret vide : refus, même avec la signature « calculée » avec un secret vide', function () {
    $ts = (string) time();
    expect((new VerificateurHmacEnvoi)->verifier('', 'srv', $ts, ENVOI_N1, envoiSigner('', $ts, ENVOI_N1, 'x'), 'x', 'POST', '/v1/commandes/pause'))->toBe(401);
});

test('en-têtes absents : 401', function () {
    expect((new VerificateurHmacEnvoi)->verifier('s', 'srv', null, null, null, 'x'))->toBe(401);
});

test('vecteurs HMAC communs (envoi-serveur/contrat/vecteurs-hmac.json)', function () {
    $chemin = dirname(base_path()) . '/envoi-serveur/contrat/vecteurs-hmac.json';
    expect(is_file($chemin))->toBeTrue();
    $v = json_decode((string) file_get_contents($chemin), true, 512, JSON_THROW_ON_ERROR);
    expect($v['requetes'])->not->toBeEmpty();
    foreach ($v['requetes'] as $r) {
        $sig = SignatureEnvoi::signer($v['secret'], SignatureEnvoi::chargeRequete($r['methode'], $r['chemin'], $r['horodatage'], $r['nonce'], $r['corps']));
        expect($sig)->toBe($r['signature'])
            ->and(SignatureEnvoi::entete('1', $sig))->toBe($r['entete']);
    }
    foreach ($v['reponses'] as $r) {
        expect(SignatureEnvoi::signer($v['secret'], SignatureEnvoi::chargeReponse($r['nonce'], $r['workspace_id'], $r['statut'], $r['corps'])))->toBe($r['signature']);
    }
});
