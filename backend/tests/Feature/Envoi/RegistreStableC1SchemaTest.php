<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * C1-0 « registre stable et rebonds » — MIGRATION SEULE (cahier C1-F2A §1.3 et §1.7, test 10).
 * Colonne `envoi_messages.id_injection_serveur` + deux index partiels. Aucun code applicatif.
 */
const C1_MIG_COLONNE = '2026_12_05_000200_envoi_messages_id_injection_serveur.php';
const C1_MIG_INDEX = '2026_12_05_000210_envoi_messages_index_injection.php';

/** Ligne minimale de envoi_messages, contraintes de clé étrangère levées (superutilisateur, dans la transaction du test). */
function c1Message(?string $serveur): void
{
    DB::statement('SET LOCAL session_replication_role = replica');
    DB::table('envoi_messages')->insert([
        'workspace_id' => (string) Str::uuid(), 'inscription_id' => (string) Str::uuid(), 'etape' => 1, 'statut' => 'envoye',
        'message_id' => 'm-' . Str::random(8) . '@example.test', 'id_injection_serveur' => $serveur,
    ]);
}

test('C1-0 la colonne id_injection_serveur existe : TEXT, NULLABLE, sans défaut', function () {
    $c = DB::selectOne("SELECT data_type, is_nullable, column_default FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'envoi_messages' AND column_name = 'id_injection_serveur'");
    expect($c)->not->toBeNull()->and($c->data_type)->toBe('text')->and($c->is_nullable)->toBe('YES')->and($c->column_default)->toBeNull();
});

test('C1-0 le CHECK de longueur accepte NULL et 1 à 200 caractères, refuse vide et 201', function () {
    foreach ([null, 'sim-1', str_repeat('x', 200)] as $ok) {
        c1Message($ok);
    }
    expect(DB::table('envoi_messages')->whereNotNull('id_injection_serveur')->count())->toBe(2);
    foreach (['', str_repeat('x', 201)] as $ko) {
        DB::beginTransaction();
        try {
            c1Message($ko);
            DB::rollBack();
            $this->fail('valeur refusée attendue');
        } catch (QueryException $e) {
            DB::rollBack();
            expect($e->getMessage())->toContain('envoi_messages_id_injection_serveur_longueur');
        }
    }
});

test('C1-0 la colonne est commentée et la RLS reste FORCÉE', function () {
    $com = DB::selectOne("SELECT col_description('envoi_messages'::regclass, a.attnum) AS c FROM pg_attribute a WHERE a.attrelid = 'envoi_messages'::regclass AND a.attname = 'id_injection_serveur'");
    $rls = DB::selectOne("SELECT relrowsecurity AS r, relforcerowsecurity AS f FROM pg_class WHERE relname = 'envoi_messages'");
    expect((string) ($com->c ?? ''))->toContain('C1')->and($rls->r)->toBeTrue()->and($rls->f)->toBeTrue();
});

test('C1-0 le rôle applicatif a SELECT, INSERT et UPDATE sur la colonne (droits de la table)', function () {
    $role = (string) config('database.connections.pgsql_app.username');
    foreach (['SELECT', 'INSERT', 'UPDATE'] as $droit) {
        expect(DB::selectOne("SELECT has_column_privilege(?, 'public.envoi_messages', 'id_injection_serveur', ?) AS p", [$role, $droit])->p)->toBeTrue($droit);
    }
});

test('C1-0 la connexion pgsql_app lit et met à jour la colonne (droit vérifié à l\'exécution, aucune ligne modifiée)', function () {
    $app = DB::connection('pgsql_app');
    try {
        $app->beginTransaction();
        $app->statement("SELECT set_config('app.current_workspace_id', ?, true)", [(string) Str::uuid()]);
        expect($app->select('SELECT id_injection_serveur FROM envoi_messages LIMIT 1'))->toBe([]);
        expect($app->update("UPDATE envoi_messages SET id_injection_serveur = 'sim-1' WHERE false"))->toBe(0);
        $app->rollBack();
    } finally {
        $app->disconnect();
    }
});

test('C1-0 les deux index partiels sont présents et VALIDES, sur (workspace_id, colonne) avec prédicat IS NOT NULL', function () {
    foreach (['envoi_messages_injection' => 'id_injection', 'envoi_messages_injection_serveur' => 'id_injection_serveur'] as $nom => $col) {
        $i = DB::selectOne("SELECT i.indisvalid AS v, pg_get_indexdef(i.indexrelid) AS d FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ? AND i.indrelid = 'envoi_messages'::regclass", [$nom]);
        expect($i)->not->toBeNull($nom)->and($i->v)->toBeTrue()->and($i->d)->toContain("(workspace_id, {$col})")->toContain("WHERE ({$col} IS NOT NULL)");
        expect(DB::selectOne('SELECT 1 AS e FROM pg_indexes WHERE schemaname = ? AND indexname = ?', ['public', $nom]))->not->toBeNull();
    }
});

test('C1-0 les migrations sont rejouables deux fois et ne touchent aucune ligne', function () {
    c1Message('sim-7');
    $avant = DB::table('envoi_messages')->orderBy('id')->get()->all();
    foreach ([C1_MIG_COLONNE, C1_MIG_INDEX] as $f) {
        $m = require database_path('migrations/' . $f);
        $m->up();
        $m->up();
        $m->down();
    }
    expect(DB::table('envoi_messages')->orderBy('id')->get()->all())->toEqual($avant)
        ->and(DB::selectOne("SELECT count(*) AS n FROM pg_indexes WHERE indexname IN ('envoi_messages_injection', 'envoi_messages_injection_serveur')")->n)->toBe(2);
});

test('C1-0 garde de source : additives, aucun DROP/DELETE/UPDATE de données, down() inerte, index hors transaction', function () {
    $col = (string) file_get_contents(database_path('migrations/' . C1_MIG_COLONNE));
    $idx = (string) file_get_contents(database_path('migrations/' . C1_MIG_INDEX));
    expect($col)->toContain('ADD COLUMN IF NOT EXISTS id_injection_serveur TEXT')->toContain('lock_timeout')
        ->and(preg_match('~\b(DELETE|TRUNCATE|UPDATE\s+envoi|DROP\s+(TABLE|COLUMN))\b~i', $col))->toBe(0)
        ->and(preg_match('~DROP\s+CONSTRAINT\s+IF\s+EXISTS\s+envoi_messages_id_injection_serveur_longueur~', $col))->toBe(1)
        ->and($idx)->toContain('$withinTransaction = false')->toContain('CONCURRENTLY ')->toContain('IF NOT EXISTS {$nom} ON envoi_messages (workspace_id, {$colonne}) WHERE {$colonne} IS NOT NULL')->toContain('DROP INDEX CONCURRENTLY IF EXISTS')
        ->and(preg_match('~\b(DELETE|TRUNCATE)\b~i', $idx))->toBe(0)
        ->and(preg_match('~function down\(\): void \{\}~', $col))->toBe(1)
        ->and(preg_match('~function down\(\): void \{\}~', $idx))->toBe(1);
});

test('C1-0 l\'ajout ne touche à aucun code applicatif de l\'envoi (migration seule)', function () {
    foreach (['Sequence/Sequenceur.php', 'Contrat/MessageAEnvoyer.php', 'Message/ComposeurMessage.php'] as $f) {
        expect((string) file_get_contents(app_path('Envoi/' . $f)))->not->toContain('id_injection_serveur');
    }
});
