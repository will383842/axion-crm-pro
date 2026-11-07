<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L4 — boîtes d'envoi (SMTP). ADDITIF SEULEMENT.
 * Les identifiants sont stockés CHIFFRÉS (Crypt Laravel, clé d'application) :
 * jamais de mot de passe en clair en base. Aucun effacement (trigger).
 * `down()` réel : ne retire que les objets créés ici (tables NEUVES).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_boites (
                id                    UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                workspace_id          UUID        NOT NULL REFERENCES workspaces(id),
                adresse               TEXT        NOT NULL,
                hote                  TEXT        NOT NULL,
                port                  INTEGER     NOT NULL CHECK (port BETWEEN 1 AND 65535),
                chiffrement           TEXT        NOT NULL DEFAULT 'tls'
                                      CHECK (chiffrement IN ('tls', 'starttls', 'aucun')),
                identifiants_chiffres TEXT        NOT NULL,
                etat                  TEXT        NOT NULL DEFAULT 'active'
                                      CHECK (etat IN ('active', 'en_pause')),
                pause_motif           TEXT        NULL,
                pause_le              TIMESTAMPTZ NULL,
                limite_jour           INTEGER     NOT NULL DEFAULT 20 CHECK (limite_jour BETWEEN 0 AND 300),
                limite_heure          INTEGER     NOT NULL DEFAULT 5 CHECK (limite_heure BETWEEN 0 AND 300),
                cree_le               TIMESTAMPTZ NOT NULL DEFAULT now(),
                maj_le                TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            SQL,
        );
        DB::statement('CREATE INDEX IF NOT EXISTS idx_envoi_boites_workspace ON envoi_boites (workspace_id)');
        DB::statement("COMMENT ON COLUMN envoi_boites.identifiants_chiffres IS 'Crypt::encryptString(json). Jamais en clair.'");
        self::securiser('envoi_boites');

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT SELECT, INSERT, UPDATE ON public.envoi_boites TO ' . $role);
        }
    }

    private static function securiser(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS {$table}_workspace_isolation ON {$table}");
        DB::statement("CREATE POLICY {$table}_workspace_isolation ON {$table} FOR ALL
            USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
            WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))");
        DB::unprepared(
            <<<SQL
            CREATE OR REPLACE FUNCTION {$table}_aucun_effacement() RETURNS trigger AS \$f\$
            BEGIN
                RAISE EXCEPTION '{$table} : aucun effacement (DELETE/TRUNCATE refusés)';
            END;
            \$f\$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS {$table}_aucun_effacement ON {$table};
            CREATE TRIGGER {$table}_aucun_effacement BEFORE DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION {$table}_aucun_effacement();
            DROP TRIGGER IF EXISTS {$table}_pas_de_truncate ON {$table};
            CREATE TRIGGER {$table}_pas_de_truncate BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION {$table}_aucun_effacement();
            SQL,
        );
    }

    /** Retour réel : ne touche que la table créée ici. */
    public function down(): void
    {
        // Les clés étrangères des injections (000041, 000042) dépendent de cette table : on retire
        // d'abord CES contraintes seulement (la table des injections et ses lignes restent intactes).
        if (DB::selectOne("SELECT to_regclass('public.envoi_injections_smtp') AS t")->t !== null) {
            DB::statement('ALTER TABLE envoi_injections_smtp DROP CONSTRAINT IF EXISTS envoi_injections_smtp_boite_workspace_fk');
            DB::statement('ALTER TABLE envoi_injections_smtp DROP CONSTRAINT IF EXISTS envoi_injections_smtp_boite_id_fkey');
        }
        DB::statement('DROP TABLE IF EXISTS envoi_boites');
        DB::statement('DROP FUNCTION IF EXISTS envoi_boites_aucun_effacement()');
    }
};
