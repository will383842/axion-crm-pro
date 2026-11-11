<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Envoi\Routes\BaseRoutesEnvoi;
use Tests\Support\ContratOpenApiEnvoi;

/** R14a : alertes d'envoi (notifications du produit « envoi » + pauses ouvertes) et règles d'alerte. */
uses(BaseRoutesEnvoi::class)->group('envoi');

function notificationEnvoi(string $ws, string $userId, string $titre = 'Santé des envois : test'): string
{
    $id = (string) Str::uuid();
    DB::table('notifications')->insert([
        'id' => $id, 'workspace_id' => $ws, 'user_id' => $userId, 'type' => 'alerte.envoi', 'title' => $titre,
        'body' => 'Détail fictif.', 'action_url' => '/envoi/sante', 'produit' => 'envoi', 'gravite' => 'attention',
    ]);

    return $id;
}

test('la liste mêle notifications du produit envoi et pauses ouvertes, conforme au contrat', function () {
    $ws = $this->espace(true);
    $admin = $this->compte($ws, 'admin');
    notificationEnvoi($ws->id, $admin->id);
    DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'workspace_id' => $ws->id, 'user_id' => $admin->id, 'type' => 'alerte.crm', 'title' => 'Hors produit', 'produit' => 'crm', 'gravite' => 'info']);
    DB::table('envoi_pauses')->insert(['workspace_id' => $ws->id, 'portee' => 'global', 'motif' => 'plaintes']);

    $r = $this->actingAs($admin)->getJson('/api/v1/envoi/alertes')->assertOk();
    expect($r->json('data'))->toHaveCount(2);
    foreach ($r->json('data') as $a) {
        expect(ContratOpenApiEnvoi::ecarts('conformite.yaml', 'AlerteEnvoi', $a))->toBe([]);
    }
    expect(collect($r->json('data'))->pluck('titre')->implode('|'))->not->toContain('Hors produit');
});

test('régler une alerte ne touche JAMAIS envoi_pauses et reste idempotent', function () {
    $ws = $this->espace(true);
    $admin = $this->compte($ws, 'owner');
    DB::table('envoi_pauses')->insert(['workspace_id' => $ws->id, 'portee' => 'global', 'motif' => 'plaintes']);
    $avant = DB::table('envoi_pauses')->get()->toJson();
    $id = $this->actingAs($admin)->getJson('/api/v1/envoi/alertes')->json('data.0.id');

    foreach ([1, 2] as $_) {
        $r = $this->actingAs($admin)->postJson("/api/v1/envoi/alertes/{$id}/regler")->assertOk();
        expect($r->json('close'))->toBeTrue()->and($r->json('niveau'))->toBe('fait');
    }
    expect(DB::table('envoi_pauses')->get()->toJson())->toBe($avant)
        ->and(DB::table('envoi_alertes_reglees')->count())->toBe(1);
    // la pause reste dans la liste, fermée côté alerte seulement
    expect($this->actingAs($admin)->getJson('/api/v1/envoi/alertes')->json('data'))->toHaveCount(1);
});

test('régler une alerte inconnue ou d’un autre espace répond 404', function () {
    $ws = $this->espace(true);
    $autre = $this->espace(true);
    $admin = $this->compte($ws, 'owner');
    $voisin = $this->compte($autre, 'owner');
    $idAutre = notificationEnvoi($autre->id, $voisin->id);
    $this->actingAs($admin)->postJson("/api/v1/envoi/alertes/{$idAutre}/regler")->assertNotFound();
    $this->actingAs($admin)->postJson('/api/v1/envoi/alertes/inconnue/regler')->assertNotFound();
});

test('régler exige workspaces.manage ; lire exige users.manage', function () {
    $ws = $this->espace(true);
    $admin = $this->compte($ws, 'admin');
    $id = notificationEnvoi($ws->id, $admin->id);
    $this->actingAs($admin)->postJson("/api/v1/envoi/alertes/{$id}/regler")->assertForbidden();
    foreach (['operator', 'viewer'] as $role) {
        $this->actingAs($this->compte($ws, $role))->getJson('/api/v1/envoi/alertes')->assertForbidden();
    }
});

test('règles d’alerte : lecture par défaut, écriture conservée, motifs étendus acceptés, canal inconnu refusé', function () {
    $ws = $this->espace(true);
    $owner = $this->compte($ws, 'owner');
    $r = $this->actingAs($owner)->getJson('/api/v1/envoi/reglages/alertes')->assertOk();
    expect(ContratOpenApiEnvoi::ecarts('conformite.yaml', 'ReglagesAlertes', $r->json()))->toBe([]);

    $corps = ['data' => [
        ['id' => 'x', 'nom' => 'Réputation', 'condition' => 'reputation_mauvaise', 'seuil' => null, 'canaux' => ['console'], 'actif' => true],
        ['id' => 'y', 'nom' => 'Homme mort', 'condition' => 'homme_mort', 'seuil' => 7, 'canaux' => ['console', 'email'], 'actif' => false],
    ], 'seconde_adresse' => null, 'regroupement_minutes' => 15];
    $ecrit = $this->actingAs($owner)->putJson('/api/v1/envoi/reglages/alertes', $corps)->assertOk();
    expect(ContratOpenApiEnvoi::ecarts('conformite.yaml', 'ReglagesAlertes', $ecrit->json()))->toBe([]);
    $lu = $this->actingAs($owner)->getJson('/api/v1/envoi/reglages/alertes')->json('data');
    expect(collect($lu)->pluck('condition')->all())->toContain('reputation_mauvaise', 'homme_mort');

    $corps['data'][0]['canaux'] = ['sms'];
    $this->actingAs($owner)->putJson('/api/v1/envoi/reglages/alertes', $corps)->assertStatus(422);
    $corps['data'][0]['canaux'] = ['console'];
    $corps['data'][0]['condition'] = 'inventee';
    $this->actingAs($owner)->putJson('/api/v1/envoi/reglages/alertes', $corps)->assertStatus(422);
});

test('une seconde adresse d’alerte n’est JAMAIS enregistrée sans confirmation : 501 tant que le code n’existe pas', function () {
    $ws = $this->espace(true);
    $owner = $this->compte($ws, 'owner');
    $corps = ['data' => [], 'seconde_adresse' => 'relais@example.invalid', 'regroupement_minutes' => 15];
    $this->actingAs($owner)->putJson('/api/v1/envoi/reglages/alertes', $corps)->assertStatus(501);
    $this->actingAs($owner)->getJson('/api/v1/envoi/reglages/alertes')->assertJsonPath('seconde_adresse', null);
});

test('le test d’alerte réutilise la cloche et l’e-mail du Socle, limité à 6 par minute', function () {
    $ws = $this->espace(true);
    $owner = $this->compte($ws, 'owner');
    $this->actingAs($owner)->postJson('/api/v1/envoi/reglages/alertes/test')->assertOk();
    expect($this->resolutions)->toBe(0);
    foreach (range(1, 5) as $_) {
        $this->actingAs($owner)->postJson('/api/v1/envoi/reglages/alertes/test');
    }
    $this->actingAs($owner)->postJson('/api/v1/envoi/reglages/alertes/test')->assertStatus(429);
});
