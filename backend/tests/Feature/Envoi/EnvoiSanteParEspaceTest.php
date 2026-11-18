<?php

use App\Envoi\Contrat\SanteServeur;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * SANTE-ESP — `envoi:sante` sonde le serveur DANS le contexte de chaque espace qui porte un adaptateur réel
 * (lecture par `envoi_couples_reels_portes()`). Aucune connexion réseau : le serveur est une doublure.
 */
function santeEspace(bool $reel = true): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Sante', 'slug' => 'sante-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    if ($reel) {
        $nom = 'sante-' . substr($id, 0, 8) . '.example.test';
        DB::table('envoi_domaines')->insert(['workspace_id' => $id, 'nom' => $nom, 'achete_le' => '2026-01-01']);
        WorkspaceContext::run($id, fn () => DB::selectOne('SELECT envoi_domaine_adaptateur_changer(?, ?, ?, ?)', [$id, $nom, 'smtp', 'auteur-test']));
    }

    return $id;
}

/**
 * Joue la commande avec une doublure ; $reponses = ok par espace (clé = id d'espace).
 *
 * @param  array<string, bool>  $reponses
 * @return array{int, list<?string>} code de sortie et contexte vu à chaque appel
 */
function santeJouer(string $mode, array $reponses, bool $defaut = true): array
{
    $vus = [];
    $doublure = Mockery::mock(ServeurEnvoi::class);
    $doublure->shouldReceive('sante')->andReturnUsing(function () use (&$vus, $reponses, $defaut) {
        $ws = WorkspaceContext::current();
        $vus[] = $ws;

        return new SanteServeur($reponses[$ws] ?? $defaut, 'doublure');
    });
    app()->instance(ServeurEnvoi::class, $doublure);
    WorkspaceContext::clear();
    DB::statement("SELECT set_config('app.current_workspace_id', '', true)");
    if ($mode === 'app') {
        DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    }
    try {
        $code = Artisan::call('envoi:sante');
    } finally {
        if ($mode === 'app') {
            DB::statement('RESET ROLE');
        }
    }

    return [$code, $vus];
}

test('deux espaces avec un adaptateur réel : un appel par espace, chacun dans son contexte', function (string $mode) {
    $a = santeEspace();
    $b = santeEspace();
    [$code, $vus] = santeJouer($mode, []);
    $mes = array_values(array_filter($vus, fn ($v) => in_array($v, [$a, $b], true)));
    expect($code)->toBe(0)
        ->and($mes)->toEqualCanonicalizing([$a, $b])
        ->and($vus)->not->toContain(null);
})->with(['proprio', 'app']);

test('un espace KO suffit : sortie 1', function (string $mode) {
    $a = santeEspace();
    $b = santeEspace();
    [$code] = santeJouer($mode, [$b => false]);
    expect($code)->toBe(1);
})->with(['proprio', 'app']);

test('l’espace de départ est rendu intact après la sonde', function () {
    santeEspace();
    WorkspaceContext::clear();
    santeJouer('proprio', []);
    expect(WorkspaceContext::current())->toBeNull();
});

test('aucun espace avec adaptateur réel : un seul appel hors contexte, comme avant', function (string $mode) {
    santeEspace(false);
    [$code, $vus] = santeJouer($mode, []);
    expect($code)->toBe(0)->and($vus)->toBe([null]);
})->with(['proprio', 'app']);
