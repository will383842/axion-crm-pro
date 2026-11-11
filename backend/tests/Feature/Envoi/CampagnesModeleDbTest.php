<?php

use App\Envoi\Campagnes\DepotCampagnes;
use App\Envoi\Exceptions\CampagneRefusee;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const CAMP_STATUTS = ['brouillon', 'a_valider', 'programmee', 'en_cours', 'en_pause', 'terminee', 'arretee'];

function campEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Cp', 'slug' => 'cp-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** @return array<string, mixed> */
function campCible(): array
{
    return ['type' => 'liste', 'reference' => 'liste-fictive', 'libelle' => 'Liste fictive'];
}

function campCreer(string $ws, array $cible = [], array $saisie = []): string
{
    return app(DepotCampagnes::class)->creer($ws, 'Campagne fictive', $cible ?: campCible(), [(string) Str::uuid()], 2, $saisie, 'responsable');
}

/** Exécute dans un point de sauvegarde et rend le code SQLSTATE de l'échec (null = accepté, annulé ensuite). */
function campEchec(Closure $f): ?string
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

/** Exécute avec le rôle applicatif (non propriétaire, soumis à la RLS et aux droits par colonne). */
function campCommeApp(string $ws, Closure $f): mixed
{
    WorkspaceContext::set($ws);
    $role = '"' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"';
    DB::statement('SET LOCAL ROLE ' . $role);
    $r = $f();   // en cas d'échec, le point de sauvegarde de campEchec() annule aussi le SET LOCAL ROLE
    DB::statement('RESET ROLE');

    return $r;
}

test('R08 RLS : un espace ne lit jamais les campagnes, journaux ni codes de l\'autre', function () {
    [$a, $b] = [campEspace(), campEspace()];
    $camp = campCreer($a);
    app(DepotCampagnes::class)->transitionner($a, $camp, 'a_valider', 1, 'responsable', 'test', 'cle-rls-0001');
    DB::table('envoi_campagne_codes')->insert(['workspace_id' => $a, 'campagne_id' => $camp, 'empreinte' => hash('sha256', 'x'), 'expire_le' => now()]);

    foreach (['envoi_campagnes', 'envoi_campagnes_journal', 'envoi_campagne_codes'] as $t) {
        $vu = fn (string $ws) => campCommeApp($ws, fn () => DB::table($t)->count());
        expect($vu($a))->toBe(1, $t)->and($vu($b))->toBe(0, $t);
    }
    expect(campCommeApp($b, fn () => app(DepotCampagnes::class)->lire($b, $camp)))->toBeNull();
    // un espace n'écrit pas dans l'autre
    expect(campEchec(fn () => campCommeApp($b, fn () => DB::table('envoi_campagnes')->insert(['workspace_id' => $a, 'nom' => 'x', 'cible' => json_encode(campCible()), 'nb_etapes' => 1, 'cree_par' => 'y']))))->toBe('42501');
});

test('R08 transitions : la matrice PHP et la base disent la même chose pour les 49 couples', function () {
    $ws = campEspace();
    foreach (CAMP_STATUTS as $de) {
        foreach (CAMP_STATUTS as $vers) {
            if ($de === $vers) {
                continue;
            }
            $id = (string) Str::uuid();
            DB::table('envoi_campagnes')->insert([
                'id' => $id, 'workspace_id' => $ws, 'nom' => 'x', 'statut' => $de, 'cible' => json_encode(campCible()), 'nb_etapes' => 1,
                'cree_par' => 'r', 'sequence_id' => (string) Str::uuid(),   // séquence posée par le propriétaire : isole la règle de transition
            ]);
            $code = campEchec(fn () => DB::table('envoi_campagnes')->where('id', $id)->update(['statut' => $vers, 'version' => 2]));
            expect($code === null)->toBe(DepotCampagnes::autorisee($de, $vers), "{$de} -> {$vers}");
        }
    }
});

test('R08 transitions interdites : refusées par le dépôt, y compris en_cours sans séquence', function () {
    $ws = campEspace();
    $d = app(DepotCampagnes::class);
    $id = campCreer($ws);
    expect(fn () => $d->transitionner($ws, $id, 'programmee', 1, 'r', 'saut', 'cle-saut-0001'))->toThrow(CampagneRefusee::class, 'interdite');
    expect($d->transitionner($ws, $id, 'a_valider', 1, 'r', 'ok', 'cle-ok-000001'))->toBeTrue();
    expect($d->transitionner($ws, $id, 'programmee', 2, 'r', 'ok', 'cle-ok-000002'))->toBeTrue();
    expect(fn () => $d->transitionner($ws, $id, 'en_cours', 3, 'r', 'démarrer', 'cle-demarre-01'))->toThrow(CampagneRefusee::class, 'séquence');
    expect($d->lire($ws, $id)->statut)->toBe('programmee');
    // et la base refuse aussi, même en direct
    expect(campEchec(fn () => DB::table('envoi_campagnes')->where('id', $id)->update(['statut' => 'en_cours', 'version' => 4])))->toBe('23514');
});

test('R08 journal : ajout seul (UPDATE/DELETE/TRUNCATE refusés), idempotent par clé', function () {
    $ws = campEspace();
    $d = app(DepotCampagnes::class);
    $id = campCreer($ws);
    expect($d->transitionner($ws, $id, 'a_valider', 1, 'r', 'motif', 'cle-idem-0001'))->toBeTrue()
        ->and($d->transitionner($ws, $id, 'a_valider', 1, 'r', 'motif', 'cle-idem-0001'))->toBeFalse();
    expect(DB::table('envoi_campagnes_journal')->where('campagne_id', $id)->count())->toBe(1)
        ->and($d->lire($ws, $id)->version)->toBe(2);
    $l = DB::table('envoi_campagnes_journal')->where('campagne_id', $id)->first();
    expect($l->transition)->toBe('brouillon>a_valider');
    expect(campEchec(fn () => DB::table('envoi_campagnes_journal')->where('id', $l->id)->update(['motif' => 'falsifié'])))->toBe('P0001')
        ->and(campEchec(fn () => DB::table('envoi_campagnes_journal')->where('id', $l->id)->delete()))->toBe('P0001')
        ->and(campEchec(fn () => DB::statement('TRUNCATE envoi_campagnes_journal')))->toBe('P0001');
});

test('R08 saisie de plus de 64 Ko refusée par le dépôt ET par la base', function () {
    $ws = campEspace();
    $trop = ['texte' => str_repeat('a', 65537)];
    expect(fn () => campCreer($ws, saisie: $trop))->toThrow(CampagneRefusee::class, '64 Ko');
    expect(campCreer($ws, saisie: ['texte' => str_repeat('a', 60000)]))->not->toBe('');
    expect(campEchec(fn () => DB::table('envoi_campagnes')->insert([
        'workspace_id' => $ws, 'nom' => 'x', 'cible' => json_encode(campCible()), 'nb_etapes' => 1, 'cree_par' => 'r', 'saisie' => json_encode($trop),
    ])))->toBe('23514');
});

test('R08 aucune écriture de sequence_id : ni par le dépôt, ni par le rôle applicatif, aucun lien avec séquences/inscriptions', function () {
    $ws = campEspace();
    $id = campCreer($ws);
    expect(fn () => app(DepotCampagnes::class)->modifier($ws, $id, 1, ['sequence_id' => (string) Str::uuid()]))->toThrow(CampagneRefusee::class, 'non modifiable');
    expect(DB::table('envoi_campagnes')->where('id', $id)->value('sequence_id'))->toBeNull();
    // la source du dépôt n'écrit pas la colonne (elle ne la mentionne que pour la lire ou la refuser)
    $source = (string) file_get_contents(app_path('Envoi/Campagnes/DepotCampagnes.php'));
    expect($source)->not->toContain("'sequence_id' =>")->and($source)->not->toContain("['sequence_id']");
    // droits par colonne : le rôle applicatif ne peut ni poser ni changer sequence_id
    expect(campEchec(fn () => campCommeApp($ws, fn () => DB::table('envoi_campagnes')->where('id', $id)->update(['sequence_id' => (string) Str::uuid(), 'version' => 2]))))->toBe('42501');
    expect(campEchec(fn () => campCommeApp($ws, fn () => DB::table('envoi_campagnes')->insert([
        'workspace_id' => $ws, 'nom' => 'x', 'cible' => json_encode(campCible()), 'nb_etapes' => 1, 'cree_par' => 'r', 'sequence_id' => (string) Str::uuid(),
    ]))))->toBe('42501');
    $liens = DB::select("SELECT confrelid::regclass::text AS t FROM pg_constraint WHERE contype = 'f' AND conrelid IN ('envoi_campagnes'::regclass, 'envoi_campagnes_journal'::regclass, 'envoi_campagne_codes'::regclass)");
    expect(collect($liens)->pluck('t')->diff(['workspaces', 'envoi_campagnes'])->all())->toBe([]);
});

test('R08 cible : une adresse e-mail est refusée par le dépôt ET par la base', function () {
    $ws = campEspace();
    $mauvaise = ['type' => 'liste', 'reference' => 'liste-fictive', 'libelle' => 'contact@example.invalid'];
    expect(fn () => campCreer($ws, $mauvaise))->toThrow(CampagneRefusee::class, 'adresse');
    expect(fn () => campCreer($ws, ['type' => 'liste', 'reference' => 'x', 'libelle' => 'y', 'extra' => 'z']))->toThrow(CampagneRefusee::class);
    foreach ([$mauvaise, ['type' => 'liste', 'reference' => 'a@b.test', 'libelle' => 'y'], ['type' => 'inconnu', 'reference' => 'x', 'libelle' => 'y']] as $c) {
        expect(campEchec(fn () => DB::table('envoi_campagnes')->insert([
            'workspace_id' => $ws, 'nom' => 'x', 'cible' => json_encode($c), 'nb_etapes' => 1, 'cree_par' => 'r',
        ])))->toBe('23514');
    }
});

test('R08 verrou optimiste : une version périmée est un conflit (dépôt et base)', function () {
    $ws = campEspace();
    $d = app(DepotCampagnes::class);
    $id = campCreer($ws);
    expect($d->modifier($ws, $id, 1, ['nom' => 'Premier']))->toBe(2);
    expect(fn () => $d->modifier($ws, $id, 1, ['nom' => 'Périmé']))->toThrow(CampagneRefusee::class, 'Conflit')
        ->and(fn () => $d->transitionner($ws, $id, 'a_valider', 1, 'r', 'périmé', 'cle-perime-001'))->toThrow(CampagneRefusee::class, 'Conflit');
    expect($d->lire($ws, $id)->nom)->toBe('Premier')
        ->and(DB::table('envoi_campagnes_journal')->where('campagne_id', $id)->count())->toBe(0);
    expect(campEchec(fn () => DB::table('envoi_campagnes')->where('id', $id)->update(['nom' => 'saut', 'version' => 9])))->toBe('P0001');
});

test('R08 codes : seule l\'empreinte est stockée, ajout seul, tentatives qui ne font que monter', function () {
    $ws = campEspace();
    $camp = campCreer($ws);
    $ligne = ['workspace_id' => $ws, 'campagne_id' => $camp, 'expire_le' => now()->addMinutes(10)];
    expect(campEchec(fn () => DB::table('envoi_campagne_codes')->insert($ligne + ['empreinte' => '123456'])))->toBe('23514');   // un code en clair n'entre pas
    $n = DB::table('envoi_campagne_codes')->insertGetId($ligne + ['empreinte' => hash('sha256', 'code-fictif')]);
    expect(campEchec(fn () => DB::table('envoi_campagne_codes')->where('id', $n)->update(['tentatives' => 1, 'consomme_le' => now()])))->toBeNull()
        ->and(campEchec(fn () => DB::table('envoi_campagne_codes')->where('id', $n)->delete()))->toBe('P0001')
        ->and(campEchec(fn () => DB::table('envoi_campagne_codes')->where('id', $n)->update(['empreinte' => hash('sha256', 'autre')])))->toBe('P0001')
        ->and(campEchec(fn () => DB::statement('TRUNCATE envoi_campagne_codes')))->toBe('P0001');
    DB::table('envoi_campagne_codes')->where('id', $n)->update(['tentatives' => 3]);
    expect(campEchec(fn () => DB::table('envoi_campagne_codes')->where('id', $n)->update(['tentatives' => 2])))->toBe('P0001');
});
