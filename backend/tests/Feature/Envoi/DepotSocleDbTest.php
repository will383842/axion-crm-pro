<?php

use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\EmpreinteTemoin;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(fn () => config(['envoi.cle_empreinte' => 'cle-de-test-seulement']));

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
        expect(fn () => DB::transaction(fn () => DB::table('envoi_journal_commandes')->update(['auteur' => 'z'])))->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('envoi_journal_commandes')->delete()))->toThrow(QueryException::class);
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
        DB::table('envoi_temoins')->insert(['workspace_id' => $ws, 'empreinte' => EmpreinteTemoin::calculer('A@example.test'), 'libelle' => 'Gmail perso']);
        DB::table('envoi_drapeaux')->insert(['workspace_id' => $ws, 'tout_arreter' => true]);
    });
    expect($depot->estTemoin($ws, ' a@example.test '))->toBeTrue();
    expect($depot->toutArreter($ws))->toBeTrue();
    $j = new DateTimeImmutable('2026-11-10');
    $depot->noterInjection($ws, $j, 'cle-1');
    $depot->noterInjection($ws, $j, 'cle-1'); // même clé : pas recomptée
    expect($depot->injectesLe($ws, $j))->toBe(1)->and($depot->injectesLe($ws, $j->modify('+1 day')))->toBe(0);
    expect($depot->dejaInjecte($ws, 'cle-1'))->toBeTrue()->and($depot->dejaInjecte($ws, 'autre'))->toBeFalse();
    expect($depot->injectesLeGlobal($j))->toBeGreaterThanOrEqual(1);
});

test('conteneur : la passerelle liée refuse tant que ENVOI_REEL_ACTIF est éteint', function () {
    $ws = espaceEnvoi();
    $m = new MessageAEnvoyer($ws, 'a@example.test', 'f@example.test', 'S', 'C', 'k');
    // Destinataire TÉMOIN : le refus vient bien du drapeau « envoi réel », pas d'une autre porte.
    WorkspaceContext::run($ws, fn () => DB::table('envoi_temoins')->insert(['workspace_id' => $ws, 'empreinte' => EmpreinteTemoin::calculer('a@example.test'), 'libelle' => 'test']));
    expect(config('envoi.reel_actif'))->toBeFalse();
    expect(fn () => WorkspaceContext::run($ws, fn () => app(ServeurEnvoi::class)->injecter($m)))->toThrow(EnvoiRefuse::class, 'Envoi réel inactif');
});


test('empreinte : HMAC-SHA256 de l’adresse normalisée, jamais l’adresse en clair', function () {
    $e = EmpreinteTemoin::calculer(' Perso@Example.TEST ');
    expect($e)->toBe(hash_hmac('sha256', 'perso@example.test', 'cle-de-test-seulement'))->and($e)->toMatch('/^[0-9a-f]{64}$/');
    expect(EmpreinteTemoin::calculer('perso@example.test', 'autre-cle'))->not->toBe($e);
    expect(Schema::hasColumn('envoi_temoins', 'adresse'))->toBeFalse();
    expect(Schema::hasColumn('envoi_temoins', 'empreinte'))->toBeTrue();
});

test('empreinte : sans clé d’application, refus (fermé)', function () {
    config(['envoi.cle_empreinte' => '']);
    expect(fn () => EmpreinteTemoin::calculer('a@example.test'))->toThrow(EnvoiRefuse::class, 'Clé');
});

test('extinction : si le journal échoue, le mode témoins reste allumé (une seule transaction)', function () {
    $ws = espaceEnvoi();
    $depot = app(DepotSocle::class);
    // Panne simulée du journal : un trigger (posé puis retiré par la connexion propriétaire,
    // auto-commit) refuse l'insertion de cet auteur précis, et de lui seul.
    $owner = DB::connection('pgsql_owner');
    $owner->unprepared("
        CREATE OR REPLACE FUNCTION envoi_test_panne_journal() RETURNS trigger AS \$f\$
        BEGIN RAISE EXCEPTION 'panne simulée du journal'; END; \$f\$ LANGUAGE plpgsql;
        CREATE TRIGGER envoi_test_panne_journal BEFORE INSERT ON envoi_journal_commandes
            FOR EACH ROW WHEN (NEW.auteur = 'echec-test-journal') EXECUTE FUNCTION envoi_test_panne_journal();
    ");
    try {
        expect(fn () => $depot->eteindreTemoins($ws, 'echec-test-journal'))->toThrow(QueryException::class, 'panne simulée');
    } finally {
        $owner->unprepared('DROP TRIGGER IF EXISTS envoi_test_panne_journal ON envoi_journal_commandes; DROP FUNCTION IF EXISTS envoi_test_panne_journal();');
    }
    expect($depot->modeTemoins($ws))->toBeTrue();
    $lignes = WorkspaceContext::run($ws, fn () => DB::table('envoi_drapeaux')->where('workspace_id', $ws)->count());
    expect($lignes)->toBe(0);
});

test('garde SQL : UPDATE direct true->false refusé, feu_vert_* refusé, INSERT false refusé', function () {
    $ws = espaceEnvoi();
    WorkspaceContext::run($ws, function () use ($ws) {
        DB::table('envoi_drapeaux')->insert(['workspace_id' => $ws]);
        expect(fn () => DB::transaction(fn () => DB::table('envoi_drapeaux')->update(['mode_temoins' => false])))->toThrow(QueryException::class, 'envoi:temoins-off');
        expect(fn () => DB::transaction(fn () => DB::table('envoi_drapeaux')->update(['feu_vert_date' => '2026-11-10'])))->toThrow(QueryException::class, 'feu_vert');
        expect(fn () => DB::transaction(fn () => DB::table('envoi_drapeaux')->update(['feu_vert_signature' => 'sig'])))->toThrow(QueryException::class, 'feu_vert');
        // allumer / armer « tout arrêter » reste permis
        DB::table('envoi_drapeaux')->update(['tout_arreter' => true, 'mode_temoins' => true]);
    });
    expect(app(DepotSocle::class)->modeTemoins($ws))->toBeTrue();
    $ws2 = espaceEnvoi();
    WorkspaceContext::run($ws2, function () use ($ws2) {
        expect(fn () => DB::transaction(fn () => DB::table('envoi_drapeaux')->insert(['workspace_id' => $ws2, 'mode_temoins' => false])))->toThrow(QueryException::class);
    });
});

test('garde SQL : la commande artisan passe et journalise, y compris sans ligne préexistante', function () {
    $ws = espaceEnvoi();
    WorkspaceContext::run($ws, fn () => DB::table('envoi_drapeaux')->insert(['workspace_id' => $ws]));
    Artisan::call('envoi:temoins-off', ['workspace' => $ws, '--auteur' => 'will']);
    expect(app(DepotSocle::class)->modeTemoins($ws))->toBeFalse();
    // l'autorisation est locale à la transaction : un UPDATE ultérieur de feu vert reste refusé
    WorkspaceContext::run($ws, function () {
        expect(fn () => DB::transaction(fn () => DB::table('envoi_drapeaux')->update(['feu_vert_date' => '2026-11-10'])))->toThrow(QueryException::class);
    });
    $ws2 = espaceEnvoi();
    Artisan::call('envoi:temoins-off', ['workspace' => $ws2, '--auteur' => 'will']);
    expect(app(DepotSocle::class)->modeTemoins($ws2))->toBeFalse();
});

test('journal : TRUNCATE refusé (trigger) et le rôle applicatif n’a ni UPDATE, DELETE ni TRUNCATE', function () {
    $ws = espaceEnvoi();
    app(DepotSocle::class)->journaliser($ws, 'x', 'test');
    expect(fn () => DB::transaction(fn () => DB::statement('TRUNCATE envoi_journal_commandes')))->toThrow(QueryException::class, 'ajout seulement');
    $role = (string) config('database.connections.pgsql_app.username');
    foreach (['UPDATE', 'DELETE', 'TRUNCATE'] as $privilege) {
        $a = DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS a', [$role, 'envoi_journal_commandes', $privilege]);
        expect($a->a)->toBeFalse("{$role} ne doit pas avoir {$privilege}");
    }
    expect(WorkspaceContext::run($ws, fn () => DB::table('envoi_journal_commandes')->count()))->toBeGreaterThanOrEqual(1);
});

test('commande envoi:tout-arreter : arme le drapeau en base et journalise', function () {
    $ws = espaceEnvoi();
    $depot = app(DepotSocle::class);
    expect($depot->toutArreter($ws))->toBeFalse();
    Artisan::call('envoi:tout-arreter', ['workspace' => $ws, '--auteur' => 'will']);
    expect($depot->toutArreter($ws))->toBeTrue()->and($depot->modeTemoins($ws))->toBeTrue();
    $n = WorkspaceContext::run($ws, fn () => DB::table('envoi_journal_commandes')->where('commande', 'tout_arreter')->where('auteur', 'will')->count());
    expect($n)->toBe(1);
});
