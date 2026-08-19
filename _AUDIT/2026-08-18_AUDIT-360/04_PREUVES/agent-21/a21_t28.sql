SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY;
SET statement_timeout='1200s';
SET max_parallel_workers_per_gather=0;
SET work_mem='64MB';
\pset pager off
\echo '=== T28b companies SANS aucun tag ==='
SELECT count(*) FROM companies c WHERE NOT EXISTS (SELECT 1 FROM company_tag ctg WHERE ctg.company_id=c.id);
\echo '=== T33 les 2 tags src:site-formulaire-autre : meme espace ? ==='
SELECT id, workspace_id, slug, category, kind FROM tags WHERE slug='src:site-formulaire-autre';
\echo '=== T34 espaces ==='
SELECT id, name FROM workspaces;
\echo '=== T35 repartition companies par espace ==='
SELECT workspace_id, count(*) FROM companies GROUP BY 1;
