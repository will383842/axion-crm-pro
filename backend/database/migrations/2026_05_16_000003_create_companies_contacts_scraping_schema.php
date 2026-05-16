<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec/03 § §4 + §5 — companies + contacts + scraper_runs (et tables associées).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- =====================================================================
            -- companies (cœur du domaine)
            -- =====================================================================
            CREATE TABLE companies (
                id                       BIGSERIAL PRIMARY KEY,
                workspace_id             UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                siren                    CHAR(9) NOT NULL,
                siret                    CHAR(14),
                denomination             TEXT,
                denomination_normalized  TEXT GENERATED ALWAYS AS (normalize_name(denomination)) STORED,
                naf                      TEXT,
                legal_form               TEXT,
                effectif_range           TEXT,
                effectif_min             INT,
                effectif_max             INT,
                size_category            TEXT,
                is_artisan               BOOLEAN NOT NULL DEFAULT false,
                address                  TEXT,
                postcode                 TEXT,
                city                     TEXT,
                insee                    TEXT,
                lat                      DOUBLE PRECISION,
                lon                      DOUBLE PRECISION,
                geo_point                geometry(Point, 4326),
                website                  TEXT,
                phone                    TEXT,
                linkedin_url             TEXT,
                discovery_source         TEXT,
                priority                 TEXT CHECK (priority IN ('haute','moyenne','basse','gelee')),
                quality_score            INT NOT NULL DEFAULT 0 CHECK (quality_score BETWEEN 0 AND 100),
                quality_badge            TEXT GENERATED ALWAYS AS (
                    CASE
                        WHEN quality_score >= 90 THEN 'complete'
                        WHEN quality_score >= 50 THEN 'partielle'
                        ELSE 'basique'
                    END
                ) STORED,
                signals                  JSONB NOT NULL DEFAULT '{}'::jsonb,
                metadata                 JSONB NOT NULL DEFAULT '{}'::jsonb,
                enriched_at              TIMESTAMPTZ,
                last_seen_at             TIMESTAMPTZ,
                created_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at               TIMESTAMPTZ,
                UNIQUE (workspace_id, siren)
            );
            CREATE INDEX idx_companies_workspace_size ON companies (workspace_id, size_category);
            CREATE INDEX idx_companies_workspace_naf ON companies (workspace_id, naf);
            CREATE INDEX idx_companies_workspace_dept ON companies (workspace_id, postcode);
            CREATE INDEX idx_companies_workspace_score ON companies (workspace_id, quality_score DESC);
            CREATE INDEX idx_companies_signals ON companies USING gin (signals);
            CREATE INDEX idx_companies_geo ON companies USING gist (geo_point);
            CREATE INDEX idx_companies_denomination_trgm ON companies USING gin (denomination_normalized gin_trgm_ops);

            -- =====================================================================
            -- contacts (décideurs / personnes)
            -- =====================================================================
            CREATE TABLE contacts (
                id                BIGSERIAL PRIMARY KEY,
                workspace_id      UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                company_id        BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
                first_name        TEXT,
                last_name         TEXT NOT NULL,
                normalized_hash   TEXT GENERATED ALWAYS AS (
                    encode(digest(normalize_name(coalesce(first_name,'') || '_' || last_name) || '_' || company_id::TEXT, 'sha256'), 'hex')
                ) STORED,
                title             TEXT,
                role              TEXT,
                email             CITEXT,
                email_status      TEXT CHECK (email_status IN ('valid','invalid','catchall','unknown','disposable','role')),
                email_score       INT CHECK (email_score BETWEEN 0 AND 100),
                email_pattern     TEXT,
                phone             TEXT,
                linkedin_url      TEXT,
                twitter_url       TEXT,
                discovery_source  TEXT,
                sources           JSONB NOT NULL DEFAULT '[]'::jsonb,
                metadata          JSONB NOT NULL DEFAULT '{}'::jsonb,
                last_verified_at  TIMESTAMPTZ,
                created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at        TIMESTAMPTZ,
                UNIQUE (workspace_id, normalized_hash)
            );
            CREATE INDEX idx_contacts_company ON contacts (company_id);
            CREATE INDEX idx_contacts_email ON contacts (email) WHERE email IS NOT NULL;
            CREATE INDEX idx_contacts_workspace ON contacts (workspace_id);

            -- =====================================================================
            -- scraper_runs (un par tentative source × cible)
            -- =====================================================================
            CREATE TABLE scraper_runs (
                id                BIGSERIAL PRIMARY KEY,
                workspace_id      UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                company_id        BIGINT REFERENCES companies(id) ON DELETE CASCADE,
                source            TEXT NOT NULL,
                status            TEXT NOT NULL CHECK (status IN ('pending','running','success','failed','partial','cancelled')),
                started_at        TIMESTAMPTZ,
                finished_at       TIMESTAMPTZ,
                latency_ms        INT,
                error             TEXT,
                payload_path      TEXT,
                request_payload   JSONB,
                response_payload  JSONB,
                dedup_key         TEXT,
                created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (workspace_id, dedup_key)
            );
            CREATE INDEX idx_runs_company_source_status ON scraper_runs (company_id, source, status);
            CREATE INDEX idx_runs_workspace_started ON scraper_runs (workspace_id, started_at DESC);
            CREATE INDEX idx_runs_dedup ON scraper_runs (dedup_key);

            -- =====================================================================
            -- tags + company_tag pivot
            -- =====================================================================
            CREATE TABLE tags (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                slug          TEXT NOT NULL,
                name          TEXT NOT NULL,
                color         TEXT,
                description   TEXT,
                rules         JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (workspace_id, slug)
            );
            CREATE TABLE company_tag (
                company_id BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
                tag_id     BIGINT NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
                PRIMARY KEY (company_id, tag_id)
            );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS company_tag CASCADE;
            DROP TABLE IF EXISTS tags CASCADE;
            DROP TABLE IF EXISTS scraper_runs CASCADE;
            DROP TABLE IF EXISTS contacts CASCADE;
            DROP TABLE IF EXISTS companies CASCADE;
        SQL);
    }
};
