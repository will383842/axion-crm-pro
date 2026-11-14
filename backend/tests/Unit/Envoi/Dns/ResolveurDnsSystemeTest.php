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
    'ipv4 mappée loopback' => [DNS_AAAA, [['ipv6' => '::ffff:127.0.0.1']]],
    'ipv4 mappée métadonnées' => [DNS_AAAA, [['ipv6' => '::ffff:169.254.169.254']]],
    'ipv4 mappée privée' => [DNS_AAAA, [['ipv6' => '::ffff:10.0.0.5']]],
    'ipv4 compatible' => [DNS_AAAA, [['ipv6' => '::127.0.0.1']]],
    'nat64' => [DNS_AAAA, [['ipv6' => '64:ff9b::a9fe:a9fe']]],
    '6to4' => [DNS_AAAA, [['ipv6' => '2002:7f00:1::1']]],
    'teredo' => [DNS_AAAA, [['ipv6' => '2001:0:4136:e378:8000:63bf:3fff:fdd2']]],
    'banc d\'essai 198.18/15' => [DNS_A, [['ip' => '198.18.0.1']]],
    'réservée 192.0.0.0/24' => [DNS_A, [['ip' => '192.0.0.8']]],
    'multicast' => [DNS_A, [['ip' => '224.0.0.1']]],
    'mélange public et privée' => [DNS_A, [['ip' => '192.0.2.1'], ['ip' => '10.0.0.5']]],
]);

test('MTA-STS : adresse publique, requête épinglée sur cette adresse', function () {
    Http::fake(['*' => Http::response('version: STSv1', 200)]);
    // 8.8.8.8 : adresse publique (192.0.2.x est réservée à la documentation).
    $r = new ResolveurDnsSysteme(fn (string $n, int $t) => $t === DNS_A ? [['ip' => '8.8.8.8']] : []);

    expect($r->politiqueMtaSts('envoi-fictif-r15.fr'))->toBe('version: STSv1');
    Http::assertSentCount(1);
    Http::assertSent(fn ($req) => $req->url() === 'https://mta-sts.envoi-fictif-r15.fr/.well-known/mta-sts.txt');
});

test('adressePublique : IPv6 publique acceptée', function () {
    expect(ResolveurDnsSysteme::adressePublique('2606:4700::1111'))->toBeTrue();
});
