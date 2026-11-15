<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi S1-B (L8-COMPO) — injections « locales » : un identifiant UUID par clé d'idempotence pour les messages dont le serveur ne rend pas
 * un UUID connu de `envoi_injections_smtp` (faux serveur : `sim-N`, Kumo : son propre identifiant). AJOUT SEUL, RLS forcée, SELECT + INSERT.
 * `envoi_verifie_id_injection` (SECURITY INVOKER : le rôle applicatif a donc SELECT sur cette table) accepte un identifiant présent dans
 * `envoi_injections_smtp` OU dans `envoi_injections_locales`, pour le même espace ; un identifiant inconnu des deux est toujours refusé.
 * Le message d'erreur et le code SQLSTATE 23503 sont ceux de 2026_11_10_000064. down() inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_injections_locales (
                workspace_id    UUID        NOT NULL REFERENCES workspaces(id),
                id_injection    UUID        NOT NULL DEFAULT gen_random_uuid() UNIQUE,
                cle_idempotence TEXT        NOT NULL CHECK (char_length(cle_idempotence) BETWEEN 1 AND 200),
                adaptateur      TEXT        NOT NULL CHECK (adaptateur IN ('faux', 'kumo', 'smtp')),
                cree_le         TIMESTAMPTZ NOT NULL DEFAULT now(),
                PRIMARY KEY (workspace_id, cle_idempotence)
            );
            ALTER TABLE envoi_injections_locales ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_injections_locales FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_injections_locales_workspace_isolation ON envoi_injections_locales;
            CREATE POLICY envoi_injections_locales_workspace_isolation ON envoi_injections_locales FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            CREATE OR REPLACE FUNCTION envoi_injections_locales_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_injections_locales : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_injections_locales_ajout_seul ON envoi_injections_locales;
            CREATE TRIGGER envoi_injections_locales_ajout_seul BEFORE UPDATE OR DELETE ON envoi_injections_locales
                FOR EACH ROW EXECUTE FUNCTION envoi_injections_locales_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_injections_locales_pas_de_truncate ON envoi_injections_locales;
            CREATE TRIGGER envoi_injections_locales_pas_de_truncate BEFORE TRUNCATE ON envoi_injections_locales
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_injections_locales_ajout_seul();

            CREATE OR REPLACE FUNCTION envoi_verifie_id_injection() RETURNS trigger AS $f$
            BEGIN
                IF NEW.id_injection IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM envoi_injections_smtp i WHERE i.id_injection = NEW.id_injection AND i.workspace_id = NEW.workspace_id)
                   AND NOT EXISTS (SELECT 1 FROM envoi_injections_locales l WHERE l.id_injection = NEW.id_injection AND l.workspace_id = NEW.workspace_id)
                THEN
                    RAISE EXCEPTION 'id_injection inconnu pour cet espace (envoi_injections_smtp ou envoi_injections_locales)' USING ERRCODE = '23503';
                END IF;
                RETURN NEW;
            END;
            $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
        SQL);

        $nom = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($nom !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$nom]) !== null) {
            $role = '"' . str_replace('"', '""', $nom) . '"';
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.envoi_injections_locales FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_injections_locales TO ' . $role);
        }
    }

    /** Retour arrière INERTE : la table, ses lignes et la protection sont conservées (le déclencheur reste élargi). */
    public function down(): void {}
};
