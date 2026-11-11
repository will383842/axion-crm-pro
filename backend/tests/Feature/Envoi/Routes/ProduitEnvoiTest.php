<?php

namespace Tests\Feature\Envoi\Routes;

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

test('produit « suspendu » ou « résilié » ou valeur inconnue : fermé (404)', function (mixed $etat) {
    $espace = $this->espace(false);
    $espace->update(['settings' => ['produits' => ['envoi' => $etat]]]);
    $user = $this->compte($espace, 'owner');
    $this->actingAs($user)->getJson('/api/v1/envoi/feu-vert')->assertNotFound();
})->with([['suspendu'], ['resilie'], ['absent'], ['ACTIF '], [true], [1], [['actif']], ['']]);

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
