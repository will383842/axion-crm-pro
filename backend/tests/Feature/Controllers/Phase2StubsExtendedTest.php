<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makePhase2User(): User
{
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'p2-' . Str::random(6),
        'name' => 'P2 WS',
    ]);

    return User::create([
        'id' => (string) Str::uuid(),
        'email' => 'p2-' . Str::random(4) . '@test.local',
        'name' => 'P2',
        'password_hash' => Hash::make('SomePass!1234'),
        'current_workspace_id' => $workspace->id,
        'first_login_completed_at' => now(),
    ]);
}

// ⚠️ Sprint 19.7 : `/api/v1/campaigns` N'EST PLUS un bouchon Phase 2. La route
// est désormais servie par ScrapingCampaignsController (cf. routes/api.php,
// « Note : /campaigns retiré — implémenté en Sprint 19.7 »). Les deux cas qui
// attendaient un 501 sur cet endpoint testaient donc un comportement supprimé
// volontairement : ils vérifient à présent le comportement RÉEL.

// ⚠️ F7 (étape 0) : `/api/v1/crm` et `/api/v1/analytics` ne sont plus des
// bouchons Phase 2 non plus — les deux `Route::any(...)` fourre-tout ont été
// retirés (collision de nommage avec le chantier CRM cible). `/api/v1/crm`
// existe encore, mais seulement via ses sous-routes RÉELLES (console v2), et
// `/api/v1/analytics` n'existe plus du tout.

test('Phase2 stubs sans auth retournent 401', function () {
    $this->getJson('/api/v1/campaigns')->assertUnauthorized();
    $this->getJson('/api/v1/cold-email')->assertUnauthorized();
    $this->getJson('/api/v1/linkedin')->assertUnauthorized();
});

/**
 * H45-001 (S1) — LE 25e CAS DE LA FAMILLE « sans auth -> 401, jamais 500 ».
 *
 * Le test ci-dessus n'interroge le produit que par `getJson()`, qui pose
 * `Accept: application/json`. C'est le seul en-tete qui EVITAIT le defaut
 * A-001 : sans lui, `Authenticate` demandait `route('login')`, qui n'existe pas
 * dans cette API, et la reponse partait en 500 (`RouteNotFoundException`) au
 * lieu de 401. Mesure de l'agent 45 sur la PRODUCTION : 5 adresses sur 5,
 * `Accept: application/json` -> 401, `Accept: text/html` -> 500.
 *
 * Les 24 autres cas sont dans `Sprint189NoFiveHundredTest` ; l'explication
 * complete du mecanisme et du correctif y est ecrite. Les DEUX sites devaient
 * etre repares : l'audit en nomme souvent un, il y en a souvent deux.
 *
 * Le cas JSON reste joue ci-dessus, sur les MEMES trois adresses : c'est lui le
 * temoin. S'il rougissait en meme temps que celui-ci, on ne mesurerait plus
 * l'en-tete `Accept` mais la disparition des routes.
 */
test('H45-001 — Phase2 stubs sans auth ET sans Accept JSON retournent 401, jamais 500', function () {
    // ⚠️ `$this->get($uri, $entetes)` et NON `call()` : `call()` n'envoie pas
    // les en-tetes, et une sonde de l'audit s'y est deja fait prendre.
    $navigateur = ['Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'];
    $clientNu = ['Accept' => '*/*'];

    foreach (['/api/v1/campaigns', '/api/v1/cold-email', '/api/v1/linkedin'] as $url) {
        foreach ([$navigateur, $clientNu] as $entetes) {
            $reponse = $this->get($url, $entetes);

            // 401 EXACTEMENT, pas « moins que 500 » : un 404 (route disparue)
            // rendrait cette garde verte par vacuite.
            $this->assertSame(401, $reponse->getStatusCode(), sprintf(
                'A-001 est de retour : %s rend %d avec « Accept: %s », la ou il '
                . 'rend 401 avec « Accept: application/json ». Correctif : les '
                . 'deux pieces de bootstrap/app.php (redirectGuestsTo(fn () => null) '
                . 'ET shouldRenderJsonWhen sur api/*).',
                $url,
                $reponse->getStatusCode(),
                $entetes['Accept'],
            ));
        }
    }
});

test('campaigns n\'est plus un bouchon Phase 2 : 200 + liste paginée', function () {
    $u = makePhase2User();

    $this->actingAs($u)->getJson('/api/v1/campaigns')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta' => ['total', 'per_page', 'current_page', 'last_page']])
        // Aucune trace du contrat de bouchon ne doit subsister.
        ->assertJsonMissingPath('sprint')
        ->assertJsonMissingPath('error');
});

test('Phase2 cold-email avec auth → 501', function () {
    $u = makePhase2User();
    $this->actingAs($u)->getJson('/api/v1/cold-email')->assertStatus(501);
});

test('Phase2 linkedin avec auth → 501', function () {
    $u = makePhase2User();
    $this->actingAs($u)->getJson('/api/v1/linkedin')->assertStatus(501);
});

// Les deux cas « crm → 501 » et « analytics → 501 » ont été retirés avec les
// routes qu'ils décrivaient (F7). Voir PasDeStub501SousCrmEtAnalyticsTest.

// Le contrat de forme du bouchon (error/message/sprint) est inchangé : on le
// vérifie désormais sur un endpoint TOUJOURS bouchonné (`/cold-email`) puisque
// `/campaigns` a été implémenté.
test('Phase2 response shape inclus sprint metadata', function () {
    $u = makePhase2User();
    $resp = $this->actingAs($u)->getJson('/api/v1/cold-email');
    $resp->assertStatus(501)
        ->assertJsonStructure(['error', 'message', 'sprint'])
        ->assertJsonPath('error', 'not_implemented')
        ->assertJsonPath('sprint', 'Phase 2');
});
