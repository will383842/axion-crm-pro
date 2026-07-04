<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Verticale Santé — table des professionnels de santé (Annuaire Santé / RPPS
 * « PS LibreAccès »). Apporte la SPÉCIALITÉ médicale (que le NAF ne distingue
 * pas — tous « 86.22Z ») + téléphone + adresse, rattachés à une company par SIREN.
 *
 * ⚠️ Donnée nominative de SANTÉ (RGPD art. 9, catégorie particulière). L'ingestion
 * est gatée par SANTE_INGESTION_ENABLED (cf. config/services.php). Table séparée
 * avec soft-delete (droit à l'effacement).
 *
 * Migration ADDITIVE + idempotente (CREATE TABLE IF NOT EXISTS / CREATE INDEX IF
 * NOT EXISTS) — ne touche aucune table existante. RLS calquée sur la policy
 * standard workspace (cf. 2026_05_18_000001_apply_rls_dynamic).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS health_practitioners (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                company_id    BIGINT NULL REFERENCES companies(id) ON DELETE SET NULL,
                siren         CHAR(9) NULL,
                rpps          VARCHAR(20) NULL,
                nom           VARCHAR(120) NULL,
                prenom        VARCHAR(120) NULL,
                specialite    VARCHAR(160) NULL,
                phone         VARCHAR(32) NULL,
                email         CITEXT NULL,
                address       TEXT NULL,
                postcode      VARCHAR(10) NULL,
                city          VARCHAR(120) NULL,
                source        VARCHAR(40) NOT NULL DEFAULT 'rpps-libreacces',
                created_at    TIMESTAMPTZ NULL,
                updated_at    TIMESTAMPTZ NULL,
                deleted_at    TIMESTAMPTZ NULL
            )
        SQL);

        DB::statement('CREATE INDEX IF NOT EXISTS hp_workspace_idx ON health_practitioners (workspace_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS hp_workspace_siren_idx ON health_practitioners (workspace_id, siren)');
        DB::statement('CREATE INDEX IF NOT EXISTS hp_workspace_specialite_idx ON health_practitioners (workspace_id, specialite)');
        DB::statement('CREATE INDEX IF NOT EXISTS hp_company_idx ON health_practitioners (company_id)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS hp_workspace_rpps_uidx ON health_practitioners (workspace_id, rpps) WHERE rpps IS NOT NULL');

        // RLS — même politique permissive-si-non-défini que les autres tables scoped.
        DB::statement('DROP POLICY IF EXISTS health_practitioners_workspace_isolation ON health_practitioners');
        DB::statement('ALTER TABLE health_practitioners ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY health_practitioners_workspace_isolation ON health_practitioners
            FOR ALL
            USING (
                workspace_id IS NULL
                OR NULLIF(current_setting('app.current_workspace_id', true), '') IS NULL
                OR workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), '')
            )
            WITH CHECK (
                workspace_id IS NULL
                OR NULLIF(current_setting('app.current_workspace_id', true), '') IS NULL
                OR workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), '')
            )");
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS health_practitioners_workspace_isolation ON health_practitioners');
        DB::statement('DROP TABLE IF EXISTS health_practitioners');
    }
};
