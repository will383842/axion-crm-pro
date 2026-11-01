<?php

/**
 * NR-19 et NR-20 — R12 « campagnes » et l’instantané des routes du serveur.
 *
 * NR-19 : les adresses actuelles `/api/v1/campaigns…` (campagnes de COLLECTE) répondent
 * toujours, OU redirigent vers leur nouveau nom après le renommage en
 * « collectes » (lot DM0-COL). Jamais 404.
 *
 * NR-20 : la liste des routes du serveur (méthode, chemin, protections) est
 * figée « AU MOINS » (`instantanes/nr20-routes.json`) : une route RETIRÉE ou une
 * protection RETIRÉE fait rougir ; en ajouter est permis. La garde jumelle
 * côté frontend (`frontend/tests/non-regression/routes.test.ts`) vérifie que
 * chaque appel d’API de l’écran vise une route de cette photo.
 *
 * Gardes existantes rattachées au groupe : `CampaignsTest`,
 * `PasDeStub501SousCrmEtAnalyticsTest`.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaRoute;
use Illuminate\Support\Facades\Route;
use Tests\Feature\NonRegression\Instantane;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** @return list<string> « MÉTHODE chemin | protections triées » */
function nr20Routes(): array
{
    $lignes = [];
    /** @var LaRoute $r */
    foreach (Route::getRoutes()->getRoutes() as $r) {
        $uri = '/' . ltrim($r->uri(), '/');
        // Hors périmètre : outils de développement et documentation.
        if (str_starts_with($uri, '/_') || str_starts_with($uri, '/telescope') || str_starts_with($uri, '/horizon')
            || str_starts_with($uri, '/pulse') || str_starts_with($uri, '/api/documentation') || str_starts_with($uri, '/docs')) {
            continue;
        }
        $middleware = array_values(array_unique(array_map(
            static fn ($m): string => is_string($m) ? preg_replace('/^(.*?):.*$/', '$1', $m) : 'closure',
            $r->gatherMiddleware(),
        )));
        sort($middleware);
        foreach (array_diff($r->methods(), ['HEAD']) as $methode) {
            $lignes[] = $methode . ' ' . $uri . ' | ' . implode(',', $middleware);
        }
    }
    $lignes = array_values(array_unique($lignes));
    sort($lignes);

    return $lignes;
}

test('NR-20 toutes les routes de la photo existent encore, avec leurs protections', function () {
    Instantane::verifierInclut('nr20-routes', nr20Routes());
    expect(count(nr20Routes()))->toBeGreaterThan(150); // témoin : ≈ 190 routes sous /api/v1 au relevé
});

test('NR-20 les routes internes signées du relevé existent (6 canaux)', function () {
    $uris = array_map(fn (string $l) => explode(' ', $l)[1], nr20Routes());

    foreach (['/api/internal/site-sync', '/api/internal/site-sync/gdpr', '/api/internal/partners/v1/ping', '/api/internal/email/zeptomail', '/api/internal/scraper-result'] as $uri) {
        expect($uris)->toContain($uri);
    }
});

test('NR-19 les adresses de collecte « campaigns » existent dans la liste des routes', function () {
    $uris = array_map(fn (string $l) => explode(' ', $l)[1], nr20Routes());

    foreach (['/api/v1/campaigns', '/api/v1/campaigns/{campaign}', '/api/v1/campaigns/{campaign}/start', '/api/v1/campaigns/{campaign}/pause', '/api/v1/campaigns/{campaign}/resume'] as $uri) {
        expect($uris)->toContain($uri);
    }
});

test('NR-19 l’adresse « campaigns » ne répond jamais 404 (réponse protégée ou redirection)', function () {
    foreach (['/api/v1/campaigns', '/api/v1/campaigns/1'] as $adresse) {
        $statut = $this->getJson($adresse)->getStatusCode();

        expect($statut)->not->toBe(404)
            ->and($statut)->toBeIn([301, 302, 307, 308, 401, 403]);
    }
});

test('NR-19 la tâche planifiée « campaigns:start-scheduled » existe toujours (collectes programmées)', function () {
    Illuminate\Support\Facades\Artisan::call('list', ['--format' => 'txt']);
    $commandes = array_map(fn ($e) => (string) $e->command, app(Illuminate\Console\Scheduling\Schedule::class)->events());

    expect(implode("\n", $commandes))->toContain('campaigns:start-scheduled');
});
