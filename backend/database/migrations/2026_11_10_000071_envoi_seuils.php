<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L7 — reglages de seuils par espace (plus STRICTS que les constantes, jamais plus laches). AJOUT SEUL : le rôle applicatif n'a ni DELETE ni TRUNCATE.
 * workspace_id + RLS FORCE (copie de 2026_11_10_000020). down() ne retire la
 * table que si elle est vide (aucun effacement de données).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_seuils (
                id           BIGSERIAL   PRIMARY KEY,
                workspace_id UUID        NOT NULL REFERENCES workspaces(id),
                nom          TEXT        NOT NULL,
                valeur       NUMERIC     NOT NULL,
                auteur       TEXT        NOT NULL,
                mis_a_jour_le TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (workspace_id, nom)
            )
            SQL,
        );

        DB::statement('ALTER TABLE envoi_seuils ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE envoi_seuils FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS envoi_seuils_workspace_isolation ON envoi_seuils');
        DB::statement(self::politique('envoi_seuils'));
        DB::statement("COMMENT ON TABLE envoi_seuils IS 'Envoi P0 L7 : reglages de seuils par espace (plus STRICTS que les constantes, jamais plus laches). Ajout seul.'");

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT SELECT, INSERT, UPDATE ON public.envoi_seuils TO ' . $role);
            DB::statement('REVOKE DELETE, TRUNCATE ON public.envoi_seuils FROM ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_seuils_id_seq TO ' . $role);
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
                IF EXISTS (SELECT 1 FROM envoi_seuils) THEN
                    RAISE EXCEPTION 'envoi_seuils contient des lignes : retour refusé (aucun effacement)';
                END IF;
            END $$;
            DROP TABLE IF EXISTS envoi_seuils;
        SQL);
    }
};
