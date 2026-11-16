<?php

use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Lot D1-L1 — schéma des accès IMAP chiffrés des boîtes (ajout seul). Chaque test de sécurité est joué SOUS LE RÔLE APPLICATIF
 * (SET LOCAL ROLE + WorkspaceContext) ET SOUS LE PROPRIÉTAIRE. Données fictives (*.example.test), aucun vrai secret.
 */
uses(TestCase::class, RefreshDatabase::class);

const BAI_MODES = ['app', 'proprio'];

function baiEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Bai', 'slug' => 'bai-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function baiBoite(string $ws): string
{
    $id = (string) Str::uuid();
    DB::table('envoi_boites')->insert(['id' => $id, 'workspace_id' => $ws, 'adresse' => 'boite-' . substr($id, 0, 6) . '@example.test',
        'hote' => 'smtp.example.test', 'port' => 587, 'chiffrement' => 'starttls', 'identifiants_chiffres' => 'chiffre-fictif']);

    return $id;
}

function baiLigne(string $ws, string $boite, array $plus = []): array
{
    return $plus + ['workspace_id' => $ws, 'boite_id' => $boite, 'hote' => 'imap.gmail.com', 'port' => 993, 'dossier' => 'INBOX',
        'identifiant_chiffre' => 'chiffre-fictif', 'actif' => true];
}

function baiSous(string $mode, string $ws, Closure $f): mixed
{
    if ($mode === 'proprio') {
        return $f();
    }
    WorkspaceContext::set($ws);
    DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    $r = $f();
    DB::statement('RESET ROLE');

    return $r;
}

/** SQLSTATE de l'échec ; null = accepté (puis annulé). */
function baiCode(string $mode, string $ws, Closure $f): ?string
{
    try {
        DB::transaction(function () use ($mode, $ws, $f) {
            baiSous($mode, $ws, $f);
            throw new RuntimeException('__annule__');
        });
    } catch (QueryException $e) {
        return (string) $e->getCode();
    } catch (RuntimeException $e) {
        return $e->getMessage() === '__annule__' ? null : 'autre:' . $e->getMessage();
    }

    return 'inattendu';
}

test('D1-L1 S1 table, index, RLS forcée et déclencheurs présents', function () {
    expect(DB::selectOne("SELECT to_regclass('public.envoi_boites_acces_imap') AS t")->t)->not->toBeNull();
    expect(DB::selectOne("SELECT 1 AS e FROM pg_indexes WHERE indexname = 'envoi_boites_acces_imap_derniere'"))->not->toBeNull();
    $c = DB::selectOne("SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = 'envoi_boites_acces_imap'");
    expect($c->relrowsecurity)->toBeTrue()->and($c->relforcerowsecurity)->toBeTrue();
    $n = DB::selectOne("SELECT count(*) AS n FROM pg_trigger WHERE tgrelid = 'envoi_boites_acces_imap'::regclass AND NOT tgisinternal")->n;
    expect((int) $n)->toBe(3);
});

test('D1-L1 S2 formes admises et refusées (rôle applicatif ET propriétaire)', function () {
    $ws = baiEspace();
    $b = baiBoite($ws);
    foreach (BAI_MODES as $mode) {
        $ins = fn (array $plus) => fn () => DB::table('envoi_boites_acces_imap')->insert(baiLigne($ws, $b, $plus));
        expect(baiCode($mode, $ws, $ins([])))->toBeNull("$mode : ligne active valide");
        expect(baiCode($mode, $ws, $ins(['actif' => false, 'hote' => null, 'port' => null, 'dossier' => null, 'identifiant_chiffre' => null])))->toBeNull("$mode : ligne inactive");
        expect(baiCode($mode, $ws, $ins(['hote' => 'imap.example.test'])))->toBe('23514');
        expect(baiCode($mode, $ws, $ins(['port' => 143])))->toBe('23514');
        expect(baiCode($mode, $ws, $ins(['dossier' => 'Spam'])))->toBe('23514');
        expect(baiCode($mode, $ws, $ins(['identifiant_chiffre' => null])))->toBe('23514');
        expect(baiCode($mode, $ws, $ins(['actif' => false])))->toBe('23514');
    }
});

test('D1-L1 S3 UPDATE et DELETE refusés (rôle applicatif ET propriétaire)', function () {
    $ws = baiEspace();
    $b = baiBoite($ws);
    DB::table('envoi_boites_acces_imap')->insert(baiLigne($ws, $b));
    foreach (BAI_MODES as $mode) {
        expect(baiCode($mode, $ws, fn () => DB::table('envoi_boites_acces_imap')->where('boite_id', $b)->update(['actif' => false, 'identifiant_chiffre' => null])))->not->toBeNull();
        expect(baiCode($mode, $ws, fn () => DB::table('envoi_boites_acces_imap')->where('boite_id', $b)->delete()))->not->toBeNull();
    }
});

test('D1-L1 S4 isolation entre espaces (rôle applicatif)', function () {
    $a = baiEspace();
    $bEsp = baiEspace();
    $boiteA = baiBoite($a);
    $boiteB = baiBoite($bEsp);
    DB::table('envoi_boites_acces_imap')->insert(baiLigne($bEsp, $boiteB));
    expect(baiCode('app', $a, fn () => DB::table('envoi_boites_acces_imap')->insert(baiLigne($a, $boiteB))))->not->toBeNull();
    expect(baiCode('app', $a, fn () => DB::table('envoi_boites_acces_imap')->insert(baiLigne($bEsp, $boiteB))))->not->toBeNull();
    expect(baiCode('app', $a, fn () => DB::table('envoi_boites_acces_imap')->insert(baiLigne($a, $boiteA))))->toBeNull();
    foreach (BAI_MODES as $mode) {
        // Boîte de l'autre espace ou inconnue, y compris sous le propriétaire (RLS contournée) : refus 23503.
        expect(baiCode($mode, $a, fn () => DB::table('envoi_boites_acces_imap')->insert(baiLigne($a, $boiteB))))->toBe('23503');
        expect(baiCode($mode, $a, fn () => DB::table('envoi_boites_acces_imap')->insert(baiLigne($a, (string) Str::uuid()))))->toBe('23503');
    }
    expect(baiSous('app', $a, fn () => DB::table('envoi_boites_acces_imap')->count()))->toBe(0);
    expect(baiSous('app', $bEsp, fn () => DB::table('envoi_boites_acces_imap')->count()))->toBe(1);
    // Sans contexte d'espace : 0 ligne.
    DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    DB::statement("SELECT set_config('app.current_workspace_id', '', true)");
    expect(DB::table('envoi_boites_acces_imap')->count())->toBe(0);
    DB::statement('RESET ROLE');
});

test('D1-L1 S5 droits du rôle applicatif : INSERT oui, UPDATE non', function () {
    $role = (string) config('database.connections.pgsql_app.username');
    expect(DB::selectOne("SELECT has_table_privilege(?, 'envoi_boites_acces_imap', 'INSERT') AS p", [$role])->p)->toBeTrue();
    expect(DB::selectOne("SELECT has_table_privilege(?, 'envoi_boites_acces_imap', 'UPDATE') AS p", [$role])->p)->toBeFalse();
    expect(DB::selectOne("SELECT has_table_privilege(?, 'envoi_boites_acces_imap', 'DELETE') AS p", [$role])->p)->toBeFalse();
    expect(DB::selectOne("SELECT has_table_privilege(?, 'envoi_boites_acces_imap', 'TRUNCATE') AS p", [$role])->p)->toBeFalse();
});

test('D1-L1 S6 up() rejouable deux fois sans erreur, down() inerte', function () {
    $m = require database_path('migrations/2026_12_05_000400_envoi_boites_acces_imap.php');
    $m->up();
    $m->up();
    $m->down();
    expect(DB::selectOne("SELECT to_regclass('public.envoi_boites_acces_imap') AS t")->t)->not->toBeNull();
});
