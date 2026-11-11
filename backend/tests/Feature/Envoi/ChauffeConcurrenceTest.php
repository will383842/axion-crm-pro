<?php

use App\Envoi\EmpreinteTemoin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * CONCURRENCE RÉELLE du quota de chauffe : deux processus PHP, chacun sa connexion Postgres.
 * Le jour 1 d'une boîte vaut 10 envois (CourbeChauffe::PALIERS). 9 sont déjà NOTÉS ; deux workers partent au même
 * instant et le « serveur » de chacun dort 1,5 s : la réservation du premier est validée mais pas notée quand le
 * second passe ses portes. Sans comptage des réservations en vol sous le même verrou, les deux passent : 11 envois.
 * L'espace est écrit en auto-commit par la connexion propriétaire (les fils ne voient pas RefreshDatabase), puis nettoyé ;
 * la boîte et ses compteurs notés vivent dans des tables temporaires de chaque ouvrier (voir ouvrier_chauffe.php).
 */
const CHAUFFE_JOUR = '2026-11-10';
const CHAUFFE_CLE = 'cle-de-test-chauffe';

function chauffeConcPreparer(): string
{
    $owner = DB::connection('pgsql_owner');
    $ws = (string) Str::uuid();
    $owner->table('workspaces')->insert(['id' => $ws, 'name' => 'ConcChauffe', 'slug' => 'concchauffe-' . substr($ws, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $ws]);
    // Mode témoins CONSERVÉ (comme PlafondConcurrenceTest) : le destinataire est un témoin déclaré. Aucune ligne `temoins_off`
    // n'est écrite au journal, que d'autres tests comptent.
    $owner->table('envoi_temoins')->insert(['workspace_id' => $ws, 'empreinte' => EmpreinteTemoin::calculer('temoin@example.test', CHAUFFE_CLE), 'libelle' => 'test']);

    return $ws;
}

function chauffeConcNettoyer(string $ws): void
{
    $owner = DB::connection('pgsql_owner');
    $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $ws]);
    $owner->table('envoi_temoins')->where('workspace_id', $ws)->delete();
    $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);
    $owner->table('workspaces')->where('id', $ws)->delete();
    $owner->table('envoi_compteur_global')->where('jour', CHAUFFE_JOUR)->delete(); // compteur agrégé, sans donnée de personne
    // Le journal est en ajout seul (sans clé étrangère) : ses lignes de test restent.
}

/** @return list<bool> accepté ou non, pour chacun des deux ouvriers */
function chauffeConcLancer(string $ws, int $notes): array
{
    $depart = microtime(true) + 3;
    $env = array_merge(getenv(), ['ENVOI_CLE_EMPREINTE' => CHAUFFE_CLE, 'APP_ENV' => 'testing']);
    $procs = [];
    foreach (['a', 'b'] as $cle) {
        $cmd = [PHP_BINARY, base_path('tests/Support/ouvrier_chauffe.php'), $ws, CHAUFFE_JOUR, 'cle-' . $cle, (string) $depart, '1.5', (string) $notes];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        expect(is_resource($p))->toBeTrue();
        $procs[] = [$p, $pipes];
    }
    $resultats = [];
    foreach ($procs as [$p, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        expect(proc_close($p))->toBe(0, "ouvrier en échec : {$err} {$out}");
        $resultats[] = (bool) (json_decode(trim($out), true)['accepte'] ?? false);
    }

    return $resultats;
}

test('concurrence : quota de la boîte à 10, 9 notés, deux workers au même instant => UN SEUL passe, jamais 11 envois', function () {
    $ws = chauffeConcPreparer();
    try {
        $acceptes = chauffeConcLancer($ws, 9);
        expect(count(array_filter($acceptes)))->toBe(1);
        // Une seule réservation a été posée : l'autre worker a vu la première (en vol) sous le même verrou et a reporté.
        $reservations = DB::connection('pgsql_owner')->table('envoi_journal_commandes')->where('workspace_id', $ws)->where('commande', 'injection')->count();
        expect($reservations)->toBe(1);
    } finally {
        chauffeConcNettoyer($ws);
    }
});
