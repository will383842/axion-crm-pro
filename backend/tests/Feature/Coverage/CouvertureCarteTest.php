<?php

use App\Http\Controllers\Api\CoverageController;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * LA CARTE DE FRANCE AFFICHAIT 0 % SUR UNE BASE DE 4,3 M DE FICHES (audit
 * visuel du 2026-10-02, lot 3 « des chiffres justes »).
 *
 * `GET /coverage?level=department` répondait `{"level":"department","cells":[]}`
 * (33 octets) : le garde `Schema::hasTable('coverage_matrix_cells')` rend
 * FAUX pour une vue matérialisée. Et une fois ce garde levé, la Corse et
 * l'outre-mer (`LEFT(postcode, 2)` = `20`, `97`) ne rejoignaient aucun
 * département.
 *
 * Fixtures FICTIVES (dépôt public).
 */
beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    Cache::flush();

    DB::table('countries')->insertOrIgnore([
        'code_iso2' => 'FR', 'code_iso3' => 'FRA', 'name_fr' => 'France', 'name_en' => 'France',
    ]);
    DB::table('regions')->insertOrIgnore(['code' => '84', 'name' => 'Auvergne-Rhône-Alpes', 'country_code' => 'FR']);
    DB::table('regions')->insertOrIgnore(['code' => '94', 'name' => 'Corse', 'country_code' => 'FR']);
    DB::table('regions')->insertOrIgnore(['code' => '04', 'name' => 'La Réunion', 'country_code' => 'FR']);
    foreach ([['38', 'Isère', '84'], ['2A', 'Corse-du-Sud', '94'], ['2B', 'Haute-Corse', '94'], ['974', 'La Réunion', '04']] as [$code, $nom, $region]) {
        DB::table('departments')->insertOrIgnore(['code' => $code, 'name' => $nom, 'region_code' => $region]);
    }
});

/** @return array{0: User, 1: string} */
function l3CouvertureConsole(): array
{
    $espace = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'cov-l3-' . Str::random(6), 'name' => 'Couverture L3']);
    $user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'cov-l3-' . Str::random(6) . '@example.test', 'name' => 'Couverture',
        'password_hash' => Hash::make('SomePass!1234'), 'current_workspace_id' => $espace->id, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($espace->id);
    $user->assignRole('admin');

    return [$user, (string) $espace->id];
}

function l3Fiche(string $espace, string $postcode): void
{
    DB::table('companies')->insert([
        'workspace_id' => $espace,
        'siren' => '9' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'denomination' => 'ZZ COUVERTURE ' . $postcode, 'postcode' => $postcode,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('le garde voit la vue materialisee la ou Schema::hasTable ne la voit pas', function () {
    // Le constat lui-même : si un jour Laravel voit les vues matérialisées,
    // ce test rougira et le garde maison pourra disparaître.
    expect(Schema::hasTable('coverage_matrix_cells'))->toBeFalse()
        ->and(CoverageController::matriceExiste())->toBeTrue();
});

test('la carte rend les vrais totaux par departement, Corse et outre-mer compris, en entiers', function () {
    [$user, $espace] = l3CouvertureConsole();
    l3Fiche($espace, '38000');
    l3Fiche($espace, '38100');
    l3Fiche($espace, '20000'); // Ajaccio → 2A
    l3Fiche($espace, '20200'); // Bastia → 2B
    l3Fiche($espace, '97400'); // Saint-Denis → 974
    DB::statement('REFRESH MATERIALIZED VIEW coverage_matrix_cells');

    $cellules = collect($this->actingAs($user)->getJson('/api/v1/coverage?level=department')
        ->assertOk()->json('cells'))->keyBy('code');

    // `keyBy` fait de « 38 » une clé ENTIÈRE (PHP) : on compare en chaînes.
    expect($cellules->keys()->map(fn ($k): string => (string) $k)->sort()->values()->all())->toBe(['2A', '2B', '38', '974'])
        ->and($cellules['38']['total'])->toBe(2)
        ->and($cellules['2A']['total'])->toBe(1)
        ->and($cellules['2B']['total'])->toBe(1)
        ->and($cellules['974']['total'])->toBe(1);
});

test('le niveau region agrege les memes cellules', function () {
    [$user, $espace] = l3CouvertureConsole();
    l3Fiche($espace, '20000');
    l3Fiche($espace, '20200');
    DB::statement('REFRESH MATERIALIZED VIEW coverage_matrix_cells');

    $cellules = collect($this->actingAs($user)->getJson('/api/v1/coverage?level=region')
        ->assertOk()->json('cells'))->keyBy('code');

    expect($cellules['94']['total'])->toBe(2);
});
