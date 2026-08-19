SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY;
SET statement_timeout='900s';
SET work_mem='512MB';
\pset pager off
\echo '=== T25 structure tags ==='
\d tags
\echo '=== T26 fiches companies portant AU MOINS un tag src: ==='
SELECT count(DISTINCT ctg.company_id) FROM company_tag ctg JOIN tags t ON t.id=ctg.tag_id WHERE t.slug LIKE 'src:%' OR t.name LIKE 'src:%';
\echo '=== T27 tags src: existants ==='
SELECT t.id, t.slug, t.name, count(ctg.company_id) fiches FROM tags t LEFT JOIN company_tag ctg ON ctg.tag_id=t.id
WHERE t.slug LIKE 'src:%' OR t.name LIKE 'src:%' GROUP BY 1,2,3 ORDER BY 4 DESC;
\echo '=== T28 companies SANS aucun tag ==='
SELECT count(*) FROM companies c WHERE NOT EXISTS (SELECT 1 FROM company_tag ctg WHERE ctg.company_id=c.id);
\echo '=== T29 tags orphelins (aucune fiche) ==='
SELECT count(*) FROM tags t WHERE NOT EXISTS (SELECT 1 FROM company_tag ctg WHERE ctg.tag_id=t.id);
\echo '=== T30 tags doublons de casse / de nom ==='
SELECT lower(unaccent(name)) k, count(*) n, string_agg(name,' | ') variantes FROM tags GROUP BY 1 HAVING count(*)>1 ORDER BY 2 DESC LIMIT 20;
\echo '=== T31 tags doublons de slug ==='
SELECT lower(slug) k, count(*) n FROM tags GROUP BY 1 HAVING count(*)>1 LIMIT 20;
\echo '=== T32 top tags par volume ==='
SELECT t.slug, count(*) n FROM company_tag ctg JOIN tags t ON t.id=ctg.tag_id GROUP BY 1 ORDER BY 2 DESC LIMIT 15;
