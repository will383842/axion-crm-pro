<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L5 (suivi) — état de la relance des effets d'opposition, en BASE (il était en cache Redis, vidé par
 * `cache:clear` à chaque déploiement). AJOUT SEUL d'une table scopée par espace (RLS) ; rien n'est supprimé.
 *
 *  - cle = 'opposition:<id>' : essais et `pas_avant` (délai croissant) ; `alerte_le` : alerte critique déjà émise.
 *  - cle = 'espace_supprime'  : `journal_le` = dernier journal « espace supprimé » (au plus un par jour et par espace).
 *
 * L'état se met à jour (UPSERT) mais ne se supprime jamais : le rôle applicatif n'a pas le droit DELETE.
 * `down()` INERTE.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_relances_effets (
                id           BIGSERIAL   PRIMARY KEY,
                workspace_id UUID        NOT NULL REFERENCES workspaces(id),
                cle          TEXT        NOT NULL CHECK (cle ~ '^(opposition:[0-9]+|espace_supprime)$'),
                essais       INTEGER     NOT NULL DEFAULT 0 CHECK (essais >= 0),
                pas_avant    TIMESTAMPTZ,
                alerte_le    TIMESTAMPTZ,
                journal_le   TIMESTAMPTZ,
                mis_a_jour_le TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            SQL,
        );
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_envoi_relances_effets_cle ON envoi_relances_effets (workspace_id, cle)');
        DB::statement('ALTER TABLE envoi_relances_effets ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE envoi_relances_effets FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS envoi_relances_effets_workspace_isolation ON envoi_relances_effets');
        DB::statement(
            "CREATE POLICY envoi_relances_effets_workspace_isolation ON envoi_relances_effets FOR ALL
            USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
            WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))",
        );
        DB::statement("COMMENT ON TABLE envoi_relances_effets IS 'Envoi P0 L5 : etat de la relance des effets d opposition (essais, delai, journaux). Jamais supprime.'");

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT SELECT, INSERT, UPDATE ON public.envoi_relances_effets TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_relances_effets_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : l'état de relance est conservé. */
    public function down(): void
    {
        // Volontairement vide.
    }
};
