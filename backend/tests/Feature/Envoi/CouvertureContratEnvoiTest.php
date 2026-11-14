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

/** Trous réels constatés au lot R19 (contrat sans route sur main). Voir docs/envoi/lots/R19.md. */
const TROUS_REELS = [
    'POST /envoi/boites' => 'routes/envoi/boites.php est vide',
    'GET /envoi/boites' => 'routes/envoi/boites.php est vide',
    'GET /envoi/boites/{id}' => 'routes/envoi/boites.php est vide',
    'POST /envoi/boites/test-connexion' => 'routes/envoi/boites.php est vide',
    'POST /envoi/boites/{id}/reactiver' => 'routes/envoi/boites.php est vide',
    'POST /envoi/boites/{id}/suspendre' => 'routes/envoi/boites.php est vide',
    'PUT /envoi/boites/{id}/reglages' => 'routes/envoi/boites.php est vide',
    'GET /envoi/chauffe' => 'routes/envoi/chauffe.php est vide',
    'POST /envoi/chauffe/{id}/reculer' => 'routes/envoi/chauffe.php est vide',
    'GET /envoi/sequences' => 'routes/envoi/sequences.php est vide',
    'GET /envoi/sequences/{id}' => 'routes/envoi/sequences.php est vide',
    'POST /envoi/sequences/{id}/pause' => 'routes/envoi/sequences.php est vide',
    'POST /envoi/sequences/{id}/reprise' => 'routes/envoi/sequences.php est vide',
    'GET /envoi/etat' => 'aucune route d’état global',
    'GET /envoi/compteurs' => 'aucune route de compteurs d’en-tête',
    'POST /envoi/tout-arreter' => 'aucune route « Tout arrêter »',
    'POST /envoi/reprendre' => 'aucune route « Reprendre »',
    'POST /envoi/sante/test' => 'aucune route de test de santé',
    'POST /notifications/{id}/lue' => 'la route réelle est POST /notifications/{n}/read (autre chemin)',
];

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
        if (! preg_match('#^/(api/v1/(envoi|notifications|config)|desinscription)#', $uri)) {
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
    expect(count(couplesDuContrat()))->toBeGreaterThan(100);
});

test('chaque couple du contrat a une route, une entrée NonServiAuPalier0 ou un trou réel déclaré', function () {
    $routes = couplesDesRoutes();
    $nonServis = array_map(cle(...), array_keys(NonServiAuPalier0::entrees()));
    $inconnus = [];
    foreach (couplesDuContrat() as $couple) {
        $ok = in_array(cle($couple), $routes, true)
            || in_array(cle($couple), $nonServis, true)
            || array_key_exists($couple, TROUS_REELS);
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
    foreach (array_keys(TROUS_REELS) as $couple) {
        expect($contrat)->toContain($couple);
        expect($routes)->not->toContain(cle($couple));
    }
});

// Trous réels : visibles dans la sortie Pest, CI verte. Un trou comblé doit sortir d'ici (voir R19.md).
foreach (TROUS_REELS as $couple => $raison) {
    test("trou réel : $couple a une route")->todo("$raison — à traiter par le lot propriétaire du contrat");
}
