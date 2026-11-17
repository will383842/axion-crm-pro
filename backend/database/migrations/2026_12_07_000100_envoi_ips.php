<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FINI-M0A (X5, X6) — inventaire des IP d'envoi, par espace et par flux (prospection ou lettre). MIGRATION SEULE : rien ne la lit encore.
 * AJOUT SEUL : DELETE/TRUNCATE refusés ; UPDATE permis uniquement de `retiree_le` NULL → non NULL (jamais l'inverse, jamais `ref`
 * ni `flux`). Une IP ne change jamais de flux : on la retire et on en inscrit une autre (sens sûr). RLS forcée par espace.
 * Modèle : 2026_12_05_000400_envoi_boites_acces_imap.php. `down()` inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_ips (
                id           UUID        PRIMARY KEY,
                workspace_id UUID        NOT NULL REFERENCES workspaces(id),
                ref          TEXT        NOT NULL CHECK (ref ~ '^[0-9]{1,3}(\.[0-9]{1,3}){3}$'),
                flux         TEXT        NOT NULL CHECK (flux IN ('prospection', 'lettre')),
                auteur       TEXT        NOT NULL,
                cree_le      TIMESTAMPTZ NOT NULL DEFAULT now(),
                retiree_le   TIMESTAMPTZ NULL,
                UNIQUE (workspace_id, ref),
                UNIQUE (workspace_id, id)
            );
            COMMENT ON TABLE envoi_ips IS 'FINI-M0A : inventaire des IP d''envoi par espace et flux. Ajout seul ; seul retiree_le NULL -> non NULL est permis.';
            ALTER TABLE envoi_ips ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_ips FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_ips_workspace_isolation ON envoi_ips;
            CREATE POLICY envoi_ips_workspace_isolation ON envoi_ips FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));

            CREATE OR REPLACE FUNCTION envoi_ips_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                IF TG_OP = 'UPDATE'
                   AND OLD.retiree_le IS NULL AND NEW.retiree_le IS NOT NULL
                   AND NEW.id = OLD.id AND NEW.workspace_id = OLD.workspace_id AND NEW.ref = OLD.ref
                   AND NEW.flux = OLD.flux AND NEW.auteur = OLD.auteur AND NEW.cree_le = OLD.cree_le THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'envoi_ips : ajout seulement (seul le retrait, retiree_le NULL vers non NULL, est permis ; DELETE/TRUNCATE refusés)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_ips_ajout_seul ON envoi_ips;
            CREATE TRIGGER envoi_ips_ajout_seul BEFORE UPDATE OR DELETE ON envoi_ips
                FOR EACH ROW EXECUTE FUNCTION envoi_ips_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_ips_pas_de_truncate ON envoi_ips;
            CREATE TRIGGER envoi_ips_pas_de_truncate BEFORE TRUNCATE ON envoi_ips
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_ips_ajout_seul();
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE DELETE, TRUNCATE ON public.envoi_ips FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_ips TO ' . $role);
            DB::statement('GRANT UPDATE (retiree_le) ON public.envoi_ips TO ' . $role);
        }
    }

    /** Retour arrière INERTE : la table, les lignes et les protections sont conservées. */
    public function down(): void {}
};
