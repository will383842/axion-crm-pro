<?php

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * S1-C — schéma de fin d'envoi, partie C : imports de listes de refus (P1-LN-B). Les lignes sont écrites par
 * `pgsql_owner` en auto-commit (le rôle applicatif est une autre session) et retirées dans `finally`.
 * Aucun envoi, aucune adresse réelle : seuls des noms en `*.example.test`.
 */
function s1cOwner(): Connection
{
    return DB::connection('pgsql_owner');
}

function s1cApp(): Connection
{
    return DB::connection('pgsql_app');
}

function s1cContexte(Connection $c, ?string $ws): void
{
    $c->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', (string) $ws]);
}

/** @return array{0: string, 1: string} */
function s1cDeuxEspaces(): array
{
    $ids = [(string) Str::uuid(), (string) Str::uuid()];
    foreach ($ids as $id) {
        s1cOwner()->table('workspaces')->insert(['id' => $id, 'name' => 'S1C', 'slug' => 's1c-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    }

    return [$ids[0], $ids[1]];
}

/** @param list<string> $ids */
function s1cNettoyer(array $ids): void
{
    $o = s1cOwner();
    s1cContexte($o, null);
    foreach (['envoi_oppositions_registre', 'envoi_oppositions_imports'] as $t) {
        $o->transaction(function () use ($o, $t, $ids): void {
            $o->statement("ALTER TABLE {$t} DISABLE TRIGGER USER");
            $o->table($t)->whereIn('workspace_id', $ids)->delete();
            $o->statement("ALTER TABLE {$t} ENABLE TRIGGER USER");
        });
    }
    $o->table('workspaces')->whereIn('id', $ids)->delete();
}

/** @param array<string, mixed> $m */
function s1cImport(string $ws, array $m = []): string
{
    $id = (string) Str::uuid();
    s1cOwner()->table('envoi_oppositions_imports')->insert($m + [
        'id' => $id, 'workspace_id' => $ws, 'auteur' => 'cli', 'empreinte_fichier' => str_repeat('a', 64),
        'jeton_simulation' => (string) Str::uuid(),
    ]);

    return (string) ($m['id'] ?? $id);
}

/** @param array<string, mixed> $m */
function s1cRegistre(string $ws, array $m = []): void
{
    s1cOwner()->table('envoi_oppositions_registre')->insert($m + [
        'workspace_id' => $ws, 'portee' => 'domaine', 'empreinte' => str_repeat('d', 64), 'valeur' => 'd.example.test',
        'motif' => 'liste', 'source' => 'manuel', 'auteur' => 'cli',
    ]);
}

function s1cRefuse(Closure $f, string $fragment = ''): void
{
    try {
        $f();
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain($fragment);

        return;
    }
    throw new RuntimeException('Refus attendu, aucune erreur levée.');
}

afterEach(function () {
    s1cApp()->disconnect();
    s1cOwner()->disconnect();
});

test('imports : UPDATE, DELETE et TRUNCATE refusés même par le propriétaire', function () {
    [$a, $b] = s1cDeuxEspaces();
    try {
        $o = s1cOwner();
        s1cImport($a);
        s1cRefuse(fn () => $o->statement('UPDATE envoi_oppositions_imports SET auteur = auteur'), 'ajout seulement');
        s1cRefuse(fn () => $o->statement('DELETE FROM envoi_oppositions_imports'), 'ajout seulement');
        s1cRefuse(fn () => $o->statement('TRUNCATE envoi_oppositions_imports CASCADE'), 'ajout seulement');
        expect($o->table('envoi_oppositions_imports')->where('workspace_id', $a)->count())->toBe(1);
    } finally {
        s1cNettoyer([$a, $b]);
    }
});

test('RLS : un espace ne voit pas les imports de l\'autre (connexion applicative)', function () {
    [$a, $b] = s1cDeuxEspaces();
    try {
        s1cImport($a);
        s1cImport($b);
        $t = s1cOwner()->selectOne("SELECT relrowsecurity AS actif, relforcerowsecurity AS force FROM pg_class WHERE oid = 'public.envoi_oppositions_imports'::regclass");
        expect((bool) $t->actif)->toBeTrue()->and((bool) $t->force)->toBeTrue();
        s1cContexte(s1cApp(), $a);
        expect(s1cApp()->table('envoi_oppositions_imports')->pluck('workspace_id')->all())->toBe([$a]);
        s1cContexte(s1cApp(), '');
        expect(s1cApp()->table('envoi_oppositions_imports')->count())->toBe(0);
    } finally {
        s1cNettoyer([$a, $b]);
    }
});

test('une ligne d\'import contenant @ est refusée', function () {
    [$a, $b] = s1cDeuxEspaces();
    try {
        s1cImport($a);
        s1cRefuse(fn () => s1cImport($a, ['auteur' => 'x@example.test']), 'check');
        s1cRefuse(fn () => s1cImport($a, ['empreinte_fichier' => 'x@example.test']), 'check');
    } finally {
        s1cNettoyer([$a, $b]);
    }
});

test('les compteurs sont des colonnes entières (aucune colonne JSON) et refusent une valeur négative', function () {
    [$a, $b] = s1cDeuxEspaces();
    try {
        $json = DB::selectOne("SELECT count(*) AS n FROM information_schema.columns WHERE table_name = 'envoi_oppositions_imports' AND data_type IN ('json', 'jsonb')");
        expect((int) $json->n)->toBe(0);
        s1cImport($a, ['nb_lignes' => 3, 'nb_adresses' => 1, 'nb_domaines' => 1, 'nb_organisations' => 1, 'nb_ignorees' => 0]);
        s1cRefuse(fn () => s1cImport($a, ['nb_lignes' => -1]), 'check');
    } finally {
        s1cNettoyer([$a, $b]);
    }
});

test('le jeton de simulation est unique par espace, accepté tel quel dans un autre espace', function () {
    [$a, $b] = s1cDeuxEspaces();
    try {
        $jeton = (string) Str::uuid();
        s1cImport($a, ['jeton_simulation' => $jeton]);
        s1cRefuse(fn () => s1cImport($a, ['jeton_simulation' => $jeton]), 'unique');
        s1cImport($b, ['jeton_simulation' => $jeton]);
        expect(s1cOwner()->table('envoi_oppositions_imports')->where('jeton_simulation', $jeton)->count())->toBe(2);
    } finally {
        s1cNettoyer([$a, $b]);
    }
});

test('import_id du registre : nullable, accepte un import du même espace, refuse celui d\'un autre espace', function () {
    [$a, $b] = s1cDeuxEspaces();
    try {
        $col = DB::selectOne("SELECT is_nullable FROM information_schema.columns WHERE table_name = 'envoi_oppositions_registre' AND column_name = 'import_id'");
        expect($col)->not->toBeNull()->and($col->is_nullable)->toBe('YES');
        s1cRegistre($a);
        $importA = s1cImport($a);
        $importB = s1cImport($b);
        s1cRegistre($a, ['empreinte' => str_repeat('e', 64), 'import_id' => $importA]);
        s1cRefuse(fn () => s1cRegistre($a, ['empreinte' => str_repeat('f', 64), 'import_id' => $importB]), 'foreign key');
        expect(s1cOwner()->table('envoi_oppositions_registre')->where('workspace_id', $a)->count())->toBe(2);
    } finally {
        s1cNettoyer([$a, $b]);
    }
});

test('garde : une ligne du registre existante n\'est pas modifiée par la migration (import_id reste NULL)', function () {
    [$a, $b] = s1cDeuxEspaces();
    try {
        s1cRegistre($a);
        $ligne = s1cOwner()->table('envoi_oppositions_registre')->where('workspace_id', $a)->first();
        expect($ligne->import_id)->toBeNull()->and($ligne->valeur)->toBe('d.example.test')->and($ligne->motif)->toBe('liste');
    } finally {
        s1cNettoyer([$a, $b]);
    }
});

test('la migration 000020 se rejoue deux fois sans erreur et son down() est inerte', function () {
    $m = require database_path('migrations/2026_12_03_000020_envoi_oppositions_imports.php');
    $m->up();
    $m->up();
    $m->down();
    expect(DB::selectOne("SELECT to_regclass('public.envoi_oppositions_imports') AS t")->t)->not->toBeNull();
});
