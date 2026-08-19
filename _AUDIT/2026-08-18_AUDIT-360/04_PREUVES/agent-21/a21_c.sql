SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY;
SET statement_timeout='600s';
\pset pager off
\echo '=== VOLUMES ==='
SELECT 'companies' t, count(*) n, count(*) FILTER (WHERE deleted_at IS NOT NULL) del FROM companies
UNION ALL SELECT 'contacts', count(*), count(*) FILTER (WHERE deleted_at IS NOT NULL) FROM contacts
UNION ALL SELECT 'candidates', count(*), 0 FROM candidates
UNION ALL SELECT 'activities', count(*), 0 FROM activities
UNION ALL SELECT 'tags', count(*), 0 FROM tags
UNION ALL SELECT 'company_tag', count(*), 0 FROM company_tag
UNION ALL SELECT 'workspaces', count(*), 0 FROM workspaces;
\echo '=== MIGRATIONS ==='
SELECT count(*) FROM migrations;
