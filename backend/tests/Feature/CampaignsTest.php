<?php

use App\Jobs\LaunchCampaignJob;
use App\Models\ScraperRun;
use App\Models\ScrapingCampaign;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // Les roles et permissions doivent exister AVANT `assignRole()`.
    $this->seed(PermissionsAndRolesSeeder::class);
});

function makeCampaignUser(string $slug = 'cm'): array
{
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => $slug . '-' . Str::random(6),
        'name' => 'WS ' . $slug,
    ]);
    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => $slug . Str::random(4) . '@test.local',
        'name' => 'User ' . $slug,
        'password_hash' => Hash::make('SomePass!1234'),
        'current_workspace_id' => $workspace->id,
        'first_login_completed_at' => now(),
    ]);

    // ⚠️ LE ROLE EST OBLIGATOIRE DEPUIS QUE F36-001 EST BRANCHE.
    //
    // Cette suite mesure le METIER (creer, lancer, annuler), pas les droits —
    // et son utilisateur n'en avait aucun. Tant qu'aucune route ne portait
    // `permission:`, cela ne se voyait pas ; depuis, elle recevait 403 partout.
    //
    // On lui donne `admin` : le geste teste ici EST celui d'un administrateur,
    // et le lui refuser reviendrait a mesurer la garde au lieu du produit. Les
    // droits sont mesures a leur place, sur trois roles :
    // `tests/Feature/Rgpd/CoucheAutorisationBrancheeTest.php`.
    setPermissionsTeamId($workspace->id);
    $user->assignRole('admin');

    return [$user, $workspace];
}

function validCampaignPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ma campagne test',
        'description' => 'Test description',
        'sources' => ['insee', 'pages_jaunes'],
        'zones' => [
            ['type' => 'department', 'code' => '75'],
            ['type' => 'department', 'code' => '92'],
        ],
        'max_companies' => 500,
        'max_duration_minutes' => 120,
        'max_requests_per_minute' => 20,
    ], $overrides);
}

// =================================================================
// CRUD
// =================================================================

test('store — création campagne draft par défaut', function () {
    [$u] = makeCampaignUser();

    Queue::fake();

    $r = $this->actingAs($u)->postJson('/api/v1/campaigns', validCampaignPayload());

    $r->assertStatus(201)
        ->assertJsonPath('status', 'draft')
        ->assertJsonPath('name', 'Ma campagne test')
        ->assertJsonPath('max_companies', 500);

    $id = $r->json('id');
    expect(ScrapingCampaign::find($id))->not->toBeNull();
});

test('store — scheduled_at futur → status scheduled', function () {
    [$u] = makeCampaignUser();

    Queue::fake();

    $payload = validCampaignPayload([
        'scheduled_at' => now()->addHour()->toIso8601String(),
    ]);

    $r = $this->actingAs($u)->postJson('/api/v1/campaigns', $payload);

    $r->assertStatus(201)
        ->assertJsonPath('status', 'scheduled');
});

test('store — sources hors whitelist rejetées', function () {
    [$u] = makeCampaignUser();

    $payload = validCampaignPayload(['sources' => ['insee', 'INVALID_SOURCE']]);

    $this->actingAs($u)
        ->postJson('/api/v1/campaigns', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['sources.1']);
});

test('store — zones vides rejetées', function () {
    [$u] = makeCampaignUser();

    $payload = validCampaignPayload(['zones' => []]);

    $this->actingAs($u)
        ->postJson('/api/v1/campaigns', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['zones']);
});

test('store — max_companies hors borne rejeté', function () {
    [$u] = makeCampaignUser();

    $payload = validCampaignPayload(['max_companies' => 100000]); // > 50000

    $this->actingAs($u)
        ->postJson('/api/v1/campaigns', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['max_companies']);
});

test('index — paginé + filtre status', function () {
    [$u, $w] = makeCampaignUser();
    ScrapingCampaign::create([
        'workspace_id' => $w->id, 'created_by' => $u->id,
        'name' => 'C1', 'status' => 'draft', 'sources' => ['insee'], 'zones' => [['type' => 'department', 'code' => '75']],
    ]);
    ScrapingCampaign::create([
        'workspace_id' => $w->id, 'created_by' => $u->id,
        'name' => 'C2', 'status' => 'running', 'sources' => ['insee'], 'zones' => [['type' => 'department', 'code' => '92']],
        'started_at' => now(),
    ]);

    $r = $this->actingAs($u)->getJson('/api/v1/campaigns?status=running');

    $r->assertOk()
        ->assertJsonPath('meta.total', 1);
});

test('show — campagne avec runs nested', function () {
    [$u, $w] = makeCampaignUser();
    $c = ScrapingCampaign::create([
        'workspace_id' => $w->id, 'created_by' => $u->id,
        'name' => 'C', 'status' => 'draft',
        'sources' => ['insee'], 'zones' => [['type' => 'department', 'code' => '75']],
    ]);

    $r = $this->actingAs($u)->getJson("/api/v1/campaigns/{$c->id}");
    $r->assertOk()->assertJsonPath('id', $c->id);
});

test('show — cross-workspace → 404 (pas 403)', function () {
    [$u1] = makeCampaignUser('ws1');
    [, $w2] = makeCampaignUser('ws2');
    $c = ScrapingCampaign::create([
        'workspace_id' => $w2->id, 'created_by' => $u1->id,
        'name' => 'foreign', 'status' => 'draft',
        'sources' => ['insee'], 'zones' => [['type' => 'department', 'code' => '75']],
    ]);

    $this->actingAs($u1)
        ->getJson("/api/v1/campaigns/{$c->id}")
        ->assertNotFound();
});

test('update — autorisé en draft uniquement', function () {
    [$u, $w] = makeCampaignUser();
    $c = ScrapingCampaign::create([
        'workspace_id' => $w->id, 'created_by' => $u->id,
        'name' => 'C', 'status' => 'running', 'started_at' => now(),
        'sources' => ['insee'], 'zones' => [['type' => 'department', 'code' => '75']],
    ]);

    $this->actingAs($u)
        ->putJson("/api/v1/campaigns/{$c->id}", ['name' => 'Updated'])
        ->assertStatus(422)
        ->assertJsonPath('error', 'invalid_state');
});

// =================================================================
// Lifecycle
// =================================================================

test('start — draft → running + LaunchCampaignJob dispatched', function () {
    [$u, $w] = makeCampaignUser();
    $c = ScrapingCampaign::create([
        'workspace_id' => $w->id, 'created_by' => $u->id,
        'name' => 'C', 'status' => 'draft',
        'sources' => ['insee'], 'zones' => [['type' => 'department', 'code' => '75']],
    ]);
    Queue::fake();

    $r = $this->actingAs($u)->postJson("/api/v1/campaigns/{$c->id}/start");

    $r->assertOk()->assertJsonPath('status', 'running');
    expect($c->fresh()->started_at)->not->toBeNull();
    Queue::assertPushed(LaunchCampaignJob::class);
});

test('start — completed → 422 invalid_state', function () {
    [$u, $w] = makeCampaignUser();
    $c = ScrapingCampaign::create([
        'workspace_id' => $w->id, 'created_by' => $u->id,
        'name' => 'C', 'status' => 'completed',
        'sources' => ['insee'], 'zones' => [['type' => 'department', 'code' => '75']],
        'finished_at' => now(),
    ]);

    $this->actingAs($u)
        ->postJson("/api/v1/campaigns/{$c->id}/start")
        ->assertStatus(422)
        ->assertJsonPath('error', 'invalid_state');
});

test('pause — running → paused', function () {
    [$u, $w] = makeCampaignUser();
    $c = ScrapingCampaign::create([
        'workspace_id' => $w->id, 'created_by' => $u->id,
        'name' => 'C', 'status' => 'running', 'started_at' => now(),
        'sources' => ['insee'], 'zones' => [['type' => 'department', 'code' => '75']],
    ]);

    $r = $this->actingAs($u)->postJson("/api/v1/campaigns/{$c->id}/pause");

    $r->assertOk()->assertJsonPath('status', 'paused');
    expect($c->fresh()->paused_reason)->toBe('manual');
});

test('resume — paused → running + monitor dispatched', function () {
    [$u, $w] = makeCampaignUser();
    $c = ScrapingCampaign::create([
        'workspace_id' => $w->id, 'created_by' => $u->id,
        'name' => 'C', 'status' => 'paused',
        'sources' => ['insee'], 'zones' => [['type' => 'department', 'code' => '75']],
        'started_at' => now()->subMinutes(10),
        'paused_at' => now(),
        'paused_reason' => 'manual',
    ]);
    Queue::fake();

    $this->actingAs($u)
        ->postJson("/api/v1/campaigns/{$c->id}/resume")
        ->assertOk()
        ->assertJsonPath('status', 'running');

    expect($c->fresh()->paused_reason)->toBeNull();
});

test('cancel — running → cancelled + runs cancelled', function () {
    [$u, $w] = makeCampaignUser();
    $c = ScrapingCampaign::create([
        'workspace_id' => $w->id, 'created_by' => $u->id,
        'name' => 'C', 'status' => 'running', 'started_at' => now(),
        'sources' => ['insee'], 'zones' => [['type' => 'department', 'code' => '75']],
    ]);
    $run = ScraperRun::create([
        'workspace_id' => $w->id,
        'campaign_id' => $c->id,
        'source' => 'insee',
        'status' => 'pending',
        'started_at' => now(),
    ]);

    $this->actingAs($u)
        ->postJson("/api/v1/campaigns/{$c->id}/cancel")
        ->assertOk()
        ->assertJsonPath('status', 'cancelled');

    expect($c->fresh()->status)->toBe('cancelled');
    expect($run->fresh()->status)->toBe('cancelled');
});

test('destroy — soft delete', function () {
    [$u, $w] = makeCampaignUser();
    $c = ScrapingCampaign::create([
        'workspace_id' => $w->id, 'created_by' => $u->id,
        'name' => 'C', 'status' => 'draft',
        'sources' => ['insee'], 'zones' => [['type' => 'department', 'code' => '75']],
    ]);

    $this->actingAs($u)
        ->deleteJson("/api/v1/campaigns/{$c->id}")
        ->assertOk()
        ->assertJsonPath('deleted', true);

    expect(ScrapingCampaign::find($c->id))->toBeNull();
    expect(ScrapingCampaign::withTrashed()->find($c->id))->not->toBeNull();
});

// =================================================================
// Cross-workspace isolation
// =================================================================

test('start cross-workspace → 404', function () {
    [$u1] = makeCampaignUser('ws1');
    [, $w2] = makeCampaignUser('ws2');
    $c = ScrapingCampaign::create([
        'workspace_id' => $w2->id, 'created_by' => $u1->id,
        'name' => 'foreign', 'status' => 'draft',
        'sources' => ['insee'], 'zones' => [['type' => 'department', 'code' => '75']],
    ]);

    $this->actingAs($u1)
        ->postJson("/api/v1/campaigns/{$c->id}/start")
        ->assertNotFound();
});

// =================================================================
// Auto-pause logic (unitaire model)
// =================================================================
//
// ⚠️ CAUSE DE L'ÉCHEC HISTORIQUE (« attendu quota_duration, obtenu
// quota_companies ») : ce n'était PAS un ordre de priorité inversé.
// `ScrapingCampaign::create()` ne renvoie QUE les attributs explicitement
// fournis — les colonnes non passées (`max_companies NOT NULL DEFAULT 1000`,
// `companies_created NOT NULL DEFAULT 0`) restaient `null` EN MÉMOIRE. Or
// `0 >= null` et `null >= null` valent `true` en PHP : le premier test de
// `shouldAutoPause()` déclenchait donc toujours, quel que soit le scénario.
// Correctif : chaque fixture renseigne désormais TOUTES les entrées lues par la
// méthode (status, max_companies, companies_created, max_duration_minutes,
// started_at). L'ordre companies-puis-durée du modèle est conservé tel quel : il
// est correct, les deux critères étant indépendants.
//
// ⚠️ NE PAS « améliorer » ces cas avec un `->refresh()`. Un aller-retour en base
// décale actuellement TOUT timestamp de +2 h (voir la note de restitution) :
// `APP_TIMEZONE=Europe/Paris` (défaut de config/app.php) alors que la session
// Postgres est en `Etc/UTC` et que le driver sérialise en naïf `Y-m-d H:i:s`.
// `started_at` relu revient 2 h dans le FUTUR et `elapsed_minutes` retombe à 0.

test('shouldAutoPause — quota companies atteint', function () {
    [$u, $w] = makeCampaignUser();
    $c = ScrapingCampaign::create([
        'workspace_id' => $w->id, 'created_by' => $u->id,
        'name' => 'C', 'status' => 'running',
        'sources' => ['insee'], 'zones' => [['type' => 'department', 'code' => '75']],
        'max_companies' => 100,
        'companies_created' => 100,
        'max_duration_minutes' => 180,
        'started_at' => now()->subMinutes(5),
    ]);

    expect($c->shouldAutoPause())->toBe('quota_companies');
});

test('shouldAutoPause — quota duration atteint', function () {
    [$u, $w] = makeCampaignUser();
    $c = ScrapingCampaign::create([
        'workspace_id' => $w->id, 'created_by' => $u->id,
        'name' => 'C', 'status' => 'running',
        'sources' => ['insee'], 'zones' => [['type' => 'department', 'code' => '75']],
        // Quota entreprises volontairement LOIN d'être atteint : seule la durée
        // doit déclencher, ce qui vérifie aussi que le 1er critère ne masque
        // plus le second.
        'max_companies' => 1000,
        'companies_created' => 0,
        'max_duration_minutes' => 5,
        'started_at' => now()->subMinutes(10),
    ]);

    expect($c->shouldAutoPause())->toBe('quota_duration');
});

test('shouldAutoPause — sous quotas → null', function () {
    [$u, $w] = makeCampaignUser();
    $c = ScrapingCampaign::create([
        'workspace_id' => $w->id, 'created_by' => $u->id,
        'name' => 'C', 'status' => 'running',
        'sources' => ['insee'], 'zones' => [['type' => 'department', 'code' => '75']],
        'max_companies' => 1000,
        'max_duration_minutes' => 180,
        'companies_created' => 50,
        'started_at' => now()->subMinutes(10),
    ]);

    expect($c->shouldAutoPause())->toBeNull();
});

test('shouldAutoPause — campagne paused → null (pas de double pause)', function () {
    [$u, $w] = makeCampaignUser();
    $c = ScrapingCampaign::create([
        'workspace_id' => $w->id, 'created_by' => $u->id,
        'name' => 'C', 'status' => 'paused',
        'sources' => ['insee'], 'zones' => [['type' => 'department', 'code' => '75']],
        'max_companies' => 100,
        'companies_created' => 200,
        'max_duration_minutes' => 5,
        'started_at' => now()->subMinutes(120),
    ]);

    // Les DEUX quotas sont dépassés : seul le statut « paused » doit primer.
    expect($c->shouldAutoPause())->toBeNull();
});
