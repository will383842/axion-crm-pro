<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L6 — registre des messages ENVOYÉS : Message-ID (empreinte SHA-256) → injection. Source du
 * rattachement d'une réponse ou d'un rebond. Alimenté par le lot d'injection ; l'adresse n'est pas
 * stockée ici (empreinte seulement).
 * AJOUT SEUL (trigger : ni UPDATE, ni DELETE, ni TRUNCATE), RLS par espace.
 * Aucune adresse en clair, aucun corps. `down()` INERTE.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_messages_envoyes (
                id                     BIGSERIAL   PRIMARY KEY,
                workspace_id           UUID        NOT NULL REFERENCES workspaces(id),
                message_id_empreinte   CHAR(64)    NOT NULL,
                id_injection           UUID        NOT NULL,
                empreinte_destinataire CHAR(64)    NOT NULL,
                cree_le                TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE UNIQUE INDEX IF NOT EXISTS envoi_messages_envoyes_message ON envoi_messages_envoyes (workspace_id, message_id_empreinte);
            CREATE OR REPLACE FUNCTION envoi_messages_envoyes_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_messages_envoyes : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END;
            $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_messages_envoyes_ajout_seul ON envoi_messages_envoyes;
            CREATE TRIGGER envoi_messages_envoyes_ajout_seul
                BEFORE UPDATE OR DELETE ON envoi_messages_envoyes
                FOR EACH ROW EXECUTE FUNCTION envoi_messages_envoyes_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_messages_envoyes_pas_de_truncate ON envoi_messages_envoyes;
            CREATE TRIGGER envoi_messages_envoyes_pas_de_truncate
                BEFORE TRUNCATE ON envoi_messages_envoyes
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_messages_envoyes_ajout_seul();
            CREATE OR REPLACE FUNCTION envoi_verifie_id_injection() RETURNS trigger AS $f$
            BEGIN
                IF NEW.id_injection IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM envoi_injections_smtp i WHERE i.id_injection = NEW.id_injection AND i.workspace_id = NEW.workspace_id
                ) THEN
                    RAISE EXCEPTION 'id_injection inconnu pour cet espace (envoi_injections_smtp)' USING ERRCODE = '23503';
                END IF;
                RETURN NEW;
            END;
            $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_messages_envoyes_id_injection ON envoi_messages_envoyes;
            CREATE TRIGGER envoi_messages_envoyes_id_injection
                BEFORE INSERT ON envoi_messages_envoyes
                FOR EACH ROW EXECUTE FUNCTION envoi_verifie_id_injection();
            ALTER TABLE envoi_messages_envoyes ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_messages_envoyes FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_messages_envoyes_workspace_isolation ON envoi_messages_envoyes;
            CREATE POLICY envoi_messages_envoyes_workspace_isolation ON envoi_messages_envoyes FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            COMMENT ON TABLE envoi_messages_envoyes IS 'Envoi P0 L6 : registre des messages envoyes (rattachement des retours). Ajout seul.';
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.envoi_messages_envoyes FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_messages_envoyes TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_messages_envoyes_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : la protection « ajout seul » et les lignes sont conservées. */
    public function down(): void
    {
        // Volontairement vide (aucun effacement, aucune protection retirée).
    }
};
