<?php

/**
 * LOT K5 — DKIM signé par le SERVEUR (ADR-K0 D3). Aucun envoi réel, données fictives.
 */

use App\Envoi\Message\ComposeurMessage;
use App\Envoi\Message\DemandeMessage;
use App\Envoi\Message\DkimSigneur;
use App\Envoi\Message\SignatureDkim;

function k5Demande(): DemandeMessage
{
    return new DemandeMessage(
        partieLocale: 'contact',
        nomAffiche: 'Société Fictive',
        replyTo: 'reponses@exemple-envoi.test',
        destinataire: 'cible@exemple.test',
        sujet: 'Bonjour',
        texte: 'Texte.',
        html: '<p>Texte.</p>',
        jetonEnvoi: 'jeton-test-12345',
        urlDesinscription: 'https://exemple-envoi.test/d/abc',
        mailtoDesinscription: 'mailto:stop@exemple-envoi.test',
        entite: 'Société Fictive SAS',
        adressePostale: '1 rue de l\'Exemple, 75000 Paris',
        texteArt14: 'Texte art. 14.',
        variables: [],
    );
}

it('mode serveur : le CRM ne signe pas', function () {
    $c = new ComposeurMessage('exemple-envoi.test', null, signeDkim: SignatureDkim::Serveur);
    $m = $c->composer(k5Demande());
    expect($m)->not->toContain('DKIM-Signature')
        ->and($m)->toContain('List-Unsubscribe-Post: List-Unsubscribe=One-Click');
});

it('mode serveur : refuse un signeur DKIM côté CRM', function () {
    $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($k, $pem);
    new ComposeurMessage('exemple-envoi.test', new DkimSigneur('exemple-envoi.test', 's1', $pem), signeDkim: SignatureDkim::Serveur);
})->throws(InvalidArgumentException::class);

it('mode crm : signe toujours (relais tiers temporaire)', function () {
    $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($k, $pem);
    $c = new ComposeurMessage('exemple-envoi.test', new DkimSigneur('exemple-envoi.test', 's1', $pem), signeDkim: SignatureDkim::Crm);
    expect($c->composer(k5Demande()))->toContain('DKIM-Signature');
});

it('mode crm sans signeur : refusé', function () {
    new ComposeurMessage('exemple-envoi.test', null, signeDkim: SignatureDkim::Crm);
})->throws(InvalidArgumentException::class);

it('List-Unsubscribe-Post figure dans h= du CRM', function () {
    expect(DkimSigneur::ENTETES_SIGNES)->toContain('List-Unsubscribe-Post');
});

function k5Liste(string $fichier): array
{
    $f = base_path('../infra/serveur-envoi/dkim/' . $fichier);
    expect(is_file($f))->toBeTrue();

    return array_values(array_filter(array_map('trim', file($f)), fn ($x) => $x !== '' && $x[0] !== '#'));
}

it('la liste signée par le serveur est celle du CRM (même ensemble, même ordre)', function () {
    expect(k5Liste('entetes-signes.txt'))->toBe(DkimSigneur::ENTETES_SIGNES)
        ->and(DkimSigneur::ENTETES_SIGNES)->toBe(['From', 'Reply-To', 'To', 'Subject', 'Date', 'Message-ID',
            'MIME-Version', 'Content-Type', 'List-Unsubscribe', 'List-Unsubscribe-Post']);
});

it('les en-têtes sur-signés du serveur sont ceux du CRM', function () {
    expect(k5Liste('entetes-sursignes.txt'))->toBe(DkimSigneur::ENTETES_SURSIGNES)
        ->and(DkimSigneur::ENTETES_SURSIGNES)->toBe(['From', 'Subject', 'List-Unsubscribe']);
});

it('h= du CRM : Reply-To signé, From/Subject/List-Unsubscribe sur-signés', function () {
    $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($k, $pem);
    $m = (new ComposeurMessage('exemple-envoi.test', new DkimSigneur('exemple-envoi.test', 's1', $pem), signeDkim: SignatureDkim::Crm))
        ->composer(k5Demande());
    $sig = preg_replace('/\r\n[ \t]+/', ' ', explode("\r\n\r\n", $m, 2)[0]);
    preg_match('/DKIM-Signature:.*? h=([^;]+);/', $sig, $mm);
    $h = array_map('trim', explode(':', $mm[1]));
    expect($h)->toBe(array_map('strtolower', [...DkimSigneur::ENTETES_SIGNES, ...DkimSigneur::ENTETES_SURSIGNES]))
        ->and(array_count_values($h)['from'])->toBe(2)
        ->and($h)->toContain('reply-to');
});

it('ComposeurMessage : signeDkim n\'a aucune valeur par défaut (ADR D3.1)', function () {
    foreach ((new ReflectionClass(ComposeurMessage::class))->getConstructor()->getParameters() as $p) {
        if ($p->getName() === 'signeDkim') {
            expect($p->isDefaultValueAvailable())->toBeFalse();

            return;
        }
    }
    throw new RuntimeException('paramètre signeDkim introuvable');
});

it('aucune clé privée DKIM côté CRM (app, config, storage, tests, .env*, infra)', function () {
    $moi = realpath(__FILE__);
    $racines = [base_path('app'), base_path('config'), base_path('storage'), base_path('tests'), base_path('../infra')];
    $trouves = [];
    foreach ($racines as $r) {
        if (! is_dir($r)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (! $f->isFile() || $f->getSize() > 2_000_000 || realpath($f->getPathname()) === $moi) {
                continue;
            }
            $contenu = (string) file_get_contents($f->getPathname());
            if (preg_match('/-----BEGIN (?:[A-Z]+ )?PRIVATE KEY-----/', $contenu) === 1
                || preg_match('/\.(pem|key)$/i', $f->getFilename()) === 1) {
                $trouves[] = $f->getPathname();
            }
        }
    }
    foreach ([...glob(base_path('.env*')) ?: [], ...glob(base_path('../.env*')) ?: []] as $env) {
        if (preg_match('/-----BEGIN (?:[A-Z]+ )?PRIVATE KEY-----|DKIM_PRIVATE_KEY\s*=\s*\S/', (string) file_get_contents($env)) === 1) {
            $trouves[] = $env;
        }
    }
    expect($trouves)->toBe([]);
});

it('aucune variable DKIM_PRIVATE_KEY dans la config ni dans app/', function () {
    $trouves = [];
    foreach ([base_path('config'), base_path('app')] as $r) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && stripos((string) file_get_contents($f->getPathname()), 'DKIM_PRIVATE_KEY') !== false) {
                $trouves[] = $f->getPathname();
            }
        }
    }
    expect($trouves)->toBe([]);
});
