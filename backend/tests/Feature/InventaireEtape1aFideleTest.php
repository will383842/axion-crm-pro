<?php

use Tests\TestCase;

uses(TestCase::class);

/**
 * A09-004 — L'INVENTAIRE DE L'ÉTAPE 1a NE DOIT PAS DÉCLARER MORT CE QUI VIT.
 *
 * Le défaut, mesuré le 2026-08-22 : `_REPORTS/2026-08-19_INVENTAIRE-ETAPE-1A.md`
 * rangeait `saved_views` dans « l'échafaudage mort » — « Aucune n'a de modèle
 * Eloquent, de contrôleur, de route ni d'écran », et « Qui la cite dans le code
 * applicatif : — ». C'était faux des deux moitiés :
 * `app/Http/Controllers/Api/SavedViewsController.php` existe, et `routes/api.php`
 * déclare TROIS `Route::apiResource('saved-views', …)`.
 *
 * Pourquoi une garde sur un document. Celui-ci sert d'entrée au §28.5 : on le
 * LIT pour décider quoi construire. Un document qui ment coûte plus qu'un
 * document absent, parce qu'on le suit — on ferait réécrire un contrôleur qui
 * existe et re-trancher un cloisonnement déjà tranché. Ce dépôt a déjà payé
 * exactement ce prix avec les deux lignes rectifiées du §2 (A09-003).
 *
 * ⚠️ CE QUE CETTE GARDE INSPECTE : elle relie le CODE au DOCUMENT. Tant que le
 * contrôleur existe et qu'une route le désigne, le document doit le nommer. Elle
 * ne relit pas la prose ligne à ligne et ne vérifie aucune autre affirmation du
 * rapport.
 */

test('A09-004 — l’inventaire nomme SavedViewsController tant que le contrôleur et ses routes existent', function () {
    $controleur = app_path('Http/Controllers/Api/SavedViewsController.php');
    $routes = base_path('routes/api.php');
    $inventaire = base_path('../_REPORTS/2026-08-19_INVENTAIRE-ETAPE-1A.md');

    // Le document peut légitimement ne pas être là (dépôt réduit, worktree
    // partiel) : on ne fabrique pas un rouge sur une absence de fichier.
    if (! is_file($inventaire)) {
        expect(true)->toBeTrue();

        return;
    }

    $controleurExiste = is_file($controleur);
    $routeExiste = $controleurExiste
        && str_contains((string) file_get_contents($routes), "Route::apiResource('saved-views'");

    if (! $controleurExiste || ! $routeExiste) {
        // Le contrôleur a été retiré : le document redevient exact tel quel, il
        // n'y a plus rien à exiger de lui. On le dit plutôt que de rougir.
        expect(true)->toBeTrue();

        return;
    }

    $texte = (string) file_get_contents($inventaire);

    expect(str_contains($texte, 'SavedViewsController'))->toBeTrue(
        "A09-004 : `_REPORTS/2026-08-19_INVENTAIRE-ETAPE-1A.md` ne nomme plus "
        . "`SavedViewsController`, alors que le contrôleur existe et que "
        . "`routes/api.php` déclare `Route::apiResource('saved-views', …)`.\n"
        . "Le document range alors `saved_views` dans l'échafaudage mort — et il "
        . "sert d'entrée au §28.5 : on le lit pour décider quoi construire. On "
        . "ferait réécrire un contrôleur qui existe.\n"
        . 'GESTE : rétablir la RECTIFICATION 2026-08-22 du §3 du rapport, qui dit '
        . 'où sont le contrôleur et les trois routes.',
    );
});
