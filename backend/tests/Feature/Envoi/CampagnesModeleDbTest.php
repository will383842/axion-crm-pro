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

const CAMP_CLE = 'cle-de-test-campagne-seulement-0123456789';

/** Pose la clé HMAC des codes (fictive, de test) comme le ferait l'environnement. */
function campCle(): void
{
    config(['envoi.cles_codes_campagne' => ['1' => CAMP_CLE, '2' => 'seconde-cle-de-test-seulement-9876543210'], 'envoi.cle_codes_campagne_version' => 1]);
}

/** Crée une campagne dans l'état voulu par le CHEMIN LÉGAL (l'insertion directe d'un autre état est refusée). */
function campEtat(string $ws, string $statut): string
{
    $chemins = [
        'brouillon' => [], 'a_valider' => ['a_valider'], 'programmee' => ['a_valider', 'programmee'],
        'en_cours' => ['a_valider', 'programmee', 'en_cours'], 'en_pause' => ['a_valider', 'programmee', 'en_cours', 'en_pause'],
        'terminee' => ['a_valider', 'programmee', 'en_cours', 'terminee'], 'arretee' => ['a_valider', 'programmee', 'en_cours', 'arretee'],
    ];
    $id = (string) Str::uuid();
    DB::table('envoi_campagnes')->insert(['id' => $id, 'workspace_id' => $ws, 'nom' => 'x', 'cible' => json_encode(campCible()), 'nb_etapes' => 1, 'cree_par' => 'r']);
    DB::table('envoi_campagnes')->where('id', $id)->update(['sequence_id' => (string) Str::uuid(), 'version' => 2]);   // propriétaire : isole la règle de transition
    $v = 2;
    foreach ($chemins[$statut] as $etape) {
        DB::table('envoi_campagnes')->where('id', $id)->update(['statut' => $etape, 'version' => ++$v]);
    }

    return $id;
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
    campCle();
    $camp = campCreer($a);
    app(DepotCampagnes::class)->transitionner($a, $camp, 'a_valider', 1, 'responsable', 'test', 'cle-rls-0001');
    DB::table('envoi_campagne_codes')->insert(['workspace_id' => $a, 'campagne_id' => $camp, 'empreinte' => hash_hmac('sha256', 'x', CAMP_CLE), 'cle_version' => 1, 'expire_le' => now()]);

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
            $id = campEtat($ws, $de);
            $v = (int) DB::table('envoi_campagnes')->where('id', $id)->value('version');
            $code = campEchec(fn () => DB::table('envoi_campagnes')->where('id', $id)->update(['statut' => $vers, 'version' => $v + 1]));
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
    // version lue comparée : une version périmée est un conflit AVANT même d'examiner la transition
    expect(fn () => $d->transitionner($ws, $id, 'terminee', 1, 'r', 'périmé', 'cle-version-001'))->toThrow(CampagneRefusee::class, 'Conflit');
    // clé d'idempotence réutilisée sur une AUTRE campagne = refus explicite (et non un « false » silencieux)
    $autre = campCreer($ws);
    expect($d->transitionner($ws, $autre, 'a_valider', 1, 'r', 'ok', 'cle-reutilisee-1'))->toBeTrue();
    $troisieme = campCreer($ws);
    expect(fn () => $d->transitionner($ws, $troisieme, 'a_valider', 1, 'r', 'ok', 'cle-reutilisee-1'))->toThrow(CampagneRefusee::class, 'd\'idempotence')
        ->and($d->lire($ws, $troisieme)->statut)->toBe('brouillon');
});

test('R08 INSERT : toute campagne naît brouillon, version 1, sans séquence — SOUS LE RÔLE APPLICATIF comme pour le propriétaire', function () {
    $ws = campEspace();
    $ligne = fn (array $plus = []) => ['workspace_id' => $ws, 'nom' => 'x', 'cible' => json_encode(campCible()), 'nb_etapes' => 1, 'cree_par' => 'r'] + $plus;
    foreach (['a_valider', 'programmee', 'en_cours', 'en_pause', 'terminee', 'arretee'] as $statut) {
        expect(campEchec(fn () => campCommeApp($ws, fn () => DB::table('envoi_campagnes')->insert($ligne(['statut' => $statut])))))->toBe('P0001', "app {$statut}")
            ->and(campEchec(fn () => DB::table('envoi_campagnes')->insert($ligne(['statut' => $statut]))))->toBe('P0001', "propriétaire {$statut}");
    }
    expect(campEchec(fn () => campCommeApp($ws, fn () => DB::table('envoi_campagnes')->insert($ligne(['version' => 7])))))->toBe('P0001');
    expect(campEchec(fn () => campCommeApp($ws, fn () => DB::table('envoi_campagnes')->insert($ligne(['statut' => 'brouillon', 'version' => 1])))))->toBeNull();
    expect(campEchec(fn () => campCommeApp($ws, fn () => DB::table('envoi_campagnes')->insert($ligne()))))->toBeNull();
});

test('R08 contrainte : en_cours, en_pause, terminee, arretee exigent sequence_id (même déclencheurs suspendus)', function () {
    $ws = campEspace();
    foreach (['en_cours', 'en_pause', 'terminee', 'arretee'] as $statut) {
        $code = campEchec(function () use ($ws, $statut) {
            DB::statement("SET LOCAL session_replication_role = 'replica'");   // suspend les déclencheurs : seule la CONTRAINTE parle
            DB::table('envoi_campagnes')->insert(['workspace_id' => $ws, 'nom' => 'x', 'statut' => $statut, 'cible' => json_encode(campCible()), 'nb_etapes' => 1, 'cree_par' => 'r']);
        });
        expect($code)->toBe('23514', $statut);
    }
});

test('R08 aucune suppression : DELETE et TRUNCATE de envoi_campagnes refusés, même au propriétaire', function () {
    $ws = campEspace();
    $id = campCreer($ws);
    expect(campEchec(fn () => DB::table('envoi_campagnes')->where('id', $id)->delete()))->toBe('P0001')
        ->and(campEchec(fn () => DB::statement('TRUNCATE envoi_campagnes CASCADE')))->toBe('P0001');
});

test('R08 journal : une ligne fictive est refusée par la base (matrice ET état réel de la campagne)', function () {
    $ws = campEspace();
    $id = campCreer($ws);   // brouillon
    $ligne = fn (string $t, string $cle) => ['workspace_id' => $ws, 'campagne_id' => $id, 'transition' => $t, 'auteur' => 'r', 'motif' => 'm', 'cle_idempotence' => $cle];
    foreach (['brouillon>terminee', 'terminee>brouillon', 'brouillon>a_valider', 'x>y'] as $i => $t) {   // hors matrice, ou campagne pas dans l'état d'arrivée
        expect(campEchec(fn () => campCommeApp($ws, fn () => DB::table('envoi_campagnes_journal')->insert($ligne($t, "cle-fictive-{$i}0")))))->toBe('P0001', $t);
    }
    DB::table('envoi_campagnes')->where('id', $id)->update(['statut' => 'a_valider', 'version' => 2]);
    expect(campEchec(fn () => campCommeApp($ws, fn () => DB::table('envoi_campagnes_journal')->insert($ligne('brouillon>a_valider', 'cle-vraie-0001')))))->toBeNull();
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

test('R08 saisie de plus de 64 Ko refusée par le dépôt ET par la base ; la borne exacte est testée des deux côtés', function () {
    $ws = campEspace();
    $trop = ['texte' => str_repeat('a', 65537)];
    expect(fn () => campCreer($ws, saisie: $trop))->toThrow(CampagneRefusee::class, '64 Ko');
    expect(campCreer($ws, saisie: ['texte' => str_repeat('a', 60000)]))->not->toBe('');
    expect(campEchec(fn () => DB::table('envoi_campagnes')->insert([
        'workspace_id' => $ws, 'nom' => 'x', 'cible' => json_encode(campCible()), 'nb_etapes' => 1, 'cree_par' => 'r', 'saisie' => json_encode($trop),
    ])))->toBe('23514');
    // BASE : le texte jsonb ajoute une espace après « : » ; `{"t": "…"}` = 9 octets + le texte. 65536 passe, 65537 non.
    $brut = fn (int $octets) => ['workspace_id' => $ws, 'nom' => 'x', 'cible' => json_encode(campCible()), 'nb_etapes' => 1, 'cree_par' => 'r',
        'saisie' => '{"t":"' . str_repeat('a', $octets - 9) . '"}'];
    expect(campEchec(fn () => DB::table('envoi_campagnes')->insert($brut(65536))))->toBeNull()
        ->and(campEchec(fn () => DB::table('envoi_campagnes')->insert($brut(65537))))->toBe('23514');
    // DÉPÔT : il mesure comme la base (jsonb) et garde une marge ; à sa borne exacte, accepté ; un octet de plus, refusé proprement.
    $limite = DepotCampagnes::SAISIE_MAX_OCTETS - DepotCampagnes::SAISIE_MARGE_OCTETS;
    $pile = ['t' => str_repeat('a', $limite - 9)];
    expect(DepotCampagnes::tailleJsonb($pile))->toBe($limite)
        ->and(campCreer($ws, saisie: $pile))->not->toBe('');
    expect(fn () => campCreer($ws, saisie: ['t' => str_repeat('a', $limite - 8)]))->toThrow(CampagneRefusee::class, '64 Ko');
});

test('R08 la mesure PHP du texte jsonb est celle de la base (espaces après « : » et « , »)', function () {
    foreach ([['a' => 1], ['a' => [1, 2, 3], 'b' => ['c' => 'd, e: f'], 'g' => true, 'h' => null], ['é' => 'à "guillemets" \\ barre', 'k' => []], []] as $v) {
        $base = (int) DB::selectOne('SELECT octet_length(?::jsonb::text) AS n', [json_encode($v ?: new stdClass)])->n;
        expect(DepotCampagnes::tailleJsonb($v))->toBe($base, json_encode($v));
    }
});

test('R08 une erreur 23514 de la base (écart de mesure) devient un refus propre, pas une QueryException', function () {
    $ws = campEspace();
    // 1.0e+25 : 7 octets en PHP, 26 dans le texte jsonb => la mesure PHP passe, la base refuse (23514) : refus propre attendu.
    $pieges = ['n' => array_fill(0, 4000, 1.0e+25)];
    expect(fn () => campCreer($ws, saisie: $pieges))->toThrow(CampagneRefusee::class, '64 Ko');
    $id = campCreer($ws);
    expect(fn () => app(DepotCampagnes::class)->modifier($ws, $id, 1, ['saisie' => $pieges]))->toThrow(CampagneRefusee::class, '64 Ko');
});

test('R08 cible : même mesure jsonb que la base, borne exacte, refus propre', function () {
    $ws = campEspace();
    $limite = DepotCampagnes::CIBLE_MAX_OCTETS - DepotCampagnes::CIBLE_MARGE_OCTETS;
    $cible = fn (int $n) => ['type' => 'liste', 'reference' => 'r', 'libelle' => str_repeat('b', $n)];
    $fixe = DepotCampagnes::tailleJsonb($cible(0));
    expect(campCreer($ws, $cible($limite - $fixe)))->not->toBe('');
    expect(fn () => campCreer($ws, $cible($limite - $fixe + 1)))->toThrow(CampagneRefusee::class, 'cible');
});

test('R08 contrôles du dépôt : 50 boîtes au plus, chaînes, saisie en liste refusée', function () {
    $ws = campEspace();
    $d = app(DepotCampagnes::class);
    $uuids = fn (int $n) => array_map(fn () => (string) Str::uuid(), range(1, $n));
    expect($d->creer($ws, 'n', campCible(), $uuids(50), 1, [], 'r'))->not->toBe('');
    expect(fn () => $d->creer($ws, 'n', campCible(), $uuids(51), 1, [], 'r'))->toThrow(CampagneRefusee::class, '50 boîtes');
    foreach ([['type' => 'liste', 'reference' => 12, 'libelle' => 'l'], ['type' => 'liste', 'reference' => 'r', 'libelle' => ['x']], ['type' => 'liste', 'reference' => '', 'libelle' => 'l']] as $c) {
        expect(fn () => $d->creer($ws, 'n', $c, [], 1, [], 'r'))->toThrow(CampagneRefusee::class, 'chaînes');
    }
    expect(fn () => $d->creer($ws, 'n', campCible(), [], 1, ['a', 'b'], 'r'))->toThrow(CampagneRefusee::class, 'objet');
    $id = campCreer($ws);
    expect(fn () => $d->modifier($ws, $id, 1, ['saisie' => ['a']]))->toThrow(CampagneRefusee::class, 'objet')
        ->and(fn () => $d->modifier($ws, $id, 1, ['saisie' => 'texte']))->toThrow(CampagneRefusee::class, 'objet')
        ->and($d->modifier($ws, $id, 1, ['saisie' => []]))->toBe(2);   // objet vide = accepté
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

test('R08 codes : seule l\'empreinte HMAC est stockée, avec sa version de clé ; ajout seul, tentatives qui ne font que monter', function () {
    campCle();
    $ws = campEspace();
    $camp = campCreer($ws);
    $ligne = ['workspace_id' => $ws, 'campagne_id' => $camp, 'expire_le' => now()->addMinutes(10), 'cle_version' => 1];
    expect(campEchec(fn () => DB::table('envoi_campagne_codes')->insert($ligne + ['empreinte' => '123456'])))->toBe('23514');   // un code en clair n'entre pas
    expect(campEchec(fn () => DB::table('envoi_campagne_codes')->insert(['cle_version' => 0] + $ligne + ['empreinte' => hash_hmac('sha256', 'c', CAMP_CLE)])))->toBe('23514');
    $n = DB::table('envoi_campagne_codes')->insertGetId($ligne + ['empreinte' => hash_hmac('sha256', 'code-fictif', CAMP_CLE)]);
    expect(campEchec(fn () => DB::table('envoi_campagne_codes')->where('id', $n)->update(['tentatives' => 1, 'consomme_le' => now()])))->toBeNull()
        ->and(campEchec(fn () => DB::table('envoi_campagne_codes')->where('id', $n)->delete()))->toBe('P0001')
        ->and(campEchec(fn () => DB::table('envoi_campagne_codes')->where('id', $n)->update(['empreinte' => hash_hmac('sha256', 'autre', CAMP_CLE)])))->toBe('P0001')
        ->and(campEchec(fn () => DB::table('envoi_campagne_codes')->where('id', $n)->update(['cle_version' => 2])))->toBe('P0001')
        ->and(campEchec(fn () => DB::statement('TRUNCATE envoi_campagne_codes')))->toBe('P0001');
    DB::table('envoi_campagne_codes')->where('id', $n)->update(['tentatives' => 3]);
    expect(campEchec(fn () => DB::table('envoi_campagne_codes')->where('id', $n)->update(['tentatives' => 2])))->toBe('P0001')
        ->and(campEchec(fn () => DB::table('envoi_campagne_codes')->where('id', $n)->update(['tentatives' => 6])))->toBe('23514');
});

test('R08 codes : l\'empreinte est un HMAC-SHA256 à clé serveur (jamais un SHA nu), clé absente = porte fermée', function () {
    campCle();
    $e1 = DepotCampagnes::empreinteCode('123456', 1);
    expect($e1)->toBe(hash_hmac('sha256', '123456', CAMP_CLE))->not->toBe(hash('sha256', '123456'))
        ->and(DepotCampagnes::empreinteCode('123456', 2))->not->toBe($e1);
    config(['envoi.cles_codes_campagne' => []]);
    expect(fn () => DepotCampagnes::empreinteCode('123456', 1))->toThrow(CampagneRefusee::class, 'clé');
    config(['envoi.cles_codes_campagne' => ['1' => 'courte']]);
    expect(fn () => DepotCampagnes::empreinteCode('123456', 1))->toThrow(CampagneRefusee::class, 'clé');
    // aucune clé dans la configuration livrée ni de SHA nu dans le code source
    expect((string) file_get_contents(app_path('Envoi/Campagnes/DepotCampagnes.php')))->not->toContain("hash('sha256'");
    expect((string) file_get_contents(config_path('envoi.php')))->toContain("env('ENVOI_CLES_CODES_CAMPAGNE'");
});

test('R08 codes : consommation atomique — vivant seulement (non consommé, non expiré, tentatives sous le plafond)', function () {
    campCle();
    $ws = campEspace();
    $d = app(DepotCampagnes::class);
    $camp = campCreer($ws);
    $d->emettreCode($ws, $camp, '123456');
    expect($d->consommerCode($ws, $camp, '000000'))->toBeFalse()   // mauvais code : une tentative de plus
        ->and((int) DB::table('envoi_campagne_codes')->where('campagne_id', $camp)->value('tentatives'))->toBe(1)
        ->and($d->consommerCode($ws, $camp, '123456'))->toBeTrue()
        ->and($d->consommerCode($ws, $camp, '123456'))->toBeFalse();   // déjà consommé

    // expiré
    $expire = campCreer($ws);
    $d->emettreCode($ws, $expire, '222222', -1);
    expect($d->consommerCode($ws, $expire, '222222'))->toBeFalse();
    expect(campEchec(fn () => DB::table('envoi_campagne_codes')->where('campagne_id', $expire)->update(['consomme_le' => now()])))->toBe('P0001');   // la base aussi

    // épuisé : 5 mauvais essais puis le BON code est refusé
    $epuise = campCreer($ws);
    $d->emettreCode($ws, $epuise, '333333');
    foreach (range(1, DepotCampagnes::CODE_TENTATIVES_MAX) as $i) {
        expect($d->consommerCode($ws, $epuise, '999999'))->toBeFalse();
    }
    expect($d->consommerCode($ws, $epuise, '333333'))->toBeFalse()
        ->and(campEchec(fn () => DB::table('envoi_campagne_codes')->where('campagne_id', $epuise)->update(['consomme_le' => now()])))->toBe('P0001');
    // le plafond du dépôt est celui de la base
    expect(DepotCampagnes::CODE_TENTATIVES_MAX)->toBe(5);
});

test('R08 codes : au plus 10 codes par campagne, le code doit avoir 6 chiffres', function () {
    campCle();
    $ws = campEspace();
    $d = app(DepotCampagnes::class);
    $camp = campCreer($ws);
    foreach (range(1, 10) as $i) {
        $d->emettreCode($ws, $camp, sprintf('%06d', $i));
    }
    expect(fn () => $d->emettreCode($ws, $camp, '000011'))->toThrow(CampagneRefusee::class, '10 codes');
    expect(fn () => $d->emettreCode($ws, campCreer($ws), '12ab56'))->toThrow(CampagneRefusee::class, '6 chiffres');
});

test('R08 codes : deux consommations CONCURRENTES du même code = une seule réussit (processus réels)', function () {
    campCle();
    $proprio = DB::connection('pgsql_owner');
    $ws = (string) Str::uuid();
    $camp = (string) Str::uuid();
    $proprio->table('workspaces')->insert(['id' => $ws, 'slug' => 'zz-cc-' . substr($ws, 0, 6), 'name' => 'Concurrence codes', 'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $proprio->table('envoi_campagnes')->insert(['id' => $camp, 'workspace_id' => $ws, 'nom' => 'x', 'cible' => json_encode(campCible()), 'nb_etapes' => 1, 'cree_par' => 'r']);
    $proprio->table('envoi_campagne_codes')->insert(['workspace_id' => $ws, 'campagne_id' => $camp, 'empreinte' => DepotCampagnes::empreinteCode('424242', 1), 'cle_version' => 1, 'expire_le' => now()->addMinutes(10)]);
    putenv('ENVOI_CLES_CODES_CAMPAGNE=' . json_encode(['1' => CAMP_CLE]));
    try {
        $depart = microtime(true) + 4;
        $procs = [];
        foreach ([1, 2, 3] as $i) {
            $procs[$i] = proc_open([PHP_BINARY, base_path('tests/Support/campagne-code-concurrent.php'), $ws, $camp, '424242', sprintf('%.4F', $depart)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes[$i]);
        }
        $reussites = 0;
        foreach ($procs as $i => $p) {
            $sortie = stream_get_contents($tubes[$i][1]) . stream_get_contents($tubes[$i][2]);
            $code = proc_close($p);
            expect($code)->toBeIn([0, 3], $sortie);
            $reussites += $code === 0 ? 1 : 0;
        }
        expect($reussites)->toBe(1);
        expect($proprio->table('envoi_campagne_codes')->where('campagne_id', $camp)->whereNotNull('consomme_le')->count())->toBe(1);
    } finally {
        putenv('ENVOI_CLES_CODES_CAMPAGNE');
        $proprio->statement("SET session_replication_role = 'replica'");
        $proprio->table('envoi_campagne_codes')->where('workspace_id', $ws)->delete();
        $proprio->table('envoi_campagnes')->where('workspace_id', $ws)->delete();
        $proprio->table('workspaces')->where('id', $ws)->delete();
        $proprio->statement("SET session_replication_role = 'origin'");
    }
});
