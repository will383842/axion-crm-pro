<?php

use App\Envoi\Chauffe\DepotIps;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Lot X6-A — envoi:ip-inventaire, envoi:domaine-ip et DepotIps. Joué sous le rôle applicatif ET sous le propriétaire.
 * Données fictives : domaines *.example.test, IP privées ou RFC 5737.
 */
uses(TestCase::class, RefreshDatabase::class);

function iipEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Iip', 'slug' => 'iip-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function iipDomaine(string $ws, string $nom, ?string $flux = 'prospection'): void
{
    DB::table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => $nom, 'achete_le' => '2026-01-01', 'flux' => $flux]);
}

/** Exécute $f sous le rôle choisi (contexte d'espace posé), puis restaure. */
function iipSous(string $mode, string $ws, Closure $f): mixed
{
    DB::beginTransaction();
    try {
        WorkspaceContext::set($ws);
        if ($mode === 'app') {
            DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
        }

        return $f();
    } finally {
        if ($mode === 'app') {
            DB::statement('RESET ROLE');
        }
        DB::commit();
    }
}

function iipCmd(string $mode, string $ws, string $commande, array $params, ?string &$sortie = null): int
{
    $code = iipSous($mode, $ws, fn () => Artisan::call($commande, $params + ['--no-interaction' => true]));
    $sortie = Artisan::output();

    return $code;
}

function iipAjouter(string $mode, string $ws, string $ip, string $flux = 'prospection'): int
{
    return iipCmd($mode, $ws, 'envoi:ip-inventaire', ['workspace' => $ws, 'action' => 'ajouter', 'ip' => $ip, '--flux' => $flux, '--auteur' => 'test']);
}

function iipLier(string $mode, string $ws, string $domaine, string $ip, ?string &$sortie = null): int
{
    return iipCmd($mode, $ws, 'envoi:domaine-ip', ['workspace' => $ws, 'domaine' => $domaine, 'ip' => $ip, '--auteur' => 'test'], $sortie);
}

function iipDepot(string $mode, string $ws, Closure $f): mixed
{
    return iipSous($mode, $ws, fn () => $f(app(DepotIps::class)));
}

test('X6-A 1 ajouter puis lier : ipDuDomaine rend l’IP', function (string $mode) {
    $ws = iipEspace();
    iipDomaine($ws, 'a.example.test');
    expect(iipAjouter($mode, $ws, '10.0.0.1'))->toBe(0);
    expect(iipLier($mode, $ws, 'a.example.test', '10.0.0.1'))->toBe(0);
    expect(iipDepot($mode, $ws, fn ($d) => $d->ipDuDomaine($ws, 'a.example.test')))->toBe('10.0.0.1');
})->with(['app', 'proprio']);

test('X6-A 2 IP retirée : ipDuDomaine rend null', function (string $mode) {
    $ws = iipEspace();
    iipDomaine($ws, 'a.example.test');
    iipAjouter($mode, $ws, '10.0.0.2');
    iipLier($mode, $ws, 'a.example.test', '10.0.0.2');
    expect(iipCmd($mode, $ws, 'envoi:ip-inventaire', ['workspace' => $ws, 'action' => 'retirer', 'ip' => '10.0.0.2', '--auteur' => 'test']))->toBe(0);
    expect(iipDepot($mode, $ws, fn ($d) => $d->ipDuDomaine($ws, 'a.example.test')))->toBeNull();
})->with(['app', 'proprio']);

test('X6-A 3 flux différent : la liaison échoue, code non nul', function (string $mode) {
    $ws = iipEspace();
    iipDomaine($ws, 'a.example.test', 'prospection');
    iipAjouter($mode, $ws, '10.0.0.3', 'lettre');
    expect(iipLier($mode, $ws, 'a.example.test', '10.0.0.3'))->not->toBe(0);
    expect(iipDepot($mode, $ws, fn ($d) => $d->ipDuDomaine($ws, 'a.example.test')))->toBeNull();
})->with(['app', 'proprio']);

test('X6-A 4 espace B : rien de l’espace A', function (string $mode) {
    $a = iipEspace();
    $b = iipEspace();
    iipDomaine($a, 'a.example.test');
    iipAjouter($mode, $a, '10.0.0.4');
    iipLier($mode, $a, 'a.example.test', '10.0.0.4');
    expect(iipDepot($mode, $b, fn ($d) => $d->ipDuDomaine($b, 'a.example.test')))->toBeNull();
    expect(iipDepot($mode, $b, fn ($d) => $d->ipsParFlux($b)))->toBe(['prospection' => [], 'lettre' => []]);
    expect(iipDepot($mode, $b, fn ($d) => $d->domainesSansIp($b)))->toBe(0);
    expect(iipLier($mode, $b, 'a.example.test', '10.0.0.4'))->not->toBe(0);
})->with(['app', 'proprio']);

test('X6-A 5 ipsParFlux exact, retirées exclues ; domainesSansIp compte', function (string $mode) {
    $ws = iipEspace();
    iipDomaine($ws, 'a.example.test');
    iipDomaine($ws, 'b.example.test');
    iipDomaine($ws, 'c.example.test', 'lettre');
    iipAjouter($mode, $ws, '10.0.0.6', 'prospection');
    iipAjouter($mode, $ws, '10.0.0.5', 'prospection');
    iipAjouter($mode, $ws, '10.0.0.7', 'lettre');
    iipAjouter($mode, $ws, '10.0.0.8', 'lettre');
    iipCmd($mode, $ws, 'envoi:ip-inventaire', ['workspace' => $ws, 'action' => 'retirer', 'ip' => '10.0.0.8', '--auteur' => 'test']);
    iipLier($mode, $ws, 'a.example.test', '10.0.0.5');
    expect(iipDepot($mode, $ws, fn ($d) => $d->ipsParFlux($ws)))->toBe(['prospection' => ['10.0.0.5', '10.0.0.6'], 'lettre' => ['10.0.0.7']]);
    expect(iipDepot($mode, $ws, fn ($d) => $d->domainesSansIp($ws)))->toBe(2);
})->with(['app', 'proprio']);

test('X6-A 6 forme : 10.0.0.1 acceptée, 1.2.3 refusée sans rien écrire', function (string $mode) {
    $ws = iipEspace();
    expect(iipAjouter($mode, $ws, '10.0.0.1'))->toBe(0);
    expect(iipAjouter($mode, $ws, '1.2.3'))->not->toBe(0);
    expect(iipAjouter($mode, $ws, '999.1.1.1'))->not->toBe(0);
    expect(iipAjouter($mode, $ws, '10.0.0.1', 'autre'))->not->toBe(0);
    expect(DB::table('envoi_ips')->where('workspace_id', $ws)->count())->toBe(1);
})->with(['app', 'proprio']);

test('X6-A 7 retirer une IP inconnue, ou deux fois : échec', function (string $mode) {
    $ws = iipEspace();
    $r = fn () => iipCmd($mode, $ws, 'envoi:ip-inventaire', ['workspace' => $ws, 'action' => 'retirer', 'ip' => '10.0.0.9', '--auteur' => 'test']);
    expect($r())->not->toBe(0);
    iipAjouter($mode, $ws, '10.0.0.9');
    expect($r())->toBe(0)->and($r())->not->toBe(0);
})->with(['app', 'proprio']);
