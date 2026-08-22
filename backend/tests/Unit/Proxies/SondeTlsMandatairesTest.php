<?php

/**
 * C19-011 — LA SONDE DE SANTE DES MANDATAIRES SONDAIT LES YEUX FERMES.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * LE DEFAUT MESURE (2026-08-22)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `WebshareProvider.php:70` et `IPRoyalProvider.php:56` ecrivaient tous deux :
 *
 *     Http::withOptions(['proxy' => $endpoint->toProxyUrl(), 'verify' => false])
 *         ->get('https://api.ipify.org?format=json')
 *
 * Les deux SEULES sondes de sante du sous-systeme mandataire desactivaient donc
 * la verification du certificat TLS de leur propre appel. Consequence exacte :
 * le mandataire peut rendre N'IMPORTE QUELLE reponse en se faisant passer pour
 * `api.ipify.org`, et `healthCheck()` la valide des qu'elle contient une IP bien
 * formee. Le seul instrument capable de dire « ce point de sortie ment » etait
 * precisement celui qu'on ne pouvait pas croire.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE CETTE GARDE VERIFIE — ET COMMENT
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Elle ne lit pas le code : elle joue les deux `healthCheck()` sous `Http::fake`
 * et LIT L'OPTION GUZZLE `verify` reellement transmise. Une garde qui se
 * contenterait de chercher la chaine `'verify' => false` dans le source
 * passerait au vert le jour ou quelqu'un ecrit `'verify' => $x` avec `$x` faux.
 *
 *  1. Defaut du depot : `verify` vaut `true` chez les DEUX fournisseurs.
 *  2. Le drapeau par fournisseur ferme l'oeil d'UN SEUL : c'est ce que le
 *     constat demande (« fournisseur par fournisseur »), et c'est le temoin qui
 *     prouve que le point 1 ne vient pas d'une valeur figee.
 *  3. Une sonde qui tourne sans verification le JOURNALISE, et son journal ne
 *     porte PAS le mot de passe du mandataire (`toProxyUrl()` l'y mettrait).
 *
 * ⚠️ CE QU'ELLE NE VOIT PAS, dit franchement : elle ne juge que ces deux
 * methodes. Le controle d'enumeration en fin de fichier ratisse `app/Services`
 * pour le `'verify' => false` en dur, mais il lit du TEXTE — une valeur fausse
 * passee par variable lui echappe.
 */

use App\Data\Proxies\ProxyEndpointData;
use App\Services\Proxies\IPRoyalProvider;
use App\Services\Proxies\WebshareProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Le point de sortie sonde. Le mot de passe est distinctif : on le traque. */
const MOT_DE_PASSE_MANDATAIRE = 'mdp-mandataire-a-ne-jamais-journaliser';

function pointDeSortie(string $fournisseur): ProxyEndpointData
{
    return new ProxyEndpointData(
        provider: $fournisseur,
        type: 'datacenter',
        zone: 'eu',
        host: 'sortie.exemple.test',
        port: 8080,
        username: 'compte-sonde',
        password: MOT_DE_PASSE_MANDATAIRE,
    );
}

/**
 * Joue une sonde et rend les options Guzzle REELLEMENT transmises.
 *
 * `Http::fake()` appelle son rappel avec `($request, $options)` : `$options`
 * est le tableau que Guzzle recevra, `verify` compris. C'est la seule facon
 * d'observer cette option sans emettre de requete.
 *
 * @return array<string, mixed>
 */
function optionsDeLaSonde(callable $sonde): array
{
    $vues = [];
    Http::fake(function ($request, $options) use (&$vues) {
        $vues[] = $options;

        return Http::response(['ip' => '203.0.113.4'], 200);
    });

    $sonde();

    // `expect($vues)->not->toBeEmpty($msg)` : le message ne passe pas de facon
    // sure a travers `->not` selon les versions de Pest. On teste le booleen.
    expect($vues !== [])->toBeTrue(
        'La sonde n a emis AUCUNE requete : la garde ne mesurerait rien. '
        . 'Verifier que healthCheck() appelle toujours api.ipify.org.',
    );

    return $vues[0];
}

test('C19-011 — par defaut, les DEUX sondes verifient le certificat TLS', function () {
    $webshare = optionsDeLaSonde(fn () => (new WebshareProvider)->healthCheck(pointDeSortie('webshare')));
    $iproyal = optionsDeLaSonde(fn () => (new IPRoyalProvider)->healthCheck(pointDeSortie('iproyal')));

    expect($webshare['verify'] ?? null)->toBeTrue(
        'WebshareProvider::healthCheck() sonde sans verifier le certificat : un mandataire '
        . 'peut se faire passer pour api.ipify.org et se declarer sain. Remettre '
        . "'verify' => \$this->verifierTlsDeLaSonde('webshare', \$endpoint).",
    );
    expect($iproyal['verify'] ?? null)->toBeTrue(
        'IPRoyalProvider::healthCheck() sonde sans verifier le certificat (meme defaut que '
        . 'Webshare, patron A-011 : le correctif n a pas ete porte au site jumeau). Remettre '
        . "'verify' => \$this->verifierTlsDeLaSonde('iproyal', \$endpoint).",
    );

    // TEMOIN — la sonde passe bien PAR le mandataire. Sans lui, une sonde qui
    // aurait perdu son `proxy` passerait les deux assertions ci-dessus tout en
    // ne mesurant plus rien du point de sortie.
    // ⚠️ `toContain($aiguille, $message)` est VARIADIQUE en Pest : le message y
    // deviendrait une seconde aiguille. D'ou le `str_contains` explicite.
    expect(str_contains((string) ($webshare['proxy'] ?? ''), 'sortie.exemple.test:8080'))->toBeTrue(
        'La sonde ne passe plus PAR le mandataire : elle ne mesure donc plus le point de sortie, '
        . 'et les assertions sur `verify` ci-dessus ne prouveraient plus rien.',
    );
});

test('C19-011 — TEMOIN : le drapeau ferme l oeil d UN SEUL fournisseur', function () {
    // Le constat exige que la bascule se fasse fournisseur par fournisseur :
    // certains mandataires HTTPS presentent un certificat d interception, et
    // remettre la verification partout d un coup ferait passer des points de
    // sortie sains pour morts — donc `pickEndpoint()` leve, donc la collecte
    // s arrete. Ce temoin prouve aussi que le test precedent ne lit pas une
    // valeur figee.
    config()->set('crm.proxies.verify_tls.webshare', false);

    $webshare = optionsDeLaSonde(fn () => (new WebshareProvider)->healthCheck(pointDeSortie('webshare')));
    $iproyal = optionsDeLaSonde(fn () => (new IPRoyalProvider)->healthCheck(pointDeSortie('iproyal')));

    expect($webshare['verify'] ?? null)->toBeFalse(
        'Le drapeau `crm.proxies.verify_tls.webshare` doit pouvoir fermer l oeil du fournisseur '
        . 'qui l exige, sinon le correctif arrete la collecte au lieu de la securiser.',
    );
    expect($iproyal['verify'] ?? null)->toBeTrue(
        'Le drapeau d UN fournisseur ne doit PAS desactiver la verification de l AUTRE : '
        . 'lire `crm.proxies.verify_tls.<fournisseur>`, pas une cle globale.',
    );
});

test('C19-011 — une sonde sans verification le DIT, et sans divulguer le mot de passe', function () {
    config()->set('crm.proxies.verify_tls.iproyal', false);

    $journal = [];
    $contextes = [];
    Log::listen(function ($message) use (&$journal, &$contextes) {
        $journal[] = $message->message;
        $contextes[] = json_encode($message->context);
    });

    optionsDeLaSonde(fn () => (new IPRoyalProvider)->healthCheck(pointDeSortie('iproyal')));

    $tout = implode("\n", $journal);
    expect(str_contains($tout, 'tls_non_verifie'))->toBeTrue(
        'Une verification TLS desactivee doit laisser une trace nommant le fournisseur et la cle, '
        . 'sinon elle redevient l etat par defaut invisible qu on repare. Journal lu : ' . $tout,
    );

    // `toProxyUrl()` porte `user:password@host:port`. Le journaliser echangerait
    // un defaut de sonde contre une fuite d identifiants.
    $toutLeContexte = implode("\n", $contextes);
    expect(str_contains($toutLeContexte, MOT_DE_PASSE_MANDATAIRE))->toBeFalse(
        'Le journal de la sonde porte le mot de passe du mandataire : journaliser host:port, '
        . 'JAMAIS toProxyUrl(). Contexte lu : ' . $toutLeContexte,
    );
});

test('C19-011 — plus aucun `verify => false` EN DUR sous app/Services', function () {
    // Enumeration, pas echantillon : le patron A-011 de ce depot est « le
    // correctif existe, il n a pas ete porte au site jumeau ». Deux fournisseurs
    // aujourd hui, un troisieme demain.
    //
    // ⚠️ `RecursiveDirectoryIterator` TRONQUE le parcours sur le montage Docker
    // de ce depot (mesure : 14 fichiers sur 56). On descend donc a la main.
    $racine = base_path('app/Services');

    $lister = function (string $dossier) use (&$lister): array {
        $trouves = [];
        foreach (scandir($dossier) ?: [] as $entree) {
            if ($entree === '.' || $entree === '..') {
                continue;
            }
            $chemin = $dossier . DIRECTORY_SEPARATOR . $entree;
            if (is_dir($chemin)) {
                $trouves = array_merge($trouves, $lister($chemin));
            } elseif (str_ends_with($entree, '.php')) {
                $trouves[] = $chemin;
            }
        }

        return $trouves;
    };

    $fichiers = $lister($racine);

    // Le parcours lui-meme est mesure : une enumeration qui ne lit rien
    // certifierait l absence de tout ce qu elle n a pas ouvert.
    expect(count($fichiers) > 20)->toBeTrue(
        'Le parcours de app/Services n a lu que ' . count($fichiers) . ' fichiers : il est tronque, '
        . 'et cette garde ne prouve donc rien. Verifier le scandir recursif.',
    );

    // ⚠️ On TOKENISE et on jette les commentaires avant de chercher. Les trois
    // fichiers reparés CITENT le defaut dans leur commentaire (« `'verify' => false`
    // en dur rendait cette sonde… ») : une recherche sur le texte brut rougirait
    // sur sa propre explication, et la seule facon de la faire taire serait
    // d'effacer l'explication. Une garde qui punit le commentaire est une garde
    // qui fabrique du code muet.
    $codeSeul = function (string $source): string {
        $code = '';
        foreach (token_get_all($source) as $jeton) {
            if (is_array($jeton)) {
                if ($jeton[0] === T_COMMENT || $jeton[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $jeton[1];
            } else {
                $code .= $jeton;
            }
        }

        return $code;
    };

    $fautifs = [];
    foreach ($fichiers as $fichier) {
        $code = $codeSeul((string) file_get_contents($fichier));
        if (preg_match('/[\'"]verify[\'"]\s*=>\s*false/i', $code) === 1) {
            $fautifs[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $fichier);
        }
    }

    expect($fautifs)->toBe(
        [],
        'Verification TLS desactivee EN DUR dans : ' . implode(', ', $fautifs)
        . '. Passer par un drapeau de configuration par fournisseur (cf. trait VerificationTlsSonde) '
        . 'plutot que par un `false` que personne ne peut voir ni journaliser.',
    );
});
