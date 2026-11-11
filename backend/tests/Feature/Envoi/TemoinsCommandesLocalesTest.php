<?php

use App\Envoi\Depot\DepotSocle;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\EmpreinteTemoin;
use App\Envoi\Exceptions\TemoinRefuse;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Sentry\Breadcrumb;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Exception\InvalidOptionException;
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
    return appelerSansAdresseEnArgument('envoi:temoin-ajouter', ['workspace' => $ws, 'fournisseur' => $fournisseur, '--libelle' => $libelle, '--auteur' => 'testeur'], $adresse);
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

test('dette D0 (sens inverse) : si l’écriture de envoi_drapeaux échoue APRÈS le journal, aucune ligne temoins_off ne reste', function () {
    $ws = espaceTemoin();
    DB::unprepared('CREATE OR REPLACE FUNCTION zz_refus_drapeaux() RETURNS trigger AS $f$ BEGIN RAISE EXCEPTION \'refus drapeaux test\'; END; $f$ LANGUAGE plpgsql');
    DB::unprepared('CREATE TRIGGER zz_refus_drapeaux BEFORE INSERT OR UPDATE ON envoi_drapeaux FOR EACH ROW EXECUTE FUNCTION zz_refus_drapeaux()');
    try {
        expect(fn () => app(DepotSocle::class)->eteindreTemoins($ws, 'testeur'))->toThrow(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER zz_refus_drapeaux ON envoi_drapeaux');
    }
    // Sans transaction commune, la ligne de journal écrite avant l'échec resterait.
    expect(journalTemoin($ws, 'temoins_off'))->toHaveCount(0);
    expect(app(DepotSocle::class)->modeTemoins($ws))->toBeTrue();
});

// ── B1 : l'adresse ne passe JAMAIS par la ligne de commande ──────────────────

test('B1 : aucun argument ni option « adresse » dans la signature des commandes témoin', function () {
    foreach (['envoi:temoin-ajouter', 'envoi:temoin-indesirable'] as $nom) {
        $def = Artisan::all()[$nom]->getDefinition();
        expect($def->getArguments())->not->toHaveKey('adresse')->and($def->getOptions())->not->toHaveKey('adresse');
        foreach (array_merge(array_keys($def->getArguments()), array_keys($def->getOptions())) as $n) {
            expect($n)->not->toMatch('/adresse|email|mail|address/i');
        }
        // Passer l'adresse en argument ou option doit être refusé par artisan lui-même.
        expect(fn () => Artisan::call($nom, ['workspace' => (string) Str::uuid(), 'adresse' => 'x@exemple.test']))->toThrow(InvalidArgumentException::class);
        expect(fn () => Artisan::call($nom, ['workspace' => (string) Str::uuid(), '--adresse' => 'x@exemple.test']))->toThrow(InvalidOptionException::class);
    }
});

test('B1 : adresse absente (STDIN vide) = échec propre, message sans adresse, rien d’écrit', function () {
    $ws = espaceTemoin();
    foreach ([['envoi:temoin-ajouter', ['workspace' => $ws, 'fournisseur' => 'gmail']], ['envoi:temoin-indesirable', ['workspace' => $ws]]] as [$cmd, $params]) {
        expect(appelerSansAdresseEnArgument($cmd, $params, null))->toBe(1);
        expect(Artisan::output())->toContain('Adresse absente');
    }
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins')->count()))->toBe(0);
});

test('B1 : quand la commande échoue (workspace invalide, clé absente), ni sortie, ni log, ni exception ne contiennent l’adresse', function () {
    $adresse = 'fuite.secrete@exemple.test';
    Log::spy();
    $ws = espaceTemoin();

    // Workspace invalide.
    foreach (['envoi:temoin-ajouter' => ['workspace' => 'pas-un-uuid', 'fournisseur' => 'gmail'], 'envoi:temoin-indesirable' => ['workspace' => 'pas-un-uuid']] as $cmd => $params) {
        expect(appelerSansAdresseEnArgument($cmd, $params, $adresse))->toBe(1);
        expect(Artisan::output())->not->toContain('fuite')->and(Artisan::output())->not->toContain('exemple.test');
    }
    // Clé d'empreinte absente.
    config(['envoi.cle_empreinte' => '']);
    foreach (['envoi:temoin-ajouter' => ['workspace' => $ws, 'fournisseur' => 'gmail'], 'envoi:temoin-indesirable' => ['workspace' => $ws]] as $cmd => $params) {
        try {
            $code = appelerSansAdresseEnArgument($cmd, $params, $adresse);
            $sortie = Artisan::output();
        } catch (Throwable $e) {
            $code = 1;
            $sortie = $e->getMessage() . $e->getTraceAsString();
        }
        expect($code)->toBe(1);
        expect($sortie)->not->toContain('fuite')->and($sortie)->not->toContain('exemple.test');
    }
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins')->count()))->toBe(0);
    expect(json_encode(DB::table('envoi_journal_commandes')->get()))->not->toContain('fuite');
});

test('B1 : sentry ne garde pas la trace des commandes envoi:temoin-* (liste d’exclusion)', function () {
    $filtre = config('sentry.before_breadcrumb');
    expect($filtre)->toBeArray();
    $cmd = new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'artisan.command', 'Starting Artisan command: envoi:temoin-ajouter', ['input' => 'x']);
    $autre = new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'artisan.command', 'Starting Artisan command: migrate', []);
    expect(call_user_func($filtre, $cmd))->toBeNull()->and(call_user_func($filtre, $autre))->toBe($autre);
});

// ── M2 : libellé ──────────────────────────────────────────────────────────────

test('M2 : un libellé en adresse reformulée ou trop long est refusé', function () {
    $ws = espaceTemoin();
    foreach (['moi at exemple.test', 'moi arobase exemple', 'moi[at]exemple', 'moi(at)exemple', 'moi {at} exemple', 'MOI AT exemple', str_repeat('x', 61)] as $l) {
        expect(ajouterTemoin($ws, 'a@exemple.test', 'gmail', $l))->toBe(1, $l);
    }
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins')->count()))->toBe(0);
    expect(ajouterTemoin($ws, 'a@exemple.test', 'gmail', 'Gmail de test'))->toBe(0);
});

test('m3 : arobase pleine largeur, « chez » et « point » (même collés) sont refusés', function () {
    $ws = espaceTemoin();
    foreach (["moi\u{FF20}exemple.test", 'moi chez exemple', 'moichezexemple', 'moi point test', 'exemplepointtest', 'moi DOT test', 'moi[dot]test'] as $l) {
        expect(ajouterTemoin($ws, 'a@exemple.test', 'gmail', $l))->toBe(1, $l);
    }
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins')->count()))->toBe(0);
});

test('m6 : lister refuse un identifiant qui n’est pas un UUID, avec un code non nul', function () {
    expect(Artisan::call('envoi:temoins-lister', ['workspace' => 'pas-un-uuid']))->toBe(1);
    expect(Artisan::output())->toContain('identifiant invalide');
});

// ── M3 : plafond et un seul témoin par fournisseur ───────────────────────────

test('M3 : un 2e témoin pour un fournisseur déjà couvert est refusé avec un message clair', function () {
    $ws = espaceTemoin();
    expect(ajouterTemoin($ws, 'a@exemple.test', 'gmail'))->toBe(0);
    expect(ajouterTemoin($ws, 'b@exemple.test', 'gmail'))->toBe(1);
    expect(Artisan::output())->toContain('déjà un témoin')->and(Artisan::output())->not->toContain('exemple.test');
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins')->count()))->toBe(1);
    // Le doublon exact reste idempotent.
    expect(ajouterTemoin($ws, 'a@exemple.test', 'gmail'))->toBe(0);
});

test('M3 : jamais plus de 6 témoins par workspace (un par fournisseur), et l’autre espace n’est pas touché', function () {
    $ws = espaceTemoin();
    $autre = espaceTemoin();
    foreach (DepotSocleDb::FOURNISSEURS_TEMOINS as $i => $f) {
        expect(ajouterTemoin($ws, "t{$i}@exemple.test", $f))->toBe(0);
    }
    expect(ajouterTemoin($ws, 'de-trop@exemple.test', 'gmail'))->toBe(1);
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins')->count()))->toBe(6);
    expect(ajouterTemoin($autre, 'a@exemple.test', 'gmail'))->toBe(0);
});

test('M3 : le plafond lève TemoinRefuse::plafondAtteint côté dépôt quand la table est pleine', function () {
    $ws = espaceTemoin();
    foreach (DepotSocleDb::FOURNISSEURS_TEMOINS as $i => $f) {
        app(DepotSocle::class)->ajouterTemoin($ws, "t{$i}@exemple.test", $f, '', 'testeur');
    }
    // Fournisseur déjà couvert ET plafond atteint : le plafond est vérifié en premier.
    expect(fn () => app(DepotSocle::class)->ajouterTemoin($ws, 'x@exemple.test', 'yahoo', '', 'testeur'))->toThrow(TemoinRefuse::class, '6 témoins');
});
