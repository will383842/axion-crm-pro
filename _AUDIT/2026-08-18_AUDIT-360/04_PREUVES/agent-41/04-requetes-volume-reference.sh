#!/usr/bin/env bash
set -u
S=/c/Users/willi/AppData/Local/Temp/claude/C--Users-willi-Documents-Projets-Axion-CRM-Pro/1db6a17f-df98-48a0-95d2-e361a24d41b6/scratchpad/mesure.sh
DB=axion_crm_perf
WS="20cd81e4-de5d-4875-a759-07d64fe1f168"
VIV="95cbe9b3-378e-4c9a-87cf-1d0faa629643"

bash $S $DB B01-companies-count \
"select count(*) as aggregate from \"companies\" where \"deleted_at\" is null and \"workspace_id\" = '$WS';"

bash $S $DB B02-companies-page-defaut \
"select * from \"companies\" where \"deleted_at\" is null and \"workspace_id\" = '$WS' order by \"quality_score\" desc limit 100 offset 0;"

bash $S $DB B03-companies-tri-denomination \
"select * from \"companies\" where \"deleted_at\" is null and \"workspace_id\" = '$WS' order by \"denomination\" asc limit 100 offset 0;"

bash $S $DB B04-companies-filtre-denomination-partial \
"select * from \"companies\" where \"deleted_at\" is null and LOWER(\"companies\".\"denomination\") LIKE '%marti%' and \"workspace_id\" = '$WS' order by \"quality_score\" desc limit 100 offset 0;"

bash $S $DB B05-contacts-count \
"select count(*) as aggregate from \"contacts\" where \"workspace_id\" = '$WS' and \"deleted_at\" is null;"

bash $S $DB B06-contacts-page \
"select * from \"contacts\" where \"workspace_id\" = '$WS' and \"deleted_at\" is null order by \"id\" desc limit 50 offset 0;"

bash $S $DB B07-contacts-tri-last-name \
"select * from \"contacts\" where \"workspace_id\" = '$WS' and \"deleted_at\" is null order by \"last_name\" asc limit 50 offset 0;"

bash $S $DB B08-contacts-filtre-last-name-partial \
"select * from \"contacts\" where \"workspace_id\" = '$WS' and \"deleted_at\" is null and LOWER(\"contacts\".\"last_name\") LIKE '%mar%' order by \"id\" desc limit 50 offset 0;"

bash $S $DB B09-hub-liste-actifs \
"select * from \"companies\" where \"deleted_at\" is null and \"workspace_id\" = '$WS' and (\"lifecycle_stage\" != 'nouveau' or exists (select 1 from \"company_tag\" inner join \"tags\" on \"tags\".\"id\" = \"company_tag\".\"tag_id\" where \"company_tag\".\"company_id\" = \"companies\".\"id\" and \"tags\".\"slug\" not like 'src:scraping-%' and \"tags\".\"slug\" like 'src:%')) order by \"updated_at\" desc, \"id\" desc limit 51;"

bash $S $DB B10-hub-recherche-q \
"select * from \"companies\" where \"deleted_at\" is null and \"workspace_id\" = '$WS' and (\"lifecycle_stage\" != 'nouveau' or exists (select 1 from \"company_tag\" inner join \"tags\" on \"tags\".\"id\" = \"company_tag\".\"tag_id\" where \"company_tag\".\"company_id\" = \"companies\".\"id\" and \"tags\".\"slug\" not like 'src:scraping-%' and \"tags\".\"slug\" like 'src:%')) and (\"denomination\" ilike 'marti%' or exists (select * from \"contacts\" where \"companies\".\"id\" = \"contacts\".\"company_id\" and (\"last_name\" ilike 'marti%' or \"email\" ilike 'marti%'))) order by \"updated_at\" desc, \"id\" desc limit 51;"

bash $S $DB B11-arbitrage-count \
"select count(*) as aggregate from \"activities\" where \"workspace_id\" = '$WS' and \"subject_id\" is null and payload -> 'pending_match' IS NOT NULL and payload -> 'arbitrage_dismissed' IS NULL;"

bash $S $DB B12-arbitrage-page \
"select * from \"activities\" where \"workspace_id\" = '$WS' and \"subject_id\" is null and payload -> 'pending_match' IS NOT NULL and payload -> 'arbitrage_dismissed' IS NULL order by \"occurred_at\" asc, \"id\" asc limit 50;"

bash $S $DB B13-timeline-activites \
"select * from \"activities\" where \"workspace_id\" = '$WS' and \"person_key\" = (select person_key from contacts where person_key is not null limit 1) order by \"occurred_at\" desc, \"id\" desc limit 200;"

bash $S $DB B14-timeline-sujets-business \
"select \"contacts\".\"id\" as contact_id, \"contacts\".\"first_name\", \"contacts\".\"last_name\", \"contacts\".\"email\", \"companies\".\"id\" as company_id, \"companies\".\"denomination\" from \"contacts\" left join \"companies\" on \"companies\".\"id\" = \"contacts\".\"company_id\" where \"contacts\".\"workspace_id\" = '$WS' and \"contacts\".\"person_key\" = (select person_key from contacts where person_key is not null limit 1) limit 20;"

bash $S $DB B15-audit-logs-count \
"select count(*) as aggregate from \"audit_logs\";"

bash $S $DB B16-audit-logs-page \
"select * from \"audit_logs\" order by \"id\" desc limit 50 offset 0;"

bash $S $DB B17-tags-liste \
"select * from \"tags\" where \"workspace_id\" = '$WS' order by \"category\" asc, \"name\" asc limit 500;"

bash $S $DB B18-tags-compteurs \
"select \"tag_id\", COUNT(*) as c from \"company_tag\" where \"tag_id\" in (select id from tags where workspace_id = '$WS' limit 500) group by \"tag_id\";"

bash $S $DB B19-vivier-candidats \
"select * from \"candidates\" where \"deleted_at\" is null and \"workspace_id\" = '$VIV' order by \"derniere_interaction_at\" desc, \"id\" desc limit 51;"

bash $S $DB B20-export-lot-companies \
"select * from \"companies\" where \"deleted_at\" is null and \"workspace_id\" = '$WS' order by \"id\" asc limit 1000;"

# --- extras volume production (perf4m) ---
DB2=axion_crm_perf4m
bash $S $DB2 A10-companies-pagination-profonde \
"select * from \"companies\" where \"deleted_at\" is null and \"workspace_id\" = '$WS' order by \"quality_score\" desc limit 100 offset 99900;"

bash $S $DB2 A11-companies-tri-enriched-at \
"select * from \"companies\" where \"deleted_at\" is null and \"workspace_id\" = '$WS' order by \"enriched_at\" asc limit 100 offset 0;"
