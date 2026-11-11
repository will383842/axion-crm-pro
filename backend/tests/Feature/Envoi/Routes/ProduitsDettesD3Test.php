<?php

namespace Tests\Feature\Envoi\Routes;

use App\Socle\Produits\ProduitsEspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\ContratOpenApiEnvoi;

uses(BaseRoutesEnvoi::class)->group('envoi');

/** Dettes D3 : suspension en console, publication de `produits` par /config/features. Fixtures fictives. */
function reglages(string $id): array
{
    return json_decode((string) DB::table('workspaces')->where('id', $id)->value('settings'), true);
}

test('produits:suspendre suspend, conserve les autres réglages, est idempotente', function () {
    $espace = $this->espace(true);
    DB::table('workspaces')->where('id', $espace->id)->update(['settings' => json_encode(['theme' => 'x', 'produits' => ['envoi' => 'actif']])]);

    $this->artisan('produits:suspendre', ['espace' => $espace->slug, 'produit' => 'envoi'])->assertSuccessful();
    $this->artisan('produits:suspendre', ['espace' => $espace->id, 'produit' => 'envoi'])->assertSuccessful();

    expect(reglages($espace->id))->toBe(['theme' => 'x', 'produits' => ['envoi' => 'suspendu']]);
    expect(ProduitsEspace::etat($espace->id, 'envoi'))->toBe('suspendu');
});

test('produits:suspendre refuse produit/espace inconnu, un produit jamais activé, et un produit résilié', function () {
    $this->artisan('produits:suspendre', ['espace' => 'inexistant', 'produit' => 'envoi'])->assertFailed();
    $espace = $this->espace(false);
    $this->artisan('produits:suspendre', ['espace' => $espace->slug, 'produit' => 'nimportequoi'])->assertFailed();
    $this->artisan('produits:suspendre', ['espace' => $espace->slug, 'produit' => 'envoi'])->assertFailed();
    expect(reglages($espace->id))->toBe([]);

    $espace->update(['settings' => ['produits' => ['envoi' => 'resilie']]]);
    $this->artisan('produits:suspendre', ['espace' => $espace->slug, 'produit' => 'envoi'])->assertFailed();
    expect(ProduitsEspace::etat($espace->id, 'envoi'))->toBe('resilie');
});

test('produits:suspendre ferme la garde, produits:activer la rouvre ; aucune route ne les expose', function () {
    $espace = $this->espace(true);
    $user = $this->compte($espace, 'admin');
    $this->actingAs($user)->getJson('/api/v1/envoi/sante')->assertOk();
    $this->artisan('produits:suspendre', ['espace' => $espace->slug, 'produit' => 'envoi'])->assertSuccessful();
    $this->actingAs($user)->getJson('/api/v1/envoi/sante')->assertForbidden()->assertJsonPath('code', 'produit_suspendu');
    $this->artisan('produits:activer', ['espace' => $espace->slug, 'produit' => 'envoi'])->assertSuccessful();
    $this->actingAs($user)->getJson('/api/v1/envoi/sante')->assertOk();

    foreach (Route::getRoutes() as $route) {
        expect($route->uri())->not->toContain('suspendre-produit')->not->toContain('produits/suspendre');
    }
});

test('/config/features publie produits : crm actif, envoi suivant ProduitsEspace', function (?string $etat, string $attendu) {
    $espace = $this->espace(false);
    if ($etat !== null) {
        $espace->update(['settings' => ['produits' => ['envoi' => $etat]]]);
    }
    $reponse = $this->actingAs($this->compte($espace, 'viewer'))->getJson('/api/v1/config/features');
    expect(ContratOpenApiEnvoi::ecarts('commun.yaml', 'Features', $reponse->json()))->toBe([]);
    $reponse->assertOk()
        ->assertJsonPath('produits.crm', 'actif')
        ->assertJsonPath('produits.envoi', $attendu);
})->with([[null, 'absent'], ['actif', 'actif'], ['suspendu', 'suspendu'], ['resilie', 'resilie'], ['bizarre', 'absent']]);
