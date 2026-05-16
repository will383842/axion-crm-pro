<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Extensions PostgreSQL 16 — cf. spec/03 § Pré-requis
            CREATE EXTENSION IF NOT EXISTS pgcrypto;
            CREATE EXTENSION IF NOT EXISTS pg_trgm;
            CREATE EXTENSION IF NOT EXISTS unaccent;
            CREATE EXTENSION IF NOT EXISTS btree_gin;
            CREATE EXTENSION IF NOT EXISTS btree_gist;
            CREATE EXTENSION IF NOT EXISTS citext;
            CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
            CREATE EXTENSION IF NOT EXISTS postgis;
            CREATE EXTENSION IF NOT EXISTS vector;
            -- pg_partman + pg_cron : provisionnés au niveau infra (shared_preload_libraries)
            -- voir spec/02_architecture_infra.md

            -- Fonction helper de normalisation des noms (dedup contacts)
            CREATE OR REPLACE FUNCTION normalize_name(input TEXT) RETURNS TEXT AS $$
              SELECT lower(unaccent(regexp_replace(
                regexp_replace(coalesce(input, ''), '\s+', ' ', 'g'),
                '\m(de|du|la|le|les|d|l)\M\s+', '', 'gi'
              )))
            $$ LANGUAGE SQL IMMUTABLE;

            -- Catégorie de taille INSEE (4 + 2 sous-segments artisanat/commerce)
            -- cf. spec/01 § Cibles.
            CREATE OR REPLACE FUNCTION compute_size_category(effectif_min INT, effectif_max INT, is_artisan BOOLEAN DEFAULT false)
            RETURNS TEXT AS $$
              SELECT CASE
                WHEN effectif_max IS NULL OR effectif_max = 0 THEN
                  CASE WHEN is_artisan THEN 'artisan' ELSE 'tpe' END
                WHEN effectif_max <= 9 THEN
                  CASE WHEN is_artisan THEN 'artisan' ELSE 'tpe' END
                WHEN effectif_max <= 19 THEN 'tpe'
                WHEN effectif_max <= 249 THEN 'pme'
                WHEN effectif_max <= 4999 THEN 'eti'
                ELSE 'grande_entreprise'
              END
            $$ LANGUAGE SQL IMMUTABLE;

            -- Recompute quality_score d'une fiche entreprise
            -- 🟢 complete = 90+, 🟡 partielle = 50-89, 🔴 basique = 0-49
            CREATE OR REPLACE FUNCTION recompute_company_quality_score(c_id BIGINT) RETURNS INT AS $$
            DECLARE
              score INT := 0;
              row_count INT;
            BEGIN
              SELECT
                (CASE WHEN c.website IS NOT NULL THEN 15 ELSE 0 END)
                + (CASE WHEN c.phone IS NOT NULL THEN 15 ELSE 0 END)
                + (CASE WHEN c.linkedin_url IS NOT NULL THEN 15 ELSE 0 END)
                + (CASE WHEN c.signals IS NOT NULL AND jsonb_array_length(coalesce(c.signals->'recent', '[]'::jsonb)) > 0 THEN 10 ELSE 0 END)
              INTO score
              FROM companies c
              WHERE c.id = c_id;

              SELECT count(*) INTO row_count
              FROM contacts ct
              WHERE ct.company_id = c_id
                AND ct.email_status = 'valid'
                AND ct.email_score >= 70;
              IF row_count > 0 THEN score := score + 45; END IF;

              UPDATE companies SET quality_score = score WHERE id = c_id;
              RETURN score;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS recompute_company_quality_score(BIGINT);
            DROP FUNCTION IF EXISTS compute_size_category(INT, INT, BOOLEAN);
            DROP FUNCTION IF EXISTS normalize_name(TEXT);
            -- Extensions volontairement conservées (peuvent servir à d'autres apps).
        SQL);
    }
};
