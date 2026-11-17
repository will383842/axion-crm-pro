<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi FINI-M0B — codes de confirmation d'ajout d'une boîte : jamais le code, seulement son empreinte HMAC ; la demande
 * d'ajout est liée par l'empreinte SHA-256 de son corps SANS le secret. Un code se consomme une seule fois
 * (`UPDATE … WHERE consomme_le IS NULL RETURNING`), tentatives plafonnées à 5. `DELETE`/`TRUNCATE` refusés (ajout seul par règle).
 * RLS forcée par espace. Migration seule : rien ne la lit. `down()` inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_boites_codes_ajout (
                id                 UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                workspace_id       UUID        NOT NULL REFERENCES workspaces(id),
                user_id            UUID        NOT NULL REFERENCES users(id),
                empreinte          TEXT        NOT NULL CHECK (length(empreinte) > 0),
                demande_empreinte  TEXT        NOT NULL CHECK (length(demande_empreinte) > 0),
                tentatives         SMALLINT    NOT NULL DEFAULT 0 CHECK (tentatives BETWEEN 0 AND 5),
                expire_le          TIMESTAMPTZ NOT NULL,
                consomme_le        TIMESTAMPTZ NULL,
                cree_le            TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX IF NOT EXISTS envoi_boites_codes_ajout_user ON envoi_boites_codes_ajout (workspace_id, user_id, cree_le DESC);
            ALTER TABLE envoi_boites_codes_ajout ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_boites_codes_ajout FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_boites_codes_ajout_workspace_isolation ON envoi_boites_codes_ajout;
            CREATE POLICY envoi_boites_codes_ajout_workspace_isolation ON envoi_boites_codes_ajout FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            CREATE OR REPLACE FUNCTION envoi_boites_codes_ajout_pas_de_suppression() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_boites_codes_ajout : DELETE/TRUNCATE refusés (ajout seulement)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_boites_codes_ajout_pas_de_delete ON envoi_boites_codes_ajout;
            CREATE TRIGGER envoi_boites_codes_ajout_pas_de_delete BEFORE DELETE ON envoi_boites_codes_ajout
                FOR EACH ROW EXECUTE FUNCTION envoi_boites_codes_ajout_pas_de_suppression();
            DROP TRIGGER IF EXISTS envoi_boites_codes_ajout_pas_de_truncate ON envoi_boites_codes_ajout;
            CREATE TRIGGER envoi_boites_codes_ajout_pas_de_truncate BEFORE TRUNCATE ON envoi_boites_codes_ajout
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_boites_codes_ajout_pas_de_suppression();
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE ALL ON public.envoi_boites_codes_ajout FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_boites_codes_ajout TO ' . $role);
            DB::statement('GRANT UPDATE (tentatives, consomme_le) ON public.envoi_boites_codes_ajout TO ' . $role);
        }
    }

    /** Retour arrière INERTE : les codes (empreintes) sont conservés. */
    public function down(): void {}
};
