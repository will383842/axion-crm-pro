#!/usr/bin/env bash
set -u
S=/c/Users/willi/AppData/Local/Temp/claude/C--Users-willi-Documents-Projets-Axion-CRM-Pro/1db6a17f-df98-48a0-95d2-e361a24d41b6/scratchpad/mesure.sh
DB=axion_crm_perf4m
WS="20cd81e4-de5d-4875-a759-07d64fe1f168"

bash $S $DB A04-companies-filtre-denomination-partial \
"select * from \"companies\" where \"deleted_at\" is null and LOWER(\"companies\".\"denomination\") LIKE '%marti%' and \"workspace_id\" = '$WS' order by \"quality_score\" desc limit 100 offset 0;"

bash $S $DB A04b-companies-count-filtre-denomination-partial \
"select count(*) as aggregate from \"companies\" where \"deleted_at\" is null and LOWER(\"companies\".\"denomination\") LIKE '%marti%' and \"workspace_id\" = '$WS';"

bash $S $DB A07-hub-liste-actifs \
"select * from \"companies\" where \"deleted_at\" is null and \"workspace_id\" = '$WS' and (\"lifecycle_stage\" != 'nouveau' or exists (select 1 from \"company_tag\" inner join \"tags\" on \"tags\".\"id\" = \"company_tag\".\"tag_id\" where \"company_tag\".\"company_id\" = \"companies\".\"id\" and \"tags\".\"slug\" not like 'src:scraping-%' and \"tags\".\"slug\" like 'src:%')) order by \"updated_at\" desc, \"id\" desc limit 51;"

bash $S $DB A08-hub-recherche-q \
"select * from \"companies\" where \"deleted_at\" is null and \"workspace_id\" = '$WS' and (\"lifecycle_stage\" != 'nouveau' or exists (select 1 from \"company_tag\" inner join \"tags\" on \"tags\".\"id\" = \"company_tag\".\"tag_id\" where \"company_tag\".\"company_id\" = \"companies\".\"id\" and \"tags\".\"slug\" not like 'src:scraping-%' and \"tags\".\"slug\" like 'src:%')) and (\"denomination\" ilike 'marti%' or exists (select * from \"contacts\" where \"companies\".\"id\" = \"contacts\".\"company_id\" and (\"last_name\" ilike 'marti%' or \"email\" ilike 'marti%'))) order by \"updated_at\" desc, \"id\" desc limit 51;"

bash $S $DB A09-export-premier-lot \
"select * from \"companies\" where \"deleted_at\" is null and \"workspace_id\" = '$WS' order by \"id\" asc limit 1000;"
