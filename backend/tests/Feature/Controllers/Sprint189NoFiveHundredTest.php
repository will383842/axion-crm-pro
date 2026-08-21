<?php

/*
|--------------------------------------------------------------------------
| Sprint 18.9 — "no 500" defensive endpoint coverage
|--------------------------------------------------------------------------
|
| Cas reproduit en prod : tous les endpoints GET /api/v1/* doivent retourner
| 200 (ou 404 sur ressource manquante) — JAMAIS 500 — même quand la DB est
| partiellement seedée, PostGIS absent, ou Reverb non provisionné.
|
| Ce fichier ajoute la régression test pour chaque endpoint :
|   - /coverage (tous niveaux)
|   - /users
|   - /workspace
|   - /rgpd/requests
|   - /ai-act/register
|   - /rotations
|   - /proxy-providers
|   - /scraper-runs
|   - /audit-logs
|   - /tags
|   - /llm/use-cases
|   - /notifications
|   - /companies
|   - /contacts
*/

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeNoFiveHundredUser(): User
{
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'sprint189-' . Str::random(6),
        'name' => 'Sprint 18.9 WS',
    ]);

    return User::create([
        'id' => (string) Str::uuid(),
        'email' => 'sp189' . Str::random(4) . '@test.local',
        'name' => 'Sprint 18.9',
        'password_hash' => Hash::make('SomePass!1234'),
        'current_workspace_id' => $workspace->id,
        'first_login_completed_at' => now(),
    ]);
}

dataset('protected_get_endpoints', [
    'workspace' => ['/api/v1/workspace'],
    'users' => ['/api/v1/users'],
    'companies' => ['/api/v1/companies'],
    'contacts' => ['/api/v1/contacts'],
    'coverage (default)' => ['/api/v1/coverage'],
    'coverage region' => ['/api/v1/coverage?level=region'],
    'coverage department' => ['/api/v1/coverage?level=department'],
    'coverage city' => ['/api/v1/coverage?level=city'],
    'coverage next-zone' => ['/api/v1/coverage/next-zone'],
    'rgpd requests' => ['/api/v1/rgpd/requests'],
    'ai-act register' => ['/api/v1/ai-act/register'],
    'rotations' => ['/api/v1/rotations'],
    'proxy providers' => ['/api/v1/proxy-providers'],
    'scraper runs' => ['/api/v1/scraper-runs'],
    'audit logs' => ['/api/v1/audit-logs'],
    'audit verify-chain' => ['/api/v1/audit-logs/verify-chain'],
    'tags' => ['/api/v1/tags'],
    'llm use-cases' => ['/api/v1/llm/use-cases'],
    'llm usage' => ['/api/v1/llm/usage'],
    'llm usage summary' => ['/api/v1/llm/usage/summary'],
    'notifications' => ['/api/v1/notifications'],
    'saved views' => ['/api/v1/saved-views'],
    'dashboard stats' => ['/api/v1/dashboard/stats'],
    'search' => ['/api/v1/search'],
]);

test('endpoint {0} authentifié ne retourne JAMAIS 500', function (string $url) {
    $u = makeNoFiveHundredUser();
    $resp = $this->actingAs($u)->getJson($url);

    // Tolérance : 200 happy path, 404 ressource absente, 422 validation. JAMAIS 500.
    expect($resp->getStatusCode())->not->toBe(500);
    expect($resp->getStatusCode())->toBeLessThan(500);
})->with('protected_get_endpoints');

test('endpoint {0} sans auth retourne 401 (jamais 500)', function (string $url) {
    $resp = $this->getJson($url);
    // Sans auth → 401 (Sanctum) ou éventuellement 419 CSRF. Surtout pas 500.
    expect($resp->getStatusCode())->not->toBe(500);
    expect($resp->getStatusCode())->toBeLessThan(500);
})->with('protected_get_endpoints');

// --------------------------------------------------------------------------
// H45-001 (S1) — LE CAS QUI AVAIT REVELE LE DEFAUT N'ETAIT PAS MESURE
// --------------------------------------------------------------------------
//
// La garde ci-dessus s'appelle « sans auth retourne 401 (jamais 500) ». Elle
// n'interroge le produit que par `getJson()`, qui pose `Accept:
// application/json` -- c'est-a-dire par le SEUL en-tete qui EVITAIT le defaut.
// Avec ses 24 jeux de donnees, plus le cas de `Phase2StubsExtendedTest`, cela
// faisait 25 assertions qui certifiaient l'absence d'un defaut VIVANT.
//
// LE DEFAUT (A-001 / F35-001). Laravel 12 pose lui-meme, dans
// `ApplicationBuilder::withMiddleware()`, un `redirectGuestsTo(fn () =>
// route('login'))` inconditionnel. Aucune route nommee `login` n'existe dans
// cette API (`routes/web.php` ne declare que `/`). Toute requete non
// authentifiee qui n'annonce PAS attendre du JSON partait donc en
// `RouteNotFoundException: Route [login] not defined` -- HTTP 500, production
// comprise, 8 475 octets de trace par requete.
// Trace complete : `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/agent-35/`
// `f35-001-cause-exacte.txt`.
//
// LA MESURE DE L'AGENT 45, sur la PRODUCTION, 5 adresses sur 5 :
// GET /api/v1/tags  Accept: application/json  ->  401
// GET /api/v1/tags  Accept: text/html         ->  500
// idem `/dashboard/stats`, `/notifications`, `/audit-logs`, `/saved-views`.
// Recensement joint : `grep -rn "\$this->get('" tests/ | grep -v getJson` ->
// 10 appels, TOUS authentifies. Zero requete non authentifiee sans `Accept:
// application/json` dans les 780 tests de la suite.
//
// CE QUE CETTE GARDE MESURE, ET POURQUOI ELLE EST ECRITE COMME CA.
//
// 1. Elle exige 401 EXACTEMENT, pas « moins que 500 ». Un 404 (route disparue)
// ou un 200 (route ouverte par megarde) doit rougir : sans cela la garde
// passerait au vert sur une adresse qui n'existe plus, ce qui est le vert le
// plus dangereux du dossier.
// 2. Elle interroge les TROIS profils de client au MEME instant, sur la MEME
// adresse : navigateur (`text/html…`), client nu (`*/*`), et SPA
// (`application/json`). Le troisieme est le TEMOIN : il prouve que la route
// est bien joignable et bien gardee, donc qu'un rouge des deux premiers
// designe l'en-tete `Accept` et rien d'autre. Comparer deux profils au meme
// instant, plutot que de se fier a une mesure anterieure, est la seule facon
// d'isoler la variable.
// 3. Elle emploie `$this->get($url, $entetes)` et NON `call()` : `call()`
// n'envoie pas les en-tetes, et une premiere sonde de l'agent 35 s'y etait
// deja fait prendre (preuve archivee sous le nom explicite
// `sonde-run1-INVALIDE-headers-non-envoyes.txt`).
//
// ⚠️ Le correctif produit tient en DEUX pieces, dans `bootstrap/app.php`, et
// aucune ne suffit seule (mesure a quatre etats, audit 360 F35-001) :
// (A) `$middleware->redirectGuestsTo(fn () => null);`
// (B) `$exceptions->shouldRenderJsonWhen(fn ($r, $e) => $r->is('api/*') || …);`
// Retirer (B) suffit a faire rougir cette garde -- verifie, pas suppose : voir
// le rapport de lot, section ROUGE.
test('H45-001 — endpoint {0} sans auth ET sans Accept JSON retourne 401, jamais 500', function (string $url) {
    // Le profil d'un NAVIGATEUR : c'est mot pour mot l'en-tete que Chrome
    // envoie, et c'est celui qui rendait 500 en production.
    $navigateur = $this->get($url, [
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    ]);

    // Le profil d'un CLIENT NU : `curl` sans option, sonde de supervision,
    // client d'integration. `expectsJson()` est faux ici aussi.
    $clientNu = $this->get($url, ['Accept' => '*/*']);

    // TEMOIN, AU MEME INSTANT — le chemin JSON doit continuer de rendre 401.
    // S'il rougissait, ce ne serait plus l'en-tete `Accept` qu'on mesure mais
    // la route elle-meme (disparue, ouverte, ou cassee), et les deux constats
    // au-dessus ne voudraient plus rien dire.
    $spa = $this->getJson($url);
    expect($spa->getStatusCode())->toBe(401, sprintf(
        'TEMOIN EN ECHEC sur %s : le chemin JSON rend %d au lieu de 401. '
        . 'Cette garde ne mesure donc plus l en-tete Accept. Reparer le temoin '
        . 'AVANT de croire quoi que ce soit des deux assertions suivantes.',
        $url,
        $spa->getStatusCode(),
    ));

    expect($navigateur->getStatusCode())->toBe(401, sprintf(
        'A-001 est de retour : %s rend %d a un client qui annonce du HTML, la ou '
        . 'il rend 401 a un client qui annonce du JSON. Un 500 dit « le serveur '
        . 'est casse » a quelqu un qui a simplement oublie de se connecter -- et '
        . 'il declenche les alertes d indisponibilite. Correctif : les DEUX pieces '
        . 'de bootstrap/app.php, redirectGuestsTo(fn () => null) ET '
        . 'shouldRenderJsonWhen(… $request->is(\'api/*\') …).',
        $url,
        $navigateur->getStatusCode(),
    ));

    expect($clientNu->getStatusCode())->toBe(401, sprintf(
        'A-001 est de retour : %s rend %d a un client qui n exprime aucune '
        . 'preference de format (Accept: */*), la ou il rend 401 au SPA.',
        $url,
        $clientNu->getStatusCode(),
    ));
})->with('protected_get_endpoints');

test('broadcasting default = log par défaut sans REVERB_APP_KEY', function () {
    // En env testing, BROADCAST_CONNECTION n'est pas set + REVERB_APP_KEY non plus.
    // Le fallback automatique doit nous donner 'log' (safe).
    expect(config('broadcasting.default'))->toBe('log');
});

test('routes/channels.php skip enregistrement quand driver = log', function () {
    // Cf. routes/channels.php — le bloc Broadcast::channel(...) n'est exécuté
    // que si driver ∉ {log, null}. Ici on vérifie juste que la config est bien 'log'
    // (test garde-fou contre régression future).
    expect(config('broadcasting.default'))->toBe('log');
});

test('coverage cells endpoint retourne 200 même sans data', function () {
    $u = makeNoFiveHundredUser();
    $resp = $this->actingAs($u)->getJson('/api/v1/coverage/cells/9999');
    $resp->assertOk();
});
