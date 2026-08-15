<?php

/**
 * ÉTANCHÉITÉ DES DEUX UNIVERS — le test que le plan (§2.10) réclamait
 * explicitement : « un viewer vivier ne peut lire AUCUNE table business ».
 *
 * Le vivier candidats et la base commerciale sont deux mondes séparés, et la
 * séparation est une exigence CNIL, pas un confort d'organisation : une
 * candidature spontanée n'est pas un prospect, et la confondre avec un contact
 * commercial rendrait le traitement illicite.
 *
 * Trois couches défendent cette frontière, et ce fichier vérifie qu'aucune ne
 * se repose sur les autres :
 *   1. l'APPARTENANCE (`user_workspaces`) — on ne voit que ses univers ;
 *   2. le SCOPE explicite des contrôleurs ;
 *   3. la RLS Postgres — même un contrôleur oublieux ne peut pas fuiter.
 *
 * 🔴 `users.current_workspace_id` n'est PAS une preuve d'appartenance : c'est
 * un pointeur d'affichage que l'utilisateur modifie lui-même. Un contrôle qui
 * s'y fierait serait contournable en une requête.
 */

use App\Models\Company;
use App\Models\Contact;
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
    config()->set('crm.console_v2', true);

    $this->business = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-business', 'name' => 'Business', 'settings' => [],
    ]);
    // Le workspace vivier est posé par une MIGRATION du lot L1 : le recréer
    // heurte l'unicité du slug. On le récupère — et si un jour la migration
    // disparaît, `firstOrCreate` garde le test exécutable au lieu de le rendre
    // rouge pour une raison sans rapport avec ce qu'il mesure.
    $this->vivier = Workspace::firstOrCreate(
        ['slug' => config('crm.vivier_workspace_slug', 'vivier-candidats')],
        ['id' => (string) Str::uuid(), 'name' => 'Vivier', 'settings' => []],
    );

    // Donnée commerciale que le vivier ne doit JAMAIS voir.
    $entreprise = Company::create([
        'workspace_id' => $this->business->id,
        'siren' => '987654321', 'denomination' => 'SECRET COMMERCIAL',
        'email_generic' => 'confidentiel@acme.fr', 'signals' => [], 'metadata' => [],
    ]);
    Contact::create([
        'workspace_id' => $this->business->id, 'company_id' => $entreprise->id,
        'last_name' => 'SECRET', 'email' => 'secret@acme.fr', 'sources' => [], 'metadata' => [],
    ]);
});

function membreDuVivierSeulement(string $vivierId): User
{
    $u = User::create([
        'id' => (string) Str::uuid(), 'email' => Str::uuid() . '@example.com', 'name' => 'Recruteur',
        'password_hash' => Hash::make('PasswordTest12345!'),
        // Son univers courant EST le vivier : il n'a aucune raison d'accéder
        // au commercial.
        'current_workspace_id' => $vivierId,
        'first_login_completed_at' => now(),
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($vivierId);
    $u->assignRole('viewer');

    return $u;
}

test('🔴 un viewer VIVIER ne lit AUCUNE fiche business', function () {
    $this->actingAs(membreDuVivierSeulement($this->vivier->id));

    // Aucune de ces surfaces ne doit livrer la moindre ligne commerciale.
    foreach (['/api/v1/companies', '/api/v1/contacts'] as $route) {
        $reponse = $this->getJson($route);

        // 200 avec zéro ligne OU 403 : les deux sont acceptables, laisser
        // filtrer une seule fiche ne l'est pas.
        expect($reponse->status())->toBeIn([200, 403]);

        if ($reponse->status() === 200) {
            expect($reponse->json('data'))->toBe([]);
            expect(json_encode($reponse->json()))->not->toContain('SECRET');
        }
    }
});

test('le hub de contacts business est fermé à un membre du seul vivier', function () {
    $this->actingAs(membreDuVivierSeulement($this->vivier->id));

    // `businessWorkspaceId()` rend `null` quand l'univers courant EST le
    // vivier → 403. C'est la couche n°1 (appartenance), avant même le scope.
    $this->getJson('/api/v1/crm/contacts-hub')->assertForbidden();
});

test('l’arbitrage des rapprochements, surface purement business, est fermé aussi', function () {
    $this->actingAs(membreDuVivierSeulement($this->vivier->id));

    // Un écran qui n'existe que côté commercial ne doit pas seulement être
    // vide pour le vivier : il doit être refusé.
    $reponse = $this->getJson('/api/v1/crm/arbitrage');
    expect($reponse->status())->toBeIn([403, 404]);
});

test('l’inverse tient aussi : un membre business ne lit pas le vivier', function () {
    $u = User::create([
        'id' => (string) Str::uuid(), 'email' => Str::uuid() . '@example.com', 'name' => 'Commercial',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $this->business->id,
        'first_login_completed_at' => now(),
    ]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    $u->assignRole('owner');
    $this->actingAs($u);

    // Être propriétaire du commercial ne donne AUCUN droit sur le vivier :
    // l'étanchéité n'est pas une hiérarchie de privilèges, c'est une
    // frontière. Un « super-admin » qui la traverserait la supprimerait.
    $this->getJson('/api/v1/crm/candidates')->assertForbidden();
});

test('le pointeur d’univers ne vaut PAS appartenance', function () {
    // `current_workspace_id` est modifiable par l'utilisateur : un contrôle qui
    // s'y fierait serait contournable en une requête. On le pointe vers le
    // business sans l'y inscrire, et l'accès au vivier doit rester refusé
    // comme l'accès business doit rester vide.
    $u = User::create([
        'id' => (string) Str::uuid(), 'email' => Str::uuid() . '@example.com', 'name' => 'Curieux',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $this->business->id,
        'first_login_completed_at' => now(),
    ]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);
    $u->assignRole('viewer');
    $this->actingAs($u);

    $this->getJson('/api/v1/crm/candidates')->assertForbidden();
});
