<?php

/**
 * RECHERCHE D'ENTREPRISE PAR NOM, REJOUÉE SOUS LE RÔLE DE PRODUCTION
 * (`axion_app`, RLS forcée) — suite de la #287.
 *
 * En production, toute recherche par nom tombait en 503 : sous la RLS,
 * `ILIKE` (non « leakproof ») ne peut pas utiliser l'index trigrammes, et la
 * requête parcourait 4,3 M de lignes. Les autres tests tournent sous le
 * propriétaire (superuser) et ne VOYAIENT PAS cette barrière. Ici :
 *   - la cause, mesurée par `EXPLAIN` (index sous le propriétaire, pas sous
 *     `axion_app`) ;
 *   - la fonction `entreprises_choix_ids` sous `axion_app` : bons résultats,
 *     et RIEN d'un autre espace, même en le demandant ;
 *   - ses droits (SECURITY DEFINER, search_path fixé, pas de PUBLIC).
 *
 * Ce test COMMIT (connexions hors transaction de test) : il nettoie tout.
 * Fixtures FICTIVES (dépôt public).
 */

use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function cersProprio(): Connection
{
    return DB::connection('pgsql_owner');
}

function cersApp(): Connection
{
    return DB::connection('pgsql_app');
}

/** @return array{id: string, ids: array<string, int>} */
function cersEspace(): array
{
    $owner = cersProprio();
    $id = (string) Str::uuid();
    $marque = substr(str_replace('-', '', $id), 0, 8);
    $owner->table('workspaces')->insert([
        'id' => $id, 'slug' => 'zz-choix-rls-' . $marque, 'name' => 'ZZ choix RLS', 'settings' => '{}',
        'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $ids = [];
    $fiches = [
        'sciado' => ['ZZ SCIADOZ ATELIER', null],
        'sg' => ['SOCIETE GENERALE ZZQX', null],
        'la_generale' => ['LA GENERALE ZZQX', null],
        'air_france' => ['AIR FRANCE ZZQX', null],
        'air_clim' => ['AIR CLIM ZZQX', 'Fort-de-France'],
        'jardins' => ['Les Jardins du Lac ZZQX', 'Annecy'],
    ];
    foreach ($fiches as $cle => [$nom, $ville]) {
        $ids[$cle] = (int) $owner->table('companies')->insertGetId([
            'workspace_id' => $id, 'siren' => (string) random_int(100000000, 999999999), 'denomination' => $nom,
            'city_name' => $ville, 'discovery_source' => 'site', 'quality_score' => 0, 'signals' => '{}', 'metadata' => '{}',
            'relation_type' => 'prospect', 'lifecycle_stage' => 'nouveau', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return ['id' => $id, 'ids' => $ids];
}

/** @param  array{id: string}  $e */
function cersNettoyer(array $e): void
{
    $owner = cersProprio();
    $owner->table('companies')->where('workspace_id', $e['id'])->delete();
    $owner->table('workspaces')->where('id', $e['id'])->delete();
}

/**
 * @param  list<string>  $entrees
 * @param  list<string>  $filtres
 * @return list<int>
 */
function cersChercher(string $espace, array $entrees, array $filtres, string $cp = ''): array
{
    $tableau = static fn (array $mots): string => '{' . implode(',', array_map(static fn (string $m): string => '"' . $m . '"', $mots)) . '}';

    return array_map(
        static fn ($l): int => (int) $l->id,
        cersApp()->select(
            'SELECT id FROM public.entreprises_choix_ids(?::uuid, ?::text[], ?::text[], ?, 10) AS t(id)',
            [$espace, $tableau($entrees), $tableau($filtres), $cp],
        ),
    );
}

function cersContexte(?string $espace): void
{
    cersApp()->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $espace ?? '']);
}

afterEach(function () {
    cersContexte(null);
    cersApp()->disconnect();
    cersProprio()->disconnect();
});

test('sous axion_app : la recherche par nom rend les bonnes fiches, dans l ordre de la #287, et rien d un autre espace', function () {
    $a = cersEspace();
    $b = cersEspace();

    try {
        $role = cersApp()->selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
        expect($role->rolsuper)->toBeFalse()->and($role->rolbypassrls)->toBeFalse();

        cersContexte($a['id']);

        expect(cersChercher($a['id'], ['sciadoz'], ['sciadoz']))->toBe([$a['ids']['sciado']]);
        // « Société Générale » : la bonne fiche, sans « LA GENERALE ».
        expect(cersChercher($a['id'], ['generale', 'zzqx'], ['societe', 'generale', 'zzqx']))->toBe([$a['ids']['sg']]);
        // « Air France » : tous les mots dans le nom d'abord, puis la ville.
        expect(cersChercher($a['id'], ['air', 'zzqx'], ['air', 'france', 'zzqx']))->toBe([$a['ids']['air_france'], $a['ids']['air_clim']]);
        // « les jardins du lac » (articles déjà retirés par le contrôleur).
        expect(cersChercher($a['id'], ['jardins', 'lac', 'zzqx'], ['jardins', 'lac', 'zzqx']))->toBe([$a['ids']['jardins']]);

        // CLOISONNEMENT : aucun identifiant de B, sous le contexte de A…
        $deB = array_values($b['ids']);
        expect(array_intersect(cersChercher($a['id'], ['zzqx'], ['zzqx']), $deB))->toBe([]);
        // … et viser B en restant dans le contexte de A ne rend RIEN.
        expect(cersChercher($b['id'], ['zzqx'], ['zzqx']))->toBe([]);

        // Sans contexte : rien.
        cersContexte(null);
        expect(cersChercher($a['id'], ['zzqx'], ['zzqx']))->toBe([]);

        // TÉMOIN : dans le contexte de B, B trouve ses propres fiches.
        cersContexte($b['id']);
        expect(cersChercher($b['id'], ['sciadoz'], ['sciadoz']))->toBe([$b['ids']['sciado']]);
    } finally {
        cersContexte(null);
        cersNettoyer($a);
        cersNettoyer($b);
    }
});

test('LA CAUSE : sous axion_app, ILIKE ne peut pas utiliser l index trigrammes ; sous le propriétaire, si', function () {
    // Sans condition sur l'espace : le seul index applicable est le trigrammes.
    $sql = "EXPLAIN SELECT c.id FROM companies c WHERE c.denomination_normalized ILIKE '%sciado%'";

    $plan = static function (Connection $connexion) use ($sql): string {
        return $connexion->transaction(function () use ($connexion, $sql): string {
            // On interdit le parcours séquentiel : s'il reste, c'est que
            // l'index est IMPOSSIBLE, pas seulement jugé plus cher.
            $connexion->statement('SET LOCAL enable_seqscan = off');

            return implode("\n", array_map(static fn ($l): string => (string) array_values((array) $l)[0], $connexion->select($sql)));
        });
    };

    expect($plan(cersProprio()))->toContain('idx_companies_denomination_trgm');

    cersContexte('00000000-0000-0000-0000-000000000001');
    expect($plan(cersApp()))->not->toContain('idx_companies_denomination_trgm');
});

test('la fonction est SECURITY DEFINER, à search_path fixé, refusée à PUBLIC et accordée au rôle applicatif', function () {
    $f = DB::selectOne(<<<'SQL'
        SELECT p.prosecdef AS definer,
               array_to_string(p.proconfig, ',') AS config,
               p.proacl IS NULL AS droits_par_defaut,
               EXISTS (SELECT 1 FROM aclexplode(p.proacl) a WHERE a.grantee = 0 AND a.privilege_type = 'EXECUTE') AS public_execute
        FROM   pg_proc p
        JOIN   pg_namespace n ON n.oid = p.pronamespace
        WHERE  n.nspname = 'public' AND p.proname = 'entreprises_choix_ids'
    SQL);

    expect($f)->not->toBeNull()
        ->and((bool) $f->definer)->toBeTrue()
        ->and((string) $f->config)->toContain('search_path=')
        ->and((bool) $f->droits_par_defaut)->toBeFalse()
        ->and((bool) $f->public_execute)->toBeFalse();

    $peut = DB::selectOne(
        "SELECT has_function_privilege(?, 'public.entreprises_choix_ids(uuid, text[], text[], text, integer)', 'EXECUTE') AS ok",
        [(string) config('database.connections.pgsql_app.username', 'axion_app')],
    );
    expect((bool) $peut->ok)->toBeTrue();
});
