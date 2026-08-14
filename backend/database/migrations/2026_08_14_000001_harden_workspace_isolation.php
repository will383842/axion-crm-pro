<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lot L0 — DURCISSEMENT DE L'ISOLATION PAR WORKSPACE (plan CRM §2.1, P0-a/b/c).
 *
 * ── Ce qui n'allait pas (vérifié, pas déduit) ────────────────────────────────
 *
 * 1. Le rôle applicatif est le POSTGRES_USER `axion`. En prod, le 2026-08-14 :
 *        SELECT rolname, rolsuper, rolbypassrls FROM pg_roles WHERE rolcanlogin;
 *        → axion | t | t
 *    SUPERUSER + BYPASSRLS : la Row Level Security posée sur 34 tables était
 *    intégralement contournée. Preuve reproductible : le test
 *    `Tests\Feature\RlsTest` « RLS bloque cross-workspace SELECT companies »
 *    échouait (2 lignes visibles au lieu d'1) alors que le contexte workspace
 *    était bien posé.
 *
 * 2. Les policies portaient un FALLBACK PERMISSIF :
 *        USING (workspace_id IS NULL
 *               OR NULLIF(current_setting('app.current_workspace_id', true),'') IS NULL
 *               OR workspace_id::TEXT = ...)
 *    « pas de contexte ⇒ je vois tout ». C'est l'inverse de ce qu'on veut.
 *
 * 3. Aucun `FORCE ROW LEVEL SECURITY` : même sans superuser, le PROPRIÉTAIRE
 *    d'une table n'est pas soumis à ses policies.
 *
 * ── Ce que fait cette migration ──────────────────────────────────────────────
 *
 *  a) crée le rôle applicatif NON-PROPRIÉTAIRE `axion_app` (+ privilèges +
 *     privilèges par défaut pour les objets futurs) ;
 *  b) `ENABLE` + `FORCE ROW LEVEL SECURITY` sur toutes les tables portant
 *     `workspace_id` (hors liste d'exclusion motivée) ;
 *  c) remplace chaque policy par une version STRICTE, sans fallback permissif.
 *
 * ── Pourquoi c'est INERTE au déploiement ─────────────────────────────────────
 *
 * L'application continue de se connecter avec `axion` (superuser) tant que le
 * drapeau `CRM_DB_APP_ROLE_ENABLED` est à false : les policies, même strictes
 * et même en FORCE, restent contournées. Le comportement observable est donc
 * rigoureusement identique. L'activation est un changement d'une variable
 * d'environnement, et le rollback aussi.
 */
return new class extends Migration
{
    /**
     * Tables EXCLUES du durcissement, avec le motif.
     *
     * - `sessions`        : infrastructure d'authentification. Une session est
     *                       lue AVANT qu'un workspace courant existe.
     * - `user_workspaces` : c'est la table qui DIT à quel workspace on
     *                       appartient. La scoper sur le workspace courant est
     *                       un problème de l'œuf et de la poule (on ne pourrait
     *                       plus changer de workspace).
     * - `audit_logs*`     : table partitionnée par pg_partman. RLS + partitions
     *                       gérées dynamiquement = piège connu du dépôt
     *                       (`create_parent` v4/v5). Hors périmètre L0, à
     *                       traiter séparément.
     */
    private const EXCLUDED_TABLES = [
        'sessions',
        'user_workspaces',
        'audit_logs',
        'audit_logs_default',
    ];

    /**
     * Tables de RÉFÉRENCE où `workspace_id IS NULL` signifie légitimement
     * « ligne globale, partagée par tous les workspaces ».
     *
     * Vérifié en prod le 2026-08-14 : `llm_use_cases` est la SEULE table
     * concernée (10 lignes globales ; 0 ligne à workspace_id NULL sur les 23
     * autres tables scopées, companies et contacts compris).
     *
     * Pour ces tables — et pour elles seules — la policy conserve la branche
     * `workspace_id IS NULL`. Partout ailleurs, une ligne sans workspace n'est
     * visible par personne : c'est justement le trou qu'on ferme.
     */
    private const GLOBAL_ROW_TABLES = [
        'llm_use_cases',
    ];

    public function up(): void
    {
        $this->createApplicationRole();

        $tables = $this->workspaceScopedTables();
        $applied = [];

        foreach ($tables as $table) {
            $this->assertNoOrphanRows($table);

            DB::statement(sprintf('ALTER TABLE %s ENABLE ROW LEVEL SECURITY', $this->quote($table)));
            DB::statement(sprintf('ALTER TABLE %s FORCE ROW LEVEL SECURITY', $this->quote($table)));

            DB::statement(sprintf('DROP POLICY IF EXISTS %s_workspace_isolation ON %s', $table, $this->quote($table)));

            $predicate = $this->strictPredicate($table);
            DB::statement(sprintf(
                'CREATE POLICY %s_workspace_isolation ON %s FOR ALL USING (%s) WITH CHECK (%s)',
                $table,
                $this->quote($table),
                $predicate,
                $predicate,
            ));

            $applied[] = $table;
        }

        DB::statement('COMMENT ON SCHEMA public IS ' . DB::getPdo()->quote(sprintf(
            'RLS durcie (lot L0, 2026-08-14) : %d tables FORCE + policy stricte. Exclues : %s. Lignes globales tolérées : %s.',
            count($applied),
            implode(',', self::EXCLUDED_TABLES),
            implode(',', self::GLOBAL_ROW_TABLES),
        )));
    }

    public function down(): void
    {
        // Retour à l'état antérieur : policy permissive + pas de FORCE.
        // (Le rollback opérationnel réel est le drapeau CRM_DB_APP_ROLE_ENABLED
        // remis à false — cette migration inverse existe pour la complétude.)
        foreach ($this->workspaceScopedTables() as $table) {
            DB::statement(sprintf('ALTER TABLE %s NO FORCE ROW LEVEL SECURITY', $this->quote($table)));
            DB::statement(sprintf('DROP POLICY IF EXISTS %s_workspace_isolation ON %s', $table, $this->quote($table)));
            DB::statement(sprintf(
                "CREATE POLICY %s_workspace_isolation ON %s FOR ALL
                 USING (
                    workspace_id IS NULL
                    OR NULLIF(current_setting('app.current_workspace_id', true), '') IS NULL
                    OR workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), '')
                 )
                 WITH CHECK (
                    workspace_id IS NULL
                    OR NULLIF(current_setting('app.current_workspace_id', true), '') IS NULL
                    OR workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), '')
                 )",
                $table,
                $this->quote($table),
            ));
        }
    }

    /**
     * Toutes les tables ordinaires (hors partitions) portant `workspace_id`,
     * moins la liste d'exclusion.
     *
     * Détection DYNAMIQUE plutôt que liste en dur : la liste en dur de la
     * migration 2026_05_18_000001 avait laissé une dizaine de tables sans
     * aucune policy (analytics_*, crm_*, email_threads, unsubscribes…).
     *
     * @return list<string>
     */
    private function workspaceScopedTables(): array
    {
        $rows = DB::select(
            "SELECT c.relname AS name
               FROM pg_class c
               JOIN pg_namespace n ON n.oid = c.relnamespace
               JOIN pg_attribute a ON a.attrelid = c.oid
                                  AND a.attname = 'workspace_id'
                                  AND a.attnum > 0
                                  AND NOT a.attisdropped
              WHERE n.nspname = current_schema()
                AND c.relkind = 'r'
                AND NOT c.relispartition
              ORDER BY c.relname",
        );

        $tables = [];
        foreach ($rows as $row) {
            $name = (string) $row->name;
            if (in_array($name, self::EXCLUDED_TABLES, true)) {
                continue;
            }
            $tables[] = $name;
        }

        return $tables;
    }

    /**
     * Une ligne sans workspace_id serait invisible pour tout le monde sous
     * policy stricte — c'est-à-dire une perte de données silencieuse.
     * On préfère un échec de migration BRUYANT et lisible.
     */
    private function assertNoOrphanRows(string $table): void
    {
        if (in_array($table, self::GLOBAL_ROW_TABLES, true)) {
            return;
        }

        $orphan = DB::selectOne(sprintf(
            'SELECT EXISTS (SELECT 1 FROM %s WHERE workspace_id IS NULL) AS has_orphan',
            $this->quote($table),
        ));

        if ($orphan && $orphan->has_orphan) {
            throw new RuntimeException(
                "Table « {$table} » : des lignes ont workspace_id NULL. Sous policy stricte "
                . 'elles deviendraient invisibles pour tous les workspaces. Rattacher ces lignes '
                . "à un workspace, ou ajouter « {$table} » à GLOBAL_ROW_TABLES avec une justification écrite.",
            );
        }
    }

    private function strictPredicate(string $table): string
    {
        $strict = "workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), '')";

        if (in_array($table, self::GLOBAL_ROW_TABLES, true)) {
            // Lignes globales de référence : lisibles partout, mais AUCUN
            // fallback « pas de contexte ⇒ je vois tout ».
            return "(workspace_id IS NULL OR {$strict})";
        }

        return $strict;
    }

    /**
     * Crée / met à jour le rôle applicatif non-propriétaire et ses privilèges.
     *
     * Le mot de passe vient de DB_APP_PASSWORD (jamais commité, posé dans le
     * `.env` du serveur et dans l'environnement de la CI). S'il est absent, le
     * rôle est créé SANS droit de connexion : les privilèges sont en place,
     * l'activation ne demandera plus qu'un `ALTER ROLE ... LOGIN PASSWORD`.
     */
    private function createApplicationRole(): void
    {
        // Lu depuis la CONFIG et non env() : `config:cache` est actif en prod,
        // où env() renverrait null (règle larastan.noEnvCallsOutsideOfConfig).
        $role = (string) (config('database.connections.pgsql_app.username') ?: 'axion_app');
        $password = (string) (config('database.connections.pgsql_app.password') ?: '');

        if (! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role)) {
            throw new RuntimeException("Nom de rôle applicatif invalide : « {$role} ».");
        }

        DB::statement(
            <<<SQL
            DO \$\$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$role}') THEN
                    EXECUTE 'CREATE ROLE {$role} NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS';
                END IF;
            END
            \$\$;
            SQL
        );

        // Jamais de superuser / bypassrls sur ce rôle, même si quelqu'un l'a
        // créé à la main auparavant.
        DB::statement("ALTER ROLE {$role} NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE");

        if ($password !== '') {
            DB::statement("ALTER ROLE {$role} LOGIN PASSWORD " . DB::getPdo()->quote($password));
        }

        $schema = 'public';
        DB::statement("GRANT USAGE ON SCHEMA {$schema} TO {$role}");
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA {$schema} TO {$role}");
        DB::statement("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA {$schema} TO {$role}");
        DB::statement("GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA {$schema} TO {$role}");

        // Objets créés PLUS TARD par le propriétaire (migrations suivantes,
        // partitions pg_partman) : privilèges accordés d'office.
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA {$schema} GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$role}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA {$schema} GRANT USAGE, SELECT ON SEQUENCES TO {$role}");
        DB::statement("ALTER DEFAULT PRIVILEGES IN SCHEMA {$schema} GRANT EXECUTE ON FUNCTIONS TO {$role}");
    }

    private function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
};
