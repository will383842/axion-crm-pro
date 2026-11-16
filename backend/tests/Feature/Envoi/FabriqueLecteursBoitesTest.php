<?php

use App\Envoi\Imap\AccesImapAbsent;
use App\Envoi\Imap\DepotAccesImap;
use App\Envoi\Imap\FabriqueLecteursBoites;
use App\Envoi\Imap\LecteurBoiteWebklex;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FauxProtocoleImap;
use Tests\Support\FauxServeurImap as Faux;
use Tests\TestCase;
use Webklex\PHPIMAP\Config;

/*
 * Lot D1-L2 — fabrique de lecteurs des boîtes (inerte). Aucun réseau : faux protocole injecté. Les cas qui touchent la base
 * sont joués SOUS LE RÔLE APPLICATIF (SET LOCAL ROLE) ET SOUS LE PROPRIÉTAIRE. Données fictives (example.test).
 */
uses(TestCase::class, RefreshDatabase::class);

const FLB_ID = 'releve@societe-a.example.test';
const FLB_MDP = 'motdepasse-temoin-zz';

function flbEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Flb', 'slug' => 'flb-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function flbBoite(string $ws, bool $acces = true): string
{
    $id = (string) Str::uuid();
    DB::table('envoi_boites')->insert(['id' => $id, 'workspace_id' => $ws, 'adresse' => 'b-' . substr($id, 0, 6) . '@societe-a.example.test',
        'hote' => 'smtp.societe-a.example.test', 'port' => 587, 'chiffrement' => 'starttls', 'identifiants_chiffres' => 'chiffre-fictif']);
    if ($acces) {
        (new DepotAccesImap)->poser($ws, $id, 'imap.gmail.com', FLB_ID, FLB_MDP);
    }

    return $id;
}

/** Joue $f sous le mode : « app » = rôle applicatif sous RLS, « proprio » = propriétaire. */
function flbSous(string $mode, string $ws, Closure $f): mixed
{
    if ($mode === 'proprio') {
        return WorkspaceContext::run($ws, $f);
    }
    WorkspaceContext::set($ws);
    DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    try {
        return $f();
    } finally {
        DB::statement('RESET ROLE');
    }
}

test('D1-L2 F1 drapeaux éteints (3 combinaisons) : la fabrique lève avant toute lecture de base et tout déchiffrement', function () {
    $ws = flbEspace();
    $b = flbBoite($ws);
    foreach ([[false, false], [true, false], [false, true]] as [$reel, $releve]) {
        config(['envoi.reel_actif' => $reel, 'envoi.releve_actif' => $releve]);
        Crypt::spy();
        $requetes = 0;
        DB::listen(function () use (&$requetes) {
            $requetes++;
        });
        $fabrique = new FabriqueLecteursBoites(new DepotAccesImap);

        expect(fn () => $fabrique->fabrique($b))->toThrow(RuntimeException::class, 'relève éteinte')
            ->and($requetes)->toBe(0);
        Crypt::shouldNotHaveReceived('decryptString');
    }
});

test('D1-L2 F2 drapeaux allumés et accès posé : un lecteur réel est rendu, sans aucune connexion', function () {
    config(['envoi.reel_actif' => true, 'envoi.releve_actif' => true]);
    Faux::reinitialiser();
    $ws = flbEspace();
    $b = flbBoite($ws);
    $appels = 0;
    $fabrique = new FabriqueLecteursBoites(new DepotAccesImap, function () use (&$appels) {
        $appels++;

        return new FauxProtocoleImap(Config::make(), true, 'ssl');
    });

    $lecteur = WorkspaceContext::run($ws, fn () => $fabrique->fabrique($b));

    expect($lecteur)->toBeInstanceOf(LecteurBoiteWebklex::class)->and($appels)->toBe(0)->and(Faux::commandes())->toBe([]);
    $lecteur->uidValidity($b); // première lecture : seulement là, la connexion est ouverte
    expect($appels)->toBe(1)->and(Faux::commandes())->not->toBe([]);
});

test('D1-L2 F3 accès absent ou retiré : AccesImapAbsent à message fixe', function () {
    config(['envoi.reel_actif' => true, 'envoi.releve_actif' => true]);
    $ws = flbEspace();
    $sans = flbBoite($ws, false);
    $retire = flbBoite($ws);
    (new DepotAccesImap)->retirer($ws, $retire);
    $fabrique = new FabriqueLecteursBoites(new DepotAccesImap);

    foreach ([$sans, $retire] as $b) {
        expect(fn () => WorkspaceContext::run($ws, fn () => $fabrique->fabrique($b)))
            ->toThrow(AccesImapAbsent::class, AccesImapAbsent::MESSAGE);
    }
    expect(fn () => $fabrique->fabrique($sans))->toThrow(RuntimeException::class); // hors contexte d'espace
});

test('D1-L2 F4 l\'accès de l\'Espace B demandé sous le contexte de A est absent (rôle applicatif ET propriétaire)', function () {
    config(['envoi.reel_actif' => true, 'envoi.releve_actif' => true]);
    $a = flbEspace();
    $b = flbEspace();
    $boiteB = flbBoite($b);
    $fabrique = new FabriqueLecteursBoites(new DepotAccesImap);
    foreach (['app', 'proprio'] as $mode) {
        expect(fn () => flbSous($mode, $a, fn () => $fabrique->fabrique($boiteB)))->toThrow(AccesImapAbsent::class);
    }
});
