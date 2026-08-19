SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY;
SET statement_timeout='900s';
SET max_parallel_workers_per_gather=0;
\pset pager off
\echo '=== T47 le contact au nom double-encode ==='
SELECT id, first_name, last_name, encode(convert_to(last_name,'UTF8'),'hex') hex
FROM contacts WHERE last_name ~ ('['||chr(195)||chr(194)||']['||chr(128)||'-'||chr(191)||']') LIMIT 5;
\echo '=== T48 contacts SANS aucun canal (ni email, ni tel, ni linkedin) ==='
SELECT count(*) FILTER (WHERE (email IS NULL OR email::text='') AND (phone IS NULL OR phone='') AND (linkedin_url IS NULL OR linkedin_url='')) sans_canal,
       count(*) total FROM contacts;
\echo '=== T49 email_status distribution ==='
SELECT coalesce(email_status,'(null)') s, count(*) FROM contacts GROUP BY 1 ORDER BY 2 DESC;
\echo '=== T50 activities ==='
SELECT kind, count(*) FROM activities GROUP BY 1 ORDER BY 2 DESC LIMIT 20;
\echo '=== T51 lifecycle_stage companies ==='
SELECT lifecycle_stage, count(*) FROM companies GROUP BY 1 ORDER BY 2 DESC;
\echo '=== T52 legal_basis renseignee ? ==='
SELECT coalesce(legal_basis,'(null)') b, count(*) FROM contacts GROUP BY 1 ORDER BY 2 DESC;
