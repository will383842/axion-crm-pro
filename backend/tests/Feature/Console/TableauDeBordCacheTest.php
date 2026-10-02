<?php

/**
 * GARDE : L'ACCUEIL NE RECOMPTE PLUS 4,3 M DE FICHES À CHAQUE OUVERTURE —
 * constat prod du 2026-10-02 (« le tableau de bord met ~10 s »).
 *
 * `GET /dashboard/stats` enchaînait à chaque appel des comptages complets
 * (`companies` 4,3 M, `contacts` 1,3 M en balayage séquentiel, répartition par
 * taille sur le tas). Il est désormais servi depuis un cache court, par espace.
 */

use App\Http\Controllers\Api\DashboardController;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function tdbCompte(string $suffixe): array
{
    $espace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'ws-tdb-' . strtolower($suffixe) . '-' . Str::random(4),
        'name' => 'TdB ' . $suffixe,
        'settings' => [],
    ]);

    $compte = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'tdb-' . strtolower($suffixe) . '@example.invalid',
        'name' => 'Operateur ' . $suffixe,
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $espace->id,
        'first_login_completed_at' => now(),
    ]);

    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $compte->id,
        'workspace_id' => $espace->id,
        'role_slug' => 'owner',
        'invited_at' => now(),
        'joined_at' => now(),
    ]);

    return [$compte, $espace->id];
}

function tdbFiche(string $espace, string $siren): void
{
    DB::table('companies')->insert([
        'workspace_id' => $espace,
        'denomination' => 'Fiche ' . $siren,
        'siren' => $siren,
        'relation_type' => 'prospect',
        'lifecycle_stage' => 'nouveau',
        'legal_basis' => 'legitimate_interest_b2b',
        'discovery_source' => 'site',
        'quality_score' => 0,
        'signals' => '{}', 'metadata' => '{}', 'field_origins' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Nombre de comptages lancés sur `companies` pendant `$travail`. */
function tdbComptages(callable $travail): int
{
    $vus = 0;
    DB::listen(function ($r) use (&$vus): void {
        if (stripos($r->sql, 'from "companies"') !== false && stripos($r->sql, 'count(') !== false) {
            $vus++;
        }
    });

    $travail();

    return $vus;
}

beforeEach(function () {
    Cache::flush();
});

test('deux ouvertures de l accueil ne recomptent pas deux fois', function () {
    [$compte, $espace] = tdbCompte('A');
    tdbFiche($espace, '820000001');
    $this->actingAs($compte);

    $premiere = tdbComptages(fn () => $this->getJson('/api/v1/dashboard/stats')->assertOk());
    $seconde = tdbComptages(fn () => $this->getJson('/api/v1/dashboard/stats')->assertOk());

    // Le témoin : le premier appel compte VRAIMENT (sinon la garde est muette).
    expect($premiere)->toBeGreaterThan(0);
    expect($seconde)->toBe(0, 'Le tableau de bord recompte la base à chaque ouverture (prod : ~10 s).');
});

test('le cache est PAR ESPACE : il ne fuit pas d un espace a l autre', function () {
    [$a, $espaceA] = tdbCompte('A');
    [$b, $espaceB] = tdbCompte('B');
    tdbFiche($espaceA, '820000011');
    tdbFiche($espaceA, '820000012');
    tdbFiche($espaceB, '820000021');

    expect($this->actingAs($a)->getJson('/api/v1/dashboard/stats')->json('companies_total'))->toBe(2);
    expect($this->actingAs($b)->getJson('/api/v1/dashboard/stats')->json('companies_total'))->toBe(1);
    expect(DashboardController::cle($espaceA))->not->toBe(DashboardController::cle($espaceB));
});

test('le libelle de periode suit la requete, il n est pas fige par le cache', function () {
    [$compte] = tdbCompte('P');
    $this->actingAs($compte);

    expect($this->getJson('/api/v1/dashboard/stats?period=7d')->json('period_label'))->toBe('7 derniers jours');
    expect($this->getJson('/api/v1/dashboard/stats?period=90d')->json('period_label'))->toBe('90 derniers jours');
});
