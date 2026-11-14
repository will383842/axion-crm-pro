<?php

use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Reponses\StockerReponse;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    config(['envoi.cles_codes_campagne' => ['1' => 'cle-de-test-r19-seulement-0123456789abcdef'], 'envoi.cle_codes_campagne_version' => 1]);
    expect(NonServiAuPalier0::entrees())->not->toBeEmpty();

    // Ressources de l'espace courant pour les entrées conditionnelles (404 sans elles).
    $brouillon = $this->actingAs($user, 'sanctum')->postJson('/api/v1/envoi/brouillons', ['nom' => 'Brouillon R19'])->assertCreated()->json('id');
    $cle = hash('sha256', 'r19');
    $evenement = WorkspaceContext::run($espace->id, fn () => DB::table('envoi_evenements')->insertGetId([
        'workspace_id' => $espace->id, 'boite' => 'b@exemple.test', 'uid_validity' => 1, 'uid' => 1,
        'empreinte_message' => $cle, 'classe' => 'reponse_humaine', 'statut' => 'a_rattacher',
        'empreinte_expediteur' => ListeSuppression::empreinte('personne@exemple.test'), 'recu_le' => now(),
    ]));
    app(StockerReponse::class)->enregistrer($espace->id, $cle, 'Objet de test', 'Bonjour', 'personne@exemple.test', 'reponse_humaine');
    $reponse = WorkspaceContext::run($espace->id, fn () => DB::table('envoi_reponses')->where('evenement_id', $evenement)->value('id'));
    $serveur = WorkspaceContext::run($espace->id, fn () => DB::table('envoi_serveurs')->insertGetId([
        'workspace_id' => $espace->id, 'source' => 'srv-r19', 'url_base' => 'https://serveur-fictif.example.invalid:8443',
    ]));
    $jetons = ['@brouillon@' => $brouillon, '@reponse@' => $reponse, '@serveur@' => $serveur];

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
        [$methode] = explode(' ', $couple, 2);
        $url = '/api/v1' . strtr($entree['exemple'], $jetons);
        expect(preg_match('/[@{}]/', $url))->toBe(0, "$couple : exemple doit être une URL concrète");
        $this->actingAs($user, 'sanctum')->json($methode, $url)->assertStatus($entree['code']);
    }
});
