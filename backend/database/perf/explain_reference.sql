\set ws '20cd81e4-de5d-4875-a759-07d64fe1f168'
\timing off
\pset pager off
\echo === HUB actifs, page 1 (50 + 1) — ContactsHubController::buildQuery + applyTemperature(actifs)
EXPLAIN (ANALYZE, BUFFERS, SUMMARY) SELECT companies.* FROM companies
WHERE deleted_at IS NULL AND workspace_id = :'ws'::uuid
  AND (lifecycle_stage <> 'nouveau' OR EXISTS (SELECT 1 FROM company_tag JOIN tags ON tags.id = company_tag.tag_id
       WHERE company_tag.company_id = companies.id AND tags.slug NOT LIKE 'src:scraping-%' AND tags.slug LIKE 'src:%'))
ORDER BY updated_at DESC, id DESC LIMIT 51;
\echo === HUB actifs, filtre relation_type=client
EXPLAIN (ANALYZE, BUFFERS, SUMMARY) SELECT companies.* FROM companies
WHERE deleted_at IS NULL AND workspace_id = :'ws'::uuid AND relation_type = 'client'
  AND (lifecycle_stage <> 'nouveau' OR EXISTS (SELECT 1 FROM company_tag JOIN tags ON tags.id = company_tag.tag_id
       WHERE company_tag.company_id = companies.id AND tags.slug NOT LIKE 'src:scraping-%' AND tags.slug LIKE 'src:%'))
ORDER BY updated_at DESC, id DESC LIMIT 51;
\echo === HUB froids (base froide 250 000)
EXPLAIN (ANALYZE, BUFFERS, SUMMARY) SELECT companies.* FROM companies
WHERE deleted_at IS NULL AND workspace_id = :'ws'::uuid AND lifecycle_stage = 'nouveau'
  AND NOT EXISTS (SELECT 1 FROM company_tag JOIN tags ON tags.id = company_tag.tag_id
       WHERE company_tag.company_id = companies.id AND tags.slug NOT LIKE 'src:scraping-%' AND tags.slug LIKE 'src:%')
ORDER BY updated_at DESC, id DESC LIMIT 51;
\echo === HUB recherche préfixe (denomination ILIKE 'Cabinet Mar%')
EXPLAIN (ANALYZE, BUFFERS, SUMMARY) SELECT companies.* FROM companies
WHERE deleted_at IS NULL AND workspace_id = :'ws'::uuid
  AND (lifecycle_stage <> 'nouveau' OR EXISTS (SELECT 1 FROM company_tag JOIN tags ON tags.id = company_tag.tag_id
       WHERE company_tag.company_id = companies.id AND tags.slug NOT LIKE 'src:scraping-%' AND tags.slug LIKE 'src:%'))
  AND (denomination ILIKE 'Cabinet Mar%' OR siren ILIKE 'Cabinet Mar%')
ORDER BY updated_at DESC, id DESC LIMIT 51;
\echo === HUB eager contacts (50 fiches)
EXPLAIN (ANALYZE, BUFFERS, SUMMARY) SELECT * FROM contacts WHERE deleted_at IS NULL AND company_id IN (
  SELECT id FROM companies WHERE workspace_id = :'ws'::uuid AND siren >= '500000000' ORDER BY updated_at DESC, id DESC LIMIT 50);
\echo === HUB compteurs (group by relation_type, lifecycle_stage)
EXPLAIN (ANALYZE, BUFFERS, SUMMARY) SELECT relation_type, lifecycle_stage, count(*) FROM companies
WHERE workspace_id = :'ws'::uuid AND deleted_at IS NULL GROUP BY relation_type, lifecycle_stage;
\echo === TIMELINE d'une personne (activities par person_key, 5 ans)
EXPLAIN (ANALYZE, BUFFERS, SUMMARY) SELECT * FROM activities WHERE workspace_id = :'ws'::uuid
  AND person_key = (SELECT person_key FROM contacts ORDER BY id OFFSET 12345 LIMIT 1)
ORDER BY occurred_at DESC LIMIT 100;
\echo === EXPORT clients (flux, ~9 000 lignes)
EXPLAIN (ANALYZE, BUFFERS, SUMMARY) SELECT id, siren, denomination, postcode, city, relation_type, lifecycle_stage FROM companies
WHERE workspace_id = :'ws'::uuid AND deleted_at IS NULL AND relation_type = 'client' ORDER BY id;
\echo === RECHERCHE GLOBALE (denomination ILIKE préfixe, sans filtre température)
EXPLAIN (ANALYZE, BUFFERS, SUMMARY) SELECT id, denomination FROM companies
WHERE workspace_id = :'ws'::uuid AND deleted_at IS NULL AND denomination ILIKE 'Cabinet Martin%' ORDER BY updated_at DESC LIMIT 20;
