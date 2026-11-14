<?php

use App\Envoi\Dns\ResolveurDnsSysteme;
use Illuminate\Support\Facades\Http;

/** B1 R15 : aucune requête HTTP sortante vers une adresse privée ou réservée (SSRF). Aucun réseau réel. */
test('MTA-STS : adresse privée, réservée ou vide, aucune requête HTTP', function (int $type, array $reponse) {
    Http::fake();
    $r = new ResolveurDnsSysteme(fn (string $n, int $t) => $t === $type ? $reponse : []);

    expect($r->politiqueMtaSts('envoi-fictif-r15.fr'))->toBeNull();
    Http::assertNothingSent();
})->with([
    'loopback' => [DNS_A, [['ip' => '127.0.0.1']]],
    'privée 10/8' => [DNS_A, [['ip' => '10.0.0.5']]],
    'lien local (métadonnées)' => [DNS_A, [['ip' => '169.254.169.254']]],
    'partagée 100.64/10' => [DNS_A, [['ip' => '100.64.0.1']]],
    'ipv6 loopback' => [DNS_AAAA, [['ipv6' => '::1']]],
    'ipv6 ULA' => [DNS_AAAA, [['ipv6' => 'fd00::1']]],
    'liste vide' => [DNS_A, []],
    'mélange public et privée' => [DNS_A, [['ip' => '192.0.2.1'], ['ip' => '10.0.0.5']]],
]);

test('MTA-STS : adresse publique, requête épinglée sur cette adresse', function () {
    Http::fake(['*' => Http::response('version: STSv1', 200)]);
    // 8.8.8.8 : adresse publique (192.0.2.x est réservée à la documentation).
    $r = new ResolveurDnsSysteme(fn (string $n, int $t) => $t === DNS_A ? [['ip' => '8.8.8.8']] : []);

    expect($r->politiqueMtaSts('envoi-fictif-r15.fr'))->toBe('version: STSv1');
    Http::assertSentCount(1);
});
