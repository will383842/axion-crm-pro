SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY;
SET statement_timeout='600s';
\pset pager off
\echo '=== T43 TEMOIN de l ecart : ce que PHP envoie vs ce que SQL compare ==='
SELECT 'Jose.ELODIE@x.fr' AS stocke_ascii,
       lower('Jose.ELODIE@x.fr') AS sql_lower,
       ('jose.elodie@x.fr' = lower('Jose.ELODIE@x.fr')) AS ascii_matche;
SELECT 'JOSE.ELODIE@x.fr' AS variante_accentuee_stockee,
       lower('JOS'||chr(201)||'.ELODIE@x.fr') AS sql_lower_accent,
       lower(unaccent(lower('JOS'||chr(201)||'.ELODIE@x.fr'))) AS php_equivalent,
       (lower('JOS'||chr(201)||'.ELODIE@x.fr') = 'jos'||chr(233)||'.elodie@x.fr') AS php_matche_sql;
\echo '=== T44 le lookup de dedup/RGPD utilise-t-il un index ? ==='
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF)
SELECT id FROM contacts WHERE workspace_id='1db106f5-c8a4-47b0-bf86-930f1ccc9f4a'
  AND lower(email::text) = 'contact@exemple.fr';
\echo '=== T45 temoin positif : la meme requete via citext (index) ==='
EXPLAIN (ANALYZE, BUFFERS, COSTS OFF)
SELECT id FROM contacts WHERE workspace_id='1db106f5-c8a4-47b0-bf86-930f1ccc9f4a'
  AND email = 'contact@exemple.fr';
\echo '=== T46 health_practitioners : volume + emails non-ASCII ==='
SELECT count(*) total, count(*) FILTER (WHERE email IS NOT NULL) avec_email,
       count(*) FILTER (WHERE email::text ~ '[^\x20-\x7E]') non_ascii FROM health_practitioners;
