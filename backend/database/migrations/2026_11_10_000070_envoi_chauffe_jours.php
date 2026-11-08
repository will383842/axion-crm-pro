<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L7 — compteurs d envoi par boite, jour (Paris) et fournisseur. AJOUT SEUL : le rôle applicatif n'a ni DELETE ni TRUNCATE.
 * workspace_id + RLS FORCE (copie de 2026_11_10_000020). down() ne retire la
 * table que si elle est vide (aucun effacement de données).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_chauffe_jours (
                id            BIGSERIAL   PRIMARY KEY,
                workspace_id  UUID        NOT NULL REFERENCES workspaces(id),
                boite         TEXT        NOT NULL,
                jour          DATE        NOT NULL,
                fournisseur   TEXT        NOT NULL CHECK (fournisseur IN ('gmail', 'microsoft', 'autre')),
                envoyes       INTEGER     NOT NULL DEFAULT 0 CHECK (envoyes >= 0),
                rebonds_durs  INTEGER     NOT NULL DEFAULT 0 CHECK (rebonds_durs >= 0),
                plaintes      INTEGER     NOT NULL DEFAULT 0 CHECK (plaintes >= 0),
                reponses      INTEGER     NOT NULL DEFAULT 0 CHECK (reponses >= 0),
                mis_a_jour_le TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (workspace_id, boite, jour, fournisseur)
            )
            SQL,
        );
        DB::statement('CREATE INDEX IF NOT EXISTS idx_envoi_chauffe_jours_lecture ON envoi_chauffe_jours (workspace_id, fournisseur, jour DESC)');
        DB::statement('ALTER TABLE envoi_chauffe_jours ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE envoi_chauffe_jours FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS envoi_chauffe_jours_workspace_isolation ON envoi_chauffe_jours');
        DB::statement(self::politique('envoi_chauffe_jours'));
        DB::statement("COMMENT ON TABLE envoi_chauffe_jours IS 'Envoi P0 L7 : compteurs d envoi par boite, jour (Paris) et fournisseur. Ajout seul.'");

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT SELECT, INSERT, UPDATE ON public.envoi_chauffe_jours TO ' . $role);
            DB::statement('REVOKE DELETE, TRUNCATE ON public.envoi_chauffe_jours FROM ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_chauffe_jours_id_seq TO ' . $role);
        }
    }

    private static function politique(string $table): string
    {
        return "CREATE POLICY {$table}_workspace_isolation ON {$table} FOR ALL
            USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
            WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))";
    }

    /** Retour PRUDENT : refusé si la table contient des lignes (aucun effacement). */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM envoi_chauffe_jours) THEN
                    RAISE EXCEPTION 'envoi_chauffe_jours contient des lignes : retour refusé (aucun effacement)';
                END IF;
            END $$;
            DROP TABLE IF EXISTS envoi_chauffe_jours;
        SQL);
    }
};
