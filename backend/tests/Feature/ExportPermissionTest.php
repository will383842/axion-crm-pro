<?php

/**
 * LES EXPORTS N'ÉTAIENT PROTÉGÉS QUE PAR UN THROTTLE.
 *
 * `GET /companies/export` sortait 4,29 M de fiches nominatives en CSV, et la
 * seule garde posée était `throttle:scraper-list` — qui limite la CADENCE, pas
 * le DROIT. N'importe quel compte authentifié, y compris un « viewer » en
 * lecture seule, pouvait emporter la base entière hors du système.
 *
 * Le plan (§2.10) demandait « export réservé admin ». On applique la
 * permission `data.export` que le dépôt définit DÉJÀ (seeder des rôles) plutôt
 * qu'un rôle en dur : elle couvre owner/admin/opérateur et exclut viewer, ce
 * qui est exactement le but. Inventer `role:admin` aurait au passage retiré
 * l'export aux opérateurs, qui l'ont légitimement.
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
    // Le VRAI référentiel du dépôt, pas une reconstitution : si le seeder
    // change, ces tests doivent bouger avec lui.
    $this->seed(PermissionsAndRolesSeeder::class);

    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-export', 'name' => 'WS', 'settings' => [],
    ]);

    // Spatie « teams » est activé : l'attribution d'un rôle exige un contexte
    // d'équipe (`model_has_roles.team_id` est NOT NULL). En requête réelle,
    // c'est `SetCurrentWorkspace` qui le pose.
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->workspace->id);
});

function utilisateurAvecRole(string $workspaceId, ?string $role): User
{
    $u = User::create([
        'id' => (string) Str::uuid(),
        'email' => Str::uuid() . '@example.com',
        'name' => 'U',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $workspaceId,
        'first_login_completed_at' => now(),
    ]);

    if ($role !== null) {
        $u->assignRole($role);
    }

    return $u;
}

test('un propriétaire peut exporter — la garde ne verrouille pas les ayants droit', function () {
    // 🔴 Le test qui compte le plus : une garde qui bloque TOUT LE MONDE est
    // pire que pas de garde. Will est `owner` en production.
    $this->actingAs(utilisateurAvecRole($this->workspace->id, 'owner'));

    $this->get('/api/v1/companies/export')->assertOk();
});

test('un opérateur peut exporter (la permission existe pour lui)', function () {
    $this->actingAs(utilisateurAvecRole($this->workspace->id, 'operator'));

    $this->get('/api/v1/companies/export')->assertOk();
});

test('un viewer NE PEUT PAS exporter les entreprises', function () {
    $this->actingAs(utilisateurAvecRole($this->workspace->id, 'viewer'));

    // ⬇️ Le défaut : avant, c'était 200 et 4,29 M de lignes.
    $this->get('/api/v1/companies/export')->assertForbidden();
});

test('un compte SANS aucun rôle ne peut pas exporter', function () {
    $this->actingAs(utilisateurAvecRole($this->workspace->id, null));

    $this->get('/api/v1/companies/export')->assertForbidden();
});

test('la garde couvre AUSSI les médias et les journalistes', function () {
    // Ces deux exports sortent des données nominatives de presse : les laisser
    // ouverts pendant qu'on ferme les entreprises n'aurait fermé qu'une porte
    // sur trois.
    $this->actingAs(utilisateurAvecRole($this->workspace->id, 'viewer'));

    $this->get('/api/v1/media/export')->assertForbidden();
    $this->get('/api/v1/journalists/export')->assertForbidden();
});

test('un viewer garde l’accès en LECTURE — on ferme l’export, pas la consultation', function () {
    $this->actingAs(utilisateurAvecRole($this->workspace->id, 'viewer'));

    $this->getJson('/api/v1/companies')->assertOk();
});
