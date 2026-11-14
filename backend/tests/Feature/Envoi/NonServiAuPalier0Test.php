<?php

use App\Envoi\Contrat\ServeurEnvoi;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\NonServiAuPalier0;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('envoi');

/** Lot R19 : chaque entrée de NonServiAuPalier0 est une vraie route qui répond bien 409 ou 501 (vérifié par appel). */
test('chaque entrée NonServiAuPalier0 a une route, une raison et répond au code annoncé (409 ou 501)', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    config(['mail.auth_mailer' => 'array']);
    $this->app->bind(ServeurEnvoi::class, fn () => throw new RuntimeException('ServeurEnvoi ne doit pas être résolu.'));
    $espace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'r19-' . Str::random(8), 'name' => 'Espace R19',
        'settings' => ['produits' => ['envoi' => 'actif']],
    ]);
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'r19-' . Str::random(6) . '@exemple.test', 'name' => 'Compte Test',
        'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
        'current_workspace_id' => $espace->id, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($espace->id);
    $user->assignRole('owner');

    $routes = [];
    foreach (Route::getRoutes() as $route) {
        foreach (array_diff($route->methods(), ['HEAD']) as $m) {
            $routes[] = $m . ' ' . preg_replace('/\{[^}]+\}/', '{}', preg_replace('#^api/v1#', '', $route->uri()));
        }
    }
    foreach (NonServiAuPalier0::entrees() as $couple => $entree) {
        expect(in_array($entree['code'], [409, 501], true))->toBeTrue("$couple : code 409 ou 501 seulement");
        expect(trim($entree['raison']))->not->toBe('', "$couple : raison obligatoire");
        expect($routes)->toContain(preg_replace('/\{[^}]+\}/', '{}', $couple));
        [$methode, $chemin] = explode(' ', $couple, 2);
        $url = '/api/v1' . preg_replace('/\{[^}]+\}/', 'exemple', $chemin);
        $this->actingAs($user, 'sanctum')->json($methode, $url)->assertStatus($entree['code']);
    }
});
