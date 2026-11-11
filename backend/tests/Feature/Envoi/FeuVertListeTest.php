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
        'envoi.reel_actif' => false,
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
    'envoi réel' => ['reel_actif_faux', fn () => config(['envoi.reel_actif' => true])],
    'mode témoins' => ['mode_temoins_vrai', fn ($ws) => sansDeclencheur(fn () => DB::table('envoi_drapeaux')->where('workspace_id', $ws)->update(['mode_temoins' => false]))],
    'signature présente' => ['signature_absente', fn ($ws) => sansDeclencheur(fn () => DB::table('envoi_drapeaux')->where('workspace_id', $ws)->update(['feu_vert_signature' => 'x']))],
    'arrêt entreprise absent' => ['arret_entreprise', fn () => app()->bind(ArretParEntreprise::class, ArretParEntrepriseAbsent::class)],
    'retrait file absent' => ['retrait_file', fn () => app()->bind(RetraitEnvoisEnFile::class, RetraitEnvoisEnFileAbsent::class)],
    'clé trop courte' => ['cle_desinscription', fn () => config(['envoi.cle_desinscription' => 'courte'])],
    'hôte manquant' => ['hotes_desinscription', fn () => config(['envoi.desinscription_hotes' => ['app' => '', 'api' => '']])],
    'relève éteinte' => ['releve_imap', fn () => config(['envoi.releve_actif' => false])],
    'domaine pas prêt' => ['domaines_prets', fn ($ws) => DB::table('envoi_controles_dns')->where('workspace_id', $ws)->update(['pret' => false])],
    'domaine listé' => ['listes_noires', fn ($ws) => sansDeclencheur(fn () => DB::table('envoi_controles_listes')->where('workspace_id', $ws)->update(['statut' => 'liste']))],
    'clé de 31 caractères' => ['cle_desinscription', fn () => config(['envoi.cle_desinscription' => str_repeat('a', 31)])],
    'domaine en pause' => ['domaines_prets', fn ($ws) => DB::table('envoi_controles_dns')->where('workspace_id', $ws)->update(['pause' => true])],
    'liste noire en pause' => ['listes_noires', fn ($ws) => sansDeclencheur(fn () => DB::table('envoi_controles_listes')->where('workspace_id', $ws)->update(['pause' => true]))],
    'drapeaux absents (mode témoins)' => ['mode_temoins_vrai', fn ($ws) => sansDeclencheur(fn () => DB::table('envoi_drapeaux')->where('workspace_id', $ws)->delete())],
    'drapeaux absents (signature)' => ['signature_absente', fn ($ws) => sansDeclencheur(fn () => DB::table('envoi_drapeaux')->where('workspace_id', $ws)->delete())],
    'un fournisseur sans témoin' => ['temoins_fournisseurs', fn ($ws) => sansDeclencheur(fn () => DB::table('envoi_temoins')->where('workspace_id', $ws)->where('fournisseur', 'orange')->delete())],
    'supervision éteinte' => ['supervision_k15', fn () => config(['envoi.sante.actif' => false])],
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

test('catalogue versionné : 18 codes uniques de 64 caractères au plus, ceux de la spécification 26 § 8', function () {
    $codes = CatalogueManuels::codes();
    expect(CatalogueManuels::VERSION)->toBeInt()->and($codes)->toHaveCount(18)->and(array_unique($codes))->toHaveCount(18);
    foreach ($codes as $c) {
        expect(mb_strlen($c))->toBeLessThanOrEqual(64);
    }
    expect($codes[0])->toBe('m8_01_port25')->and($codes[17])->toBe('m8_18_homme_mort')->and($codes)->not->toContain('a11_inscriptions');
});

test('aucun point manuel ne devient automatique : 18 points, bloquants, « vrai serveur »', function () {
    $ws = espaceFeuVert();
    $manuels = array_values(array_filter(app(ListeFeuVert::class)->points($ws), fn ($p) => ! $p->automatique));
    expect($manuels)->toHaveCount(18);
    foreach ($manuels as $p) {
        expect($p->fait)->toBeFalse()->and($p->manuel)->toBeTrue()->and($p->bloquant)->toBeTrue()->and($p->detail)->toContain('vrai serveur');
    }
});

test('un point automatique n’accepte jamais de preuve', function () {
    $ws = espaceFeuVert();
    expect(fn () => app(DepotPreuves::class)->ajouter($ws, 'reel_actif_faux', 'preuve', 'auteur'))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(DepotPreuves::class)->ajouter($ws, 'code_inconnu', 'preuve', 'auteur'))->toThrow(InvalidArgumentException::class);
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
})->with(['vide' => [''], 'blancs' => ['   '], 'trop longue' => [str_repeat('a', 501)], 'e-mail' => ['voir jean.dupont@exemple.test ok'], 'espace insécable seule' => ["\u{00A0}"], 'séparateurs Unicode seuls' => ["\u{2003}\u{202F}\u{3000}"]]);

test('auteur refusé : adresse e-mail, espace insécable seule, trop long', function (string $auteur) {
    $ws = espaceFeuVert();
    expect(fn () => app(DepotPreuves::class)->ajouter($ws, ListeFeuVert::PREMIER, 'ok', $auteur))->toThrow(InvalidArgumentException::class);
})->with(['e-mail' => ['jean@exemple.test'], 'insécable' => ["\u{00A0}"], 'trop long' => [str_repeat('a', 101)]]);

test('cycle fait / défait / refait : la dernière ligne par code fait foi, rien n’est effacé', function () {
    $ws = espaceFeuVert();
    $code = ListeFeuVert::PREMIER;
    $d = app(DepotPreuves::class);
    $d->ajouter($ws, $code, 'preuve 1', 'responsable');
    expect(point($ws, $code)->fait)->toBeTrue();
    expect(point($ws, $code)->detail)->toContain('revalider avec le vrai serveur');
    $d->defaire($ws, $code, 'preuve périmée', 'responsable');
    $p = point($ws, $code);
    expect($p->fait)->toBeFalse()->and($p->bloquant)->toBeTrue()->and($p->preuve)->toBeNull();
    $d->ajouter($ws, $code, 'preuve 2', 'responsable');
    expect(point($ws, $code)->fait)->toBeTrue()->and(point($ws, $code)->preuve)->toBe('preuve 2');
    expect(DB::table('envoi_feu_vert_preuves')->where('workspace_id', $ws)->where('code', $code)->count())->toBe(3);
});

test('défaire un point automatique ou inconnu est refusé', function () {
    $ws = espaceFeuVert();
    expect(fn () => app(DepotPreuves::class)->defaire($ws, 'reel_actif_faux', 'x', 'auteur'))->toThrow(InvalidArgumentException::class);
});

test('envoi_drapeaux absent : les points qui en dépendent restent non faits', function () {
    $ws = espaceFeuVert();
    toutVert($ws);
    sansDeclencheur(fn () => DB::table('envoi_drapeaux')->where('workspace_id', $ws)->delete());
    expect(point($ws, 'mode_temoins_vrai')->fait)->toBeFalse()->and(point($ws, 'signature_absente')->fait)->toBeFalse();
});

test('table de preuves : UPDATE et DELETE refusés par le déclencheur', function () {
    $ws = espaceFeuVert();
    app(DepotPreuves::class)->ajouter($ws, ListeFeuVert::PREMIER, 'ok', 'auteur');
    WorkspaceContext::run($ws, function () {
        expect(fn () => DB::transaction(fn () => DB::table('envoi_feu_vert_preuves')->update(['auteur' => 'z'])))->toThrow(QueryException::class);
        expect(fn () => DB::transaction(fn () => DB::table('envoi_feu_vert_preuves')->delete()))->toThrow(QueryException::class);
    });
});

test('isolement entre espaces', function () {
    $a = espaceFeuVert();
    $b = espaceFeuVert();
    $code = ListeFeuVert::PREMIER;
    app(DepotPreuves::class)->ajouter($a, $code, 'preuve de A', 'auteur');
    expect(point($a, $code)->fait)->toBeTrue()->and(point($b, $code)->fait)->toBeFalse();
    expect(app(DepotPreuves::class)->dernieres($b))->toBe([])->and(app(DepotPreuves::class)->dernieres($a))->toHaveCount(1);
});

test('la liste ne signe jamais et n’écrit jamais envoi_drapeaux', function () {
    $ws = espaceFeuVert();
    toutVert($ws);
    $avant = DB::table('envoi_drapeaux')->where('workspace_id', $ws)->first();
    app(ListeFeuVert::class)->points($ws);
    app(DepotPreuves::class)->ajouter($ws, ListeFeuVert::PREMIER, 'ok', 'auteur');
    expect(DB::table('envoi_drapeaux')->where('workspace_id', $ws)->first())->toEqual($avant);
    expect(config('envoi.reel_actif'))->toBeFalse();
});
