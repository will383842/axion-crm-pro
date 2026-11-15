<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi S1-B (D12) — décisions de feu vert final : « accorde » ou « retire », auteur, motif, empreinte de la liste vue, points en JSONB.
 * La DERNIÈRE décision (plus grand `id`) fait foi. AJOUT SEUL, RLS forcée par espace, droits SELECT + INSERT.
 * `motif` est un texte libre : aucun `@` (aucune adresse dans la base d'envoi), comme pour les essais. down() inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_feu_vert_decisions (
                id              BIGSERIAL   PRIMARY KEY,
                workspace_id    UUID        NOT NULL REFERENCES workspaces(id),
                decision        TEXT        NOT NULL CHECK (decision IN ('accorde', 'retire')),
                auteur          TEXT        NOT NULL CHECK (char_length(auteur) BETWEEN 1 AND 100 AND position('@' IN auteur) = 0),
                motif           TEXT        NOT NULL CHECK (char_length(motif) <= 500 AND position('@' IN motif) = 0),
                empreinte_liste TEXT        NOT NULL CHECK (char_length(empreinte_liste) BETWEEN 1 AND 128),
                points          JSONB       NOT NULL DEFAULT '{}'::jsonb CHECK (position('@' IN points::text) = 0),
                cree_le         TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX IF NOT EXISTS envoi_feu_vert_decisions_derniere ON envoi_feu_vert_decisions (workspace_id, id DESC);
            ALTER TABLE envoi_feu_vert_decisions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_feu_vert_decisions FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_feu_vert_decisions_workspace_isolation ON envoi_feu_vert_decisions;
            CREATE POLICY envoi_feu_vert_decisions_workspace_isolation ON envoi_feu_vert_decisions FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            CREATE OR REPLACE FUNCTION envoi_feu_vert_decisions_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_feu_vert_decisions : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_feu_vert_decisions_ajout_seul ON envoi_feu_vert_decisions;
            CREATE TRIGGER envoi_feu_vert_decisions_ajout_seul BEFORE UPDATE OR DELETE ON envoi_feu_vert_decisions
                FOR EACH ROW EXECUTE FUNCTION envoi_feu_vert_decisions_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_feu_vert_decisions_pas_de_truncate ON envoi_feu_vert_decisions;
            CREATE TRIGGER envoi_feu_vert_decisions_pas_de_truncate BEFORE TRUNCATE ON envoi_feu_vert_decisions
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_feu_vert_decisions_ajout_seul();
        SQL);

        $nom = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($nom !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$nom]) !== null) {
            $role = '"' . str_replace('"', '""', $nom) . '"';
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.envoi_feu_vert_decisions FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_feu_vert_decisions TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_feu_vert_decisions_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : l'historique des décisions est conservé. */
    public function down(): void {}
};
