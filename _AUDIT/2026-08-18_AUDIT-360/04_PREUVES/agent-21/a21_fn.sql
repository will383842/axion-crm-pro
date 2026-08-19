SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY;
\pset pager off
SELECT p.proname, pg_get_functiondef(p.oid) AS def
FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
WHERE n.nspname='public' AND p.proname IN ('normalize_name','trg_company_recompute_score','trg_contact_recompute_company_score','compute_company_quality_score','company_quality_score');
