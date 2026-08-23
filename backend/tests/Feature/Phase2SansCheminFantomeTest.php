<?php

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class);

/**
 * B12-017 — AUCUN CONTRÔLEUR PHASE 2 NE PUBLIE UN CHEMIN QUE PERSONNE NE SERT.
 *
 * Le défaut, mesuré le 2026-08-22 :
 * `app/Http/Controllers/Api/Phase2/CampaignsController.php` n'était désigné par
 * AUCUNE route — `routes/api.php` n'importe que ses deux voisins, et une
 * recherche de `Phase2\CampaignsController` sur tout `backend/` ne rendait
 * aucune occurrence. Classe morte, donc ; mais surtout, elle portait
 * `@OA\Get(path="/campaigns", …, response=501)`. Cette annotation PUBLIAIT le
 * chemin `/campaigns` dans la spécification OpenAPI servie par Swagger, en
 * « non implémenté », à côté du vrai `/campaigns` qui répond depuis le Sprint
 * 19.7. Un lecteur de la documentation d'API lisait donc l'inverse de la vérité.
 *
 * ⚠️ CE QUE CETTE GARDE INSPECTE, EXACTEMENT : les annotations OpenAPI des
 * contrôleurs du dossier `Api/Phase2`, confrontées aux routes RÉELLEMENT
 * enregistrées. Elle ne dit rien de l'existence des fichiers eux-mêmes — le
 * dossier peut contenir une classe morte sans chemin publié, et cette garde
 * restera verte. C'est un choix assumé : le chemin fantôme est ce qui MENT à un
 * appelant, la classe inerte n'est que du poids.
 *
 * ⚠️ Le dossier est parcouru par `scandir`, pas par un itérateur récursif :
 * `RecursiveDirectoryIterator` TRONQUE le parcours sur le montage Docker de ce
 * dépôt (14 fichiers vus sur 56, mesuré). Un parcours qui s'arrête tôt rend une
 * garde verte qui n'a rien inspecté.
 */

/** @return list<string> chemins absolus des contrôleurs Phase 2 */
function b12017FichiersPhase2(): array
{
    $dossier = app_path('Http/Controllers/Api/Phase2');
    if (! is_dir($dossier)) {
        return [];
    }

    $trouves = [];
    foreach (scandir($dossier) ?: [] as $entree) {
        if ($entree === '.' || $entree === '..') {
            continue;
        }
        $complet = $dossier . DIRECTORY_SEPARATOR . $entree;
        if (is_file($complet) && str_ends_with($entree, '.php')) {
            $trouves[] = $complet;
        }
    }

    return $trouves;
}

/**
 * Ramène une URI de route à la forme qu'emploient les annotations `@OA\*`.
 *
 * Deux normalisations, et chacune a une raison mesurée :
 *  - le préfixe de version saute (`api/v1/campaigns` -> `/campaigns`) : les
 *    annotations du dépôt écrivent le chemin sans lui ;
 *  - le segment fourre-tout optionnel saute (`/cold-email{any?}` ->
 *    `/cold-email`) : `routes/api.php` déclare les deux bouchons conservés en
 *    `Route::any('/cold-email{any?}', …)`, et sans cette ligne la garde les
 *    prendrait pour des chemins fantômes — un faux rouge sur deux routes qui
 *    existent bel et bien.
 */
function b12017Normaliser(string $uri): string
{
    $sansPrefixe = preg_replace('#^api/v\d+/#', '', $uri) ?? $uri;
    $sansFourreTout = preg_replace('/\{[a-zA-Z_]+\?\}$/', '', $sansPrefixe) ?? $sansPrefixe;

    return rtrim('/' . ltrim($sansFourreTout, '/'), '/') ?: '/';
}

/** @return list<string> les URI servies, normalisées */
function b12017UrisServies(): array
{
    $uris = [];
    foreach (Route::getRoutes() as $route) {
        $uris[] = b12017Normaliser($route->uri());
    }

    return array_values(array_unique($uris));
}

test('B12-017 — aucun contrôleur Phase 2 ne documente un chemin qu’aucune route ne sert', function () {
    $fichiers = b12017FichiersPhase2();

    // Sans ce contrôle, un dossier renommé rendrait la garde verte sans rien
    // avoir inspecté — le défaut « assertion vraie par construction ».
    expect(count($fichiers) > 0)->toBeTrue(
        'B12-017 : aucun fichier trouvé dans app/Http/Controllers/Api/Phase2. '
        . 'Si le dossier a été renommé ou vidé, cette garde ne mesure plus rien : '
        . 'GESTE — mettre à jour le chemin dans b12017FichiersPhase2(), ou '
        . 'supprimer cette garde en connaissance de cause.',
    );

    $servies = b12017UrisServies();
    $fantomes = [];

    foreach ($fichiers as $fichier) {
        $source = (string) file_get_contents($fichier);
        // Toutes les annotations `@OA\Get|Post|Put|Patch|Delete(path="…")`.
        //
        // 🔴 L'ancre de debut de ligne n'est pas cosmetique — mesure du
        // 2026-08-23. Sans elle, la garde comptait comme PUBLIEE une annotation
        // seulement CITEE dans une phrase : l'en-tete de Phase2\CampaignsController
        // expliquait quelle annotation il avait fallu retirer, et la garde lisait
        // cette explication comme le defaut qu'elle decrivait. Elle rougissait sur
        // le compte rendu de sa propre reparation.
        //
        // Toute annotation REELLE de ce depot ouvre une ligne de bloc de
        // documentation (mesure : grep -rn "OA.Get(path" backend/app/ — les 40+
        // occurrences s'ecrivent «      * @OA\Get(path="…" »). Une citation en
        // cours de phrase ne le fait jamais : on ne perd aucune annotation vraie,
        // et on cesse de lire du francais comme du code.
        preg_match_all('/^\s*\*\s*@OA\\\\(?:Get|Post|Put|Patch|Delete)\s*\(\s*path\s*=\s*"([^"]+)"/m', $source, $trouvees);

        foreach ($trouvees[1] ?? [] as $chemin) {
            $cible = b12017Normaliser($chemin);

            if (! in_array($cible, $servies, true)) {
                $fantomes[] = basename($fichier) . ' publie ' . $chemin . ' — aucune route ne le sert';

                continue;
            }

            // Le chemin est servi — mais l'est-il par CE contrôleur ? Un bouchon
            // qui documente un chemin servi par quelqu'un d'autre publie une
            // réponse (501) que personne ne renverra jamais. C'est EXACTEMENT le
            // cas de `/campaigns` : servi depuis le Sprint 19.7 par le vrai
            // contrôleur des collectes, et documenté en 501 par le bouchon.
            $classe = 'App\\Http\\Controllers\\Api\\Phase2\\' . basename($fichier, '.php');
            $sertCeChemin = false;
            foreach (Route::getRoutes() as $route) {
                if (b12017Normaliser($route->uri()) === $cible
                    && str_contains((string) $route->getActionName(), $classe)) {
                    $sertCeChemin = true;
                    break;
                }
            }
            if (! $sertCeChemin) {
                $fantomes[] = basename($fichier) . ' publie ' . $chemin . ' — servi par un AUTRE contrôleur';
            }
        }
    }

    expect($fantomes)->toBe(
        [],
        "B12-017 : un contrôleur Phase 2 publie dans l'OpenAPI un chemin qu'il ne "
        . "sert pas :\n  - " . implode("\n  - ", $fantomes)
        . "\nCe chemin apparaît dans la documentation Swagger — en « 501 Not "
        . 'implemented » — à côté de la vraie route qui, elle, répond. Le lecteur '
        . "de la documentation lit l'inverse de la vérité.\n"
        . 'GESTE : retirer l’annotation @OA\\* du contrôleur bouchon (et, s’il '
        . 'n’est désigné par aucune route, supprimer le fichier).',
    );
});
