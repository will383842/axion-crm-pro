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

function rlsCleanup(array $workspaceIds): void
{
    rlsOwner()->table('companies')->whereIn('workspace_id', $workspaceIds)->delete();
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

test('les tables scopées portent FORCE ROW LEVEL SECURITY', function () {
    $rows = rlsOwner()->select(
        "SELECT c.relname AS name, c.relrowsecurity, c.relforcerowsecurity
           FROM pg_class c
           JOIN pg_namespace n ON n.oid = c.relnamespace
           JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = 'workspace_id'
                              AND a.attnum > 0 AND NOT a.attisdropped
          WHERE n.nspname = current_schema()
            AND c.relkind = 'r'
            AND NOT c.relispartition
            AND c.relname NOT IN ('sessions', 'user_workspaces', 'audit_logs_default')",
    );

    expect($rows)->not->toBeEmpty();

    $manquantes = [];
    foreach ($rows as $row) {
        if (! $row->relrowsecurity || ! $row->relforcerowsecurity) {
            $manquantes[] = $row->name;
        }
    }

    expect($manquantes)->toBe([]);
});

test('aucune policy ne conserve le fallback permissif « pas de contexte ⇒ tout voir »', function () {
    $fautives = rlsOwner()->select(
        "SELECT tablename, policyname, qual
           FROM pg_policies
          WHERE schemaname = current_schema()
            AND qual LIKE '%current_setting%'
            AND qual LIKE '%IS NULL%'
            AND tablename <> 'llm_use_cases'",
    );

    // `llm_use_cases` est la seule table à lignes globales (workspace_id NULL
    // légitime, 10 lignes en prod) : sa policy garde la branche
    // `workspace_id IS NULL`, jamais la branche `current_setting(...) IS NULL`.
    expect(array_map(fn ($r) => $r->tablename . '.' . $r->policyname, $fautives))->toBe([]);
});

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
