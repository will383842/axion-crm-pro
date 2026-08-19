<?php

/**
 * GARDE DU CANAL MACHINE INTERNE — audit 360, F37-001 (S0).
 *
 * `POST /api/internal/scraper-result` est le seul canal machine authentifié du
 * CRM. Sa vérification de signature était **fail-open** : le secret était lu par
 * `env('WORKER_INTERNAL_HMAC_SECRET', '')`, et un secret vide donnait
 * `hash_hmac('sha256', $body, '')` — un condensé parfaitement valide, calculable
 * par n'importe qui, puisque la clé est la chaîne vide.
 *
 * Mesuré le 2026-08-19 : le secret est **vide sur le serveur de production**, et
 * le funnel d'ingestion y est **ouvert**. N'importe qui pouvait donc pousser des
 * enregistrements dans la base de production.
 *
 * La classe durcie `HmacSignature` — déjà en place sur SiteSync et Gdpr — porte
 * exactement la garde qui manquait (`if ($secret === '') return false`). Elle
 * n'avait jamais été rétroportée ici : c'est l'origine du défaut.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Impose une valeur à une variable d'environnement, sur les TROIS canaux.
 *
 * `putenv()` seul ne suffit pas : le dépôt de variables de Laravel interroge
 * `ServerConstAdapter` ($_SERVER) en premier, et `variables_order = EGPCS` y
 * place les variables du conteneur. C'est le même piège que documente
 * `tests/bootstrap.php`.
 */
function imposerEnv(string $cle, ?string $valeur): void
{
    if ($valeur === null) {
        unset($_SERVER[$cle], $_ENV[$cle]);
        putenv($cle);

        return;
    }

    $_SERVER[$cle] = $valeur;
    $_ENV[$cle] = $valeur;
    putenv("{$cle}={$valeur}");
}

function appelerCanalInterne(string $corps, string $signature)
{
    return test()->call('POST', '/api/internal/scraper-result', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WORKER_SIGNATURE' => $signature,
    ], $corps);
}

afterEach(function () {
    imposerEnv('WORKER_INTERNAL_HMAC_SECRET', null);
});

test('F37-001 — secret ABSENT : le canal refuse, il ne verifie pas « quand meme »', function () {
    imposerEnv('WORKER_INTERNAL_HMAC_SECRET', '');

    $corps = json_encode(['run_id' => 'r1', 'source' => 'test', 'status' => 'ok']);

    // La signature qu'un attaquant calculerait sans connaître aucun secret :
    // la clé vide est connue de tous.
    $signatureForgee = hash_hmac('sha256', $corps, '');

    $reponse = appelerCanalInterne($corps, $signatureForgee);

    expect($reponse->status())->toBe(503);
    expect($reponse->json('error'))->toBe('signature_secret_absent');
});

test('F37-001 — TEMOIN : avec un vrai secret, une signature VALIDE passe', function () {
    imposerEnv('WORKER_INTERNAL_HMAC_SECRET', 'un-secret-de-test-suffisamment-long-2026');

    $corps = json_encode(['run_id' => 'r2', 'source' => 'test', 'status' => 'ok']);
    $signature = hash_hmac('sha256', $corps, 'un-secret-de-test-suffisamment-long-2026');

    // Le témoin est indispensable : sans lui, un correctif qui refuserait TOUT
    // passerait pour une réussite.
    expect(appelerCanalInterne($corps, $signature)->status())->toBe(200);
});

test('F37-001 — TEMOIN : avec un vrai secret, une signature FAUSSE est refusee', function () {
    imposerEnv('WORKER_INTERNAL_HMAC_SECRET', 'un-secret-de-test-suffisamment-long-2026');

    $corps = json_encode(['run_id' => 'r3', 'source' => 'test', 'status' => 'ok']);

    $reponse = appelerCanalInterne($corps, hash_hmac('sha256', $corps, 'le-mauvais-secret'));

    expect($reponse->status())->toBe(401);
    expect($reponse->json('error'))->toBe('bad_signature');
});

test('F37-001 — la signature de la CLE VIDE ne passe pas non plus quand un vrai secret existe', function () {
    imposerEnv('WORKER_INTERNAL_HMAC_SECRET', 'un-secret-de-test-suffisamment-long-2026');

    $corps = json_encode(['run_id' => 'r4', 'source' => 'test', 'status' => 'ok']);

    // C'est l'attaque exacte que permettait le fail-open.
    expect(appelerCanalInterne($corps, hash_hmac('sha256', $corps, ''))->status())->toBe(401);
});
