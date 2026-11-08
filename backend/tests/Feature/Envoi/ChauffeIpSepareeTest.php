<?php

use App\Envoi\Chauffe\BudgetChauffe;
use App\Envoi\Chauffe\DepotChauffe;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function espaceIp(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Ip', 'slug' => 'ip-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

test('K8 la chauffe de l\'IP est séparée : ses jours n\'avancent pas ceux du domaine, et ne gonflent pas les totaux', function () {
    $ws = espaceIp();
    $d = app(DepotChauffe::class);
    $jour = new DateTimeImmutable('2026-12-01', new DateTimeZone('Europe/Paris'));
    for ($i = 1; $i <= 8; $i++) {
        $d->incrementer($ws, 'ip-ref-1', $jour->modify("-{$i} days"), 'autre', 'envoyes', 10, BudgetChauffe::RESSOURCE_IP);
    }

    expect($d->volumesParJour($ws, 'ip-ref-1', $jour, BudgetChauffe::RESSOURCE_IP))->toHaveCount(8)
        ->and($d->volumesParJour($ws, 'ip-ref-1', $jour, BudgetChauffe::RESSOURCE_DOMAINE))->toBe([])
        ->and($d->totaux7Jours($ws, $jour, BudgetChauffe::RESSOURCE_DOMAINE)['envoyes'])->toBe(0);

    // 8 jours à 10 : l'IP est en P2 (35) ; le domaine, sans historique, reste en P1 (20) et freine.
    $b = BudgetChauffe::duJour(
        BudgetChauffe::joursEffectifs($d->volumesParJour($ws, 'ip-ref-1', $jour, BudgetChauffe::RESSOURCE_IP)),
        [],
        $jour,
    );
    expect($b->budgetIp)->toBe(35)->and($b->budget)->toBe(20)->and($b->limitant)->toBe(BudgetChauffe::RESSOURCE_DOMAINE);
});

test('K8 un type de ressource inconnu est refusé', function () {
    $d = app(DepotChauffe::class);
    $d->incrementer(espaceIp(), 'x', new DateTimeImmutable('2026-12-01'), 'autre', 'envoyes', 1, 'boite');
})->throws(InvalidArgumentException::class);

test('K8 collision : incrémenter l\'IP alors qu\'une ligne domaine existe pour le même (boîte, jour, fournisseur) => DEUX lignes distinctes', function () {
    $ws = espaceIp();
    $d = app(DepotChauffe::class);
    $jour = new DateTimeImmutable('2026-12-01', new DateTimeZone('Europe/Paris'));
    $d->incrementer($ws, 'ref-1', $jour, 'gmail', 'envoyes', 5, BudgetChauffe::RESSOURCE_DOMAINE);
    $d->incrementer($ws, 'ref-1', $jour, 'gmail', 'envoyes', 7, BudgetChauffe::RESSOURCE_IP);
    $d->incrementer($ws, 'ref-1', $jour, 'gmail', 'envoyes', 1, BudgetChauffe::RESSOURCE_IP);

    $l = WorkspaceContext::run($ws, fn () => DB::table('envoi_chauffe_jours')->where('workspace_id', $ws)->orderBy('ressource_type')->get(['ressource_type', 'envoyes']));
    expect($l)->toHaveCount(2)
        ->and($l[0]->ressource_type)->toBe('domaine')->and((int) $l[0]->envoyes)->toBe(5)
        ->and($l[1]->ressource_type)->toBe('ip')->and((int) $l[1]->envoyes)->toBe(8);
});

test('K8 la base refuse une ligne dont le type et la clé se contredisent (ip hors préfixe, domaine sous préfixe ip)', function () {
    $ws = espaceIp();
    $ligne = fn (string $boite, string $type) => WorkspaceContext::run($ws, fn () => DB::transaction(fn () => DB::table('envoi_chauffe_jours')->insert([
        'workspace_id' => $ws, 'boite' => $boite, 'jour' => '2026-12-01', 'fournisseur' => 'gmail', 'ressource_type' => $type, 'envoyes' => 1,
    ])));
    expect(fn () => $ligne('42', 'ip'))->toThrow(QueryException::class)
        ->and(fn () => $ligne('ip:42', 'domaine'))->toThrow(QueryException::class);
});

test('K8 un UPDATE de ressource_type est refusé par le garde (l\'historique d\'une IP ne devient pas du domaine)', function () {
    $ws = espaceIp();
    app(DepotChauffe::class)->incrementer($ws, 'ref-1', new DateTimeImmutable('2026-12-01'), 'gmail', 'plaintes', 1, BudgetChauffe::RESSOURCE_IP);

    // Point de reprise (DB::transaction imbriquée) : sans lui, la transaction avortée masque la vraie erreur (25P02) au nettoyage du contexte.
    // Le garde (BEFORE UPDATE) parle avant le CHECK : sans lui, l'erreur serait « violates check constraint ».
    expect(fn () => WorkspaceContext::run($ws, fn () => DB::transaction(fn () => DB::table('envoi_chauffe_jours')->where('workspace_id', $ws)->update(['ressource_type' => 'domaine']))))
        ->toThrow(QueryException::class, 'identité figée');
    $def = (string) DB::scalar("SELECT pg_get_functiondef('envoi_chauffe_jours_garde'::regproc)");
    expect($def)->toContain('ressource_type');
});
