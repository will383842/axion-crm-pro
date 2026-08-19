SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY;
SET statement_timeout='900s';
\pset pager off
\echo '=== T16 TEMOIN Luhn SIREN (552081317 = Renault, valide ; 552081318 = invalide) ==='
WITH luhn(s) AS (VALUES ('552081317'),('552081318'),('732829320'))
SELECT s,
 ( (SELECT sum(CASE WHEN (9-i)%2=1 THEN (CASE WHEN substr(s,i,1)::int*2>9 THEN substr(s,i,1)::int*2-9 ELSE substr(s,i,1)::int*2 END) ELSE substr(s,i,1)::int END)
    FROM generate_series(1,9) i) % 10 = 0 ) AS luhn_ok
FROM luhn;
\echo '=== T17 COMPLETUDE companies (4 295 349) ==='
SELECT
 count(*) FILTER (WHERE email_generic IS NOT NULL AND email_generic<>'') email_rens,
 count(*) FILTER (WHERE email_generic ~ '^[^@[:space:]]+@[^@[:space:]]+\.[A-Za-z]{2,}$') email_plausible,
 count(*) FILTER (WHERE phone IS NOT NULL AND phone<>'') tel_rens,
 count(*) FILTER (WHERE length(regexp_replace(coalesce(phone,''),'[^0-9]','','g')) IN (10,11,12) AND phone<>'') tel_plausible,
 count(*) FILTER (WHERE address IS NOT NULL AND address<>'') adr_rens,
 count(*) FILTER (WHERE address ~ '[0-9]' AND length(address)>=8) adr_plausible,
 count(*) FILTER (WHERE siren IS NOT NULL) siren_rens,
 count(*) FILTER (WHERE website IS NOT NULL AND website<>'') web_rens,
 count(*) FILTER (WHERE website ~ '^https?://[^[:space:]]+\.[A-Za-z]{2,}') web_plausible
FROM companies;
\echo '=== T18 SIREN plausible (9 chiffres + Luhn) ==='
SELECT count(*) FILTER (WHERE siren ~ '^[0-9]{9}$') neuf_chiffres,
 count(*) FILTER (WHERE siren ~ '^[0-9]{9}$' AND
   ((SELECT sum(CASE WHEN (9-i)%2=1 THEN (CASE WHEN substr(siren,i,1)::int*2>9 THEN substr(siren,i,1)::int*2-9 ELSE substr(siren,i,1)::int*2 END) ELSE substr(siren,i,1)::int END)
     FROM generate_series(1,9) i) % 10 = 0)) luhn_ok
FROM companies WHERE siren IS NOT NULL;
\echo '=== T19 COMPLETUDE contacts (1 319 567) ==='
SELECT
 count(*) FILTER (WHERE email IS NOT NULL AND email::text<>'') email_rens,
 count(*) FILTER (WHERE email::text ~ '^[^@[:space:]]+@[^@[:space:]]+\.[A-Za-z]{2,}$') email_plausible,
 count(*) FILTER (WHERE phone IS NOT NULL AND phone<>'') tel_rens,
 count(*) FILTER (WHERE first_name IS NOT NULL AND first_name<>'') prenom_rens,
 count(*) FILTER (WHERE title IS NOT NULL AND title<>'') titre_rens,
 count(*) FILTER (WHERE linkedin_url IS NOT NULL AND linkedin_url<>'') li_rens,
 count(*) FILTER (WHERE person_key IS NOT NULL) person_key_rens,
 count(*) FILTER (WHERE email_status IS NOT NULL) email_status_rens
FROM contacts;
