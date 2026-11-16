<?php

use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Lot C3-0 — boîte de retraits, registre Kumo durable, couples portés (migration SEULE, INERTE).
 * Chaque test de sécurité est rejoué SOUS LE RÔLE APPLICATIF (SET LOCAL ROLE + WorkspaceContext) ET SOUS LE PROPRIÉTAIRE.
 * Données fictives, aucune adresse en clair : seulement des empreintes.
 */
uses(TestCase::class, RefreshDatabase::class);

const C3_MODES = ['app', 'proprio'];
const C3_MIGRATIONS = [
    '2026_12_05_000300_envoi_retraits_demandes.php',
    '2026_12_05_000310_envoi_registre_kumo.php',
    '2026_12_05_000320_envoi_couples_reels_portes.php',
];

function c3Espace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'C3', 'slug' => 'c3-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function c3Role(): string
{
    return (string) config('database.connections.pgsql_app.username');
}

/** SQLSTATE de $f joué dans un point de sauvegarde annulé ensuite (null = accepté). */
function c3Code(Closure $f): ?string
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

function c3Sous(string $mode, string $ws, Closure $f): ?string
{
    return c3Code(function () use ($mode, $ws, $f) {
        if ($mode === 'app') {
            WorkspaceContext::set($ws);
            DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', c3Role()) . '"');
        } else {
            WorkspaceContext::set($ws);
        }
        $f();
    });
}

function c3Retrait(string $ws, array $plus = []): array
{
    return $plus + ['workspace_id' => $ws, 'empreinte_adresse' => hash('sha256', Str::uuid()->toString())];
}

function c3Injection(string $ws, array $plus = []): array
{
    return $plus + ['workspace_id' => $ws, 'cle_idempotence' => 'cle-' . Str::random(8), 'id_injection' => (string) Str::uuid(),
        'etat' => 'a_injecter', 'instant' => 1000, 'jeton' => bin2hex(random_bytes(16))];
}

test('C3-0 les tables et colonnes attendues existent, RLS activée et FORCÉE', function () {
    $attendu = [
        'envoi_retraits_demandes' => ['id', 'workspace_id', 'empreinte_adresse', 'statut', 'commande_id', 'cibles_nb', 'tentatives', 'cree_le', 'traite_le'],
        'envoi_kumo_injections' => ['workspace_id', 'cle_idempotence', 'id_injection', 'etat', 'reporte_au', 'instant', 'jeton', 'code', 'seq'],
        'envoi_kumo_etat' => ['workspace_id', 'en_pause', 'derniere_seq', 'remise_5xx_le'],
        'envoi_kumo_5xx' => ['id', 'workspace_id', 'instant'],
    ];
    foreach ($attendu as $table => $colonnes) {
        $reel = array_column(DB::select("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ?", [$table]), 'column_name');
        expect($reel)->toEqualCanonicalizing($colonnes, $table);
        $rls = DB::selectOne('SELECT relrowsecurity AS r, relforcerowsecurity AS f FROM pg_class WHERE oid = ?::regclass', [$table]);
        expect($rls->r)->toBeTrue($table)->and($rls->f)->toBeTrue($table);
    }
});

test('C3-0 droits exacts du rôle applicatif', function () {
    $role = c3Role();
    $droit = fn (string $t, string $d): bool => DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS p', [$role, "public.{$t}", $d])->p;
    $col = fn (string $t, string $c, string $d): bool => DB::selectOne('SELECT has_column_privilege(?, ?, ?, ?) AS p', [$role, "public.{$t}", $c, $d])->p;

    expect($droit('envoi_retraits_demandes', 'SELECT'))->toBeTrue()
        ->and($droit('envoi_retraits_demandes', 'INSERT'))->toBeTrue()
        ->and($droit('envoi_retraits_demandes', 'DELETE'))->toBeFalse()
        ->and($droit('envoi_retraits_demandes', 'TRUNCATE'))->toBeFalse();
    foreach (['statut', 'commande_id', 'cibles_nb', 'tentatives', 'traite_le'] as $c) {
        expect($col('envoi_retraits_demandes', $c, 'UPDATE'))->toBeTrue($c);
    }
    foreach (['id', 'workspace_id', 'empreinte_adresse', 'cree_le'] as $c) {
        expect($col('envoi_retraits_demandes', $c, 'UPDATE'))->toBeFalse($c);
    }
    foreach (['envoi_kumo_injections', 'envoi_kumo_etat'] as $t) {
        expect($droit($t, 'SELECT'))->toBeTrue($t)->and($droit($t, 'INSERT'))->toBeTrue($t)->and($droit($t, 'UPDATE'))->toBeTrue($t)
            ->and($droit($t, 'DELETE'))->toBeFalse($t)->and($droit($t, 'TRUNCATE'))->toBeFalse($t);
    }
    expect($droit('envoi_kumo_5xx', 'SELECT'))->toBeTrue()->and($droit('envoi_kumo_5xx', 'INSERT'))->toBeTrue()
        ->and($droit('envoi_kumo_5xx', 'UPDATE'))->toBeFalse()->and($droit('envoi_kumo_5xx', 'DELETE'))->toBeFalse()
        ->and(DB::selectOne("SELECT has_sequence_privilege(?, 'public.envoi_kumo_5xx_id_seq', 'USAGE') AS p", [$role])->p)->toBeTrue();
});

test('C3-0 retraits : UPDATE de empreinte_adresse refusé (colonne sans droit, et figée même pour le propriétaire)', function (string $mode) {
    $ws = c3Espace();
    DB::table('envoi_retraits_demandes')->insert(c3Retrait($ws));
    expect(c3Sous($mode, $ws, fn () => DB::statement('UPDATE envoi_retraits_demandes SET empreinte_adresse = ?', [str_repeat('a', 64)])))->not->toBeNull();
})->with(C3_MODES);

test('C3-0 retraits : DELETE et TRUNCATE refusés', function (string $mode) {
    $ws = c3Espace();
    DB::table('envoi_retraits_demandes')->insert(c3Retrait($ws));
    expect(c3Sous($mode, $ws, fn () => DB::statement('DELETE FROM envoi_retraits_demandes')))->not->toBeNull();
    expect(c3Sous($mode, $ws, fn () => DB::statement('TRUNCATE envoi_retraits_demandes')))->not->toBeNull();
    expect(DB::table('envoi_retraits_demandes')->count())->toBe(1);
})->with(C3_MODES);

test('C3-0 retraits : en_attente -> envoye accepté, mais envoye -> en_attente et sans_objet -> envoye refusés', function (string $mode) {
    $ws = c3Espace();
    $id = (string) Str::uuid();
    DB::table('envoi_retraits_demandes')->insert(c3Retrait($ws, ['id' => $id]));
    expect(c3Sous($mode, $ws, fn () => DB::statement("UPDATE envoi_retraits_demandes SET statut = 'envoye', traite_le = now(), cibles_nb = 3 WHERE id = ?", [$id])))->toBeNull();

    DB::statement("UPDATE envoi_retraits_demandes SET statut = 'envoye' WHERE id = ?", [$id]);
    expect(c3Sous($mode, $ws, fn () => DB::statement("UPDATE envoi_retraits_demandes SET statut = 'en_attente' WHERE id = ?", [$id])))->not->toBeNull();
    expect(c3Sous($mode, $ws, fn () => DB::statement('UPDATE envoi_retraits_demandes SET tentatives = 9 WHERE id = ?', [$id])))->not->toBeNull();

    $id2 = (string) Str::uuid();
    DB::table('envoi_retraits_demandes')->insert(c3Retrait($ws, ['id' => $id2]));
    DB::statement("UPDATE envoi_retraits_demandes SET statut = 'sans_objet' WHERE id = ?", [$id2]);
    expect(c3Sous($mode, $ws, fn () => DB::statement("UPDATE envoi_retraits_demandes SET statut = 'envoye' WHERE id = ?", [$id2])))->not->toBeNull();
})->with(C3_MODES);

test('C3-0 retraits : CHECK sur empreinte, statut et cibles_nb', function (string $mode) {
    $ws = c3Espace();
    foreach ([['empreinte_adresse' => 'XYZ'], ['empreinte_adresse' => strtoupper(hash('sha256', 'x'))], ['statut' => 'autre'], ['cibles_nb' => 101], ['cibles_nb' => -1]] as $mauvais) {
        expect(c3Sous($mode, $ws, fn () => DB::table('envoi_retraits_demandes')->insert(c3Retrait($ws, $mauvais))))->not->toBeNull(json_encode($mauvais));
    }
    expect(c3Sous($mode, $ws, fn () => DB::table('envoi_retraits_demandes')->insert(c3Retrait($ws, ['cibles_nb' => 100]))))->toBeNull();
})->with(C3_MODES);

test('C3-0 registre Kumo : DELETE et TRUNCATE refusés sur les trois tables', function (string $mode) {
    $ws = c3Espace();
    DB::table('envoi_kumo_injections')->insert(c3Injection($ws));
    DB::table('envoi_kumo_etat')->insert(['workspace_id' => $ws]);
    DB::table('envoi_kumo_5xx')->insert(['workspace_id' => $ws, 'instant' => 5]);
    foreach (['envoi_kumo_injections', 'envoi_kumo_etat', 'envoi_kumo_5xx'] as $t) {
        expect(c3Sous($mode, $ws, fn () => DB::statement("DELETE FROM {$t}")))->not->toBeNull("DELETE {$t}");
        expect(c3Sous($mode, $ws, fn () => DB::statement("TRUNCATE {$t}")))->not->toBeNull("TRUNCATE {$t}");
        expect(DB::table($t)->count())->toBe(1);
    }
})->with(C3_MODES);

test('C3-0 registre Kumo : envoi_kumo_5xx en ajout seul (UPDATE refusé), ajout accepté', function (string $mode) {
    $ws = c3Espace();
    expect(c3Sous($mode, $ws, fn () => DB::table('envoi_kumo_5xx')->insert(['workspace_id' => $ws, 'instant' => 7])))->toBeNull();
    DB::table('envoi_kumo_5xx')->insert(['workspace_id' => $ws, 'instant' => 7]);
    expect(c3Sous($mode, $ws, fn () => DB::statement('UPDATE envoi_kumo_5xx SET instant = 8')))->not->toBeNull();
})->with(C3_MODES);

test('C3-0 registre Kumo : seq posé ne change plus, posé une première fois il est accepté', function (string $mode) {
    $ws = c3Espace();
    $cle = 'cle-seq';
    DB::table('envoi_kumo_injections')->insert(c3Injection($ws, ['cle_idempotence' => $cle]));
    expect(c3Sous($mode, $ws, fn () => DB::statement("UPDATE envoi_kumo_injections SET etat = 'accepte', code = 202, seq = 5 WHERE cle_idempotence = ?", [$cle])))->toBeNull();

    DB::statement('UPDATE envoi_kumo_injections SET seq = 5 WHERE cle_idempotence = ?', [$cle]);
    expect(c3Sous($mode, $ws, fn () => DB::statement('UPDATE envoi_kumo_injections SET seq = 6 WHERE cle_idempotence = ?', [$cle])))->not->toBeNull();
    expect(c3Sous($mode, $ws, fn () => DB::statement('UPDATE envoi_kumo_injections SET seq = NULL WHERE cle_idempotence = ?', [$cle])))->not->toBeNull();
    expect(c3Sous($mode, $ws, fn () => DB::statement("UPDATE envoi_kumo_injections SET etat = 'echec' WHERE cle_idempotence = ?", [$cle])))->toBeNull();
})->with(C3_MODES);

test('C3-0 registre Kumo : CHECK de clé, état, jeton et code', function (string $mode) {
    $ws = c3Espace();
    foreach ([['cle_idempotence' => ''], ['cle_idempotence' => str_repeat('x', 201)], ['etat' => 'inconnu'], ['jeton' => 'ZZ'], ['code' => 199], ['code' => 600]] as $mauvais) {
        expect(c3Sous($mode, $ws, fn () => DB::table('envoi_kumo_injections')->insert(c3Injection($ws, $mauvais))))->not->toBeNull(json_encode($mauvais));
    }
    expect(c3Sous($mode, $ws, fn () => DB::table('envoi_kumo_injections')->insert(c3Injection($ws, ['code' => 503, 'cle_idempotence' => str_repeat('x', 200)]))))->toBeNull();
    DB::table('envoi_kumo_injections')->insert(c3Injection($ws, ['cle_idempotence' => 'dup']));
    expect(c3Sous($mode, $ws, fn () => DB::table('envoi_kumo_injections')->insert(c3Injection($ws, ['cle_idempotence' => 'dup']))))->not->toBeNull();
})->with(C3_MODES);

test('C3-0 RLS : une ligne de A est invisible depuis B (sous le rôle applicatif)', function () {
    $a = c3Espace();
    $b = c3Espace();
    DB::table('envoi_retraits_demandes')->insert(c3Retrait($a));
    DB::table('envoi_kumo_injections')->insert(c3Injection($a));
    DB::table('envoi_kumo_etat')->insert(['workspace_id' => $a]);
    DB::table('envoi_kumo_5xx')->insert(['workspace_id' => $a, 'instant' => 1]);

    foreach (['envoi_retraits_demandes', 'envoi_kumo_injections', 'envoi_kumo_etat', 'envoi_kumo_5xx'] as $t) {
        $vus = [];
        c3Code(function () use ($b, $t, &$vus) {
            WorkspaceContext::set($b);
            DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', c3Role()) . '"');
            $vus['b'] = DB::table($t)->count();
        });
        c3Code(function () use ($a, $t, &$vus) {
            WorkspaceContext::set($a);
            DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', c3Role()) . '"');
            $vus['a'] = DB::table($t)->count();
        });
        expect($vus)->toBe(['b' => 0, 'a' => 1], $t);
    }
    // Écriture croisée refusée : B ne peut pas insérer pour A.
    expect(c3Sous('app', $b, fn () => DB::table('envoi_kumo_etat')->insert(['workspace_id' => $a])))->not->toBeNull();
});

test('C3-0 envoi_couples_reels_portes() voit A et B depuis un contexte vide sous le rôle applicatif, et rien de « faux »', function (string $mode) {
    $a = c3Espace();
    $b = c3Espace();
    foreach ([[$a, 'a-kumo.example.test', 'kumo'], [$b, 'b-smtp.example.test', 'smtp'], [$b, 'b-faux.example.test', 'faux']] as [$ws, $nom, $adaptateur]) {
        DB::table('envoi_domaines')->insert(['workspace_id' => $ws, 'nom' => $nom, 'achete_le' => '2026-01-01']);
        if ($adaptateur !== 'faux') {
            WorkspaceContext::run($ws, fn () => DB::selectOne('SELECT envoi_domaine_adaptateur_changer(?, ?, ?, ?)', [$ws, $nom, $adaptateur, 'auteur-test']));
        }
    }
    $lignes = [];
    expect(c3Code(function () use ($mode, &$lignes) {
        WorkspaceContext::clear();
        DB::statement("SELECT set_config('app.current_workspace_id', '', true)");
        if ($mode === 'app') {
            DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', c3Role()) . '"');
        }
        $lignes = array_map(fn ($l) => (array) $l, DB::select('SELECT workspace_id, domaine, adaptateur FROM envoi_couples_reels_portes()'));
        throw new RuntimeException('__annule__');
    }))->toBeNull();
    $mes = array_values(array_filter($lignes, fn ($l) => in_array($l['workspace_id'], [$a, $b], true)));
    expect(array_map(fn ($l) => $l['domaine'], $mes))->toEqualCanonicalizing(['a-kumo.example.test', 'b-smtp.example.test'])
        ->and(array_keys($mes[0]))->toBe(['workspace_id', 'domaine', 'adaptateur']);
})->with(C3_MODES);

test('C3-0 la fonction n’est pas exécutable par PUBLIC, et le rôle applicatif l’exécute', function () {
    $role = c3Role();
    expect(DB::selectOne("SELECT has_function_privilege(?, 'public.envoi_couples_reels_portes()', 'EXECUTE') AS p", [$role])->p)->toBeTrue()
        ->and(DB::selectOne("SELECT (proacl IS NULL OR proacl::TEXT ~ '(^\{|,)=X/') AS p FROM pg_proc WHERE proname = 'envoi_couples_reels_portes'")->p)->toBeFalse();
});

test('C3-0 NR-17 : down() est vide, up() rejouable, aller-retour-aller sans effet sur les données', function () {
    $ws = c3Espace();
    DB::table('envoi_retraits_demandes')->insert(c3Retrait($ws));
    DB::table('envoi_kumo_injections')->insert(c3Injection($ws));
    foreach (C3_MIGRATIONS as $fichier) {
        $migration = require database_path('migrations/' . $fichier);
        $migration->up();
        $migration->down();
        $migration->up();
    }
    expect(DB::table('envoi_retraits_demandes')->count())->toBe(1)->and(DB::table('envoi_kumo_injections')->count())->toBe(1);
});
