<?php

/**
 * LOT L2 — composeur du message. Composition seulement : aucun envoi réseau.
 * Données 100 % fictives ; la clé DKIM de test est générée à la volée.
 */

use App\Envoi\Message\ComposeurMessage;
use App\Envoi\Message\ControleurContenu;
use App\Envoi\Message\DemandeMessage;
use App\Envoi\Message\DkimSigneur;
use App\Envoi\Message\MessageRefuse;

function l2Demande(array $o = []): DemandeMessage
{
    $a = array_merge([
        'partieLocale' => 'contact',
        'nomAffiche' => 'Hélène Dupré',
        'replyTo' => 'reponses@exemple-envoi.test',
        'destinataire' => 'cible@exemple.test',
        'sujet' => 'Bonjour {{prenom|cher contact}}, une proposition',
        'texte' => "Bonjour {{prenom|cher contact}},\nÉvénement à découvrir : café à 10 €.",
        'html' => '<p>Bonjour {{prenom|cher contact}},</p><p>Événement à découvrir : café à 10 €.</p>',
        'jetonEnvoi' => 'jeton-test-12345',
        'urlDesinscription' => 'https://exemple-envoi.test/desinscription/abc',
        'mailtoDesinscription' => 'mailto:desinscription@exemple-envoi.test?subject=stop',
        'entite' => 'Société Fictive SAS',
        'adressePostale' => '1 rue de l\'Exemple, 75000 Paris',
        'texteArt14' => 'Texte art. 14 fourni par le paramétrage.',
        'variables' => [],
    ], $o);

    return new DemandeMessage(...$a);
}

function l2CleTest(): string
{
    $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($k, $pem);

    return $pem;
}

/** @return array{0:array<string,string>,1:string,2:string} en-têtes (clé minuscule), corps, tête brute */
function l2Analyse(string $msg): array
{
    [$tete, $corps] = explode("\r\n\r\n", $msg, 2);
    $tete = preg_replace('/\r\n[ \t]+/', ' ', $tete);
    $h = [];
    foreach (explode("\r\n", $tete) as $l) {
        [$n, $v] = explode(':', $l, 2);
        $h[strtolower($n)] = trim($v);
    }

    return [$h, $corps, $tete];
}

function l2Composeur(?DkimSigneur $d = null): ComposeurMessage
{
    return new ComposeurMessage('exemple-envoi.test', $d);
}

it('pose Message-ID unique sous le domaine d\'envoi', function () {
    [$a] = l2Analyse(l2Composeur()->composer(l2Demande()));
    [$b] = l2Analyse(l2Composeur()->composer(l2Demande()));
    expect($a['message-id'])->toMatch('/^<[0-9a-f]{32}@exemple-envoi\.test>$/')
        ->and($a['message-id'])->not->toBe($b['message-id']);
});

it('pose la Date RFC 2822', function () {
    [$h] = l2Analyse(l2Composeur()->composer(l2Demande()));
    expect(DateTimeImmutable::createFromFormat(DateTimeInterface::RFC2822, $h['date']))->not->toBeFalse();
});

it('pose From avec nom affiché et adresse du domaine d\'envoi', function () {
    [$h] = l2Analyse(l2Composeur()->composer(l2Demande()));
    expect($h['from'])->toEndWith('<contact@exemple-envoi.test>');
    $nom = trim(explode('<', $h['from'])[0]);
    expect(iconv_mime_decode($nom, 0, 'UTF-8'))->toBe('Hélène Dupré');
});

it('pose Reply-To et To', function () {
    [$h] = l2Analyse(l2Composeur()->composer(l2Demande()));
    expect($h['reply-to'])->toBe('reponses@exemple-envoi.test')->and($h['to'])->toBe('cible@exemple.test');
});

it('pose MIME-Version et multipart/alternative', function () {
    [$h] = l2Analyse(l2Composeur()->composer(l2Demande()));
    expect($h['mime-version'])->toBe('1.0')->and($h['content-type'])->toStartWith('multipart/alternative; boundary=');
});

it('pose List-Unsubscribe https ET mailto et le One-Click', function () {
    [$h] = l2Analyse(l2Composeur()->composer(l2Demande()));
    expect($h['list-unsubscribe'])->toContain('<https://exemple-envoi.test/desinscription/abc>')
        ->and($h['list-unsubscribe'])->toContain('<mailto:desinscription@exemple-envoi.test')
        ->and($h['list-unsubscribe-post'])->toBe('List-Unsubscribe=One-Click');
});

it('pose X-Envoi-Jeton', function () {
    [$h] = l2Analyse(l2Composeur()->composer(l2Demande()));
    expect($h['x-envoi-jeton'])->toBe('jeton-test-12345');
});

it('encode le sujet accentué en RFC 2047', function () {
    [$h] = l2Analyse(l2Composeur()->composer(l2Demande(['sujet' => 'Événement spécial'])));
    expect(iconv_mime_decode($h['subject'], 0, 'UTF-8'))->toBe('Événement spécial');
});

it('contient texte ET HTML en UTF-8 avec accents, pied légal et art. 14', function () {
    $msg = l2Composeur()->composer(l2Demande(['variables' => ['prenom' => 'Zoé']]));
    [, $corps] = l2Analyse($msg);
    preg_match('/boundary="([^"]+)"/', $msg, $b);
    $parties = array_values(array_filter(explode('--' . $b[1], $corps), fn ($p) => str_contains($p, 'Content-Type')));
    expect($parties)->toHaveCount(2);
    foreach ($parties as $p) {
        expect($p)->toContain('charset=UTF-8');
    }
    $texte = quoted_printable_decode(explode("\r\n\r\n", $parties[0], 2)[1]);
    $html = quoted_printable_decode(explode("\r\n\r\n", $parties[1], 2)[1]);
    expect($parties[0])->toContain('text/plain')->and($parties[1])->toContain('text/html')
        ->and($texte)->toContain('Bonjour Zoé,')->toContain('Événement à découvrir : café à 10 €.')
        ->toContain('Société Fictive SAS')->toContain('Texte art. 14 fourni')->toContain('https://exemple-envoi.test/desinscription/abc')
        ->and($html)->toContain('Événement')->toContain('Société Fictive SAS')->toContain('Texte art. 14 fourni')
        ->toContain('href="https://exemple-envoi.test/desinscription/abc"');
});

it('utilise la valeur de repli quand la variable est absente', function () {
    $msg = l2Composeur()->composer(l2Demande());
    expect(quoted_printable_decode($msg))->toContain('Bonjour cher contact,');
});

it('relance dans le même fil : In-Reply-To et References', function () {
    [$h] = l2Analyse(l2Composeur()->composer(l2Demande(['inReplyTo' => '<a@exemple-envoi.test>', 'references' => ['<a@exemple-envoi.test>']])));
    expect($h['in-reply-to'])->toBe('<a@exemple-envoi.test>')->and($h['references'])->toBe('<a@exemple-envoi.test>');
    [$premier] = l2Analyse(l2Composeur()->composer(l2Demande()));
    expect($premier)->not->toHaveKey('in-reply-to');
});

it('refuse un saut de ligne dans un en-tête (injection)', function () {
    l2Composeur()->composer(l2Demande(['sujet' => "x\r\nBcc: a@b.test"]));
})->throws(InvalidArgumentException::class);

// ---- Contrôleur : un test par refus -------------------------------------------------

dataset('refus', [
    'pièce jointe' => [fn () => ['piecesJointes' => ['facture.pdf']], 'Pièce jointe'],
    'variable sans repli' => [fn () => ['texte' => 'Bonjour {{prenom}}'], 'sans valeur de repli'],
    'variable repli vide' => [fn () => ['texte' => 'Bonjour {{prenom|}}'], 'sans valeur de repli'],
    'lien axion-ia.com' => [fn () => ['html' => '<a href="https://www.axion-ia.com/x">x</a>'], 'axion-ia.com'],
    'adresse axion-ia.com' => [fn () => ['texte' => 'écrire à jean@axion-ia.com'], 'axion-ia.com'],
    'mot Grenoble' => [fn () => ['texte' => 'Nous sommes à grenoble.'], 'Grenoble'],
    'pixel 1x1' => [fn () => ['html' => '<img src="https://exemple-envoi.test/a.gif" width="1" height="1">'], 'Pixel'],
    'pixel style' => [fn () => ['html' => '<img src="https://exemple-envoi.test/a.gif" style="width:1px;height:1px">'], 'Pixel'],
    'pixel de suivi' => [fn () => ['html' => '<img src="https://exemple-envoi.test/pixel/abc.gif">'], 'Pixel'],
    'lien traqueur' => [fn () => ['html' => '<a href="https://click.tracker.test/c/abc">x</a>'], 'traqueur'],
    'lien redirigé' => [fn () => ['html' => '<a href="https://exemple.test/r?url=https://cible.test">x</a>'], 'traqueur'],
]);

it('refuse avec un motif lisible', function (Closure $o, string $motif) {
    try {
        l2Composeur()->composer(l2Demande($o()));
        $this->fail('Le message aurait dû être refusé.');
    } catch (MessageRefuse $e) {
        expect(implode(' ', $e->motifs))->toContain($motif);
    }
})->with('refus');

it('le contrôleur accepte un contenu propre', function () {
    expect((new ControleurContenu)->analyser(['<p>Bonjour {{a|b}}</p><a href="https://exemple.test/x">x</a>']))->toBe([]);
});

it('le contrôleur refuse une pièce jointe vue dans le MIME brut', function () {
    expect((new ControleurContenu)->analyser(['Content-Disposition: attachment; filename=a.pdf']))->not->toBe([]);
});

// ---- DKIM ---------------------------------------------------------------------------

it('la liste des en-têtes signés DKIM est exactement la liste exigée', function () {
    expect(DkimSigneur::ENTETES_SIGNES)->toBe(['From', 'To', 'Subject', 'Date', 'Message-ID', 'MIME-Version',
        'Content-Type', 'List-Unsubscribe', 'List-Unsubscribe-Post']);
});

it('signe en DKIM avec le sélecteur dédié et la signature se vérifie', function () {
    $pem = l2CleTest();
    $pub = openssl_pkey_get_details(openssl_pkey_get_private($pem))['key'];
    $msg = l2Composeur(new DkimSigneur('exemple-envoi.test', 'crm1', $pem))->composer(l2Demande(['variables' => ['prenom' => 'Zoé']]));
    [$h, $corps, $tete] = l2Analyse($msg);

    $dkim = $h['dkim-signature'];
    preg_match_all('/(\w+)=([^;]*)/', $dkim, $m, PREG_SET_ORDER);
    $tags = array_column($m, 2, 1);
    $tags = array_map(fn ($v) => preg_replace('/\s+/', '', $v), $tags);

    expect($tags['d'])->toBe('exemple-envoi.test')->and($tags['s'])->toBe('crm1')
        ->and($tags['a'])->toBe('rsa-sha256')->and($tags['c'])->toBe('relaxed/relaxed')
        ->and(explode(':', $tags['h']))->toBe(array_map('strtolower', DkimSigneur::ENTETES_SIGNES));

    // body hash
    $bodyHash = base64_encode(hash('sha256', DkimSigneur::canonCorps($corps), true));
    expect($tags['bh'])->toBe($bodyHash);

    // signature : on reconstruit les en-têtes signés depuis le message reçu
    $lignes = [];
    foreach (explode("\r\n", preg_replace('/\r\n[ \t]+/', ' ', explode("\r\n\r\n", $msg, 2)[0])) as $l) {
        [$n, $v] = explode(':', $l, 2);
        $lignes[strtolower($n)] = DkimSigneur::canonEntete($n, $v);
    }
    $aVerifier = '';
    foreach ($tags['h'] ? explode(':', $tags['h']) : [] as $n) {
        $aVerifier .= $lignes[$n] . "\r\n";
    }
    $sansB = preg_replace('/\bb=[^;]*$/', 'b=', preg_replace('/\s+/', ' ', 'DKIM-Signature: ' . $dkim));
    $aVerifier .= DkimSigneur::canonEntete('DKIM-Signature', preg_replace('/^DKIM-Signature:\s*/', '', $sansB));
    $sig = base64_decode($tags['b']);

    expect(openssl_verify($aVerifier, $sig, $pub, OPENSSL_ALGO_SHA256))->toBe(1);

    // altérer un en-tête signé casse la signature
    $altere = str_replace('contact@exemple-envoi.test', 'autre@exemple-envoi.test', $aVerifier);
    expect(openssl_verify($altere, $sig, $pub, OPENSSL_ALGO_SHA256))->toBe(0);
});

it('refuse une clé DKIM illisible', function () {
    new DkimSigneur('exemple-envoi.test', 'crm1', 'pas une clé');
})->throws(InvalidArgumentException::class);
