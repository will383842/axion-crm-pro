<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L4 — suivi des injections SMTP par boîte. ADDITIF SEULEMENT.
 * Aucun contenu de message, aucune adresse en clair : clé d'idempotence,
 * état, code SMTP numérique. Aucun effacement (trigger). `down()` réel : ne retire que les objets créés ici (tables NEUVES).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_injections_smtp (
                id              BIGSERIAL   PRIMARY KEY,
                id_injection    UUID        NOT NULL DEFAULT gen_random_uuid(),
                workspace_id    UUID        NOT NULL REFERENCES workspaces(id),
                boite_id        UUID        NOT NULL REFERENCES envoi_boites(id),
                cle_idempotence TEXT        NOT NULL,
                etat            TEXT        NOT NULL DEFAULT 'a_injecter'
                                CHECK (etat IN ('a_injecter', 'injection_en_cours', 'accepte', 'a_verifier', 'reporte', 'echec')),
                code_smtp       INTEGER     NULL,
                reporte_au      TIMESTAMPTZ NULL,
                cree_le         TIMESTAMPTZ NOT NULL DEFAULT now(),
                maj_le          TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (workspace_id, cle_idempotence),
                UNIQUE (id_injection)
            )
            SQL,
        );
        DB::statement('CREATE INDEX IF NOT EXISTS idx_envoi_injections_smtp_boite ON envoi_injections_smtp (boite_id, etat, maj_le)');
        DB::statement("COMMENT ON TABLE envoi_injections_smtp IS 'Envoi P0 L4 : etats d injection SMTP. Ajout seul, aucun effacement.'");
        DB::statement('ALTER TABLE envoi_injections_smtp ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE envoi_injections_smtp FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS envoi_injections_smtp_workspace_isolation ON envoi_injections_smtp');
        DB::statement("CREATE POLICY envoi_injections_smtp_workspace_isolation ON envoi_injections_smtp FOR ALL
            USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
            WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))");
        DB::unprepared(
            <<<'SQL'
            CREATE OR REPLACE FUNCTION envoi_injections_smtp_aucun_effacement() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_injections_smtp : aucun effacement (DELETE/TRUNCATE refusés)';
            END;
            $f$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS envoi_injections_smtp_aucun_effacement ON envoi_injections_smtp;
            CREATE TRIGGER envoi_injections_smtp_aucun_effacement BEFORE DELETE ON envoi_injections_smtp
                FOR EACH ROW EXECUTE FUNCTION envoi_injections_smtp_aucun_effacement();
            DROP TRIGGER IF EXISTS envoi_injections_smtp_pas_de_truncate ON envoi_injections_smtp;
            CREATE TRIGGER envoi_injections_smtp_pas_de_truncate BEFORE TRUNCATE ON envoi_injections_smtp
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_injections_smtp_aucun_effacement();
            SQL,
        );

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT SELECT, INSERT, UPDATE ON public.envoi_injections_smtp TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_injections_smtp_id_seq TO ' . $role);
        }
    }

    /** Retour réel : ne touche que la table créée ici. */
    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS envoi_injections_smtp');
        DB::statement('DROP FUNCTION IF EXISTS envoi_injections_smtp_aucun_effacement()');
    }
};
