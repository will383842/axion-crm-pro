<?php

use App\Envoi\EmpreinteTemoin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * CONCURRENCE RÉELLE : plusieurs processus PHP, chacun avec sa propre connexion
 * Postgres, injectent au même instant. Le plafond dur ne doit jamais être
 * dépassé (verrou consultatif du jour : comptage + appel + note atomiques).
 * Les données sont écrites en auto-commit par la connexion propriétaire (les
 * processus fils ne voient pas la transaction de RefreshDatabase).
 */
const CLE_TEST_CONCURRENCE = 'cle-de-test-concurrence';

/** @return array{string, list<string>} jour d'essai (lointain, aléatoire) et espaces créés */
function preparerEspaces(int $combien): array
{
    $owner = DB::connection('pgsql_owner');
    $jour = (new DateTimeImmutable('2100-01-01'))->modify('+' . random_int(0, 3000) . ' days')->format('Y-m-d');
    $ids = [];
    for ($i = 0; $i < $combien; $i++) {
        $id = (string) Str::uuid();
        $owner->table('workspaces')->insert(['id' => $id, 'name' => 'Conc', 'slug' => 'conc-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
        $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $id]);
        $owner->table('envoi_temoins')->insert(['workspace_id' => $id, 'empreinte' => EmpreinteTemoin::calculer('temoin@example.test', CLE_TEST_CONCURRENCE), 'libelle' => 'test']);
        $ids[] = $id;
    }

    return [$jour, $ids];
}

function nettoyerEspaces(string $jour, array $ids): void
{
    $owner = DB::connection('pgsql_owner');
    foreach ($ids as $id) {
        $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $id]);
        $owner->table('envoi_temoins')->where('workspace_id', $id)->delete();
        $owner->table('envoi_drapeaux')->where('workspace_id', $id)->delete();
        $owner->table('workspaces')->where('id', $id)->delete();
    }
    $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    $owner->table('envoi_compteur_global')->where('jour', $jour)->delete(); // compteur agrégé, sans donnée de personne
    // Le journal est en ajout seul : ses lignes de test restent (sans clé étrangère).
}

/**
 * @param  list<array{string, string}>  $taches  [workspace, clé]
 * @return list<bool>
 */
function lancerOuvriers(array $taches, string $jour, int $plafond, int $global): array
{
    $depart = microtime(true) + 2.5;
    $env = array_merge(getenv(), ['ENVOI_CLE_EMPREINTE' => CLE_TEST_CONCURRENCE, 'APP_ENV' => 'testing']);
    $procs = [];
    foreach ($taches as [$ws, $cle]) {
        $cmd = [PHP_BINARY, base_path('tests/Support/ouvrier_injection.php'), $ws, $jour, $cle, (string) $plafond, (string) $global, (string) $depart];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        expect(is_resource($p))->toBeTrue();
        $procs[] = [$p, $pipes];
    }
    $resultats = [];
    foreach ($procs as [$p, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($p))->toBe(0, "ouvrier en échec : {$err} {$out}");
        $resultats[] = (bool) (json_decode(trim($out), true)['accepte'] ?? false);
    }

    return $resultats;
}

function lignesInjection(string $ws, string $jour): int
{
    $owner = DB::connection('pgsql_owner');

    return (int) $owner->table('envoi_journal_commandes')->where('workspace_id', $ws)->where('commande', 'injection')->where('jour', $jour)->count();
}

test('concurrence : 8 processus, plafond 3 par espace => exactement 3 partent, jamais 4', function () {
    [$jour, $ids] = preparerEspaces(1);
    try {
        $taches = [];
        for ($i = 0; $i < 8; $i++) {
            $taches[] = [$ids[0], "cle-{$i}"];
        }
        $acceptes = lancerOuvriers($taches, $jour, 3, 300);
        expect(count(array_filter($acceptes)))->toBe(3);
        expect(lignesInjection($ids[0], $jour))->toBe(3);
    } finally {
        nettoyerEspaces($jour, $ids);
    }
});

test('concurrence : plafond GLOBAL 2 sur deux espaces => 2 partent au total', function () {
    [$jour, $ids] = preparerEspaces(2);
    try {
        $taches = [];
        for ($i = 0; $i < 4; $i++) {
            $taches[] = [$ids[0], "a-{$i}"];
            $taches[] = [$ids[1], "b-{$i}"];
        }
        $acceptes = lancerOuvriers($taches, $jour, 5, 2);
        expect(count(array_filter($acceptes)))->toBe(2);
        expect(lignesInjection($ids[0], $jour) + lignesInjection($ids[1], $jour))->toBe(2);
    } finally {
        nettoyerEspaces($jour, $ids);
    }
});

test('concurrence : la même clé lancée 5 fois en parallèle ne compte qu’une fois', function () {
    [$jour, $ids] = preparerEspaces(1);
    try {
        $taches = array_fill(0, 5, [$ids[0], 'meme-cle']);
        $acceptes = lancerOuvriers($taches, $jour, 3, 300);
        expect(count(array_filter($acceptes)))->toBe(5); // rejeu idempotent : même résultat pour tous
        expect(lignesInjection($ids[0], $jour))->toBe(1);
    } finally {
        nettoyerEspaces($jour, $ids);
    }
});
