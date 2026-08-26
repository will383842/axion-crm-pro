<?php

use App\Http\Middleware\SetCurrentWorkspace;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/**
 * Garde d'ORDRE du groupe de middlewares `api`.
 *
 * Pourquoi une garde structurelle et pas un appel HTTP : la RLS est INERTE en
 * local et ACTIVE en production (cf. la migration `harden_workspace_isolation`
 * du 2026-08-14, qui pose `FORCE ROW LEVEL SECURITY` sur `media`,
 * `journalists`, `companies` et `contacts`). Un test qui appellerait
 * `GET /api/v1/media/{id}` serait donc VERT en local ET en CI même avec le
 * défaut présent — il ne mesurerait rien. Seul l'ordre des middlewares est
 * observable des deux côtés à l'identique.
 *
 * Ce que la garde protège, constaté en production le 2026-08-26 : les fiches de
 * détail rendaient toutes 404/500 depuis le 2026-08-14 alors que les listes
 * répondaient. `SetCurrentWorkspace` était enregistré en `append:`, or le
 * groupe `api` de Laravel 12 se termine par `SubstituteBindings` — la
 * résolution implicite des modèles (`show(Media $media)`) interrogeait donc
 * Postgres AVANT que `app.current_workspace_id` ne soit posée. La policy
 * échoue fermée (`workspace_id = NULLIF(current_setting(...), '')` vaut NULL
 * quand la variable est absente), donc zéro ligne, donc `ModelNotFoundException`.
 */
test('le contexte workspace est posé avant la résolution implicite des modèles', function () {
    $groupe = app(Router::class)->getMiddlewareGroups()['api'] ?? [];

    $posContexte = array_search(SetCurrentWorkspace::class, $groupe, true);
    $posBinding = array_search(SubstituteBindings::class, $groupe, true);

    expect($posContexte)->not->toBeFalse('SetCurrentWorkspace absent du groupe api');
    expect($posBinding)->not->toBeFalse('SubstituteBindings absent du groupe api');

    expect($posContexte)->toBeLessThan(
        $posBinding,
        'SetCurrentWorkspace doit précéder SubstituteBindings, sinon la '
        . 'résolution implicite des modèles interroge Postgres hors contexte '
        . 'workspace et la RLS (fail-closed en prod) rend zéro ligne : toutes '
        . 'les routes de détail répondent 404.',
    );
});

/**
 * Le versant symétrique, et le piège du correctif « évident » : remonter
 * `SetCurrentWorkspace` avec `prepend:` le placerait AVANT
 * `EnsureFrontendRequestsAreStateful`. L'authentification par cookie de session
 * ne serait alors pas encore configurée, `$request->user()` rendrait `null`, et
 * le middleware sortirait en silence sur son propre garde-fou
 * (`if (! $user) { return $next($request); }`). Le défaut survivrait à
 * l'identique — SANS que rien ne rougisse, ici comme en production.
 */
test('le contexte workspace est posé après la résolution de l’authentification', function () {
    $groupe = app(Router::class)->getMiddlewareGroups()['api'] ?? [];

    $posContexte = array_search(SetCurrentWorkspace::class, $groupe, true);
    $posAuth = array_search(EnsureFrontendRequestsAreStateful::class, $groupe, true);

    expect($posContexte)->not->toBeFalse('SetCurrentWorkspace absent du groupe api');
    expect($posAuth)->not->toBeFalse('EnsureFrontendRequestsAreStateful absent du groupe api');

    expect($posContexte)->toBeGreaterThan(
        $posAuth,
        'SetCurrentWorkspace doit suivre EnsureFrontendRequestsAreStateful, '
        . 'sinon $request->user() rend null et le middleware sort en silence : '
        . 'le contexte workspace n’est jamais posé, et aucune garde ne rougit.',
    );
});
