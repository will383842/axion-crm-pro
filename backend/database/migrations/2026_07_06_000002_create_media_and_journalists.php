<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Base MÉDIAS + JOURNALISTES (chantier 2026-07-06).
 *
 * Deux tables dédiées, séparées des `companies` :
 *  - `media`       : les MÉDIAS en tant qu'entités (chaîne TV, émission, journal
 *                    quotidien/hebdo/mensuel, radio, agence de presse, portail web,
 *                    blog, production audiovisuelle). Un média peut être rattaché à
 *                    une `company` (l'éditeur, via SIREN) quand elle existe dans la
 *                    base des 4,3M — mais beaucoup de médias (émissions, titres,
 *                    blogs) ne sont PAS des sociétés → table dédiée.
 *  - `journalists` : les JOURNALISTES / contacts rédaction rattachés à un média.
 *                    ⚠️ DONNÉE PERSONNELLE (RGPD) → soft-delete (droit à l'effacement),
 *                    champ `opt_out`, `source_url` (traçabilité/transparence CNIL).
 *                    L'ingestion/scraping est gaté par MEDIA_JOURNALISTS_ENABLED
 *                    (cf. config/services.php), intérêt légitime B2B relations presse.
 *
 * Migration ADDITIVE + idempotente (IF NOT EXISTS) — ne touche aucune table
 * existante. RLS calquée sur la policy workspace standard
 * (cf. 2026_05_18_000001_apply_rls_dynamic + 2026_07_04_000001_create_health_practitioners).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- MÉDIAS ----------
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS media (
                id               BIGSERIAL PRIMARY KEY,
                workspace_id     UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                company_id       BIGINT NULL REFERENCES companies(id) ON DELETE SET NULL,
                parent_media_id  BIGINT NULL REFERENCES media(id) ON DELETE SET NULL,
                siren            CHAR(9) NULL,
                name             VARCHAR(240) NOT NULL,
                media_type       VARCHAR(40) NOT NULL,   -- presse_quotidien|presse_hebdo|presse_mensuel|presse_autre|radio|tv|tv_emission|agence_presse|portail_web|blog|production_audiovisuelle
                periodicity      VARCHAR(40) NULL,        -- quotidien|hebdomadaire|mensuel|bimensuel|trimestriel|...
                editorial_theme  VARCHAR(120) NULL,       -- généraliste|sport|économie|tech|local|culture|...
                diffusion_zone   VARCHAR(40) NULL,        -- national|régional|local|départemental
                publisher        VARCHAR(240) NULL,       -- éditeur (texte CPPAP)
                department_code  VARCHAR(5) NULL,
                region_code      VARCHAR(5) NULL,
                city             VARCHAR(160) NULL,
                postcode         VARCHAR(10) NULL,
                website          TEXT NULL,
                website_status   VARCHAR(16) NULL,        -- pending|found|not_found|exhausted (même moteur que companies)
                email            CITEXT NULL,             -- email rédaction générique (contact@, redaction@)
                phone            VARCHAR(32) NULL,
                socials          JSONB NULL,              -- {twitter,linkedin,facebook,instagram,youtube,tiktok}
                cppap_number     VARCHAR(40) NULL,
                arcom_id         VARCHAR(60) NULL,
                enrich_status    VARCHAR(16) NOT NULL DEFAULT 'pending',
                enriched_at      TIMESTAMPTZ NULL,
                source           VARCHAR(40) NOT NULL DEFAULT 'naf-extract', -- naf-extract|cppap|arcom|spel|agence|web
                created_at       TIMESTAMPTZ NULL,
                updated_at       TIMESTAMPTZ NULL,
                deleted_at       TIMESTAMPTZ NULL
            )
        SQL);

        DB::statement('CREATE INDEX IF NOT EXISTS media_workspace_idx ON media (workspace_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS media_workspace_type_idx ON media (workspace_id, media_type)');
        DB::statement('CREATE INDEX IF NOT EXISTS media_workspace_dept_idx ON media (workspace_id, department_code)');
        DB::statement('CREATE INDEX IF NOT EXISTS media_workspace_theme_idx ON media (workspace_id, editorial_theme)');
        DB::statement('CREATE INDEX IF NOT EXISTS media_company_idx ON media (company_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS media_parent_idx ON media (parent_media_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS media_enrich_status_idx ON media (workspace_id, enrich_status)');
        // Déduplication : un même titre CPPAP / un même média nommé par workspace.
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS media_workspace_cppap_uidx ON media (workspace_id, cppap_number) WHERE cppap_number IS NOT NULL');

        DB::statement('DROP POLICY IF EXISTS media_workspace_isolation ON media');
        DB::statement('ALTER TABLE media ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY media_workspace_isolation ON media
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

        // ---------- JOURNALISTES (données personnelles — RGPD) ----------
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS journalists (
                id             BIGSERIAL PRIMARY KEY,
                workspace_id   UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                media_id       BIGINT NULL REFERENCES media(id) ON DELETE SET NULL,
                company_id     BIGINT NULL REFERENCES companies(id) ON DELETE SET NULL,
                first_name     VARCHAR(120) NULL,
                last_name      VARCHAR(120) NULL,
                role           VARCHAR(160) NULL,   -- rédacteur en chef|journaliste|pigiste|correspondant|directeur de publication
                beat           VARCHAR(160) NULL,   -- rubrique : politique|sport|éco|tech|culture|faits divers|...
                email          CITEXT NULL,
                phone          VARCHAR(32) NULL,
                socials        JSONB NULL,
                source         VARCHAR(40) NOT NULL DEFAULT 'ours', -- ours|mentions-legales|web|signature
                source_url     TEXT NULL,           -- provenance (transparence RGPD)
                opt_out        BOOLEAN NOT NULL DEFAULT FALSE,  -- droit d'opposition
                created_at     TIMESTAMPTZ NULL,
                updated_at     TIMESTAMPTZ NULL,
                deleted_at     TIMESTAMPTZ NULL     -- droit à l'effacement
            )
        SQL);

        DB::statement('CREATE INDEX IF NOT EXISTS journalists_workspace_idx ON journalists (workspace_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS journalists_media_idx ON journalists (media_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS journalists_workspace_beat_idx ON journalists (workspace_id, beat)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS journalists_dedup_uidx ON journalists (workspace_id, media_id, last_name, first_name) WHERE last_name IS NOT NULL');

        DB::statement('DROP POLICY IF EXISTS journalists_workspace_isolation ON journalists');
        DB::statement('ALTER TABLE journalists ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY journalists_workspace_isolation ON journalists
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
        DB::statement('DROP POLICY IF EXISTS journalists_workspace_isolation ON journalists');
        DB::statement('DROP TABLE IF EXISTS journalists');
        DB::statement('DROP POLICY IF EXISTS media_workspace_isolation ON media');
        DB::statement('DROP TABLE IF EXISTS media');
    }
};
