<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L5 — oppositions recueillies par le lien de désinscription.
 * ADDITIF SEULEMENT. Un lien = au plus une opposition (UNIQUE) : le
 * rejeu d'un jeton ne crée jamais de doublon. L'opposition met à l'écart, elle
 * n'efface aucune donnée (règle S8). `effets_termines_le` ne sert qu'à relancer
 * les effets (idempotents) si le premier passage a été interrompu.
 * `down()` inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_oppositions (
                id                 BIGSERIAL   PRIMARY KEY,
                workspace_id       UUID        NOT NULL REFERENCES workspaces(id),
                lien_id            BIGINT      NOT NULL,
                empreinte_adresse  CHAR(64)    NOT NULL,
                canal              TEXT        NOT NULL CHECK (canal IN ('page', 'one_click')),
                enregistree_le     TIMESTAMPTZ NOT NULL DEFAULT now(),
                effets_termines_le TIMESTAMPTZ,
                -- Composite : une opposition ne peut pointer que sur un lien DE SON espace.
                CONSTRAINT fk_envoi_oppositions_lien FOREIGN KEY (workspace_id, lien_id)
                    REFERENCES envoi_liens_desinscription (workspace_id, id)
            )
            SQL,
        );
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_envoi_oppositions_lien ON envoi_oppositions (lien_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_envoi_oppositions_empreinte ON envoi_oppositions (workspace_id, empreinte_adresse)');
        DB::statement('ALTER TABLE envoi_oppositions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE envoi_oppositions FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS envoi_oppositions_workspace_isolation ON envoi_oppositions');
        DB::statement(self::politique('envoi_oppositions'));
        DB::statement("COMMENT ON TABLE envoi_oppositions IS 'Envoi P0 L5 : oppositions par lien de desinscription (une par inscription). Ajout seul.'");

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT SELECT, INSERT ON public.envoi_oppositions TO ' . $role);
            DB::statement('GRANT UPDATE (effets_termines_le) ON public.envoi_oppositions TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_oppositions_id_seq TO ' . $role);
        }
    }

    private static function politique(string $table): string
    {
        return "CREATE POLICY {$table}_workspace_isolation ON {$table} FOR ALL
            USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
            WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))";
    }

    /** Retour arrière INERTE : les oppositions sont conservées. */
    public function down(): void
    {
        DB::statement("COMMENT ON TABLE envoi_oppositions IS 'Envoi P0 L5 : table conservee au retour arriere.'");
    }
};
