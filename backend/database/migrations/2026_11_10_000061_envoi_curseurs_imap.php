<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L6 — curseur de relève IMAP : un par (espace, boîte). Mémorise
 * UIDVALIDITY et le dernier UID traité. Le curseur n'est qu'un ACCÉLÉRATEUR :
 * la vérité est dans `envoi_evenements` (unicité), donc le perdre ou le
 * remonter ne crée ni doublon ni perte. `down()` INERTE.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_curseurs_imap (
                id           BIGSERIAL   PRIMARY KEY,
                workspace_id UUID        NOT NULL REFERENCES workspaces(id),
                boite        TEXT        NOT NULL,
                uid_validity BIGINT      NOT NULL,
                dernier_uid  BIGINT      NOT NULL DEFAULT 0,
                maj_le       TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE UNIQUE INDEX IF NOT EXISTS envoi_curseurs_imap_boite
                ON envoi_curseurs_imap (workspace_id, boite);
            ALTER TABLE envoi_curseurs_imap ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_curseurs_imap FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_curseurs_imap_workspace_isolation ON envoi_curseurs_imap;
            CREATE POLICY envoi_curseurs_imap_workspace_isolation ON envoi_curseurs_imap FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            COMMENT ON TABLE envoi_curseurs_imap IS 'Envoi P0 L6 : curseur de relève IMAP (accélérateur, pas une vérité).';
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE DELETE, TRUNCATE ON public.envoi_curseurs_imap FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT, UPDATE ON public.envoi_curseurs_imap TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_curseurs_imap_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : le curseur est conservé. */
    public function down(): void
    {
        // Volontairement vide.
    }
};
