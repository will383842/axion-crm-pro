<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi R16 — accès IMAP des témoins pour le frein automatique. AJOUT SEUL (UPDATE/DELETE/TRUNCATE refusés), RLS par espace,
 * droits SELECT + INSERT. L'hôte est contraint par un CHECK à la liste fermée codée (aucun hôte libre : pas de SSRF).
 * L'identifiant (adresse + mot de passe) est chiffré par l'application ; seule l'empreinte de l'adresse est en clair.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_temoins_acces (
                id                  BIGSERIAL   PRIMARY KEY,
                workspace_id        UUID        NOT NULL REFERENCES workspaces(id),
                empreinte_temoin    CHAR(64)    NOT NULL,
                fournisseur         TEXT        NOT NULL CHECK (fournisseur IN ('gmail', 'google_workspace', 'outlook_com', 'microsoft_365', 'yahoo', 'orange')),
                hote                TEXT        NOT NULL CHECK (hote IN ('imap.gmail.com', 'imap.mail.yahoo.com', 'imap.orange.fr', 'outlook.office365.com')),
                identifiant_chiffre TEXT        NOT NULL CHECK (char_length(identifiant_chiffre) > 0),
                cree_le             TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX IF NOT EXISTS envoi_temoins_acces_ws ON envoi_temoins_acces (workspace_id, empreinte_temoin, id DESC);
            ALTER TABLE envoi_temoins_acces ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_temoins_acces FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_temoins_acces_workspace_isolation ON envoi_temoins_acces;
            CREATE POLICY envoi_temoins_acces_workspace_isolation ON envoi_temoins_acces FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            CREATE OR REPLACE FUNCTION envoi_temoins_acces_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_temoins_acces : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_temoins_acces_ajout_seul ON envoi_temoins_acces;
            CREATE TRIGGER envoi_temoins_acces_ajout_seul BEFORE UPDATE OR DELETE ON envoi_temoins_acces
                FOR EACH ROW EXECUTE FUNCTION envoi_temoins_acces_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_temoins_acces_pas_de_truncate ON envoi_temoins_acces;
            CREATE TRIGGER envoi_temoins_acces_pas_de_truncate BEFORE TRUNCATE ON envoi_temoins_acces
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_temoins_acces_ajout_seul();
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.envoi_temoins_acces FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_temoins_acces TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_temoins_acces_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : les accès posés sont conservés. */
    public function down(): void {}
};
