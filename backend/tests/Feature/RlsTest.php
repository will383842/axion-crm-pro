<?php

use App\Exceptions\MissingWorkspaceContextException;
use App\Models\Company;
use App\Support\WorkspaceContext;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * ÉTANCHÉITÉ PAR WORKSPACE — tests de la barrière réelle (lot L0).
 *
 * Ces tests étaient en quarantaine (tests/QUARANTAINE.md, 2026-08-13) : l'un
 * d'eux échouait, l'autre CERTIFIAIT le trou (« RLS bypass quand session var
 * vide »). Sortie de quarantaine au lot L0, avec le sens inversé : ce qui était
 * documenté comme un comportement acceptable est désormais interdit.
 *
 * ── Pourquoi une connexion dédiée ────────────────────────────────────────────
 *
 * Le rôle `axion` (POSTGRES_USER) est SUPERUSER et BYPASSRLS — vérifié en prod
 * le 2026-08-14. Aucune policy, même en FORCE, ne s'applique à lui. Tester la
 * RLS depuis la connexion par défaut ne prouverait donc RIEN. Ces tests
 * passent par la connexion `pgsql_app`, qui utilise le rôle applicatif
 * non-propriétaire `axion_app` créé par la migration 2026_08_14_000001.
 *
 * ── Pourquoi les données sont écrites sur `pgsql_owner` ──────────────────────
 *
 * RefreshDatabase enveloppe la connexion PAR DÉFAUT dans une transaction non
 * committée : ces lignes seraient invisibles depuis `pgsql_app`, qui est une
 * autre session. On écrit donc le jeu d'essai sur `pgsql_owner` (auto-commit)
 * et on le nettoie explicitement.
 */
function rlsOwner(): Connection
{
    return DB::connection('pgsql_owner');
}

function rlsApp(): Connection
{
    return DB::connection('pgsql_app');
}

/**
 * @return array{0: string, 1: string} identifiants des deux workspaces créés
 */
function rlsSeedTwoWorkspaces(): array
{
    $wsA = (string) Str::uuid();
    $wsB = (string) Str::uuid();
    $now = now();

    rlsOwner()->table('workspaces')->insert([
        ['id' => $wsA, 'slug' => 'rls-a-' . substr($wsA, 0, 8), 'name' => 'RLS A', 'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ['id' => $wsB, 'slug' => 'rls-b-' . substr($wsB, 0, 8), 'name' => 'RLS B', 'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
    ]);

    rlsOwner()->table('companies')->insert([
        ['workspace_id' => $wsA, 'siren' => rlsSiren(), 'denomination' => 'AAA', 'signals' => '{}', 'metadata' => '{}', 'quality_score' => 0, 'created_at' => $now, 'updated_at' => $now],
        ['workspace_id' => $wsB, 'siren' => rlsSiren(), 'denomination' => 'BBB', 'signals' => '{}', 'metadata' => '{}', 'quality_score' => 0, 'created_at' => $now, 'updated_at' => $now],
    ]);

    return [$wsA, $wsB];
}

function rlsSiren(): string
{
    return str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
}

/**
 * ⚠️ Ce nettoyage n'est pas une politesse : c'est la SEULE chose qui empêche ce
 * fichier de polluer toute la suite. Les lignes semées le sont sur
 * `pgsql_owner`, en AUTO-COMMIT — la transaction de `RefreshDatabase` ne les
 * annule pas. Un seul chemin qui l'oublie laisse 2 entreprises et 2 workspaces
 * commités pour le reste de l'exécution, et n'importe quel test ultérieur qui
 * compte `companies` rougit. Comme Pest tire un ordre au hasard, ce n'est
 * jamais le même fichier qui tombe : le symptôme ressemble à de la fragilité
 * alors que la cause est ici. Constaté et corrigé le 2026-08-16.
 *
 * Les tags sont retirés AVANT les workspaces : `tags.workspace_id` les
 * référence, la suppression du workspace échouerait sur la clé étrangère.
 */
function rlsCleanup(array $workspaceIds): void
{
    rlsOwner()->table('companies')->whereIn('workspace_id', $workspaceIds)->delete();
    rlsOwner()->table('tags')->whereIn('workspace_id', $workspaceIds)->delete();
    rlsOwner()->table('workspaces')->whereIn('id', $workspaceIds)->delete();
}

afterEach(function () {
    WorkspaceContext::clear(rlsApp());
    WorkspaceContext::clear();
    rlsApp()->disconnect();
    rlsOwner()->disconnect();
});

// ─────────────────────────────────────────────────────────────────────────────
// 1. La barrière SQL mord vraiment
// ─────────────────────────────────────────────────────────────────────────────

test('le rôle applicatif ne voit que son workspace', function () {
    [$wsA, $wsB] = rlsSeedTwoWorkspaces();

    try {
        rlsApp()->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $wsA]);

        $rows = rlsApp()->table('companies')->pluck('workspace_id')->all();

        expect($rows)->not->toBeEmpty()
            ->and(array_unique($rows))->toBe([$wsA]);
    } finally {
        rlsCleanup([$wsA, $wsB]);
    }
});

test('sans contexte workspace, le rôle applicatif ne voit AUCUNE ligne', function () {
    [$wsA, $wsB] = rlsSeedTwoWorkspaces();

    try {
        // Anciennement : `pas de contexte ⇒ je vois tout` (fallback permissif).
        rlsApp()->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);

        expect(rlsApp()->table('companies')->count())->toBe(0);
    } finally {
        rlsCleanup([$wsA, $wsB]);
    }
});

test('une écriture dans un AUTRE workspace est rejetée par la policy', function () {
    [$wsA, $wsB] = rlsSeedTwoWorkspaces();

    try {
        rlsApp()->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $wsA]);

        $insert = fn () => rlsApp()->table('companies')->insert([
            'workspace_id' => $wsB,
            'siren' => rlsSiren(),
            'denomination' => 'INTRUS',
            'signals' => '{}',
            'metadata' => '{}',
            'quality_score' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect($insert)->toThrow(QueryException::class);
    } finally {
        rlsCleanup([$wsA, $wsB]);
    }
});

test('une écriture SANS contexte est rejetée (pas un no-op silencieux)', function () {
    [$wsA, $wsB] = rlsSeedTwoWorkspaces();

    try {
        rlsApp()->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);

        $insert = fn () => rlsApp()->table('companies')->insert([
            'workspace_id' => $wsA,
            'siren' => rlsSiren(),
            'denomination' => 'SANS CONTEXTE',
            'signals' => '{}',
            'metadata' => '{}',
            'quality_score' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect($insert)->toThrow(QueryException::class);
    } finally {
        rlsCleanup([$wsA, $wsB]);
    }
});

test('un UPDATE croisé ne touche aucune ligne du workspace voisin', function () {
    [$wsA, $wsB] = rlsSeedTwoWorkspaces();

    try {
        rlsApp()->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $wsA]);

        $affected = rlsApp()->table('companies')->where('workspace_id', $wsB)->update(['denomination' => 'PIRATÉ']);
        expect($affected)->toBe(0);

        $intact = rlsOwner()->table('companies')->where('workspace_id', $wsB)->value('denomination');
        expect($intact)->toBe('BBB');
    } finally {
        rlsCleanup([$wsA, $wsB]);
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. Configuration de la barrière
// ─────────────────────────────────────────────────────────────────────────────

test('le rôle applicatif n’est ni superutilisateur ni BYPASSRLS ni propriétaire', function () {
    $role = config('database.connections.pgsql_app.username');

    $attrs = rlsOwner()->selectOne(
        'SELECT rolsuper, rolbypassrls, rolcreatedb, rolcreaterole FROM pg_roles WHERE rolname = ?',
        [$role],
    );

    expect($attrs)->not->toBeNull()
        ->and($attrs->rolsuper)->toBeFalsy()
        ->and($attrs->rolbypassrls)->toBeFalsy()
        ->and($attrs->rolcreatedb)->toBeFalsy()
        ->and($attrs->rolcreaterole)->toBeFalsy();

    $owner = rlsOwner()->selectOne("SELECT tableowner FROM pg_tables WHERE tablename = 'companies'");
    expect($owner->tableowner)->not->toBe($role);
});

// ⚠️ 2026-08-18 — LES DEUX CONTRÔLES STRUCTURELS ONT DÉMÉNAGÉ.
//
// Ils vivaient ici : « les tables scopées portent FORCE ROW LEVEL SECURITY » et
// « aucune policy ne conserve le fallback permissif ». Ils sont maintenant dans
// `tests/Feature/EtancheiteParTableTest.php`, adossés à
// `Tests\Support\EtancheiteWorkspace`. Deux raisons MESURÉES, pas une
// préférence de rangement :
//
//  1. LEUR LISTE D'EXCLUSION AVAIT DIVERGÉ de celle de la migration de
//     durcissement. Ici : `('sessions','user_workspaces','audit_logs_default')`.
//     Là-bas : ces trois-là PLUS `audit_logs`. La divergence ne se voyait pas
//     parce que `audit_logs` est une table PARTITIONNÉE (`relkind='p'`) que le
//     scan `relkind='r'` n'atteint jamais — exclusion TACITE, indistinguable
//     d'un oubli. Il n'y a désormais qu'une seule liste, et un test qui la
//     compare au source de la migration.
//
//  2. LE DÉTECTEUR DE REPLI PERMISSIF ÉTAIT AVEUGLE à une forme d'écriture.
//     Il cherchait `qual LIKE '%IS NULL%'` ; il ne voyait donc pas
//     `COALESCE(NULLIF(current_setting(...), ''), workspace_id::text)`, qui a
//     exactement le même effet. C'est cette policy-là qui a survécu au
//     durcissement sur `email_verification_logs` (nom raccourci → le
//     `DROP POLICY` de la migration l'a manquée), et cette table rend
//     aujourd'hui TOUTES ses lignes sans contexte. Mesuré, pas déduit.
//
// Ce fichier garde ce qu'il prouve le mieux : la barrière en situation sur
// `companies` et `tags`, la configuration du rôle, la ceinture applicative et
// l'inertie des drapeaux.

// ─────────────────────────────────────────────────────────────────────────────
// 3. Ceinture applicative : échec BRUYANT hors requête HTTP
//    (le piège identifié : un cron vert qui ne fait rien)
// ─────────────────────────────────────────────────────────────────────────────

test('en mode strict, une requête sans contexte workspace échoue bruyamment', function () {
    config(['crm.strict_workspace_scope' => true]);
    WorkspaceContext::clear();

    expect(fn () => Company::query()->count())
        ->toThrow(MissingWorkspaceContextException::class);
});

test('en mode strict, WorkspaceContext::run pose le contexte et le restaure', function () {
    [$wsA, $wsB] = rlsSeedTwoWorkspaces();
    config(['crm.strict_workspace_scope' => true]);
    WorkspaceContext::clear();

    try {
        $count = WorkspaceContext::run($wsA, fn () => Company::query()->count());
        expect($count)->toBe(1);

        // Contexte bien restauré (donc de nouveau absent) après le bloc.
        expect(WorkspaceContext::current())->toBeNull();
        expect(fn () => Company::query()->count())
            ->toThrow(MissingWorkspaceContextException::class);
    } finally {
        rlsCleanup([$wsA, $wsB]);
    }
});

test('en mode strict, le contexte est restauré même si le job lève une exception', function () {
    config(['crm.strict_workspace_scope' => true]);
    $wsA = (string) Str::uuid();

    try {
        WorkspaceContext::run($wsA, function () {
            throw new DomainException('boom');
        });
    } catch (DomainException) {
        // attendu
    }

    expect(WorkspaceContext::current())->toBeNull();
});

test('runWithoutScope exige une justification écrite', function () {
    expect(fn () => WorkspaceContext::runWithoutScope('', fn () => null))
        ->toThrow(InvalidArgumentException::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. INERTIE — drapeaux à OFF, le comportement est celui d'avant le lot L0
// ─────────────────────────────────────────────────────────────────────────────

test('drapeaux par défaut : aucun durcissement actif', function () {
    expect(config('crm.db_app_role'))->toBeFalsy()
        ->and(config('crm.strict_workspace_scope'))->toBeFalsy();
});

test('drapeaux OFF : une requête sans contexte se comporte comme avant (aucune exception)', function () {
    [$wsA, $wsB] = rlsSeedTwoWorkspaces();
    config(['crm.strict_workspace_scope' => false]);
    WorkspaceContext::clear();

    try {
        expect(Company::query()->count())->toBeGreaterThanOrEqual(0);
    } finally {
        rlsCleanup([$wsA, $wsB]);
    }
});

test('drapeau db_app_role à OFF : la connexion par défaut reste le rôle historique', function () {
    expect(config('database.connections.pgsql.username'))
        ->toBe(config('database.connections.pgsql_owner.username'));
});

// ─────────────────────────────────────────────────────────────────────────────
// RÉGRESSION PRODUCTION 2026-08-15 02:30 — le backfill des tags `src:` a
// échoué sous RLS active : `SQLSTATE[42501] new row violates row-level
// security policy for table "tags"`. Cause : la commande lisait et écrivait
// SANS contexte workspace. Sous le rôle applicatif, la RLS rend alors les tags
// INVISIBLES (le SELECT renvoie null ⇒ la commande croit devoir les créer)
// puis REFUSE l'insertion. Ce test fige les deux moitiés du symptôme.
// ─────────────────────────────────────────────────────────────────────────────

test('sans contexte, un tag EXISTANT est invisible et son insertion est REFUSÉE (le symptôme du backfill)', function () {
    [$wsA, $wsB] = rlsSeedTwoWorkspaces();

    try {
        // Le tag existe, posé par le propriétaire (comme le fait le seeder).
        rlsOwner()->table('tags')->insert([
            'workspace_id' => $wsA,
            'slug' => 'src:scraping-insee',
            'name' => 'Collecte — INSEE',
            'category' => 'intent',
            'kind' => 'auto',
            'rules' => '{}',
            'is_locked' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 1re moitié : SANS contexte, la lecture ne le voit pas.
        WorkspaceContext::clear(rlsApp());
        $invisible = rlsApp()->table('tags')->where('workspace_id', $wsA)->where('slug', 'src:scraping-insee')->value('id');
        expect($invisible)->toBeNull();

        // 2e moitié : et l'insertion « de rattrapage » est refusée par la policy.
        expect(fn () => rlsApp()->table('tags')->insertOrIgnore([
            'workspace_id' => $wsA,
            'slug' => 'src:scraping-insee',
            'name' => 'Collecte — INSEE',
            'category' => 'intent',
            'kind' => 'auto',
            'rules' => '{}',
            'is_locked' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]))->toThrow(QueryException::class);

        // AVEC contexte : le tag redevient visible, donc aucune insertion n'est
        // tentée — c'est exactement ce que le correctif rétablit.
        rlsApp()->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $wsA]);
        $visible = rlsApp()->table('tags')->where('workspace_id', $wsA)->where('slug', 'src:scraping-insee')->value('id');
        expect($visible)->not->toBeNull();
    } finally {
        // Ce `finally` manquait : ce test était le SEUL des sept semeurs à ne
        // pas nettoyer. Ses 2 entreprises restaient commitées pour toute la
        // suite (cf. `rlsCleanup`).
        rlsCleanup([$wsA, $wsB]);
    }
});

test('la commande de backfill pose bien son contexte workspace (correctif du 2026-08-15)', function () {
    // Garde structurelle : sans `WorkspaceContext::run`, la commande retombe
    // dans la panne ci-dessus dès que le rôle applicatif est actif.
    $source = file_get_contents(app_path('Console/Commands/ScrapingBackfillSrcTags.php'));

    expect($source)->toContain('WorkspaceContext::run(');
});
