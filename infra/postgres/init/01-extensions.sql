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
-- pg_partman + pg_cron : nécessitent shared_preload_libraries + privilèges superuser
-- (provisionnés via Coolify image custom — cf. spec/02).
