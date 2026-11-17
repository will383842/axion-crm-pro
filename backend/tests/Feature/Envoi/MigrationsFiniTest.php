<?php

/**
 * FINI-M0B — migration seule : attestation serveur réel, boîte d'une inscription, codes d'ajout de boîte.
 * Chaque test joue DEUX fois : rôle applicatif (RLS armée, `SET LOCAL ROLE`) ET propriétaire (le filtre explicite par
 * `workspace_id` et les déclencheurs tiennent sans RLS). Aucune connexion réseau, aucune donnée de personne.
 */

use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

dataset('roles', ['rôle applicatif' => ['app'], 'propriétaire' => ['owner']]);

function fmEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Fm', 'slug' => 'fm-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** Exécute $f sous le rôle voulu et le contexte d'espace $ws. */
function fmComme(string $role, string $ws, Closure $f): mixed
{
    WorkspaceContext::set($ws);
    if ($role === 'app') {
        DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    }
    try {
        return $f();
    } finally {
        if ($role === 'app') {
            DB::statement('RESET ROLE');
        }
    }
}

/** Point de sauvegarde : SQLSTATE de l'échec, null si accepté (annulé ensuite). */
function fmEchec(Closure $f): ?string
{
    try {
        DB::transaction(function () use ($f) {
            $f();
            throw new RuntimeException('__annule__');
        });
    } catch (QueryException $e) {
        return (string) $e->getCode();
    } catch (RuntimeException $e) {
        return $e->getMessage() === '__annule__' ? null : 'autre:' . $e->getMessage();
    }

    return 'inattendu';
}

function fmBoite(string $ws): string
{
    $id = (string) Str::uuid();
    DB::table('envoi_boites')->insert([
        'id' => $id, 'workspace_id' => $ws, 'adresse' => 'fm-' . substr($id, 0, 8) . '@boite.example.test',
        'hote' => 'smtp.example.test', 'port' => 587, 'chiffrement' => 'starttls', 'identifiants_chiffres' => 'zz-chiffre',
    ]);

    return $id;
}

function fmInscription(string $ws): string
{
    $sequence = (string) Str::uuid();
    DB::table('envoi_sequences')->insert(['id' => $sequence, 'workspace_id' => $ws, 'nom' => 'ZZ fini']);
    $id = (string) Str::uuid();
    DB::table('envoi_inscriptions')->insert([
        'id' => $id, 'workspace_id' => $ws, 'sequence_id' => $sequence, 'contact_id' => 1, 'entreprise_id' => 1,
        'empreinte_adresse' => str_repeat('a', 64), 'prochaine_echeance' => now()->addDay(), 'version_figee' => '{}',
    ]);

    return $id;
}

function fmAttestation(string $ws, array $plus = []): array
{
    return $plus + ['workspace_id' => $ws, 'geste' => 'atteste', 'auteur' => 'responsable', 'motif' => 'Serveur installé et vérifié'];
}

test('objets présents : tables, colonne, déclencheur', function () {
    foreach (['envoi_attestations_serveur_reel', 'envoi_boites_codes_ajout'] as $t) {
        expect(DB::selectOne('SELECT to_regclass(?) AS t', [$t])->t)->not->toBeNull($t);
    }
    expect(DB::selectOne("SELECT 1 AS e FROM information_schema.columns WHERE table_name = 'envoi_inscriptions' AND column_name = 'boite_id'"))->not->toBeNull();
    expect(DB::selectOne("SELECT 1 AS e FROM pg_trigger WHERE tgname = 'envoi_inscriptions_boite_figee'"))->not->toBeNull();
});

test('attestation : ajout seul (UPDATE, DELETE, TRUNCATE refusés), motif sans « @ » ni trop court', function (string $role) {
    $ws = fmEspace();
    fmComme($role, $ws, function () use ($ws) {
        DB::table('envoi_attestations_serveur_reel')->insert(fmAttestation($ws));
        expect(fmEchec(fn () => DB::table('envoi_attestations_serveur_reel')->update(['geste' => 'retire'])))->toBeIn(['P0001', '42501']);
        expect(fmEchec(fn () => DB::table('envoi_attestations_serveur_reel')->delete()))->toBeIn(['P0001', '42501']);
        expect(fmEchec(fn () => DB::statement('TRUNCATE envoi_attestations_serveur_reel')))->toBeIn(['P0001', '42501']);
        expect(fmEchec(fn () => DB::table('envoi_attestations_serveur_reel')->insert(fmAttestation($ws, ['motif' => 'écrit à a@example.test pour accord']))))->toBe('23514');
        expect(fmEchec(fn () => DB::table('envoi_attestations_serveur_reel')->insert(fmAttestation($ws, ['motif' => 'court']))))->toBe('23514');
        expect(fmEchec(fn () => DB::table('envoi_attestations_serveur_reel')->insert(fmAttestation($ws, ['geste' => 'autre']))))->toBe('23514');
        expect(DB::table('envoi_attestations_serveur_reel')->where('workspace_id', $ws)->count())->toBe(1);
    });
})->with('roles');

test('inscription : la boîte d\'un autre espace est refusée, la boîte posée ne change plus', function (string $role) {
    [$a, $b] = [fmEspace(), fmEspace()];
    $boiteA = fmBoite($a);
    $boiteA2 = fmBoite($a);
    $boiteB = fmBoite($b);
    $insc = fmInscription($a);
    expect(DB::table('envoi_inscriptions')->where('id', $insc)->value('boite_id'))->toBeNull();

    fmComme($role, $a, function () use ($a, $insc, $boiteA, $boiteA2, $boiteB) {
        $maj = fn (string $boite) => DB::table('envoi_inscriptions')->where('workspace_id', $a)->where('id', $insc)->update(['boite_id' => $boite]);
        expect(fmEchec(fn () => $maj($boiteB)))->toBe('23503');
        $maj($boiteA);
        expect(DB::table('envoi_inscriptions')->where('id', $insc)->value('boite_id'))->toBe($boiteA);
        expect(fmEchec(fn () => $maj($boiteA2)))->toBe('P0001');
        expect(fmEchec(fn () => DB::table('envoi_inscriptions')->where('id', $insc)->update(['boite_id' => null])))->toBe('P0001');
        $maj($boiteA); // même valeur : permis
    });
})->with('roles');

test('codes d\'ajout : tentatives plafonnées à 5, DELETE refusé, consommation unique', function (string $role) {
    $ws = fmEspace();
    $user = (string) Str::uuid();
    DB::table('users')->insert(['id' => $user, 'email' => 'fm-' . substr($user, 0, 8) . '@example.test', 'name' => 'Fm', 'created_at' => now(), 'updated_at' => now()]);
    $ligne = fn (array $plus = []) => ['workspace_id' => $ws, 'user_id' => $user, 'empreinte' => str_repeat('b', 64), 'demande_empreinte' => str_repeat('c', 64), 'expire_le' => now()->addMinutes(10)] + $plus;

    fmComme($role, $ws, function () use ($ws, $ligne) {
        expect(fmEchec(fn () => DB::table('envoi_boites_codes_ajout')->insert($ligne(['tentatives' => 6]))))->toBe('23514');
        DB::table('envoi_boites_codes_ajout')->insert($ligne(['tentatives' => 5]));
        expect(fmEchec(fn () => DB::table('envoi_boites_codes_ajout')->update(['tentatives' => 6])))->toBe('23514');
        expect(fmEchec(fn () => DB::table('envoi_boites_codes_ajout')->delete()))->toBeIn(['P0001', '42501']);
        expect(fmEchec(fn () => DB::statement('TRUNCATE envoi_boites_codes_ajout')))->toBeIn(['P0001', '42501']);
        $prises = fn () => DB::table('envoi_boites_codes_ajout')->where('workspace_id', $ws)->whereNull('consomme_le')->update(['consomme_le' => now()]);
        expect([$prises(), $prises()])->toBe([1, 0]);
    });
})->with('roles');

test('cloisonnement : l\'espace B ne voit rien de A (rôle applicatif : RLS ; propriétaire : filtre explicite)', function (string $role) {
    [$a, $b] = [fmEspace(), fmEspace()];
    DB::table('envoi_attestations_serveur_reel')->insert(fmAttestation($a));
    $boite = fmBoite($a);
    $insc = fmInscription($a);
    DB::table('envoi_inscriptions')->where('id', $insc)->update(['boite_id' => $boite]);

    $vu = fn (string $ws) => fmComme($role, $ws, fn () => [
        DB::table('envoi_attestations_serveur_reel')->where('workspace_id', $ws)->count(),
        DB::table('envoi_inscriptions')->where('workspace_id', $ws)->whereNotNull('boite_id')->count(),
    ]);
    expect($vu($a))->toBe([1, 1])->and($vu($b))->toBe([0, 0]);
    if ($role === 'app') {
        // sans filtre explicite, la RLS seule isole B
        expect(fmComme('app', $b, fn () => DB::table('envoi_attestations_serveur_reel')->count()))->toBe(0);
        expect(fmComme('app', $b, fn () => fmEchec(fn () => DB::table('envoi_attestations_serveur_reel')->insert(fmAttestation($a)))))->toBe('42501');
    }
})->with('roles');
