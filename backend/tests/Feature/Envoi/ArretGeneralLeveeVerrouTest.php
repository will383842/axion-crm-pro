<?php

use App\Envoi\Depot\DepotSocleDb;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->group('envoi');

/*
 * T-SOCLE (a) : armer et lever « Tout arrêter » prennent le même verrou par espace que `EnvoiSocleController::arretGeneral`.
 * Processus réels, auto-commit : pas de RefreshDatabase (une transaction englobante cacherait tout au sous-processus).
 */

const VERROU_ARRET_CLE = "SELECT pg_advisory_xact_lock(hashtextextended('envoi_arret_general|' || ?, 0))";

function verrouEspaceArret(): string
{
    $proprio = DB::connection('pgsql_owner');
    $ws = (string) Str::uuid();
    $proprio->table('workspaces')->insert(['id' => $ws, 'slug' => 'zz-lv-' . substr($ws, 0, 6), 'name' => 'Levée verrou', 'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

    return $ws;
}

/** Nettoyage réservé aux tests : les lignes des tables d'ajout seul ne se retirent que triggers coupés, sur cette connexion jetable. */
function verrouNettoyerArret(string $ws): void
{
    $proprio = DB::connection('pgsql_owner');
    $proprio->statement("SET session_replication_role = 'replica'");
    foreach (['envoi_journal_commandes', 'envoi_drapeaux'] as $t) {
        $proprio->table($t)->where('workspace_id', $ws)->delete();
    }
    $proprio->table('workspaces')->where('id', $ws)->delete();
    $proprio->statement("SET session_replication_role = 'origin'");
}

/** @return array{process: resource, tubes: array<int, resource>} */
function verrouLancer(string $ws, string $action, float $depart, int $delai): array
{
    $tubes = [];
    $p = proc_open([PHP_BINARY, base_path('tests/Support/lever-tout-arreter-concurrent.php'), $ws, $action, sprintf('%.4F', $depart), (string) $delai], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes, base_path(), array_merge(getenv(), ['APP_ENV' => 'testing', 'ENVOI_REEL_ACTIF' => 'false']));

    return ['process' => $p, 'tubes' => $tubes];
}

/** @return array{resultat: mixed, sqlstate: ?string, duree: float} */
function verrouAttendre(array $proc): array
{
    $sortie = stream_get_contents($proc['tubes'][1]);
    $erreur = stream_get_contents($proc['tubes'][2]);
    expect(proc_close($proc['process']))->toBe(0, $sortie . $erreur);
    $json = json_decode(trim((string) $sortie), true);
    expect($json)->toBeArray($sortie . $erreur);

    return $json;
}

test('levée bloquée par le verrou de l\'espace : attente puis échec sur le délai, drapeau toujours armé, aucune reprise', function () {
    $proprio = DB::connection('pgsql_owner');
    $ws = verrouEspaceArret();
    try {
        WorkspaceContext::run($ws, fn () => (new DepotSocleDb)->armerToutArreter($ws, 'test'));
        expect($proprio->table('envoi_drapeaux')->where('workspace_id', $ws)->value('tout_arreter'))->toBeTrue();

        $proprio->beginTransaction(); // seconde connexion : tient le verrou, transaction ouverte
        $proprio->select(VERROU_ARRET_CLE, [$ws]);
        try {
            $r = verrouAttendre(verrouLancer($ws, 'lever', microtime(true) + 1, 1));
        } finally {
            $proprio->rollBack();
        }

        expect($r['sqlstate'])->toBe('55P03')                 // lock_not_available : délai de 1 s dépassé
            ->and($r['resultat'])->toBeNull()
            ->and($r['duree'])->toBeGreaterThan(0.8)          // il a ATTENDU (sans verrou, la levée passe tout de suite)
            ->and($proprio->table('envoi_drapeaux')->where('workspace_id', $ws)->value('tout_arreter'))->toBeTrue()
            ->and($proprio->table('envoi_journal_commandes')->where('workspace_id', $ws)->where('commande', 'reprise')->count())->toBe(0);
    } finally {
        verrouNettoyerArret($ws);
    }
});

test('armement bloqué par le verrou de l\'espace : attente puis échec, rien n\'est journalisé ni armé', function () {
    $proprio = DB::connection('pgsql_owner');
    $ws = verrouEspaceArret();
    try {
        $proprio->beginTransaction();
        $proprio->select(VERROU_ARRET_CLE, [$ws]);
        try {
            $r = verrouAttendre(verrouLancer($ws, 'armer', microtime(true) + 1, 1));
        } finally {
            $proprio->rollBack();
        }

        expect($r['sqlstate'])->toBe('55P03')->and($r['duree'])->toBeGreaterThan(0.8)
            ->and($proprio->table('envoi_journal_commandes')->where('workspace_id', $ws)->where('commande', 'tout_arreter')->count())->toBe(0)
            ->and((bool) $proprio->table('envoi_drapeaux')->where('workspace_id', $ws)->value('tout_arreter'))->toBeFalse();
    } finally {
        verrouNettoyerArret($ws);
    }
});

test('armements et levées CONCURRENTS (processus réels) : l\'état final est celui de la dernière ligne du journal', function () {
    $proprio = DB::connection('pgsql_owner');
    foreach ([1, 2, 3] as $manche) {
        $ws = verrouEspaceArret();
        try {
            WorkspaceContext::run($ws, fn () => (new DepotSocleDb)->armerToutArreter($ws, 'test'));
            $depart = microtime(true) + 4;
            $procs = [];
            foreach (['lever', 'armer', 'lever', 'armer', 'lever', 'armer'] as $action) {
                $procs[] = verrouLancer($ws, $action, $depart, 0);
            }
            foreach ($procs as $p) {
                expect(verrouAttendre($p)['sqlstate'])->toBeNull();
            }
            $derniere = $proprio->table('envoi_journal_commandes')->where('workspace_id', $ws)->whereIn('commande', ['tout_arreter', 'reprise'])->orderByDesc('id')->value('commande');
            $drapeau = (bool) $proprio->table('envoi_drapeaux')->where('workspace_id', $ws)->value('tout_arreter');
            expect($derniere)->toBe($drapeau ? 'tout_arreter' : 'reprise', "manche {$manche}");
        } finally {
            verrouNettoyerArret($ws);
        }
    }
});
