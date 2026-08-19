SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY;
SET statement_timeout='900s';
\pset pager off
\set dbl '\'[\' || chr(195) || chr(194) || \'][\' || chr(128) || \'-\' || chr(191) || \']\''
\echo '=== T11 TEMOIN : les motifs detectent-ils ce qu ils cherchent ? ==='
SELECT (chr(195)||chr(169)) ~ (:dbl) temoin_double_encodage_positif,
       'Elodie' ~ (:dbl) temoin_negatif,
       ('r'||chr(65533)||'le') LIKE '%'||chr(65533)||'%' temoin_remplacement_positif,
       'role' LIKE '%'||chr(65533)||'%' temoin_remplacement_negatif,
       '&amp;' ~ '&(amp|nbsp|quot|eacute|egrave|agrave|ccedil|#[0-9]+);' temoin_entites_positif,
       'Durand & Fils' ~ '&(amp|nbsp|quot|eacute|egrave|agrave|ccedil|#[0-9]+);' temoin_entites_negatif;
\echo '=== T12 companies.denomination ==='
SELECT count(*) FILTER (WHERE denomination ~ (:dbl)) double_encodage,
       count(*) FILTER (WHERE denomination LIKE '%'||chr(65533)||'%') remplacement,
       count(*) FILTER (WHERE denomination ~ '&(amp|nbsp|quot|eacute|egrave|agrave|ccedil|#[0-9]+);') entites_html,
       count(*) total FROM companies;
\echo '=== T13 companies.address + city_name + enseigne ==='
SELECT count(*) FILTER (WHERE address ~ (:dbl)) addr_dbl,
       count(*) FILTER (WHERE address LIKE '%'||chr(65533)||'%') addr_repl,
       count(*) FILTER (WHERE city_name ~ (:dbl)) city_dbl,
       count(*) FILTER (WHERE enseigne ~ (:dbl)) ens_dbl,
       count(*) FILTER (WHERE enseigne ~ '&(amp|nbsp|quot|eacute|egrave|agrave|ccedil|#[0-9]+);') ens_ent
FROM companies;
\echo '=== T14 contacts ==='
SELECT count(*) FILTER (WHERE last_name ~ (:dbl)) ln_dbl,
       count(*) FILTER (WHERE first_name ~ (:dbl)) fn_dbl,
       count(*) FILTER (WHERE last_name LIKE '%'||chr(65533)||'%' OR first_name LIKE '%'||chr(65533)||'%') repl,
       count(*) FILTER (WHERE last_name ~ '&(amp|nbsp|quot|eacute|egrave|agrave|ccedil|#[0-9]+);' OR first_name ~ '&(amp|nbsp|quot|eacute|egrave|agrave|ccedil|#[0-9]+);') ent,
       count(*) FILTER (WHERE title ~ (:dbl)) titre_dbl
FROM contacts;
\echo '=== T15 echantillon double encodage companies ==='
SELECT id, denomination FROM companies WHERE denomination ~ (:dbl) LIMIT 10;
