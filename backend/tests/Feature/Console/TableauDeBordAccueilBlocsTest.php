<?php

/**
 * NOUVEL ACCUEIL EN BLOCS (maquette validée par Will, 03/10/2026) — les trois
 * chiffres que `GET /dashboard/stats` gagne pour le bloc « Ma base » :
 *
 *  - `companies_enriched` : les fiches vivantes qui portent un `enriched_at`
 *    (la tuile « Fiches enrichies » en tire une part du total) ;
 *  - `prospects_joignables` / `prospects_joignables_idf` : le nombre de
 *    membres des audiences système « Prospects contactables » et « … —
 *    Île-de-France », tel que le rafraîchissement nocturne l'a écrit.
 *
 * Ce que ces gardes tiennent : les bons chiffres, cloisonnés par espace ;
 * `null` (jamais 0) pour une audience absente ou jamais rafraîchie ; la clé de
 * cache passée en `v5` ; et, sous le RÔLE DE PRODUCTION (`axion_app`), le
 * comptage des fiches enrichies servi par son index partiel.
 *
 * Fixtures FICTIVES (dépôt public).
 */

use App\Http\Controllers\Api\DashboardController;
use App\Models\User;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function blocsCompte(string $espace): User
{
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'blocs-' . Str::random(8) . '@example.invalid', 'name' => 'ZZ Accueil',
        'password_hash' => Hash::make('PasswordTest12345!'), 'current_workspace_id' => $espace, 'first_login_completed_at' => now(),
    ]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($espace);
    $user->assignRole('owner');

    return $user;
}

/** @param  array<string, mixed>  $champs */
function blocsFiche(string $espace, array $champs = []): int
{
    return (int) DB::table('companies')->insertGetId($champs + [
        'workspace_id' => $espace,
        'siren' => '9' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'denomination' => 'ZZ ACCUEIL ' . Str::random(6),
        'created_at' => now()->subDays(10), 'updated_at' => now(),
    ]);
}

function blocsAudience(string $espace, string $nom, int $membres, mixed $rafraichie): int
{
    return (int) DB::table('email_audiences')->insertGetId([
        'workspace_id' => $espace, 'name' => $nom, 'criteria' => '{}',
        'member_count' => $membres, 'refreshed_at' => $rafraichie,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

beforeEach(function () {
    Cache::flush();
    $this->seed(PermissionsAndRolesSeeder::class);
});

test('la clé de cache passe en v5 : la forme de la réponse a changé', function () {
    $espace = (string) Str::uuid();

    expect(DashboardController::cle($espace))->toBe('crm:dashboard:stats:v5:' . $espace);
});

test('fiches enrichies : toutes les fiches vivantes avec enriched_at, ni la corbeille ni un autre espace', function () {
    $espace = F::espace('zz-blocs-enr');
    $autre = F::espace('zz-blocs-enr-autre');
    blocsFiche($espace, ['enriched_at' => now()->subMonths(3)]);
    blocsFiche($espace, ['enriched_at' => now()->subHour()]);
    blocsFiche($espace);
    blocsFiche($espace, ['enriched_at' => now()->subDay(), 'deleted_at' => now()]);
    blocsFiche($autre, ['enriched_at' => now()->subDay()]);

    $this->actingAs(blocsCompte($espace));
    $r = $this->getJson('/api/v1/dashboard/stats')->assertOk();

    expect($r->json('companies_enriched'))->toBe(2)
        ->and($r->json('companies_total'))->toBe(3);
});

test('prospects joignables : le nombre de membres des deux audiences système, cloisonné par espace', function () {
    $espace = F::espace('zz-blocs-aud');
    $autre = F::espace('zz-blocs-aud-autre');
    blocsAudience($espace, DashboardController::AUDIENCE_JOIGNABLES, 410, now()->subHours(5));
    blocsAudience($espace, DashboardController::AUDIENCE_JOIGNABLES_IDF, 141, now()->subHours(5));
    blocsAudience($autre, DashboardController::AUDIENCE_JOIGNABLES, 999, now()->subHours(5));

    $this->actingAs(blocsCompte($espace));
    $r = $this->getJson('/api/v1/dashboard/stats')->assertOk();

    expect($r->json('prospects_joignables'))->toBe(410)
        ->and($r->json('prospects_joignables_idf'))->toBe(141);
});

test('audience absente, supprimée ou jamais rafraîchie : null, jamais 0', function () {
    $espace = F::espace('zz-blocs-null');
    // Jamais rafraîchie : `member_count` vaut 0 par défaut, ce qui se lirait « personne ».
    blocsAudience($espace, DashboardController::AUDIENCE_JOIGNABLES, 0, null);
    // Supprimée : ne compte plus.
    $idf = blocsAudience($espace, DashboardController::AUDIENCE_JOIGNABLES_IDF, 50, now());
    DB::table('email_audiences')->where('id', $idf)->update(['deleted_at' => now()]);

    $this->actingAs(blocsCompte($espace));
    $r = $this->getJson('/api/v1/dashboard/stats')->assertOk();

    expect(array_key_exists('prospects_joignables', $r->json()))->toBeTrue()
        ->and($r->json('prospects_joignables'))->toBeNull()
        ->and($r->json('prospects_joignables_idf'))->toBeNull();
});

test('TEMOIN : une audience rafraîchie qui compte vraiment 0 membre rend 0', function () {
    $espace = F::espace('zz-blocs-zero');
    blocsAudience($espace, DashboardController::AUDIENCE_JOIGNABLES, 0, now());

    $this->actingAs(blocsCompte($espace));

    expect($this->getJson('/api/v1/dashboard/stats')->assertOk()->json('prospects_joignables'))->toBe(0);
});

test('plusieurs audiences du même nom : la plus ancienne (celle du seeder) fait foi', function () {
    $espace = F::espace('zz-blocs-double');
    blocsAudience($espace, DashboardController::AUDIENCE_JOIGNABLES, 300, now());
    blocsAudience($espace, DashboardController::AUDIENCE_JOIGNABLES, 7, now());

    $this->actingAs(blocsCompte($espace));

    expect($this->getJson('/api/v1/dashboard/stats')->assertOk()->json('prospects_joignables'))->toBe(300);
});

test('lecture des audiences en panne : null, et le résultat partiel n entre pas en cache', function () {
    $espace = F::espace('zz-blocs-panne');
    blocsAudience($espace, DashboardController::AUDIENCE_JOIGNABLES, 410, now());
    $panne = true;
    DB::connection()->beforeExecuting(function (string $sql) use (&$panne): void {
        if ($panne && stripos($sql, 'from "email_audiences"') !== false) {
            throw new RuntimeException('panne simulee de la lecture des audiences');
        }
    });

    $this->actingAs(blocsCompte($espace));
    $r = $this->getJson('/api/v1/dashboard/stats')->assertOk();

    expect($r->json('prospects_joignables'))->toBeNull()
        ->and(Cache::has(DashboardController::cle($espace)))->toBeFalse();

    $panne = false;
    expect($this->getJson('/api/v1/dashboard/stats')->assertOk()->json('prospects_joignables'))->toBe(410);
});

// ── Sous le rôle de production ──────────────────────────────────────────────

function blocsApp(): Connection
{
    return DB::connection('pgsql_app');
}

afterEach(function () {
    if (! array_key_exists('pgsql_app', DB::getConnections())) {
        return;
    }

    try {
        blocsApp()->statement('RESET enable_seqscan');
        blocsApp()->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    } catch (Throwable) {
        // Connexion déjà perdue : elle part avec le `disconnect()`.
    }
    blocsApp()->disconnect();
});

test('sous axion_app : le comptage des fiches enrichies passe par idx_companies_ws_enriched_at', function () {
    $role = blocsApp()->selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
    expect($role->rolsuper)->toBeFalse()->and($role->rolbypassrls)->toBeFalse();

    $espace = (string) Str::uuid();
    // La requête EXACTE du contrôleur (`compter` : espace, corbeille, puis le filtre).
    $q = DB::table('companies')->where('workspace_id', $espace)->whereNull('deleted_at')->whereNotNull('enriched_at')
        ->selectRaw('count(*) AS aggregate');

    blocsApp()->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $espace]);
    blocsApp()->statement('SET enable_seqscan = off');
    $plan = implode("\n", array_map(
        static fn ($l): string => (string) array_values((array) $l)[0],
        blocsApp()->select('EXPLAIN ' . $q->toSql(), $q->getBindings()),
    ));

    expect($plan)->toContain('idx_companies_ws_enriched_at')
        ->and($plan)->not->toContain('Seq Scan');
});
