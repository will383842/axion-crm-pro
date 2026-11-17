<?php

use App\Envoi\Chauffe\BudgetChauffe;
use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Chauffe\DepotIps;
use App\Envoi\Chauffe\GardeChauffe;
use App\Envoi\Chauffe\ResolveurEnMemoire;
use App\Envoi\Chauffe\ResolveurFournisseur;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Depot\DepotSocle;
use App\Support\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * Lot X6-B — reculs d'IP appliqués par la garde, et comptage des envois par IP. Joué sous le rôle applicatif ET sous
 * le propriétaire. Le lien message -> IP vient de l'inventaire (domaine de l'expéditeur -> ip_id), pas du transport.
 * Données fictives : domaines *.example.test, IP privées.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-10 11:00:00', 'UTC')); // 12:00 Paris
    config(['envoi.cle_empreinte' => 'cle-de-test-seulement']);
    app()->instance(ResolveurFournisseur::class, new ResolveurEnMemoire(['gmail.com' => 'gmail']));
});

afterEach(fn () => Carbon::setTestNow());

function ripgJour(string $j = '2026-11-10'): DateTimeImmutable
{
    return new DateTimeImmutable($j, new DateTimeZone('Europe/Paris'));
}

function ripgEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Ripg', 'slug' => 'ripg-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    Artisan::call('envoi:temoins-off', ['workspace' => $id, '--auteur' => 'test']);
    WorkspaceContext::run($id, fn () => DB::table('envoi_journal_commandes')->insert([
        'workspace_id' => $id, 'commande' => 'tableau_valide', 'auteur' => 'test', 'details' => '{}',
        'jour' => '2026-11-10', 'cree_le' => '2026-11-10 08:00:00+01',
    ]));

    return $id;
}

/** Domaine + boîte `a@domaine` ; adaptateur « kumo » ou « faux » ; IP (ou null) inscrite et reliée. */
function ripgDomaine(string $ws, string $domaine, string $adaptateur, ?string $ip): void
{
    DB::table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => $domaine, 'achete_le' => '2026-01-01', 'flux' => 'prospection']);
    WorkspaceContext::run($ws, fn () => DB::table('envoi_boites')->insert([
        'id' => (string) Str::uuid(), 'workspace_id' => $ws, 'adresse' => "a@{$domaine}", 'hote' => 'smtp.example.test', 'port' => 587,
        'identifiants_chiffres' => 'chiffre-de-test',
    ]));
    if ($adaptateur !== 'faux') {
        WorkspaceContext::run($ws, fn () => DB::select('SELECT envoi_domaine_adaptateur_changer(?, ?, ?, ?)', [$ws, $domaine, $adaptateur, 'test']));
    }
    if ($ip !== null) {
        $depot = app(DepotIps::class);
        if (! in_array($ip, $depot->ipsParFlux($ws)['prospection'], true)) {
            $depot->ajouter($ws, $ip, 'prospection', 'test');
        }
        $depot->lier($ws, $domaine, $ip, 'test');
    }
}

function ripgIpEnvoyes(string $ws, string $ip, int $n, string $jour = '2026-11-10'): void
{
    app(DepotChauffe::class)->incrementer($ws, $ip, ripgJour($jour), 'autre', 'envoyes', $n, BudgetChauffe::RESSOURCE_IP);
}

function ripgRecul(string $ws, string $ip, string $jour = '2026-11-10'): void
{
    WorkspaceContext::run($ws, fn () => DB::table('envoi_chauffe_reculs')->insert([
        'workspace_id' => $ws, 'ressource_type' => 'ip', 'ressource' => $ip, 'motif' => 'test', 'auteur' => 'test', 'date_recul' => $jour,
    ]));
}

function ripgMessage(string $ws, string $domaine): MessageAEnvoyer
{
    return new MessageAEnvoyer($ws, 'x@gmail.com', "a@{$domaine}", 'S', 'C', (string) Str::uuid());
}

function ripgGarde(): GardeChauffe
{
    return new GardeChauffe(app(DepotChauffe::class), app(DepotSocle::class), app(ResolveurFournisseur::class), null);
}

/** Exécute $f sous le rôle choisi (contexte d'espace posé), puis restaure. */
function ripgSous(string $mode, string $ws, Closure $f): mixed
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

function ripgRefus(string $mode, string $ws, string $domaine): ?string
{
    $maintenant = new DateTimeImmutable('2026-11-10 12:00:00', new DateTimeZone('Europe/Paris'));

    return ripgSous($mode, $ws, function () use ($ws, $domaine, $maintenant) {
        $garde = ripgGarde();
        $garde->battre($maintenant);

        return $garde->refus(ripgMessage($ws, $domaine), ripgJour(), $maintenant, 'gmail');
    });
}

test('X6-B 1 deux domaines sur la même IP reculée en P1 : les deux sont bloqués à 20 cumulés', function (string $mode) {
    $ws = ripgEspace();
    ripgDomaine($ws, 'a.example.test', 'kumo', '10.0.0.1');
    ripgDomaine($ws, 'b.example.test', 'kumo', '10.0.0.1');
    ripgRecul($ws, '10.0.0.1');
    ripgIpEnvoyes($ws, '10.0.0.1', 19);
    expect(ripgRefus($mode, $ws, 'a.example.test'))->toBeNull();
    expect(ripgRefus($mode, $ws, 'b.example.test'))->toBeNull();
    ripgIpEnvoyes($ws, '10.0.0.1', 1);
    expect(ripgRefus($mode, $ws, 'a.example.test'))->toBe(GardeChauffe::QUOTA_IP_ATTEINT);
    expect(ripgRefus($mode, $ws, 'b.example.test'))->toBe(GardeChauffe::QUOTA_IP_ATTEINT);
})->with(['app', 'proprio']);

test('X6-B 2 sans recul d\'IP : seuils inchangés', function (string $mode) {
    $ws = ripgEspace();
    ripgDomaine($ws, 'a.example.test', 'kumo', '10.0.0.1');
    ripgIpEnvoyes($ws, '10.0.0.1', 25);
    expect(ripgRefus($mode, $ws, 'a.example.test'))->toBeNull();
})->with(['app', 'proprio']);

test('X6-B 3 domaine kumo sans IP : ip_inconnue (échec fermé) ; IP retirée : de même', function (string $mode) {
    $ws = ripgEspace();
    ripgDomaine($ws, 'a.example.test', 'kumo', null);
    expect(ripgRefus($mode, $ws, 'a.example.test'))->toBe(GardeChauffe::IP_INCONNUE);
    ripgDomaine($ws, 'b.example.test', 'kumo', '10.0.0.2');
    expect(ripgRefus($mode, $ws, 'b.example.test'))->toBeNull();
    app(DepotIps::class)->retirer($ws, '10.0.0.2');
    expect(ripgRefus($mode, $ws, 'b.example.test'))->toBe(GardeChauffe::IP_INCONNUE);
})->with(['app', 'proprio']);

test('X6-B 4 domaine sur le faux serveur sans IP : comportement de main', function (string $mode) {
    $ws = ripgEspace();
    ripgDomaine($ws, 'a.example.test', 'faux', null);
    expect(ripgRefus($mode, $ws, 'a.example.test'))->toBeNull();
})->with(['app', 'proprio']);

test('X6-B 5 noter incrémente la boîte ET l\'IP ; sans IP, la boîte seule', function (string $mode) {
    $ws = ripgEspace();
    ripgDomaine($ws, 'a.example.test', 'kumo', '10.0.0.1');
    ripgDomaine($ws, 'b.example.test', 'faux', null);
    ripgSous($mode, $ws, function () use ($ws) {
        ripgGarde()->noter(ripgMessage($ws, 'a.example.test'), ripgJour(), 'gmail');
        ripgGarde()->noter(ripgMessage($ws, 'b.example.test'), ripgJour(), 'gmail');
    });
    $d = app(DepotChauffe::class);
    expect($d->envoyesBoite($ws, (string) $d->boiteId($ws, 'a@a.example.test'), ripgJour()))->toBe(1);
    expect($d->envoyesBoite($ws, (string) $d->boiteId($ws, 'a@b.example.test'), ripgJour()))->toBe(1);
    expect($d->envoyesIp($ws, '10.0.0.1', ripgJour()))->toBe(1);
})->with(['app', 'proprio']);

test('X6-B 6 le recul de l\'IP de l\'espace B est sans effet sur A', function (string $mode) {
    $a = ripgEspace();
    $b = ripgEspace();
    ripgDomaine($a, 'a.example.test', 'kumo', '10.0.0.1');
    ripgDomaine($b, 'a.example.test', 'kumo', '10.0.0.1');
    ripgRecul($b, '10.0.0.1');
    ripgIpEnvoyes($a, '10.0.0.1', 25);
    ripgIpEnvoyes($b, '10.0.0.1', 25);
    expect(ripgRefus($mode, $a, 'a.example.test'))->toBeNull();
    expect(ripgRefus($mode, $b, 'a.example.test'))->toBe(GardeChauffe::QUOTA_IP_ATTEINT);
})->with(['app', 'proprio']);

test('X6-B 7 jamais de montée : une IP très chauffée puis reculée reste bornée au palier maximal (50)', function (string $mode) {
    $ws = ripgEspace();
    ripgDomaine($ws, 'a.example.test', 'kumo', '10.0.0.1');
    for ($i = 1; $i <= 40; $i++) {
        ripgIpEnvoyes($ws, '10.0.0.1', 150, ripgJour()->modify("-{$i} days")->format('Y-m-d'));
    }
    ripgRecul($ws, '10.0.0.1');
    ripgIpEnvoyes($ws, '10.0.0.1', 49);
    expect(ripgRefus($mode, $ws, 'a.example.test'))->toBeNull();
    ripgIpEnvoyes($ws, '10.0.0.1', 1);
    expect(ripgRefus($mode, $ws, 'a.example.test'))->toBe(GardeChauffe::QUOTA_IP_ATTEINT);
})->with(['app', 'proprio']);
