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

it('la liste signée par le serveur est celle du CRM (même ensemble, même ordre)', function () {
    $f = base_path('../infra/serveur-envoi/dkim/entetes-signes.txt');
    expect(is_file($f))->toBeTrue();
    $l = array_values(array_filter(array_map('trim', file($f)), fn ($x) => $x !== '' && $x[0] !== '#'));
    expect($l)->toBe(DkimSigneur::ENTETES_SIGNES)
        ->and($l)->toBe(['From', 'To', 'Subject', 'Date', 'Message-ID', 'MIME-Version', 'Content-Type', 'List-Unsubscribe', 'List-Unsubscribe-Post']);
});

it('aucune clé privée dans app/, config/ ni dans l\'infra', function () {
    $racines = [base_path('app'), base_path('config'), base_path('../infra')];
    $trouves = [];
    foreach ($racines as $r) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (! $f->isFile() || $f->getSize() > 2_000_000) {
                continue;
            }
            $nom = $f->getFilename();
            $contenu = (string) file_get_contents($f->getPathname());
            if (preg_match('/-----BEGIN (?:[A-Z]+ )?PRIVATE KEY-----/', $contenu) === 1
                || preg_match('/\.(pem|key)$/i', $nom) === 1) {
                $trouves[] = $f->getPathname();
            }
        }
    }
    expect($trouves)->toBe([]);
});
