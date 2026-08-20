<?php

/**
 * GARDE — B10-001 (S1) : « une base dont pg_partman vit dans public ne peut
 * plus JAMAIS etre reconstruite par migrate:fresh, ET LA MIGRATION QUI CORRIGE
 * CELA EST INATTEIGNABLE PAR CE CHEMIN ».
 *
 * ── LA MOITIE DEJA TRAITEE, ET CELLE QUI RESTAIT ──────────────────────────
 *
 * `2026_08_18_100001_partman_dans_son_propre_schema` sait relocaliser
 * l'extension. `ReconstructionBaseTest` verifie qu'aucune table d'extension ne
 * traine dans le `search_path`. Ce qui manquait est plus bete et plus grave :
 * ces deux pieces s'executent APRES `Dropping all tables`.
 *
 * `migrate:fresh` fait, dans cet ordre :
 *   1. `db:wipe` → `PostgresBuilder::dropAllTables()` → UN SEUL
 *      `DROP TABLE … CASCADE` sur toutes les tables du `search_path` ;
 *   2. `migrate` → les migrations.
 *
 * Sur une base ou pg_partman vit dans `public`, l'etape 1 echoue
 * (SQLSTATE 2BP01) et l'etape 2 N'A JAMAIS LIEU. La migration correctrice est
 * donc inatteignable par le seul chemin qui en aurait besoin — et
 * `make db-rebuild-check` (Makefile l. 93-109), qui appelle deux fois
 * `migrate:fresh`, ne peut pas passer non plus.
 *
 * ── CE QUE CE FICHIER FAIT ────────────────────────────────────────────────
 *
 * Il fabrique une base TEMOIN jetable, y installe pg_partman dans `public`
 * (l'etat fautif), REPRODUIT l'echec 2BP01, applique la relocalisation, et
 * verifie que le meme `DROP` passe ensuite. Rien de partage n'est touche : la
 * base temoin est creee puis supprimee par le test.
 */

use App\Support\RelocalisationPartman;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

uses(TestCase::class);

const BASE_TEMOIN_B10 = 'axion_crm_temoin_b10';

/**
 * Emule exactement ce que fait `PostgresBuilder::dropAllTables()` : enumerer
 * les tables des schemas du `search_path` et les passer a UN SEUL
 * `DROP TABLE … CASCADE`.
 *
 * @return list<string> les tables enumerees
 */
function tablesDuCheminDeDrop(string $connexion): array
{
    $lignes = DB::connection($connexion)->select(<<<'SQL'
        SELECT c.relname AS nom
        FROM   pg_class c
        JOIN   pg_namespace n ON n.oid = c.relnamespace
        WHERE  c.relkind IN ('r', 'p')
          AND  n.nspname = ANY (current_schemas(false))
        ORDER  BY 1
    SQL);

    return array_map(static fn ($l): string => (string) $l->nom, $lignes);
}

beforeEach(function () {
    // Connexion d'administration : `CREATE DATABASE` ne peut pas s'executer
    // dans une transaction, et surtout pas sur la base que l'on cree.
    $base = config('database.connections.pgsql_owner');

    config([
        'database.connections.temoin_b10_admin' => array_merge($base, ['database' => 'postgres']),
        'database.connections.temoin_b10' => array_merge($base, ['database' => BASE_TEMOIN_B10]),
    ]);

    DB::purge('temoin_b10_admin');
    DB::purge('temoin_b10');

    DB::connection('temoin_b10_admin')->statement('DROP DATABASE IF EXISTS ' . BASE_TEMOIN_B10);
    DB::connection('temoin_b10_admin')->statement('CREATE DATABASE ' . BASE_TEMOIN_B10);
});

afterEach(function () {
    DB::purge('temoin_b10');
    DB::connection('temoin_b10_admin')->statement('DROP DATABASE IF EXISTS ' . BASE_TEMOIN_B10);
    DB::purge('temoin_b10_admin');
});

test('TEMOIN — pg_partman dans public fait echouer le DROP global de migrate:fresh', function () {
    DB::connection('temoin_b10')->statement('CREATE EXTENSION IF NOT EXISTS pg_partman');

    $schema = (string) DB::connection('temoin_b10')->scalar(
        "SELECT n.nspname FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace WHERE e.extname = 'pg_partman'",
    );
    expect($schema)->toBe('public');

    $tables = tablesDuCheminDeDrop('temoin_b10');
    // Sans cette premisse, tout le reste du fichier serait un vert a vide.
    expect($tables)->toContain('part_config');

    $erreur = null;
    try {
        DB::connection('temoin_b10')->statement(
            'DROP TABLE "' . implode('","', $tables) . '" CASCADE',
        );
    } catch (Throwable $e) {
        $erreur = $e->getMessage();
    }

    expect($erreur)->not->toBeNull();
    // Message PostgreSQL, en anglais : pas de piege d'accent.
    $this->assertStringContainsString('cannot drop table part_config', (string) $erreur);
    $this->assertStringContainsString('extension pg_partman requires it', (string) $erreur);
});

test('la relocalisation rend le DROP global possible, et elle est rejouable', function () {
    DB::connection('temoin_b10')->statement('CREATE EXTENSION IF NOT EXISTS pg_partman');

    // Le geste que `make db-rebuild-*` et le crochet `migrate:fresh` jouent
    // AVANT le `DROP`, c'est-a-dire au seul moment ou il sert a quelque chose.
    DB::connection('temoin_b10')->unprepared(RelocalisationPartman::SQL);

    $schema = (string) DB::connection('temoin_b10')->scalar(
        "SELECT n.nspname FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace WHERE e.extname = 'pg_partman'",
    );
    expect($schema)->toBe('partman');

    $tables = tablesDuCheminDeDrop('temoin_b10');
    expect($tables)->not->toContain('part_config');

    if ($tables !== []) {
        DB::connection('temoin_b10')->statement('DROP TABLE "' . implode('","', $tables) . '" CASCADE');
    }

    // REJOUABLE : deuxieme passage sur une base deja relocalisee, sans erreur.
    DB::connection('temoin_b10')->unprepared(RelocalisationPartman::SQL);

    $schema2 = (string) DB::connection('temoin_b10')->scalar(
        "SELECT n.nspname FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace WHERE e.extname = 'pg_partman'",
    );
    expect($schema2)->toBe('partman');
});

test('la commande db:partman-relocaliser joue ce geste sur la connexion demandee', function () {
    DB::connection('temoin_b10')->statement('CREATE EXTENSION IF NOT EXISTS pg_partman');

    $code = Artisan::call('db:partman-relocaliser', [
        '--database' => 'temoin_b10',
    ]);

    expect($code)->toBe(0, Artisan::output());

    $schema = (string) DB::connection('temoin_b10')->scalar(
        "SELECT n.nspname FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace WHERE e.extname = 'pg_partman'",
    );
    expect($schema)->toBe('partman');
});

test('le crochet migrate:fresh est arme : l evenement CommandStarting declenche la relocalisation', function () {
    // Sans ce crochet, `RefreshDatabase` (toute la suite Pest) reste bloque sur
    // une base fautive : il appelle `migrate:fresh`, jamais le Makefile.
    Log::spy();

    event(new CommandStarting(
        'migrate:fresh',
        new ArrayInput([]),
        new BufferedOutput,
    ));

    Log::shouldHaveReceived('info')->atLeast()->once()
        ->withArgs(fn (string $m): bool => str_contains($m, RelocalisationPartman::PREFIXE_JOURNAL));
});

test('TEMOIN — le crochet ne se declenche PAS sur une autre commande', function () {
    Log::spy();

    event(new CommandStarting(
        'migrate',
        new ArrayInput([]),
        new BufferedOutput,
    ));

    Log::shouldNotHaveReceived('info');
});
