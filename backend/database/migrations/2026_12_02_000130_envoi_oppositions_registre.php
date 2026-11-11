<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi R13 — registre d'opposition MANUEL (adresse, organisation, domaine), AJOUT SEUL : un déclencheur refuse UPDATE,
 * DELETE et TRUNCATE ; droits SELECT + INSERT seulement. RLS ENABLE + FORCE sur app.current_workspace_id.
 * Adresse : empreinte + valeur chiffrée (valeur en clair NULL). Domaine et organisation (SIREN) : en clair normalisé.
 * Une ligne par (espace, portée, empreinte) : l'inscription est idempotente. Pas de « retirer » : levée = décision humaine
 * hors écran. ADDITIF SEULEMENT. `down()` inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_oppositions_registre (
                id             BIGSERIAL   PRIMARY KEY,
                workspace_id   UUID        NOT NULL, -- pas de clé étrangère : l'historique survit à tout
                portee         TEXT        NOT NULL CHECK (portee IN ('adresse', 'organisation', 'domaine')),
                empreinte      TEXT        NOT NULL CHECK (empreinte ~ '^[0-9a-f]{64}$'),
                valeur         TEXT        NULL,
                valeur_chiffree TEXT       NULL,
                motif          TEXT        NOT NULL CHECK (char_length(motif) BETWEEN 1 AND 500 AND position('@' IN motif) = 0),
                source         TEXT        NOT NULL DEFAULT 'manuel' CHECK (source = 'manuel'),
                auteur         TEXT        NOT NULL CHECK (auteur <> ''),
                cree_le        TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT envoi_oppositions_registre_une_par_valeur UNIQUE (workspace_id, portee, empreinte),
                CONSTRAINT envoi_oppositions_registre_forme_valeur CHECK (
                    (portee = 'adresse' AND valeur IS NULL AND valeur_chiffree IS NOT NULL)
                    OR (portee <> 'adresse' AND valeur IS NOT NULL AND valeur <> '' AND valeur_chiffree IS NULL)
                )
            )
            SQL,
        );
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION envoi_oppositions_registre_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_oppositions_registre : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public
            SQL);
        DB::statement('DROP TRIGGER IF EXISTS envoi_oppositions_registre_ajout_seul ON envoi_oppositions_registre');
        DB::statement('CREATE TRIGGER envoi_oppositions_registre_ajout_seul BEFORE UPDATE OR DELETE ON envoi_oppositions_registre FOR EACH ROW EXECUTE FUNCTION envoi_oppositions_registre_ajout_seul()');
        DB::statement('DROP TRIGGER IF EXISTS envoi_oppositions_registre_pas_de_truncate ON envoi_oppositions_registre');
        DB::statement('CREATE TRIGGER envoi_oppositions_registre_pas_de_truncate BEFORE TRUNCATE ON envoi_oppositions_registre FOR EACH STATEMENT EXECUTE FUNCTION envoi_oppositions_registre_ajout_seul()');
        DB::statement('ALTER TABLE envoi_oppositions_registre ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE envoi_oppositions_registre FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS envoi_oppositions_registre_workspace_isolation ON envoi_oppositions_registre');
        DB::statement("CREATE POLICY envoi_oppositions_registre_workspace_isolation ON envoi_oppositions_registre FOR ALL
            USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
            WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))");
        DB::statement("COMMENT ON TABLE envoi_oppositions_registre IS 'Envoi R13 : registre d''opposition manuel (adresse|organisation|domaine), ajout seul.'");

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE ALL ON public.envoi_oppositions_registre FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_oppositions_registre TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_oppositions_registre_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : le registre est conservé (ajout seul). */
    public function down(): void
    {
        // Volontairement vide (aucun effacement, aucune protection retirée).
    }
};
