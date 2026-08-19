\echo '########## 1. Le SQL EXACT de retention:purge, joue SANS contexte ##########'
\echo '--- etat initial : notifications, deux espaces'
SELECT workspace_id, count(*) FROM notifications GROUP BY 1 ORDER BY 1;

\echo '--- 1a. sous le role APPLICATIF (axion_app, cible du lot L0) : cron vert, zero ligne purgee'
BEGIN;
SET LOCAL ROLE axion_app;
SELECT set_config('app.current_workspace_id','',true);
UPDATE notifications SET created_at = now() - INTERVAL '400 days';
SELECT count(*) AS visibles_sans_contexte FROM notifications;
DELETE FROM notifications WHERE created_at < now() - INTERVAL '90 days';
ROLLBACK;

\echo '--- 1b. sous le role REEL de l application (axion, SUPERUSER) : purge les DEUX espaces'
BEGIN;
UPDATE notifications SET created_at = now() - INTERVAL '400 days';
SELECT set_config('app.current_workspace_id','',true);
SELECT count(*) AS visibles_sans_contexte FROM notifications;
DELETE FROM notifications WHERE created_at < now() - INTERVAL '90 days';
SELECT count(*) AS restantes FROM notifications;
ROLLBACK;

\echo '########## 2. Un job sans contexte : lecture ET ecriture ##########'
\echo '--- 2a. lecture (ScrapingCampaign::find equivalent) sous axion_app sans contexte'
BEGIN;
SET LOCAL ROLE axion_app;
SELECT set_config('app.current_workspace_id','',true);
SELECT count(*) AS scraping_campaigns_visibles FROM scraping_campaigns;
SELECT count(*) AS companies_visibles FROM companies;
SELECT count(*) AS contacts_visibles FROM contacts;
ROLLBACK;

\echo '--- 2b. ecriture sans contexte (Company::updateOrCreate equivalent) sous axion_app'
BEGIN;
SET LOCAL ROLE axion_app;
SELECT set_config('app.current_workspace_id','',true);
INSERT INTO companies (workspace_id, denomination, siren, country_code)
VALUES ('11111111-1111-4111-8111-111111111111','A11-SANS-CONTEXTE','999999999','FR');
ROLLBACK;

\echo '########## 3. health_practitioners : la policy reellement en base ##########'
SELECT p.polname, pg_get_expr(p.polqual, p.polrelid) AS using_clause
  FROM pg_policy p WHERE p.polrelid = 'health_practitioners'::regclass;
BEGIN;
SET LOCAL ROLE axion_app;
SELECT set_config('app.current_workspace_id','',true);
SELECT count(*) AS hp_sans_contexte FROM health_practitioners;
SELECT set_config('app.current_workspace_id','11111111-1111-4111-8111-111111111111',true);
SELECT count(*) AS hp_contexte_a FROM health_practitioners;
ROLLBACK;

\echo '########## 4. email_verification_logs : DEUX policies, dont une permissive ##########'
SELECT p.polname, pg_get_expr(p.polqual, p.polrelid) AS using_clause
  FROM pg_policy p WHERE p.polrelid = 'email_verification_logs'::regclass ORDER BY 1;
BEGIN;
SET LOCAL ROLE axion_app;
SELECT set_config('app.current_workspace_id','',true);
SELECT count(*) AS evl_sans_contexte FROM email_verification_logs;
ROLLBACK;

\echo '########## 5. audit_logs / sessions / user_workspaces : porteuses de workspace_id, SANS RLS ##########'
SELECT c.relname, c.relrowsecurity AS rls, c.relforcerowsecurity AS force_rls,
       (SELECT count(*) FROM pg_policy p WHERE p.polrelid=c.oid) AS policies
  FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
 WHERE n.nspname='public' AND c.relname IN ('audit_logs','audit_logs_default','sessions','user_workspaces')
 ORDER BY 1;
