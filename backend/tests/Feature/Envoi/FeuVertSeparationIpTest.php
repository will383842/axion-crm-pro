<?php

/**
 * FINI X5-B : `auto.separation_lettre_prospection` lit l'inventaire des IP (`envoi_ips`, `envoi_domaines.ip_id`).
 * Chaque cas tourne sous `pgsql` (propriétaire : prouve le filtre `workspace_id` explicite) ET `pgsql_app` (RLS armée).
 * Aucun réseau. Adresses en `*.example.test`, IP de documentation (RFC 5737).
 */

use App\Envoi\FeuVert\DepotPreuves;
use App\Envoi\FeuVert\ListeFeuVert;
use App\Envoi\FeuVert\PointFeuVert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->group('envoi');

const SIP_CODE = 'auto.separation_lettre_prospection';

afterEach(function () {
    foreach (['pgsql_app', 'pgsql_owner'] as $c) {
        if (array_key_exists($c, DB::getConnections())) {
            DB::connection($c)->disconnect();
        }
    }
});

function sipSur(string $nom, Closure $f): mixed
{
    $avant = DB::getDefaultConnection();
    DB::setDefaultConnection($nom);
    try {
        return $f();
    } finally {
        DB::setDefaultConnection($avant);
    }
}

function sipEspace(): string
{
    $ws = (string) Str::uuid();
    sipSur('pgsql_owner', fn () => DB::statement("INSERT INTO workspaces (id, name, slug, settings, created_at, updated_at) VALUES (?, 'Test', ?, '{}', now(), now())", [$ws, 'xb-' . substr($ws, 0, 8)]));

    return $ws;
}

/** Inscrit une IP ; rend son identifiant. */
function sipIp(string $ws, string $ref, string $flux): string
{
    return sipSur('pgsql_owner', function () use ($ws, $ref, $flux): string {
        $id = (string) Str::uuid();
        DB::statement("INSERT INTO envoi_ips (id, workspace_id, ref, flux, auteur) VALUES (?, ?, ?, ?, 'test')", [$id, $ws, $ref, $flux]);

        return $id;
    });
}

/** Inscrit un domaine du flux donné, relié ou non à une IP. */
function sipDomaine(string $ws, string $nom, ?string $flux, ?string $ipId): void
{
    sipSur('pgsql_owner', fn () => DB::statement(
        "INSERT INTO envoi_domaines (workspace_id, nom, achete_le, flux, ip_id) VALUES (?, ?, '2026-01-01', ?, ?)",
        [$ws, $nom, $flux, $ipId],
    ));
}

/** Test seulement : modifie une IP ou un domaine en court-circuitant les déclencheurs (la base est lue, jamais modifiée par le code). */
function sipForce(string $sql, array $liens): void
{
    sipSur('pgsql_owner', function () use ($sql, $liens): void {
        DB::transaction(function () use ($sql, $liens): void {
            DB::statement('ALTER TABLE envoi_ips DISABLE TRIGGER USER');
            DB::statement('ALTER TABLE envoi_domaines DISABLE TRIGGER USER');
            DB::statement($sql, $liens);
            DB::statement('ALTER TABLE envoi_ips ENABLE TRIGGER USER');
            DB::statement('ALTER TABLE envoi_domaines ENABLE TRIGGER USER');
        });
    });
}

function sipPoint(string $conn, string $ws): PointFeuVert
{
    return sipSur($conn, function () use ($ws): PointFeuVert {
        foreach (app(ListeFeuVert::class)->points($ws) as $p) {
            if ($p->code === SIP_CODE) {
                return $p;
            }
        }
        throw new RuntimeException(SIP_CODE);
    });
}

function sipScenario(Closure $f): void
{
    $espaces = [];
    try {
        $f(function () use (&$espaces): string {
            return $espaces[] = sipEspace();
        });
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        sipSur('pgsql_owner', function () use ($espaces): void {
            DB::transaction(function () use ($espaces): void {
                foreach (['envoi_domaines', 'envoi_ips'] as $t) {
                    DB::statement("ALTER TABLE {$t} DISABLE TRIGGER USER");
                    DB::table($t)->whereIn('workspace_id', $espaces)->delete();
                    DB::statement("ALTER TABLE {$t} ENABLE TRIGGER USER");
                }
                DB::table('workspaces')->whereIn('id', $espaces)->delete();
            });
        });
    }
}

dataset('connexions', ['propriétaire (pgsql)' => ['pgsql'], 'applicatif (pgsql_app)' => ['pgsql_app']]);

test('tous les domaines reliés à une IP de leur flux (deux flux) ⇒ fait', function (string $conn) {
    sipScenario(function (Closure $espace) use ($conn): void {
        $ws = $espace();
        $ipP = sipIp($ws, '192.0.2.10', 'prospection');
        $ipL = sipIp($ws, '198.51.100.20', 'lettre');
        sipDomaine($ws, 'p.example.test', 'prospection', $ipP);
        sipDomaine($ws, 'l.example.test', 'lettre', $ipL);
        $p = sipPoint($conn, $ws);
        expect($p->fait)->toBeTrue()->and($p->automatique)->toBeTrue();
    });
})->with('connexions');

test('un espace sans lettre n’a pas besoin d’IP lettre', function (string $conn) {
    sipScenario(function (Closure $espace) use ($conn): void {
        $ws = $espace();
        sipDomaine($ws, 'p.example.test', 'prospection', sipIp($ws, '192.0.2.10', 'prospection'));
        expect(sipPoint($conn, $ws)->fait)->toBeTrue();
    });
})->with('connexions');

test('aucun domaine ⇒ rouge (rien à prouver : échec fermé)', function (string $conn) {
    sipScenario(function (Closure $espace) use ($conn): void {
        $ws = $espace();
        sipIp($ws, '192.0.2.10', 'prospection');
        expect(sipPoint($conn, $ws)->fait)->toBeFalse();
    });
})->with('connexions');

test('un domaine sans IP ⇒ rouge, avec la raison', function (string $conn) {
    sipScenario(function (Closure $espace) use ($conn): void {
        $ws = $espace();
        sipDomaine($ws, 'p.example.test', 'prospection', sipIp($ws, '192.0.2.10', 'prospection'));
        sipDomaine($ws, 'q.example.test', 'prospection', null);
        $p = sipPoint($conn, $ws);
        expect($p->fait)->toBeFalse()->and($p->detail)->toContain('sans IP');
    });
})->with('connexions');

test('un domaine sans flux ⇒ rouge', function (string $conn) {
    sipScenario(function (Closure $espace) use ($conn): void {
        $ws = $espace();
        sipDomaine($ws, 'p.example.test', null, null);
        expect(sipPoint($conn, $ws)->fait)->toBeFalse();
    });
})->with('connexions');

test('IP retirée ⇒ rouge', function (string $conn) {
    sipScenario(function (Closure $espace) use ($conn): void {
        $ws = $espace();
        $ip = sipIp($ws, '192.0.2.10', 'prospection');
        sipDomaine($ws, 'p.example.test', 'prospection', $ip);
        expect(sipPoint($conn, $ws)->fait)->toBeTrue();
        sipSur('pgsql_owner', fn () => DB::statement('UPDATE envoi_ips SET retiree_le = now() WHERE id = ?', [$ip]));
        $p = sipPoint($conn, $ws);
        expect($p->fait)->toBeFalse()->and($p->detail)->toContain('retirée');
    });
})->with('connexions');

test('ligne forcée : une IP reliée à des domaines de deux flux ⇒ rouge', function (string $conn) {
    sipScenario(function (Closure $espace) use ($conn): void {
        $ws = $espace();
        $ip = sipIp($ws, '192.0.2.10', 'prospection');
        sipDomaine($ws, 'p.example.test', 'prospection', $ip);
        sipDomaine($ws, 'l.example.test', 'lettre', sipIp($ws, '198.51.100.20', 'lettre'));
        expect(sipPoint($conn, $ws)->fait)->toBeTrue();
        sipForce('UPDATE envoi_domaines SET ip_id = ? WHERE workspace_id = ? AND nom = ?', [$ip, $ws, 'l.example.test']);
        $p = sipPoint($conn, $ws);
        expect($p->fait)->toBeFalse()->and($p->detail)->toContain('deux flux');
    });
})->with('connexions');

test('l’inventaire complet de l’espace B n’éclaire pas l’espace A', function (string $conn) {
    sipScenario(function (Closure $espace) use ($conn): void {
        $a = $espace();
        $b = $espace();
        sipDomaine($b, 'p.example.test', 'prospection', sipIp($b, '192.0.2.10', 'prospection'));
        sipDomaine($a, 'p.example.test', 'prospection', null);
        expect(sipPoint($conn, $b)->fait)->toBeTrue()->and(sipPoint($conn, $a)->fait)->toBeFalse();
    });
})->with('connexions');

test('le point reste automatique : il refuse toute preuve manuelle', function () {
    sipScenario(function (Closure $espace): void {
        expect(fn () => app(DepotPreuves::class)->ajouter($espace(), SIP_CODE, 'Preuve de test, réf. exemple-01', 'auteur'))->toThrow(InvalidArgumentException::class);
    });
});
