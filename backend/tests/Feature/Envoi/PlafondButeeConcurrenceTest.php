<?php

use App\Envoi\EmpreinteTemoin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * P1-PLAFOND — concurrence RÉELLE de la butée en base : 8 processus, un seul pré-remplissage (`envoi_compteur_espace.n = 295`,
 * connexion propriétaire : le rôle applicatif n'a pas INSERT), journal vide. Les plafonds PHP (300) laissent tout passer : seule
 * la butée limite à 5. Données écrites en auto-commit (les processus fils ne voient pas la transaction), retirées à la fin.
 */
test('butée : 8 processus avec 295 places prises ⇒ exactement 5 partent', function () {
    $cleEmpreinte = 'cle-test-butee';
    $owner = DB::connection('pgsql_owner');
    $ws = (string) Str::uuid();
    $jour = (new DateTimeImmutable('2100-01-01'))->modify('+' . random_int(0, 3000) . ' days')->format('Y-m-d');
    $owner->table('workspaces')->insert(['id' => $ws, 'name' => 'Butee', 'slug' => 'butee-' . substr($ws, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    try {
        $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $ws]);
        $owner->table('envoi_temoins')->insert(['workspace_id' => $ws, 'empreinte' => EmpreinteTemoin::calculer('temoin@example.test', $cleEmpreinte), 'libelle' => 'test']);
        $owner->table('envoi_compteur_espace')->insert(['workspace_id' => $ws, 'jour' => $jour, 'n' => 295]);
        $depart = microtime(true) + 2.5;
        $env = array_merge(getenv(), ['ENVOI_CLE_EMPREINTE' => $cleEmpreinte, 'APP_ENV' => 'testing', 'ENVOI_PLAFOND_JOUR' => '300']);
        $procs = [];
        for ($i = 0; $i < 8; $i++) {
            $p = proc_open([PHP_BINARY, base_path('tests/Support/ouvrier_butee.php'), $ws, $jour, "cle-{$i}", (string) $depart], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            expect(is_resource($p))->toBeTrue();
            $procs[] = [$p, $pipes];
        }
        $succes = 0;
        foreach ($procs as [$p, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            expect(proc_close($p))->toBe(0, "ouvrier en échec : {$err} {$out}");
            $succes += (json_decode(trim($out), true)['accepte'] ?? false) ? 1 : 0;
        }
        expect($succes)->toBe(5)
            ->and((int) $owner->table('envoi_compteur_espace')->where('workspace_id', $ws)->where('jour', $jour)->value('n'))->toBe(300);
    } finally {
        $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $ws]);
        $owner->table('envoi_compteur_espace')->where('workspace_id', $ws)->delete();
        $owner->table('envoi_temoins')->where('workspace_id', $ws)->delete();
        $owner->table('envoi_drapeaux')->where('workspace_id', $ws)->delete();
        $owner->table('workspaces')->where('id', $ws)->delete();
        $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
        $owner->table('envoi_compteur_global')->where('jour', $jour)->delete();
    }
});
