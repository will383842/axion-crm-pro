-- Extensions Postgres requises pour le POC dedup
-- (toutes incluses dans l'image postgres:16-alpine standard, sauf pg_partman géré côté seed)
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS btree_gin;
CREATE EXTENSION IF NOT EXISTS unaccent;
-- Note : pg_partman simulé via partitionnement natif PARTITION BY RANGE dans migrate.ts
