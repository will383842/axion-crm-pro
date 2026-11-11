<?php

use App\Envoi\Desinscription\ArretParEntreprise;
use App\Envoi\Desinscription\ArretParEntrepriseAbsent;
use App\Envoi\Desinscription\RetraitEnvoisEnFile;
use App\Envoi\Desinscription\RetraitEnvoisEnFileAbsent;
use App\Envoi\FeuVert\DepotPreuves;
use App\Envoi\FeuVert\ListeFeuVert;
use App\Envoi\FeuVert\PointFeuVert;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        if (Schema::hasColumn('envoi_temoins', 'fournisseur')) {
            foreach (ListeFeuVert::FOURNISSEURS_TEMOINS as $i => $f) {
                DB::table('envoi_temoins')->insert(['workspace_id' => $ws, 'empreinte' => str_repeat(dechex($i + 1), 64), 'fournisseur' => $f]);
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
    'supervision éteinte' => ['supervision_k15', fn () => config(['envoi.sante.actif' => false])],
]);

test('tout vert : les points automatiques sont faits, le fournisseur dépend de la colonne F03', function () {
    $ws = espaceFeuVert();
    toutVert($ws);
    foreach (app(ListeFeuVert::class)->points($ws) as $p) {
        if ($p->automatique && $p->code !== 'temoins_fournisseurs') {
            expect($p->fait)->toBeTrue($p->code);
        }
    }
    expect(point($ws, 'temoins_fournisseurs')->fait)->toBe(Schema::hasColumn('envoi_temoins', 'fournisseur'));
});

test('les détails et sources ne révèlent aucune valeur secrète', function () {
    $ws = espaceFeuVert();
    toutVert($ws);
    $cle = (string) config('envoi.cle_desinscription');
    foreach (app(ListeFeuVert::class)->points($ws) as $p) {
        expect($p->detail . $p->source)->not->toContain($cle)->not->toContain('exemple.test');
    }
});

test('aucun point manuel ne devient automatique, 18 points § 8 + A11', function () {
    $ws = espaceFeuVert();
    $manuels = array_values(array_filter(app(ListeFeuVert::class)->points($ws), fn ($p) => ! $p->automatique));
    expect($manuels)->toHaveCount(19);
    foreach ($manuels as $p) {
        expect($p->fait)->toBeFalse()->and($p->detail)->toContain('vrai serveur');
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
})->with(['vide' => [''], 'blancs' => ['   '], 'trop longue' => [str_repeat('a', 501)], 'e-mail' => ['voir jean.dupont@exemple.test ok']]);

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
