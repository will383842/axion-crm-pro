<?php

use App\Envoi\Temoins\DepotAccesTemoins;
use App\Envoi\Temoins\HotesTemoins;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(fn () => config(['envoi.cle_empreinte' => 'cle-de-test-seulement']));

function espaceR16(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Test', 'slug' => 'r16-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** Les deux lignes (adresse puis mot de passe) arrivent sur l'entrée standard, jamais en argument. */
function poserAccesR16(string $ws, string $fournisseur, string $adresse, string $mdp): int
{
    $flux = fopen('php://memory', 'r+');
    fwrite($flux, $adresse . "\n" . $mdp . "\n");
    rewind($flux);
    app()->instance('envoi.flux_entree', $flux);
    try {
        return Artisan::call('envoi:temoins-acces', ['workspace' => $ws, 'fournisseur' => $fournisseur, '--no-interaction' => true]);
    } finally {
        app()->forgetInstance('envoi.flux_entree');
        fclose($flux);
    }
}

test('liste fermée : six fournisseurs, hôtes codés, jamais d’hôte libre', function () {
    expect(array_keys(HotesTemoins::LISTE))->toBe(['gmail', 'google_workspace', 'outlook_com', 'microsoft_365', 'yahoo', 'orange']);
    foreach (HotesTemoins::LISTE as $fiche) {
        expect($fiche['hote'])->toMatch('/^[a-z0-9.-]+\.[a-z]+$/')->and($fiche['dossiers'])->not->toBeEmpty();
    }
    expect(fn () => HotesTemoins::pour('exemple.invalid'))->toThrow(InvalidArgumentException::class);
    expect(HotesTemoins::hoteAutorise('imap.gmail.com'))->toBeTrue()
        ->and(HotesTemoins::hoteAutorise('169.254.169.254'))->toBeFalse()
        ->and(HotesTemoins::hoteAutorise('127.0.0.1'))->toBeFalse();
});

test('Yahoo et Orange se rattachent à « autre » pour la pause', function () {
    expect(HotesTemoins::pourPause('gmail'))->toBe('gmail')
        ->and(HotesTemoins::pourPause('google_workspace'))->toBe('gmail')
        ->and(HotesTemoins::pourPause('outlook_com'))->toBe('microsoft')
        ->and(HotesTemoins::pourPause('microsoft_365'))->toBe('microsoft')
        ->and(HotesTemoins::pourPause('yahoo'))->toBe('autre')
        ->and(HotesTemoins::pourPause('orange'))->toBe('autre');
});

test('la commande pose un accès chiffré, sans jamais afficher ni journaliser l’adresse ou le mot de passe', function () {
    $ws = espaceR16();
    Log::spy();
    expect(poserAccesR16($ws, 'gmail', 'Alice@exemple.test', 'mdp-super-secret'))->toBe(0);
    $sortie = Artisan::output();
    expect($sortie)->not->toContain('alice')->and($sortie)->not->toContain('exemple.test')->and($sortie)->not->toContain('mdp-super-secret');
    $ligne = WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins_acces')->first());
    expect($ligne->hote)->toBe('imap.gmail.com')
        ->and($ligne->fournisseur)->toBe('gmail')
        ->and($ligne->identifiant_chiffre)->not->toContain('mdp-super-secret')->and($ligne->identifiant_chiffre)->not->toContain('exemple.test');
    $acces = app(DepotAccesTemoins::class)->lister($ws);
    expect($acces)->toHaveCount(1)
        ->and($acces[0]->adresse)->toBe('alice@exemple.test')
        ->and($acces[0]->motDePasse)->toBe('mdp-super-secret');
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});

test('la commande refuse un fournisseur hors liste, une option d’hôte et une adresse mal formée', function () {
    $ws = espaceR16();
    expect(poserAccesR16($ws, 'serveur-libre', 'a@exemple.test', 'x'))->toBe(1)
        ->and(poserAccesR16($ws, 'gmail', 'pas-une-adresse', 'x'))->toBe(1)
        ->and(poserAccesR16($ws, 'gmail', 'a@exemple.test', ''))->toBe(1);
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins_acces')->count()))->toBe(0);
    expect(fn () => Artisan::call('envoi:temoins-acces', ['workspace' => $ws, 'fournisseur' => 'gmail', '--hote' => 'evil.example.invalid']))
        ->toThrow(InvalidOptionException::class);
});

test('la base refuse un hôte hors liste et la table est en ajout seul', function () {
    $ws = espaceR16();
    $ligne = ['workspace_id' => $ws, 'empreinte_temoin' => str_repeat('a', 64), 'fournisseur' => 'gmail', 'identifiant_chiffre' => 'x'];
    expect(fn () => DB::transaction(fn () => WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins_acces')->insert($ligne + ['hote' => '10.0.0.1']))))->toThrow(QueryException::class);
    WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins_acces')->insert($ligne + ['hote' => 'imap.gmail.com']));
    expect(fn () => DB::transaction(fn () => DB::table('envoi_temoins_acces')->update(['hote' => 'imap.gmail.com'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('envoi_temoins_acces')->delete()))->toThrow(QueryException::class);
});

test('un autre espace ne voit pas les accès (RLS)', function () {
    $a = espaceR16();
    $b = espaceR16();
    poserAccesR16($a, 'yahoo', 'a@exemple.test', 'mdp');
    expect(app(DepotAccesTemoins::class)->lister($b))->toBeEmpty();
});

test('poser deux fois le même témoin : la dernière ligne fait foi', function () {
    $ws = espaceR16();
    poserAccesR16($ws, 'gmail', 'a@exemple.test', 'ancien');
    poserAccesR16($ws, 'gmail', 'a@exemple.test', 'nouveau');
    $acces = app(DepotAccesTemoins::class)->lister($ws);
    expect($acces)->toHaveCount(1)->and($acces[0]->motDePasse)->toBe('nouveau');
});

test('aucune route ne pose d’accès', function () {
    foreach (app('router')->getRoutes() as $route) {
        expect($route->uri())->not->toContain('temoins-acces')->and($route->uri())->not->toContain('temoins-releve');
    }
});
