<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 K12 — contrôles DNS quotidiens des domaines d'envoi.
 * ADDITIF SEULEMENT. Le rôle applicatif n'a que SELECT et INSERT. `down()` inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_controles_dns (
                id           BIGSERIAL   PRIMARY KEY,
                workspace_id UUID        NOT NULL REFERENCES workspaces(id),
                domaine      TEXT        NOT NULL,
                controle_le  TIMESTAMPTZ NOT NULL,
                pret         BOOLEAN     NOT NULL,
                pause        BOOLEAN     NOT NULL DEFAULT false,
                resultats    JSONB       NOT NULL DEFAULT '{}'::jsonb,
                cree_le      TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX IF NOT EXISTS envoi_controles_dns_ws_domaine
                ON envoi_controles_dns (workspace_id, domaine, controle_le);
            ALTER TABLE envoi_controles_dns ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_controles_dns FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_controles_dns_workspace_isolation ON envoi_controles_dns;
            CREATE POLICY envoi_controles_dns_workspace_isolation ON envoi_controles_dns FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.envoi_controles_dns FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_controles_dns TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_controles_dns_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : les contrôles sont conservés. */
    public function down(): void
    {
        DB::statement("COMMENT ON TABLE envoi_controles_dns IS 'Envoi P0 K12 : table conservee au retour arriere.'");
    }
};
