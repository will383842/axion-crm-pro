SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY;
SET statement_timeout='1800s';
SET work_mem='512MB';
\pset pager off
\echo '=== T23 recompute COMPLET (avec bonus +20 contact) vs stocke ==='
WITH ct AS (SELECT company_id FROM contacts WHERE email_status IN ('valid','catchall','unknown','role') GROUP BY 1),
rec AS (
 SELECT c.id, c.quality_score stocke,
  least(100,
     (CASE WHEN c.email_generic IS NOT NULL AND c.email_generic<>'' THEN 15 ELSE 0 END)
   + (CASE WHEN c.website IS NOT NULL THEN 15 ELSE 0 END)
   + (CASE WHEN c.phone IS NOT NULL THEN 10 ELSE 0 END)
   + (CASE WHEN c.linkedin_url IS NOT NULL THEN 10 ELSE 0 END)
   + (CASE WHEN c.address IS NOT NULL AND c.address<>'' THEN 10 ELSE 0 END)
   + (CASE WHEN c.lat IS NOT NULL AND c.lon IS NOT NULL THEN 10 ELSE 0 END)
   + (CASE WHEN c.enseigne IS NOT NULL AND c.enseigne<>'' THEN 5 ELSE 0 END)
   + (CASE WHEN jsonb_array_length(coalesce(c.signals->'recent','[]'::jsonb))>0 THEN 5 ELSE 0 END)
   + (CASE WHEN ct.company_id IS NOT NULL THEN 20 ELSE 0 END)) theorique
 FROM companies c LEFT JOIN ct ON ct.company_id=c.id)
SELECT count(*) total,
       count(*) FILTER (WHERE stocke=theorique) identiques,
       count(*) FILTER (WHERE stocke<>theorique) divergents,
       count(*) FILTER (WHERE stocke<theorique) sous_evalues,
       count(*) FILTER (WHERE stocke>theorique) sur_evalues,
       max(theorique) max_theorique
FROM rec;
\echo '=== T24 max/min stocke + badge complete atteignable ? ==='
SELECT min(quality_score) mn, max(quality_score) mx, count(*) FILTER (WHERE quality_score>=90) au_dessus_de_90 FROM companies;
