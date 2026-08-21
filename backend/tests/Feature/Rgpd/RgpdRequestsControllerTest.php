<?php

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * 🔴 CE COMPTE PORTE MAINTENANT UN ROLE, et ce n'est pas un detail de banc.
 *
 * Ces tests creaient un utilisateur SANS aucun role. Ils passaient parce que les
 * routes RGPD n'exigeaient AUCUNE permission : n'importe quel compte
 * authentifie - y compris un `viewer` cense etre en lecture seule - pouvait
 * deposer une demande d'effacement et la TRAITER. C'etait le defaut B15-010 (S0),
 * mesure le 2026-08-19, et ces tests le garantissaient en vert.
 *
 * Les routes exigent desormais `rgpd.view` et `rgpd.handle` - des permissions qui
 * existaient deja dans le seeder, et que seuls `owner` et `admin` portent. Le
 * compte de test recoit donc `admin` : le test verifie que l'endpoint FONCTIONNE,
 * pas qu'il est ouvert a tous. La garde du droit, elle, est dans
 * `PermissionsRoutesRgpdTest`.
 */
function makeRgpdUser(): array
{
    test()->seed(PermissionsAndRolesSeeder::class);

    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'rgpd-' . Str::random(6),
        'name' => 'RGPD WS',
    ]);
    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'rgpd' . Str::random(4) . '@test.local',
        'name' => 'RGPD',
        'password_hash' => Hash::make('SomePass!1234'),
        'current_workspace_id' => $workspace->id,
        'first_login_completed_at' => now(),
    ]);

    setPermissionsTeamId($workspace->id);
    $user->assignRole('admin');

    return [$user, $workspace];
}

test('GET /rgpd/requests sans auth → 401', function () {
    $this->getJson('/api/v1/rgpd/requests')->assertUnauthorized();
});

test('GET /rgpd/requests authentifié → OK', function () {
    [$u] = makeRgpdUser();
    $this->actingAs($u)->getJson('/api/v1/rgpd/requests')->assertOk();
});

test('POST /rgpd/requests crée une demande', function () {
    [$u] = makeRgpdUser();
    $this->actingAs($u)
        ->postJson('/api/v1/rgpd/requests', [
            'type' => 'erasure',
            'subject_email' => 'subject@example.com',
        ])
        ->assertStatus(201)
        ->assertJsonStructure(['id', 'type', 'status', 'subject_email']);
});

test('POST /rgpd/requests valide le type', function () {
    [$u] = makeRgpdUser();
    $this->actingAs($u)
        ->postJson('/api/v1/rgpd/requests', [
            'type' => 'invalid_type',
            'subject_email' => 'subject@example.com',
        ])
        ->assertStatus(422);
});

test('POST /rgpd/requests valide email', function () {
    [$u] = makeRgpdUser();
    $this->actingAs($u)
        ->postJson('/api/v1/rgpd/requests', [
            'type' => 'access',
            'subject_email' => 'not-an-email',
        ])
        ->assertStatus(422);
});

test('POST /rgpd/requests accepte les 5 types valides', function () {
    [$u] = makeRgpdUser();
    foreach (['access', 'portability', 'erasure', 'rectification', 'opposition'] as $type) {
        $this->actingAs($u)
            ->postJson('/api/v1/rgpd/requests', [
                'type' => $type,
                'subject_email' => "$type@example.com",
            ])
            ->assertStatus(201);
    }
});

test('GET /rgpd/export/{token} avec token invalide → 404', function () {
    $this->getJson('/api/v1/rgpd/export/invalid_token_123')->assertNotFound();
});

test('GET /audit-logs/verify-chain authentifié → OK', function () {
    [$u] = makeRgpdUser();
    $this->actingAs($u)
        ->getJson('/api/v1/audit-logs/verify-chain')
        ->assertOk()
        ->assertJsonStructure(['valid']);
});

/**
 * 🔴 PORTABILITÉ RGPD (article 20) — le lien doit fonctionner SANS COMPTE.
 *
 * `GET /rgpd/export/{token}` vivait dans le groupe authentifié. Or son unique
 * destinataire est la PERSONNE CONCERNÉE, qui n'a par définition aucun compte
 * dans le CRM : la portabilité était donc inutilisable, et le jeton ne servait
 * à rien puisqu'il fallait déjà une session pour l'employer.
 *
 * Ces tests verrouillent les deux moitiés de la règle : le lien s'ouvre sans
 * authentification, et un jeton inconnu rend 404 — jamais 401, qui révélerait
 * l'existence de la ressource, ni 200, qui livrerait des données.
 */
test('le lien de portabilité s’ouvre SANS authentification', function () {
    // Aucun `actingAs` : on est un visiteur anonyme, muni du seul jeton.
    $reponse = $this->getJson('/api/v1/rgpd/export/jeton-inconnu-mais-bien-forme');

    expect($reponse->status())->not->toBe(401);
    expect($reponse->status())->toBe(404);
    expect($reponse->json('error'))->toBe('invalid_or_expired_token');
});

test('un jeton EXPIRÉ ne livre rien', function () {
    $email = 'sujet@example.com';

    // `rgpd_requests.workspace_id` est NOT NULL : une demande appartient
    // toujours à un univers, même quand son destinataire n'a pas de compte.
    // L'omettre faisait échouer la fixture sur une contrainte de schéma, donc
    // sans jamais atteindre ce qu'on veut vérifier — l'expiration.
    [, $workspace] = makeRgpdUser();

    DB::table('rgpd_requests')->insert([
        // `rgpd_requests.id` est un ENTIER auto-incrémenté, pas un UUID : on
        // laisse la base le poser. Le schéma réel tranche, pas l'habitude.
        'workspace_id' => $workspace->id,
        'subject_email' => $email,
        'type' => 'portability',
        'status' => 'done',
        'export_token' => hash('sha256', 'jeton-perime'),
        // Expiré depuis hier : la fenêtre de 7 jours est une garantie, pas une
        // indication — un export qui survivrait à sa date serait une fuite
        // qui dort.
        'export_expires_at' => now()->subDay(),
        'requested_at' => now()->subDays(9),
        'processed_at' => now()->subDays(8),
        'created_at' => now()->subDays(9),
        'updated_at' => now()->subDays(8),
    ]);

    $this->getJson('/api/v1/rgpd/export/jeton-perime')->assertNotFound();
});
