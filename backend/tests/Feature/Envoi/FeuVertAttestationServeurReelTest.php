<?php

/**
 * X5-A — attestation humaine « serveur réel » : les 22 points qui attendent le serveur ne passent « fait » que si une
 * attestation est ACTIVE (dernière ligne de l'espace = « atteste ») ET qu'une preuve « fait » a été déposée APRÈS elle.
 *
 * Données écrites par le propriétaire (auto-commit, dates posées explicitement), lues par `pgsql` (RLS contournée : prouve
 * le filtre `workspace_id` explicite) ET `pgsql_app` (RLS armée). Aucun réseau. Adresses en `*.example.test`.
 */

use App\Envoi\FeuVert\CatalogueManuels;
use App\Envoi\FeuVert\ListeFeuVert;
use App\Envoi\FeuVert\PointFeuVert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('envoi');

afterEach(function () {
    foreach (['pgsql_app', 'pgsql_owner'] as $c) {
        if (array_key_exists($c, DB::getConnections())) {
            DB::connection($c)->disconnect();
        }
    }
});

function attSrSur(string $nom, Closure $f): mixed
{
    $avant = DB::getDefaultConnection();
    DB::setDefaultConnection($nom);
    try {
        return $f();
    } finally {
        DB::setDefaultConnection($avant);
    }
}

/** @return list<string> codes manuels qui attendent le vrai serveur */
function attSrCodesServeur(): array
{
    return array_values(array_filter(CatalogueManuels::codesManuels(), static fn (string $c): bool => CatalogueManuels::POINTS[$c]['serveur_reel']));
}

function attSrEspace(): string
{
    $ws = (string) Str::uuid();
    attSrSur('pgsql_owner', fn () => DB::statement("INSERT INTO workspaces (id, name, slug, settings, created_at, updated_at) VALUES (?, 'Test', ?, '{}', now(), now())", [$ws, 'xa-' . substr($ws, 0, 8)]));

    return $ws;
}

/** Attestation posée il y a $minutes minutes (base). */
function attSrAttestation(string $ws, string $geste, int $minutes): void
{
    attSrSur('pgsql_owner', fn () => DB::statement(
        "INSERT INTO envoi_attestations_serveur_reel (workspace_id, geste, auteur, motif, le) VALUES (?, ?, 'responsable', 'Serveur installe, constat ecrit', now() - make_interval(mins => ?))",
        [$ws, $geste, $minutes],
    ));
}

function attSrPreuve(string $ws, string $code, int $minutes, bool $fait = true): void
{
    attSrSur('pgsql_owner', fn () => DB::statement(
        'INSERT INTO envoi_feu_vert_preuves (workspace_id, code, preuve, auteur, fait, cree_le) VALUES (?, ?, ?, ?, ?, now() - make_interval(mins => ?))',
        [$ws, $code, 'Constat du 17/11 : reference interne 42', 'responsable', $fait ? 'true' : 'false', $minutes],
    ));
}

function attSrPoint(string $conn, string $ws, string $code): PointFeuVert
{
    return attSrSur($conn, function () use ($ws, $code): PointFeuVert {
        foreach (app(ListeFeuVert::class)->points($ws) as $p) {
            if ($p->code === $code) {
                return $p;
            }
        }
        throw new RuntimeException($code);
    });
}

function attSrNettoyer(array $espaces): void
{
    attSrSur('pgsql_owner', function () use ($espaces): void {
        foreach (['envoi_attestations_serveur_reel', 'envoi_feu_vert_preuves', 'envoi_journal_commandes'] as $t) {
            DB::transaction(function () use ($t, $espaces): void {
                DB::statement("ALTER TABLE {$t} DISABLE TRIGGER USER");
                DB::table($t)->whereIn('workspace_id', $espaces)->delete();
                DB::statement("ALTER TABLE {$t} ENABLE TRIGGER USER");
            });
        }
        DB::table('workspaces')->whereIn('id', $espaces)->delete();
    });
}

function attSrScenario(Closure $f): void
{
    $espaces = [];
    try {
        $f(function () use (&$espaces): string {
            return $espaces[] = attSrEspace();
        });
    } finally {
        // La commande écrit dans la transaction du test (connexion par défaut) : la terminer AVANT le nettoyage par le
        // propriétaire, sinon ses verrous bloquent ALTER TABLE / DELETE (blocage sans fin).
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        attSrNettoyer($espaces);
    }
}

dataset('connexions', ['propriétaire (pgsql)' => ['pgsql'], 'applicatif (pgsql_app)' => ['pgsql_app']]);

test('sans attestation : les points serveur réel attendent le serveur (comportement de main)', function (string $conn) {
    attSrScenario(function (Closure $espace) use ($conn): void {
        $ws = $espace();
        $codes = attSrCodesServeur();
        expect($codes)->toHaveCount(22);
        attSrPreuve($ws, $codes[0], 5);
        foreach ($codes as $c) {
            $p = attSrPoint($conn, $ws, $c);
            expect($p->etat)->toBe(PointFeuVert::ETAT_ATTEND_SERVEUR)->and($p->fait)->toBeFalse();
        }
    });
})->with('connexions');

test('attestation + preuve postérieure ⇒ fait ; autres points inchangés', function (string $conn) {
    attSrScenario(function (Closure $espace) use ($conn): void {
        $ws = $espace();
        [$a, $b] = attSrCodesServeur();
        attSrAttestation($ws, 'atteste', 10);
        attSrPreuve($ws, $a, 5);
        expect(attSrPoint($conn, $ws, $a)->fait)->toBeTrue()->and(attSrPoint($conn, $ws, $a)->etat)->toBe(PointFeuVert::ETAT_FAIT)
            ->and(attSrPoint($conn, $ws, $b)->fait)->toBeFalse();
    });
})->with('connexions');

test('attestation + preuve antérieure ⇒ pas fait (à revalider)', function (string $conn) {
    attSrScenario(function (Closure $espace) use ($conn): void {
        $ws = $espace();
        [$a] = attSrCodesServeur();
        attSrPreuve($ws, $a, 20);
        attSrAttestation($ws, 'atteste', 10);
        $p = attSrPoint($conn, $ws, $a);
        expect($p->fait)->toBeFalse()->and($p->detail)->toContain('revalider');
    });
})->with('connexions');

test('preuve postérieure puis « défaite » (dernière ligne fait = faux) ⇒ pas fait', function (string $conn) {
    attSrScenario(function (Closure $espace) use ($conn): void {
        $ws = $espace();
        [$a] = attSrCodesServeur();
        attSrAttestation($ws, 'atteste', 10);
        attSrPreuve($ws, $a, 5);
        attSrPreuve($ws, $a, 2, false);
        expect(attSrPoint($conn, $ws, $a)->fait)->toBeFalse();
    });
})->with('connexions');

test('« retire » après « atteste » ⇒ tous les points attendent de nouveau le serveur', function (string $conn) {
    attSrScenario(function (Closure $espace) use ($conn): void {
        $ws = $espace();
        [$a] = attSrCodesServeur();
        attSrAttestation($ws, 'atteste', 10);
        attSrPreuve($ws, $a, 5);
        attSrAttestation($ws, 'retire', 1);
        foreach (attSrCodesServeur() as $c) {
            expect(attSrPoint($conn, $ws, $c)->etat)->toBe(PointFeuVert::ETAT_ATTEND_SERVEUR);
        }
    });
})->with('connexions');

test('l’attestation de l’espace B est sans effet sur A', function (string $conn) {
    attSrScenario(function (Closure $espace) use ($conn): void {
        $a = $espace();
        $b = $espace();
        [$code] = attSrCodesServeur();
        attSrAttestation($b, 'atteste', 10);
        attSrPreuve($b, $code, 5);
        attSrPreuve($a, $code, 5);
        expect(attSrPoint($conn, $a, $code)->etat)->toBe(PointFeuVert::ETAT_ATTEND_SERVEUR)
            ->and(attSrPoint($conn, $b, $code)->fait)->toBeTrue();
    });
})->with('connexions');

test('l’attestation change l’empreinte du feu vert', function () {
    attSrScenario(function (Closure $espace): void {
        $ws = $espace();
        [$a] = attSrCodesServeur();
        $liste = app(ListeFeuVert::class);
        $avant = $liste->empreinte($ws, $liste->points($ws));
        attSrAttestation($ws, 'atteste', 10);
        attSrPreuve($ws, $a, 5);
        expect($liste->empreinte($ws, $liste->points($ws)))->not->toBe($avant);
    });
});

test('erreur de lecture de l’attestation ⇒ exception, jamais « fait » (échec fermé)', function () {
    attSrScenario(function (Closure $espace): void {
        $ws = $espace();
        attSrSur('pgsql_owner', fn () => DB::statement('ALTER TABLE envoi_attestations_serveur_reel RENAME TO envoi_attestations_serveur_reel_x'));
        try {
            expect(fn () => app(ListeFeuVert::class)->points($ws))->toThrow(Throwable::class);
        } finally {
            attSrSur('pgsql_owner', fn () => DB::statement('ALTER TABLE envoi_attestations_serveur_reel_x RENAME TO envoi_attestations_serveur_reel'));
        }
    });
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'pgsql seulement');

test('commande : écrit la ligne et le journal ; sans --motif, motif court ou avec @ ⇒ échec, rien écrit', function () {
    attSrScenario(function (Closure $espace): void {
        $ws = $espace();
        $n = fn () => DB::table('envoi_attestations_serveur_reel')->where('workspace_id', $ws)->count(); // même transaction que la commande

        expect(Artisan::call('envoi:serveur-reel-attester', ['workspace' => $ws, 'geste' => 'atteste', '--auteur' => 'responsable']))->toBe(1);
        expect(Artisan::call('envoi:serveur-reel-attester', ['workspace' => $ws, 'geste' => 'atteste', '--motif' => 'Serveur installe, constat ecrit']))->toBe(1);
        expect(Artisan::call('envoi:serveur-reel-attester', ['workspace' => $ws, 'geste' => 'atteste', '--auteur' => 'responsable', '--motif' => 'contact@example.test ok']))->toBe(1);
        expect(Artisan::call('envoi:serveur-reel-attester', ['workspace' => $ws, 'geste' => 'atteste', '--auteur' => 'responsable', '--motif' => 'court']))->toBe(1);
        expect(Artisan::call('envoi:serveur-reel-attester', ['workspace' => $ws, 'geste' => 'oups', '--auteur' => 'responsable', '--motif' => 'Serveur installe, constat ecrit']))->toBe(1);
        expect($n())->toBe(0);

        expect(Artisan::call('envoi:serveur-reel-attester', ['workspace' => $ws, 'geste' => 'atteste', '--auteur' => 'responsable', '--motif' => 'Serveur installe, constat ecrit']))->toBe(0);
        expect($n())->toBe(1)
            ->and(DB::table('envoi_journal_commandes')->where('workspace_id', $ws)->where('commande', 'attestation_serveur_reel')->count())->toBe(1);
    });
});

test('aucune route ne contient « attest »', function () {
    foreach (Route::getRoutes() as $r) {
        expect(strtolower($r->uri()))->not->toContain('attest');
    }
});

test('« accordé » reste impossible tant que auto.separation_lettre_prospection est rouge, même attesté', function () {
    attSrScenario(function (Closure $espace): void {
        $ws = $espace();
        attSrAttestation($ws, 'atteste', 10);
        foreach (attSrCodesServeur() as $c) {
            attSrPreuve($ws, $c, 5);
        }
        $liste = app(ListeFeuVert::class);
        $sep = collect($liste->points($ws))->firstWhere('code', 'auto.separation_lettre_prospection');
        expect($sep)->not->toBeNull()->and($sep->fait)->toBeFalse();
        $bloquants = array_filter($liste->points($ws), static fn (PointFeuVert $p): bool => $p->bloquant && $p->code !== ListeFeuVert::POINT_EXPLOITATION);
        expect($bloquants)->not->toBe([]);
    });
});
