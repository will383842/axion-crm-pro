<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Contrat v3 de l'origine (ADR-CONTRAT-V3-ORIGINE § 2) — ADDITIF SEULEMENT.
 *
 *  - `personnes.origines` jsonb NULL : historique des origines d'une personne,
 *    union sans retrait, 50 entrées au plus ;
 *  - `personne_origines_archive` : au-delà de 50, la plus ancienne y est
 *    DÉPLACÉE (jamais supprimée). Table en AJOUT SEUL : le rôle applicatif
 *    n'a ni UPDATE, ni DELETE, ni TRUNCATE ;
 *  - index GIN (`jsonb_path_ops`) pour le filtre famille/détail de l'API.
 *
 * Aucun DROP, aucune suppression ; `down()` ne retire rien. Rien n'est lu ni
 * écrit tant que l'interrupteur `origines.v3_enabled` est éteint.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE personnes ADD COLUMN IF NOT EXISTS origines jsonb NULL');
        DB::statement("COMMENT ON COLUMN personnes.origines IS 'Contrat v3 : historique des origines (union sans retrait, 50 max, puis archive). Jamais de pseudo, de nom ni de texte.'");
        DB::statement('CREATE INDEX IF NOT EXISTS idx_personnes_origines_gin ON personnes USING gin (origines jsonb_path_ops)');

        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS personne_origines_archive (
                id           BIGSERIAL   PRIMARY KEY,
                workspace_id UUID        NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                personne_id  BIGINT      NOT NULL,
                cle          TEXT        NOT NULL,
                origine      JSONB       NOT NULL,
                archive_le   TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            SQL,
        );
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS personne_origines_archive_personne_cle_key ON personne_origines_archive (personne_id, cle)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_personne_origines_archive_workspace ON personne_origines_archive (workspace_id, personne_id)');
        DB::statement("COMMENT ON TABLE personne_origines_archive IS 'Contrat v3 : origines au-dela de 50 par personne. Lecture seule, ajout seul, jamais de suppression.'");

        DB::statement('ALTER TABLE personne_origines_archive ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE personne_origines_archive FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS personne_origines_archive_workspace_isolation ON personne_origines_archive');
        DB::statement(
            "CREATE POLICY personne_origines_archive_workspace_isolation ON personne_origines_archive FOR ALL
             USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
             WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))",
        );

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.personne_origines_archive FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.personne_origines_archive TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.personne_origines_archive_id_seq TO ' . $role);
        }
    }

    /**
     * Retour arrière INERTE : on ne supprime jamais de donnée de personne. Colonne,
     * index et archive restent ; les retirer, un jour, serait une décision à part.
     */
    public function down(): void
    {
        DB::statement("COMMENT ON TABLE personne_origines_archive IS 'Contrat v3 : archive des origines. Conservee au retour arriere.'");
    }
};
