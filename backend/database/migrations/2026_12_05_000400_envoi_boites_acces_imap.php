<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi D1-L1 — accès IMAP chiffrés des boîtes. AJOUT SEUL (UPDATE/DELETE/TRUNCATE refusés), RLS par espace, droits SELECT + INSERT.
 * Hôte en liste fermée (CHECK, pas de SSRF), port 993 et dossier INBOX seuls. La dernière ligne fait foi : retirer un accès =
 * ajouter une ligne `actif = false` (sans secret). Le secret est chiffré par l'application, jamais en clair.
 * Écart au cahier : la boîte est vérifiée par un déclencheur (même espace, même code 23503) et non par une clé étrangère, car le
 * retour de `envoi_boites` (000040, DROP TABLE) est rejoué par NR-17 et échouerait devant une table dépendante.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_boites_acces_imap (
                id                  BIGSERIAL   PRIMARY KEY,
                workspace_id        UUID        NOT NULL REFERENCES workspaces(id),
                boite_id            UUID        NOT NULL,
                hote                TEXT        NULL CHECK (hote IS NULL OR hote IN ('imap.gmail.com', 'imap.mail.yahoo.com', 'imap.orange.fr', 'outlook.office365.com')),
                port                INTEGER     NULL CHECK (port IS NULL OR port = 993),
                dossier             TEXT        NULL CHECK (dossier IS NULL OR dossier = 'INBOX'),
                identifiant_chiffre TEXT        NULL,
                actif               BOOLEAN     NOT NULL,
                cree_le             TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT envoi_boites_acces_imap_forme CHECK (
                    (actif AND hote IS NOT NULL AND port IS NOT NULL AND dossier IS NOT NULL
                         AND identifiant_chiffre IS NOT NULL AND char_length(identifiant_chiffre) > 0)
                 OR (NOT actif AND identifiant_chiffre IS NULL))
            );
            CREATE INDEX IF NOT EXISTS envoi_boites_acces_imap_derniere ON envoi_boites_acces_imap (workspace_id, boite_id, id DESC);
            COMMENT ON COLUMN envoi_boites_acces_imap.identifiant_chiffre IS 'Crypt::encryptString(json {u, s}). Jamais en clair.';
            ALTER TABLE envoi_boites_acces_imap ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_boites_acces_imap FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_boites_acces_imap_workspace_isolation ON envoi_boites_acces_imap;
            CREATE POLICY envoi_boites_acces_imap_workspace_isolation ON envoi_boites_acces_imap FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            CREATE OR REPLACE FUNCTION envoi_boites_acces_imap_boite_valide() RETURNS trigger AS $f$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM public.envoi_boites b WHERE b.workspace_id = NEW.workspace_id AND b.id = NEW.boite_id) THEN
                    RAISE EXCEPTION 'envoi_boites_acces_imap : boîte inconnue de cet espace' USING ERRCODE = 'foreign_key_violation';
                END IF;
                RETURN NEW;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_boites_acces_imap_boite_valide ON envoi_boites_acces_imap;
            CREATE TRIGGER envoi_boites_acces_imap_boite_valide BEFORE INSERT ON envoi_boites_acces_imap
                FOR EACH ROW EXECUTE FUNCTION envoi_boites_acces_imap_boite_valide();
            CREATE OR REPLACE FUNCTION envoi_boites_acces_imap_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_boites_acces_imap : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_boites_acces_imap_ajout_seul ON envoi_boites_acces_imap;
            CREATE TRIGGER envoi_boites_acces_imap_ajout_seul BEFORE UPDATE OR DELETE ON envoi_boites_acces_imap
                FOR EACH ROW EXECUTE FUNCTION envoi_boites_acces_imap_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_boites_acces_imap_pas_de_truncate ON envoi_boites_acces_imap;
            CREATE TRIGGER envoi_boites_acces_imap_pas_de_truncate BEFORE TRUNCATE ON envoi_boites_acces_imap
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_boites_acces_imap_ajout_seul();
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.envoi_boites_acces_imap FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_boites_acces_imap TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_boites_acces_imap_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : les accès posés sont conservés. */
    public function down(): void {}
};
