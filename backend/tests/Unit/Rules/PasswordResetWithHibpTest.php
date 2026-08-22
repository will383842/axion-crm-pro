<?php

use App\Services\Auth\HibpChecker;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Cache::flush());

test('HIBP integration : password "password" est compromis', function () {
    $body = "1E4C9B93F3F0682250B6CF8331B7EE68FD8:9659365\r\n";
    $mock = new MockHandler([new Response(200, [], $body)]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $checker = new HibpChecker($client);

    expect($checker->isBreached('password'))->toBeTrue();
});

test('HIBP intégration : password long custom est sain', function () {
    // SHA1 d'un password complexe : on s'attend à ce qu'il ne soit pas dans la liste
    $mock = new MockHandler([new Response(200, [], "OTHERSUFFIX:1\r\n")]);
    $client = new Client(['handler' => HandlerStack::create($mock)]);
    $checker = new HibpChecker($client);

    expect($checker->isBreached('Some-Long-and-Unique-Password-9876!@#'))->toBeFalse();
});

/**
 * H45-003 (S2) — CE TEST N'AFFIRMAIT RIEN DE CE QUE SON NOM PROMET.
 *
 * Il se terminait sur `expect(true)->toBeTrue();`, précédé de son propre aveu :
 * « Vu qu'on n'a plus de mock, on ne peut tester ça directement. On valide juste
 * qu'on n'a pas crashé. » Le préfixe de cache pouvait disparaître — ou devenir
 * le mot de passe entier, ce qui rejouerait un appel HIBP par mot de passe — sans
 * qu'une seule assertion ne bouge.
 *
 * Ce que la garde mesure désormais, en trois temps :
 *   1. deux préfixes distincts ⇒ deux entrées de cache distinctes, chacune
 *      portant SA réponse (une clé partagée servirait le mauvais corps) ;
 *   2. la clé est bien le préfixe de 5 caractères et pas le sha1 entier — c'est
 *      la k-anonymité sur laquelle repose ce service ;
 *   3. un TROISIÈME mot de passe, différent mais de MÊME préfixe sha1, est servi
 *      depuis le cache. La file de réponses simulées est vide : un cache indexé
 *      autrement partirait sur le réseau et rendrait `null`. C'est ce temps-là
 *      qui prouve que le préfixe, et lui seul, est la clé.
 */
test('H45-003 : HIBP indexe son cache sur les 5 premiers caracteres du sha1, et sur rien d\'autre', function () {
    Cache::flush();

    $motA = 'different-password-A';
    $motB = 'different-password-B';
    // Jumeau de préfixe trouvé par recherche exhaustive le 2026-08-22 :
    // sha1('jumeau-de-prefixe-1436306') et sha1('different-password-A')
    // commencent tous deux par 1348B et divergent dès le 6e caractère.
    $motJumeauDeA = 'jumeau-de-prefixe-1436306';

    $sha1A = strtoupper(sha1($motA));
    $sha1B = strtoupper(sha1($motB));
    $sha1J = strtoupper(sha1($motJumeauDeA));

    $prefixeA = substr($sha1A, 0, 5);
    $prefixeB = substr($sha1B, 0, 5);

    // Le jeu d'essai doit tenir avant tout le reste : sans cela la garde
    // mesurerait autre chose que ce qu'elle annonce.
    expect($prefixeA !== $prefixeB)->toBeTrue(
        "Jeu d'essai cassé : `{$motA}` et `{$motB}` partagent le préfixe {$prefixeA}. "
        . 'Geste : choisir deux mots de passe dont les sha1 diffèrent sur les 5 premiers caractères.',
    );
    expect(substr($sha1J, 0, 5) === $prefixeA && $sha1J !== $sha1A)->toBeTrue(
        "Jeu d'essai cassé : `{$motJumeauDeA}` ({$sha1J}) n'est plus un jumeau de préfixe de "
        . "`{$motA}` ({$sha1A}). Geste : rechercher un nouveau jumeau (sha1 partageant les 5 "
        . 'premiers caractères) plutôt que de retirer le 3e temps de cette garde.',
    );

    // Chaque réponse simulée porte le suffixe RÉEL de son mot de passe : on
    // vérifie ainsi que c'est bien le corps de sa propre entrée qui est relu.
    $corpsA = "PADDINGSANSRAPPORT0000000000000000:0\r\n" . substr($sha1A, 5) . ":42\r\n";
    $corpsB = substr($sha1B, 5) . ":7\r\n";

    $mock = new MockHandler([new Response(200, [], $corpsA), new Response(200, [], $corpsB)]);
    $checker = new HibpChecker(new Client(['handler' => HandlerStack::create($mock)]));

    expect($checker->getBreachCount($motA) === 42)->toBeTrue(
        "La réponse simulée du préfixe {$prefixeA} n'a pas été lue (attendu 42). "
        . 'Geste : vérifier le parsing de `getBreachCount` avant de toucher au cache.',
    );
    expect($checker->getBreachCount($motB) === 7)->toBeTrue(
        "La réponse simulée du préfixe {$prefixeB} n'a pas été lue (attendu 7). "
        . 'Geste : vérifier que chaque préfixe déclenche bien son propre appel.',
    );

    // 1) Deux entrées, une par préfixe, chacune avec SON corps.
    expect(Cache::get('hibp:range:' . $prefixeA) === $corpsA)->toBeTrue(
        "L'entrée de cache `hibp:range:{$prefixeA}` ne porte pas la réponse de ce préfixe. "
        . 'Geste : `HibpChecker::getBreachCount` doit mémoriser le corps HIBP sous '
        . '`hibp:range:<5 premiers caractères du sha1 majuscule>` — H45-003.',
    );
    expect(Cache::get('hibp:range:' . $prefixeB) === $corpsB)->toBeTrue(
        "L'entrée de cache `hibp:range:{$prefixeB}` ne porte pas la réponse de ce préfixe : les "
        . 'deux préfixes se marchent dessus, un mot de passe compromis peut donc être déclaré sain. '
        . 'Geste : vérifier la construction de la clé de cache — H45-003.',
    );

    // 2) La clé est le PRÉFIXE, pas le hachage complet : une clé portant le sha1
    //    entier signalerait qu'on n'interroge plus HIBP par plage.
    expect(Cache::has('hibp:range:' . $sha1A))->toBeFalse(
        "Une entrée de cache porte le sha1 COMPLET de `{$motA}`. Le service n'est censé manipuler "
        . 'que les 5 premiers caractères (k-anonymité HIBP). Geste : revenir à `substr($sha1, 0, 5)` '
        . 'dans la clé de cache — H45-003.',
    );

    // 3) File de réponses simulées ÉPUISÉE. Un mot de passe différent mais de
    //    même préfixe doit être servi par le cache. S'il partait sur le réseau,
    //    `MockHandler` lèverait, `getBreachCount` attraperait et rendrait `null`.
    $compteJumeau = $checker->getBreachCount($motJumeauDeA);
    expect($compteJumeau === 0)->toBeTrue(
        "Attendu 0 (entrée `hibp:range:{$prefixeA}` relue depuis le cache, suffixe absent), obtenu "
        . var_export($compteJumeau, true) . ". `null` signifie que l'appel est parti sur le réseau : "
        . "le cache n'est donc plus indexé sur le seul préfixe de 5 caractères, et HIBP est "
        . 'réinterrogé une fois par mot de passe. Geste : rétablir la clé `hibp:range:<préfixe>` — H45-003.',
    );
});

/**
 * H45-003 (S2) — CE TEST NE REGARDAIT PAS L'EN-TÊTE QU'IL PROMET DE GARDER.
 *
 * Il se terminait sur `expect($captured)->not->toBeNull();` : il assurait que la
 * requête avait été capturée, jamais qu'elle portait un `User-Agent`.
 *
 * Pire, et c'est la mesure du 2026-08-22 : son gestionnaire simulé rendait une
 * `Response` nue au lieu d'une promesse. Les intergiciels de Guzzle appellent
 * `->then()` dessus, ce qui lève une `Error` — que `getBreachCount` avale par son
 * `catch (\Throwable)`. La requête était capturée AVANT la levée, donc l'assertion
 * restait verte sur un appel qui n'avait jamais abouti. On passe désormais par
 * `MockHandler`, qui rend de vraies promesses et expose la dernière requête émise ;
 * et on assure d'abord que l'appel a ABOUTI (compte 0, pas `null`) avant de
 * regarder ses en-têtes.
 *
 * L'autre moitié du piège : l'en-tête n'est posé que sur le client que
 * `HibpChecker` se construit LUI-MÊME. Un test qui injecte son propre `Client` ne
 * mesure que le `User-Agent` par défaut de Guzzle. On lit donc par réflexion la
 * configuration du client interne, puis on la rejoue sur le fil.
 */
test('H45-003 : HIBP annonce son User-Agent, et cet en-tete part reellement sur le fil', function () {
    // Aucun appel réseau ici : on n'inspecte que la configuration du client que
    // `HibpChecker` se donne quand on ne lui en injecte aucun.
    $clientInterne = (new ReflectionProperty(HibpChecker::class, 'http'))
        ->getValue(new HibpChecker());

    expect(property_exists($clientInterne, 'config'))->toBeTrue(
        "Guzzle n'expose plus sa configuration sous la propriété `config`. Geste : adapter cette "
        . "garde à la nouvelle propriété plutôt que de la supprimer — c'est elle qui prouve que "
        . "HibpChecker s'annonce à HIBP (H45-003).",
    );

    /** @var array<string,mixed> $config */
    $config = (new ReflectionProperty($clientInterne, 'config'))->getValue($clientInterne);
    $agent = (string) (($config['headers'] ?? [])['User-Agent'] ?? '');

    expect(str_contains($agent, 'Axion-CRM-Pro'))->toBeTrue(
        "Le client interne de HibpChecker s'annonce « {$agent} ». HIBP demande un User-Agent "
        . "identifiant l'appelant et refuse les clients anonymes. Geste : rétablir l'en-tête "
        . '`User-Agent` dans le constructeur de HibpChecker — H45-003.',
    );

    // Et maintenant sur le fil : la même configuration, avec un gestionnaire
    // simulé à la place du transport réel.
    $mock = new MockHandler([new Response(200, [], "NOSUFFIX:0\r\n")]);
    $temoin = new HibpChecker(new Client(array_merge($config, ['handler' => HandlerStack::create($mock)])));

    $compte = $temoin->getBreachCount('test-password');
    expect($compte === 0)->toBeTrue(
        "L'appel simulé n'a pas abouti (obtenu " . var_export($compte, true) . ' au lieu de 0) : les '
        . 'assertions sur les en-têtes qui suivent porteraient sur une requête jamais partie. '
        . "Geste : c'est exactement le piège de l'ancien test — vérifier le gestionnaire simulé.",
    );

    $requete = $mock->getLastRequest();
    expect($requete !== null)->toBeTrue(
        "Aucune requête n'a atteint le gestionnaire simulé. Geste : vérifier que `getBreachCount` "
        . 'interroge bien HIBP pour un mot de passe absent du cache.',
    );

    $agentEmis = $requete->getHeaderLine('User-Agent');
    expect(str_contains($agentEmis, 'Axion-CRM-Pro'))->toBeTrue(
        "La requête part avec le User-Agent « {$agentEmis} » : l'en-tête déclaré dans la "
        . "configuration n'atteint pas le fil. Geste : vérifier la casse et l'orthographe de la clé "
        . '`User-Agent` dans le constructeur de HibpChecker — H45-003.',
    );
});
