<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PONT-LINKEDIN PL-M0B — plafonds et arrêt de la voie S, AJOUT SEUL : la dernière ligne fait foi. Aucune ligne de plafond ⇒ le
 * code applique les défauts sûrs ; aucune ligne d'arrêt ⇒ non arrêté. RLS ENABLE + FORCE, SELECT + INSERT seulement.
 * ADDITIF SEULEMENT. `down()` inerte. (`expediteur_user_id` : UUID, comme `users.id`.)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS pl_plafonds (
                id                    BIGSERIAL   PRIMARY KEY,
                workspace_id          UUID        NOT NULL REFERENCES workspaces(id),
                expediteur_user_id    UUID        NOT NULL REFERENCES users(id),
                invitations_jour      SMALLINT    NOT NULL DEFAULT 10 CHECK (invitations_jour BETWEEN 0 AND 25),
                invitations_semaine   SMALLINT    NOT NULL DEFAULT 50 CHECK (invitations_semaine BETWEEN 0 AND 100),
                messages_jour         SMALLINT    NOT NULL DEFAULT 20 CHECK (messages_jour BETWEEN 0 AND 40),
                notes_mois            SMALLINT    NOT NULL DEFAULT 5 CHECK (notes_mois BETWEEN 0 AND 5),
                jours_ouvres_seulement BOOLEAN    NOT NULL DEFAULT true,
                auteur                TEXT        NOT NULL,
                le                    TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            SQL);
        DB::statement('CREATE INDEX IF NOT EXISTS pl_plafonds_dernier ON pl_plafonds (workspace_id, expediteur_user_id, id DESC)');
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS pl_arret (
                id                 BIGSERIAL   PRIMARY KEY,
                workspace_id       UUID        NOT NULL REFERENCES workspaces(id),
                expediteur_user_id UUID        NULL REFERENCES users(id),
                geste              TEXT        NOT NULL CHECK (geste IN ('arret','reprise')),
                motif              TEXT        NOT NULL CHECK (length(motif) BETWEEN 10 AND 300 AND position('@' IN motif) = 0),
                auteur             TEXT        NOT NULL,
                declencheur        TEXT        NOT NULL CHECK (declencheur IN ('humain','acceptation_basse','oppose_dans_file','avertissement')),
                le                 TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            SQL);
        DB::statement('CREATE INDEX IF NOT EXISTS pl_arret_dernier ON pl_arret (workspace_id, expediteur_user_id, id DESC)');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION pl_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION '% : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)', TG_TABLE_NAME;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public, pg_temp
            SQL);
        foreach (['pl_plafonds', 'pl_arret'] as $t) {
            DB::statement("DROP TRIGGER IF EXISTS {$t}_ajout_seul ON {$t}");
            DB::statement("CREATE TRIGGER {$t}_ajout_seul BEFORE UPDATE OR DELETE ON {$t} FOR EACH ROW EXECUTE FUNCTION pl_ajout_seul()");
            DB::statement("DROP TRIGGER IF EXISTS {$t}_pas_de_truncate ON {$t}");
            DB::statement("CREATE TRIGGER {$t}_pas_de_truncate BEFORE TRUNCATE ON {$t} FOR EACH STATEMENT EXECUTE FUNCTION pl_ajout_seul()");
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS {$t}_workspace_isolation ON {$t}");
            DB::statement("CREATE POLICY {$t}_workspace_isolation ON {$t} FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))");
        }
        DB::statement("COMMENT ON TABLE pl_plafonds IS 'PONT-LINKEDIN : plafonds de la voie S, ajout seul, la dernière ligne fait foi.'");
        DB::statement("COMMENT ON TABLE pl_arret IS 'PONT-LINKEDIN : arrêt / reprise de la voie S, ajout seul, la dernière ligne fait foi.'");

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE ALL ON public.pl_plafonds, public.pl_arret FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.pl_plafonds, public.pl_arret TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.pl_plafonds_id_seq, public.pl_arret_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : plafonds et arrêts conservés (ajout seul). */
    public function down(): void
    {
        // Volontairement vide (aucun effacement, aucune protection retirée).
    }
};
