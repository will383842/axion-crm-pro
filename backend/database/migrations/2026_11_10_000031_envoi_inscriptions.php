<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L1 — inscriptions d'une fiche à une séquence.
 * ADDITIF SEULEMENT. Adresse en EMPREINTE (SHA-256), jamais en clair : l'adresse
 * est relue dans la fiche au moment d'envoyer. La version du message est FIGÉE
 * à l'inscription (`version_figee`, immuable par déclencheur).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_inscriptions (
                id                  UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                workspace_id        UUID        NOT NULL REFERENCES workspaces(id),
                sequence_id         UUID        NOT NULL,
                contact_id          BIGINT      NOT NULL,
                entreprise_id       BIGINT      NOT NULL,
                empreinte_adresse   CHAR(64)    NOT NULL,
                statut              TEXT        NOT NULL DEFAULT 'active'
                                    CHECK (statut IN ('active', 'terminee', 'arretee')),
                motif_arret         TEXT,
                etape_courante      SMALLINT    NOT NULL DEFAULT 1 CHECK (etape_courante BETWEEN 1 AND 3),
                prochaine_echeance  TIMESTAMPTZ NOT NULL,
                version_figee       JSONB       NOT NULL,
                fil_message_id      TEXT,
                fil_references      TEXT,
                inscrite_le         TIMESTAMPTZ NOT NULL DEFAULT now(),
                arretee_le          TIMESTAMPTZ,
                FOREIGN KEY (workspace_id, sequence_id) REFERENCES envoi_sequences (workspace_id, id),
                CHECK (statut <> 'arretee' OR motif_arret IS NOT NULL),
                UNIQUE (workspace_id, sequence_id, contact_id)
            )
            SQL,
        );
        DB::statement('CREATE INDEX IF NOT EXISTS idx_envoi_inscriptions_echeance ON envoi_inscriptions (workspace_id, prochaine_echeance) WHERE statut = \'active\'');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_envoi_inscriptions_entreprise ON envoi_inscriptions (workspace_id, entreprise_id)');
        DB::statement(
            <<<'SQL'
            CREATE OR REPLACE FUNCTION envoi_inscriptions_fige() RETURNS trigger AS $$
            BEGIN
                IF NEW.version_figee IS DISTINCT FROM OLD.version_figee THEN
                    RAISE EXCEPTION 'envoi_inscriptions.version_figee est figee a l inscription';
                END IF;
                RETURN NEW;
            END $$ LANGUAGE plpgsql
            SQL,
        );
        DB::statement('DROP TRIGGER IF EXISTS envoi_inscriptions_fige ON envoi_inscriptions');
        DB::statement('CREATE TRIGGER envoi_inscriptions_fige BEFORE UPDATE ON envoi_inscriptions FOR EACH ROW EXECUTE FUNCTION envoi_inscriptions_fige()');
        $this->securiser('envoi_inscriptions');
    }

    private function securiser(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS {$table}_workspace_isolation ON {$table}");
        DB::statement("CREATE POLICY {$table}_workspace_isolation ON {$table} FOR ALL
            USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
            WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))");
        DB::statement("COMMENT ON TABLE {$table} IS 'Envoi P0 L1 : sequenceur. Ajout seul, aucune adresse en clair.'");
        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement("GRANT SELECT, INSERT, UPDATE ON public.{$table} TO {$role}");
        }
    }

    /** Retour arrière INERTE. */
    public function down(): void
    {
        DB::statement("COMMENT ON TABLE envoi_inscriptions IS 'Envoi P0 L1 : table conservee au retour arriere.'");
    }
};
