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

// ── M1 / M2 (audit PR #446) : TLS mutuel + AC privée obligatoires, épinglage strict ──

function envoiFichierTemp(string $contenu = "-----BEGIN FAKE-----\n"): string
{
    $f = (string) tempnam(sys_get_temp_dir(), 'envoi-unit');
    file_put_contents($f, $contenu);

    return $f;
}

function envoiCible(): array
{
    return GardeHoteServeur::controler('https://envoi.exemple.test', envoiResolveur(['93.184.216.34']));
}

test('M1 : reel_actif vrai et TLS mutuel / AC privée incomplets = REFUS, jamais de repli sur les AC publiques', function (array $config) {
    $ca = envoiFichierTemp();
    $cert = envoiFichierTemp();
    $cle = envoiFichierTemp();
    config(['envoi.reel_actif' => true, 'envoi.serveur.ca_path' => $ca, 'envoi.serveur.client_cert_path' => $cert, 'envoi.serveur.client_key_path' => $cle]);
    config(array_map(fn ($v) => $v === '@absent' ? '/chemin/inexistant/x.pem' : $v, $config));
    expect(fn () => GardeHoteServeur::optionsHttp(envoiCible()))->toThrow(ServeurEnvoiRefuse::class);
    @unlink($ca);
    @unlink($cert);
    @unlink($cle);
})->with([
    'AC vide' => [['envoi.serveur.ca_path' => '']],
    'certificat client vide' => [['envoi.serveur.client_cert_path' => '']],
    'clé client vide' => [['envoi.serveur.client_key_path' => '']],
    'certificat sans clé' => [['envoi.serveur.client_key_path' => '', 'envoi.serveur.client_cert_path' => __FILE__]],
    'AC absente' => [['envoi.serveur.ca_path' => '@absent']],
    'certificat absent' => [['envoi.serveur.client_cert_path' => '@absent']],
    'clé absente' => [['envoi.serveur.client_key_path' => '@absent']],
    'tout vide' => [['envoi.serveur.ca_path' => '', 'envoi.serveur.client_cert_path' => '', 'envoi.serveur.client_key_path' => '']],
]);

test('M1 : configuration complète acceptée, AC privée seule dans « verify »', function () {
    $ca = envoiFichierTemp();
    $cert = envoiFichierTemp();
    $cle = envoiFichierTemp();
    config(['envoi.reel_actif' => true, 'envoi.serveur.ca_path' => $ca, 'envoi.serveur.client_cert_path' => $cert, 'envoi.serveur.client_key_path' => $cle]);
    $o = GardeHoteServeur::optionsHttp(envoiCible());
    expect($o['verify'])->toBe($ca)->and($o['cert'])->toBe($cert)->and($o['ssl_key'])->toBe($cle);
    foreach ([$ca, $cert, $cle] as $f) {
        @unlink($f);
    }
});

test('M2 : aucune AC publique du système (CAPATH = dossier vide) et épinglage de clé publique si configuré', function () {
    config(['envoi.reel_actif' => false, 'envoi.serveur.empreinte_cle' => '']);
    $o = GardeHoteServeur::optionsHttp(envoiCible());
    $dossier = $o['curl'][CURLOPT_CAPATH];
    expect(is_dir($dossier))->toBeTrue()->and(glob(rtrim($dossier, '/') . '/*'))->toBe([])
        ->and($o['curl'])->not->toHaveKey(CURLOPT_PINNEDPUBLICKEY);

    $empreinte = 'sha256//' . base64_encode(hash('sha256', 'cle-de-test', true));
    config(['envoi.serveur.empreinte_cle' => $empreinte]);
    expect(GardeHoteServeur::optionsHttp(envoiCible())['curl'][CURLOPT_PINNEDPUBLICKEY])->toBe($empreinte);

    config(['envoi.serveur.empreinte_cle' => 'nimportequoi']);
    expect(fn () => GardeHoteServeur::optionsHttp(envoiCible()))->toThrow(ServeurEnvoiRefuse::class);
});
