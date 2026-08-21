<?php

/**
 * GARDES DES ROUTES RGPD ET DU JOURNAL D'AUDIT — audit 360, B15-010 et B16-004 (S0).
 *
 * Ces routes n'exigeaient AUCUNE permission. N'importe quel compte authentifié —
 * y compris un `viewer`, censé être en lecture seule — pouvait déposer une
 * demande d'effacement et la TRAITER, donc effacer ou exporter les données de
 * n'importe quelle personne. Et lire le journal d'audit de tous les espaces.
 *
 * Le modèle de droits était pourtant juste depuis le début : `viewer` porte
 * `rgpd.view` mais pas `rgpd.handle`, et pas `audit.view`. Les permissions
 * existaient, elles n'étaient simplement jamais exigées.
 *
 * Ces gardes ont été vues rougir avant le correctif : le `viewer` recevait 200.
 */

use App\Models\RgpdRequest;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Crée un compte portant le rôle demandé, dans son propre espace. */
function compteAvecRole(string $role): User
{
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'rgpd-' . Str::random(8),
        'name' => 'Espace RGPD',
    ]);

    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => $role . '-' . Str::random(6) . '@rgpd.test',
        'name' => ucfirst($role),
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $workspace->id,
        'first_login_completed_at' => now(),
    ]);

    setPermissionsTeamId($workspace->id);
    $user->assignRole($role);

    return $user;
}

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
});

test('B15-010 — un compte en LECTURE SEULE ne peut pas deposer de demande RGPD', function () {
    $viewer = compteAvecRole('viewer');

    $this->actingAs($viewer)
        ->postJson('/api/v1/rgpd/requests', [
            'type' => 'erasure',
            'email' => 'quelquun@example.com',
        ])
        ->assertForbidden();
});

test('B15-010 — un compte en LECTURE SEULE ne peut pas TRAITER une demande RGPD', function () {
    $compte = compteAvecRole('viewer');

    // Une demande REELLE, pas un identifiant invente. La cle de `rgpd_requests`
    // est un ENTIER, et la resolution du modele passe AVANT la garde de
    // permission dans la pile : un identifiant inexistant rend 404 et la garde
    // ne s'exprime jamais. On mesurerait la resolution, pas le droit.
    // Constate en jouant cette garde.
    $demande = RgpdRequest::create([
        'workspace_id' => $compte->current_workspace_id,
        'type' => 'erasure',
        'status' => 'pending',
        'subject_email' => 'personne@example.com',
        'requested_at' => now(),
    ]);

    $this->actingAs($compte)
        ->postJson('/api/v1/rgpd/requests/' . $demande->id . '/process')
        ->assertForbidden();
});

test('B15-010 — TEMOIN : le meme compte peut CONSULTER les demandes', function () {
    $viewer = compteAvecRole('viewer');

    // `viewer` porte bien `rgpd.view` : la garde discrimine l'action, pas la
    // personne. Sans ce témoin, on ne saurait pas si le 403 vient du droit ou
    // d'une route cassée.
    expect($this->actingAs($viewer)->getJson('/api/v1/rgpd/requests')->status())
        ->not->toBe(403);
});

test('B15-010 — TEMOIN : un OWNER peut, lui, traiter une demande', function () {
    $compte = compteAvecRole('owner');
    $demande = RgpdRequest::create([
        'workspace_id' => $compte->current_workspace_id,
        'type' => 'erasure',
        'status' => 'pending',
        'subject_email' => 'personne@example.com',
        'requested_at' => now(),
    ]);

    // 403 ici serait un faux positif du correctif : l'owner doit franchir la
    // garde. Le statut d'arrivee depend du traitement lui-meme ; seul le 403
    // est interdit.
    expect($this->actingAs($compte)->postJson('/api/v1/rgpd/requests/' . $demande->id . '/process')->status())
        ->not->toBe(403);
});

test('B16-004 — un compte en LECTURE SEULE ne peut pas lire le journal d audit', function () {
    $viewer = compteAvecRole('viewer');

    $this->actingAs($viewer)->getJson('/api/v1/audit-logs')->assertForbidden();
});

test('B16-004 — TEMOIN : un ADMIN peut lire le journal d audit', function () {
    $admin = compteAvecRole('admin');

    expect($this->actingAs($admin)->getJson('/api/v1/audit-logs')->status())->not->toBe(403);
});
