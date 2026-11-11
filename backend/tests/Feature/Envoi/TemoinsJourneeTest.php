<?php

use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\FeuVert\CatalogueManuels;
use App\Envoi\FeuVert\ListeFeuVert;
use App\Envoi\JourEnvoi;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('envoi');

beforeEach(fn () => config(['envoi.cle_empreinte' => 'cle-de-test-seulement']));

function espaceJournee(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Test', 'slug' => 'tj-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function jourRelatif(int $decalage): string
{
    return JourEnvoi::de()->modify(($decalage >= 0 ? '+' : '') . $decalage . ' days')->format('Y-m-d');
}

/** Enregistre une journée par la commande (jamais d'adresse). Vert par défaut. */
function noterJournee(string $ws, string $fournisseur, int $decalage = 0, bool $vert = true, array $options = []): int
{
    $params = ['workspace' => $ws, 'fournisseur' => $fournisseur, '--jour' => jourRelatif($decalage), '--auteur' => 'testeur']
        + ($vert ? ['--reception' => 'inbox', '--auth-alignee' => true, '--bouton-vu' => true, '--post-effectif' => true] : ['--reception' => 'absent']);

    return Artisan::call('envoi:temoins-journee', $options + $params + ['--no-interaction' => true]);
}

function jourComplet(string $ws, int $decalage, bool $vert = true, array $sauf = []): void
{
    foreach (array_diff(DepotSocleDb::FOURNISSEURS_TEMOINS, $sauf) as $f) {
        expect(noterJournee($ws, $f, $decalage, $vert))->toBe(0);
    }
}

function pointTroisJours(string $ws): bool
{
    foreach (app(ListeFeuVert::class)->points($ws) as $p) {
        if ($p->code === 'auto.temoins_3_jours') {
            return $p->fait;
        }
    }
    throw new RuntimeException('point absent');
}

test('catalogue : auto.temoins_3_jours, groupe temoins, nature auto, source F15', function () {
    $c = CatalogueManuels::POINTS['auto.temoins_3_jours'];
    expect($c['groupe'])->toBe('temoins')->and($c['source'])->toContain('F15')->and($c['serveur_reel'])->toBeFalse();
    expect(CatalogueManuels::estManuel('auto.temoins_3_jours'))->toBeFalse();
});

test('3 jours consécutifs verts chez les 6 fournisseurs : fait', function () {
    $ws = espaceJournee();
    foreach ([0, -1, -2] as $d) {
        jourComplet($ws, $d);
    }
    expect(pointTroisJours($ws))->toBeTrue();
});

test('hier, avant-hier et le jour d’avant : fait même sans ligne aujourd’hui', function () {
    $ws = espaceJournee();
    foreach ([-1, -2, -3] as $d) {
        jourComplet($ws, $d);
    }
    expect(pointTroisJours($ws))->toBeTrue();
});

test('2 jours seulement : non', function () {
    $ws = espaceJournee();
    foreach ([0, -1] as $d) {
        jourComplet($ws, $d);
    }
    expect(pointTroisJours($ws))->toBeFalse();
});

test('un jour rouge au milieu remet le compteur à zéro', function () {
    $ws = espaceJournee();
    jourComplet($ws, 0);
    jourComplet($ws, -1, false);
    jourComplet($ws, -2);
    jourComplet($ws, -3);
    expect(pointTroisJours($ws))->toBeFalse();
});

test('un trou d’un jour : non', function () {
    $ws = espaceJournee();
    jourComplet($ws, 0);
    jourComplet($ws, -2);
    jourComplet($ws, -3);
    expect(pointTroisJours($ws))->toBeFalse();
});

test('5 fournisseurs sur 6 un jour : jour rouge, non', function () {
    $ws = espaceJournee();
    jourComplet($ws, 0);
    jourComplet($ws, -1, true, ['orange']);
    jourComplet($ws, -2);
    expect(pointTroisJours($ws))->toBeFalse();
});

test('chaque critère compte : indésirables, authentification, bouton, POST', function (array $options) {
    $ws = espaceJournee();
    jourComplet($ws, 0);
    jourComplet($ws, -1, true, ['yahoo']);
    // Le jour -1 est vert chez les 5 autres ; la ligne de yahoo manque un critère : jour rouge.
    $base = ['--reception' => 'inbox', '--auth-alignee' => true, '--bouton-vu' => true, '--post-effectif' => true];
    foreach ($options as $cle => $valeur) {
        if ($valeur === null) {
            unset($base[$cle]);
        }
    }
    expect(Artisan::call('envoi:temoins-journee', ['workspace' => $ws, 'fournisseur' => 'yahoo', '--jour' => jourRelatif(-1), '--auteur' => 't', '--no-interaction' => true] + $base))->toBe(0);
    jourComplet($ws, -2);
    expect(pointTroisJours($ws))->toBeFalse();
})->with([
    'authentification' => [['--auth-alignee' => null]],
    'bouton' => [['--bouton-vu' => null]],
    'post' => [['--post-effectif' => null]],
]);

test('corriger un jour rouge en vert par une nouvelle ligne : la dernière fait foi', function () {
    $ws = espaceJournee();
    jourComplet($ws, 0);
    jourComplet($ws, -1, false);
    jourComplet($ws, -2);
    expect(pointTroisJours($ws))->toBeFalse();
    jourComplet($ws, -1);
    expect(pointTroisJours($ws))->toBeTrue();
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins_journees')->where('jour', jourRelatif(-1))->count()))->toBe(12);
});

test('isolement entre espaces', function () {
    $a = espaceJournee();
    $b = espaceJournee();
    foreach ([0, -1, -2] as $d) {
        jourComplet($a, $d);
    }
    expect(pointTroisJours($a))->toBeTrue()->and(pointTroisJours($b))->toBeFalse();
    expect(DB::table('envoi_temoins_journees')->where('workspace_id', $b)->count())->toBe(0);
});

test('ajout seul : UPDATE, DELETE et TRUNCATE refusés', function () {
    $ws = espaceJournee();
    noterJournee($ws, 'gmail');
    foreach (['UPDATE envoi_temoins_journees SET bouton_vu = false', 'DELETE FROM envoi_temoins_journees', 'TRUNCATE envoi_temoins_journees'] as $sql) {
        expect(fn () => DB::transaction(fn () => WorkspaceContext::run($ws, fn () => DB::statement($sql))))->toThrow(QueryException::class);
    }
    expect(Schema::hasTable('envoi_temoins_journees'))->toBeTrue();
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins_journees')->count()))->toBe(1);
});

test('aucune écriture dans envoi_drapeaux, ENVOI_REEL_ACTIF inchangé, point lu seulement', function () {
    $ws = espaceJournee();
    DB::table('envoi_drapeaux')->insert(['workspace_id' => $ws, 'mode_temoins' => true]);
    $avant = DB::table('envoi_drapeaux')->where('workspace_id', $ws)->first();
    $reel = config('envoi.reel_actif');
    foreach ([0, -1, -2] as $d) {
        jourComplet($ws, $d);
    }
    pointTroisJours($ws);
    expect(DB::table('envoi_drapeaux')->where('workspace_id', $ws)->first())->toEqual($avant);
    expect(config('envoi.reel_actif'))->toBe($reel)->and($reel)->toBeFalse();
});

test('refus : fournisseur inconnu, réception invalide, workspace invalide, jour futur ; aucune ligne', function () {
    $ws = espaceJournee();
    $base = ['--reception' => 'inbox', '--auteur' => 't', '--no-interaction' => true];
    expect(Artisan::call('envoi:temoins-journee', ['workspace' => $ws, 'fournisseur' => 'inconnu'] + $base))->toBe(1);
    expect(Artisan::call('envoi:temoins-journee', ['workspace' => $ws, 'fournisseur' => 'gmail', '--reception' => 'spam', '--no-interaction' => true]))->toBe(1);
    expect(Artisan::call('envoi:temoins-journee', ['workspace' => 'pas-un-uuid', 'fournisseur' => 'gmail'] + $base))->toBe(1);
    expect(Artisan::call('envoi:temoins-journee', ['workspace' => $ws, 'fournisseur' => 'gmail', '--jour' => jourRelatif(2)] + $base))->toBe(1);
    expect(DB::table('envoi_temoins_journees')->count())->toBe(0);
});

test('aucune option ni argument ne prend d’adresse ; sortie et logs sans adresse', function () {
    $def = Artisan::all()['envoi:temoins-journee']->getDefinition();
    expect(array_keys($def->getArguments()))->toBe(['workspace', 'fournisseur']);
    foreach (array_keys($def->getOptions()) as $nom) {
        expect($nom)->not->toContain('adresse')->not->toContain('email')->not->toContain('mail');
    }
    Log::spy();
    $ws = espaceJournee();
    noterJournee($ws, 'gmail');
    expect(Artisan::output())->not->toContain('@');
    Log::shouldNotHaveReceived('info');
});

test('indésirables : appelle la garde existante (adresse sur STDIN, témoin du bon fournisseur) et ouvre une pause', function () {
    $ws = espaceJournee();
    expect(appelerSansAdresseEnArgument('envoi:temoin-ajouter', ['workspace' => $ws, 'fournisseur' => 'gmail', '--libelle' => 'Gmail de test', '--auteur' => 't'], 'alice@gmail.com'))->toBe(0);
    $code = appelerSansAdresseEnArgument('envoi:temoins-journee', ['workspace' => $ws, 'fournisseur' => 'gmail', '--reception' => 'indesirables', '--jour' => jourRelatif(0), '--auteur' => 't'], 'alice@gmail.com');
    expect($code)->toBe(0);
    expect(Artisan::output())->not->toContain('alice')->not->toContain('@');
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins_journees')->where('reception', 'indesirables')->count()))->toBe(1);
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_pauses')->count()))->toBeGreaterThan(0);
});

test('indésirables : adresse absente, inconnue ou d’un autre fournisseur refusée, aucune ligne', function () {
    $ws = espaceJournee();
    appelerSansAdresseEnArgument('envoi:temoin-ajouter', ['workspace' => $ws, 'fournisseur' => 'gmail', '--libelle' => 'g', '--auteur' => 't'], 'alice@gmail.com');
    $p = ['workspace' => $ws, 'fournisseur' => 'yahoo', '--reception' => 'indesirables', '--jour' => jourRelatif(0), '--auteur' => 't'];
    expect(appelerSansAdresseEnArgument('envoi:temoins-journee', $p, null))->toBe(1);
    expect(appelerSansAdresseEnArgument('envoi:temoins-journee', $p, 'inconnu@exemple.test'))->toBe(1);
    expect(appelerSansAdresseEnArgument('envoi:temoins-journee', $p, 'alice@gmail.com'))->toBe(1);
    expect(DB::table('envoi_temoins_journees')->count())->toBe(0);
});
