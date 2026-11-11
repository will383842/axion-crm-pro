<?php

use App\Envoi\Chauffe\BudgetChauffe;
use App\Envoi\Chauffe\CourbeChauffe;
use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Exceptions\ReculRefuse;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function espaceReculs(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Rc', 'slug' => 'rc-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function jourReculs(string $d = '2026-12-01'): DateTimeImmutable
{
    return new DateTimeImmutable($d, new DateTimeZone('Europe/Paris'));
}

/** $n jours d'envoi (volume suffisant) finissant la veille de $jour, pour la ressource. */
function chaufferReculs(DepotChauffe $d, string $ws, string $type, string $ressource, DateTimeImmutable $jour, int $n): void
{
    for ($i = 1; $i <= $n; $i++) {
        $d->incrementer($ws, $ressource, $jour->modify("-{$i} days"), 'autre', 'envoyes', 20, $type);
    }
}

test('F07a un recul s\'enregistre, se relit, et ne touche que la ressource nommée', function () {
    $ws = espaceReculs();
    $d = app(DepotChauffe::class);
    $j = jourReculs();
    chaufferReculs($d, $ws, BudgetChauffe::RESSOURCE_IP, 'ip-1', $j, 8);

    expect($d->reculer($ws, 'ip', 'ip-1', 'plaintes', 'responsable', $j))->toBeTrue();
    expect($d->reculsDe($ws, 'ip', 'ip-1', $j))->toHaveCount(1)
        ->and($d->reculsDe($ws, 'domaine', 'ip-1', $j))->toBe([])      // l'autre type, même nom : non touché
        ->and($d->reculsDe($ws, 'ip', 'ip-2', $j))->toBe([]);          // une autre ressource : non touchée
    expect($d->reculsDe($ws, 'ip', 'ip-1', $j->modify('-1 day')))->toBe([]);   // pas de recul « du futur »
});

test('F07a idempotent dans la journée : un second recul le même jour ne recule pas deux fois', function () {
    $ws = espaceReculs();
    $d = app(DepotChauffe::class);
    $j = jourReculs();
    chaufferReculs($d, $ws, 'domaine', 'dom-1', $j, 12);   // P3

    expect($d->reculer($ws, 'domaine', 'dom-1', 'a', 'responsable', $j))->toBeTrue()
        ->and($d->reculer($ws, 'domaine', 'dom-1', 'b', 'responsable', $j->modify('+5 hours')))->toBeFalse();
    expect($d->reculsDe($ws, 'domaine', 'dom-1', $j))->toHaveCount(1);
    $effectifs = BudgetChauffe::joursEffectifs($d->volumesParJour($ws, 'dom-1', $j, 'domaine'));
    expect(CourbeChauffe::palierEtendu(CourbeChauffe::jourDeChauffeAvecReculs($effectifs, $d->reculsDe($ws, 'domaine', 'dom-1', $j), $j)))->toBe('P2');
});

test('F07a recul en P1 refusé : 409, rien n\'est écrit ; recul du lendemain après un recul vers P1 aussi', function () {
    $ws = espaceReculs();
    $d = app(DepotChauffe::class);
    $j = jourReculs();

    try {
        $d->reculer($ws, 'ip', 'ip-neuve', 'test', 'responsable', $j);
        $this->fail('409 attendu');
    } catch (ReculRefuse $e) {
        expect($e->statut())->toBe(409)->and($e->getCode())->toBe(409);
    }
    expect($d->reculsDe($ws, 'ip', 'ip-neuve', $j))->toBe([]);

    chaufferReculs($d, $ws, 'ip', 'ip-2', $j, 8);                       // P2
    expect($d->reculer($ws, 'ip', 'ip-2', 'x', 'responsable', $j))->toBeTrue();   // -> P1
    expect(fn () => $d->reculer($ws, 'ip', 'ip-2', 'y', 'responsable', $j->modify('+1 day')))->toThrow(ReculRefuse::class);
});

test('F07a type inconnu refusé', function () {
    app(DepotChauffe::class)->reculer(espaceReculs(), 'boite', 'b-1', 'x', 'responsable', jourReculs());
})->throws(InvalidArgumentException::class);

test('F07a isolement entre espaces : aucun recul visible, aucun effet sur l\'autre espace', function () {
    $a = espaceReculs();
    $b = espaceReculs();
    $d = app(DepotChauffe::class);
    $j = jourReculs();
    chaufferReculs($d, $a, 'ip', 'ip-1', $j, 8);
    chaufferReculs($d, $b, 'ip', 'ip-1', $j, 8);

    expect($d->reculer($a, 'ip', 'ip-1', 'x', 'responsable', $j))->toBeTrue();
    expect($d->reculsDe($b, 'ip', 'ip-1', $j))->toBe([]);
    expect($d->reculer($b, 'ip', 'ip-1', 'x', 'responsable', $j))->toBeTrue();   // pas bloqué par le recul de $a
    // RLS ENABLE + FORCE + politique sur app.current_workspace_id (la preuve de fuite elle-même est dans le test d'étanchéité, via le semeur)
    $t = DB::selectOne("SELECT relrowsecurity AS e, relforcerowsecurity AS f FROM pg_class WHERE relname = 'envoi_chauffe_reculs'");
    expect($t->e)->toBeTrue()->and($t->f)->toBeTrue();
    expect((string) DB::selectOne("SELECT qual FROM pg_policies WHERE tablename = 'envoi_chauffe_reculs'")->qual)->toContain('app.current_workspace_id');
});

test('F07a ajout seul : UPDATE, DELETE et TRUNCATE refusés par le déclencheur', function () {
    $ws = espaceReculs();
    $d = app(DepotChauffe::class);
    $j = jourReculs();
    chaufferReculs($d, $ws, 'ip', 'ip-1', $j, 8);
    $d->reculer($ws, 'ip', 'ip-1', 'x', 'responsable', $j);

    WorkspaceContext::run($ws, function () {
        expect(fn () => DB::transaction(fn () => DB::table('envoi_chauffe_reculs')->update(['motif' => 'y'])))->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('envoi_chauffe_reculs')->delete()))->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::statement('TRUNCATE envoi_chauffe_reculs')))->toThrow(QueryException::class);
    });
});

test('F07a la base refuse un type hors ip|domaine et un motif vide', function () {
    $ws = espaceReculs();
    $ligne = ['workspace_id' => $ws, 'ressource_type' => 'ip', 'ressource' => 'r', 'motif' => 'm', 'auteur' => 'a', 'date_recul' => '2026-12-01'];
    WorkspaceContext::run($ws, function () use ($ligne) {
        expect(fn () => DB::transaction(fn () => DB::table('envoi_chauffe_reculs')->insert([...$ligne, 'ressource_type' => 'boite'])))->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('envoi_chauffe_reculs')->insert([...$ligne, 'motif' => ''])))->toThrow(QueryException::class);
    });
});
