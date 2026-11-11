<?php

namespace Tests\Feature\Envoi\Routes;

use Illuminate\Support\Facades\DB;

uses(BaseRoutesEnvoi::class)->group('envoi');

/** Audit sécurité de #510 : `settings.produits` est réservé à l'exploitation, jamais modifiable par `PUT /workspace`. */
function reglagesBruts(string $espace): array
{
    return json_decode((string) DB::table('workspaces')->where('id', $espace)->value('settings'), true) ?: [];
}

test('un propriétaire ne peut pas s’activer le produit envoi par PUT /workspace (422) et la garde reste fermée', function () {
    $espace = $this->espace(false);
    $user = $this->compte($espace, 'owner');

    $this->actingAs($user)->putJson('/api/v1/workspace', ['settings' => ['produits' => ['envoi' => 'actif']]])
        ->assertStatus(422)->assertJsonValidationErrors(['settings.produits']);

    expect(reglagesBruts($espace->id))->not->toHaveKey('produits');
    $this->actingAs($user)->getJson('/api/v1/envoi/feu-vert')->assertNotFound();
});

test('même une valeur vide ou autre pour settings.produits est refusée', function (mixed $valeur) {
    $user = $this->compte($this->espace(false), 'owner');
    $this->actingAs($user)->putJson('/api/v1/workspace', ['settings' => ['produits' => $valeur]])->assertStatus(422);
})->with([[[]], ['actif'], [null], [['crm' => 'actif']]]);

test('enregistrer d’autres réglages CONSERVE settings.produits tel qu’en base', function () {
    $espace = $this->espace(true);
    $user = $this->compte($espace, 'owner');

    $this->actingAs($user)->putJson('/api/v1/workspace', ['settings' => ['fuseau' => 'Europe/Paris']])->assertOk();
    expect(reglagesBruts($espace->id))->toBe(['fuseau' => 'Europe/Paris', 'produits' => ['envoi' => 'actif']]);

    $this->actingAs($user)->putJson('/api/v1/workspace', ['settings' => []])->assertOk();
    expect(reglagesBruts($espace->id))->toBe(['produits' => ['envoi' => 'actif']]);
    $this->actingAs($user)->getJson('/api/v1/envoi/feu-vert')->assertOk();
});

test('comportement inchangé pour les autres clés : remplacement, retrait possible, espace sans produit', function () {
    $espace = $this->espace(false);
    $user = $this->compte($espace, 'owner');

    $this->actingAs($user)->putJson('/api/v1/workspace', ['settings' => ['a' => 1, 'b' => 2]])->assertOk();
    $this->actingAs($user)->putJson('/api/v1/workspace', ['settings' => ['a' => 3]])->assertOk();
    expect(reglagesBruts($espace->id))->toBe(['a' => 3]);
});
