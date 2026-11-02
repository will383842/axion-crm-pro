<?php

/**
 * L3c-0 (ADR contrat v3 § 6, I3) — `rgpd.handle` n'appartient qu'au propriétaire.
 *
 * Le seeder ne tourne pas en production : un compte admin DÉJÀ en base garderait
 * le droit de déposer et de traiter une demande RGPD. La migration de données
 * `2026_11_04_000010_retirer_rgpd_handle_aux_admins` le lui retire (idempotente).
 *
 * Et une demande d'effacement venue de la console est SIGNALÉE à Will :
 * notification (événement `NotificationCreated`) + pastille « Demandes RGPD ».
 *
 * Fixtures fictives (adresses en .test).
 */

use App\Events\NotificationCreated;
use App\Models\RgpdRequest;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function asdrCompte(string $role, string $espace): User
{
    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => $role . '-' . Str::random(6) . '@asdr.test',
        'name' => ucfirst($role),
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $espace,
        'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($espace);
    $user->assignRole($role);

    return $user;
}

/** Remet un admin « historique » : le lien rôle↔permission `rgpd.handle` que le seeder retire désormais. */
function asdrRendreLeDroitALAdmin(): void
{
    $admin = DB::table('roles')->where('name', 'admin')->whereNull('team_id')->value('id');
    $handle = DB::table('permissions')->where('name', 'rgpd.handle')->value('id');
    DB::table('role_has_permissions')->updateOrInsert(['role_id' => $admin, 'permission_id' => $handle], []);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

function asdrMigration(): void
{
    $migration = require database_path('migrations/2026_11_04_000010_retirer_rgpd_handle_aux_admins.php');
    $migration->up();
}

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->espace = (string) Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'asdr-' . Str::random(6), 'name' => 'Espace',
    ])->id;
    $this->journaliste = (int) DB::table('journalists')->insertGetId([
        'workspace_id' => $this->espace, 'last_name' => 'ZZ', 'first_name' => 'Test',
        'email' => 'zz@asdr.test', 'source' => 'ours', 'opt_out' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->demande = RgpdRequest::create([
        'workspace_id' => $this->espace, 'type' => 'erasure', 'status' => 'pending',
        'subject_email' => 'personne@asdr.test', 'requested_at' => now(),
    ]);
});

test('un admin DÉJÀ en base (droit hérité) reçoit 403 sur les trois routes après la migration ; le owner passe', function () {
    asdrRendreLeDroitALAdmin();
    $admin = asdrCompte('admin', $this->espace);

    // TÉMOIN : avant la migration, le droit hérité ouvre la route (sinon le test ne prouve rien).
    $this->actingAs($admin)->postJson('/api/v1/rgpd/requests', ['type' => 'access', 'subject_email' => 'avant@asdr.test'])
        ->assertStatus(201);

    asdrMigration();
    asdrMigration(); // idempotente
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($admin);
    $this->postJson('/api/v1/rgpd/requests', ['type' => 'access', 'subject_email' => 'apres@asdr.test'])->assertForbidden();
    $this->postJson('/api/v1/rgpd/requests/' . $this->demande->id . '/process')->assertForbidden();
    $this->postJson("/api/v1/journalists/{$this->journaliste}/opt-out")->assertForbidden();

    $proprietaire = asdrCompte('owner', $this->espace);
    expect($this->actingAs($proprietaire)->postJson('/api/v1/rgpd/requests', ['type' => 'access', 'subject_email' => 'owner@asdr.test'])->status())
        ->toBe(201);
    expect($this->postJson("/api/v1/journalists/{$this->journaliste}/opt-out")->status())->not->toBe(403);
});

test('I1 — DELETE /journalists/{id} exige rgpd.handle : admin 403, owner 204 ; la ligne est masquée, jamais supprimée', function () {
    $admin = asdrCompte('admin', $this->espace);
    $this->actingAs($admin)->deleteJson("/api/v1/journalists/{$this->journaliste}")->assertForbidden();
    expect(DB::table('journalists')->where('id', $this->journaliste)->whereNull('deleted_at')->exists())->toBeTrue();

    $proprietaire = asdrCompte('owner', $this->espace);
    $reponse = $this->actingAs($proprietaire)->deleteJson("/api/v1/journalists/{$this->journaliste}");
    expect($reponse->status())->toBe(204);
    expect(DB::table('journalists')->where('id', $this->journaliste)->exists())->toBeTrue();
});

test('M1 — la migration ne garde le droit direct qu au owner DU MÊME espace : admin en A + owner en B perd le droit en A', function () {
    $handle = (int) DB::table('permissions')->where('name', 'rgpd.handle')->value('id');
    $espaceB = (string) Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'asdr-b-' . Str::random(6), 'name' => 'Espace B',
    ])->id;

    // Un seul compte : admin de l'espace A, owner de l'espace B.
    $compte = asdrCompte('admin', $this->espace);
    setPermissionsTeamId($espaceB);
    $compte->assignRole('owner');
    DB::table('model_has_permissions')->insert([
        'permission_id' => $handle, 'model_type' => User::class, 'model_id' => $compte->id, 'team_id' => $this->espace,
    ]);
    DB::table('model_has_permissions')->insert([
        'permission_id' => $handle, 'model_type' => User::class, 'model_id' => $compte->id, 'team_id' => $espaceB,
    ]);

    // Témoin : un compte admin ET owner du MÊME espace garde son droit direct.
    $double = asdrCompte('admin', $this->espace);
    setPermissionsTeamId($this->espace);
    $double->assignRole('owner');
    DB::table('model_has_permissions')->insert([
        'permission_id' => $handle, 'model_type' => User::class, 'model_id' => $double->id, 'team_id' => $this->espace,
    ]);

    asdrMigration();

    expect(DB::table('model_has_permissions')->where('permission_id', $handle)->where('model_id', $compte->id)->where('team_id', $this->espace)->count())->toBe(0)
        ->and(DB::table('model_has_permissions')->where('permission_id', $handle)->where('model_id', $compte->id)->where('team_id', $espaceB)->count())->toBe(1)
        ->and(DB::table('model_has_permissions')->where('permission_id', $handle)->where('model_id', $double->id)->where('team_id', $this->espace)->count())->toBe(1);
});

test('la migration retire aussi le droit posé DIRECTEMENT sur un admin, mais pas sur un owner', function () {
    $handle = (int) DB::table('permissions')->where('name', 'rgpd.handle')->value('id');
    $admin = asdrCompte('admin', $this->espace);
    $proprietaire = asdrCompte('owner', $this->espace);
    foreach ([$admin, $proprietaire] as $compte) {
        DB::table('model_has_permissions')->insert([
            'permission_id' => $handle, 'model_type' => User::class, 'model_id' => $compte->id, 'team_id' => $this->espace,
        ]);
    }

    asdrMigration();

    expect(DB::table('model_has_permissions')->where('permission_id', $handle)->where('model_id', $admin->id)->count())->toBe(0)
        ->and(DB::table('model_has_permissions')->where('permission_id', $handle)->where('model_id', $proprietaire->id)->count())->toBe(1)
        ->and(DB::table('role_has_permissions')->where('permission_id', $handle)
            ->whereIn('role_id', DB::table('roles')->where('name', 'admin')->pluck('id'))->count())->toBe(0);
});

test('une demande d\'effacement de la CONSOLE prévient le owner (NotificationCreated) et allume la pastille ; un admin ne voit rien', function () {
    config(['crm.console_v2' => true]);
    Cache::flush();
    Event::fake([NotificationCreated::class]);
    $proprietaire = asdrCompte('owner', $this->espace);
    $admin = asdrCompte('admin', $this->espace);

    // Une demande d'ACCÈS ne prévient personne (seul l'effacement est signalé).
    $this->actingAs($proprietaire)->postJson('/api/v1/rgpd/requests', ['type' => 'access', 'subject_email' => 'acces@asdr.test'])->assertStatus(201);
    expect(DB::table('notifications')->count())->toBe(0);

    $this->postJson('/api/v1/rgpd/requests', ['type' => 'erasure', 'subject_email' => 'efface@asdr.test'])->assertStatus(201);

    $notification = DB::table('notifications')->where('user_id', $proprietaire->id)->first();
    expect($notification)->not->toBeNull()
        ->and($notification->type)->toBe('rgpd.demande_effacement')
        ->and($notification->action_url)->toBe('/rgpd/requests')
        ->and(mb_strtolower((string) $notification->body))->not->toContain('efface@asdr.test');
    Event::assertDispatched(NotificationCreated::class, fn (NotificationCreated $e): bool => $e->userId === (string) $proprietaire->id);

    // Pastille : 2 demandes d'effacement en attente (celle du beforeEach + la nouvelle).
    $this->getJson('/api/v1/crm/a-traiter/compteurs')->assertOk()->assertJsonPath('demandes_rgpd', 2);

    Cache::flush();
    $this->actingAs($admin)->getJson('/api/v1/crm/a-traiter/compteurs')->assertOk()->assertJsonPath('demandes_rgpd', null);
});
