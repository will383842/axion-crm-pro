#!/usr/bin/env bash
set -u
S=/c/Users/willi/AppData/Local/Temp/claude/C--Users-willi-Documents-Projets-Axion-CRM-Pro/1db6a17f-df98-48a0-95d2-e361a24d41b6/scratchpad/mesure.sh
DB=axion_crm_perf4m
WS="20cd81e4-de5d-4875-a759-07d64fe1f168"

bash $S $DB A01-companies-count \
"select count(*) as aggregate from \"companies\" where \"deleted_at\" is null and \"workspace_id\" = '$WS';"

bash $S $DB A02-companies-page-defaut \
"select * from \"companies\" where \"deleted_at\" is null and \"workspace_id\" = '$WS' order by \"quality_score\" desc limit 100 offset 0;"

bash $S $DB A03-companies-tri-denomination \
"select * from \"companies\" where \"deleted_at\" is null and \"workspace_id\" = '$WS' order by \"denomination\" asc limit 100 offset 0;"

bash $S $DB A05-companies-filtre-naf \
"select * from \"companies\" where \"deleted_at\" is null and \"naf\" = '6201Z' and \"workspace_id\" = '$WS' order by \"quality_score\" desc limit 100 offset 0;"

bash $S $DB A06-companies-filtre-cree-apres \
"select * from \"companies\" where \"deleted_at\" is null and \"created_at\" >= '2026-08-01 00:00:00' and \"workspace_id\" = '$WS' order by \"quality_score\" desc limit 100 offset 0;"
