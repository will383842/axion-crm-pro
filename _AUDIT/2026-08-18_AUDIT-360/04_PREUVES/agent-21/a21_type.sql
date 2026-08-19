SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY;
SET statement_timeout='1200s';
SET max_parallel_workers_per_gather=0;
\pset pager off
\echo '=== T36 companies.relation_type : distribution ==='
SELECT relation_type, count(*), round(100.0*count(*)/sum(count(*)) OVER (),4) pct FROM companies GROUP BY 1 ORDER BY 2 DESC;
\echo '=== T37 conference / newsletter comme TYPE (le CDC en fait des MOTIFS) ==='
SELECT count(*) FROM companies WHERE relation_type IN ('conference','newsletter');
\echo '=== T38 contacts : existe-t-il une colonne de type ? ==='
SELECT count(*) AS colonnes_de_type FROM information_schema.columns
WHERE table_schema='public' AND table_name='contacts' AND column_name IN ('relation_type','type','contact_type','lifecycle_stage');
\echo '=== T39 candidates : colonnes de type + volume ==='
SELECT (SELECT count(*) FROM candidates) volume,
       (SELECT count(*) FROM information_schema.columns WHERE table_schema=''||'public' AND table_name='candidates' AND column_name='relation_type') col_relation_type;
\echo '=== T40 companies portant PLUSIEURS tags impliquant un type (multi-types du 2.2) ==='
WITH typed AS (
  SELECT ctg.company_id, count(DISTINCT t.slug) n
  FROM company_tag ctg JOIN tags t ON t.id=ctg.tag_id
  WHERE t.slug IN ('src:site-formulaire-presse','src:site-formulaire-partenariat','src:site-formulaire-investisseur',
                   'src:site-formulaire-speaker','src:newsletter','svc:conference','src:site-formulaire-podcast')
  GROUP BY 1)
SELECT count(*) FILTER (WHERE n>=1) au_moins_un, count(*) FILTER (WHERE n>=2) multi_types FROM typed;
\echo '=== T41 country_code / entity_nature ==='
SELECT country_code, entity_nature, count(*) FROM companies GROUP BY 1,2 ORDER BY 3 DESC LIMIT 15;
\echo '=== T42 fiches sans siren : ancre foreign_id respectee ? ==='
SELECT count(*) FILTER (WHERE siren IS NULL) sans_siren,
       count(*) FILTER (WHERE siren IS NULL AND foreign_id IS NULL) sans_ancre,
       count(*) FILTER (WHERE siren IS NULL AND entity_nature IS NULL) sans_nature
FROM companies;
