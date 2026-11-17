<?php

use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Lot FINI-M0A — inventaire des IP d'envoi (`envoi_ips`) et IP d'un domaine (`envoi_domaines.ip_id`). Migration seule.
 * Chaque test est joué SOUS LE RÔLE APPLICATIF (SET LOCAL ROLE + WorkspaceContext) ET SOUS LE PROPRIÉTAIRE.
 * Données fictives : domaines *.example.test, IP en 192.0.2.0/24 et 198.51.100.0/24 (RFC 5737).
 */
uses(TestCase::class, RefreshDatabase::class);

const MIP_MODES = ['app', 'proprio'];

function mipEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Mip', 'slug' => 'mip-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function mipIp(string $ws, string $ref, string $flux = 'prospection', ?string $retiree = null): string
{
    $id = (string) Str::uuid();
    DB::table('envoi_ips')->insert(['id' => $id, 'workspace_id' => $ws, 'ref' => $ref, 'flux' => $flux, 'auteur' => 'test', 'retiree_le' => $retiree]);

    return $id;
}

function mipDomaine(string $ws, string $nom, ?string $flux = 'prospection'): void
{
    DB::table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => $nom, 'achete_le' => '2026-01-01', 'flux' => $flux]);
}

function mipSous(string $mode, string $ws, Closure $f): mixed
{
    if ($mode === 'proprio') {
        WorkspaceContext::set($ws);

        return $f();
    }
    WorkspaceContext::set($ws);
    DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    $r = $f();
    DB::statement('RESET ROLE');

    return $r;
}

/** SQLSTATE de l'échec ; null = accepté (puis annulé). Le message est rangé dans $msg. */
function mipCode(string $mode, string $ws, Closure $f, ?string &$msg = null): ?string
{
    try {
        DB::transaction(function () use ($mode, $ws, $f) {
            mipSous($mode, $ws, $f);
            throw new RuntimeException('__annule__');
        });
    } catch (QueryException $e) {
        $msg = $e->getMessage();

        return (string) $e->getCode();
    } catch (RuntimeException $e) {
        return $e->getMessage() === '__annule__' ? null : 'autre:' . $e->getMessage();
    }

    return 'inattendu';
}

function mipPoser(string $ws, string $domaine, string $ip): mixed
{
    return DB::selectOne('SELECT envoi_domaine_ip_poser(?, ?, ?, ?) AS ok', [$ws, $domaine, $ip, 'test']);
}

test('M0A 1 table, colonne, fonction, RLS forcée et déclencheurs présents', function () {
    expect(DB::selectOne("SELECT to_regclass('public.envoi_ips') AS t")->t)->not->toBeNull();
    expect(DB::selectOne("SELECT 1 AS e FROM information_schema.columns WHERE table_name = 'envoi_domaines' AND column_name = 'ip_id'"))->not->toBeNull();
    expect(DB::selectOne("SELECT 1 AS e FROM pg_proc WHERE proname = 'envoi_domaine_ip_poser'"))->not->toBeNull();
    $c = DB::selectOne("SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = 'envoi_ips'");
    expect($c->relrowsecurity)->toBeTrue()->and($c->relforcerowsecurity)->toBeTrue();
});

test('M0A 2 envoi_ips : ajout seul, ref et flux figés, retrait à sens unique', function (string $mode) {
    $ws = mipEspace();
    mipSous($mode, $ws, fn () => null);
    $id = mipIp($ws, '192.0.2.10');
    $retiree = mipIp($ws, '192.0.2.11', 'prospection', '2026-01-01 00:00:00+00');

    expect(mipCode($mode, $ws, fn () => DB::update("UPDATE envoi_ips SET flux = 'lettre' WHERE id = ?", [$id])))->not->toBeNull();
    expect(mipCode($mode, $ws, fn () => DB::update("UPDATE envoi_ips SET ref = '192.0.2.99' WHERE id = ?", [$id])))->not->toBeNull();
    expect(mipCode($mode, $ws, fn () => DB::delete('DELETE FROM envoi_ips WHERE id = ?', [$id])))->not->toBeNull();
    expect(mipCode($mode, $ws, fn () => DB::statement('TRUNCATE envoi_ips')))->not->toBeNull();
    expect(mipCode($mode, $ws, fn () => DB::update('UPDATE envoi_ips SET retiree_le = NULL WHERE id = ?', [$retiree])))->not->toBeNull();
    // Le sens sûr reste permis : NULL → non NULL.
    expect(mipCode($mode, $ws, fn () => DB::update('UPDATE envoi_ips SET retiree_le = now() WHERE id = ?', [$id])))->toBeNull();
    // Le message du déclencheur est préfixé (le rôle applicatif est déjà arrêté plus tôt, par le droit).
    mipCode($mode, $ws, fn () => DB::delete('DELETE FROM envoi_ips WHERE id = ?', [$id]), $msg);
    expect($msg)->toContain($mode === 'app' ? 'permission denied' : 'envoi_ips :');
})->with(MIP_MODES);

test('M0A 2b envoi_ips : formes refusées (ref, flux, doublon)', function (string $mode) {
    $ws = mipEspace();
    $ins = fn (string $ref, string $flux) => fn () => DB::table('envoi_ips')->insert(
        ['id' => (string) Str::uuid(), 'workspace_id' => $ws, 'ref' => $ref, 'flux' => $flux, 'auteur' => 'test'],
    );
    expect(mipCode($mode, $ws, $ins('192.0.2.1', 'prospection')))->toBeNull();
    expect(mipCode($mode, $ws, $ins('pas-une-ip', 'prospection')))->toBe('23514');
    expect(mipCode($mode, $ws, $ins('192.0.2.1', 'autre')))->toBe('23514');
    mipIp($ws, '192.0.2.1');
    expect(mipCode($mode, $ws, $ins('192.0.2.1', 'lettre')))->toBe('23505');
})->with(MIP_MODES);

test('M0A 3 rôle applicatif : UPDATE ip_id direct refusé (42501), la fonction le pose', function () {
    $ws = mipEspace();
    mipDomaine($ws, 'a.example.test');
    $ip = mipIp($ws, '192.0.2.20');
    expect(mipCode('app', $ws, fn () => DB::update('UPDATE envoi_domaines SET ip_id = ? WHERE workspace_id = ?', [$ip, $ws])))->toBe('42501');

    WorkspaceContext::set($ws);
    DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    mipPoser($ws, 'a.example.test', '192.0.2.20');
    DB::statement('RESET ROLE');
    expect(DB::table('envoi_domaines')->where('workspace_id', $ws)->value('ip_id'))->toBe($ip);
    $j = DB::table('envoi_journal_commandes')->where('workspace_id', $ws)->where('commande', 'domaine_ip')->first();
    expect($j)->not->toBeNull()->and($j->details)->not->toContain('@');
});

test('M0A 3b propriétaire : la fonction pose aussi l’IP', function () {
    $ws = mipEspace();
    mipDomaine($ws, 'a.example.test');
    $ip = mipIp($ws, '192.0.2.21');
    WorkspaceContext::set($ws);
    mipPoser($ws, 'a.example.test', '192.0.2.21');
    expect(DB::table('envoi_domaines')->where('workspace_id', $ws)->value('ip_id'))->toBe($ip);
});

test('M0A 4 fonction : autre espace, IP retirée, flux différent, domaine sans flux refusés', function (string $mode) {
    $a = mipEspace();
    $b = mipEspace();
    mipDomaine($a, 'p.example.test', 'prospection');
    mipDomaine($a, 'sans.example.test', null);
    mipIp($a, '192.0.2.30', 'prospection');
    mipIp($a, '192.0.2.31', 'prospection', '2026-01-01 00:00:00+00');
    mipIp($a, '198.51.100.30', 'lettre');
    mipIp($b, '192.0.2.32', 'prospection');
    $essai = fn (string $ws, string $dom, string $ip) => fn () => mipPoser($ws, $dom, $ip);

    expect(mipCode($mode, $a, $essai($a, 'p.example.test', '192.0.2.30')))->toBeNull();
    expect(mipCode($mode, $a, $essai($a, 'p.example.test', '192.0.2.32')))->not->toBeNull(); // IP de B
    expect(mipCode($mode, $a, $essai($a, 'p.example.test', '192.0.2.31')))->not->toBeNull(); // retirée
    expect(mipCode($mode, $a, $essai($a, 'p.example.test', '198.51.100.30')))->not->toBeNull(); // autre flux
    expect(mipCode($mode, $a, $essai($a, 'sans.example.test', '192.0.2.30')))->not->toBeNull(); // domaine sans flux
    expect(mipCode($mode, $a, $essai($b, 'p.example.test', '192.0.2.32')))->not->toBeNull(); // espace ≠ contexte
})->with(MIP_MODES);

test('M0A 5 espace B ne voit ni ne touche rien de A', function (string $mode) {
    $a = mipEspace();
    $b = mipEspace();
    mipIp($a, '192.0.2.40');
    mipIp($b, '192.0.2.41');
    if ($mode === 'app') {
        $vu = mipSous($mode, $b, fn () => DB::select('SELECT ref FROM envoi_ips WHERE workspace_id = ?', [$a]));
        expect($vu)->toBe([]);
        expect(mipSous($mode, $b, fn () => count(DB::select('SELECT 1 FROM envoi_ips'))))->toBe(1);
    } else {
        // Propriétaire (superutilisateur, pas de RLS) : le filtre explicite par espace tient.
        expect(DB::table('envoi_ips')->where('workspace_id', $b)->pluck('ref')->all())->toBe(['192.0.2.41']);
    }
    expect(mipCode($mode, $b, fn () => DB::table('envoi_ips')->insert(
        ['id' => (string) Str::uuid(), 'workspace_id' => $a, 'ref' => '192.0.2.42', 'flux' => 'lettre', 'auteur' => 'test'],
    )))
        ->toBe($mode === 'app' ? '42501' : null);
})->with(MIP_MODES);
