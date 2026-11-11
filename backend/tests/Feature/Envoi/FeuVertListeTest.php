<?php

use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Desinscription\ArretParEntreprise;
use App\Envoi\Desinscription\ArretParEntrepriseAbsent;
use App\Envoi\Desinscription\RetraitEnvoisEnFile;
use App\Envoi\Desinscription\RetraitEnvoisEnFileAbsent;
use App\Envoi\FeuVert\CatalogueManuels;
use App\Envoi\FeuVert\DepotPreuves;
use App\Envoi\FeuVert\ListeFeuVert;
use App\Envoi\FeuVert\PointFeuVert;
use App\Envoi\JourEnvoi;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('envoi');
function espaceFeuVert(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Test', 'slug' => 'fv-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** Pose tous les verrous automatiques au vert (réglages de test, aucune valeur réelle). */
function toutVert(string $ws): void
{
    config([
        'envoi.cle_desinscription' => str_repeat('a', 40),
        'envoi.desinscription_hote' => 'app',
        'envoi.desinscription_hotes' => ['app' => 'https://app.exemple.test', 'api' => 'https://api.exemple.test'],
        'envoi.releve_actif' => true,
        'envoi.sante.actif' => true,
    ]);
    app()->instance(RetraitEnvoisEnFile::class, Mockery::mock(RetraitEnvoisEnFile::class));
    DB::table('envoi_drapeaux')->updateOrInsert(['workspace_id' => $ws], ['mode_temoins' => true, 'feu_vert_signature' => null]);
    WorkspaceContext::run($ws, function () use ($ws) {
        DB::table('envoi_controles_dns')->insert(['workspace_id' => $ws, 'domaine' => 'a.exemple.test', 'controle_le' => now(), 'pret' => true, 'pause' => false]);
        DB::table('envoi_controles_listes')->insert(['workspace_id' => $ws, 'type' => 'domaine', 'ressource' => 'a.exemple.test', 'statut' => 'non_liste', 'zone' => 'z.test', 'pause' => false]);
        foreach (DepotSocleDb::FOURNISSEURS_TEMOINS as $i => $f) {
            DB::table('envoi_temoins')->insert(['workspace_id' => $ws, 'empreinte' => str_repeat(dechex($i + 1), 64), 'fournisseur' => $f]);
            // F15 : 3 jours verts chez chaque fournisseur (point auto.temoins_3_jours).
            foreach ([0, 1, 2] as $recul) {
                DB::table('envoi_temoins_journees')->insert(['workspace_id' => $ws, 'jour' => JourEnvoi::de()->modify("-{$recul} days")->format('Y-m-d'), 'fournisseur' => $f, 'reception' => 'inbox', 'auth_alignee' => true, 'bouton_vu' => true, 'post_effectif' => true, 'auteur' => 'test']);
            }
        }
    });
}

/** Test seulement : fabrique un état en court-circuitant les déclencheurs (la garde elle-même n'est pas modifiée). */
function sansDeclencheur(Closure $f): void
{
    DB::statement("SET session_replication_role = 'replica'");
    try {
        $f();
    } finally {
        DB::statement("SET session_replication_role = 'origin'");
    }
}

function point(string $ws, string $code): PointFeuVert
{
    foreach (app(ListeFeuVert::class)->points($ws) as $p) {
        if ($p->code === $code) {
            return $p;
        }
    }
    throw new RuntimeException($code);
}

test('chaque verrou faux rend son point non fait', function (string $code, Closure $casse) {
    $ws = espaceFeuVert();
    toutVert($ws);
    $casse($ws);
    expect(point($ws, $code)->fait)->toBeFalse()->and(point($ws, $code)->detail)->not->toBe('');
})->with([
    'envoi réel' => ['auto.reel_actif_faux', fn () => config(['envoi.reel_actif' => true])],
    'mode témoins' => ['auto.mode_temoins_vrai', fn ($ws) => sansDeclencheur(fn () => DB::table('envoi_drapeaux')->where('workspace_id', $ws)->update(['mode_temoins' => false]))],
    'signature présente' => ['auto.signature_absente', fn ($ws) => sansDeclencheur(fn () => DB::table('envoi_drapeaux')->where('workspace_id', $ws)->update(['feu_vert_signature' => 'x']))],
    'arrêt entreprise absent' => ['auto.arret_entreprise', fn () => app()->bind(ArretParEntreprise::class, ArretParEntrepriseAbsent::class)],
    'retrait file absent' => ['auto.retrait_file', fn () => app()->bind(RetraitEnvoisEnFile::class, RetraitEnvoisEnFileAbsent::class)],
    'clé trop courte' => ['auto.cle_desinscription', fn () => config(['envoi.cle_desinscription' => 'courte'])],
    'hôte manquant' => ['auto.hotes_desinscription', fn () => config(['envoi.desinscription_hotes' => ['app' => '', 'api' => '']])],
    'relève éteinte' => ['auto.releve_imap', fn () => config(['envoi.releve_actif' => false])],
    'domaine pas prêt' => ['auto.domaines_prets', fn ($ws) => DB::table('envoi_controles_dns')->where('workspace_id', $ws)->update(['pret' => false])],
    'domaine listé' => ['auto.listes_noires', fn ($ws) => sansDeclencheur(fn () => DB::table('envoi_controles_listes')->where('workspace_id', $ws)->update(['statut' => 'liste']))],
    'clé de 31 caractères' => ['auto.cle_desinscription', fn () => config(['envoi.cle_desinscription' => str_repeat('a', 31)])],
    'domaine en pause' => ['auto.domaines_prets', fn ($ws) => DB::table('envoi_controles_dns')->where('workspace_id', $ws)->update(['pause' => true])],
    'liste noire en pause' => ['auto.listes_noires', fn ($ws) => sansDeclencheur(fn () => DB::table('envoi_controles_listes')->where('workspace_id', $ws)->update(['pause' => true]))],
    'drapeaux absents (mode témoins)' => ['auto.mode_temoins_vrai', fn ($ws) => sansDeclencheur(fn () => DB::table('envoi_drapeaux')->where('workspace_id', $ws)->delete())],
    'drapeaux absents (signature)' => ['auto.signature_absente', fn ($ws) => sansDeclencheur(fn () => DB::table('envoi_drapeaux')->where('workspace_id', $ws)->delete())],
    'un fournisseur sans témoin' => ['auto.temoins_fournisseurs', fn ($ws) => sansDeclencheur(fn () => DB::table('envoi_temoins')->where('workspace_id', $ws)->where('fournisseur', 'orange')->delete())],
    'clé de 29 caractères + 3 espaces de bord' => ['auto.cle_desinscription', fn () => config(['envoi.cle_desinscription' => '  ' . str_repeat('a', 29) . ' '])],
    'hôte inconnu' => ['auto.hotes_desinscription', fn () => config(['envoi.desinscription_hote' => 'apii'])],
    'adresse égale à un espace' => ['auto.hotes_desinscription', fn () => config(['envoi.desinscription_hotes' => ['app' => ' ', 'api' => 'https://api.exemple.test']])],
    'adresse avec chemin' => ['auto.hotes_desinscription', fn () => config(['envoi.desinscription_hotes' => ['app' => 'https://app.exemple.test/chemin', 'api' => 'https://api.exemple.test']])],
    'une seule adresse vide (api)' => ['auto.hotes_desinscription', fn () => config(['envoi.desinscription_hotes' => ['app' => 'https://app.exemple.test', 'api' => '']])],
    'une seule adresse vide (app)' => ['auto.hotes_desinscription', fn () => config(['envoi.desinscription_hotes' => ['app' => '', 'api' => 'https://api.exemple.test']])],
    'http en production' => ['auto.hotes_desinscription', function () {
        app()->detectEnvironment(fn () => 'production');
        config(['envoi.desinscription_hotes' => ['app' => 'http://app.exemple.test', 'api' => 'https://api.exemple.test']]);
    }],
    'liste noire non fiable' => ['auto.listes_noires', fn ($ws) => sansDeclencheur(fn () => DB::table('envoi_controles_listes')->where('workspace_id', $ws)->update(['statut' => 'non_fiable']))],
    'supervision éteinte' => ['auto.supervision_k15', fn () => config(['envoi.sante.actif' => false])],
]);

test('tout vert : tous les points automatiques sont faits, clé de 32 caractères acceptée', function () {
    $ws = espaceFeuVert();
    toutVert($ws);
    config(['envoi.cle_desinscription' => str_repeat('a', 32)]);
    foreach (app(ListeFeuVert::class)->points($ws) as $p) {
        if ($p->automatique) {
            expect($p->fait)->toBeTrue($p->code);
            expect($p->manuel)->toBeFalse()->and($p->bloquant)->toBeFalse();
        }
    }
});

test('les détails et sources ne révèlent aucune valeur secrète', function () {
    $ws = espaceFeuVert();
    toutVert($ws);
    $cle = (string) config('envoi.cle_desinscription');
    foreach (app(ListeFeuVert::class)->points($ws) as $p) {
        expect($p->detail . $p->source)->not->toContain($cle)->not->toContain('exemple.test');
    }
});

test('catalogue versionné unique : codes uniques, préfixe obligatoire, 64 caractères au plus, 18 manuels', function () {
    $codes = CatalogueManuels::codes();
    expect(CatalogueManuels::VERSION)->toBeInt()->and(array_unique($codes))->toHaveCount(count($codes));
    foreach ($codes as $c) {
        expect(mb_strlen($c))->toBeLessThanOrEqual(64)->and(str_starts_with($c, 'auto.') || str_starts_with($c, 'manuel.'))->toBeTrue($c);
        expect(CatalogueManuels::GROUPES)->toContain(CatalogueManuels::POINTS[$c]['groupe']);
    }
    $manuels = CatalogueManuels::codesManuels();
    expect($manuels)->toHaveCount(18)->and($manuels[0])->toBe('manuel.port25')->and($manuels)->toContain('manuel.inscriptions')->not->toContain('m8_13_inscriptions');
    expect(count($codes) - 18)->toBe(13);
    foreach ($codes as $c) {
        if (str_starts_with($c, 'auto.')) {
            expect(fn () => app(DepotPreuves::class)->ajouter(espaceFeuVert(), $c, 'Preuve de test, réf. exemple-01', 'auteur'))->toThrow(InvalidArgumentException::class);
        }
    }
});

test('chaque point expose groupe, nature, état et source ; un manuel qui exige le vrai serveur attend K17', function () {
    $ws = espaceFeuVert();
    toutVert($ws);
    app(DepotPreuves::class)->ajouter($ws, 'manuel.port25', 'Preuve de test, réf. exemple-01', 'auteur');
    app(DepotPreuves::class)->ajouter($ws, 'manuel.capacite_reponses', 'Preuve de test, réf. exemple-01', 'auteur');
    $liste = app(ListeFeuVert::class)->points($ws);
    expect($liste)->toHaveCount(31);
    foreach ($liste as $p) {
        expect(CatalogueManuels::GROUPES)->toContain($p->groupe);
        expect($p->nature)->toBe($p->automatique ? 'auto' : 'manuel')->and(str_starts_with($p->code, $p->nature . '.'))->toBeTrue()->and($p->source)->not->toBe('');
        expect($p->etat)->toBeIn(['fait', 'a_faire', 'attend_serveur_reel']);
        if ($p->automatique) {
            expect($p->etat)->toBe($p->fait ? 'fait' : 'a_faire');
        }
    }
    expect(point($ws, 'manuel.port25'))->fait->toBeTrue()->etat->toBe('attend_serveur_reel')->source->toBe('26§8.1');
    expect(point($ws, 'manuel.ptr_fcrdns'))->fait->toBeFalse()->etat->toBe('attend_serveur_reel');
    expect(point($ws, 'manuel.capacite_reponses'))->fait->toBeTrue()->etat->toBe('fait');
    expect(point($ws, 'manuel.homme_mort')->libelle)->not->toBe('')->and(point($ws, 'manuel.inscriptions')->libelle)->toContain('Signal Spam');
});

test('une ligne AUTOMATIQUE insérée directement (fait = true) ne rend jamais le point fait', function () {
    $ws = espaceFeuVert();
    toutVert($ws);
    config(['envoi.reel_actif' => true]);
    WorkspaceContext::run($ws, fn () => DB::table('envoi_feu_vert_preuves')->insert(['workspace_id' => $ws, 'code' => 'auto.reel_actif_faux', 'preuve' => 'forcé', 'auteur' => 'x', 'fait' => true]));
    expect(point($ws, 'auto.reel_actif_faux')->fait)->toBeFalse();
});

test('par défaut (réglages non posés), l’envoi réel est éteint et le point est fait', function () {
    $ws = espaceFeuVert();
    expect(config('envoi.reel_actif'))->toBeFalse()->and(point($ws, 'auto.reel_actif_faux')->fait)->toBeTrue();
});

test('un point automatique n’accepte jamais de preuve', function () {
    $ws = espaceFeuVert();
    expect(fn () => app(DepotPreuves::class)->ajouter($ws, 'auto.reel_actif_faux', 'Preuve de test, réf. exemple-01', 'auteur'))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(DepotPreuves::class)->ajouter($ws, 'code_inconnu', 'Preuve de test, réf. exemple-01', 'auteur'))->toThrow(InvalidArgumentException::class);
});

test('preuve manuelle : acceptée, rend le point fait, reste manuel', function () {
    $ws = espaceFeuVert();
    $code = ListeFeuVert::PREMIER;
    app(DepotPreuves::class)->ajouter($ws, $code, str_repeat('a', 500), 'responsable');
    $p = point($ws, $code);
    expect($p->fait)->toBeTrue()->and($p->automatique)->toBeFalse()->and($p->preuve)->toBe(str_repeat('a', 500));
});

test('preuve refusée : vide, > 500 caractères, adresse e-mail', function (string $texte) {
    $ws = espaceFeuVert();
    expect(fn () => app(DepotPreuves::class)->ajouter($ws, ListeFeuVert::PREMIER, $texte, 'auteur'))->toThrow(InvalidArgumentException::class);
})->with(['vide' => [''], 'blancs' => ['   '], 'trop longue' => [str_repeat('a', 501)], 'e-mail' => ['voir jean.dupont@exemple.test ok'], 'arobase pleine largeur' => ["voir jean.dupont\u{FF20}exemple.test"], 'caractère de contrôle au milieu' => ["avant\0après"], 'espace insécable seule' => ["\u{00A0}"], 'séparateurs Unicode seuls' => ["\u{2003}\u{202F}\u{3000}"]]);

test('auteur refusé : adresse e-mail, espace insécable seule, trop long', function (string $auteur) {
    $ws = espaceFeuVert();
    expect(fn () => app(DepotPreuves::class)->ajouter($ws, ListeFeuVert::PREMIER, 'Preuve de test, réf. exemple-01', $auteur))->toThrow(InvalidArgumentException::class);
})->with(['e-mail' => ['jean@exemple.test'], 'arobase pleine largeur' => ["jean\u{FF20}exemple.test"], 'contrôle au milieu' => ["jean\0dupont"], 'insécable' => ["\u{00A0}"], 'trop long' => [str_repeat('a', 101)]]);

test('auteur de 100 caractères accepté', function () {
    $ws = espaceFeuVert();
    app(DepotPreuves::class)->ajouter($ws, ListeFeuVert::PREMIER, 'Preuve de test, réf. exemple-01', str_repeat('a', 100));
    expect(point($ws, ListeFeuVert::PREMIER)->fait)->toBeTrue();
});

test('cycle fait / défait / refait : la dernière ligne par code fait foi, rien n’est effacé', function () {
    $ws = espaceFeuVert();
    $code = ListeFeuVert::PREMIER;
    $d = app(DepotPreuves::class);
    $d->ajouter($ws, $code, 'Première preuve de test', 'responsable');
    expect(point($ws, $code)->fait)->toBeTrue();
    expect(point($ws, $code)->detail)->toContain('revalider avec le vrai serveur');
    $d->defaire($ws, $code, 'Première preuve périmée', 'responsable');
    $p = point($ws, $code);
    expect($p->fait)->toBeFalse()->and($p->bloquant)->toBeTrue()->and($p->preuve)->toBeNull();
    $d->ajouter($ws, $code, 'Seconde preuve de test', 'responsable');
    expect(point($ws, $code)->fait)->toBeTrue()->and(point($ws, $code)->preuve)->toBe('Seconde preuve de test');
    expect(DB::table('envoi_feu_vert_preuves')->where('workspace_id', $ws)->where('code', $code)->count())->toBe(3);
});

test('défaire un point automatique ou inconnu est refusé', function () {
    $ws = espaceFeuVert();
    expect(fn () => app(DepotPreuves::class)->defaire($ws, 'auto.reel_actif_faux', 'Motif de test suffisant', 'auteur'))->toThrow(InvalidArgumentException::class);
});

test('défaire un point qui n’a jamais été fait est refusé', function () {
    $ws = espaceFeuVert();
    expect(fn () => app(DepotPreuves::class)->defaire($ws, ListeFeuVert::PREMIER, 'Motif de test suffisant', 'auteur'))->toThrow(InvalidArgumentException::class);
    expect(DB::table('envoi_feu_vert_preuves')->where('workspace_id', $ws)->count())->toBe(0);
});

test('envoi_drapeaux absent : les points qui en dépendent restent non faits', function () {
    $ws = espaceFeuVert();
    toutVert($ws);
    sansDeclencheur(fn () => DB::table('envoi_drapeaux')->where('workspace_id', $ws)->delete());
    expect(point($ws, 'auto.mode_temoins_vrai')->fait)->toBeFalse()->and(point($ws, 'auto.signature_absente')->fait)->toBeFalse();
});

test('table de preuves : UPDATE, DELETE et TRUNCATE refusés par le déclencheur', function () {
    $ws = espaceFeuVert();
    app(DepotPreuves::class)->ajouter($ws, ListeFeuVert::PREMIER, 'Preuve de test, réf. exemple-01', 'auteur');
    WorkspaceContext::run($ws, function () {
        expect(fn () => DB::transaction(fn () => DB::table('envoi_feu_vert_preuves')->update(['auteur' => 'z'])))->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('envoi_feu_vert_preuves')->delete()))->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::statement('TRUNCATE TABLE envoi_feu_vert_preuves')))->toThrow(QueryException::class);
    });
});

test('isolement entre espaces', function () {
    $a = espaceFeuVert();
    $b = espaceFeuVert();
    $code = ListeFeuVert::PREMIER;
    app(DepotPreuves::class)->ajouter($a, $code, 'Preuve du premier espace', 'auteur');
    expect(point($a, $code)->fait)->toBeTrue()->and(point($b, $code)->fait)->toBeFalse();
    expect(app(DepotPreuves::class)->dernieres($b))->toBe([])->and(app(DepotPreuves::class)->dernieres($a))->toHaveCount(1);
});

test('la liste ne signe jamais et n’écrit jamais envoi_drapeaux', function () {
    $ws = espaceFeuVert();
    toutVert($ws);
    $avant = DB::table('envoi_drapeaux')->where('workspace_id', $ws)->first();
    app(ListeFeuVert::class)->points($ws);
    app(DepotPreuves::class)->ajouter($ws, ListeFeuVert::PREMIER, 'Preuve de test, réf. exemple-01', 'auteur');
    expect(DB::table('envoi_drapeaux')->where('workspace_id', $ws)->first())->toEqual($avant);
    expect(config('envoi.reel_actif'))->toBeFalse();
});
