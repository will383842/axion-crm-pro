<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi R14 — réglages d'envoi clé/valeur bornés (absence, plafonds, horaires). UPSERT permis ; DELETE et TRUNCATE retirés au rôle applicatif (comme envoi_seuils) ; RLS par espace.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_reglages (
                id            BIGSERIAL   PRIMARY KEY,
                workspace_id  UUID        NOT NULL REFERENCES workspaces(id),
                cle           TEXT        NOT NULL CHECK (cle ~ '^[a-z0-9_.]{1,60}$'),
                valeur        TEXT        NOT NULL CHECK (char_length(valeur) <= 500),
                auteur        TEXT        NOT NULL CHECK (char_length(auteur) BETWEEN 1 AND 100),
                mis_a_jour_le TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (workspace_id, cle)
            );
            ALTER TABLE envoi_reglages ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_reglages FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_reglages_workspace_isolation ON envoi_reglages;
            CREATE POLICY envoi_reglages_workspace_isolation ON envoi_reglages FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE DELETE, TRUNCATE ON public.envoi_reglages FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT, UPDATE ON public.envoi_reglages TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_reglages_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : les réglages sont conservés. */
    public function down(): void {}
};
