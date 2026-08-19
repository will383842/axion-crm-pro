SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY;
SET statement_timeout='900s';
SET work_mem='256MB';
\pset pager off
\echo '=== T6 last_name : effet ACCENT SEUL (lower vs lower(unaccent)) ==='
SELECT count(*) FILTER (WHERE lower(last_name) <> lower(unaccent(last_name))) accent_seul,
       count(*) FILTER (WHERE lower(last_name) <> normalize_name(last_name)) accent_plus_particules
FROM contacts;
\echo '=== T7 recherche par nom : combien de fiches INTROUVABLES par lower() si on tape sans accent ==='
SELECT count(*) FROM contacts WHERE last_name ~ '[^\x20-\x7E]';
\echo '=== T8 Dupont/Dupond : normalize_name les confond-il ? ==='
SELECT normalize_name('Dupont') a, normalize_name('DUPONT') b, normalize_name('dupont') c, normalize_name('Dupond') d,
       (normalize_name('DUPONT')=normalize_name('dupont')) casse_attrapee,
       (normalize_name('Dupont')=normalize_name('Dupond')) dupond_attrape;
\echo '=== T9 TOUS les index UNIQUE portant sur email dans la base ==='
SELECT indexname, indexdef FROM pg_indexes WHERE schemaname='public' AND indexdef ILIKE '%unique%' AND indexdef ILIKE '%email%';
\echo '=== T9b index UNIQUE utilisant lower() ==='
SELECT indexname, indexdef FROM pg_indexes WHERE schemaname='public' AND indexdef ILIKE '%unique%' AND indexdef ILIKE '%lower(%';
\echo '=== T10 companies : denomination avec majuscules/accents ==='
SELECT count(*) FILTER (WHERE denomination ~ '[^\x20-\x7E]') accentuees,
       count(*) FILTER (WHERE lower(denomination) <> lower(unaccent(denomination))) diverge_accent,
       count(*) total FROM companies;
