<?php

/**
 * FV-BAT — relectures de #690, #702 et #718 sur le calcul du feu vert.
 *  - La batterie ne lit que les domaines et IP ENCORE configurés (un domaine retiré ne bloque plus pour toujours).
 *  - Les dernières lignes de listes se choisissent par `controle_le DESC, id DESC`.
 *  - Attestation et retrait portant le même `le` : « retire » gagne (échec fermé), jamais un départage sur un UUID.
 *  - L'espace B est sans effet sur l'espace A.
 *
 * Données écrites par le propriétaire, lues par `pgsql` (RLS contournée) ET `pgsql_app` (RLS armée). Aucun réseau.
 * Adresses en `*.example.test` (dépôt PUBLIC).
 */

use App\Envoi\FeuVert\CatalogueManuels;
use App\Envoi\FeuVert\DepotAttestationServeurReel;
use App\Envoi\FeuVert\ListeFeuVert;
use App\Envoi\FeuVert\PointFeuVert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

function fvbSur(string $nom, Closure $f): mixed
{
    $avant = DB::getDefaultConnection();
    DB::setDefaultConnection($nom);
    try {
        return $f();
    } finally {
        DB::setDefaultConnection($avant);
    }
}

function fvbEspace(): string
{
    $ws = (string) Str::uuid();
    fvbSur('pgsql_owner', fn () => DB::statement("INSERT INTO workspaces (id, name, slug, settings, created_at, updated_at) VALUES (?, 'Test', ?, '{}', now(), now())", [$ws, 'fvb-' . substr($ws, 0, 8)]));

    return $ws;
}

function fvbDns(string $ws, string $domaine, int $minutes): void
{
    fvbSur('pgsql_owner', fn () => DB::statement('INSERT INTO envoi_controles_dns (workspace_id, domaine, controle_le, pret, pause) VALUES (?, ?, now() - make_interval(mins => ?), true, false)', [$ws, $domaine, $minutes]));
}

function fvbListe(string $ws, string $type, string $ressource, int $minutes): void
{
    fvbSur('pgsql_owner', fn () => DB::statement("INSERT INTO envoi_controles_listes (workspace_id, type, ressource, statut, zone, pause, controle_le) VALUES (?, ?, ?, 'non_liste', 'z.example.test', false, now() - make_interval(mins => ?))", [$ws, $type, $ressource, $minutes]));
}

function fvbPoint(string $conn, string $ws, string $code): PointFeuVert
{
    return fvbSur($conn, function () use ($ws, $code): PointFeuVert {
        foreach (app(ListeFeuVert::class)->points($ws) as $p) {
            if ($p->code === $code) {
                return $p;
            }
        }
        throw new RuntimeException($code);
    });
}

function fvbNettoyer(array $espaces): void
{
    fvbSur('pgsql_owner', function () use ($espaces): void {
        foreach (['envoi_controles_dns', 'envoi_controles_listes', 'envoi_attestations_serveur_reel', 'envoi_feu_vert_preuves'] as $t) {
            DB::transaction(function () use ($t, $espaces): void {
                DB::statement("ALTER TABLE {$t} DISABLE TRIGGER USER");
                DB::table($t)->whereIn('workspace_id', $espaces)->delete();
                DB::statement("ALTER TABLE {$t} ENABLE TRIGGER USER");
            });
        }
        DB::table('workspaces')->whereIn('id', $espaces)->delete();
    });
}

function fvbScenario(Closure $f): void
{
    $espaces = [];
    try {
        $f(function () use (&$espaces): string {
            return $espaces[] = fvbEspace();
        });
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        fvbNettoyer($espaces);
    }
}

dataset('connexions', ['propriétaire (pgsql)' => ['pgsql'], 'applicatif (pgsql_app)' => ['pgsql_app']]);

const FVB_BATTERIE = 'auto.batterie_fraiche';

test('un domaine retiré de la configuration, avec une vieille ligne, ne bloque plus la batterie', function (string $conn) {
    fvbScenario(function (Closure $espace) use ($conn): void {
        $ws = $espace();
        config(['envoi.dns.domaines' => ['a.example.test' => ['selecteur' => 's', 'helo' => 'h.example.test', 'ip' => '192.0.2.1']]]);
        fvbDns($ws, 'a.example.test', 2);
        fvbListe($ws, 'domaine', 'a.example.test', 2);
        fvbDns($ws, 'retire.example.test', 120);
        fvbListe($ws, 'domaine', 'retire.example.test', 120);
        expect(fvbPoint($conn, $ws, FVB_BATTERIE)->fait)->toBeTrue();
    });
})->with('connexions');

test('un domaine ENCORE configuré et périmé bloque toujours la batterie', function (string $conn) {
    fvbScenario(function (Closure $espace) use ($conn): void {
        $ws = $espace();
        config(['envoi.dns.domaines' => ['a.example.test' => [], 'b.example.test' => []]]);
        fvbDns($ws, 'a.example.test', 2);
        fvbListe($ws, 'domaine', 'a.example.test', 2);
        fvbDns($ws, 'b.example.test', 120);
        fvbListe($ws, 'domaine', 'b.example.test', 2);
        expect(fvbPoint($conn, $ws, FVB_BATTERIE)->fait)->toBeFalse();
    });
})->with('connexions');

test('une IP retirée de la configuration ne bloque plus ; une IP d\'un autre espace ne compte pas', function (string $conn) {
    fvbScenario(function (Closure $espace) use ($conn): void {
        $ws = $espace();
        $autre = $espace();
        config([
            'envoi.dns.domaines' => ['a.example.test' => []],
            'envoi.listes_noires.ips' => ['192.0.2.10@' . $ws, '192.0.2.99@' . $autre],
        ]);
        fvbDns($ws, 'a.example.test', 2);
        fvbListe($ws, 'domaine', 'a.example.test', 2);
        fvbListe($ws, 'ip', '192.0.2.10', 2);
        fvbListe($ws, 'ip', '192.0.2.99', 120); // configurée pour l'espace B seulement : sans effet sur A
        fvbListe($ws, 'ip', '192.0.2.50', 120); // retirée
        expect(fvbPoint($conn, $ws, FVB_BATTERIE)->fait)->toBeTrue();
    });
})->with('connexions');

test('deux lignes de listes : la plus récente par controle_le fait foi, pas la plus grande id', function (string $conn) {
    fvbScenario(function (Closure $espace) use ($conn): void {
        $ws = $espace();
        config(['envoi.dns.domaines' => ['a.example.test' => []]]);
        fvbDns($ws, 'a.example.test', 2);
        fvbListe($ws, 'domaine', 'a.example.test', 2);   // id plus petit, contrôle récent
        fvbListe($ws, 'domaine', 'a.example.test', 120); // id plus grand, contrôle ancien (rattrapage)
        expect(fvbPoint($conn, $ws, FVB_BATTERIE)->fait)->toBeTrue();
    });
})->with('connexions');

test('l\'espace B est sans effet sur l\'espace A', function (string $conn) {
    fvbScenario(function (Closure $espace) use ($conn): void {
        $a = $espace();
        $b = $espace();
        config(['envoi.dns.domaines' => ['a.example.test' => []]]);
        fvbDns($b, 'a.example.test', 2);
        fvbListe($b, 'domaine', 'a.example.test', 2);
        expect(fvbPoint($conn, $a, FVB_BATTERIE)->fait)->toBeFalse();
    });
})->with('connexions');

function fvbAttestation(string $ws, string $id, string $geste): void
{
    fvbSur('pgsql_owner', fn () => DB::statement(
        "INSERT INTO envoi_attestations_serveur_reel (id, workspace_id, geste, auteur, motif, le) VALUES (?, ?, ?, 'responsable', 'Serveur installe, constat ecrit', '2026-11-18 10:00:00+00')",
        [$id, $ws, $geste],
    ));
}

test('attestation et retrait au même horodatage : « retire » gagne, quel que soit l\'UUID', function (string $conn, string $idAtteste, string $idRetire) {
    fvbScenario(function (Closure $espace) use ($conn, $idAtteste, $idRetire): void {
        $ws = $espace();
        fvbAttestation($ws, $idAtteste, 'atteste');
        fvbAttestation($ws, $idRetire, 'retire');
        expect(fvbSur($conn, fn () => app(DepotAttestationServeurReel::class)->active($ws)))->toBeFalse();
        $codes = array_values(array_filter(CatalogueManuels::codesManuels(), static fn (string $c): bool => CatalogueManuels::POINTS[$c]['serveur_reel']));
        expect($codes)->toHaveCount(22);
        fvbSur('pgsql_owner', fn () => DB::statement('INSERT INTO envoi_feu_vert_preuves (workspace_id, code, preuve, auteur, fait, cree_le) VALUES (?, ?, ?, ?, true, ?::timestamptz + interval \'5 minutes\')', [$ws, $codes[0], 'Constat du 17/11 : reference interne 42', 'responsable', '2026-11-18 10:00:00+00']));
        foreach ($codes as $c) {
            expect(fvbPoint($conn, $ws, $c)->etat)->toBe(PointFeuVert::ETAT_ATTEND_SERVEUR);
        }
    });
})->with([
    'propriétaire, atteste a le plus grand id' => ['pgsql', 'ffffffff-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000001'],
    'propriétaire, retire a le plus grand id' => ['pgsql', '00000000-0000-4000-8000-000000000002', 'ffffffff-0000-4000-8000-000000000002'],
    'applicatif, atteste a le plus grand id' => ['pgsql_app', 'ffffffff-0000-4000-8000-000000000003', '00000000-0000-4000-8000-000000000003'],
    'applicatif, retire a le plus grand id' => ['pgsql_app', '00000000-0000-4000-8000-000000000004', 'ffffffff-0000-4000-8000-000000000004'],
]);
