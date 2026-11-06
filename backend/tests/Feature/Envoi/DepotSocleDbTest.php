<?php

use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function espaceEnvoi(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Test', 'slug' => 'envoi-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

test('journal en ajout seul : UPDATE et DELETE refusés', function () {
    $ws = espaceEnvoi();
    app(DepotSocle::class)->journaliser($ws, 'x', 'test');
    WorkspaceContext::run($ws, function () {
        expect(fn () => DB::table('envoi_journal_commandes')->update(['auteur' => 'z']))->toThrow(QueryException::class);
        expect(fn () => DB::table('envoi_journal_commandes')->delete())->toThrow(QueryException::class);
    });
});

test('commande envoi:temoins-off : éteint le mode et journalise', function () {
    $ws = espaceEnvoi();
    $depot = app(DepotSocle::class);
    expect($depot->modeTemoins($ws))->toBeTrue();
    Artisan::call('envoi:temoins-off', ['workspace' => $ws, '--auteur' => 'will']);
    expect($depot->modeTemoins($ws))->toBeFalse();
    $n = WorkspaceContext::run($ws, fn () => DB::table('envoi_journal_commandes')->where('commande', 'temoins_off')->where('auteur', 'will')->count());
    expect($n)->toBe(1);
});

test('dépôt : témoins, tout arrêter, compteur du jour', function () {
    $ws = espaceEnvoi();
    $depot = app(DepotSocle::class);
    expect($depot->toutArreter($ws))->toBeFalse();
    expect($depot->estTemoin($ws, 'a@example.test'))->toBeFalse();
    WorkspaceContext::run($ws, function () use ($ws) {
        DB::table('envoi_temoins')->insert(['workspace_id' => $ws, 'adresse' => 'A@example.test']);
        DB::table('envoi_drapeaux')->insert(['workspace_id' => $ws, 'tout_arreter' => true]);
    });
    expect($depot->estTemoin($ws, ' a@example.test '))->toBeTrue();
    expect($depot->toutArreter($ws))->toBeTrue();
    $j = new DateTimeImmutable('2026-11-10');
    $depot->noterInjection($ws, $j);
    expect($depot->injectesLe($ws, $j))->toBe(1)->and($depot->injectesLe($ws, $j->modify('+1 day')))->toBe(0);
});

test('conteneur : la passerelle liée refuse tant que ENVOI_REEL_ACTIF est éteint', function () {
    $ws = espaceEnvoi();
    $m = new MessageAEnvoyer($ws, 'a@example.test', 'f@example.test', 'S', 'C', 'k');
    expect(fn () => app(ServeurEnvoi::class)->injecter($m))->toThrow(EnvoiRefuse::class);
});
