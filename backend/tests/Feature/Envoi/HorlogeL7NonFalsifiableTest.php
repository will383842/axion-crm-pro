<?php

/**
 * Envoi D1b (sécurité) — l'horloge de référence des triggers L7 (`envoi_l7_horloge()`) ne se règle plus depuis une session.
 *
 * Faille : la fonction suivait `app.maintenant` de N'IMPORTE QUELLE session. Avec le rôle applicatif,
 * `SELECT set_config('app.maintenant', '2099-01-01', true)` faisait « expirer » une pause non expirée
 * (levée par expiration) et laissait un battement de santé daté du futur masquer l'arrêt sur silence (INF-025).
 *
 * Correctif : la base n'obéit à `app.maintenant` que si un paramètre POSÉ SUR LA BASE (ALTER DATABASE, donc inaccessible
 * à une session) `axion.test_horloge` vaut « on » : seul le banc de test le pose. Un set_config de session sur ce nom
 * n'y change rien (on lit le catalogue `pg_db_role_setting`, pas le réglage de session).
 * Fixtures fictives ; aucun envoi.
 */

use App\Envoi\Chauffe\DepotChauffe;
use App\Support\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const H7_FUTUR = '2300-01-01 00:00:00+00';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-10 11:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function h7Espace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'H7', 'slug' => 'h7-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** Simule la base de PRODUCTION : le paramètre de base du banc de test est retiré (annulé avec la transaction du test). */
function h7HorsTest(): void
{
    DB::unprepared("DO \$\$ BEGIN EXECUTE format('ALTER DATABASE %I RESET axion.test_horloge', current_database()); END \$\$");
}

/** Pause de 100 ans : non expirée quelle que soit la date réelle d'exécution. */
function h7PauseLongue(string $ws): int
{
    app(DepotChauffe::class)->ouvrirPause($ws, 'indesirable_temoin', null, new DateTimeImmutable('2026-11-10 12:00:00+01:00'), 24 * 365 * 100);

    return (int) WorkspaceContext::run($ws, fn () => DB::table('envoi_pauses')->where('workspace_id', $ws)->value('id'));
}

/** Attaque : le rôle applicatif fige l'horloge de la transaction dans le futur puis « expire » la pause. */
function h7Attaque(string $ws, int $pause, ?string $avant = null): void
{
    try {
        // Point de sauvegarde : l'échec attendu du trigger n'abandonne pas la transaction du test.
        WorkspaceContext::run($ws, fn () => DB::transaction(function () use ($pause, $avant) {
            DB::statement('SET LOCAL ROLE ' . config('database.connections.pgsql_app.username'));
            DB::select($avant ?? 'SELECT 1');
            DB::select("SELECT set_config('app.maintenant', ?, true)", [H7_FUTUR]);
            DB::update("UPDATE envoi_pauses SET levee_par = 'expiration', levee_le = expire_le WHERE id = ?", [$pause]);
        }));
    } finally {
        DB::statement('RESET ROLE');
    }
}

test('D1b-1 : hors test, set_config(app.maintenant, futur) NE lève PAS une pause non expirée', function () {
    $ws = h7Espace();
    $pause = h7PauseLongue($ws);
    h7HorsTest();

    expect(fn () => h7Attaque($ws, $pause))->toThrow(QueryException::class, 'expiration refusée');
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_pauses')->where('id', $pause)->value('levee_le')))->toBeNull();
});

test('D1b-2 : hors test, un set_config de SESSION sur axion.test_horloge n\'ouvre rien (le paramètre vient du catalogue de la base)', function () {
    $ws = h7Espace();
    $pause = h7PauseLongue($ws);
    h7HorsTest();

    expect(fn () => h7Attaque($ws, $pause, "SELECT set_config('axion.test_horloge', 'on', true)"))->toThrow(QueryException::class, 'expiration refusée');
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_pauses')->where('id', $pause)->value('levee_le')))->toBeNull();
});

test('D1b-3 : hors test, un battement daté du futur ne masque pas le silence (INF-025) : il est ramené à l\'horloge de la base', function () {
    app(DepotChauffe::class)->noterBattement(new DateTimeImmutable('2026-11-10 12:00:00+01:00'));
    h7HorsTest();

    DB::transaction(function () {
        DB::statement('SET LOCAL ROLE ' . config('database.connections.pgsql_app.username'));
        DB::select("SELECT set_config('app.maintenant', ?, true)", [H7_FUTUR]);
        DB::update('UPDATE envoi_sante_battement SET dernier_le = ? WHERE id = 1', [H7_FUTUR]);
    });
    DB::statement('RESET ROLE');

    $borne = (int) DB::scalar('SELECT EXTRACT(EPOCH FROM clock_timestamp())') + 60;
    expect((int) DB::scalar('SELECT EXTRACT(EPOCH FROM dernier_le) FROM envoi_sante_battement WHERE id = 1'))->toBeLessThanOrEqual($borne);
});

test('D1b-4 : hors test, l\'INSERT d\'un battement futur est borné aussi', function () {
    h7HorsTest();
    DB::select("SELECT set_config('app.maintenant', ?, true)", [H7_FUTUR]);
    DB::statement('INSERT INTO envoi_sante_battement (id, dernier_le) VALUES (1, ?) ON CONFLICT (id) DO UPDATE SET dernier_le = EXCLUDED.dernier_le', [H7_FUTUR]);

    $borne = (int) DB::scalar('SELECT EXTRACT(EPOCH FROM clock_timestamp())') + 60;
    expect((int) DB::scalar('SELECT EXTRACT(EPOCH FROM dernier_le) FROM envoi_sante_battement WHERE id = 1'))->toBeLessThanOrEqual($borne);
});

test('D1b-5 : en test, l\'horloge injectée fonctionne encore (le banc pose axion.test_horloge sur sa base)', function () {
    expect((bool) DB::scalar("SELECT EXISTS (SELECT 1 FROM pg_db_role_setting s WHERE s.setrole = 0 AND 'axion.test_horloge=on' = ANY (s.setconfig)
        AND s.setdatabase = (SELECT oid FROM pg_database WHERE datname = current_database()))"))->toBeTrue();

    $ws = h7Espace();
    $pause = h7PauseLongue($ws);
    // Horloge injectée au-delà de l'expiration : la levée par expiration est acceptée (et journalisée par le trigger).
    DB::select("SELECT set_config('app.maintenant', ?, true)", [H7_FUTUR]);
    $n = WorkspaceContext::run($ws, fn () => DB::update("UPDATE envoi_pauses SET levee_par = 'expiration', levee_le = expire_le WHERE id = ?", [$pause]));
    expect($n)->toBe(1)
        ->and(WorkspaceContext::run($ws, fn () => DB::table('envoi_journal_commandes')->where('workspace_id', $ws)->where('commande', 'pause_expiree')->count()))->toBe(1);
});

test('D1b-6 : en test, l\'horloge injectée borne un battement (jamais dans le futur de CETTE horloge)', function () {
    DB::select("SELECT set_config('app.maintenant', '2026-11-10 11:00:00+00', true)");
    DB::statement('INSERT INTO envoi_sante_battement (id, dernier_le) VALUES (1, ?)', ['2026-11-10 23:00:00+00']);
    expect((string) DB::scalar("SELECT to_char(dernier_le AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') FROM envoi_sante_battement WHERE id = 1"))->toBe('2026-11-10 11:00');
});
