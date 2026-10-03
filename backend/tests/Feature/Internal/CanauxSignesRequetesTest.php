<?php

/**
 * CANAUX INTERNES SIGNÉS PAR LE SITE — horodatage requis sur
 * `/internal/site-sync` et `/internal/site-sync/gdpr` ; requêtes répétées
 * refusées sur `/internal/site-sync/gdpr` (sans identifiant d'idempotence).
 * `/internal/site-sync` s'appuie sur l'`event_id` : un doublon exact y passe.
 *
 * Le drapeau `crm.ingest.enabled` est laissé FERMÉ : une requête authentifiée
 * reçoit alors son 503 habituel (`ingest_disabled`), ce qui prouve qu'elle a
 * franchi l'authentification sans rien écrire. Les refus d'authentification
 * restent des 401 au format existant (`stale_signature` / `bad_signature`).
 *
 * Journaux : chaque refus écrit l'empreinte HMAC à clé de l'IP
 * (`App\Support\EmpreinteIp`, comme le canal Partners), jamais l'IP en clair.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// RefreshDatabase : chaque requête HTTP écrit au journal d'audit (voir
// l'en-tête de `PatronHmacDeReferenceTest.php`).
uses(TestCase::class, RefreshDatabase::class);

define('CANAL_SIGNE_SECRET', 'secret-de-test-' . str_repeat('e5f6', 12));

beforeEach(function () {
    config([
        'crm.ingest.enabled' => false,
        'crm.ingest.hmac_secret' => CANAL_SIGNE_SECRET,
        'crm.ingest.max_clock_skew_seconds' => 300,
        'crm.ingest.replay_store' => 'array',
    ]);
});

/**
 * @param  array<string, string>  $entetes  en-têtes HTTP_* ajoutés ou remplacés
 */
function appelCanalSigne(string $route, string $corps, array $entetes): TestResponse
{
    return test()->call('POST', $route, [], [], [], array_merge([
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $entetes), $corps);
}

/** @return array<string, string> */
function entetesSignes(string $corps, ?string $horodatage = null, string $prefixe = ''): array
{
    $horodatage ??= (string) time();

    return [
        'HTTP_X_SITE_TIMESTAMP' => $horodatage,
        'HTTP_X_SITE_SIGNATURE' => $prefixe . hash_hmac('sha256', $horodatage . '.' . $corps, CANAL_SIGNE_SECRET),
    ];
}

dataset('routes signées par le site', [
    'site-sync' => ['/api/internal/site-sync', '{"event_id":"00000000-0000-4000-8000-000000000001","event_type":"form_submission"}'],
    'site-sync/gdpr' => ['/api/internal/site-sync/gdpr', '{"action":"export","person_key":"' . str_repeat('a', 64) . '","email":"zz@example.invalid","scope":"both"}'],
]);

dataset('route signée sans idempotence', [
    'site-sync/gdpr' => ['/api/internal/site-sync/gdpr', '{"action":"export","person_key":"' . str_repeat('a', 64) . '","email":"zz@example.invalid","scope":"both"}'],
]);

test('TÉMOIN : une requête valide passe l’authentification (503 drapeau fermé, comme avant)', function (string $route, string $corps) {
    appelCanalSigne($route, $corps, entetesSignes($corps))
        ->assertStatus(503)
        ->assertJsonPath('error', 'ingest_disabled');
})->with('routes signées par le site');

test('une requête identique REJOUÉE dans la fenêtre est refusée', function (string $route, string $corps) {
    $entetes = entetesSignes($corps);

    appelCanalSigne($route, $corps, $entetes)->assertStatus(503);
    appelCanalSigne($route, $corps, $entetes)
        ->assertStatus(401)
        ->assertJsonPath('error', 'stale_signature');
})->with('route signée sans idempotence');

test('site-sync : un doublon EXACT passe l’authentification (l’idempotence par event_id le traite)', function () {
    $corps = '{"event_id":"00000000-0000-4000-8000-000000000001","event_type":"form_submission"}';
    $entetes = entetesSignes($corps);

    appelCanalSigne('/api/internal/site-sync', $corps, $entetes)->assertStatus(503);
    appelCanalSigne('/api/internal/site-sync', $corps, $entetes)
        ->assertStatus(503)
        ->assertJsonPath('error', 'ingest_disabled');
});

test('site-sync : la mémoire des requêtes n’est pas sollicitée (un magasin en panne n’y change rien)', function () {
    config(['crm.ingest.replay_store' => 'magasin-inexistant']);
    $corps = '{"event_id":"00000000-0000-4000-8000-000000000002","event_type":"form_submission"}';

    appelCanalSigne('/api/internal/site-sync', $corps, entetesSignes($corps))
        ->assertStatus(503)
        ->assertJsonPath('error', 'ingest_disabled');
});

test('le rejeu ne se contourne pas en changeant l’écriture de la signature (préfixe sha256=)', function (string $route, string $corps) {
    $horodatage = (string) time();

    appelCanalSigne($route, $corps, entetesSignes($corps, $horodatage))->assertStatus(503);
    appelCanalSigne($route, $corps, entetesSignes($corps, $horodatage, 'sha256='))
        ->assertStatus(401)
        ->assertJsonPath('error', 'stale_signature');
})->with('route signée sans idempotence');

test('TÉMOIN : le même corps re-signé avec un autre horodatage passe (nouvelle tentative de l’émetteur)', function (string $route, string $corps) {
    appelCanalSigne($route, $corps, entetesSignes($corps, (string) time()))->assertStatus(503);
    appelCanalSigne($route, $corps, entetesSignes($corps, (string) (time() - 1)))->assertStatus(503);
})->with('routes signées par le site');

test('sans horodatage : refus AVANT tout calcul de signature', function (string $route, string $corps) {
    // Signature volontairement fausse : si la signature était contrôlée en
    // premier, la réponse serait `bad_signature`.
    appelCanalSigne($route, $corps, ['HTTP_X_SITE_SIGNATURE' => str_repeat('0', 64)])
        ->assertStatus(401)
        ->assertJsonPath('error', 'stale_signature');

    // Et une signature calculée sur un horodatage vide ne passe pas non plus.
    appelCanalSigne($route, $corps, ['HTTP_X_SITE_SIGNATURE' => hash_hmac('sha256', '.' . $corps, CANAL_SIGNE_SECRET)])
        ->assertStatus(401)
        ->assertJsonPath('error', 'stale_signature');
})->with('routes signées par le site');

test('horodatage non entier ou hors fenêtre : refus avant la signature', function (string $route, string $corps) {
    foreach (['abc', '12.5', (string) (time() - 3600), (string) (time() + 3600)] as $horodatage) {
        appelCanalSigne($route, $corps, array_merge(entetesSignes($corps, $horodatage), [
            'HTTP_X_SITE_SIGNATURE' => str_repeat('0', 64),
        ]))->assertStatus(401)->assertJsonPath('error', 'stale_signature');
    }
})->with('routes signées par le site');

test('TÉMOIN : horodatage valide et signature fausse → bad_signature, comme avant', function (string $route, string $corps) {
    appelCanalSigne($route, $corps, array_merge(entetesSignes($corps), [
        'HTTP_X_SITE_SIGNATURE' => str_repeat('0', 64),
    ]))->assertStatus(401)->assertJsonPath('error', 'bad_signature');
})->with('routes signées par le site');

test('mémoire anti-rejeu indisponible : la requête est REFUSÉE, et le journal ne contient ni corps ni SQL', function (string $route, string $corps) {
    // Un vrai magasin Redis, pointé sur un port où rien n'écoute : la
    // connexion échoue à la première commande, comme un Redis tombé.
    config([
        'database.redis.panne-test' => [
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 0,
            'timeout' => 0.5,
            'read_write_timeout' => 0.5,
        ],
        'cache.stores.panne-test' => ['driver' => 'redis', 'connection' => 'panne-test'],
        'crm.ingest.replay_store' => 'panne-test',
    ]);
    Log::spy();

    appelCanalSigne($route, $corps, entetesSignes($corps))
        ->assertStatus(503)
        ->assertJsonPath('error', 'replay_guard_unavailable');

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $contexte = []) {
            $serialise = $message . json_encode($contexte);

            return str_contains($message, 'anti-rejeu indisponible')
                && ! str_contains($serialise, 'example.invalid')
                && ! str_contains($serialise, 'event_id')
                && ! str_contains(strtolower($serialise), 'select ');
        })
        ->once();
})->with('route signée sans idempotence');

// ─── Journaux : IP hachée, comme le canal Partners ───────────────────────

/** @return list<array{message: string, contexte: array<string, mixed>}> */
function journalCanalSigne(string $route, Closure $action): array
{
    $canal = ltrim(str_replace('/api/internal/', '', $route), '/');
    $journal = [];
    Log::listen(function (MessageLogged $e) use (&$journal, $canal): void {
        if (str_starts_with($e->message, $canal . ' rejeté')) {
            $journal[] = ['message' => $e->message, 'contexte' => $e->context];
        }
    });
    $action();

    return $journal;
}

dataset('refus du canal signé par le site', [
    'horodatage absent' => ['/api/internal/site-sync/gdpr', 'absent', 401, '{"error":"stale_signature"}'],
    'signature invalide' => ['/api/internal/site-sync/gdpr', 'signature', 401, '{"error":"bad_signature"}'],
    'requête déjà reçue' => ['/api/internal/site-sync/gdpr', 'rejeu', 401, '{"error":"stale_signature"}'],
    'mémoire anti-rejeu indisponible' => ['/api/internal/site-sync/gdpr', 'panne', 503, '{"error":"replay_guard_unavailable"}'],
    'site-sync : signature invalide' => ['/api/internal/site-sync', 'signature', 401, '{"error":"bad_signature"}'],
]);

test('journal des refus du canal du site : empreinte HMAC à clé de l’IP, jamais l’IP en clair, réponse inchangée', function (string $route, string $cas, int $statut, string $corpsAttendu) {
    config(['crm.journaux.ip_cle' => hash('sha256', 'cle-journal-ip-site')]);
    $ip = '203.0.113.88';
    $corps = '{"action":"export","person_key":"' . str_repeat('a', 64) . '","email":"zz@example.invalid","scope":"both"}';
    $entetes = entetesSignes($corps);
    $avecIp = fn (array $e): array => array_merge($e, ['REMOTE_ADDR' => $ip]);

    if ($cas === 'rejeu') {
        appelCanalSigne($route, $corps, $avecIp($entetes))->assertStatus(503);
    }
    if ($cas === 'panne') {
        config(['crm.ingest.replay_store' => 'magasin-inexistant']);
    }
    $entetes = match ($cas) {
        'absent' => array_diff_key($entetes, ['HTTP_X_SITE_TIMESTAMP' => 1]),
        'signature' => array_merge($entetes, ['HTTP_X_SITE_SIGNATURE' => str_repeat('0', 64)]),
        default => $entetes,
    };

    $reponse = null;
    $journal = journalCanalSigne($route, function () use ($route, $corps, $entetes, $avecIp, &$reponse): void {
        $reponse = appelCanalSigne($route, $corps, $avecIp($entetes));
    });

    expect($reponse->getStatusCode())->toBe($statut)
        ->and($reponse->getContent())->toBe($corpsAttendu)
        ->and($journal)->toHaveCount(1)
        ->and(json_encode($journal))->not->toContain($ip)
        ->and($journal[0]['contexte'])->not->toHaveKey('ip')
        ->and($journal[0]['contexte']['ip_empreinte'])
        ->toBe(substr(hash_hmac('sha256', $ip, hash('sha256', 'cle-journal-ip-site')), 0, 32));
})->with('refus du canal signé par le site');

test('plus aucune IP en clair dans le contrôle du canal du site', function () {
    expect((string) file_get_contents(app_path('Support/CanalSigneSite.php')))
        ->not->toContain("'ip' =>")
        ->toContain('EmpreinteIp::de(');
});
