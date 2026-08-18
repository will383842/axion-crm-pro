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
            -- pg_partman activé via image custom Dockerfile.postgres (Sprint 19.3).
            -- Si l'extension n'est pas dispo (image base sans pg_partman), le DO $$ skip silencieusement.
            --
            -- 🔴 `SCHEMA partman` N'EST PAS COSMÉTIQUE — c'est ce qui rend la base
            -- RECONSTRUCTIBLE. `PostgresBuilder::dropAllTables()` (Laravel) énumère les
            -- tables des schémas du `search_path` (`public` ici) et émet UN SEUL
            -- `DROP TABLE … CASCADE`. Tant que pg_partman vivait dans `public`, ses
            -- tables internes `part_config` / `part_config_sub` entraient dans ce lot et
            -- PostgreSQL refusait la commande entière :
            --     SQLSTATE[2BP01] cannot drop table part_config because extension
            --     pg_partman requires it
            -- → `migrate:fresh` et `RefreshDatabase` mouraient AVANT la première
            -- migration, sur toute base déjà migrée. Mesuré le 2026-08-18, cf.
            -- `_REPORTS/2026-08-18_RECONSTRUCTION-BASE.md`. Hors du `search_path`,
            -- `dropAllTables()` ne les voit plus.
            -- C'est aussi le schéma qu'attend déjà le code de
            -- `2026_05_17_000011_setup_pg_partman_audit_logs` (`partman.create_parent`).
            --
            -- ⚠️ Un `CREATE EXTENSION IF NOT EXISTS … SCHEMA partman` NE SUFFIT PAS :
            -- `IF NOT EXISTS` ne regarde que le NOM. Sur une base dont le volume
            -- Postgres a été initialisé par l'ancien `infra/postgres/init/01-extensions.sql`
            -- (c'est le cas de `axion_crm_test` en CI, créée par `POSTGRES_DB`),
            -- l'extension existe DÉJÀ dans `public` et la clause `SCHEMA` est
            -- silencieusement ignorée. Il faut donc relocaliser explicitement — et ici,
            -- AVANT `000011`, sans quoi `000011` enregistrerait `audit_logs` dans
            -- `public.part_config` et la relocalisation deviendrait impossible sans
            -- perte (pg_partman est `relocatable = false` : seul DROP + CREATE marche).
            -- Le même bloc existe dans `2026_08_18_100001_partman_dans_son_propre_schema`
            -- pour les bases où 000001 est DÉJÀ enregistrée (dev, préprod, production) —
            -- les deux doivent rester d'accord.
            DO $$
            DECLARE
                v_schema TEXT;
                v_lignes BIGINT;
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_available_extensions WHERE name = 'pg_partman') THEN
                    RAISE NOTICE 'pg_partman not available, skipping (will be retried in image custom build)';
                    RETURN;
                END IF;

                SELECT n.nspname
                INTO   v_schema
                FROM   pg_extension e
                JOIN   pg_namespace n ON n.oid = e.extnamespace
                WHERE  e.extname = 'pg_partman';

                IF v_schema IS NULL THEN
                    EXECUTE 'CREATE SCHEMA IF NOT EXISTS partman';
                    EXECUTE 'CREATE EXTENSION pg_partman SCHEMA partman';
                    RETURN;
                END IF;

                IF v_schema = 'partman' THEN
                    RETURN;
                END IF;

                EXECUTE format('SELECT count(*) FROM %I.part_config', v_schema) INTO v_lignes;

                IF v_lignes > 0 THEN
                    RAISE WARNING 'pg_partman est dans le schéma % et gère % ensemble(s) de partitions : '
                        'relocalisation ABANDONNÉE (elle détruirait part_config). '
                        'La base restera non reconstructible par migrate:fresh.', v_schema, v_lignes;
                    RETURN;
                END IF;

                EXECUTE 'DROP EXTENSION pg_partman';
                EXECUTE 'CREATE SCHEMA IF NOT EXISTS partman';
                EXECUTE 'CREATE EXTENSION pg_partman SCHEMA partman';
                RAISE NOTICE 'pg_partman relocalisé de % vers partman.', v_schema;
            EXCEPTION WHEN OTHERS THEN
                RAISE NOTICE 'pg_partman: % — on continue sans partitionnement.', SQLERRM;
            END $$;

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
