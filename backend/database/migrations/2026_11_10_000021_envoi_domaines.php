<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L3 — domaines d'envoi (âge et derniers contrôles DNS).
 * ADDITIF SEULEMENT. Table propre au lot L3 (L0 ne la crée pas). `down()` inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_domaines (
                id              BIGSERIAL   PRIMARY KEY,
                workspace_id    UUID        NOT NULL REFERENCES workspaces(id),
                nom             TEXT        NOT NULL,
                achete_le       DATE        NOT NULL,
                dmarc_verifie_le TIMESTAMPTZ NULL,
                spf_verifie_le  TIMESTAMPTZ NULL,
                dkim_verifie_le TIMESTAMPTZ NULL,
                cree_le         TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (workspace_id, nom)
            )
            SQL,
        );
        DB::statement('ALTER TABLE envoi_domaines ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE envoi_domaines FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS envoi_domaines_workspace_isolation ON envoi_domaines');
        DB::statement("CREATE POLICY envoi_domaines_workspace_isolation ON envoi_domaines FOR ALL
            USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
            WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))");
        DB::statement("COMMENT ON TABLE envoi_domaines IS 'Envoi P0 L3 : domaines d envoi, age et controles DNS. Ajout seul.'");

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT SELECT, INSERT, UPDATE ON public.envoi_domaines TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_domaines_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : les domaines sont conservés. */
    public function down(): void
    {
        DB::statement("COMMENT ON TABLE envoi_domaines IS 'Envoi P0 L3 : table conservee au retour arriere.'");
    }
};
