<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L7 — pauses automatiques ou humaines (levee = date, jamais d effacement). AJOUT SEUL : le rôle applicatif n'a ni DELETE ni TRUNCATE.
 * workspace_id + RLS FORCE (copie de 2026_11_10_000020). down() ne retire la
 * table que si elle est vide (aucun effacement de données).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_pauses (
                id            BIGSERIAL   PRIMARY KEY,
                workspace_id  UUID        NOT NULL REFERENCES workspaces(id),
                portee        TEXT        NOT NULL CHECK (portee IN ('global', 'fournisseur')),
                fournisseur   TEXT        NULL CHECK (fournisseur IN ('gmail', 'microsoft', 'autre')),
                motif         TEXT        NOT NULL,
                declenchee_le TIMESTAMPTZ NOT NULL DEFAULT now(),
                levee_le      TIMESTAMPTZ NULL,
                levee_par     TEXT        NULL,
                CHECK ((portee = 'fournisseur') = (fournisseur IS NOT NULL))
            )
            SQL,
        );
        DB::statement('CREATE INDEX IF NOT EXISTS idx_envoi_pauses_actives ON envoi_pauses (workspace_id) WHERE levee_le IS NULL');
        DB::statement('ALTER TABLE envoi_pauses ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE envoi_pauses FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS envoi_pauses_workspace_isolation ON envoi_pauses');
        DB::statement(self::politique('envoi_pauses'));
        DB::statement("COMMENT ON TABLE envoi_pauses IS 'Envoi P0 L7 : pauses automatiques ou humaines (levee = date, jamais d effacement). Ajout seul.'");

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT SELECT, INSERT, UPDATE ON public.envoi_pauses TO ' . $role);
            DB::statement('REVOKE DELETE, TRUNCATE ON public.envoi_pauses FROM ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_pauses_id_seq TO ' . $role);
        }
    }

    private static function politique(string $table): string
    {
        return "CREATE POLICY {$table}_workspace_isolation ON {$table} FOR ALL
            USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
            WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))";
    }

    /** Retour PRUDENT : refusé si la table contient des lignes (aucun effacement). */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM envoi_pauses) THEN
                    RAISE EXCEPTION 'envoi_pauses contient des lignes : retour refusé (aucun effacement)';
                END IF;
            END $$;
            DROP TABLE IF EXISTS envoi_pauses;
        SQL);
    }
};
