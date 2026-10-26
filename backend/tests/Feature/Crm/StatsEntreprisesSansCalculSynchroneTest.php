<?php

/**
 * 26/10/2026 — la page « Entreprises » restait sur ses squelettes après chaque
 * déploiement : `cache:clear` vidait les indicateurs, et `/companies/stats`
 * les recalculait DANS la requête (jusqu'à 120 s), trois fois de suite avec les
 * nouveaux essais du front. Diagnostic : `docs/DIAG-LISTE-ENTREPRISES-2026-10-26.md`.
 *
 * Gardes : cache vide → réponse immédiate `total: null`, UN SEUL calcul même
 * avec trois appels, et la commande de réchauffage remplit bien le cache.
 *
 * Fixtures FICTIVES (dépôt public).
 */

use App\Http\Controllers\Api\CompaniesController;
use App\Models\User;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->espace = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $this->espace, 'slug' => 'zz-stats-' . substr(str_replace('-', '', $this->espace), 0, 8), 'name' => 'ZZ stats',
        'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->user = User::create([
        'id' => (string) Str::uuid(), 'email' => Str::uuid() . '@example.test', 'name' => 'Console',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $this->espace, 'first_login_completed_at' => now(),
    ]);
    $this->seed(PermissionsAndRolesSeeder::class);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->espace);
    $this->user->assignRole('owner');
    $this->actingAs($this->user);
    DB::table('companies')->insert([
        'workspace_id' => $this->espace, 'siren' => '9' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'denomination' => 'ZZ STATS ' . Str::random(6), 'size_category' => 'tpe', 'sector_main' => 'btp',
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

/** Nombre de calculs d'indicateurs (l'échantillon est leur première requête). */
function statsCalculs(callable $travail): int
{
    $vus = 0;
    DB::listen(function ($q) use (&$vus): void {
        if (stripos($q->sql, 'TABLESAMPLE') !== false) {
            $vus++;
        }
    });
    $travail();
    // Le calcul part APRÈS la réponse : on exécute ce qui est resté en attente.
    app(DeferredCallbackCollection::class)->invoke();

    return $vus;
}

test('cache vide : réponse immédiate total null, un seul calcul même avec 3 appels', function () {
    $premiere = null;
    $calculs = statsCalculs(function () use (&$premiere): void {
        $premiere = $this->getJson('/api/v1/companies/stats')->assertOk();
        $this->getJson('/api/v1/companies/stats')->assertOk();
        $this->getJson('/api/v1/companies/stats')->assertOk();
    });

    expect($premiere->json('total'))->toBeNull()
        ->and($premiere->json('computed_at'))->toBeNull()
        ->and($calculs)->toBe(1);

    // Le calcul différé a rempli le cache : l'appel suivant rend les chiffres, sans recalcul.
    $apres = $this->getJson('/api/v1/companies/stats')->assertOk();
    expect($apres->json('total'))->toBe(1);
});

test('calcul déjà en cours (verrou pris) : aucun second calcul', function () {
    $verrou = Cache::lock('crm:companies:stats:calcul:' . $this->espace, 150);
    expect($verrou->get())->toBeTrue();

    $calculs = statsCalculs(fn () => expect(app(CompaniesController::class)->rechaufferStats($this->espace))->toBeFalse());

    expect($calculs)->toBe(0)
        ->and(Cache::has(CompaniesController::cleStats($this->espace)))->toBeFalse();
    $verrou->release();
});

test('la commande de réchauffage remplit les indicateurs ET le total par défaut', function () {
    $this->artisan('crm:entreprises:rechauffer-cache', ['--workspace' => $this->espace])->assertSuccessful();

    expect(Cache::has(CompaniesController::cleStats($this->espace)))->toBeTrue();

    // Le total de la liste par défaut est servi sans nouveau comptage.
    $this->getJson('/api/v1/companies?vue=liste')->assertOk()->assertJsonPath('meta.total', 1);
    $recomptes = 0;
    DB::listen(function ($q) use (&$recomptes): void {
        if (preg_match('/select count\(\*\) as aggregate from "companies"/i', $q->sql) === 1) {
            $recomptes++;
        }
    });
    $this->getJson('/api/v1/companies?vue=liste')->assertOk();
    expect($recomptes)->toBe(0);
});
