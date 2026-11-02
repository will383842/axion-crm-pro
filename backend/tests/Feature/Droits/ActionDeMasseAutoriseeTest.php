<?php

/**
 * S6 — L'action de masse exige une permission, vérifiée par la politique.
 *
 * Périmètre du lot : la politique (`CompanyPolicy::actionDeMasse`). Le câblage
 * de la route `POST /api/v1/crm/bulk` (routes/api.php, BulkController) est hors
 * de la liste fermée du lot S6 : il est noté dans la PR (« Hors périmètre »).
 */

use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $this->espace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-masse', 'name' => 'WS', 'settings' => [],
    ]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->espace->id);
});

function s6ActeurAvecRole(string $espaceId, string $role): User
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

test('un viewer n’a pas le droit d’agir en masse : refus, sans effet', function () {
    $viewer = s6ActeurAvecRole($this->espace->id, 'viewer');

    expect(Gate::forUser($viewer)->allows('actionDeMasse', Company::class))->toBeFalse()
        ->and(Gate::forUser($viewer)->inspect('actionDeMasse', Company::class)->denied())->toBeTrue();
});

test('un compte sans aucun rôle n’a pas le droit d’agir en masse', function () {
    $u = User::create([
        'id' => (string) Str::uuid(),
        'email' => Str::uuid() . '@example.test',
        'name' => 'Sans rôle',
        'password_hash' => Hash::make('MotDePasseDeTest12345!'),
        'current_workspace_id' => $this->espace->id,
        'first_login_completed_at' => now(),
    ]);

    expect(Gate::forUser($u)->allows('actionDeMasse', Company::class))->toBeFalse();
});

test('owner, admin et operator gardent le droit d’agir en masse', function () {
    foreach (['owner', 'admin', 'operator'] as $role) {
        $u = s6ActeurAvecRole($this->espace->id, $role);
        expect(Gate::forUser($u)->allows('actionDeMasse', Company::class))->toBeTrue($role);
    }
});
