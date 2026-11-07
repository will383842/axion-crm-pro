<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L5 — inscriptions : ce à quoi un lien de désinscription est lié.
 * ADDITIF SEULEMENT. La `reference` (UUID aléatoire) est la seule chose qui
 * voyage dans le jeton : aucune donnée de personne dans l'URL. L'adresse reste
 * en base (RLS FORCE), car la liste de suppression existante se tient par
 * adresse. `down()` inerte : rien ne s'efface (règle S8).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_inscriptions (
                id                BIGSERIAL   PRIMARY KEY,
                workspace_id      UUID        NOT NULL REFERENCES workspaces(id),
                reference         UUID        NOT NULL DEFAULT gen_random_uuid(),
                adresse           CITEXT      NOT NULL,
                empreinte_adresse CHAR(64)    NOT NULL,
                portee            TEXT        NOT NULL DEFAULT 'business'
                                  CHECK (portee IN ('business', 'vivier')),
                cree_le           TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            SQL,
        );
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_envoi_inscriptions_reference ON envoi_inscriptions (reference)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_envoi_inscriptions_empreinte ON envoi_inscriptions (workspace_id, empreinte_adresse)');
        DB::statement('ALTER TABLE envoi_inscriptions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE envoi_inscriptions FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS envoi_inscriptions_workspace_isolation ON envoi_inscriptions');
        DB::statement(self::politique('envoi_inscriptions'));
        DB::statement("COMMENT ON TABLE envoi_inscriptions IS 'Envoi P0 L5 : inscription liee a un lien de desinscription (reference opaque). Ajout seul.'");

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT SELECT, INSERT ON public.envoi_inscriptions TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_inscriptions_id_seq TO ' . $role);
        }
    }

    private static function politique(string $table): string
    {
        return "CREATE POLICY {$table}_workspace_isolation ON {$table} FOR ALL
            USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
            WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))";
    }

    /** Retour arrière INERTE : les inscriptions sont conservées. */
    public function down(): void
    {
        DB::statement("COMMENT ON TABLE envoi_inscriptions IS 'Envoi P0 L5 : table conservee au retour arriere.'");
    }
};
