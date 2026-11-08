<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi K15 — supervision : relevés de santé du serveur et constats (ouverture / rétablissement / alerte émise).
 * Noms : `envoi_releves_sante` et `envoi_constats_sante` (préfixe `envoi_`, puis le sujet). Cette migration n'a encore
 * jamais tourné en production : les noms définitifs sont posés ici, sans RENAME ni perte.
 * Purge : aucune (ajout seul). Dette K15-D3 : ~288 relevés par jour et par serveur ; partitionnement/archivage à prévoir
 * (voir docs/envoi/supervision-k15.md).
 * AJOUTS SEULS : un trigger refuse UPDATE, DELETE et TRUNCATE ; le rôle applicatif n'a que SELECT et INSERT. `down()` inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_releves_sante (
                id           BIGSERIAL   PRIMARY KEY,
                workspace_id UUID        NOT NULL, -- pas de clé étrangère : l'historique survit à tout
                source       TEXT        NOT NULL,
                mesure_le    TIMESTAMPTZ NOT NULL,
                ok           BOOLEAN     NOT NULL,
                mesures      TEXT        NOT NULL DEFAULT '{}' -- JSON en texte (garde RGPD : pas de nouvelle colonne JSONB)
            )
            SQL);
        DB::statement('CREATE INDEX IF NOT EXISTS envoi_releves_sante_serveur ON envoi_releves_sante (workspace_id, source, id DESC)');
        // Couvrants pour max(mesure_le) des relevés réussis (homme mort) et min(mesure_le) (premier relevé) : un parcours d'index, pas de table.
        DB::statement('CREATE INDEX IF NOT EXISTS envoi_releves_sante_instant ON envoi_releves_sante (workspace_id, source, mesure_le)');
        DB::statement('CREATE INDEX IF NOT EXISTS envoi_releves_sante_instant_ok ON envoi_releves_sante (workspace_id, source, mesure_le) WHERE ok');
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_constats_sante (
                id           BIGSERIAL   PRIMARY KEY,
                workspace_id UUID        NOT NULL,
                source       TEXT        NOT NULL,
                code         TEXT        NOT NULL,
                etat         TEXT        NOT NULL CHECK (etat IN ('ouvert', 'retabli', 'alerte_emise')), -- 'alerte_emise' : ligne d'ajout posée quand l'alerte de l'ouverture est partie
                gravite      TEXT        NOT NULL CHECK (gravite IN ('attention', 'critique')),
                detail       TEXT        NOT NULL DEFAULT '',
                palier       SMALLINT    NOT NULL DEFAULT 1, -- 1 = attention, 2 = attention renforcée (ex. certificat 14 j), 3 = critique
                cree_le      TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            SQL);
        DB::statement('CREATE INDEX IF NOT EXISTS envoi_constats_sante_code ON envoi_constats_sante (workspace_id, source, code, id DESC)');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION envoi_sante_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION '% : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)', TG_TABLE_NAME;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public
            SQL);
        foreach (['envoi_releves_sante', 'envoi_constats_sante'] as $t) {
            DB::statement("DROP TRIGGER IF EXISTS {$t}_ajout_seul ON {$t}");
            DB::statement("CREATE TRIGGER {$t}_ajout_seul BEFORE UPDATE OR DELETE ON {$t} FOR EACH ROW EXECUTE FUNCTION envoi_sante_ajout_seul()");
            DB::statement("DROP TRIGGER IF EXISTS {$t}_pas_de_truncate ON {$t}");
            DB::statement("CREATE TRIGGER {$t}_pas_de_truncate BEFORE TRUNCATE ON {$t} FOR EACH STATEMENT EXECUTE FUNCTION envoi_sante_ajout_seul()");
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS {$t}_workspace_isolation ON {$t}");
            DB::statement("CREATE POLICY {$t}_workspace_isolation ON {$t} FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))");
        }

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            foreach (['envoi_releves_sante', 'envoi_constats_sante'] as $t) {
                DB::statement("GRANT SELECT, INSERT ON public.{$t} TO {$role}");
                DB::statement("GRANT USAGE, SELECT ON SEQUENCE public.{$t}_id_seq TO {$role}");
            }
        }
    }

    /** Retour arrière INERTE : les relevés et constats sont conservés (ajout seul). */
    public function down(): void
    {
        // Volontairement vide.
    }
};
