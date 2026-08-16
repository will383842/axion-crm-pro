<?php

use App\Models\ScrapingCampaign;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * VERROU DU DÉCALAGE DE 2 HEURES (corrigé le 2026-08-16).
 *
 * Ces tests rougissent dès que la session Postgres cesse d'être alignée sur
 * `app.timezone` — c'est-à-dire dès que quelqu'un retire `'timezone'` de
 * `config/database.php`, ou désaligne `DB_TIMEZONE` de `APP_TIMEZONE`.
 *
 * Sans ce verrou, le réglage peut disparaître d'un fichier de configuration
 * sans que rien ne proteste : c'est exactement comme ça que le défaut a vécu
 * jusqu'ici, et il ne s'est vu que parce qu'une campagne prévue pour 3 h en a
 * tourné 5.
 */
function horodatageWorkspaceEtUtilisateur(): array
{
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'tz-' . Str::random(6),
        'name' => 'TZ WS',
    ]);

    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'tz' . Str::random(4) . '@test.local',
        'name' => 'TZ',
        'password_hash' => Hash::make('SomePass!1234'),
        'current_workspace_id' => $workspace->id,
    ]);

    return [$user, $workspace];
}

test('la session Postgres est alignée sur le fuseau de l’application', function () {
    expect(DB::selectOne('SHOW timezone')->TimeZone)->toBe(config('app.timezone'));
});

test('un horodatage écrit puis relu revient au même instant', function () {
    [$user, $workspace] = horodatageWorkspaceEtUtilisateur();

    $ecrit = now();

    $campagne = ScrapingCampaign::create([
        'workspace_id' => $workspace->id,
        'created_by' => $user->id,
        'name' => 'TZ',
        'status' => 'running',
        'sources' => ['insee'],
        'zones' => [['type' => 'department', 'code' => '75']],
        'started_at' => $ecrit,
        'max_companies' => 1000,
        'companies_created' => 0,
        'max_duration_minutes' => 180,
    ]);

    // L'ALLER-RETOUR est le cœur du test : c'est lui qui décalait de +120 min.
    $relu = $campagne->fresh()->started_at;

    expect(abs(Carbon::parse($ecrit)->diffInSeconds($relu, false)))->toBeLessThan(2);
});

test('l’auto-pause sur quota de durée mord APRÈS un aller-retour en base', function () {
    [$user, $workspace] = horodatageWorkspaceEtUtilisateur();

    // Plafond 5 min, démarrée il y a 10 min : la campagne DOIT se mettre en
    // pause. Avec le décalage, `started_at` relu revenait 2 h dans le futur,
    // `elapsed_minutes` retombait à 0, et la campagne tournait indéfiniment.
    $campagne = ScrapingCampaign::create([
        'workspace_id' => $workspace->id,
        'created_by' => $user->id,
        'name' => 'TZ duree',
        'status' => 'running',
        'sources' => ['insee'],
        'zones' => [['type' => 'department', 'code' => '75']],
        'started_at' => now()->subMinutes(10),
        'max_companies' => 1000,
        'companies_created' => 0,
        'max_duration_minutes' => 5,
    ]);

    $relue = $campagne->fresh();

    expect($relue->elapsed_minutes)->toBeGreaterThanOrEqual(10)
        ->and($relue->shouldAutoPause())->toBe('quota_duration');
});

test('ce que Postgres écrit lui-même reste juste', function () {
    // `DEFAULT now()` n'a jamais été décalé : c'est ce qui interdit toute
    // correction en bloc des données déjà stockées, et ce qui fonde le
    // discriminant des microsecondes utilisé par `horodatages:corriger`.
    DB::statement('CREATE TEMP TABLE tz_controle (ecrit_par_pg timestamptz DEFAULT now())');
    DB::table('tz_controle')->insert(['ecrit_par_pg' => DB::raw('now()')]);

    $valeur = DB::table('tz_controle')->value('ecrit_par_pg');

    expect(abs(now()->diffInSeconds(Carbon::parse($valeur), false)))->toBeLessThan(5);
});
