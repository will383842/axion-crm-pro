-- Axion CRM Pro — extensions PostgreSQL 16 requises (cf. spec/02 + 03)
-- Exécuté automatiquement par l'image postgres:16-alpine au premier démarrage.

CREATE EXTENSION IF NOT EXISTS "pg_trgm";        -- fuzzy matching dedup
CREATE EXTENSION IF NOT EXISTS "unaccent";       -- normalisation noms
CREATE EXTENSION IF NOT EXISTS "btree_gin";      -- index composites JSONB
CREATE EXTENSION IF NOT EXISTS "btree_gist";     -- exclusion constraints
CREATE EXTENSION IF NOT EXISTS "pgcrypto";       -- gen_random_uuid, hash
CREATE EXTENSION IF NOT EXISTS "citext";         -- emails case-insensitive
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";      -- uuid v4 (compat)
CREATE EXTENSION IF NOT EXISTS "postgis";        -- géocodage + carte France
CREATE EXTENSION IF NOT EXISTS "vector";         -- pgvector (futur embeddings)
-- pg_partman activé via image custom Dockerfile.postgres (Sprint 19.3).
-- Necessite l'image ghcr.io/will383842/axion-crm-pro-postgres:16-3.5-vector-partman.
--
-- 🔴 `SCHEMA partman` EST OBLIGATOIRE — 2026-08-18.
-- Sans cette clause, l'extension atterrit dans `public`, et ses tables internes
-- `part_config` / `part_config_sub` se retrouvent dans la liste que
-- `Illuminate\Database\Schema\PostgresBuilder::dropAllTables()` passe à un
-- unique `DROP TABLE … CASCADE`. PostgreSQL refuse alors la commande ENTIÈRE :
--     SQLSTATE[2BP01] cannot drop table part_config because extension
--     pg_partman requires it
-- Conséquence mesurée : `migrate:fresh` réussit UNE fois puis échoue à jamais,
-- et `RefreshDatabase` (toute la suite Pest) meurt avant le premier test.
-- Détail et reproduction : `_REPORTS/2026-08-18_RECONSTRUCTION-BASE.md`.
--
-- ⚠️ Ce fichier ne s'exécute qu'au PREMIER démarrage d'un volume Postgres neuf.
-- Les bases créées ensuite (`CREATE DATABASE …`, y compris `axion_crm_test`)
-- n'en voient rien : c'est la migration
-- `2026_05_16_000001_create_extensions_and_helpers` qui crée les extensions, et
-- `2026_08_18_100001_partman_dans_son_propre_schema` qui relocalise l'existant.
-- Les trois doivent rester d'accord.
CREATE SCHEMA IF NOT EXISTS partman;
CREATE EXTENSION IF NOT EXISTS "pg_partman" SCHEMA partman;  -- partitioning audit_logs (Sprint 17)
