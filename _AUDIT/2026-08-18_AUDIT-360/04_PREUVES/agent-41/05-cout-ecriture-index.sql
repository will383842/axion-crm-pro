\timing on
\echo '=== AGENT 41 — coût d écriture des index de companies (base axion_crm_a41, copie jetable de axion_crm_perf, 300 000 fiches, locale C) ==='
\echo '--- état initial'
select count(*) from companies;
select pg_size_pretty(pg_relation_size('companies')) tas, pg_size_pretty(pg_indexes_size('companies')) index;

\echo '=== TOUR 1 : insertion de 50 000 fiches AVEC les 27 index ==='
explain (analyze, buffers, timing off)
insert into companies (workspace_id, siren, denomination, naf, size_category,
  department_code, region_code, sector_main, postcode, city_name, quality_score, prospection_status,
  lifecycle_stage, relation_type, priority, website_status, signals, metadata, field_origins,
  country_code, is_artisan, geo_point, created_at, updated_at)
select workspace_id,
       lpad((100000000 + row_number() over (order by id))::text, 9, '0'),
       denomination, naf, size_category, department_code, region_code,
       sector_main, postcode, city_name, quality_score, prospection_status, lifecycle_stage,
       relation_type, priority, website_status, signals, metadata, field_origins, country_code,
       is_artisan, geo_point, now(), now()
from companies order by id limit 50000;

\echo '=== retrait des index JAMAIS PARCOURUS par une requête d écran ni par une requête du produit ==='
drop index idx_companies_denomination_trgm;
drop index idx_companies_geo;
drop index idx_companies_signals;
drop index idx_companies_ws_stage_updated_id;
drop index idx_companies_workspace_dept;
drop index idx_companies_archive_reason;
drop index idx_companies_best_email_confidence;
drop index idx_companies_revalidate;
drop index idx_companies_workspace_country_nature;
select pg_size_pretty(pg_indexes_size('companies')) index_apres_retrait;

\echo '=== TOUR 2 : insertion de 50 000 fiches SANS ces 9 index ==='
explain (analyze, buffers, timing off)
insert into companies (workspace_id, siren, denomination, naf, size_category,
  department_code, region_code, sector_main, postcode, city_name, quality_score, prospection_status,
  lifecycle_stage, relation_type, priority, website_status, signals, metadata, field_origins,
  country_code, is_artisan, geo_point, created_at, updated_at)
select workspace_id,
       lpad((200000000 + row_number() over (order by id))::text, 9, '0'),
       denomination, naf, size_category, department_code, region_code,
       sector_main, postcode, city_name, quality_score, prospection_status, lifecycle_stage,
       relation_type, priority, website_status, signals, metadata, field_origins, country_code,
       is_artisan, geo_point, now(), now()
from companies order by id limit 50000;

\echo '=== TÉMOIN : on remet les index, et on refait exactement la même insertion ==='
create index idx_companies_denomination_trgm on companies using gin (denomination_normalized gin_trgm_ops);
create index idx_companies_geo on companies using gist (geo_point);
create index idx_companies_signals on companies using gin (signals);
create index idx_companies_ws_stage_updated_id on companies (workspace_id, lifecycle_stage, updated_at desc, id desc) where deleted_at is null;
create index idx_companies_workspace_dept on companies (workspace_id, postcode);
create index idx_companies_archive_reason on companies (workspace_id, archive_reason) where archive_reason is not null;
create index idx_companies_best_email_confidence on companies (best_email_confidence) where best_email_confidence is not null;
create index idx_companies_revalidate on companies (website_status) where website_status::text = 'found' and website_revalidated_at is null;
create index idx_companies_workspace_country_nature on companies (workspace_id, country_code, entity_nature) where country_code <> 'FR';

\echo '=== TOUR 3 (témoin) : insertion de 50 000 fiches AVEC les 27 index de nouveau ==='
explain (analyze, buffers, timing off)
insert into companies (workspace_id, siren, denomination, naf, size_category,
  department_code, region_code, sector_main, postcode, city_name, quality_score, prospection_status,
  lifecycle_stage, relation_type, priority, website_status, signals, metadata, field_origins,
  country_code, is_artisan, geo_point, created_at, updated_at)
select workspace_id,
       lpad((300000000 + row_number() over (order by id))::text, 9, '0'),
       denomination, naf, size_category, department_code, region_code,
       sector_main, postcode, city_name, quality_score, prospection_status, lifecycle_stage,
       relation_type, priority, website_status, signals, metadata, field_origins, country_code,
       is_artisan, geo_point, now(), now()
from companies order by id limit 50000;

select count(*) from companies;
select pg_size_pretty(pg_relation_size('companies')) tas, pg_size_pretty(pg_indexes_size('companies')) index;
