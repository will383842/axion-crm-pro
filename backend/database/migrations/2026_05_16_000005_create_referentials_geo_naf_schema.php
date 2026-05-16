<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec/03 § §2 + §3 — référentiels géo (countries, regions, departments, cities) +
 * NAF 5 niveaux + legal_forms + effectif_ranges + axion_offer_targets + strategic_keywords.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- =====================================================================
            -- §2 référentiels géo
            -- =====================================================================
            CREATE TABLE countries (
                code_iso2   TEXT PRIMARY KEY,
                code_iso3   TEXT NOT NULL UNIQUE,
                name_fr     TEXT NOT NULL,
                name_en     TEXT NOT NULL,
                eu_member   BOOLEAN NOT NULL DEFAULT false,
                currency    TEXT NOT NULL DEFAULT 'EUR',
                created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            CREATE TABLE regions (
                code         TEXT PRIMARY KEY,
                country_code TEXT NOT NULL REFERENCES countries(code_iso2),
                name         TEXT NOT NULL,
                geometry     geometry(MultiPolygon, 4326),
                population   INT,
                created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX idx_regions_geometry ON regions USING gist (geometry);

            CREATE TABLE departments (
                code         TEXT PRIMARY KEY,
                region_code  TEXT NOT NULL REFERENCES regions(code),
                name         TEXT NOT NULL,
                geometry     geometry(MultiPolygon, 4326),
                population   INT,
                created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX idx_departments_region ON departments (region_code);
            CREATE INDEX idx_departments_geometry ON departments USING gist (geometry);

            CREATE TABLE cities (
                code_insee    TEXT PRIMARY KEY,
                department    TEXT NOT NULL REFERENCES departments(code),
                name          TEXT NOT NULL,
                slug          TEXT NOT NULL,
                postal_codes  TEXT[] NOT NULL DEFAULT '{}',
                population    INT NOT NULL DEFAULT 0,
                geometry      geometry(MultiPolygon, 4326),
                centroid      geometry(Point, 4326),
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX idx_cities_dept ON cities (department);
            CREATE INDEX idx_cities_population ON cities (population DESC);
            CREATE INDEX idx_cities_slug ON cities (slug);
            CREATE INDEX idx_cities_geometry ON cities USING gist (geometry);
            CREATE INDEX idx_cities_centroid ON cities USING gist (centroid);
            CREATE INDEX idx_cities_name_trgm ON cities USING gin (name gin_trgm_ops);

            -- =====================================================================
            -- §3 NAF (5 niveaux) + tables business
            -- =====================================================================
            CREATE TABLE naf_sections (
                code  CHAR(1) PRIMARY KEY,
                label TEXT NOT NULL
            );

            CREATE TABLE naf_divisions (
                code         CHAR(2) PRIMARY KEY,
                section_code CHAR(1) NOT NULL REFERENCES naf_sections(code),
                label        TEXT NOT NULL
            );
            CREATE INDEX idx_naf_div_section ON naf_divisions (section_code);

            CREATE TABLE naf_groups (
                code          CHAR(3) PRIMARY KEY,
                division_code CHAR(2) NOT NULL REFERENCES naf_divisions(code),
                label         TEXT NOT NULL
            );

            CREATE TABLE naf_classes (
                code        CHAR(4) PRIMARY KEY,
                group_code  CHAR(3) NOT NULL REFERENCES naf_groups(code),
                label       TEXT NOT NULL
            );

            CREATE TABLE naf_subclasses (
                code         CHAR(5) PRIMARY KEY,
                class_code   CHAR(4) NOT NULL REFERENCES naf_classes(code),
                label        TEXT NOT NULL,
                is_artisanat BOOLEAN NOT NULL DEFAULT false
            );

            -- legal_forms (formes juridiques INSEE)
            CREATE TABLE legal_forms (
                code        CHAR(4) PRIMARY KEY,
                label       TEXT NOT NULL,
                is_company  BOOLEAN NOT NULL DEFAULT true
            );

            -- effectif_ranges (16 codes INSEE incl. NN)
            CREATE TABLE effectif_ranges (
                code           TEXT PRIMARY KEY,            -- '00','01','02','03','11','12','21','22','31','32','41','42','51','52','53','NN'
                label          TEXT NOT NULL,
                size_category  TEXT NOT NULL,
                min_value      INT,
                max_value      INT
            );

            -- axion_offer_targets (Audit Flash, Mission PME, etc.)
            CREATE TABLE axion_offer_targets (
                code         TEXT PRIMARY KEY,
                name         TEXT NOT NULL,
                size_focus   TEXT[] NOT NULL DEFAULT '{}',
                price_min_eur NUMERIC(10,2),
                price_max_eur NUMERIC(10,2),
                description  TEXT
            );

            -- strategic_keywords (mots-clés business à matcher)
            CREATE TABLE strategic_keywords (
                id          BIGSERIAL PRIMARY KEY,
                workspace_id UUID REFERENCES workspaces(id) ON DELETE CASCADE,
                keyword      TEXT NOT NULL,
                weight       INT NOT NULL DEFAULT 1,
                created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX idx_strategic_keywords_kw ON strategic_keywords (keyword);

            -- opt-out cross-workspace (global, RGPD)
            CREATE TABLE opt_out (
                id              BIGSERIAL PRIMARY KEY,
                email           CITEXT,
                phone           TEXT,
                source          TEXT NOT NULL,
                reason          TEXT,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX idx_opt_out_email ON opt_out (email) WHERE email IS NOT NULL;
            CREATE INDEX idx_opt_out_phone ON opt_out (phone) WHERE phone IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS opt_out CASCADE;
            DROP TABLE IF EXISTS strategic_keywords CASCADE;
            DROP TABLE IF EXISTS axion_offer_targets CASCADE;
            DROP TABLE IF EXISTS effectif_ranges CASCADE;
            DROP TABLE IF EXISTS legal_forms CASCADE;
            DROP TABLE IF EXISTS naf_subclasses CASCADE;
            DROP TABLE IF EXISTS naf_classes CASCADE;
            DROP TABLE IF EXISTS naf_groups CASCADE;
            DROP TABLE IF EXISTS naf_divisions CASCADE;
            DROP TABLE IF EXISTS naf_sections CASCADE;
            DROP TABLE IF EXISTS cities CASCADE;
            DROP TABLE IF EXISTS departments CASCADE;
            DROP TABLE IF EXISTS regions CASCADE;
            DROP TABLE IF EXISTS countries CASCADE;
        SQL);
    }
};
