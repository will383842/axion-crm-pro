<?php

use App\Envoi\Serveur\GardeHoteServeur;
use App\Envoi\Serveur\ServeurEnvoiRefuse;
use App\Envoi\Smtp\ResolveurHote;

function envoiResolveur(array $ips): ResolveurHote
{
    return new class($ips) implements ResolveurHote
    {
        public function __construct(private array $ips) {}

        public function resoudre(string $hote): array
        {
            return $this->ips;
        }
    };
}

test('https public : adresse IPv4 épinglée', function () {
    $c = GardeHoteServeur::controler('https://envoi.exemple.test', envoiResolveur(['2001:4860::1', '93.184.216.34']));
    expect($c['ip'])->toBe('93.184.216.34')->and($c['base'])->toBe('https://envoi.exemple.test');
    $o = GardeHoteServeur::optionsHttp($c);
    expect($o['allow_redirects'])->toBeFalse()->and($o['verify'])->toBeTrue()->and($o['force_ip_resolve'])->toBe('v4')
        ->and($o['curl'][CURLOPT_RESOLVE])->toBe(['envoi.exemple.test:443:93.184.216.34']);
});

test('refus : http, plages privées, métadonnées, loopback, link-local, IPv6 seule, identifiants, chemin', function (string $url, array $ips) {
    expect(fn () => GardeHoteServeur::controler($url, envoiResolveur($ips)))->toThrow(ServeurEnvoiRefuse::class);
})->with([
    'http' => ['http://envoi.exemple.test', ['93.184.216.34']],
    'privée' => ['https://envoi.exemple.test', ['10.0.0.5']],
    'une adresse privée parmi des publiques' => ['https://envoi.exemple.test', ['93.184.216.34', '192.168.1.1']],
    'métadonnées' => ['https://envoi.exemple.test', ['169.254.169.254']],
    'loopback' => ['https://envoi.exemple.test', ['127.0.0.1']],
    'ipv6 seule' => ['https://envoi.exemple.test', ['2001:4860::1']],
    'littéral métadonnées' => ['https://169.254.169.254', []],
    'localhost' => ['https://localhost', ['93.184.216.34']],
    'identifiants' => ['https://u:p@envoi.exemple.test', ['93.184.216.34']],
    'chemin' => ['https://envoi.exemple.test/x', ['93.184.216.34']],
    'port' => ['https://envoi.exemple.test:22', ['93.184.216.34']],
    'non résolu' => ['https://envoi.exemple.test', []],
]);
