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
use App\Envoi\Message\SignatureDkim;

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
    return new ComposeurMessage('exemple-envoi.test', $d, signeDkim: $d === null ? SignatureDkim::Serveur : SignatureDkim::Crm);
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
    preg_match('/boundary="([^"]+)"/', $msg, $b);
    $parties = array_values(array_filter(explode('--' . $b[1], explode("\r\n\r\n", $msg, 2)[1]), fn ($p) => str_contains($p, 'Content-Type')));
    expect($parties)->toHaveCount(2);
    foreach ($parties as $p) {
        expect(quoted_printable_decode(explode("\r\n\r\n", $p, 2)[1]))->toContain('Bonjour cher contact,');
    }
});

it('relance dans le même fil : In-Reply-To et References', function () {
    [$h] = l2Analyse(l2Composeur()->composer(l2Demande(['inReplyTo' => '<a@exemple-envoi.test>', 'references' => ['<a@exemple-envoi.test>']])));
    expect($h['in-reply-to'])->toBe('<a@exemple-envoi.test>')->and($h['references'])->toBe('<a@exemple-envoi.test>');
    [$premier] = l2Analyse(l2Composeur()->composer(l2Demande()));
    expect($premier)->not->toHaveKey('in-reply-to');
});

it('sujet ASCII avec virgule : aucun guillemet, valeur brute', function () {
    $msg = l2Composeur()->composer(l2Demande());
    [$h, , $tete] = l2Analyse($msg);
    expect($h['subject'])->toBe('Bonjour cher contact, une proposition')->and($h['subject'])->not->toContain('"');
    $sujet = l2Composeur()->composer(l2Demande(['sujet' => 'Re: (offre) <a@b> ; [x] "ok"']));
    [$h2] = l2Analyse($sujet);
    expect($h2['subject'])->toBe('Re: (offre) <a@b> ; [x] "ok"');
});

it('plie les en-têtes > 78 caractères et n\'en dépasse jamais 998', function () {
    $long = implode(' ', array_fill(0, 200, 'proposition')); // ~2 400 car.
    $refs = array_map(fn ($i) => '<' . str_repeat('x', 30) . $i . '@exemple-envoi.test>', range(1, 40));
    foreach ([['sujet' => $long], ['sujet' => str_repeat('a', 1200)], ['inReplyTo' => $refs[0], 'references' => $refs]] as $o) {
        $msg = l2Composeur(new DkimSigneur('exemple-envoi.test', 'crm1', l2CleTest()))->composer(l2Demande($o));
        $tete = explode("\r\n\r\n", $msg, 2)[0];
        foreach (explode("\r\n", $tete) as $ligne) {
            expect(strlen($ligne))->toBeLessThanOrEqual(78);
        }
        [$h] = l2Analyse($msg);
        if (isset($o['references'])) {
            expect(explode(' ', $h['references']))->toBe($refs);
        } else {
            expect(iconv_mime_decode($h['subject'], 0, 'UTF-8'))->toBe($o['sujet']);
        }
    }
});

it('refuse > ou saut de ligne dans les liens de désinscription', function (array $o) {
    l2Composeur()->composer(l2Demande($o));
})->with([
    [['urlDesinscription' => 'https://exemple-envoi.test/a>, <https://evil.test/']],
    [['mailtoDesinscription' => 'mailto:a@exemple-envoi.test>, <mailto:b@evil.test']],
    [['urlDesinscription' => "https://exemple-envoi.test/a\nBcc: x@y.test"]],
    [['mailtoDesinscription' => "mailto:a@exemple-envoi.test\r\nBcc: x@y.test"]],
])->throws(InvalidArgumentException::class);

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
    'img display:none' => [fn () => ['html' => '<img src="https://exemple-envoi.test/a.gif" style="display:none">'], 'Pixel'],
    'img visibility:hidden' => [fn () => ['html' => '<img src="https://exemple-envoi.test/a.png" style="visibility:hidden">'], 'Pixel'],
    'img opacity:0' => [fn () => ['html' => '<img src="https://exemple-envoi.test/a.png" style="opacity:0">'], 'Pixel'],
    'img max-height:0' => [fn () => ['html' => '<img src="https://exemple-envoi.test/a.png" style="max-height:0">'], 'Pixel'],
    'img !important' => [fn () => ['html' => '<img src="https://exemple-envoi.test/a.png" style="width:1px !important;height:1px !important">'], 'Pixel'],
    'img 2x2' => [fn () => ['html' => '<img src="https://exemple-envoi.test/a.png" width="2" height="2">'], 'Pixel'],
    'img distante taille normale' => [fn () => ['html' => '<img src="https://exemple-envoi.test/logo.png" width="300" height="100">'], 'Pixel'],
    'img sans taille' => [fn () => ['html' => '<img src="http://exemple-envoi.test/logo.png">'], 'Pixel'],
    'img data:' => [fn () => ['html' => '<img src="data:image/png;base64,AAAA">'], 'Pixel'],
    'style url(' => [fn () => ['html' => '<div style="background:url(https://exemple-envoi.test/a.png)">x</div>'], 'Pixel'],
    'IMG majuscules' => [fn () => ['html' => '<IMG SRC="https://exemple-envoi.test/a.png">'], 'Pixel'],
    'balise link' => [fn () => ['html' => '<link rel="stylesheet" href="https://exemple-envoi.test/a.css"><p>x</p>'], 'Pixel'],
    'attribut srcset' => [fn () => ['html' => '<p srcset="https://exemple-envoi.test/a.png 1x">x</p>'], 'Pixel'],
    'attribut background' => [fn () => ['html' => '<div background="https://exemple-envoi.test/a.png">x</div>'], 'Pixel'],
    'entité numérique sans point-virgule' => [fn () => ['html' => '<div style="background:u&#114l(https://exemple-envoi.test/p.gif)">x</div>'], 'Pixel'],
    'entité hexadécimale sans point-virgule' => [fn () => ['html' => '<p>u&#x72l(https://exemple-envoi.test/p.gif)</p>'], 'entité'],
    'échappement CSS url' => [fn () => ['html' => '<div style="background:\\75rl(https://exemple-envoi.test/p.gif)">x</div>'], 'Pixel'],
    'échappement CSS @import' => [fn () => ['html' => '<div style="@\\69mport \'https://exemple-envoi.test/a.css\'">x</div>'], 'Pixel'],
    'image-set' => [fn () => ['html' => '<div style="background:image-set(\'https://exemple-envoi.test/a.png\' 1x)">x</div>'], 'Pixel'],
    '-webkit-image-set' => [fn () => ['html' => '<div style="background:-webkit-image-set(\'https://exemple-envoi.test/a.png\' 1x)">x</div>'], 'Pixel'],
    'VML v:image' => [fn () => ['html' => '<v:image src="https://exemple-envoi.test/a.png"></v:image>'], 'Pixel'],
    'VML v:fill' => [fn () => ['html' => '<v:fill src="https://exemple-envoi.test/a.png"/>'], 'Pixel'],
    'SVG feImage' => [fn () => ['html' => '<svg><feImage href="https://exemple-envoi.test/a.png"/></svg>'], 'Pixel'],
    'SVG use' => [fn () => ['html' => '<p><use href="https://exemple-envoi.test/a.svg#x"/></p>'], 'Pixel'],
    'commentaire conditionnel Outlook' => [fn () => ['html' => '<!--[if mso]><v:image src="https://exemple-envoi.test/a.png"/><![endif]-->'], 'HTML refusé'],
    'balise style' => [fn () => ['html' => '<style>p{color:red}</style><p>x</p>'], 'Pixel'],
    'attribut style seul' => [fn () => ['html' => '<p style="color:red">x</p>'], 'Pixel'],
    'attribut class' => [fn () => ['html' => '<p class="a">x</p>'], 'HTML refusé'],
    'href javascript' => [fn () => ['html' => '<a href="javascript:alert(1)">x</a>'], 'HTML refusé'],
    'href http non sécurisé' => [fn () => ['html' => '<a href="http://exemple.test/x">x</a>'], 'HTML refusé'],
    'href // sans schéma' => [fn () => ['html' => '<a href="//exemple.test/x">x</a>'], 'HTML refusé'],
    'href data:' => [fn () => ['html' => '<a href="data:text/html;base64,AAAA">x</a>'], 'HTML refusé'],
    'balise iframe' => [fn () => ['html' => '<iframe src="https://exemple.test"></iframe>'], 'Pixel'],
    'balise script' => [fn () => ['html' => '<script>1</script>'], 'Pixel'],
    'body background' => [fn () => ['html' => '<body background="https://exemple-envoi.test/a.png"><p>x</p>'], 'Pixel'],
    'balise non fermée' => [fn () => ['html' => '<p>x'], 'HTML refusé'],
    'esperluette nue' => [fn () => ['html' => '<p>R&D</p>'], 'entité'],
    'axion-ia entité sans ;' => [fn () => ['html' => '<p>axion-ia&#46com</p>'], 'axion-ia.com'],
    'axion-ia %2E' => [fn () => ['texte' => 'axion-ia%2Ecom'], 'axion-ia.com'],
    'axion-ia point idéographique' => [fn () => ['texte' => 'jean@axion-ia。com'], 'axion-ia.com'],
    'axion-ia largeur nulle' => [fn () => ['texte' => "axion-ia\u{200B}.com"], 'axion-ia.com'],
    'Grenoble soft-hyphen' => [fn () => ['texte' => "Gre\u{00AD}noble"], 'Grenoble'],
    'Grenoble largeur nulle' => [fn () => ['texte' => "Gre\u{200B}no\u{2060}ble\u{FEFF}"], 'Grenoble'],
    'Grenoble entité sans ;' => [fn () => ['texte' => 'Gren&#111ble'], 'Grenoble'],
    'traqueur // sans schéma' => [fn () => ['html' => '<a href="//bit.ly/x">x</a>'], 'traqueur'],
    'traqueur antislash' => [fn () => ['texte' => 'voir https:\\\\bit.ly\\x'], 'traqueur'],
    'traqueur point final' => [fn () => ['html' => '<a href="https://bit.ly./x">x</a>'], 'traqueur'],
    'traqueur majuscules' => [fn () => ['html' => '<a href="https://BIT.LY/x">x</a>'], 'traqueur'],
    'Grenoble bidi LRE' => [fn () => ['texte' => "Gre\u{202A}noble"], 'Grenoble'],
    'Grenoble isolat bidi' => [fn () => ['texte' => "Gre\u{2066}noble"], 'Grenoble'],
    'Grenoble sélecteur de variante' => [fn () => ['texte' => "Gre\u{FE0F}noble"], 'Grenoble'],
    'Grenoble caractère tag' => [fn () => ['texte' => "Gre\u{E0020}noble"], 'Grenoble'],
    'Grenoble marque arabe' => [fn () => ['texte' => "Gre\u{061C}noble"], 'Grenoble'],
    'Grenoble remplisseur hangul' => [fn () => ['texte' => "Gre\u{3164}noble"], 'Grenoble'],
    'Grenoble HTML entité bidi' => [fn () => ['html' => '<p>Gre&#x202A;noble</p>'], 'Grenoble'],
    'axion-ia isolat bidi texte brut' => [fn () => ['texte' => "axion-ia\u{2066}.com"], 'axion-ia.com'],
    'entité numérique nulle après entité valide' => [fn () => ['html' => '<p>&#65; &#0;</p>'], 'entité numérique invalide'],
    'variable dans nom affiché' => [fn () => ['nomAffiche' => 'Nom {{x|y}}'], '{{'],
    'variable dans lien désinscription' => [fn () => ['urlDesinscription' => 'https://exemple-envoi.test/{{x|y}}'], '{{'],
    'https sans hôte' => [fn () => ['urlDesinscription' => 'https://'], 'https'],
    'mailto vide' => [fn () => ['mailtoDesinscription' => 'mailto:'], 'mailto'],
    'Grenoble en entité' => [fn () => ['texte' => 'Nous sommes à Gren&#111;ble.'], 'Grenoble'],
    'Grenoble coupé par balises' => [fn () => ['html' => '<p>Gre<b></b>noble</p>'], 'Grenoble'],
    'axion-ia entité' => [fn () => ['html' => '<a href="https://axion-ia&#46;com/x">x</a>'], 'axion-ia.com'],
    'redirection &amp;' => [fn () => ['html' => '<a href="https://exemple.test/r?x=1&amp;url=https://cible.test">x</a>'], 'traqueur'],
    'redirection q=' => [fn () => ['html' => '<a href="https://exemple.test/r?q=https://cible.test">x</a>'], 'traqueur'],
    'redirection q= encodée' => [fn () => ['html' => '<a href="https://exemple.test/r?q=https%3A%2F%2Fcible.test">x</a>'], 'traqueur'],
    'lien traqueur' => [fn () => ['html' => '<a href="https://click.tracker.test/c/abc">x</a>'], 'traqueur'],
    'lien redirigé' => [fn () => ['html' => '<a href="https://exemple.test/r?url=https://cible.test">x</a>'], 'traqueur'],
]);

it('refuse une URL de désinscription de plus de 900 caractères et accepte la limite', function () {
    $base = 'https://exemple-envoi.test/';
    expect(fn () => l2Composeur()->composer(l2Demande(['urlDesinscription' => $base . str_repeat('a', 901 - strlen($base))])))
        ->toThrow(InvalidArgumentException::class);
    $msg = l2Composeur(new DkimSigneur('exemple-envoi.test', 'crm1', l2CleTest()))
        ->composer(l2Demande(['urlDesinscription' => $base . str_repeat('a', 900 - strlen($base))]));
    foreach (explode("\r\n", explode("\r\n\r\n", $msg, 2)[0]) as $ligne) {
        expect(strlen($ligne))->toBeLessThan(998);
    }
});

it('plie List-Unsubscribe long sans le casser', function () {
    $u = 'https://exemple-envoi.test/' . str_repeat('b', 800);
    [$h] = l2Analyse(l2Composeur()->composer(l2Demande(['urlDesinscription' => $u])));
    expect($h['list-unsubscribe'])->toStartWith('<' . $u . '>, <mailto:');
});

it('refuse avec un motif lisible', function (Closure $o, string $motif) {
    try {
        l2Composeur()->composer(l2Demande($o()));
        $this->fail('Le message aurait dû être refusé.');
    } catch (MessageRefuse $e) {
        expect(implode(' ', $e->motifs))->toContain($motif);
    }
})->with('refus');

it('le contrôleur accepte un contenu propre', function () {
    expect((new ControleurContenu)->analyser([], [], ['<p>Bonjour <b>{{a|b}}</b></p><ul><li><a href="https://exemple.test/x?a=1&amp;b=2">x</a></li></ul><br><hr/><a href="mailto:a@exemple.test">m</a>']))->toBe([]);
});

it('le contrôleur ne refuse pas du texte brut avec chevrons ou &', function () {
    expect((new ControleurContenu)->analyser(['R&D < 5 % et a>b']))->toBe([]);
});

it('le contrôleur n\'a pas de faux positif sur open.spotify.com ni sur un lien simple', function () {
    expect((new ControleurContenu)->analyser([], [], ['<a href="https://open.spotify.com/track/abc?si=1">x</a> <a href="https://links.exemple.test/a">y</a>']))->toBe([]);
});

it('le contrôleur refuse une pièce jointe vue dans le MIME brut', function () {
    expect((new ControleurContenu)->analyser(['Content-Disposition: attachment; filename=a.pdf']))->not->toBe([]);
});

// ---- DKIM ---------------------------------------------------------------------------

it('la liste des en-têtes signés DKIM est exactement la liste exigée', function () {
    expect(DkimSigneur::ENTETES_SIGNES)->toBe(['From', 'Reply-To', 'To', 'Subject', 'Date', 'Message-ID', 'MIME-Version',
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
        ->and(explode(':', $tags['h']))->toBe(array_map('strtolower', [...DkimSigneur::ENTETES_SIGNES, ...DkimSigneur::ENTETES_SURSIGNES]));

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
    $vus = [];
    foreach ($tags['h'] ? explode(':', $tags['h']) : [] as $n) {
        if (isset($vus[$n])) {
            continue;   // sur-signature (RFC 6376 § 5.4.2) : 2e occurrence absente du message = vide
        }
        $vus[$n] = true;
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

it('DKIM : vérification indépendante sur message figé (canonisation écrite à la main)', function () {
    $pem = l2CleTest();
    $pub = openssl_pkey_get_details(openssl_pkey_get_private($pem))['key'];
    $entetes = [
        'From' => 'A <contact@exemple-envoi.test>', 'Reply-To' => 'r@exemple-envoi.test', 'To' => 'cible@exemple.test', 'Subject' => 'Sujet  de   test',
        'Date' => 'Mon, 02 Nov 2026 10:00:00 +0000', 'Message-ID' => '<fixe@exemple-envoi.test>', 'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8', 'List-Unsubscribe' => '<https://exemple-envoi.test/u>',
        'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
    ];
    $corps = "Ligne un  \r\nLigne   deux\r\n\r\n\r\n";
    $ligne = (new DkimSigneur('exemple-envoi.test', 'crm1', $pem))->signer($entetes, $corps, 1780000000);

    // Valeurs figées, calculées hors du code testé : corps canonisé = « Ligne un\r\nLigne deux\r\n ».
    expect(base64_encode(hash('sha256', "Ligne un\r\nLigne deux\r\n", true)))
        ->toBe('igz4sVfZ9caY86e4xaF+p7WfJZNye3GseYFQ/wC1vaU=');
    $unfold = preg_replace('/\r\n[ \t]+/', ' ', $ligne);
    preg_match('/ bh=([^;]+);/', $unfold, $bh);
    expect($bh[1])->toBe('igz4sVfZ9caY86e4xaF+p7WfJZNye3GseYFQ/wC1vaU=');
    preg_match('/ b=(.*)$/', $unfold, $b);
    $sansB = substr($unfold, strlen('DKIM-Signature: '), -strlen($b[1]));
    $attendu = "from:A <contact@exemple-envoi.test>\r\nreply-to:r@exemple-envoi.test\r\nto:cible@exemple.test\r\nsubject:Sujet de test\r\n"
        . "date:Mon, 02 Nov 2026 10:00:00 +0000\r\nmessage-id:<fixe@exemple-envoi.test>\r\nmime-version:1.0\r\n"
        . "content-type:text/plain; charset=UTF-8\r\nlist-unsubscribe:<https://exemple-envoi.test/u>\r\n"
        . "list-unsubscribe-post:List-Unsubscribe=One-Click\r\ndkim-signature:" . preg_replace('/\s+/', ' ', trim($sansB));
    expect(openssl_verify($attendu, base64_decode(preg_replace('/\s+/', '', $b[1])), $pub, OPENSSL_ALGO_SHA256))->toBe(1);
});

it('DKIM : refuse un d= différent du domaine d\'envoi du message', function () {
    $s = new DkimSigneur('autre-domaine.test', 'crm1', l2CleTest());
    l2Composeur($s)->composer(l2Demande());
})->throws(InvalidArgumentException::class);

it('DKIM : le signeur refuse un From d\'un autre domaine', function () {
    $e = ['From' => 'A <x@autre.test>', 'Reply-To' => 'r@b.test', 'To' => 'a@b.test', 'Subject' => 's', 'Date' => 'd', 'Message-ID' => '<m@exemple-envoi.test>',
        'MIME-Version' => '1.0', 'Content-Type' => 'text/plain', 'List-Unsubscribe' => '<https://x.test>', 'List-Unsubscribe-Post' => 'x'];
    (new DkimSigneur('exemple-envoi.test', 'crm1', l2CleTest()))->signer($e, "a\r\n");
})->throws(InvalidArgumentException::class);

it('DKIM : refuse une clé RSA de moins de 2048 bits', function () {
    $k = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($k, $pem);
    new DkimSigneur('exemple-envoi.test', 'crm1', $pem);
})->throws(InvalidArgumentException::class);

it('refuse une clé DKIM illisible', function () {
    new DkimSigneur('exemple-envoi.test', 'crm1', 'pas une clé');
})->throws(InvalidArgumentException::class);

it('refuse un contenu dont le décodage % produit de l\'UTF-8 invalide (contrôles jamais aveugles)', function (array $o) {
    l2Composeur()->composer(l2Demande($o));
})->throws(MessageRefuse::class)->with([
    'Grenoble %FF' => [['texte' => 'Bonjour Grenoble %FF']],
    'axion-ia.com %C3' => [['texte' => 'Visitez axion-ia.com %C3']],
    'bit.ly %FF' => [['html' => '<p><a href="https://bit.ly/x">x</a> %FF</p>']],
    '20%DE Grenoble' => [['texte' => 'remise 20%DE avec Grenoble']],
    'variable Grenoble %FF' => [['texte' => 'Bonjour {{v|x}}', 'variables' => ['v' => 'Grenoble %FF']]],
    'html Grenoble %FF lien' => [['html' => '<p>Grenoble %FF <a href="https://bit.ly/x">x</a></p>']],
]);

it('accepte toujours les contenus légitimes', function (string $texte) {
    $msg = l2Composeur()->composer(l2Demande(['texte' => $texte]));
    expect($msg)->toBeString();
})->with([
    'R&D < 5 %' => ['R&D < 5 %'],
    'open.spotify.com' => ['Écoutez open.spotify.com/track/1'],
    'entité' => ['?a=1&amp;b=2'],
    'vrai 20 %' => ['Remise de 20 % sur tout'],
]);

it('refuse un en-tête impossible à plier (inReplyTo de 1000 caractères)', function () {
    ComposeurMessage::plier('In-Reply-To: ' . str_repeat('x', 1000));
})->throws(MessageRefuse::class);

it('refuse via le composeur un inReplyTo de 1000 caractères', function () {
    l2Composeur()->composer(l2Demande(['inReplyTo' => '<' . str_repeat('x', 1000) . '@exemple-envoi.test>']));
})->throws(MessageRefuse::class);
