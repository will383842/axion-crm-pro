<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `finess_etablissements` — les ÉTABLISSEMENTS FINESS rattachés à une fiche.
 *
 * `companies` est unique par (espace, SIREN) : une société qui exploite
 * plusieurs officines n'a qu'UNE fiche. Les autres établissements (SIRET
 * différents) sont donc notés ici, SANS toucher la fiche existante
 * (`crm:pharmacies:import-finess`). Sert aussi au décompte « nombre
 * d'officines de la même société » (`crm:pharmacies:classer-taille`).
 *
 * On GARDE TOUT : `company_id` en RESTRICT, ni DELETE ni TRUNCATE pour le rôle
 * applicatif, RLS stricte par espace (patron de `companies_opco`).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS finess_etablissements (
                id           BIGSERIAL PRIMARY KEY,
                workspace_id UUID   NOT NULL REFERENCES workspaces(id) ON DELETE RESTRICT,
                company_id   BIGINT NOT NULL REFERENCES companies(id) ON DELETE RESTRICT,
                siret        CHAR(14) NOT NULL CHECK (siret ~ '^[0-9]{14}$'),
                finess_et    TEXT,
                categorie    TEXT NOT NULL,
                code_postal  TEXT,
                commune      TEXT,
                departement  TEXT,
                created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT finess_etablissements_siret_unique UNIQUE (workspace_id, siret)
            )
            SQL,
        );
        DB::unprepared(
            <<<'SQL'
            CREATE OR REPLACE FUNCTION public.finess_etablissements_meme_espace() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = public, pg_catalog
            AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM companies c WHERE c.id = NEW.company_id AND c.workspace_id = NEW.workspace_id) THEN
                    RAISE EXCEPTION 'finess_etablissements : l entreprise % n est pas de cet espace', NEW.company_id
                        USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;

            DROP TRIGGER IF EXISTS finess_etablissements_meme_espace ON finess_etablissements;
            CREATE TRIGGER finess_etablissements_meme_espace
                BEFORE INSERT OR UPDATE OF workspace_id, company_id ON finess_etablissements
                FOR EACH ROW EXECUTE FUNCTION public.finess_etablissements_meme_espace();
            SQL,
        );
        DB::statement("COMMENT ON TABLE finess_etablissements IS 'Établissements FINESS rattachés à une fiche. GRANT DELETE et TRUNCATE interdits au rôle applicatif : on garde tout.'");
        DB::statement('CREATE INDEX IF NOT EXISTS idx_finess_etablissements_company ON finess_etablissements (company_id)');

        DB::statement('ALTER TABLE finess_etablissements ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE finess_etablissements FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS finess_etablissements_workspace_isolation ON finess_etablissements');
        DB::statement(
            "CREATE POLICY finess_etablissements_workspace_isolation ON finess_etablissements FOR ALL
             USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
             WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))",
        );

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT SELECT, INSERT, UPDATE ON public.finess_etablissements TO ' . $role);
            DB::statement('REVOKE DELETE, TRUNCATE ON public.finess_etablissements FROM ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.finess_etablissements_id_seq TO ' . $role);
        }
    }

    public function down(): void
    {
        // On garde tout : aucune suppression de table n'est jamais jouée.
    }
};
