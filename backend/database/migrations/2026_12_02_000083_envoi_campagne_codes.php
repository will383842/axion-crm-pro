<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi R08 — codes de validation d'une campagne : on ne stocke JAMAIS le code, seulement son empreinte (SHA-256, 64 hex).
 * AJOUT SEUL : jamais de DELETE/TRUNCATE ; seules `tentatives` (qui ne fait que monter) et `consomme_le` (posé une seule
 * fois) évoluent, tout le reste est figé. RLS par espace. `down()` inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_campagne_codes (
                id           BIGSERIAL   PRIMARY KEY,
                workspace_id UUID        NOT NULL REFERENCES workspaces(id),
                campagne_id  UUID        NOT NULL,
                empreinte    TEXT        NOT NULL CHECK (empreinte ~ '^[0-9a-f]{64}$'),
                expire_le    TIMESTAMPTZ NOT NULL,
                tentatives   SMALLINT    NOT NULL DEFAULT 0 CHECK (tentatives BETWEEN 0 AND 100),
                consomme_le  TIMESTAMPTZ NULL,
                cree_le      TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT envoi_campagne_codes_campagne FOREIGN KEY (campagne_id, workspace_id) REFERENCES envoi_campagnes (id, workspace_id)
            );
            CREATE INDEX IF NOT EXISTS envoi_campagne_codes_campagne_idx ON envoi_campagne_codes (campagne_id, id DESC);
            ALTER TABLE envoi_campagne_codes ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_campagne_codes FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_campagne_codes_workspace_isolation ON envoi_campagne_codes;
            CREATE POLICY envoi_campagne_codes_workspace_isolation ON envoi_campagne_codes FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            CREATE OR REPLACE FUNCTION envoi_campagne_codes_garde() RETURNS trigger AS $f$
            BEGIN
                IF TG_OP = 'UPDATE'
                   AND (NEW.id, NEW.workspace_id, NEW.campagne_id, NEW.empreinte, NEW.expire_le, NEW.cree_le)
                       IS NOT DISTINCT FROM (OLD.id, OLD.workspace_id, OLD.campagne_id, OLD.empreinte, OLD.expire_le, OLD.cree_le)
                   AND NEW.tentatives >= OLD.tentatives
                   AND (OLD.consomme_le IS NULL OR NEW.consomme_le IS NOT DISTINCT FROM OLD.consomme_le) THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'envoi_campagne_codes : ajout seulement (seuls tentatives et consomme_le évoluent ; DELETE/TRUNCATE refusés)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_campagne_codes_garde ON envoi_campagne_codes;
            CREATE TRIGGER envoi_campagne_codes_garde BEFORE UPDATE OR DELETE ON envoi_campagne_codes FOR EACH ROW EXECUTE FUNCTION envoi_campagne_codes_garde();
            DROP TRIGGER IF EXISTS envoi_campagne_codes_pas_de_truncate ON envoi_campagne_codes;
            CREATE TRIGGER envoi_campagne_codes_pas_de_truncate BEFORE TRUNCATE ON envoi_campagne_codes FOR EACH STATEMENT EXECUTE FUNCTION envoi_campagne_codes_garde();
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE ALL ON public.envoi_campagne_codes FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_campagne_codes TO ' . $role);
            DB::statement('GRANT UPDATE (tentatives, consomme_le) ON public.envoi_campagne_codes TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_campagne_codes_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : les empreintes sont conservées. */
    public function down(): void {}
};
