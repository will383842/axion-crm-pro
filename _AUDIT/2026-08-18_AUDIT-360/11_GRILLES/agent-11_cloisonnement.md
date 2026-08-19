# AGENT 11 — Cloisonnement par espace de travail, table par table

> Périmètre : les 101 tables de la base locale `axion_crm` (le prompt en annonçait 104 — le
> compte réel, mesuré, est 101 relations `relkind IN ('r','p')` dans le schéma `public`, dont
> 1 table partitionnée et 1 partition enfant).
> Toutes les épreuves SQL ont été jouées sur une base **jetable** créée pour l'occasion,
> `axion_crm_a11` (dump de schéma de `axion_crm`, jamais la production).

## 0. Référence de mesure — et sa dérive

| Ce que dit le dossier | Ce que dit `git log`, relu par moi |
|---|---|
| `main = c0c453d` | `c0c453d` est **3 commits derrière** au moment où j'ai commencé. `HEAD` était `1145473` au démarrage des mesures, puis `e8924b8` en fin de session : **d'autres agents commitent sur `main` pendant l'audit**. |

⚠️ **La base locale aussi a bougé pendant la session.** Au moment de mon relevé,
`axion_crm` portait **101** relations (`relkind IN ('r','p')`, schéma `public`). En fin de
session elle en porte **114** : **13 partitions mensuelles d'`audit_logs`** (`audit_logs_p20260201`
… `audit_logs_p20270201`) sont apparues pendant que je travaillais. Ma grille du §2 est donc
une **photographie datée** — celle du dump de schéma qui a servi à construire `axion_crm_a11`.
Les 13 nouvelles relations ne changent aucune conclusion : elles sont toutes `rls=f force=f`
avec **0 policy** (preuve `08_audit-logs-sans-rls.txt`), ce qui va dans le sens de B11-006.

Toutes mes mesures de code portent sur l'arbre de travail entre `1145473` et `e8924b8` ;
aucun fichier des chemins audités (`app/Support/WorkspaceContext.php`,
`app/Models/Scopes/WorkspaceScope.php`, `app/Http/Middleware/SetCurrentWorkspace.php`,
`app/Jobs/Concerns/RunsInWorkspace.php`, `database/migrations/2026_08_14_000001_*`)
n'a bougé entre ces deux commits (`git log --oneline c0c453d..HEAD -- backend/app backend/database` :
aucun commit touchant le backend, uniquement de la documentation RGPD/CNIL).

## 1. LE FAIT QUI COMMANDE TOUT LE RESTE — et qui n'est pas le même ici et là-bas

Le cloisonnement repose sur **deux** dispositifs, tous deux commandés par des drapeaux :

| Drapeau | Ce qu'il arme | Atelier local (mesuré) | Production (mesuré par l'agent 08) |
|---|---|---|---|
| `CRM_DB_APP_ROLE_ENABLED` | la connexion applicative passe du rôle `axion` (SUPERUSER + BYPASSRLS) au rôle `axion_app` (ni l'un ni l'autre) → **la RLS mord** | **`false`** | **`true`** |
| `CRM_STRICT_WORKSPACE_SCOPE` | `WorkspaceScope` (global scope Eloquent) + échec bruyant hors contexte | **`false`** | non mesurable d'ici |

Mesure locale (jouée) :

```
$ docker exec axion-crm-api php artisan tinker --execute="..."
pgsql | axion | strict=false | approle='axion_app'
current_user=axion

$ docker exec axion-crm-postgres psql -U axion -d axion_crm -c "SELECT rolname, rolsuper, rolbypassrls FROM pg_roles WHERE rolcanlogin"
 axion     | t | t
 axion_app | f | f
```

Mesure production (source : `04_PREUVES/agent-08/04_prod-env-isolation-tz.txt`, lecture seule) :
`CRM_DB_APP_ROLE_ENABLED=true`, `axion super=true bypassrls=true`, `axion_app super=false bypassrls=false`.
Corroboration indépendante : l'incident **production du 2026-08-15 02:30**
(`SQLSTATE[42501] new row violates row-level security policy for table "tags"`,
documenté dans `app/Console/Commands/ScrapingBackfillSrcTags.php:85`) n'est possible
**que si la RLS mord en production**.

**Conséquence méthodologique, et c'est le cœur de ce rapport :** l'atelier local exécute
tout le code hors-requête *sans* RLS, la production l'exécute *avec*. Tout code qui oublie
de poser le contexte d'espace est donc **vert en local et muet en production**.

## 2. GRILLE — les 101 tables, une ligne par table

Colonnes :
- **kind** : `table` / `partitionnee` (relkind `p`) / `partition` (enfant)
- **ws** : la table porte-t-elle une colonne d'espace `workspace_id` ?
- **RLS** / **FORCE** : `relrowsecurity` / `relforcerowsecurity` (sans `FORCE`, le propriétaire de la table échappe à sa propre policy — **démontré** au §4, témoin n°2)
- **pol** : nombre de policies
- **s/ctx** : lignes visibles **sans** contexte d'espace, sous le rôle `axion_app` (attendu **0**)
- **ctx A** : lignes visibles avec le contexte de l'espace A (attendu **1**)
- **hors A** : lignes d'un AUTRE espace visibles avec le contexte de A (attendu **0**)
- **verdict** : ETANCHE / FUITE-SANS-CONTEXTE / FUITE-CROSS-ESPACE / `-` (hors périmètre : pas de colonne d'espace)

Méthode : semis générique de **2 lignes par table** (une dans l'espace
`1111…`, une dans l'espace `2222…`) sur les 57 tables ordinaires portant `workspace_id`
(`04_PREUVES/agent-11/` — script `a11_seed.sql` + rattrapages), puis les trois lectures
ci-dessus jouées sous `SET ROLE axion_app`. **Aucune table n'est restée vide** : le contrôle
« 0 ligne sans contexte » n'est donc jamais vrai par vacuité (c'est le piège n°12 du dossier).

| table | kind | ws | RLS | FORCE | pol | s/ctx | ctx A | hors A | verdict |
|---|---|---|---|---|---|---|---|---|---|
| `activities` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `ai_act_register` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `analytics_attribution` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `analytics_cohorts` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `analytics_daily_rollups` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `analytics_funnels` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `analytics_kpis` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `audience_members` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `audit_logs` | partitionnee | **oui** | **non** | **non** | **0** | non mesuré — table partitionnée, hors semis | — | — | **SANS PROTECTION** |
| `audit_logs_default` | partition | **oui** | **non** | **non** | **0** | non mesuré — partition enfant | — | — | **SANS PROTECTION** |
| `business_events` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `campaigns` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `candidate_tag` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `candidates` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `companies` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `company_tag` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `contacts` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `coverage_zones` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `crm_lost_reasons` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `crm_notes` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `crm_pipelines` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `crm_tasks` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `deals` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `dnc_lists` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `duplicate_flags` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `email_audiences` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `email_events` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `email_inboxes` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `email_sends` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `email_templates` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `email_threads` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `email_verification_logs` | table | oui | oui | oui | **2** | **2** | 1 | 0 | **FUITE-SANS-CONTEXTE** |
| `email_warmup_pools` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `health_practitioners` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `invitations` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `journalists` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `linkedin_accounts` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `linkedin_invitations` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `linkedin_messages` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `linkedin_profiles_cache` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `linkedin_sequences` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `llm_usage` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `llm_use_cases` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE (lignes globales `workspace_id IS NULL` tolérées **par décision écrite**) |
| `media` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `notifications` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `pipeline_stages` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `proxy_providers_config` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `proxy_usage_log` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `rgpd_requests` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `rotations` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `saved_views` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `scraper_runs` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `scraping_campaigns` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `sessions` | table | oui | **non** | **non** | **0** | **2** | 2 | **1** | **FUITE-CROSS-ESPACE** — exclusion **motivée** (une session est lue avant tout espace) |
| `strategic_keywords` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `tags` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `unsubscribes` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `user_workspaces` | table | oui | **non** | **non** | **0** | **2** | 2 | **1** | **FUITE-CROSS-ESPACE** — exclusion **motivée** (poule et œuf : c'est elle qui dit à quel espace on appartient) |
| `web_vital_samples` | table | oui | oui | oui | 1 | 0 | 1 | 0 | ETANCHE |
| `analytics_funnel_snapshots` | table | **non** | non | non | 0 | — | — | — | **hors cloisonnement** — porte des agrégats liés à `analytics_funnels` (scopée) via `funnel_id` |
| `axion_offer_targets` | table | non | non | non | 0 | — | — | — | référentiel commercial, pas de donnée client |
| `cities` | table | non | non | non | 0 | — | — | — | référentiel géo |
| `countries` | table | non | non | non | 0 | — | — | — | référentiel géo |
| `crm_outbound_events` | table | **non** | non | non | 0 | — | — | — | **données personnelles** (`person_key`, `email_hash`, `payload`) — cloisonné par une colonne `scope`, pas par un espace |
| `deal_history` | table | **non** | non | non | 0 | — | — | — | **historique d'affaires** — rattaché à `deals` (scopée) par `deal_id` seulement |
| `departments` | table | non | non | non | 0 | — | — | — | référentiel géo |
| `dnc_entries` | table | **non** | non | non | 0 | — | — | — | **`email`, `phone`** — rattaché à `dnc_lists` (scopée) par `dnc_list_id` seulement |
| `effectif_ranges` | table | non | non | non | 0 | — | — | — | référentiel |
| `email_messages` | table | **non** | non | non | 0 | — | — | — | **corps des courriels** (`from_address`, `to_addresses`, `body_text`) — rattaché à `email_threads` (scopée) par `thread_id` seulement |
| `email_sequences` | table | **non** | non | non | 0 | — | — | — | rattaché à `campaigns` (scopée) par `campaign_id` seulement |
| `email_suppressions` | table | **non** | non | non | 0 | — | — | — | **`email`, `email_hash`** — colonne `scope`, transversale par conception |
| `email_validations` | table | **non** | non | non | 0 | — | — | — | **`email`** — transversale par conception (cache de validation) |
| `legal_forms` | table | non | non | non | 0 | — | — | — | référentiel |
| `linkedin_health_checks` | table | **non** | non | non | 0 | — | — | — | rattaché à `linkedin_accounts` (scopée) par `account_id` seulement |
| `linkedin_sequence_runs` | table | **non** | non | non | 0 | — | — | — | **`contact_id`** — rattaché à `linkedin_sequences` (scopée) par `sequence_id` seulement |
| `magic_links` | table | non | non | non | 0 | — | — | — | authentification (par utilisateur) |
| `migrations` | table | non | non | non | 0 | — | — | — | infrastructure |
| `model_has_permissions` | table | non | non | non | 0 | — | — | — | RBAC |
| `model_has_roles` | table | non | non | non | 0 | — | — | — | RBAC — porte un `team_id` = l'espace (colonne d'espace **sous un autre nom**) |
| `naf_classes` | table | non | non | non | 0 | — | — | — | référentiel NAF |
| `naf_divisions` | table | non | non | non | 0 | — | — | — | référentiel NAF |
| `naf_groups` | table | non | non | non | 0 | — | — | — | référentiel NAF |
| `naf_sections` | table | non | non | non | 0 | — | — | — | référentiel NAF |
| `naf_subclasses` | table | non | non | non | 0 | — | — | — | référentiel NAF |
| `opt_out` | table | **non** | non | non | 0 | — | — | — | **`email`, `phone`** (liste d'opposition) — colonne `scope`, transversale par conception |
| `part_config` | table | non | non | non | 0 | — | — | — | pg_partman |
| `part_config_sub` | table | non | non | non | 0 | — | — | — | pg_partman |
| `password_reset_tokens` | table | non | non | non | 0 | — | — | — | authentification |
| `permissions` | table | non | non | non | 0 | — | — | — | RBAC |
| `personal_access_tokens` | table | non | non | non | 0 | — | — | — | authentification |
| `prompt_template_versions` | table | non | non | non | 0 | — | — | — | configuration LLM |
| `prompt_templates` | table | non | non | non | 0 | — | — | — | configuration LLM |
| `regions` | table | non | non | non | 0 | — | — | — | référentiel géo |
| `role_has_permissions` | table | non | non | non | 0 | — | — | — | RBAC |
| `roles` | table | non | non | non | 0 | — | — | — | RBAC |
| `scraping_sources` | table | non | non | non | 0 | — | — | — | configuration de collecte |
| `search_engines` | table | non | non | non | 0 | — | — | — | configuration |
| `spatial_ref_sys` | table | non | non | non | 0 | — | — | — | PostGIS |
| `user_agents` | table | non | non | non | 0 | — | — | — | configuration |
| `users` | table | non | non | non | 0 | — | — | — | identité (rattachement par `user_workspaces`) |
| `workspaces` | table | non | non | non | 0 | — | — | — | la table des espaces elle-même |

### Totaux mesurés

| Grandeur | Valeur |
|---|---|
| Tables auditées (relations `r` + `p`, schéma `public`) | **101** |
| Tables portant une colonne d'espace `workspace_id` | **59** (57 ordinaires + `audit_logs` + `audit_logs_default`) |
| Tables portant `ENABLE` **et** `FORCE ROW LEVEL SECURITY` | **55** |
| Tables portant `workspace_id` **sans** aucune RLS ni policy | **4** au moment du relevé : `sessions`, `user_workspaces`, `audit_logs`, `audit_logs_default` — **16** en fin de session, les 13 partitions mensuelles d'`audit_logs` apparues entre-temps étant toutes `rls=f force=f` avec 0 policy |
| Épreuve « sans contexte → 0 ligne » réussie | **54 / 57** |
| Épreuve « contexte A → seulement A » réussie | **55 / 57** |
| Tables sans colonne d'espace portant des **données personnelles ou clients** | **11** (voir B11-007) |

## 3. LE PATRON « CONTEXTE PERDU APRÈS LA RÉPONSE » — recensement exhaustif

`SetCurrentWorkspace::terminate()` (`app/Http/Middleware/SetCurrentWorkspace.php:76-84`) appelle
`WorkspaceContext::clear()`. Symétriquement, `AppServiceProvider::boot()` (ligne 90) pose un
`Queue::looping()` qui **efface le contexte avant chaque job**. Tout ce qui s'exécute
après la réponse, ou hors requête, part donc **de zéro**.

J'ai cherché **toutes** les formes de ce patron. Voici le recensement, chaque ligne mesurée :

| Forme du patron | Occurrences mesurées | État |
|---|---|---|
| `Cache::flexible` (rafraîchissement différé, s'exécute après la réponse) | **1** — `app/Crm/Console/CompteursHub.php:75` | **CORRIGÉ** le 19/08 : le calcul pose lui-même `WorkspaceContext::run` (ligne 148). Gardé par `CompteursHubTest` « le calcul pose lui-même le contexte de workspace ». |
| `dispatchAfterResponse()` / `->afterResponse()` | **0** | sans objet |
| `defer()` / `App::terminating()` | **0** | sans objet |
| `register_shutdown_function`, Octane | **0** | sans objet |
| Middleware `terminate()` s'exécutant **après** `SetCurrentWorkspace` | **0** — un seul middleware du groupe `api` porte un `terminate()`, c'est `SetCurrentWorkspace` lui-même (`AuditHashChainLogger` et `EnforceFirstLoginSetup` travaillent dans `handle()`, donc **avant** le nettoyage) | sain |
| **Jobs de file** (`app/Jobs/`) | **6**, dont **5 sans aucun contexte** | **B11-002** |
| **Commandes planifiées** (`routes/console.php`) | **33** distinctes, dont **28 touchent une table cloisonnée**, dont **26 sans aucun contexte** | **B11-001** |
| Événements `ShouldBroadcast` + `SerializesModels` (re-résolution des modèles dans le worker) | **4** (`CompanyEnriched`, `NotificationCreated`, `ScrapeJobCompleted`, `ScraperRunCancelled`) | **B11-011** (dormant : Reverb est arrêté) |
| Échappatoire **explicite** `WorkspaceContext::runWithoutScope()` | **0 appelant** dans `app/` | **B11-009** |

Détail des jobs (`04_PREUVES/agent-11/06_hors-http_commandes-planifiees-et-jobs.txt`) :

| Job | contexte posé ? |
|---|---|
| `DispatchScrapeJob` | **non** |
| `EnrichCompanyJob` | oui (`RunsInWorkspace`) |
| `LaunchCampaignJob` | **non** (lit `ScrapingCampaign::find()`, écrit `companies`, `scraping_campaigns`) |
| `LaunchZoneScrapingJob` | **non** (`Company::updateOrCreate`, `scraping_campaigns`, `scraper_runs`) |
| `MonitorCampaignProgressJob` | **non** (`ScrapingCampaign::find()`) |
| `RefreshAudienceChunkJob` | **non** (`EmailAudience::find()`, `contacts`, `audience_members`) |

Détail des tâches planifiées : les **deux seules** qui posent un contexte sont
`rgpd:purge-vivier` et `rgpd:purge-business-prospects`. Les 26 autres qui touchent une table
cloisonnée n'en posent aucun (tableau complet dans la preuve 06).

## 4. TÉMOINS NÉGATIFS — la preuve que mes contrôles savent voir une fuite

### Témoin n°1 — la sonde distingue bien étanche et non étanche

La **même** épreuve, sur les **mêmes** données, sous les **deux** rôles :

| Rôle joué | superuser | bypassrls | verdict sur 57 tables |
|---|---|---|---|
| `axion_app` (rôle applicatif de production) | f | f | 54 ETANCHE, 2 FUITE-CROSS-ESPACE, 1 FUITE-SANS-CONTEXTE |
| `axion` (rôle de l'atelier local) | **t** | **t** | **57 FUITE-CROSS-ESPACE** |

Preuve : `04_PREUVES/agent-11/03_epreuve_role-applicatif.txt` et
`04_PREUVES/agent-11/04_TEMOIN-NEGATIF_role-superuser-toutes-tables-fuient.txt`.
Une sonde qui rendrait « 0 » par accident (droits manquants, table vide, connexion muette)
ne pourrait pas produire ces deux tableaux opposés sur les mêmes lignes.

### Témoin n°2 — `FORCE ROW LEVEL SECURITY` est bien porteur

Table jetable `a11_force_demo`, propriétaire jetable `a11_owner` (NOSUPERUSER NOBYPASSRLS),
2 lignes dans 2 espaces, contexte posé sur l'espace A :

```
 PROPRIETAIRE, RLS SANS FORCE |               2
 PROPRIETAIRE, RLS AVEC FORCE |               1
```

La colonne « FORCE » de ma grille n'est donc pas décorative : sans elle, le propriétaire
des tables voit tout, y compris avec une policy stricte.

### Témoin n°3 — la sonde « écriture » sait refuser

Sous `axion_app` sans contexte, l'écriture que ferait `Company::updateOrCreate` est
**refusée**, pas silencieusement acceptée :

```
ERROR:  new row violates row-level security policy for table "companies"
```

## 5. LES GARDES, ET LEUR ROUGE

| Garde | Ce qu'elle mesure | Vue rougir ? |
|---|---|---|
| `tests/Feature/EtancheiteParTableTest.php` (566 l., 11 tests) | les 3 propriétés sur chaque table scopée, via la connexion `pgsql_app` | **OUI, rouge — 4 échecs / 11, sans que rien n'ait été cassé** (preuve `07_ROUGE_*`). Mais ce rouge n'est **pas attribuable au produit** : deux exécutions de tests concurrentes partageaient la base `axion_crm_test` (B11-005). |
| `tests/Feature/RlsTest.php` (386 l.) | RLS sur `companies` et `tags`, inertie des drapeaux, régression du backfill 2026-08-15 | non rejouée isolément (voir §8) |
| `tests/Feature/Crm/CompteursHubTest.php` § « le calcul pose lui-même le contexte » | le patron `Cache::flexible` | non rejouée isolément (voir §8) |
| **aucune garde** | les 26 tâches planifiées et les 5 jobs sans contexte | — |

---

## 6. CE QUE J'AI CHERCHÉ ET QUI VA BIEN

- Le point chaud **`health_practitioners`** : le commentaire « même politique
  permissive-si-non-défini » (`2026_07_04_000001_create_health_practitioners.php:51`) décrit
  une policy qui **n'existe plus**. Le durcissement du 14/08 l'a remplacée par une policy
  stricte unique. Mesuré, pas déduit : la table rend **0 ligne sans contexte** et **1 ligne
  (la bonne) avec le contexte de A** (preuve 05, section 3). Ce n'est **pas** une porte
  ouverte — c'est un commentaire faux (voir B11-008).
- `Queue::looping` efface bien le contexte entre deux jobs : un contexte résiduel ne peut pas
  fuir d'un job sur le suivant.
- `WorkspaceContext::run()` restaure l'état précédent dans un `finally` : il ne laisse jamais
  de contexte derrière lui, même sur exception.
- La liste d'exclusion de la migration de durcissement et celle des tests sont tenues
  synchronisées par un test dédié — c'est du bon travail, et il vaut mieux que ce que j'aurais
  écrit.

---

## 7. CONSTATS

### [B11-001] 26 des 33 tâches planifiées touchent des tables cloisonnées sans jamais poser de contexte d'espace
- Sévérité      : S0 bloquant
- Domaine       : backend / sécurité / conformité
- Référence     : `main` entre `1145473` et `e8924b8` (le dossier annonçait `c0c453d`, dépassé)
- Emplacement   : `backend/routes/console.php:12-170` ; 26 fichiers de `backend/app/Console/Commands/`
- Constat       : sur les 33 commandes planifiées, 28 lisent ou écrivent une table portant `workspace_id` ; seules `rgpd:purge-vivier` et `rgpd:purge-business-prospects` posent un `WorkspaceContext`.
- Preuve        : `04_PREUVES/agent-11/06_hors-http_commandes-planifiees-et-jobs.txt` (croisement automatique nom de commande → fichier → tables touchées → présence de `WorkspaceContext`) ; `04_PREUVES/agent-11/05_hors-requete_purge-jobs-policies.txt` §2a : sous `axion_app` sans contexte, `scraping_campaigns`, `companies` et `contacts` rendent **0 ligne** alors que 2 lignes existent.
- Témoin négatif: la même lecture, avec le contexte de l'espace A, rend bien **1 ligne** (grille §2, colonne « ctx A ») — la sonde n'est pas aveugle. Et sous le rôle `axion` (atelier local), les mêmes commandes voient **tout** : c'est bien le contexte qui décide, pas la sonde.
- Impact        : en production, où `CRM_DB_APP_ROLE_ENABLED=true`, ces 26 tâches s'exécutent sous `axion_app` sans `app.current_workspace_id`. Les policies strictes rendent alors **zéro ligne** : la tâche parcourt un ensemble vide, ne fait rien, et **sort en succès**. Un cron vert qui ne traite rien. Les tâches qui écrivent échouent au contraire en `SQLSTATE[42501]` — c'est exactement le symptôme constaté en production le 2026-08-15 sur `tags`. Toute la chaîne `media:*` (14 tâches), `prospection:*`, `companies:rescrape-archives`, `campaigns:start-scheduled`, `audiences:full-refresh` est concernée.
- Reproduction  : `docker exec axion-crm-postgres psql -U axion -d axion_crm_a11 -f /tmp/a11_hr.sql` (script archivé) ; ou, sur une base jetable migrée, `CRM_DB_APP_ROLE_ENABLED=true php artisan media:score-confidence` et comparer le nombre traité au nombre de lignes réelles.
- Correctif     : faire porter à chaque commande scopée la boucle « pour chaque espace, `WorkspaceContext::run($espace, …)` » — le patron existe déjà et est écrit dans `RgpdPurgeVivier` et `ScrapingBackfillSrcTags`. Pour les traitements réellement transversaux, exiger `WorkspaceContext::runWithoutScope('justification')`. Coût : ~1 h par commande × 26, plus un test de garde générique (« aucune commande planifiée touchant une table scopée n'est dépourvue de l'un des deux »), ~4 h.
- Statut        : ouvert

### [B11-002] 5 des 6 jobs de file s'exécutent sans contexte d'espace, alors que `Queue::looping` l'efface avant chacun
- Sévérité      : S0 bloquant
- Domaine       : backend / sécurité
- Référence     : `main` `1145473`..`e8924b8`
- Emplacement   : `backend/app/Jobs/DispatchScrapeJob.php`, `LaunchCampaignJob.php:51`, `LaunchZoneScrapingJob.php:81`, `MonitorCampaignProgressJob.php:42`, `RefreshAudienceChunkJob.php:49` ; contre-exemple correct : `EnrichCompanyJob.php`
- Constat       : `AppServiceProvider::boot()` (ligne 90) efface le contexte avant chaque job ; le trait `RunsInWorkspace` existe pour le reposer, et un seul job sur six l'utilise.
- Preuve        : `04_PREUVES/agent-11/06_hors-http_commandes-planifiees-et-jobs.txt` (section « Jobs ») ; `04_PREUVES/agent-11/05_hors-requete_purge-jobs-policies.txt` §2a (lecture → 0 ligne) et §2b (écriture → `ERROR: new row violates row-level security policy for table "companies"`).
- Témoin négatif: avec le contexte posé, les mêmes tables rendent la ligne attendue et l'insertion passe (grille §2). Le contrôle distingue donc bien les deux situations.
- Impact        : en production, `LaunchCampaignJob::handle()` fait `ScrapingCampaign::find($this->campaignId)` — sans contexte, la policy rend `null`, et le job sort par sa branche « campagne introuvable ». Une campagne lancée depuis l'écran ne démarre jamais, sans erreur visible. `LaunchZoneScrapingJob` et `RefreshAudienceChunkJob` échouent au contraire à l'écriture (42501), donc en `failed_jobs`. Le trait `RunsInWorkspace` documente précisément ce scénario dans son propre en-tête : il a été écrit, puis appliqué à un seul job.
- Reproduction  : mêmes commandes que B11-001, §2 du script.
- Correctif     : `use RunsInWorkspace;` + envelopper le corps de `handle()` dans `$this->inWorkspace($workspaceId, fn () => …)`. Les cinq jobs disposent déjà de l'identifiant d'espace (propriété `workspaceId`, ou via le modèle chargé — pour ceux-là, résoudre l'espace d'abord par une lecture `runWithoutScope` explicite). Coût : ~3 h + tests.
- Statut        : ouvert

### [B11-003] `retention:purge` supprime sans aucun filtre d'espace : elle purge tous les espaces à la fois, ou aucun
- Sévérité      : S1 grave
- Domaine       : backend / conformité
- Référence     : `main` `1145473`..`e8924b8`
- Emplacement   : `backend/app/Console/Commands/RetentionPurge.php:28-46` ; planifiée `routes/console.php:15` (`dailyAt('04:00')`)
- Constat       : les trois requêtes de la commande sont des `DELETE`/`UPDATE` bruts sur `email_validations`, `notifications` et `scraper_runs`, sans clause d'espace et sans contexte.
- Preuve        : `04_PREUVES/agent-11/05_hors-requete_purge-jobs-policies.txt` §1. Sous le rôle historique `axion` : `DELETE 2` — les lignes des **deux** espaces disparaissent. Sous le rôle applicatif `axion_app` sans contexte : `visibles_sans_contexte = 0`, `DELETE 0` — la commande ne purge **rien** et rend `SUCCESS`.
- Témoin négatif: le même `UPDATE`/`DELETE` sous `axion` voit 2 lignes et en supprime 2 ; la sonde sait donc compter des suppressions quand il y en a.
- Impact        : deux défauts opposés selon le drapeau. Aujourd'hui en production (`axion_app`), la rétention RGPD des `notifications` (90 j) et le noircissement des `scraper_runs` (90 j) **ne s'appliquent pas** — obligation de limitation de conservation non tenue, sans aucun signal. Si le drapeau était remis à `false`, la même commande purgerait indistinctement tous les espaces.
- Reproduction  : script archivé, section 1a/1b.
- Correctif     : boucler sur les espaces avec `WorkspaceContext::run`, ou déclarer explicitement le caractère transversal avec `runWithoutScope('rétention RGPD transversale, décision du …')` **et** faire tourner la commande sous une connexion propriétaire dédiée. Coût : ~2 h.
- Statut        : ouvert

### [B11-004] `email_verification_logs` porte deux policies dont une permissive : sans contexte, toutes les lignes sont visibles
- Sévérité      : S2 défaut
- Domaine       : sécurité
- Référence     : `main` `1145473`..`e8924b8`
- Emplacement   : `backend/database/migrations/2026_05_19_000001_create_email_verification_logs.php` (policy `email_verif_workspace_isolation`) vs `2026_08_14_000001_harden_workspace_isolation.php:97` (le `DROP POLICY IF EXISTS` ne vise que le nom canonique `<table>_workspace_isolation`)
- Constat       : la table porte **2** policies ; la survivante s'appelle `email_verif_workspace_isolation` (nom raccourci) et son prédicat est `workspace_id::text = COALESCE(NULLIF(current_setting(...),''), workspace_id::text)` — sans contexte, il vaut toujours vrai. Postgres additionne les policies permissives par `OR` : la stricte ne peut donc rien reprendre.
- Preuve        : `04_PREUVES/agent-11/02_policies-texte.txt` (les 56 policies, texte intégral) ; `04_PREUVES/agent-11/05_hors-requete_purge-jobs-policies.txt` §4 : `evl_sans_contexte = 2`. Grille §2, ligne `email_verification_logs`.
- Témoin négatif: les 54 autres tables scopées rendent `0` sur exactement la même sonde — le contrôle sait donc rendre 0 quand la barrière tient.
- Impact        : toute lecture hors contexte (job, tâche planifiée, rafraîchissement différé) voit les journaux de vérification d'adresses de **tous** les espaces. Ces journaux contiennent des adresses de courriel : donnée personnelle. C'est aussi la seule table qui ne bénéficie pas du durcissement L0.
- Reproduction  : `SELECT polname, pg_get_expr(polqual, polrelid) FROM pg_policy WHERE polrelid='email_verification_logs'::regclass;`
- Correctif     : une migration d'une ligne — `DROP POLICY IF EXISTS email_verif_workspace_isolation ON email_verification_logs;` — puis retirer la table de `EtancheiteWorkspace::DEFAUTS_CONNUS` et supprimer le test « DÉFAUT CONNU » (le test rougira sinon, c'est voulu). Coût : ~30 min. **Défaut déjà identifié et épinglé par `EtancheiteParTableTest` : je le confirme par une mesure indépendante, il reste ouvert.**
- Statut        : ouvert

### [B11-005] La suite de tests vise une base unique codée en dur : deux exécutions concurrentes se détruisent mutuellement, et la garde d'étanchéité en est sortie ROUGE
- Sévérité      : S2 défaut
- Domaine       : tests
- Référence     : `main` `1145473` (exécution) — `e8924b8` (rejeu)
- Emplacement   : `backend/phpunit.xml:43-44` (`DB_DATABASE=axion_crm_test`, `force="true"`, aucune isolation par processus)
- Constat       : `php artisan test --filter=EtancheiteParTable` a rendu **4 échecs, 7 succès (48 assertions)** sur la référence, sans aucune modification de ma part. Pendant le rejeu, `ps aux` dans le conteneur `api` montre **deux processus `pest` simultanés** (le mien, `--filter=EtancheiteParTable`, et celui d'un autre agent, `--filter=CrmOutbound`) visant **la même base** `axion_crm_test`, chacun y lançant son `migrate:fresh`.
- Preuve        : `04_PREUVES/agent-11/07_ROUGE_EtancheiteParTableTest_sur_main.txt` (les 4 échecs) ; `docker exec axion-crm-api sh -c "ps aux | grep pest"` → deux lignes `--filter=…` distinctes ; l'un des quatre messages d'échec est « Ces tables portent `workspace_id` mais ne sont semées par personne : **`audit_logs`** », ce qui suppose `audit_logs` en `relkind='r'` — or elle est bien `relkind='p'` avec 14 partitions sur `axion_crm_test` une fois la migration terminée (`04_PREUVES/agent-11/08_audit-logs-sans-rls.txt`). Le test a donc observé une base **à mi-migration**.
- Témoin négatif: la même base, interrogée après la fin des migrations, rend l'inventaire attendu (1 parent partitionné + 14 relations) — la sonde d'inventaire n'est pas fausse, c'est l'instant de mesure qui l'était.
- Impact        : deux conséquences. (1) **On ne peut pas obtenir un vert ou un rouge digne de confiance** sur la suite d'étanchéité tant que deux exécutions peuvent se croiser — c'est le piège n°7 du dossier sous une autre forme : un rouge peut être un accident, donc un vert aussi. (2) Le rouge que j'ai mesuré n'est **pas attribuable au produit** avec certitude ; je le rapporte comme un défaut d'outillage, pas comme un défaut de cloisonnement. Les bases `axion_crm_test_test_1` / `_test_2` existent (support `--parallel`), mais `php artisan test` sans `--parallel` ne les utilise pas.
- Reproduction  : lancer deux `php artisan test --filter=X` simultanés dans le conteneur `api` et observer les échecs croisés.
- Correctif     : dériver le nom de base du PID ou d'une variable (`DB_DATABASE=axion_crm_test_${TEST_TOKEN:-0}`), ou poser un verrou de fichier au démarrage de la suite. Coût : ~2 h. **Puis** rejouer `EtancheiteParTableTest` seul pour obtenir un verdict propre — ce que je n'ai pas pu faire.
- Statut        : ouvert

### [B11-006] `audit_logs` et ses 14 partitions portent une colonne d'espace, sans aucune RLS ni policy
- Sévérité      : S1 grave
- Domaine       : sécurité / conformité
- Référence     : `main` `1145473`..`e8924b8`
- Emplacement   : exclusion écrite dans `backend/database/migrations/2026_08_14_000001_harden_workspace_isolation.php:61-66` ; table créée par `2026_05_16_000002_create_auth_tenant_audit_schema.php`, partitionnée par `2026_05_17_000011_setup_pg_partman_audit_logs.php`
- Constat       : sur `axion_crm` comme sur `axion_crm_test`, le parent `audit_logs` (`relkind='p'`) et ses 14 relations enfants portent `workspace_id` avec `relrowsecurity=false`, `relforcerowsecurity=false` et **0 policy**. La migration de durcissement les exclut explicitement, au motif que « RLS + partitions gérées dynamiquement = piège connu du dépôt ».
- Preuve        : `04_PREUVES/agent-11/08_audit-logs-sans-rls.txt` (les 15 relations d'`axion_crm`, une par ligne, plus l'agrégat sur `axion_crm_test` : `avec_rls = 0`, `total_policies = 0`) ; `04_PREUVES/agent-11/05_hors-requete_purge-jobs-policies.txt` §5.
- Témoin négatif: sur la même base et la même requête, les 55 autres tables scopées ressortent avec `rls=t force=t` et 1 policy — le relevé sait distinguer une table protégée d'une table qui ne l'est pas.
- Impact        : le journal d'audit — la chaîne cryptographique append-only qui sert de preuve en cas de contrôle CNIL — est la **seule** donnée cloisonnable du système qu'aucune barrière SQL ne protège. Sous le rôle applicatif, un compte d'un espace peut lire (et modifier : `axion_app` a `INSERT, UPDATE, DELETE` sur toutes les tables du schéma) les traces d'audit de tous les autres espaces. Le motif d'exclusion est daté : Postgres accepte `ENABLE`/`FORCE ROW LEVEL SECURITY` et une policy **sur le parent partitionné** depuis la version 10, et les partitions en héritent.
- Reproduction  : `docker exec axion-crm-postgres psql -U axion -d axion_crm -c "SELECT relname, relkind, relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname LIKE 'audit_logs%' AND relkind IN ('r','p')"`
- Correctif     : poser `ALTER TABLE audit_logs ENABLE/FORCE ROW LEVEL SECURITY` + la policy stricte **sur le parent**, vérifier qu'une partition créée ensuite par pg_partman en hérite (elle doit : la policy vit sur le parent), et retirer `audit_logs`/`audit_logs_default` de `EXCLUDED_TABLES` et d'`ABSENTES_PAR_CONSTRUCTION`. Prévoir le cas de la lecture transversale par la supervision : `runWithoutScope`. Coût : ~4 h + une migration + un test.
- Statut        : ouvert

### [B11-007] Onze tables portant des données personnelles ou clients n'ont ni colonne d'espace, ni policy
- Sévérité      : S2 défaut
- Domaine       : sécurité / conformité
- Référence     : `main` `1145473`..`e8924b8`
- Emplacement   : schéma `public` — `crm_outbound_events`, `deal_history`, `dnc_entries`, `email_messages`, `email_sequences`, `email_suppressions`, `email_validations`, `linkedin_health_checks`, `linkedin_sequence_runs`, `opt_out`, `analytics_funnel_snapshots`
- Constat       : ces onze tables portent des données rattachables à une personne ou à un client (`email`, `phone`, `person_key`, `body_text`, `to_addresses`, `contact_id`, `deal_id`) sans colonne d'espace ni policy. Sept d'entre elles sont des tables filles de tables cloisonnées : leur isolation ne tient que si **chaque** requête passe par la table mère.
- Preuve        : grille §2 (partie basse) ; `04_PREUVES/agent-11/01_inventaire-rls-101-tables.txt`. Colonnes relevées par `information_schema.columns`.
- Témoin négatif: le même relevé identifie correctement les 33 autres tables sans espace comme des référentiels (NAF, géo, RBAC, infrastructure) — le critère « porte-t-elle de la donnée personnelle » n'est pas appliqué au hasard.
- Impact        : quatre d'entre elles sont transversales **par conception** et portent une colonne `scope` à la place (`opt_out`, `email_suppressions`, `email_validations`, `crm_outbound_events`) — c'est défendable et il faut l'écrire. Les sept autres (`deal_history`, `dnc_entries`, `email_messages`, `email_sequences`, `linkedin_health_checks`, `linkedin_sequence_runs`, `analytics_funnel_snapshots`) ne sont aujourd'hui interrogées nulle part dans `app/` (mesuré : 0 référence) — le risque est donc **futur** : la première fonctionnalité qui lira `email_messages` lira les courriels de tous les espaces, et rien ne l'en empêchera.
- Reproduction  : requête d'inventaire du §2, plus `grep -rn "'email_messages'" backend/app`.
- Correctif     : pour les sept tables filles, ajouter `workspace_id` (dénormalisation assumée) + policy stricte, comme pour les 55 autres ; pour les quatre transversales, écrire la décision dans `EtancheiteWorkspace` à côté de `LIGNES_GLOBALES`, avec le motif. Coût : ~1 j (migration + backfill + tests).
- Statut        : ouvert

### [B11-008] Le commentaire « politique permissive-si-non-défini » de `health_practitioners` décrit une policy qui n'existe plus — et sert de modèle
- Sévérité      : S3 finition
- Domaine       : backend
- Référence     : `main` `1145473`..`e8924b8`
- Emplacement   : `backend/database/migrations/2026_07_04_000001_create_health_practitioners.php:51-65`
- Constat       : le commentaire annonce « même politique permissive-si-non-défini que les autres tables scoped », et le code qui suit crée effectivement une policy à triple repli. Cette policy a été remplacée le 2026-08-14 par une policy stricte ; le commentaire et le corps de la migration sont restés.
- Preuve        : `04_PREUVES/agent-11/05_hors-requete_purge-jobs-policies.txt` §3 — une seule policy en base, prédicat `workspace_id::text = NULLIF(current_setting('app.current_workspace_id', true), '')`, `hp_sans_contexte = 0`, `hp_contexte_a = 1`.
- Témoin négatif: la même sonde rend `2` sur `email_verification_logs`, qui elle a bien gardé un repli permissif — elle sait donc voir un repli quand il y en a un.
- Impact        : aucun aujourd'hui sur les données (art. 9 RGPD : nom, prénom, spécialité, RPPS — la table **est** correctement isolée). Le risque est de recopie : cette migration est le dernier exemple écrit de « comment créer une table scopée », et elle enseigne le mauvais patron. Une nouvelle table créée sur ce modèle **après** le 14/08 ne serait rattrapée par rien.
- Reproduction  : lecture du fichier + `SELECT pg_get_expr(polqual, polrelid) FROM pg_policy WHERE polrelid='health_practitioners'::regclass`.
- Correctif     : corriger le commentaire et le prédicat de la migration (elle n'est pas rejouée en production, mais elle l'est sur chaque base fraîche), ou ajouter un test de garde « aucune migration ne crée de policy contenant `current_setting(...) IS NULL` ou `COALESCE(NULLIF(current_setting` ». Coût : ~1 h.
- Statut        : ouvert

### [B11-009] L'échappatoire explicite `runWithoutScope()` n'a aucun appelant, alors que 31 commandes prennent la sortie implicite
- Sévérité      : S2 défaut
- Domaine       : backend / tests
- Référence     : `main` `1145473`..`e8924b8`
- Emplacement   : `backend/app/Support/WorkspaceContext.php:126-149`
- Constat       : `runWithoutScope(string $justification, Closure)` est décrite comme « volontairement verbeuse : elle doit sauter aux yeux en revue de code ». Mesure : **0 appelant** dans `app/`, `database/` ; un seul appel dans `tests/`, et c'est le test qui vérifie qu'elle refuse une justification vide.
- Preuve        : `grep -rn "runWithoutScope" backend/app backend/tests backend/database` → une seule ligne, `tests/Feature/RlsTest.php:292`.
- Témoin négatif: le même `grep` trouve 20 appels de `WorkspaceContext::run(` dans `app/` — il n'est donc pas aveugle au motif.
- Impact        : la garde de revue de code ne garde rien. Un traitement transversal légitime (rétention, supervision, réconciliation) est aujourd'hui **indistinguable** d'un oubli : les deux s'écrivent en ne faisant rien. C'est ce qui rend B11-001 et B11-003 invisibles à la relecture.
- Reproduction  : le `grep` ci-dessus.
- Correctif     : rendre l'absence de contexte impossible à obtenir par omission dans les contextes non-HTTP — par exemple un `Event::listen(CommandStarting)` qui, pour toute commande touchant une table scopée, exige que le contexte ait été posé ou que `runWithoutScope` ait été appelée. Coût : ~1 j, et c'est ce qui referme la famille entière.
- Statut        : ouvert

### [B11-010] L'atelier local n'arme aucun des deux dispositifs de cloisonnement : tout le code non contextualisé y passe au vert
- Sévérité      : S2 défaut
- Domaine       : tests / backend
- Référence     : `main` `1145473`..`e8924b8`
- Emplacement   : `backend/.env:51-53` (`CRM_DB_APP_ROLE_ENABLED=false`, `CRM_STRICT_WORKSPACE_SCOPE=false`) ; `docker inspect axion-crm-api` : aucune des deux variables n'est posée dans l'environnement du conteneur
- Constat       : en local, l'application se connecte comme `axion` (SUPERUSER + BYPASSRLS) et le global scope Eloquent est inerte. En production, `CRM_DB_APP_ROLE_ENABLED=true` (mesuré par l'agent 08). Les deux environnements n'exécutent pas le même cloisonnement.
- Preuve        : mesure `current_user=axion` + `strict=false` (§1) ; `04_PREUVES/agent-08/04_prod-env-isolation-tz.txt` ; `04_PREUVES/agent-11/04_TEMOIN-NEGATIF_role-superuser-toutes-tables-fuient.txt` (57 tables sur 57 fuient sous ce rôle).
- Témoin négatif: la même épreuve sous `axion_app` rend 54 tables étanches — l'écart mesuré entre les deux rôles est exactement l'écart entre les deux environnements.
- Impact        : c'est la cause racine de B11-001, B11-002 et B11-003. Un développeur qui écrit une commande sans contexte la voit fonctionner parfaitement en local et sur `axion_crm`, et elle est muette en production. Les tests d'étanchéité contournent le problème en ouvrant explicitement une connexion `pgsql_app` — ce qui les rend justes, mais ne protège **que** le code qu'ils visent.
- Reproduction  : `docker exec axion-crm-api php artisan tinker --execute="echo DB::selectOne('select current_user')->current_user;"`
- Correctif     : poser `CRM_DB_APP_ROLE_ENABLED=true` dans l'atelier local et dans la CI, comme en production, après avoir traité B11-001/002/003 (l'inverse casserait l'atelier). Coût : la bascule est gratuite, ce sont les correctifs préalables qui coûtent.
- Statut        : ouvert

### [B11-011] Quatre événements diffusés re-résolvent leurs modèles dans le worker, donc sans contexte d'espace
- Sévérité      : S3 finition
- Domaine       : backend
- Référence     : `main` `1145473`..`e8924b8`
- Emplacement   : `backend/app/Events/{CompanyEnriched,NotificationCreated,ScrapeJobCompleted,ScraperRunCancelled}.php`
- Constat       : les quatre implémentent `ShouldBroadcast` (donc mis en file) et utilisent `SerializesModels`, qui re-charge les modèles depuis la base **au moment de l'exécution du job**, c'est-à-dire après `Queue::looping()` qui a effacé le contexte.
- Preuve        : `grep -n "ShouldBroadcast\|SerializesModels" backend/app/Events/*.php` — 4 fichiers, les deux traits sur chacun.
- Témoin négatif: le même relevé trouve `SerializesModels` sur `DispatchScrapeJob` et pas sur les cinq autres jobs — il n'est pas aveugle au motif.
- Impact        : dormant. Reverb est arrêté et Echo est désactivé côté frontend (cf. `.github/workflows/deploy-direct-ssh.yml:189-199`), donc aucun de ces événements n'est réellement diffusé aujourd'hui. Le jour où le temps réel est rallumé, la re-résolution lèvera `ModelNotFoundException` sous RLS stricte, et la diffusion échouera en silence dans `failed_jobs`.
- Reproduction  : rallumer Reverb, déclencher `CompanyEnriched`, observer `failed_jobs`.
- Correctif     : porter dans chaque événement l'identifiant d'espace et envelopper `broadcastWith()` dans `WorkspaceContext::run`, ou passer des tableaux plutôt que des modèles. Coût : ~2 h, à faire **avant** de rallumer le temps réel.
- Statut        : ouvert

---

## 8. CE QUE JE N'AI PAS PU VÉRIFIER, ET POURQUOI

1. **L'état réel de la production.** Je n'ai aucun accès à la base de production et je n'en
   voulais aucun. Tout ce que j'affirme sur la production repose sur deux sources
   secondaires que je nomme : la mesure `docker inspect` de l'agent 08
   (`CRM_DB_APP_ROLE_ENABLED=true`) et l'incident du 2026-08-15 documenté dans le code.
   **Je n'ai pas vérifié moi-même** que la connexion applicative de production est bien
   `axion_app`, ni la valeur de `CRM_STRICT_WORKSPACE_SCOPE` en production.
2. **La sortie ROUGE volontaire, obtenue en cassant une garde du produit.** Je ne l'ai pas
   jouée, et c'est le manque le plus sérieux de ce rapport. Trois raisons, dans l'ordre :
   (a) la garde `EtancheiteParTableTest` est sortie **rouge d'elle-même** sur la référence,
   sortie archivée — mais B11-005 montre que ce rouge est pollué par une exécution
   concurrente, donc il ne remplace pas un rouge provoqué ; (b) chaque exécution de ce
   fichier coûte ≈ 13 minutes et **la base de test est partagée avec les autres agents**,
   qui l'utilisaient pendant toute ma session : un rouge provoqué aurait été aussi peu
   interprétable que celui-ci ; (c) l'effet de ce que j'aurais cassé — `FORCE ROW LEVEL
   SECURITY` — est démontré **directement en SQL** au §4 témoin n°2, sur une table jetable
   avec un propriétaire non-superutilisateur : `2` lignes visibles sans `FORCE`, `1` avec.
   **À rejouer proprement une fois B11-005 corrigé** : retirer `FORCE` dans la migration de
   durcissement, `--filter="portent ENABLE"`, archiver le rouge, restaurer.
3. **Le détail des deux autres échecs** d'`EtancheiteParTableTest` : la première exécution
   a été tronquée par mon propre `tail`, la deuxième a été tuée (exit 137), et la troisième
   tournait encore en concurrence d'un autre agent quand j'ai clos. Le décompte
   `4 failed, 7 passed` et deux des quatre messages sont archivés ; les deux autres ne le
   sont pas.
4. **Le chemin « jeton porteur »** (`Authorization: Bearer`). `SetCurrentWorkspace::handle()`
   appelle `$request->user()` **avant** que `auth:sanctum` ait choisi son garde, donc sur le
   garde par défaut `web` (session). Pour la SPA, qui utilise les cookies d'état
   (`frontend/src/lib/api.ts:7` `withCredentials: true` + `/sanctum/csrf-cookie`), cela
   fonctionne. Pour un client à jeton porteur, je **soupçonne** qu'aucun contexte n'est posé —
   je n'ai pas pu le mesurer : mes deux tentatives d'appel HTTP ont rendu `HTTP 000`,
   le conteneur `api` étant saturé par les exécutions de tests. À reprendre.
5. **Les 44 tables sans colonne d'espace** n'ont pas été soumises aux épreuves « 0 ligne sans
   contexte » : la question ne se pose pas pour elles. Je les ai en revanche toutes classées,
   une par une, dans la grille — c'est ce qui a permis d'isoler les 11 de B11-007.
6. **Les partitions futures d'`audit_logs`.** Je n'ai testé que `audit_logs_default`.
   Le comportement de pg_partman quand une nouvelle partition mensuelle est créée
   (hérite-t-elle d'une policy ? des privilèges de `axion_app` ?) n'a pas été mesuré.
7. **`RlsTest` et `CompteursHubTest` n'ont pas été rejoués** isolément, pour la même raison
   de coût. Je les ai lus, pas exécutés.

---

## 9. INTÉGRITÉ DE L'ARBRE DE TRAVAIL

Aucun fichier du produit n'a été modifié. Toutes les épreuves destructrices ont été jouées sur
la base **jetable** `axion_crm_a11`, créée pour l'occasion à partir d'un dump de schéma, et sur
des objets jetables (`a11_force_demo`, rôle `a11_owner`). Les seules écritures hors de cette
base sont : un jeton d'accès nommé `a11-audit` inséré dans `axion_crm.personal_access_tokens`
(à supprimer), et la base `axion_crm_test` reconstruite par `RefreshDatabase` — c'est sa
fonction.

`git status --porcelain` au début de ma session :

```
?? _AUDIT/2026-08-18_AUDIT-360/
?? _PROMPTS/PROMPT_AUDIT_360_CRM_PRO_2026-08-18.md
?? _PROMPTS/PROMPT_AUDIT_360_CRM_PRO_2026-08-18.v2.0-original-sauvegarde.md.bak
?? _PROMPTS/PROMPT_UX_CONSOLES_ADMIN_2026-08-18.md
?? backend/nul
```

À la fin, deux lignes de plus, **qui ne sont pas de moi** :

```
 D frontend/eslint-suppressions.json
?? frontend/eslint-suppressions.json.AUDIT46
```

Le suffixe `.AUDIT46` désigne un autre agent de cet audit, qui travaille en parallèle sur le
même arbre. Je n'ai touché **aucun** fichier de `frontend/`. Sur mon périmètre —
`backend/app/`, `backend/database/`, `backend/tests/`, `backend/routes/` — `git status` est
**vide**, avant comme après. `backend/nul` préexistait à ma session.

⚠️ Cet écart est lui-même une observation : l'arbre de travail est partagé entre plusieurs
agents, comme la base `axion_crm_test` (B11-005) et la base `axion_crm` (13 partitions
apparues en cours de session). Aucune de mes conclusions ne dépend de l'arbre de travail
d'un autre agent, mais quiconque relit mes mesures doit savoir qu'elles ont été prises sur
un plan de travail qui bougeait.

## 10. PREUVES ARCHIVÉES — `04_PREUVES/agent-11/`

| Fichier | Contenu |
|---|---|
| `01_inventaire-rls-101-tables.txt` | les 101 tables, `relkind`, `rls`, `force`, nombre de policies, présence de `workspace_id` |
| `02_policies-texte.txt` | le texte intégral des 56 policies (`USING` et `WITH CHECK`) |
| `03_epreuve_role-applicatif.txt` | l'épreuve « 0 sans contexte / A avec A » sur 57 tables, sous `axion_app` |
| `04_TEMOIN-NEGATIF_role-superuser-toutes-tables-fuient.txt` | la même épreuve sous `axion` : 57/57 fuient |
| `05_hors-requete_purge-jobs-policies.txt` | purge sans contexte (deux rôles), lecture et écriture d'un job, `health_practitioners`, `email_verification_logs`, tables sans RLS |
| `06_hors-http_commandes-planifiees-et-jobs.txt` | les 33 commandes planifiées et les 6 jobs, avec « touche une table scopée » et « pose un contexte » |
| `07_ROUGE_EtancheiteParTableTest_sur_main.txt` | la garde d'étanchéité, rouge sur la référence (4 échecs / 11) |
| `08_audit-logs-sans-rls.txt` | `audit_logs` + ses 14 partitions : `workspace_id` présent, `rls=f`, `force=f`, 0 policy, sur deux bases |
| `00_script_semis.sql`, `00_script_epreuve.sql`, `00_script_hors_requete.sql` | les scripts rejouables des trois épreuves |
