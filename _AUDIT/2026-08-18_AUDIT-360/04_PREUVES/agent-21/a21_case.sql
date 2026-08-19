SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY;
SET statement_timeout='900s';
SET work_mem='256MB';
\pset pager off
\echo '=== T0 TEMOIN : lower() PG sous lc_ctype=C ==='
SELECT lower('DUPONT') a, lower('ELODIE') b, lower('ÉLODIE') c, upper('élodie') d,
       lower('ÉLODIE')='élodie' pg_lower_replie_accent,
       ('ÉLODIE'::citext = 'élodie'::citext) citext_egal_accent,
       ('DUPONT'::citext = 'dupont'::citext) citext_egal_ascii,
       lower(unaccent('ÉLODIE')) unaccent_lower,
       normalize_name('ÉLODIE') nn;
\echo '=== T1 emails contenant un caractere non-ASCII ==='
SELECT count(*) FILTER (WHERE email::text ~ '[^\x20-\x7E]') non_ascii,
       count(*) FILTER (WHERE email::text <> lower(email::text)) email_avec_majuscule_pg,
       count(*) FILTER (WHERE lower(email::text) <> lower(unaccent(email::text))) diverge_sql_vs_php
FROM contacts WHERE email IS NOT NULL;
\echo '=== T2 groupes doublons email : citext vs repli PHP (unaccent+lower) ==='
WITH a AS (SELECT count(*) g FROM (SELECT workspace_id, email FROM contacts WHERE email IS NOT NULL GROUP BY 1,2 HAVING count(*)>1) x),
     b AS (SELECT count(*) g FROM (SELECT workspace_id, lower(unaccent(email::text)) e FROM contacts WHERE email IS NOT NULL GROUP BY 1,2 HAVING count(*)>1) y)
SELECT a.g citext_groupes, b.g php_groupes, b.g-a.g ecart FROM a,b;
\echo '=== T3 doublons email supplementaires attrapes par le repli PHP ==='
WITH a AS (SELECT coalesce(sum(c-1),0) s FROM (SELECT workspace_id, email, count(*) c FROM contacts WHERE email IS NOT NULL GROUP BY 1,2 HAVING count(*)>1) x),
     b AS (SELECT coalesce(sum(c-1),0) s FROM (SELECT workspace_id, lower(unaccent(email::text)) e, count(*) c FROM contacts WHERE email IS NOT NULL GROUP BY 1,2 HAVING count(*)>1) y)
SELECT a.s citext_surnum, b.s php_surnum, b.s-a.s echappent FROM a,b;
\echo '=== T4 last_name : lower() PG vs normalize_name (unaccent) ==='
SELECT count(*) FILTER (WHERE lower(last_name) <> normalize_name(last_name)) diverge,
       count(*) FILTER (WHERE last_name ~ '[^\x20-\x7E]') non_ascii,
       count(*) total FROM contacts;
\echo '=== T5 noms : DUPONT/dupont/Dupont attrapes ? (echantillon reel) ==='
SELECT last_name, count(*) FROM contacts WHERE lower(last_name)='dupont' GROUP BY 1 ORDER BY 2 DESC LIMIT 10;
