<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L5 — liens de désinscription : ce à quoi un lien de désinscription est lié.
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
            CREATE TABLE IF NOT EXISTS envoi_liens_desinscription (
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
        // Garde : une table homonyme préexistante (autre schéma) sans `reference` doit échouer bruyamment.
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = current_schema() AND table_name = 'envoi_liens_desinscription' AND column_name = 'reference'
                ) THEN
                    RAISE EXCEPTION 'envoi_liens_desinscription : colonne reference absente (table preexistante incompatible)';
                END IF;
            END
            $$
            SQL);
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_envoi_liens_desinscription_reference ON envoi_liens_desinscription (reference)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_envoi_liens_desinscription_empreinte ON envoi_liens_desinscription (workspace_id, empreinte_adresse)');
        DB::statement('ALTER TABLE envoi_liens_desinscription ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE envoi_liens_desinscription FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS envoi_liens_desinscription_workspace_isolation ON envoi_liens_desinscription');
        DB::statement(self::politique('envoi_liens_desinscription'));
        DB::statement("COMMENT ON TABLE envoi_liens_desinscription IS 'Envoi P0 L5 : inscription liee a un lien de desinscription (reference opaque). Ajout seul.'");

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT SELECT, INSERT ON public.envoi_liens_desinscription TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_liens_desinscription_id_seq TO ' . $role);
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
        DB::statement("COMMENT ON TABLE envoi_liens_desinscription IS 'Envoi P0 L5 : table conservee au retour arriere.'");
    }
};
