<?php

use Illuminate\Routing\Route as RouteLaravel;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class);

/**
 * DM0-COL : `/api/v1/collectes…` est un alias de `/api/v1/campaigns…`.
 * Même contrôleur, même action, mêmes droits et mêmes limites de débit
 * (donc même RLS : aucun code métier n'est dupliqué). Les anciennes routes
 * restent en place : rien ne casse.
 */
function routesDe(string $segment): array
{
    $prefixe = 'api/v1/' . $segment;
    $routes = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var RouteLaravel $route */
        $uri = $route->uri();
        if ($uri === $prefixe || str_starts_with($uri, $prefixe . '/')) {
            foreach ($route->methods() as $methode) {
                if ($methode === 'HEAD') {
                    continue;
                }
                $cle = $methode . ' ' . substr($uri, strlen($prefixe));
                // Le nom du paramètre d'URL (`{campaign}`) ne compte pas.
                $routes[preg_replace('/\{[^}]+\}/', '{id}', $cle)] = $route;
            }
        }
    }

    return $routes;
}

it('expose chaque route /campaigns sous /collectes avec le même contrôleur', function () {
    $anciennes = routesDe('campaigns');
    $nouvelles = routesDe('collectes');

    expect($anciennes)->not->toBeEmpty();
    expect(array_keys($nouvelles))->toEqualCanonicalizing(array_keys($anciennes));

    foreach ($anciennes as $cle => $ancienne) {
        expect($nouvelles[$cle]->getActionName(), $cle)->toBe($ancienne->getActionName());
        expect($nouvelles[$cle]->gatherMiddleware(), $cle)->toEqualCanonicalizing($ancienne->gatherMiddleware());
    }
});

it('garde toutes les actions de collecte sous /collectes', function () {
    $cles = array_keys(routesDe('collectes'));

    foreach (['start', 'pause', 'resume', 'cancel', 'archive', 'unarchive', 'stats'] as $action) {
        expect($cles)->toContain($action === 'stats' ? 'GET /{id}/stats' : 'POST /{id}/' . $action);
    }
});

it('refuse un visiteur sans session sur les deux adresses (401)', function () {
    $this->getJson('/api/v1/campaigns')->assertStatus(401);
    $this->getJson('/api/v1/collectes')->assertStatus(401);
});
