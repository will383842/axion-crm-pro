<?php

use App\Envoi\Depot\DepotSocle;
use App\Envoi\EmpreinteTemoin;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(fn () => config(['envoi.cle_empreinte' => 'cle-de-test-seulement']));

function espaceTemoin(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Test', 'slug' => 'tem-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function ajouterTemoin(string $ws, string $adresse, string $fournisseur = 'gmail', string $libelle = 'Gmail de test'): int
{
    return Artisan::call('envoi:temoin-ajouter', ['workspace' => $ws, 'adresse' => $adresse, 'fournisseur' => $fournisseur, '--libelle' => $libelle, '--auteur' => 'testeur']);
}

function journalTemoin(string $ws, string $commande): array
{
    return WorkspaceContext::run($ws, fn () => DB::table('envoi_journal_commandes')->where('commande', $commande)->get()->all());
}

test('ajout : le témoin est reconnu, avec son fournisseur, et la sortie n’affiche jamais l’adresse', function () {
    $ws = espaceTemoin();
    expect(ajouterTemoin($ws, 'Alice@Exemple.test'))->toBe(0);
    expect(app(DepotSocle::class)->estTemoin($ws, ' alice@exemple.test '))->toBeTrue();
    expect(Artisan::output())->not->toContain('alice')->and(Artisan::output())->not->toContain('exemple.test');
    $ligne = WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins')->first());
    expect($ligne->fournisseur)->toBe('gmail')->and($ligne->empreinte)->toBe(EmpreinteTemoin::calculer('alice@exemple.test'));
});

test('l’adresse n’est ni dans la sortie, ni dans le journal, ni dans les logs', function () {
    $ws = espaceTemoin();
    Log::spy();
    ajouterTemoin($ws, 'secret.perso@exemple.test');
    $sortie = Artisan::output();
    $journal = json_encode(journalTemoin($ws, 'temoin_ajoute'));
    foreach ([$sortie, $journal] as $texte) {
        expect($texte)->not->toContain('secret.perso')->and($texte)->not->toContain('exemple.test');
    }
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
    // L'empreinte complète n'est pas non plus recopiée : seul un préfixe court sert de repère.
    expect($journal)->not->toContain(EmpreinteTemoin::calculer('secret.perso@exemple.test'));
});

test('une adresse non ASCII est refusée (dette K6a I-3) et rien n’est écrit', function () {
    $ws = espaceTemoin();
    expect(ajouterTemoin($ws, 'zoé@exemple.test'))->toBe(1);
    expect(ajouterTemoin($ws, 'a@faß.exemple.test'))->toBe(1);
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins')->count()))->toBe(0);
});

test('une adresse mal formée est refusée', function () {
    $ws = espaceTemoin();
    expect(ajouterTemoin($ws, 'pas-une-adresse'))->toBe(1);
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins')->count()))->toBe(0);
});

test('un doublon est idempotent : pas d’erreur, une seule ligne', function () {
    $ws = espaceTemoin();
    expect(ajouterTemoin($ws, 'a@exemple.test'))->toBe(0);
    expect(ajouterTemoin($ws, ' A@EXEMPLE.test '))->toBe(0);
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins')->count()))->toBe(1);
});

test('chaque ajout, doublon compris, laisse une trace dans le journal', function () {
    $ws = espaceTemoin();
    ajouterTemoin($ws, 'a@exemple.test');
    ajouterTemoin($ws, 'a@exemple.test');
    $lignes = journalTemoin($ws, 'temoin_ajoute');
    expect($lignes)->toHaveCount(2)->and($lignes[0]->auteur)->toBe('testeur');
    expect(json_decode($lignes[0]->details, true))->toMatchArray(['fournisseur' => 'gmail', 'nouveau' => true]);
    expect(json_decode($lignes[1]->details, true))->toMatchArray(['nouveau' => false]);
});

test('si le journal échoue, le témoin n’est pas ajouté (même transaction)', function () {
    $ws = espaceTemoin();
    DB::unprepared('CREATE OR REPLACE FUNCTION zz_refus_journal() RETURNS trigger AS $f$ BEGIN RAISE EXCEPTION \'refus test\'; END; $f$ LANGUAGE plpgsql');
    DB::unprepared('CREATE TRIGGER zz_refus_journal BEFORE INSERT ON envoi_journal_commandes FOR EACH ROW EXECUTE FUNCTION zz_refus_journal()');
    expect(fn () => app(DepotSocle::class)->ajouterTemoin($ws, 'a@exemple.test', 'gmail', '', 'testeur'))->toThrow(QueryException::class);
    DB::unprepared('DROP TRIGGER zz_refus_journal ON envoi_journal_commandes');
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins')->count()))->toBe(0);
});

test('un fournisseur hors des 6 valeurs est refusé par la commande ET par la base', function () {
    $ws = espaceTemoin();
    expect(ajouterTemoin($ws, 'a@exemple.test', 'protonmail'))->toBe(1);
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins')->count()))->toBe(0);
    expect(fn () => WorkspaceContext::run($ws, fn () => DB::transaction(fn () => DB::table('envoi_temoins')->insert([
        'workspace_id' => $ws, 'empreinte' => str_repeat('a', 64), 'fournisseur' => 'protonmail',
    ]))))->toThrow(QueryException::class);
    foreach (['gmail', 'google_workspace', 'outlook_com', 'microsoft_365', 'yahoo', 'orange'] as $i => $f) {
        expect(ajouterTemoin($ws, "t{$i}@exemple.test", $f))->toBe(0);
    }
});

test('un libellé qui ressemble à une adresse est refusé', function () {
    $ws = espaceTemoin();
    expect(ajouterTemoin($ws, 'a@exemple.test', 'gmail', 'moi@exemple.test'))->toBe(1);
});

test('lister : empreinte abrégée, libellé, fournisseur — jamais l’adresse', function () {
    $ws = espaceTemoin();
    ajouterTemoin($ws, 'a@exemple.test', 'yahoo', 'Yahoo de test');
    expect(Artisan::call('envoi:temoins-lister', ['workspace' => $ws]))->toBe(0);
    $sortie = Artisan::output();
    expect($sortie)->toContain('yahoo')->and($sortie)->toContain('Yahoo de test')
        ->and($sortie)->toContain(substr(EmpreinteTemoin::calculer('a@exemple.test'), 0, 12))
        ->and($sortie)->not->toContain('exemple.test');
});

test('lister : un autre espace ne voit rien (RLS)', function () {
    $ws = espaceTemoin();
    $autre = espaceTemoin();
    ajouterTemoin($ws, 'a@exemple.test');
    expect(app(DepotSocle::class)->listerTemoins($autre))->toBe([]);
    expect(app(DepotSocle::class)->listerTemoins($ws))->toHaveCount(1);
});

test('dette D0 : temoins-off est journalisé DANS la même transaction que l’extinction', function () {
    $ws = espaceTemoin();
    DB::unprepared('CREATE OR REPLACE FUNCTION zz_refus_journal() RETURNS trigger AS $f$ BEGIN RAISE EXCEPTION \'refus test\'; END; $f$ LANGUAGE plpgsql');
    DB::unprepared('CREATE TRIGGER zz_refus_journal BEFORE INSERT ON envoi_journal_commandes FOR EACH ROW EXECUTE FUNCTION zz_refus_journal()');
    expect(fn () => app(DepotSocle::class)->eteindreTemoins($ws, 'testeur'))->toThrow(QueryException::class);
    DB::unprepared('DROP TRIGGER zz_refus_journal ON envoi_journal_commandes');
    expect(app(DepotSocle::class)->modeTemoins($ws))->toBeTrue();
    Artisan::call('envoi:temoins-off', ['workspace' => $ws, '--auteur' => 'testeur']);
    expect(app(DepotSocle::class)->modeTemoins($ws))->toBeFalse()->and(journalTemoin($ws, 'temoins_off'))->toHaveCount(1);
});
