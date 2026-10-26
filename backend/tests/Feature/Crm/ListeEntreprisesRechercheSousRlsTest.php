<?php

/**
 * RECHERCHE PAR NOM DE LA LISTE « ENTREPRISES » SOUS LA RLS — CLOISONNEMENT
 * (26/10/2026, `docs/DIAG-LISTE-ENTREPRISES-2026-10-26.md` §2.4 et §5.5).
 *
 * `filter[denomination]` de `/companies` faisait un Parallel Seq Scan sur
 * ≈ 4,4 M de lignes sous `axion_app` : `ILIKE` n'est pas « leakproof », donc
 * l'index trigrammes est inutilisable sous la RLS (cause prouvée par
 * `ChoixEntrepriseSousRlsTest`). La liste passe désormais par la fonction
 * `entreprises_liste_denomination_ids` (SECURITY DEFINER, patron
 * `entreprises_choix_ids`). Une fonction qui lit HORS RLS est exactement
 * l'endroit où un espace pourrait voir les fiches d'un autre : ce fichier
 * prouve qu'il n'en est rien.
 *   - par l'API : un espace B ne voit JAMAIS les résultats de l'espace A
 *     (lignes ET total), et réciproquement ;
 *   - sous `axion_app` : la fonction ne rend rien hors du contexte de la
 *     connexion, et le SQL RÉELLEMENT émis par la liste de A, rejoué dans le
 *     contexte de B, ne rend aucune fiche ;
 *   - le plan sous `axion_app` ne balaie plus `companies` ;
 *   - les droits de la fonction (SECURITY DEFINER, search_path, pas de PUBLIC).
 *
 * Les tests sous `axion_app` COMMITTENT (connexions hors transaction de test)
 * et nettoient tout. Fixtures FICTIVES (dépôt public).
 */

use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use App\Support\RechercheDenomination;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function lersProprio(): Connection
{
    return DB::connection('pgsql_owner');
}

function lersApp(): Connection
{
    return DB::connection('pgsql_app');
}

function lersContexte(?string $espace): void
{
    lersApp()->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $espace ?? '']);
}

/** Un espace et son opérateur, dans la transaction du test (chemin HTTP). */
function lersOperateur(string $nom): User
{
    $espace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'ws-lers-' . Str::lower(Str::random(6)),
        'name' => $nom,
        'settings' => [],
    ]);

    $utilisateur = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'lers-' . Str::lower(Str::random(6)) . '@example.invalid',
        'name' => 'Operateur ' . $nom,
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $espace->id,
        'first_login_completed_at' => now(),
    ]);

    DB::table('user_workspaces')->insertOrIgnore([
        'user_id' => $utilisateur->id,
        'workspace_id' => $espace->id,
        'role_slug' => 'owner',
        'invited_at' => now(),
        'joined_at' => now(),
    ]);

    return $utilisateur;
}

/**
 * @param  list<string>  $noms
 * @return list<int>
 */
function lersFiches(Connection $connexion, string $espace, array $noms): array
{
    $ids = [];
    foreach ($noms as $nom) {
        $ids[] = (int) $connexion->table('companies')->insertGetId([
            'workspace_id' => $espace, 'siren' => (string) random_int(100000000, 999999999), 'denomination' => $nom,
            'discovery_source' => 'site', 'quality_score' => 0, 'signals' => '{}', 'metadata' => '{}',
            'relation_type' => 'prospect', 'lifecycle_stage' => 'nouveau', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $ids;
}

/**
 * @param  list<string>  $termes
 * @return list<int>
 */
function lersChercher(string $espace, array $termes): array
{
    $tableau = '{' . implode(',', array_map(static fn (string $t): string => '"' . $t . '"', $termes)) . '}';

    return array_map(
        static fn ($l): int => (int) $l->id,
        lersApp()->select(
            'SELECT id FROM public.entreprises_liste_denomination_ids(?::uuid, ?::text[]) AS t(id) ORDER BY id',
            [$espace, $tableau],
        ),
    );
}

/** @return array{id: string, ids: list<int>} */
function lersEspaceCommite(): array
{
    $id = (string) Str::uuid();
    lersProprio()->table('workspaces')->insert([
        'id' => $id, 'slug' => 'zz-lers-' . substr(str_replace('-', '', $id), 0, 8), 'name' => 'ZZ liste RLS', 'settings' => '{}',
        'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return ['id' => $id, 'ids' => lersFiches(lersProprio(), $id, ['OURY ZZLERS', 'AXION IA ZZLERS', 'Boulangerie Crème ZZLERS'])];
}

/** @param  array{id: string}  $e */
function lersNettoyer(array $e): void
{
    lersProprio()->table('companies')->where('workspace_id', $e['id'])->delete();
    lersProprio()->table('workspaces')->where('id', $e['id'])->delete();
}

afterEach(function () {
    lersContexte(null);
    lersApp()->disconnect();
    lersProprio()->disconnect();
});

test('API : un espace B ne voit JAMAIS les résultats de recherche de l espace A (lignes et total)', function () {
    $a = lersOperateur('Espace A');
    $b = lersOperateur('Espace B');

    // Les MÊMES noms dans les deux espaces : une fuite se verrait par l'id.
    $idsA = lersFiches(DB::connection(), (string) $a->current_workspace_id, ['OURY TRANSPORTS', 'AXION IA', 'La Boulangerie Oury']);
    $idsB = lersFiches(DB::connection(), (string) $b->current_workspace_id, ['OURY TRANSPORTS', 'AUTRE NOM']);

    foreach (['OURY', 'oury', 'AXION IA', 'Oury,axion'] as $saisie) {
        $url = '/api/v1/companies?vue=liste&' . http_build_query(['filter' => ['denomination' => $saisie]]);

        $this->actingAs($b);
        $vuB = $this->getJson($url)->assertOk();
        $idsVusParB = array_map('intval', array_column($vuB->json('data') ?? [], 'id'));
        expect(array_intersect($idsVusParB, $idsA))->toBe([], "B voit une fiche de A pour « {$saisie} »");
        expect($idsVusParB)->each->toBeIn($idsB);
        expect($vuB->json('meta.total'))->toBe(count($idsVusParB));

        $this->actingAs($a);
        $vuA = $this->getJson($url)->assertOk();
        $idsVusParA = array_map('intval', array_column($vuA->json('data') ?? [], 'id'));
        expect(array_intersect($idsVusParA, $idsB))->toBe([], "A voit une fiche de B pour « {$saisie} »");
        expect($idsVusParA)->each->toBeIn($idsA);
        expect($vuA->json('meta.total'))->toBe(count($idsVusParA));
    }

    // Justesse : mêmes résultats qu'avant le correctif (normalisation, OU).
    $this->actingAs($a);
    $noms = fn (string $saisie): array => collect($this->getJson('/api/v1/companies?' . http_build_query(['filter' => ['denomination' => $saisie]]))
        ->assertOk()->json('data'))->pluck('denomination')->sort()->values()->all();

    expect($noms('OURY'))->toBe(['La Boulangerie Oury', 'OURY TRANSPORTS']);
    expect($noms('AXION IA'))->toBe(['AXION IA']);
    expect($noms('la boulangerie'))->toBe(['La Boulangerie Oury']);
    expect($noms('Oury,axion'))->toBe(['AXION IA', 'La Boulangerie Oury', 'OURY TRANSPORTS']);
    // Un joker tapé n'élargit pas la recherche à toute la liste.
    expect($noms('%'))->toBe([]);

    $this->actingAs($b);
    expect($noms('AXION IA'))->toBe([]);
});

test('sous axion_app : la fonction ne rend que l espace du contexte, jamais un autre', function () {
    $a = lersEspaceCommite();
    $b = lersEspaceCommite();

    try {
        $role = lersApp()->selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        expect($role->rolsuper)->toBeFalse()->and($role->rolbypassrls)->toBeFalse();

        lersContexte($a['id']);
        expect(lersChercher($a['id'], ['oury']))->toBe([$a['ids'][0]]);
        expect(lersChercher($a['id'], ['AXION IA']))->toBe([$a['ids'][1]]);
        // Normalisée DANS la fonction, comme `RechercheDenomination`.
        expect(lersChercher($a['id'], ['Crème']))->toBe([$a['ids'][2]]);
        // Plusieurs termes : OU.
        expect(lersChercher($a['id'], ['oury', 'axion']))->toBe([$a['ids'][0], $a['ids'][1]]);
        // Aucun identifiant de B dans le contexte de A…
        expect(array_intersect(lersChercher($a['id'], ['zzlers']), $b['ids']))->toBe([]);
        // … et viser B depuis le contexte de A ne rend RIEN.
        expect(lersChercher($b['id'], ['zzlers']))->toBe([]);

        // Contexte vidé : rien.
        lersContexte(null);
        expect(lersChercher($a['id'], ['zzlers']))->toBe([]);

        // Contexte jamais posé (connexion neuve) : rien.
        lersApp()->disconnect();
        expect(lersChercher($a['id'], ['zzlers']))->toBe([]);

        // TÉMOIN : dans le contexte de B, B trouve les siennes, et seulement elles.
        lersContexte($b['id']);
        expect(lersChercher($b['id'], ['zzlers']))->toBe($b['ids']);
    } finally {
        lersContexte(null);
        lersNettoyer($a);
        lersNettoyer($b);
    }
});

test('sous axion_app : le SQL réellement émis par la liste de A, rejoué dans le contexte de B, ne rend rien ; et il ne balaie plus companies', function () {
    $a = lersEspaceCommite();
    $b = lersEspaceCommite();

    try {
        // Le SQL de la liste, produit par le VRAI filtre, pour l'espace A.
        $requete = Company::query()->whereNull('deleted_at')->where('workspace_id', $a['id']);
        RechercheDenomination::appliquerDansEspace($requete, 'OURY', $a['id']);
        $sql = $requete->toBase()->select('companies.id')->toRawSql();
        expect($sql)->toContain('entreprises_liste_denomination_ids');

        lersContexte($a['id']);
        expect(array_map(static fn ($l): int => (int) $l->id, lersApp()->select($sql)))->toBe([$a['ids'][0]]);

        // Même SQL, contexte B : la fonction refuse ET la RLS filtre.
        lersContexte($b['id']);
        expect(lersApp()->select($sql))->toBe([]);

        // Plan sous `axion_app`, balayage séquentiel interdit : s'il restait,
        // c'est qu'il serait IMPOSSIBLE de l'éviter (cf. « LA CAUSE » de
        // `ChoixEntrepriseSousRlsTest`, où il reste pour l'ILIKE direct).
        lersContexte($a['id']);
        $plan = lersApp()->transaction(function () use ($sql): string {
            lersApp()->statement('SET LOCAL enable_seqscan = off');

            return implode("\n", array_map(static fn ($l): string => (string) array_values((array) $l)[0], lersApp()->select('EXPLAIN ' . $sql)));
        });
        expect($plan)->toContain('entreprises_liste_denomination_ids')
            ->and($plan)->not->toContain('Seq Scan on companies');
    } finally {
        lersContexte(null);
        lersNettoyer($a);
        lersNettoyer($b);
    }
});

test('la fonction est SECURITY DEFINER, à search_path fixé, refusée à PUBLIC et accordée au rôle applicatif', function () {
    $f = DB::selectOne(<<<'SQL'
        SELECT p.prosecdef AS definer,
               array_to_string(p.proconfig, ',') AS config,
               p.proacl IS NULL AS droits_par_defaut,
               EXISTS (SELECT 1 FROM aclexplode(p.proacl) a WHERE a.grantee = 0 AND a.privilege_type = 'EXECUTE') AS public_execute
        FROM   pg_proc p
        JOIN   pg_namespace n ON n.oid = p.pronamespace
        WHERE  n.nspname = 'public' AND p.proname = 'entreprises_liste_denomination_ids'
    SQL);

    expect($f)->not->toBeNull()
        ->and((bool) $f->definer)->toBeTrue()
        ->and((string) $f->config)->toContain('search_path=')
        ->and((bool) $f->droits_par_defaut)->toBeFalse()
        ->and((bool) $f->public_execute)->toBeFalse();

    $peut = DB::selectOne(
        "SELECT has_function_privilege(?, 'public.entreprises_liste_denomination_ids(uuid, text[])', 'EXECUTE') AS ok",
        [(string) config('database.connections.pgsql_app.username', 'axion_app')],
    );
    expect((bool) $peut->ok)->toBeTrue();
});
