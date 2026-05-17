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
CREATE EXTENSION IF NOT EXISTS "pg_partman";     -- partitioning audit_logs (Sprint 17)
