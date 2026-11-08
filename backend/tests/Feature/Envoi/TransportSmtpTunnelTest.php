<?php

use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Serveur\Kumo\CoupureTransport;
use App\Envoi\Serveur\Kumo\EchecAuthentification;
use App\Envoi\Serveur\Kumo\EsmtpSuiviData;
use App\Envoi\Serveur\Kumo\RefusConnexion;
use App\Envoi\Serveur\Kumo\TransportSmtpTunnel;
use App\Envoi\Serveur\ServeurEnvoiRefuse;
use Tests\TestCase;

uses(TestCase::class);

// Transport SMTP du tunnel K9 contre un faux serveur local AVEC STARTTLS (certificat auto-signé généré ici).
// Aucun envoi réel, aucun réseau externe. « Avant DATA » = rien n'a pu partir ; « après DATA » = sort incertain.

const NOM_CERT_TUNNEL = 'faux.exemple.test';

/** @return array{0: string, 1: string} chemin du PEM (cert + clé), jamais commité */
function tunnelCertificat(): array
{
    static $pem = null;
    if ($pem === null) {
        $cle = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => NOM_CERT_TUNNEL], $cle);
        $cert = openssl_csr_sign($csr, null, $cle, 1);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($cle, $clePem);
        $pem = (string) tempnam(sys_get_temp_dir(), 'tun');
        file_put_contents($pem, $certPem . $clePem);
    }

    return [$pem, $pem];
}

function tunnelMsg(): MessageAEnvoyer
{
    return new MessageAEnvoyer('00000000-0000-4000-8000-000000000001', 'dest@example.test', 'from@example.test', 'Sujet', 'Corps du message', 'cle-tunnel');
}

function tunnelAvecFauxServeur(string $mode, Closure $corps): mixed
{
    [$pem] = tunnelCertificat();
    $fichier = (string) tempnam(sys_get_temp_dir(), 'tunsmtp');
    $pipes = [];
    $p = proc_open([PHP_BINARY, __DIR__ . '/../../Support/faux_smtp_tls_serveur.php', $mode, $fichier, $pem], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($p)) {
        throw new RuntimeException('Faux serveur SMTP non démarré.');
    }
    try {
        $port = (int) trim((string) fgets($pipes[1]));
        expect($port)->toBeGreaterThan(0);

        return $corps($port, $fichier);
    } finally {
        @proc_terminate($p);
        foreach ($pipes as $pipe) {
            @fclose($pipe);
        }
        @proc_close($p);
        @unlink($fichier);
        @unlink($fichier . '.cmd');
    }
}

function tunnel(int $port, int $delai = 30, ?string $nom = NOM_CERT_TUNNEL): TransportSmtpTunnel
{
    return new TransportSmtpTunnel('127.0.0.1', $port, 'utilisateur', 'secret-de-test-tunnel', $delai, tunnelCertificat()[0], $nom);
}

/** @return array{0: bool, 1: float} apresData, durée */
function tunnelCoupure(TransportSmtpTunnel $t): array
{
    $debut = microtime(true);
    try {
        $t->envoyer(tunnelMsg(), str_repeat('a', 32));
    } catch (CoupureTransport $c) {
        return [$c->apresData, microtime(true) - $debut];
    }
    throw new RuntimeException('Une coupure était attendue.');
}

test('le délai par défaut est de 30 secondes', function () {
    $p = (new ReflectionClass(TransportSmtpTunnel::class))->getConstructor()->getParameters();
    $delai = array_values(array_filter($p, fn ($x) => $x->getName() === 'delaiSecondes'))[0];
    expect($delai->getDefaultValue())->toBe(30);
});

test('accord 250 : code rendu, jeton dans l’en-tête, AUTH seulement après STARTTLS', function () {
    tunnelAvecFauxServeur('ok', function (int $port, string $fichier) {
        expect(tunnel($port)->envoyer(tunnelMsg(), str_repeat('ab', 16)))->toBe(250);
        expect(file_get_contents($fichier))->toContain('X-Envoi-Jeton: ' . str_repeat('ab', 16));
        $cmd = (string) file_get_contents($fichier . '.cmd');
        expect(strpos($cmd, 'STARTTLS'))->not->toBeFalse()->and(strpos($cmd, 'STARTTLS'))->toBeLessThan(strpos($cmd, 'AUTH'));
    });
});

test('muet après DATA : le délai coupe, classé APRÈS DATA (a_verifier, jamais renvoyé)', function () {
    tunnelAvecFauxServeur('muet_apres_data', function (int $port) {
        [$apres, $duree] = tunnelCoupure(tunnel($port, 1));
        expect($apres)->toBeTrue()->and($duree)->toBeLessThan(6.0);
    });
});

test('coupure après DATA : classée APRÈS DATA', function () {
    tunnelAvecFauxServeur('coupure_apres_data', function (int $port) {
        expect(tunnelCoupure(tunnel($port))[0])->toBeTrue();
    });
});

test('STARTTLS absent : classé AVANT DATA, et aucun identifiant n’est envoyé en clair', function () {
    tunnelAvecFauxServeur('sans_starttls', function (int $port, string $fichier) {
        expect(tunnelCoupure(tunnel($port))[0])->toBeFalse();
        expect(strtoupper((string) file_get_contents($fichier . '.cmd')))->not->toContain('AUTH');
    });
});

// Corrigé (audit K9) : l'ancien test classait l'AUTH 535 en simple coupure avant DATA, donc en report toutes les
// 15 minutes (apparence de forçage d'identifiants). Un échec AUTH est désormais une exception À PART.
test('AUTH refusé (535) : EchecAuthentification (à part), sans secret ni cause chaînée, DATA jamais atteint', function () {
    tunnelAvecFauxServeur('auth_535', function (int $port, string $fichier) {
        try {
            tunnel($port)->envoyer(tunnelMsg(), str_repeat('a', 32));
        } catch (EchecAuthentification $e) {
            expect($e->getMessage())->not->toContain('secret-de-test-tunnel')->and($e->getPrevious())->toBeNull();
            expect(filesize($fichier))->toBe(0);

            return;
        }
        throw new RuntimeException('EchecAuthentification attendu.');
    });
});

test('délai sur la bannière : classé AVANT DATA', function () {
    tunnelAvecFauxServeur('banniere_muette', function (int $port) {
        [$apres, $duree] = tunnelCoupure(tunnel($port, 1));
        expect($apres)->toBeFalse()->and($duree)->toBeLessThan(6.0);
    });
});

test('certificat qui ne correspond pas au nom attendu : EchecAuthentification (à part), avant DATA', function () {
    tunnelAvecFauxServeur('ok', function (int $port, string $fichier) {
        expect(fn () => tunnel($port, 30, 'autre.exemple.test')->envoyer(tunnelMsg(), str_repeat('a', 32)))
            ->toThrow(EchecAuthentification::class);
        expect(filesize($fichier))->toBe(0);
    });
});

test('5xx NUMÉRIQUE avant DATA (RCPT ou MAIL FROM refusé) : le code est rendu, jamais une coupure à reporter', function (string $mode) {
    tunnelAvecFauxServeur($mode, function (int $port, string $fichier) {
        expect(tunnel($port)->envoyer(tunnelMsg(), str_repeat('a', 32)))->toBe(550);
        expect(filesize($fichier))->toBe(0);
    });
})->with(['rcpt_550', 'mail_550']);

// B1 : un 5xx de CONNEXION (bannière, EHLO) n'est pas un refus du message : jamais rendu comme code d'échec définitif.
test('5xx de CONNEXION (bannière 554, EHLO 554, STARTTLS 554) : RefusConnexion (alerte et pause), jamais un code rendu', function (string $mode) {
    tunnelAvecFauxServeur($mode, function (int $port, string $fichier) {
        expect(fn () => tunnel($port)->envoyer(tunnelMsg(), str_repeat('a', 32)))->toThrow(RefusConnexion::class);
        expect(filesize($fichier))->toBe(0);
    });
})->with(['banniere_554', 'ehlo_554', 'starttls_554']);

test('jeton de 33 à 64 caractères : refusé (le VERP exige 32), jamais envoyé sans VERP', function () {
    expect(fn () => tunnel(1)->envoyer(tunnelMsg(), str_repeat('a', 40)))->toThrow(ServeurEnvoiRefuse::class);
});

test('enveloppe invalide (adresse non RFC ou partie locale non ASCII) : refus local, aucune connexion', function (string $dest) {
    $m = new MessageAEnvoyer('00000000-0000-4000-8000-000000000001', $dest, 'from@example.test', 'S', 'C', 'cle-tunnel');
    // port 1 : une connexion échouerait ; le refus doit venir AVANT.
    expect(fn () => tunnel(1)->envoyer($m, str_repeat('a', 32)))->toThrow(ServeurEnvoiRefuse::class);
})->with(['a b@example.test', 'é@example.test', 'a@@x']);

test('X-Envoi-Source est transmis et le chemin de retour est le VERP rb-<jeton>@bounce.<domaine>', function () {
    config(['envoi.cle_empreinte' => 'cle-empreinte-de-test-0123456789']);
    tunnelAvecFauxServeur('ok', function (int $port, string $fichier) {
        $jeton = str_repeat('ab', 16);
        expect(tunnel($port)->envoyer(tunnelMsg(), $jeton, 'src-test'))->toBe(250);
        expect(file_get_contents($fichier))->toContain('X-Envoi-Source: src-test');
        $cmd = (string) file_get_contents($fichier . '.cmd');
        expect($cmd)->toMatch('/MAIL FROM:<rb-' . $jeton . '[0-9a-f]{10}@bounce\.example\.test>/i');
    });
});

test('4xx et 5xx après DATA : le code SMTP est rendu tel quel', function (string $mode, int $code) {
    tunnelAvecFauxServeur($mode, function (int $port) use ($code) {
        expect(tunnel($port)->envoyer(tunnelMsg(), str_repeat('a', 32)))->toBe($code);
    });
})->with([['451_apres_data', 451], ['550_apres_data', 550]]);

// Corrigé (audit K9) : l'ancien test lisait getTraceAsString(), qui coupe les arguments à 15 caractères : il passait
// même avec un secret exposé. On vérifie l'exception rendue : ni le secret, ni cause chaînée, et le secret est
// marqué #[\SensitiveParameter] (le constructeur ET executeCommand, qui reçoit « AUTH ... » avec identifiants).
test('construction refusée : ni le secret ni une cause chaînée dans l’exception rendue', function () {
    try {
        new TransportSmtpTunnel('', 587, 'u', 'secret-visible-xyz');
    } catch (Throwable $e) {
        expect($e->getMessage())->not->toContain('secret-visible-xyz')->and($e->getPrevious())->toBeNull();

        return;
    }
    throw new RuntimeException('Un refus était attendu.');
});

test('executeCommand porte #[SensitiveParameter] (la commande AUTH contient les identifiants)', function () {
    $p = (new ReflectionMethod(EsmtpSuiviData::class, 'executeCommand'))->getParameters()[0];
    expect($p->getAttributes(SensitiveParameter::class))->toHaveCount(1);
});
