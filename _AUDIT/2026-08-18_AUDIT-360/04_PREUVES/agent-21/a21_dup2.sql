SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY;
SET statement_timeout='900s';
SET work_mem='256MB';
\pset pager off
\echo '=== D5 contacts: meme email (citext, tel quel) ==='
WITH d AS (SELECT workspace_id, email, count(*) c FROM contacts WHERE email IS NOT NULL AND email::text<>'' GROUP BY 1,2 HAVING count(*)>1)
SELECT count(*) groupes, coalesce(sum(c),0) fiches, coalesce(sum(c-1),0) surnumeraires FROM d;
\echo '=== D6 contacts: email renseigne / total ==='
SELECT count(*) total, count(email) avec_email, count(DISTINCT email) emails_distincts FROM contacts;
\echo '=== D7 contacts: meme telephone ==='
WITH d AS (SELECT workspace_id, phone, count(*) c FROM contacts WHERE phone IS NOT NULL AND phone<>'' GROUP BY 1,2 HAVING count(*)>1)
SELECT count(*) groupes, coalesce(sum(c),0) fiches, coalesce(sum(c-1),0) surnumeraires FROM d;
\echo '=== D8 contacts: meme nom+prenom+company_id (brut, sans normalisation) ==='
WITH d AS (SELECT workspace_id, company_id, coalesce(first_name,''), last_name, count(*) c FROM contacts GROUP BY 1,2,3,4 HAVING count(*)>1)
SELECT count(*) groupes, coalesce(sum(c),0) fiches, coalesce(sum(c-1),0) surnumeraires FROM d;
\echo '=== D9 contacts: meme nom+prenom NORMALISE, entreprises DIFFERENTES mais meme denomination ==='
WITH x AS (SELECT ct.workspace_id, normalize_name(coalesce(ct.first_name,'')||'_'||ct.last_name) nk, c.denomination_normalized dn, count(*) cnt
           FROM contacts ct JOIN companies c ON c.id=ct.company_id
           WHERE c.denomination_normalized IS NOT NULL AND c.denomination_normalized<>''
           GROUP BY 1,2,3 HAVING count(*)>1)
SELECT count(*) groupes, coalesce(sum(cnt),0) fiches, coalesce(sum(cnt-1),0) surnumeraires FROM x;
