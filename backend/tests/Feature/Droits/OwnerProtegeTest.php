<?php

/**
 * S6 — Un admin ne peut JAMAIS nommer un owner (ni toucher à un owner).
 */

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->espace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-owner', 'name' => 'WS', 'settings' => [],
    ]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->espace->id);
});

function compteAvecRole(string $espaceId, string $role): User
{
    $u = User::create([
        'id' => (string) Str::uuid(),
        'email' => Str::uuid() . '@example.test',
        'name' => 'Personne factice',
        'password_hash' => Hash::make('MotDePasseDeTest12345!'),
        'current_workspace_id' => $espaceId,
        'first_login_completed_at' => now(),
    ]);
    $u->assignRole($role);

    return $u;
}

test('un admin qui invite quelqu’un avec le rôle owner reçoit 403, rien n’est créé', function () {
    $this->actingAs(compteAvecRole($this->espace->id, 'admin'));

    $this->postJson('/api/v1/users', [
        'email' => 'nouveau@example.test', 'name' => 'Nouveau', 'role' => 'owner',
    ])->assertForbidden();

    expect(User::where('email', 'nouveau@example.test')->exists())->toBeFalse();
});

test('un admin qui promeut un compte au rôle owner reçoit 403, le rôle ne change pas', function () {
    $this->actingAs(compteAvecRole($this->espace->id, 'admin'));
    $cible = compteAvecRole($this->espace->id, 'operator');

    $this->putJson('/api/v1/users/' . $cible->id, ['role' => 'owner'])->assertForbidden();

    setPermissionsTeamId($this->espace->id);
    expect($cible->fresh()->hasRole('owner'))->toBeFalse()
        ->and($cible->fresh()->hasRole('operator'))->toBeTrue();
});

test('un admin ne peut ni rétrograder ni fermer un owner', function () {
    $this->actingAs(compteAvecRole($this->espace->id, 'admin'));
    $owner = compteAvecRole($this->espace->id, 'owner');

    $this->putJson('/api/v1/users/' . $owner->id, ['role' => 'viewer'])->assertForbidden();
    $this->deleteJson('/api/v1/users/' . $owner->id)->assertForbidden();

    setPermissionsTeamId($this->espace->id);
    expect($owner->fresh()->hasRole('owner'))->toBeTrue()
        ->and($owner->fresh()->trashed())->toBeFalse();
});

test('un owner peut toujours nommer un owner (la garde ne bloque pas les ayants droit)', function () {
    $this->actingAs(compteAvecRole($this->espace->id, 'owner'));

    $this->postJson('/api/v1/users', [
        'email' => 'second@example.test', 'name' => 'Second', 'role' => 'owner',
    ])->assertCreated();
});

test('un admin peut toujours inviter un operator', function () {
    $this->actingAs(compteAvecRole($this->espace->id, 'admin'));

    $this->postJson('/api/v1/users', [
        'email' => 'operateur@example.test', 'name' => 'Operateur', 'role' => 'operator',
    ])->assertCreated();
});
