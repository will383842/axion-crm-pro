<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi L8-F07a — reculs de chauffe (IP ou domaine), AJOUT SEUL : un trigger refuse UPDATE, DELETE et TRUNCATE ;
 * droits SELECT + INSERT seulement. RLS ENABLE + FORCE sur app.current_workspace_id. Une ligne = un recul d'UN
 * palier sur UNE ressource nommée, un jour donné (unique : un second recul le même jour ne recule pas deux fois).
 * ADDITIF SEULEMENT. `down()` inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_chauffe_reculs (
                id             BIGSERIAL   PRIMARY KEY,
                workspace_id   UUID        NOT NULL, -- pas de clé étrangère : l'historique survit à tout
                ressource_type TEXT        NOT NULL CHECK (ressource_type IN ('ip', 'domaine')),
                ressource      TEXT        NOT NULL CHECK (ressource <> ''),
                motif          TEXT        NOT NULL CHECK (motif <> ''),
                auteur         TEXT        NOT NULL CHECK (auteur <> ''),
                date_recul     DATE        NOT NULL,
                cree_le        TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT envoi_chauffe_reculs_un_par_jour UNIQUE (workspace_id, ressource_type, ressource, date_recul)
            )
            SQL,
        );
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION envoi_chauffe_reculs_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_chauffe_reculs : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public
            SQL);
        DB::statement('DROP TRIGGER IF EXISTS envoi_chauffe_reculs_ajout_seul ON envoi_chauffe_reculs');
        DB::statement('CREATE TRIGGER envoi_chauffe_reculs_ajout_seul BEFORE UPDATE OR DELETE ON envoi_chauffe_reculs FOR EACH ROW EXECUTE FUNCTION envoi_chauffe_reculs_ajout_seul()');
        DB::statement('DROP TRIGGER IF EXISTS envoi_chauffe_reculs_pas_de_truncate ON envoi_chauffe_reculs');
        DB::statement('CREATE TRIGGER envoi_chauffe_reculs_pas_de_truncate BEFORE TRUNCATE ON envoi_chauffe_reculs FOR EACH STATEMENT EXECUTE FUNCTION envoi_chauffe_reculs_ajout_seul()');
        DB::statement('ALTER TABLE envoi_chauffe_reculs ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE envoi_chauffe_reculs FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS envoi_chauffe_reculs_workspace_isolation ON envoi_chauffe_reculs');
        DB::statement("CREATE POLICY envoi_chauffe_reculs_workspace_isolation ON envoi_chauffe_reculs FOR ALL
            USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
            WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))");
        DB::statement("COMMENT ON TABLE envoi_chauffe_reculs IS 'Envoi L8-F07a : reculs de chauffe (ip|domaine), ajout seul.'");

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE ALL ON public.envoi_chauffe_reculs FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_chauffe_reculs TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_chauffe_reculs_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : les reculs sont conservés (ajout seul). */
    public function down(): void
    {
        // Volontairement vide (aucun effacement, aucune protection retirée).
    }
};
