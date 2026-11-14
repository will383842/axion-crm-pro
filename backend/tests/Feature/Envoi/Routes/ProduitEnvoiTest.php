<?php

namespace Tests\Feature\Envoi\Routes;

use Tests\Support\ContratOpenApiEnvoi;

uses(BaseRoutesEnvoi::class)->group('envoi');

test('espace SANS produit envoi : 404 sur chaque URI /envoi/*, même pour un propriétaire', function () {
    $user = $this->compte($this->espace(false), 'owner');
    $routes = $this->routesEnvoi();
    expect($routes)->not->toBeEmpty();
    foreach ($routes as [$methode, $uri]) {
        $this->actingAs($user)->json($methode, $uri, ['preuve' => 'x'])
            ->assertNotFound();
    }
});

test('espace SANS produit envoi : le 404 passe avant le contrôle de permission (pas de 403 qui trahit la surface)', function () {
    $user = $this->compte($this->espace(false), 'viewer');
    $this->actingAs($user)->getJson('/api/v1/envoi/sante')->assertNotFound();
    $this->actingAs($user)->postJson('/api/v1/envoi/feu-vert/manuel.port25/fait', ['preuve' => 'x'])->assertNotFound();
});

test('produit absent ou valeur inconnue : fermé (404)', function (mixed $etat) {
    $espace = $this->espace(false);
    $espace->update(['settings' => ['produits' => ['envoi' => $etat]]]);
    $user = $this->compte($espace, 'owner');
    $this->actingAs($user)->getJson('/api/v1/envoi/feu-vert')->assertNotFound();
})->with([['absent'], ['ACTIF '], [true], [1], [['actif']], ['']]);

// D3 : suspendu et résilié sont des états CONNUS du contrat : fermés aussi, mais avec un 403 qui explique (français).
test('produit « suspendu » ou « résilié » : 403 clair, code stable, aucun effet', function (string $etat, string $code, string $mot) {
    $espace = $this->espace(false);
    $espace->update(['settings' => ['produits' => ['envoi' => $etat]]]);
    $user = $this->compte($espace, 'viewer');
    $reponse = $this->actingAs($user)->getJson('/api/v1/envoi/feu-vert')->assertForbidden()->assertJsonPath('error', $code);
    expect($reponse->json('message'))->toContain($mot)
        ->and(ContratOpenApiEnvoi::ecarts('commun.yaml', 'Erreur', $reponse->json()))->toBe([]);
    $this->actingAs($user)->postJson('/api/v1/envoi/feu-vert/manuel.port25/fait', ['preuve' => 'x'])->assertForbidden()->assertJsonPath('error', $code);
    expect($this->resolutions)->toBe(0);
})->with([['suspendu', 'produit_suspendu', 'suspendu'], ['resilie', 'produit_resilie', 'résilié']]);

test('espace AVEC produit envoi : comportement inchangé (200 / 403 selon la permission)', function () {
    $espace = $this->espace(true);
    $this->actingAs($this->compte($espace, 'admin'))->getJson('/api/v1/envoi/sante')->assertOk();
    $this->actingAs($this->compte($espace, 'viewer'))->getJson('/api/v1/envoi/sante')->assertForbidden();
    $this->actingAs($this->compte($espace, 'viewer'))->getJson('/api/v1/envoi/feu-vert')->assertOk();
    $this->actingAs($this->compte($espace, 'viewer'))->postJson('/api/v1/envoi/feu-vert/manuel.port25/fait', ['preuve' => 'courte'])->assertForbidden();
});

test('sans session : 401 (le produit ne se devine pas sans être connecté)', function () {
    $this->getJson('/api/v1/envoi/sante')->assertUnauthorized();
});
