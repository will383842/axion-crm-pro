<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L1 — un message par (inscription, étape). L'UNICITÉ (inscription_id, etape)
 * est la garantie de fond contre le doublon ; le verrou de ligne de l'inscription
 * (SELECT … FOR UPDATE) sérialise les exécutions concurrentes.
 * ADDITIF SEULEMENT. Aucune adresse, aucun contenu : identifiants de fil seulement.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_messages (
                id             UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                workspace_id   UUID        NOT NULL REFERENCES workspaces(id),
                inscription_id UUID        NOT NULL REFERENCES envoi_inscriptions(id),
                etape          SMALLINT    NOT NULL CHECK (etape BETWEEN 1 AND 3),
                statut         TEXT        NOT NULL CHECK (statut IN ('reserve', 'envoye', 'refuse', 'reporte')),
                refus          TEXT,
                message_id     TEXT        NOT NULL,
                in_reply_to    TEXT,
                id_injection   TEXT,
                cree_le        TIMESTAMPTZ NOT NULL DEFAULT now(),
                maj_le         TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (inscription_id, etape)
            )
            SQL,
        );
        $table = 'envoi_messages';
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS {$table}_workspace_isolation ON {$table}");
        DB::statement("CREATE POLICY {$table}_workspace_isolation ON {$table} FOR ALL
            USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
            WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))");
        DB::statement("COMMENT ON TABLE {$table} IS 'Envoi P0 L1 : un message par (inscription, etape). Ajout seul.'");
        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement("GRANT SELECT, INSERT, UPDATE ON public.{$table} TO {$role}");
        }
    }

    /** Retour arrière INERTE. */
    public function down(): void
    {
        DB::statement("COMMENT ON TABLE envoi_messages IS 'Envoi P0 L1 : table conservee au retour arriere.'");
    }
};
