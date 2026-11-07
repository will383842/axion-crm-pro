<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L3 — verdicts d'adresse lus par la porte `EnvoiAutorise`.
 * ADDITIF SEULEMENT. Aucune adresse en clair : l'empreinte SHA-256 de
 * l'adresse normalisée (`ListeSuppression::empreinte`). `down()` inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_verdicts_adresse (
                id               BIGSERIAL   PRIMARY KEY,
                workspace_id     UUID        NOT NULL REFERENCES workspaces(id),
                empreinte_adresse CHAR(64)   NOT NULL,
                verdict          TEXT        NOT NULL
                                 CHECK (verdict IN ('valide', 'invalide', 'risque', 'inconnu')),
                verifie_le       TIMESTAMPTZ NOT NULL,
                source           TEXT        NOT NULL,
                cree_le          TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            SQL,
        );
        DB::statement('CREATE INDEX IF NOT EXISTS idx_envoi_verdicts_adresse_lecture ON envoi_verdicts_adresse (workspace_id, empreinte_adresse, verifie_le DESC)');
        DB::statement('ALTER TABLE envoi_verdicts_adresse ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE envoi_verdicts_adresse FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS envoi_verdicts_adresse_workspace_isolation ON envoi_verdicts_adresse');
        DB::statement(self::politique('envoi_verdicts_adresse'));
        DB::statement("COMMENT ON TABLE envoi_verdicts_adresse IS 'Envoi P0 L3 : verdicts de verification d adresse (empreinte seule). Ajout seul.'");

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT SELECT, INSERT ON public.envoi_verdicts_adresse TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_verdicts_adresse_id_seq TO ' . $role);
        }
    }

    private static function politique(string $table): string
    {
        return "CREATE POLICY {$table}_workspace_isolation ON {$table} FOR ALL
            USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
            WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))";
    }

    /** Retour arrière INERTE : les verdicts sont conservés. */
    public function down(): void
    {
        DB::statement("COMMENT ON TABLE envoi_verdicts_adresse IS 'Envoi P0 L3 : table conservee au retour arriere.'");
    }
};
