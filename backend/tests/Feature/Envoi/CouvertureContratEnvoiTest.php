<?php

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\NonServiAuPalier0;
use Tests\TestCase;

uses(TestCase::class)->group('envoi');

/**
 * Lot R19 : bilan de couverture du contrat d'envoi. Chaque couple méthode + chemin des yaml d'envoi a une route réelle,
 * ou une entrée NonServiAuPalier0 (409/501 vérifié par appel), ou figure dans TROUS_REELS ci-dessous (test `todo`).
 * Aucune route /envoi/* ne doit exister sans contrat. Aucune route nouvelle ici : le test ne fait que constater.
 */

/**
 * Trous réels constatés au lot R19 (contrat sans route sur main), lus dans tests/Support/Couverture/trous/*.php
 * (un fichier par domaine, glob trié). Voir docs/envoi/lots/R19.md.
 */
function trousReels(): array
{
    $trous = [];
    foreach (glob(dirname(__DIR__, 2) . '/Support/Couverture/trous/*.php') ?: [] as $fichier) {
        $trous = array_merge($trous, require $fichier);
    }
    ksort($trous);

    return $trous;
}

/** @return list<string> couples « MÉTHODE /chemin » de tous les yaml d'envoi */
function couplesDuContrat(): array
{
    $couples = [];
    foreach (glob(dirname(__DIR__, 3) . '/openapi/envoi/*.yaml') as $fichier) {
        foreach ((Yaml::parseFile($fichier)['paths'] ?? []) as $chemin => $operations) {
            foreach (array_keys($operations) as $methode) {
                if (in_array($methode, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $couples[] = strtoupper($methode) . ' ' . $chemin;
                }
            }
        }
    }
    sort($couples);

    return array_values(array_unique($couples));
}

/** Clé de comparaison : paramètres {x} réduits à {}. @return list<string> */
function couplesDesRoutes(): array
{
    $cles = [];
    foreach (Route::getRoutes() as $route) {
        $uri = '/' . $route->uri();
        if (! preg_match('#^/(api/v1/(envoi|notifications|config)(/|$)|desinscription)#', $uri)) {
            continue;
        }
        $uri = preg_replace('#^/api/v1#', '', $uri); // le contrat déclare le serveur /api/v1 ; la page publique n'a pas de préfixe
        foreach (array_diff($route->methods(), ['HEAD']) as $m) {
            $cles[] = $m . ' ' . preg_replace('/\{[^}]+\}/', '{}', $uri);
        }
    }

    return array_values(array_unique($cles));
}

function cle(string $couple): string
{
    return preg_replace('/\{[^}]+\}/', '{}', $couple);
}

test('le contrat d’envoi est lu en entier', function () {
    expect(glob(dirname(__DIR__, 3) . '/openapi/envoi/*.yaml'))->not->toBeFalse()->and(count(glob(dirname(__DIR__, 3) . '/openapi/envoi/*.yaml')))->toBeGreaterThanOrEqual(10);
    // Test modifié : pourquoi (D1 : un couple retiré du contrat, compensé par une assertion exacte).
    expect(count(couplesDuContrat()))->toBeGreaterThanOrEqual(107);
    expect(couplesDuContrat())
        ->not->toContain('POST /envoi/reprendre')
        ->not->toContain('POST /envoi/tout-arreter')
        ->toContain('POST /envoi/arret-general');
});

test('chaque couple du contrat a une route, une entrée NonServiAuPalier0 ou un trou réel déclaré', function () {
    $routes = couplesDesRoutes();
    $nonServis = array_map(cle(...), array_keys(NonServiAuPalier0::entrees()));
    $inconnus = [];
    foreach (couplesDuContrat() as $couple) {
        $ok = in_array(cle($couple), $routes, true)
            || in_array(cle($couple), $nonServis, true)
            || array_key_exists($couple, trousReels());
        if (! $ok) {
            $inconnus[] = $couple;
        }
    }
    expect($inconnus)->toBe([], 'couples du contrat sans route, sans entrée NonServiAuPalier0 et hors trous déclarés');
});

test('sens inverse : aucune route /envoi/* sans entrée dans un contrat', function () {
    $contrat = array_map(cle(...), couplesDuContrat());
    $orphelines = [];
    foreach (couplesDesRoutes() as $cle) {
        if (str_contains($cle, ' /envoi') && ! in_array($cle, $contrat, true)) {
            $orphelines[] = $cle;
        }
    }
    expect($orphelines)->toBe([]);
});

test('les trous déclarés sont de vrais trous (ni route réelle, ni entrée NonServiAuPalier0)', function () {
    $routes = couplesDesRoutes();
    $contrat = couplesDuContrat();
    foreach (array_keys(trousReels()) as $couple) {
        expect($contrat)->toContain($couple);
        expect($routes)->not->toContain(cle($couple));
        expect(array_keys(NonServiAuPalier0::entrees()))->not->toContain($couple);
    }
});

// Trous réels : visibles dans la sortie Pest, CI verte. Un trou comblé doit sortir d'ici (voir R19.md).
foreach (trousReels() as $couple => $raison) {
    test("trou réel : $couple a une route")->todo("$raison — à traiter par le lot propriétaire du contrat");
}

test('le contrat d’arrêt général déclare la réponse 403 (refus faute de permission)', function () {
    $doc = Yaml::parseFile(dirname(__DIR__, 3) . '/openapi/envoi/socle.yaml');
    expect($doc['paths']['/envoi/arret-general']['post']['responses'])->toHaveKey('403');
});
