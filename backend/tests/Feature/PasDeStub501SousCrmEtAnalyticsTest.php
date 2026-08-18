<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * F7 (préalable étape 0) — AUCUNE ROUTE FACTICE SOUS `/crm` NI `/analytics`.
 *
 * Ce que cette garde protège, et pourquoi elle existe.
 *
 * `routes/api.php` déclarait deux fourre-tout Phase 2 :
 *
 *     Route::any('/crm{any?}',       CrmController::class)->where('any', '.*');
 *     Route::any('/analytics{any?}', AnalyticsController::class)->where('any', '.*');
 *
 * Ils répondaient 501 « not_implemented » sous EXACTEMENT les deux noms du
 * chantier CRM cible. Deux conséquences, la seconde étant la coûteuse :
 *
 *   1. vocabulaire — une adresse `/crm` qui répond « non implémenté » pendant
 *      qu'un chantier « CRM cible » démarre est un piège de nommage ;
 *   2. masquage — `Route::any('/crm{any?}')` capturait TOUTE sous-route
 *      `/v1/crm/*` non déclarée au-dessus par le groupe `crm-console`. Un
 *      futur endpoint CRM oublié dans ce groupe, ou déclaré APRÈS le
 *      fourre-tout, aurait répondu 501 au lieu de 404 — un « pas encore
 *      écrit » impossible à distinguer d'un « mal branché ».
 *
 * La garde a trois volets, dans cet ordre :
 *
 *   A. STATIQUE   — le routeur ne porte plus AUCUNE route factice sous ces
 *                   deux préfixes (aucun contrôleur `…\Api\Phase2\*`, aucun
 *                   segment fourre-tout `{any?}`) ;
 *   B. TÉMOIN     — les routes RÉELLES de la console v2 sous `/crm` sont
 *                   toujours là. Sans ce volet, tout supprimer sous `/crm`
 *                   ferait passer le volet A : une garde qui se satisfait du
 *                   vide ne garde rien ;
 *   C. DYNAMIQUE  — appel HTTP réel : ni `/crm`, ni `/analytics`, ni leurs
 *                   sous-chemins ne répondent 501, quel que soit le verbe.
 *
 * ⚠️ `/cold-email` et `/linkedin` restent des bouchons 501 À DESSEIN (leurs
 * noms n'entrent en collision avec aucun nom du cahier des charges) : le
 * dernier cas le CONSTATE, pour que ce choix reste explicite et que personne
 * ne « nettoie » les quatre bouchons en croyant appliquer F7.
 */
beforeEach(function () {
    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'ws-f7-' . Str::random(6),
        'name' => 'WS F7',
        'settings' => [],
    ]);

    $this->user = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'f7-' . Str::random(6) . '@example.invalid',
        'name' => 'F7',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $this->workspace->id,
        'first_login_completed_at' => now(),
    ]);

    $this->actingAs($this->user);
});

/** Préfixes surveillés, en URI de routeur (Laravel les stocke sans « / » initial). */
const F7_PREFIXES_SURVEILLES = ['api/v1/crm', 'api/v1/analytics'];

/**
 * Toutes les routes enregistrées dont l'URI tombe sous l'un des préfixes.
 *
 * On compare sur `préfixe` OU `préfixe/…` OU `préfixe{…}` : `api/v1/crm-autre`
 * ne doit pas être capté, mais `api/v1/crm{any?}` doit l'être.
 *
 * @return array<int, Illuminate\Routing\Route>
 */
function f7RoutesSousPrefixes(): array
{
    $trouvees = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        foreach (F7_PREFIXES_SURVEILLES as $prefixe) {
            if ($uri === $prefixe || str_starts_with($uri, $prefixe . '/') || str_starts_with($uri, $prefixe . '{')) {
                $trouvees[] = $route;
                break;
            }
        }
    }

    return $trouvees;
}

/** Description lisible d'une route, pour que l'échec nomme le coupable. */
function f7Decrire(Illuminate\Routing\Route $route): string
{
    return implode('|', $route->methods()) . ' /' . $route->uri() . ' → ' . ($route->getActionName() ?: '?');
}

// ── A. STATIQUE ──────────────────────────────────────────────────────────────

test('🔴 A1 — aucune route sous /crm ni /analytics n’est servie par un contrôleur bouchon Phase 2', function () {
    $fautives = [];

    foreach (f7RoutesSousPrefixes() as $route) {
        $action = (string) $route->getActionName();

        if (str_contains($action, 'App\\Http\\Controllers\\Api\\Phase2\\')) {
            $fautives[] = f7Decrire($route);
        }
    }

    expect($fautives)->toBe(
        [],
        "Un bouchon Phase 2 (501) est de retour sous /crm ou /analytics :\n  - "
        . implode("\n  - ", $fautives)
        . "\nCes deux noms appartiennent au chantier CRM cible : ils ne doivent porter que du code réel.",
    );
});

test('🔴 A2 — aucun fourre-tout {any} ne capture les sous-chemins de /crm ni de /analytics', function () {
    $fautives = [];

    foreach (f7RoutesSousPrefixes() as $route) {
        // Un segment optionnel fourre-tout (`{any?}`, `{path?}`…) suffixé
        // directement au préfixe : c'est le patron exact du bouchon retiré.
        if (preg_match('/\{[a-zA-Z_]+\?\}$/', $route->uri()) === 1) {
            $fautives[] = f7Decrire($route);
        }
    }

    expect($fautives)->toBe(
        [],
        "Un fourre-tout capture à nouveau tout /crm/* ou /analytics/* :\n  - "
        . implode("\n  - ", $fautives)
        . "\nUne sous-route non déclarée doit répondre 404, jamais 501.",
    );
});

// ── B. TÉMOIN (anti-vacuité) ─────────────────────────────────────────────────

test('🔴 B — les routes RÉELLES de la console v2 sous /crm sont toujours enregistrées', function () {
    $uris = array_map(fn ($r) => $r->uri(), f7RoutesSousPrefixes());

    // Sans ce cas, supprimer TOUT ce qui vit sous /crm ferait passer A1 et A2.
    foreach ([
        'api/v1/crm/contacts-hub',
        'api/v1/crm/contacts-hub/counts',
        'api/v1/crm/candidates',
        'api/v1/crm/candidates/counts',
        'api/v1/crm/arbitrage',
        'api/v1/crm/bulk',
    ] as $attendue) {
        expect($uris)->toContain($attendue);
    }

    // Et elles sont bien servies par de VRAIS contrôleurs de console.
    foreach (f7RoutesSousPrefixes() as $route) {
        expect((string) $route->getActionName())->toContain('App\\Http\\Controllers\\');
    }
});

// ── C. DYNAMIQUE ─────────────────────────────────────────────────────────────

test('🔴 C1 — /api/v1/crm et /api/v1/analytics ne répondent 501 sur aucun verbe', function () {
    foreach (['/api/v1/crm', '/api/v1/analytics'] as $chemin) {
        foreach (['get', 'post', 'put', 'patch', 'delete'] as $verbe) {
            $reponse = $this->json(strtoupper($verbe), $chemin);

            expect($reponse->getStatusCode())->not->toBe(
                501,
                strtoupper($verbe) . " {$chemin} répond 501 : un bouchon « non implémenté » est revenu sous un nom du chantier CRM cible.",
            );
        }
    }
});

test('🔴 C2 — un sous-chemin inexistant sous /crm ou /analytics répond 404, pas 501', function () {
    foreach ([
        '/api/v1/crm/pipeline',
        '/api/v1/crm/deals/42',
        '/api/v1/analytics/dashboard',
        '/api/v1/analytics/rapports/ventes',
    ] as $chemin) {
        $this->getJson($chemin)->assertNotFound();
    }
});

test('🔴 C3 — le drapeau console v2 ouvert, /crm sert du code réel (200), pas un bouchon', function () {
    config(['crm.console_v2' => true]);

    $reponse = $this->getJson('/api/v1/crm/contacts-hub/counts');

    expect($reponse->getStatusCode())->not->toBe(501);
    // Le contrat de forme du bouchon Phase 2 ne doit subsister nulle part.
    $reponse->assertJsonMissingPath('sprint');
});

// ── Choix assumé : les deux autres bouchons restent ──────────────────────────

test('/cold-email et /linkedin restent des bouchons 501 — choix assumé, hors périmètre F7', function () {
    $this->getJson('/api/v1/cold-email')->assertStatus(501);
    $this->getJson('/api/v1/linkedin')->assertStatus(501);
});
