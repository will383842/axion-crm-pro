SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY;
SET statement_timeout='900s';
\pset pager off
\echo '=== T20 quality_score : distribution exacte ==='
SELECT quality_score, count(*), round(100.0*count(*)/sum(count(*)) OVER (),4) pct FROM companies GROUP BY 1 ORDER BY 1;
\echo '=== T21 quality_badge ==='
SELECT quality_badge, count(*), round(100.0*count(*)/sum(count(*)) OVER (),4) pct FROM companies GROUP BY 1 ORDER BY 2 DESC;
\echo '=== T22 score theorique recalcule (sans UPDATE) vs stocke ==='
SELECT stocke, theorique, count(*) FROM (
 SELECT c.quality_score stocke,
   least(100,
     (CASE WHEN c.email_generic IS NOT NULL AND c.email_generic<>'' THEN 15 ELSE 0 END)
   + (CASE WHEN c.website IS NOT NULL THEN 15 ELSE 0 END)
   + (CASE WHEN c.phone IS NOT NULL THEN 10 ELSE 0 END)
   + (CASE WHEN c.linkedin_url IS NOT NULL THEN 10 ELSE 0 END)
   + (CASE WHEN c.address IS NOT NULL AND c.address<>'' THEN 10 ELSE 0 END)
   + (CASE WHEN c.lat IS NOT NULL AND c.lon IS NOT NULL THEN 10 ELSE 0 END)
   + (CASE WHEN c.enseigne IS NOT NULL AND c.enseigne<>'' THEN 5 ELSE 0 END)
   + (CASE WHEN jsonb_array_length(coalesce(c.signals->'recent','[]'::jsonb))>0 THEN 5 ELSE 0 END)) theorique
 FROM companies c) z GROUP BY 1,2 ORDER BY 3 DESC LIMIT 25;
