<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L6 — événements de retour lus par IMAP, AJOUT SEUL (trigger : ni
 * UPDATE, ni DELETE, ni TRUNCATE). Aucun corps de message, aucune adresse en
 * clair : empreintes SHA-256 seulement. Identifiant d'événement = (boîte,
 * UIDVALIDITY, UID) ; second garde-fou (boîte, empreinte du Message-ID) pour
 * qu'une renumérotation des UID ne crée aucun doublon. `down()` INERTE.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_evenements (
                id                    BIGSERIAL   PRIMARY KEY,
                workspace_id          UUID        NOT NULL REFERENCES workspaces(id),
                boite                 TEXT        NOT NULL,
                uid_validity          BIGINT      NOT NULL,
                uid                   BIGINT      NOT NULL,
                empreinte_message     CHAR(64)    NOT NULL,
                classe                TEXT        NOT NULL
                                      CHECK (classe IN ('reponse_humaine','auto_reponse','desinscription','hostile','rebond_dur','rebond_mou')),
                statut                TEXT        NOT NULL CHECK (statut IN ('rattache','a_rattacher')),
                id_injection          TEXT,
                empreinte_expediteur  CHAR(64)    NOT NULL,
                empreinte_destinataire CHAR(64),
                code_dsn              TEXT,
                recu_le               TIMESTAMPTZ NOT NULL,
                cree_le               TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT envoi_evenements_rattache_coherent
                    CHECK ((statut = 'rattache') = (id_injection IS NOT NULL))
            );
            CREATE UNIQUE INDEX IF NOT EXISTS envoi_evenements_cle_imap
                ON envoi_evenements (workspace_id, boite, uid_validity, uid);
            CREATE UNIQUE INDEX IF NOT EXISTS envoi_evenements_cle_message
                ON envoi_evenements (workspace_id, boite, empreinte_message);
            CREATE INDEX IF NOT EXISTS envoi_evenements_a_rattacher
                ON envoi_evenements (workspace_id, recu_le) WHERE statut = 'a_rattacher';
            CREATE OR REPLACE FUNCTION envoi_evenements_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_evenements : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END;
            $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_evenements_ajout_seul ON envoi_evenements;
            CREATE TRIGGER envoi_evenements_ajout_seul
                BEFORE UPDATE OR DELETE ON envoi_evenements
                FOR EACH ROW EXECUTE FUNCTION envoi_evenements_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_evenements_pas_de_truncate ON envoi_evenements;
            CREATE TRIGGER envoi_evenements_pas_de_truncate
                BEFORE TRUNCATE ON envoi_evenements
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_evenements_ajout_seul();
            ALTER TABLE envoi_evenements ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_evenements FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_evenements_workspace_isolation ON envoi_evenements;
            CREATE POLICY envoi_evenements_workspace_isolation ON envoi_evenements FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            COMMENT ON TABLE envoi_evenements IS 'Envoi P0 L6 : retours IMAP (empreintes, aucun corps). Ajout seul.';
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.envoi_evenements FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_evenements TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_evenements_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : la protection « ajout seul » et les lignes sont conservées. */
    public function down(): void
    {
        // Volontairement vide (aucun effacement, aucune protection retirée).
    }
};
