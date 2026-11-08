<?php

use App\Envoi\Dns\ConfigurationDomaine;
use App\Envoi\Dns\ControleurDns;
use App\Envoi\Dns\DepotControlesDnsDb;
use App\Envoi\Dns\ResolveurDns;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Unit\Envoi\Dns\FauxResolveurDns;

uses(TestCase::class, RefreshDatabase::class);

require_once __DIR__ . '/../../Unit/Envoi/Dns/FauxResolveurDns.php';

function espaceDns(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Test', 'slug' => 'dns-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function domaineDns(string $ws, string $nom = 'envoi.example.test'): void
{
    WorkspaceContext::run($ws, fn () => DB::table('envoi_domaines')->insert([
        'workspace_id' => $ws, 'nom' => $nom, 'achete_le' => '2026-01-01',
    ]));
}

test('la table envoi_controles_dns existe, avec RLS forcée', function () {
    $r = DB::selectOne("SELECT relrowsecurity AS rls, relforcerowsecurity AS force FROM pg_class WHERE relname = 'envoi_controles_dns'");
    expect($r)->not->toBeNull()->and($r->rls)->toBeTrue()->and($r->force)->toBeTrue();
});

test('enregistrer écrit une ligne isolée par workspace', function () {
    $a = espaceDns();
    $b = espaceDns();
    $rapport = (new ControleurDns(FauxResolveurDns::conforme()))->controler(new ConfigurationDomaine('envoi.example.test', 'sel1', 'mx.envoi.example.test', '192.0.2.10'));
    (new DepotControlesDnsDb)->enregistrer($a, $rapport, new DateTimeImmutable('2026-11-11 06:00:00'));

    expect(WorkspaceContext::run($a, fn () => DB::table('envoi_controles_dns')->where('workspace_id', $a)->count()))->toBe(1)
        ->and(WorkspaceContext::run($b, fn () => DB::table('envoi_controles_dns')->where('workspace_id', $b)->count()))->toBe(0);
});

test('marquerVerifie pose les trois dates sur le domaine du workspace seulement', function () {
    $a = espaceDns();
    $b = espaceDns();
    domaineDns($a);
    domaineDns($b);
    (new DepotControlesDnsDb)->marquerVerifie($a, 'envoi.example.test', new DateTimeImmutable('2026-11-11 06:00:00'));

    $la = WorkspaceContext::run($a, fn () => DB::table('envoi_domaines')->where('workspace_id', $a)->first());
    $lb = WorkspaceContext::run($b, fn () => DB::table('envoi_domaines')->where('workspace_id', $b)->first());
    expect($la->spf_verifie_le)->not->toBeNull()->and($la->dkim_verifie_le)->not->toBeNull()->and($la->dmarc_verifie_le)->not->toBeNull()
        ->and($lb->spf_verifie_le)->toBeNull();
});

test('le rôle applicatif ne peut ni modifier ni effacer les contrôles', function () {
    $ws = espaceDns();
    $role = (string) config('database.connections.pgsql_app.username', 'axion_app');
    if (DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$role]) === null) {
        $this->markTestSkipped('rôle applicatif absent');
    }
    foreach (['UPDATE', 'DELETE', 'TRUNCATE'] as $priv) {
        $a = DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS a', [$role, 'public.envoi_controles_dns', $priv]);
        expect($a->a)->toBeFalse();
    }
});

test('la commande quotidienne contrôle chaque domaine configuré, sans réseau réel', function () {
    $ws = espaceDns();
    domaineDns($ws);
    config(['envoi.dns.domaines' => ['envoi.example.test' => ['selecteur' => 'sel1', 'helo' => 'mx.envoi.example.test', 'ip' => '192.0.2.10']]]);
    $faux = FauxResolveurDns::conforme();
    $this->app->instance(ResolveurDns::class, $faux);

    expect(Artisan::call('envoi:controle-dns'))->toBe(0);

    $ligne = WorkspaceContext::run($ws, fn () => DB::table('envoi_controles_dns')->where('workspace_id', $ws)->first());
    expect($ligne)->not->toBeNull()->and($ligne->pret)->toBeTrue()->and($ligne->pause)->toBeFalse();
});

test('la commande : DMARC absent => pause journalisée', function () {
    $ws = espaceDns();
    domaineDns($ws);
    config(['envoi.dns.domaines' => ['envoi.example.test' => ['selecteur' => 'sel1', 'helo' => 'mx.envoi.example.test', 'ip' => '192.0.2.10']]]);
    $faux = FauxResolveurDns::conforme();
    unset($faux->txt['_dmarc.envoi.example.test']);
    $this->app->instance(ResolveurDns::class, $faux);

    Artisan::call('envoi:controle-dns');

    $n = WorkspaceContext::run($ws, fn () => DB::table('envoi_journal_commandes')->where('workspace_id', $ws)->where('commande', 'pause_domaine_dmarc_absent')->count());
    expect($n)->toBe(1);
});

test('la commande ignore un domaine sans configuration DNS et ne plante pas', function () {
    $ws = espaceDns();
    domaineDns($ws, 'inconnu.example.test');
    config(['envoi.dns.domaines' => []]);
    $this->app->instance(ResolveurDns::class, new FauxResolveurDns);

    expect(Artisan::call('envoi:controle-dns'))->toBe(0);
});
