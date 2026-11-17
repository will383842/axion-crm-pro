<?php

/**
 * X5-C — fraîcheur de la batterie : le point `auto.batterie_fraiche` n'est fait que si la plus ancienne des
 * « dernières lignes » (DNS par domaine, listes par ressource) a moins de 15 minutes, horloge de la BASE.
 *
 * Les lignes sont écrites (et nettoyées) par le propriétaire, puis lues par la connexion testée : `pgsql` (superutilisateur,
 * RLS contournée : prouve que le filtre `workspace_id` explicite tient seul) ET `pgsql_app` (RLS armée, contexte d'espace).
 * Adresses en `*.example.test` (dépôt PUBLIC) ; aucun appel réseau.
 */

use App\Envoi\FeuVert\CatalogueManuels;
use App\Envoi\FeuVert\DecisionFeuVert;
use App\Envoi\FeuVert\ListeFeuVert;
use App\Envoi\FeuVert\PointFeuVert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('envoi');

const FBF_CODE = 'auto.batterie_fraiche';

afterEach(function () {
    foreach (['pgsql_app', 'pgsql_owner'] as $c) {
        if (array_key_exists($c, DB::getConnections())) {
            DB::connection($c)->disconnect();
        }
    }
});

/** Exécute $f avec $nom pour connexion par défaut, puis la restaure. */
function fbfSur(string $nom, Closure $f): mixed
{
    $avant = DB::getDefaultConnection();
    DB::setDefaultConnection($nom);
    try {
        return $f();
    } finally {
        DB::setDefaultConnection($avant);
    }
}

/**
 * Pose une ligne DNS et une ligne de liste pour $ws, âgées de $dnsMin et $listesMin minutes (null = aucune ligne).
 * L'âge est calculé par la BASE (`now() - interval`), jamais par PHP.
 */
function fbfPoser(string $ws, ?int $dnsMin, ?int $listesMin, string $domaine = 'a.example.test', string $ecrivain = 'pgsql_owner'): void
{
    fbfSur($ecrivain, function () use ($ws, $dnsMin, $listesMin, $domaine): void {
        // La table DNS a une clé étrangère : l'espace est posé (committé) par le propriétaire.
        DB::statement("INSERT INTO workspaces (id, name, slug, settings, created_at, updated_at) VALUES (?, 'Test', ?, '{}', now(), now()) ON CONFLICT (id) DO NOTHING", [$ws, 'fbf-' . substr($ws, 0, 8)]);
        if ($dnsMin !== null) {
            DB::statement('INSERT INTO envoi_controles_dns (workspace_id, domaine, controle_le, pret, pause) VALUES (?, ?, now() - make_interval(mins => ?), true, false)', [$ws, $domaine, $dnsMin]);
        }
        if ($listesMin !== null) {
            DB::statement("INSERT INTO envoi_controles_listes (workspace_id, type, ressource, statut, zone, pause, controle_le) VALUES (?, 'domaine', ?, 'non_liste', 'z.example.test', false, now() - make_interval(mins => ?))", [$ws, $domaine, $listesMin]);
        }
    });
}

/** Nettoie les lignes de TEST (tables en ajout seul : déclencheurs coupés le temps du nettoyage, base de test). */
function fbfNettoyer(array $espaces): void
{
    fbfSur('pgsql_owner', function () use ($espaces): void {
        foreach (['envoi_controles_dns', 'envoi_controles_listes'] as $t) {
            DB::transaction(function () use ($t, $espaces): void {
                DB::statement("ALTER TABLE {$t} DISABLE TRIGGER USER");
                DB::table($t)->whereIn('workspace_id', $espaces)->delete();
                DB::statement("ALTER TABLE {$t} ENABLE TRIGGER USER");
            });
        }
        DB::table('workspaces')->whereIn('id', $espaces)->delete();
    });
}

function fbfPoint(string $conn, string $ws): PointFeuVert
{
    return fbfSur($conn, function () use ($ws): PointFeuVert {
        foreach (app(ListeFeuVert::class)->points($ws) as $p) {
            if ($p->code === FBF_CODE) {
                return $p;
            }
        }
        throw new RuntimeException(FBF_CODE);
    });
}

/** Un scénario sur chaque connexion : $f reçoit la connexion de lecture et la liste des espaces à nettoyer. */
function fbfScenario(string $conn, Closure $f): void
{
    $espaces = [];
    try {
        $f($conn, function () use (&$espaces): string {
            $ws = (string) Str::uuid();
            $espaces[] = $ws;

            return $ws;
        });
    } finally {
        fbfNettoyer($espaces);
    }
}

dataset('connexions', ['propriétaire (pgsql)' => ['pgsql'], 'applicatif (pgsql_app)' => ['pgsql_app']]);

test('catalogue : le point existe (groupe dns, automatique, sans vrai serveur) et la version passe à 6', function () {
    expect(CatalogueManuels::VERSION)->toBe(6)
        ->and(CatalogueManuels::POINTS[FBF_CODE]['groupe'])->toBe('dns')
        ->and(CatalogueManuels::POINTS[FBF_CODE]['serveur_reel'])->toBeFalse()
        ->and(CatalogueManuels::codes())->toHaveCount(49);
});

test('contrôles de 14 minutes (DNS et listes) ⇒ fait', function (string $conn) {
    fbfScenario($conn, function (string $conn, Closure $espace): void {
        $ws = $espace();
        fbfPoser($ws, 14, 14);
        $p = fbfPoint($conn, $ws);
        expect($p->fait)->toBeTrue()->and($p->bloquant)->toBeFalse()->and($p->automatique)->toBeTrue();
    });
})->with('connexions');

test('DNS de 16 minutes, listes fraîches ⇒ rouge et bloquant', function (string $conn) {
    fbfScenario($conn, function (string $conn, Closure $espace): void {
        $ws = $espace();
        fbfPoser($ws, 16, 1);
        $p = fbfPoint($conn, $ws);
        expect($p->fait)->toBeFalse()->and($p->bloquant)->toBeTrue()->and($p->detail)->not->toBe('');
    });
})->with('connexions');

test('listes de 16 minutes, DNS frais ⇒ rouge (les deux tables comptent)', function (string $conn) {
    fbfScenario($conn, function (string $conn, Closure $espace): void {
        $ws = $espace();
        fbfPoser($ws, 1, 16);
        expect(fbfPoint($conn, $ws)->fait)->toBeFalse();
    });
})->with('connexions');

test('une seule table remplie (listes absentes, ou DNS absents) ⇒ rouge ; aucun contrôle ⇒ rouge', function (string $conn) {
    fbfScenario($conn, function (string $conn, Closure $espace): void {
        $sansListes = $espace();
        fbfPoser($sansListes, 1, null);
        $sansDns = $espace();
        fbfPoser($sansDns, null, 1);
        expect(fbfPoint($conn, $sansListes)->fait)->toBeFalse()
            ->and(fbfPoint($conn, $sansDns)->fait)->toBeFalse()
            ->and(fbfPoint($conn, $espace())->fait)->toBeFalse();
    });
})->with('connexions');

test('seule la DERNIÈRE ligne de chaque domaine compte ; la plus ancienne de ces dernières lignes décide', function (string $conn) {
    fbfScenario($conn, function (string $conn, Closure $espace): void {
        $ws = $espace();
        // a : une vieille ligne (30 min) puis une récente (1 min) ⇒ la dernière est fraîche ; b : seulement 16 min ⇒ périmée.
        fbfPoser($ws, 30, 1, 'a.example.test');
        fbfPoser($ws, 1, 1, 'a.example.test');
        expect(fbfPoint($conn, $ws)->fait)->toBeTrue();
        fbfPoser($ws, 16, 1, 'b.example.test');
        expect(fbfPoint($conn, $ws)->fait)->toBeFalse();
    });
})->with('connexions');

test('cloisonnement : des contrôles frais de l\'espace B ne rendent pas l\'espace A vert', function (string $conn) {
    fbfScenario($conn, function (string $conn, Closure $espace): void {
        $a = $espace();
        $b = $espace();
        fbfPoser($a, 16, 16);
        fbfPoser($b, 1, 1);
        expect(fbfPoint($conn, $a)->fait)->toBeFalse()->and(fbfPoint($conn, $b)->fait)->toBeTrue();
    });
})->with('connexions');

test('l\'empreinte change au passage des 15 minutes (ligne vieillie par la base, sans sleep)', function (string $conn) {
    fbfScenario($conn, function (string $conn, Closure $espace): void {
        $ws = $espace();
        fbfPoser($ws, 14, 14);
        $liste = app(ListeFeuVert::class);
        $avant = fbfSur($conn, fn () => $liste->empreinte($ws, $liste->points($ws)));
        // Le temps « passe » : la ligne DNS passe de 14 à 16 minutes (déclencheur d'ajout seul coupé, base de test).
        fbfSur('pgsql_owner', function () use ($ws): void {
            DB::transaction(function () use ($ws): void {
                DB::statement('ALTER TABLE envoi_controles_dns DISABLE TRIGGER USER');
                DB::statement("UPDATE envoi_controles_dns SET controle_le = now() - interval '16 minutes' WHERE workspace_id = ?", [$ws]);
                DB::statement('ALTER TABLE envoi_controles_dns ENABLE TRIGGER USER');
            });
        });
        $apres = fbfSur($conn, fn () => $liste->empreinte($ws, $liste->points($ws)));
        expect($apres)->not->toBe($avant);
    });
})->with('connexions');

test('décision : « accorde » sur batterie de 16 minutes ⇒ 409 sans ligne ; « retire » ⇒ 200', function () {
    // Tout reste dans la transaction du test (la décision écrite référence l'espace : rien de committé ici).
    $ws = (string) Str::uuid();
    fbfPoser($ws, 16, 1, 'a.example.test', DB::getDefaultConnection());

    $liste = app(ListeFeuVert::class);
    $empreinte = $liste->empreinte($ws, $liste->points($ws));
    $decision = app(DecisionFeuVert::class);
    $r = $decision->decider($ws, 'accorde', 'Motif de test assez long pour la règle', 'Responsable Test', $empreinte);
    expect($r['statut'])->toBe(409)
        ->and(DB::table('envoi_feu_vert_decisions')->where('workspace_id', $ws)->count())->toBe(0);
    $r = $decision->decider($ws, 'retire', 'Motif de test assez long pour la règle', 'Responsable Test', null);
    expect($r['statut'])->toBe(200)
        ->and(DB::table('envoi_feu_vert_decisions')->where('workspace_id', $ws)->count())->toBe(1);

});
