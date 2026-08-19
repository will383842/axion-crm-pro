SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY;
SET statement_timeout='900s';
SET work_mem='256MB';
\pset pager off
\echo '=== D1 companies: SIREN partage (tous espaces) ==='
WITH d AS (SELECT siren, count(*) c FROM companies WHERE siren IS NOT NULL GROUP BY siren HAVING count(*)>1)
SELECT count(*) AS groupes, coalesce(sum(c),0) AS fiches, coalesce(sum(c-1),0) AS surnumeraires FROM d;
\echo '=== D2 companies: siren NULL ==='
SELECT count(*) FILTER (WHERE siren IS NULL) AS sans_siren, count(*) FILTER (WHERE siren IS NOT NULL) AS avec_siren, count(*) FILTER (WHERE foreign_id IS NOT NULL) AS avec_foreign_id FROM companies;
\echo '=== D3 companies: meme denomination_normalized + city_name ==='
WITH d AS (SELECT workspace_id, denomination_normalized, lower(coalesce(city_name,city,'')) v, count(*) c
           FROM companies WHERE denomination_normalized IS NOT NULL AND denomination_normalized<>''
           GROUP BY 1,2,3 HAVING count(*)>1)
SELECT count(*) AS groupes, coalesce(sum(c),0) AS fiches, coalesce(sum(c-1),0) AS surnumeraires FROM d;
\echo '=== D4 companies: meme telephone ==='
WITH d AS (SELECT workspace_id, phone, count(*) c FROM companies WHERE phone IS NOT NULL AND phone<>'' GROUP BY 1,2 HAVING count(*)>1)
SELECT count(*) AS groupes, coalesce(sum(c),0) AS fiches, coalesce(sum(c-1),0) AS surnumeraires FROM d;
