<?php

/**
 * Envoi L8-B — `envoi:transition:constat` : compte, SANS RIEN ÉCRIRE, les lignes encore rattachées par les anciennes
 * formes d'identifiant d'injection (cahier B6-B8, § 12.2). Chaque test joue en `['app', 'proprio']` : rôle applicatif
 * sous RLS (`SET LOCAL ROLE`), puis propriétaire. Données FICTIVES, aucun réseau.
 */

use App\Support\WorkspaceContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

afterEach(function () {
    try {
        WorkspaceContext::clear();
    } catch (Throwable) {
    }
});

/** Un message : le Message-ID et les deux colonnes d'identifiant sont choisis par le test. */
function tcMessage(string $ws, ?string $idInjection, ?string $serveur, ?string $inscription = null, int $etape = 1, ?string $messageId = null): string
{
    $messageId ??= 'm-' . Str::random(10) . '@example.test';
    DB::table('envoi_messages')->insert([
        'workspace_id' => $ws, 'inscription_id' => $inscription ?? (string) Str::uuid(), 'etape' => $etape, 'statut' => 'envoye',
        'message_id' => $messageId, 'id_injection' => $idInjection, 'id_injection_serveur' => $serveur,
    ]);

    return $messageId;
}

function tcRegistreLocal(string $ws, string $cle): string
{
    $id = (string) Str::uuid();
    DB::table('envoi_injections_locales')->insert(['workspace_id' => $ws, 'id_injection' => $id, 'cle_idempotence' => $cle, 'adaptateur' => 'faux']);

    return $id;
}

function tcEnvoye(string $ws, string $messageId, string $idInjection): void
{
    DB::table('envoi_messages_envoyes')->insert([
        'workspace_id' => $ws, 'message_id_empreinte' => hash('sha256', 'mid:' . $messageId),
        'id_injection' => $idInjection, 'empreinte_destinataire' => hash('sha256', 'dest-' . Str::random(6)),
    ]);
}

/**
 * Jeu d'un espace : 2 messages de forme 2, 1 de forme 3, 1 repli, plus des lignes « nouvelle forme » qui ne comptent pas.
 * Les clés étrangères sont levées (superutilisateur, dans la transaction du test) : seules les colonnes lues comptent.
 */
function tcJeu(string $ws, int $forme2, int $forme3, int $replis): void
{
    DB::statement('SET LOCAL session_replication_role = replica');
    for ($i = 0; $i < $forme2; $i++) {
        tcMessage($ws, null, 'sim-' . Str::random(6));
    }
    for ($i = 0; $i < $forme3; $i++) {
        $ins = (string) Str::uuid();
        tcRegistreLocal($ws, 'seq-' . $ins . '-1');
        tcMessage($ws, 'sim-' . Str::random(6), null, $ins, 1);
    }
    for ($i = 0; $i < $replis; $i++) {
        $mid = tcMessage($ws, 'sim-' . Str::random(6), null);
        tcEnvoye($ws, $mid, (string) Str::uuid());
    }
    // Nouvelle forme : ne compte dans AUCUNE des trois.
    $ins = (string) Str::uuid();
    $uuid = tcRegistreLocal($ws, 'seq-' . $ins . '-1');
    $mid = tcMessage($ws, $uuid, 'sim-ok', $ins, 1);
    tcEnvoye($ws, $mid, $uuid);
    tcMessage($ws, $uuid, null);
}

/** Joue la commande dans un point de sauvegarde annulé ensuite ; rend [code de sortie, sortie, requêtes SQL vues]. */
function tcLancer(string $mode, string $ws, array $arguments): array
{
    $sql = [];
    DB::listen(function ($q) use (&$sql) {
        $sql[] = $q->sql;
    });
    $resultat = [];
    try {
        DB::transaction(function () use ($mode, $ws, $arguments, &$resultat) {
            if ($mode === 'app') {
                WorkspaceContext::set($ws);
                DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
            }
            $code = Artisan::call('envoi:transition:constat', $arguments);
            $resultat = [$code, Artisan::output()];
            throw new RuntimeException('__annule__');
        });
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== '__annule__') {
            throw $e;
        }
    }

    return [...$resultat, $sql];
}

/** @return array{forme2: int, forme3: int, replis: int}|null */
function tcLire(string $sortie, string $ws): ?array
{
    foreach (explode("\n", $sortie) as $ligne) {
        if (str_contains($ligne, $ws) && preg_match('/forme2=(\d+) forme3=(\d+) replis=(\d+)/', $ligne, $m) === 1) {
            return ['forme2' => (int) $m[1], 'forme3' => (int) $m[2], 'replis' => (int) $m[3]];
        }
    }

    return null;
}

test('L8-B les trois formes donnent des comptes exacts, sous le rôle applicatif ET sous le propriétaire', function () {
    $a = F::espace('zz-constat-a');
    tcJeu($a, 2, 3, 4);
    foreach (['app', 'proprio'] as $mode) {
        [$code, $sortie] = tcLancer($mode, $a, ['workspace' => $a]);
        expect($code)->toBe(0)->and(tcLire($sortie, $a))->toBe(['forme2' => 2, 'forme3' => 3, 'replis' => 4], $mode);
    }
});

test('L8-B aucune écriture, mode lecture seule demandé, sortie sans adresse', function () {
    $a = F::espace('zz-constat-ecriture');
    tcJeu($a, 1, 1, 1);
    $avant = [DB::table('envoi_messages')->count(), DB::table('envoi_injections_locales')->count(), DB::table('envoi_messages_envoyes')->count()];
    foreach (['app', 'proprio'] as $mode) {
        [, $sortie, $sql] = tcLancer($mode, $a, ['workspace' => $a]);
        foreach ($sql as $requete) {
            expect($requete)->not->toMatch('/^\s*(insert|update|delete|truncate|create|alter|drop)\b/i');
        }
        expect(implode("\n", $sql))->toContain('transaction_read_only')
            ->and($sortie)->not->toContain('@');
    }
    expect([DB::table('envoi_messages')->count(), DB::table('envoi_injections_locales')->count(), DB::table('envoi_messages_envoyes')->count()])->toBe($avant);
});

test('L8-B l’espace B n’est pas compté dans A, avec ou sans argument', function () {
    $a = F::espace('zz-constat-iso-a');
    $b = F::espace('zz-constat-iso-b');
    tcJeu($a, 1, 0, 0);
    tcJeu($b, 5, 6, 7);
    foreach (['app', 'proprio'] as $mode) {
        [, $seul] = tcLancer($mode, $a, ['workspace' => $a]);
        expect(tcLire($seul, $a))->toBe(['forme2' => 1, 'forme3' => 0, 'replis' => 0], $mode)
            ->and(tcLire($seul, $b))->toBeNull();
        [, $tous] = tcLancer($mode, $a, []);
        expect(tcLire($tous, $a))->toBe(['forme2' => 1, 'forme3' => 0, 'replis' => 0], $mode)
            ->and(tcLire($tous, $b))->toBe(['forme2' => 5, 'forme3' => 6, 'replis' => 7], $mode);
    }
});

test('L8-B un espace inexistant donne un code de sortie non nul', function () {
    [$code] = tcLancer('proprio', (string) Str::uuid(), ['workspace' => (string) Str::uuid()]);
    expect($code)->toBe(1);
});

test('L8-B la commande n’est pas planifiée', function () {
    $planifiees = collect(app(Schedule::class)->events())->map(fn ($e) => (string) $e->command)->implode("\n");
    expect($planifiees)->not->toContain('envoi:transition:constat');
});
