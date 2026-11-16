<?php

use App\Envoi\Serveur\Kumo\RegistreInjections;
use App\Envoi\Serveur\Kumo\RegistreInjectionsDb;
use App\Support\WorkspaceContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Lot C3-2 — registre durable de l'adaptateur Kumo (implémentation de RegistreInjections sur les tables de C3-0).
 * Chaque test est rejoué SOUS LE RÔLE APPLICATIF (SET LOCAL ROLE) ET SOUS LE PROPRIÉTAIRE. Faux serveur : aucun réseau.
 * Données fictives : aucune adresse, seulement des clés d'idempotence et des identifiants.
 */
uses(TestCase::class, RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-12-10 12:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

function rdEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'RD', 'slug' => 'rd-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function rdMode(string $mode): void
{
    if ($mode === 'app') {
        DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    }
}

/** @return array{id: string, etat: string, reporte_au: ?int, instant: int, jeton: string} */
function rdLigne(string $etat = 'injection_en_cours', int $instant = 1000, array $plus = []): array
{
    return $plus + ['id' => (string) Str::uuid(), 'etat' => $etat, 'reporte_au' => null, 'instant' => $instant, 'jeton' => bin2hex(random_bytes(16))];
}

/** Compte brut, hors RLS d'espace : rôle propriétaire (SUPERUSER) le temps du comptage, puis rôle de départ rétabli. */
function rdCompte(string $table): int
{
    $role = DB::selectOne('SELECT current_user AS u')->u;
    DB::statement('RESET ROLE');
    $n = (int) DB::selectOne("SELECT count(*) AS n FROM {$table}")->n;
    if ($role === config('database.connections.pgsql_app.username')) {
        DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', $role) . '"');
    }

    return $n;
}

dataset('modes', ['app', 'proprio']);

test('C3-2 la liaison du conteneur rend l\'implémentation durable', function () {
    expect(app(RegistreInjections::class))->toBeInstanceOf(RegistreInjectionsDb::class);
});

test('C3-2 lire : clé absente = null ; écrire puis lire rend la ligne', function (string $mode) {
    $ws = rdEspace();
    rdMode($mode);
    $r = new RegistreInjectionsDb;
    expect($r->lire($ws, 'absente'))->toBeNull();
    $l = rdLigne('reporte', 1000, ['reporte_au' => 5000, 'code' => 451]);
    $r->ecrire($ws, 'k1', $l);
    expect($r->lire($ws, 'k1'))->toEqual($l);
    // Réécriture : la ligne est remplacée, pas dupliquée.
    $l2 = ['etat' => 'accepte', 'reporte_au' => null, 'instant' => 2000] + $l;
    $r->ecrire($ws, 'k1', $l2);
    expect($r->lire($ws, 'k1'))->toEqual($l2)->and(rdCompte('envoi_kumo_injections'))->toBe(1);
})->with('modes');

test('C3-2 ecrireSi : compare-and-set sur (état, instant), clé absente = null/null', function (string $mode) {
    $ws = rdEspace();
    rdMode($mode);
    $r = new RegistreInjectionsDb;
    $a = rdLigne('injection_en_cours', 1000);
    expect($r->ecrireSi($ws, 'k', null, null, $a))->toBeTrue()
        ->and($r->ecrireSi($ws, 'k', null, null, rdLigne()))->toBeFalse()          // déjà là : l'insertion perd
        ->and($r->lire($ws, 'k'))->toEqual($a)
        ->and($r->ecrireSi($ws, 'k', 'accepte', 1000, rdLigne()))->toBeFalse()     // mauvais état
        ->and($r->ecrireSi($ws, 'k', 'injection_en_cours', 999, rdLigne()))->toBeFalse() // mauvais instant
        ->and($r->ecrireSi($ws, 'k', 'injection_en_cours', null, rdLigne()))->toBeFalse()
        ->and($r->ecrireSi($ws, 'absente', 'reporte', 1000, rdLigne()))->toBeFalse()
        ->and($r->lire($ws, 'absente'))->toBeNull();
    $b = ['etat' => 'accepte', 'instant' => 1001] + $a;
    expect($r->ecrireSi($ws, 'k', 'injection_en_cours', 1000, $b))->toBeTrue()->and($r->lire($ws, 'k'))->toEqual($b);
})->with('modes');

test('C3-2 ABA : un worker lent (même état, ancien instant) ne peut pas écraser', function (string $mode) {
    $ws = rdEspace();
    rdMode($mode);
    $r = new RegistreInjectionsDb;
    $id = (string) Str::uuid();
    $j = bin2hex(random_bytes(16));
    $r->ecrire($ws, 'k', ['id' => $id, 'etat' => 'reporte', 'reporte_au' => 5, 'instant' => 100, 'jeton' => $j]);
    // Le worker lent a vu (reporte, 100). Entre-temps : reporte → injection_en_cours → reporte (instant 300).
    expect($r->ecrireSi($ws, 'k', 'reporte', 100, ['etat' => 'injection_en_cours', 'instant' => 200, 'reporte_au' => null] + ['id' => $id, 'jeton' => $j]))->toBeTrue()
        ->and($r->ecrireSi($ws, 'k', 'injection_en_cours', 200, ['etat' => 'reporte', 'instant' => 300, 'reporte_au' => 9] + ['id' => $id, 'jeton' => $j]))->toBeTrue()
        ->and($r->ecrireSi($ws, 'k', 'reporte', 100, ['etat' => 'injection_en_cours', 'instant' => 400, 'reporte_au' => null] + ['id' => $id, 'jeton' => $j]))->toBeFalse()
        ->and($r->lire($ws, 'k')['instant'])->toBe(300);
})->with('modes');

test('C3-2 deux instances, même clé, lecture périmée : un seul ecrireSi vrai', function (string $mode) {
    $ws = rdEspace();
    rdMode($mode);
    $w1 = new RegistreInjectionsDb;
    $w2 = new RegistreInjectionsDb;
    expect($w1->ecrireSi($ws, 'k', null, null, rdLigne('a_injecter', 10)))->toBeTrue();
    $vu1 = $w1->lire($ws, 'k');
    $vu2 = $w2->lire($ws, 'k');
    $gagnants = (int) $w1->ecrireSi($ws, 'k', $vu1['etat'], $vu1['instant'], rdLigne('injection_en_cours', 11))
        + (int) $w2->ecrireSi($ws, 'k', $vu2['etat'], $vu2['instant'], rdLigne('injection_en_cours', 12));
    expect($gagnants)->toBe(1);
})->with('modes');

test('C3-2 seq : posé une fois, jamais perdu par une réécriture sans seq', function (string $mode) {
    $ws = rdEspace();
    rdMode($mode);
    $r = new RegistreInjectionsDb;
    $r->ecrire($ws, 'k', rdLigne('injection_en_cours', 1));
    $acc = ['etat' => 'accepte', 'instant' => 2, 'seq' => $r->prochaineSequence($ws), 'code' => 250] + $r->lire($ws, 'k');
    $r->ecrire($ws, 'k', $acc);
    expect($r->lire($ws, 'k')['seq'])->toBe(1);
    // Réécriture sans `seq` (ex. a_verifier lu plus tard) : le seq posé est conservé, pas d'exception du déclencheur.
    unset($acc['seq']);
    $r->ecrire($ws, 'k', ['instant' => 3] + $acc);
    expect($r->lire($ws, 'k')['seq'])->toBe(1);
    expect($r->ecrireSi($ws, 'k', 'accepte', 3, ['instant' => 4] + $acc))->toBeTrue()
        ->and($r->lire($ws, 'k')['seq'])->toBe(1);
})->with('modes');

test('C3-2 prochaineSequence : 1, 2, 3 par espace, indépendante entre espaces', function (string $mode) {
    $a = rdEspace();
    $b = rdEspace();
    rdMode($mode);
    $r = new RegistreInjectionsDb;
    expect([$r->prochaineSequence($a), $r->prochaineSequence($a), $r->prochaineSequence($b), $r->prochaineSequence($a), $r->prochaineSequence($b)])
        ->toBe([1, 2, 1, 3, 2]);
})->with('modes');

test('C3-2 pause : par espace, persistée, lue par une autre instance', function (string $mode) {
    $a = rdEspace();
    $b = rdEspace();
    rdMode($mode);
    $r = new RegistreInjectionsDb;
    expect($r->enPause($a))->toBeFalse();
    $r->fixerPause($a, true);
    expect((new RegistreInjectionsDb)->enPause($a))->toBeTrue()->and($r->enPause($b))->toBeFalse();
    $r->fixerPause($a, false);
    expect($r->enPause($a))->toBeFalse();
})->with('modes');

test('C3-2 5xx : fenêtre depuis, cloisonnement, remise à zéro SANS DELETE', function (string $mode) {
    $a = rdEspace();
    $b = rdEspace();
    rdMode($mode);
    $r = new RegistreInjectionsDb;
    foreach ([100, 200, 300] as $i) {
        $r->noter5xx($a, $i);
    }
    $r->noter5xx($b, 250);
    expect($r->compter5xx($a, 0))->toBe(3)->and($r->compter5xx($a, 200))->toBe(2)->and($r->compter5xx($a, 301))->toBe(0)->and($r->compter5xx($b, 0))->toBe(1);
    $avant = rdCompte('envoi_kumo_5xx');
    $r->reinitialiser5xx($a);
    expect($r->compter5xx($a, 0))->toBe(0)->and(rdCompte('envoi_kumo_5xx'))->toBe($avant)   // rien n'est effacé
        ->and($r->compter5xx($b, 0))->toBe(1);                                                // l'autre espace est intact
    // Nouvelles erreurs APRÈS la remise : comptées, même avec l'horloge figée à la seconde de la remise + 1.
    $r->noter5xx($a, Carbon::now()->getTimestamp() + 5);
    expect($r->compter5xx($a, 0))->toBe(1);
})->with('modes');

test('C3-2 tout : borné aux 30 derniers jours, trié par clé, cloisonné', function (string $mode) {
    $a = rdEspace();
    $b = rdEspace();
    rdMode($mode);
    $r = new RegistreInjectionsDb;
    $now = Carbon::now()->getTimestamp();
    $r->ecrire($a, 'z', rdLigne('accepte', $now - 10));
    $r->ecrire($a, 'b', rdLigne('reporte', $now - 29 * 86400));
    $r->ecrire($a, 'vieille', rdLigne('accepte', $now - 31 * 86400));
    $r->ecrire($b, 'autre', rdLigne('accepte', $now));
    expect(array_keys($r->tout($a)))->toBe(['b', 'z'])->and(array_keys($r->tout($b)))->toBe(['autre']);
    // La ligne ancienne existe toujours (limite de LECTURE, rien n'est effacé) et se relit par sa clé.
    expect($r->lire($a, 'vieille'))->not->toBeNull();
})->with('modes');

test('C3-2 cloisonnement : A ne lit ni n\'écrit la clé de B, même clé réutilisée', function (string $mode) {
    $a = rdEspace();
    $b = rdEspace();
    rdMode($mode);
    $r = new RegistreInjectionsDb;
    $la = rdLigne('accepte', 10);
    $lb = rdLigne('reporte', 20);
    $r->ecrire($a, 'meme-cle', $la);
    $r->ecrire($b, 'meme-cle', $lb);
    expect($r->lire($a, 'meme-cle'))->toEqual($la)->and($r->lire($b, 'meme-cle'))->toEqual($lb)
        ->and($r->ecrireSi($a, 'meme-cle', 'reporte', 20, rdLigne()))->toBeFalse()   // l'état de B n'est pas celui de A
        ->and($r->lire($b, 'meme-cle'))->toEqual($lb)
        ->and(rdCompte('envoi_kumo_injections'))->toBe(2);
})->with('modes');

test('C3-2 le contexte de l\'appelant est restitué après chaque appel', function (string $mode) {
    $a = rdEspace();
    $b = rdEspace();
    rdMode($mode);
    WorkspaceContext::set($b);
    (new RegistreInjectionsDb)->ecrire($a, 'k', rdLigne());
    expect(WorkspaceContext::current())->toEqual($b);
})->with('modes');

test('C3-2 table absente : exception claire, jamais une QueryException brute', function () {
    $ws = rdEspace();
    Schema::shouldReceive('hasTable')->andReturn(false);
    $r = new RegistreInjectionsDb;
    foreach ([fn () => $r->lire($ws, 'k'), fn () => $r->enPause($ws), fn () => $r->prochaineSequence($ws)] as $f) {
        expect($f)->toThrow(RuntimeException::class, 'registre Kumo durable');
    }
});

test('C3-2 aucune ligne de personne touchée : seules les trois tables du registre bougent', function () {
    $ws = rdEspace();
    $avant = [rdCompte('envoi_inscriptions'), rdCompte('envoi_oppositions'), rdCompte('envoi_messages')];
    $r = new RegistreInjectionsDb;
    $r->ecrire($ws, 'k', rdLigne());
    $r->fixerPause($ws, true);
    $r->noter5xx($ws, 1);
    $r->reinitialiser5xx($ws);
    expect([rdCompte('envoi_inscriptions'), rdCompte('envoi_oppositions'), rdCompte('envoi_messages')])->toBe($avant);
});
