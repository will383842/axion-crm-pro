# AGENT 10 — Architecte du modèle de données

> Périmètre : **18 modèles** (`backend/app/Models/`), `Concerns/BelongsToWorkspace`,
> `Scopes/WorkspaceScope`, **58 migrations** (`backend/database/migrations/`), et les
> relations réellement en base.
> Preuves brutes : `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/agent-10/` (31 fichiers).

## 0. La référence — relue, pas recopiée

Le dossier commun nomme `main = c0c453d`. **C'est faux au moment de mon passage** (règle 13 du
dossier : ne pas utiliser un SHA écrit dans un document).

```
$ git log -1 --format='%H %s'      # au début de mon exécution
1145473c438751bed33e815d4bde93d7594ba4f1 docs(rgpd): registre des violations… (#188)
$ git log -1 --format='%H %s'      # à la fin
e8924b81ad64c0b236acd99ac5cbac4cd68eada7 fix(rgpd+acces): rectification du registre… (#189)
$ git diff --stat 1145473c e8924b8 -- backend/
(vide)
```

**`backend/` est identique entre les deux.** Toutes mes mesures valent donc pour `1145473c`
**et** pour `e8924b8`. Les constats ci-dessous sont référencés `main e8924b8` (`backend/`
inchangé depuis `1145473c`). Preuve : `04_PREUVES/agent-10/29-etat-final.txt`.

## 1. Le geste obligatoire — `migrate:fresh` DEUX FOIS

### 1.1 `make db-rebuild-check` n'est pas exécutable sur ce poste

```
$ make db-rebuild-check
/usr/bin/bash: line 1: make: command not found      (EXIT=127)
$ which make mingw32-make ; where.exe make
(aucun)
```

`make` n'est installé ni dans Git Bash, ni dans le `PATH` Windows. **La garde documentée par le
`Makefile` (cible `db-rebuild-check`, lignes 93-109) est inexécutable telle quelle sur le poste
du dirigeant.** J'ai donc rejoué **la recette de la cible, ligne pour ligne, sans la réinventer**
(règle 8) : `/tmp/rebuild.sh` reprend les six commandes de `Makefile:95-108` à l'identique.

### 1.2 Première exécution — les DEUX reconstructions échouent

Preuve : `04_PREUVES/agent-10/06-db-rebuild-check.txt`.

```
== reconstruction n1 ==   → RC1=1
== reconstruction n2 ==   → RC2=1
SQLSTATE[2BP01]: Dependent objects still exist: 7 ERROR:
  cannot drop table part_config because extension pg_partman requires it
select … pg_extension … pg_partman  →  public
select count(*) from partman.part_config  →  ERROR: relation "partman.part_config" does not exist
```

**La première reconstruction échoue, pas la seconde.** Le piège annoncé dans mon mandat («
`pg_partman` fait échouer `RefreshDatabase` sur une base ancienne ») est donc **plus grave que
décrit** : sur une base déjà migrée dont `pg_partman` vit dans `public`, `migrate:fresh` est mort
**dès le premier coup**, et la migration qui corrige le défaut
(`2026_08_18_100001_partman_dans_son_propre_schema`) **ne peut jamais être atteinte par ce
chemin**, puisque `dropAllTables()` s'exécute AVANT toute migration.

### 1.3 État de la base locale à mon arrivée : **4 migrations en retard**

```
$ docker exec axion-crm-api php artisan migrate:status | grep Pending
  2026_08_18_000001_backfill_empreintes_oppositions ......... Pending
  2026_08_18_100001_partman_dans_son_propre_schema .......... Pending
  2026_08_19_000001_companies_hub_counts_index .............. Pending
  2026_08_19_000002_crm_activites_et_motifs ................. Pending
```

54 lignes dans `migrations`, 58 fichiers sur disque. `axion_crm` **n'était pas à jour** ; les
tables `crm_activites` et `crm_motifs` n'existaient pas ; `pg_partman` était toujours dans
`public`. Preuves : `04-migrations-diff.txt`, `05-migrate-status-avant.txt`.

### 1.4 Après `php artisan migrate --force`, la double reconstruction PASSE

Preuves : `07-migrate-puis-relocalisation.txt`, `08-db-rebuild-check-APRES-relocalisation.txt`.

```
2026_08_18_100001_partman_dans_son_propre_schema ...... 424.96ms DONE
select … pg_extension … pg_partman  →  partman

== reconstruction n1 ==   → RC1=0
== reconstruction n2 ==   → RC2=0
 parent_table      | partition_type | partition_interval | premake | retention
 public.audit_logs | range          | 1 mon              |       6 | 24 months
EXIT_GLOBAL=0
```

**Verdict du geste obligatoire : `migrate:fresh` passe DEUX FOIS DE SUITE, mais seulement après
qu'un `migrate` ordinaire a relocalisé `pg_partman`.** Le correctif fonctionne ; la séquence de
réparation n'est écrite nulle part dans la garde. État final : 58/58 migrations, 14 partitions
`audit_logs`, `pg_partman` dans `partman`, `part_config` gère réellement `public.audit_logs`
(29-etat-final.txt).

### 1.5 Les quatre bases locales sont, elles, saines

```
axion_crm → partman | axion_crm_test → partman | axion_crm_perf → partman | axion_crm_perf4m → partman
```
(`09-bases-et-partman.txt`). Aucun index invalide dans les quatre (`27-index-sante.txt`).

---

## 2. Grille — les 18 MODÈLES, 14 points, aucune case vide

Légende : ✅ conforme · ⚠️ partiel/à surveiller · ❌ défaut · n/a sans objet ·
« nv » = non vérifié (motif donné en §6).

| # | Modèle | 1. ws / RLS / FORCE | 2. test étanchéité | 3. contraintes en base | 4. index | 5. types | 6. suppression | 7. sérialisation | 8. portée globale | 9. migration | 10. volume | 11. rétention | 12. RGPD export / effacement | 13. `fresh` | 14. vivant ? |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | `Company` (`companies`) | ✅ ws + RLS + FORCE + 1 policy stricte | ✅ couverte, verte | ✅ 11 CHECK, 3 UNIQUE, FK ws CASCADE | ⚠️ 27 index, 1 491 Mo pour 624 Mo de tas (perf4m) | ✅ 203/203 colonnes en `timestamptz`; `lat/lon` float; enums en CHECK | ❌ **`deleted_at` EN BASE, `SoftDeletes` ABSENT du modèle** → `delete()` est une suppression DURE qui cascade vers contacts/tags/audience_members/scraper_runs (B10-016) | ⚠️ pas de `$hidden`; masquage `contacts.view_pii` appliqué dans `CompaniesController:87` | ⚠️ `BelongsToWorkspace` posé, **inerte** (drapeau false) | ✅ rejouable | prod ≈ 4 295 349 ; perf4m 2 800 000 ; local 0 | ❌ aucune purge sur `companies` | ⚠️ effacement par `contacts.email` seulement ; `companies` jamais visée | ✅ | ✅ 23 réf. app / 25 front |
| 2 | `Contact` (`contacts`) | ✅ ws + RLS + FORCE | ✅ verte | ✅ 4 CHECK, 2 UNIQUE, FK CASCADE | ⚠️ 11 index ; `idx_contacts_ws_id_desc` présent, non retenu par le planificateur (23-explain) | ✅ | ❌ **`deleted_at` en base, `SoftDeletes` absent du modèle** → DURE (B10-016) | ⚠️ pas de `$hidden`; masqué dans `ContactsController:103` et `ContactsHubController:276` | ⚠️ trait posé, inerte | ✅ | prod ≈ 1 319 567 ; perf 50 000 | ⚠️ `rgpd:purge-business-prospects` (contacts périmés) | ✅ export **et** effacement (par `email` exact) | ✅ | ✅ 21 / 19 |
| 3 | `Candidate` (`candidates`) | ✅ ws + RLS + FORCE + trigger `candidates_enforce_vivier_workspace` | ✅ verte | ✅ 3 CHECK (relation_type, lifecycle_stage, legal_basis) | ✅ index ws | ✅ 8 casts `datetime`, `opt_out` bool | ✅ `SoftDeletes` | ❌ `present()` rend `email`, `phone`, `cv_ref`, `attributes` **en clair, sans masquage** (B10-005) | ⚠️ trait posé, inerte | ✅ | prod **0** ; local 0 | ✅ `rgpd:purge-vivier` (2 ans / 90 j) | ❌ **absent de l'export ET de l'effacement RGPD** (B10-004) | ✅ | ✅ contrôleur + route |
| 4 | `Tag` (`tags`) | ✅ ws + RLS + FORCE | ✅ verte | ✅ 2 CHECK (category, kind), colonne générée `namespace` | ✅ | ✅ `rules` jsonb → array | ⚠️ DURE, et c'est cohérent : `tags` n'a pas de `deleted_at` ; pivots `company_tag`/`candidate_tag` en CASCADE | ✅ aucune donnée personnelle | ⚠️ trait posé, inerte | ✅ | local 59 | n/a référentiel | n/a | ✅ | ✅ 23 / 10 |
| 5 | `User` (`users`) | ❌ **pas de `workspace_id`** (par construction) ; pas de RLS | n/a hors périmètre du scan | ✅ FK `current_workspace_id` SET NULL | ✅ | ✅ `totp_secret`, `two_factor_secret`, `two_factor_recovery_codes` en `encrypted` | ❌ `deleted_at` en base, `SoftDeletes` absent (B10-016) ; `sessions`/`users.current_workspace_id` en SET NULL | ✅ `$hidden` = password_hash, remember_token, totp_secret, 2FA ×2 — **le seul modèle du dépôt à en avoir un** | n/a | ✅ | local 2 | ❌ aucune | ❌ hors export et hors effacement RGPD | ✅ | ✅ |
| 6 | `Workspace` (`workspaces`) | n/a (c'est l'espace) | n/a | ✅ | ✅ | ✅ `cost_cap_eur` numeric(10,2) → `decimal:2` | ❌ `deleted_at` en base, `SoftDeletes` absent (B10-016) ; 45 FK CASCADE, **15 tables à `workspace_id` SANS FK** → orphelins (B10-007) | ✅ | n/a | ✅ | local 2 | n/a | n/a | ✅ | ✅ 13 réf. |
| 7 | `AuditLog` (`audit_logs`) | ❌ ws présent, **RLS absente, 0 policy, exclusion explicite** | ❌ **hors scan** (`relkind='p'`), exclusion écrite | ⚠️ PK (id, created_at) ; FK `user_id` SET NULL ; défaut `prev_hash` corrigé en `repeat('0',64)` | ✅ 2 index (ws, user) × 14 partitions | ✅ `created_at` timestamptz, `ip` inet | ❌ `axion_app` a **DELETE** sur `audit_logs` (14-droits) : un journal inviolable qu'on peut effacer (B10-002) | ⚠️ pas de `$hidden` ; `ip`, `user_agent`, `path` sérialisés | ❌ aucune (pas de trait) | ✅ (l'ordre partitions→INSERT est gardé par un test) | local 0 après reconstruction | ⚠️ `retention=24 months` configurée mais **jamais appliquée** (B10-003) | ❌ ni export ni effacement ; `rgpd:anonymize-ips` couvre `ip` seulement | ✅ 14 partitions créées | ✅ contrôleur + service |
| 8 | `AudienceMember` (`audience_members`) | ✅ ws + RLS + FORCE | ✅ verte | ⚠️ FK companies/contacts CASCADE, **pas de FK sur `workspace_id`** | ✅ | ✅ `added_at` datetime | ⚠️ DURE ; orphelin si le workspace disparaît | ⚠️ pas de `$hidden` (ne porte que des id) | ❌ pas de trait | ✅ | local 0 | ❌ aucune | ❌ ni export ni effacement | ✅ | ⚠️ 3 réf. app, 0 route, 0 écran |
| 9 | `EmailAudience` (`email_audiences`) | ✅ ws + RLS + FORCE | ✅ verte | ✅ FK ws CASCADE | ✅ | ✅ `criteria` jsonb | ✅ `SoftDeletes` | ⚠️ pas de `$hidden` ; `criteria` peut contenir des adresses | ❌ pas de trait | ✅ | local 0 | ❌ aucune | ❌ ni export ni effacement | ✅ | ⚠️ 3 réf. app, contrôleur `AudiencesController` |
| 10 | `HealthPractitioner` (`health_practitioners`) | ✅ ws + RLS + FORCE ; **prouvé de bout en bout** par un test nominatif | ✅ test dédié, vert | ✅ FK ws CASCADE, FK company SET NULL | ✅ | ✅ | ✅ `SoftDeletes` | ❌ **art. 9 RGPD** (nom, prénom, RPPS, spécialité, adresse) sans `$hidden` — aucun contrôleur ne l'expose aujourd'hui | ❌ pas de trait | ✅ | local 0 | ❌ aucune | ⚠️ effacement OUI (`GdprErasureService:69`), **export NON** | ✅ | ⚠️ 4 réf. app, aucun contrôleur |
| 11 | `Journalist` (`journalists`) | ✅ ws + RLS + FORCE | ✅ verte | ✅ FK ws CASCADE, media/company SET NULL | ✅ | ⚠️ `$casts` en **propriété statique** (ancien style) au lieu de `casts()` | ✅ `SoftDeletes` | ❌ `GET /journalists` rend le **modèle brut** : `email`, `phone`, `socials`, `source_url` en clair, **sans `contacts.view_pii`** (B10-005) | ❌ pas de trait | ✅ | local 0 | ❌ aucune | ✅ effacement OUI (anonymisation + opt-out + soft-delete) ; export NON | ✅ | ✅ contrôleur + 2 routes + 4 front |
| 12 | `LlmUseCase` (`llm_use_cases`) | ✅ ws + RLS + FORCE ; **seule table à `workspace_id IS NULL` légitime**, déclarée des deux côtés | ✅ verte (branche « lignes globales ») | ✅ FK ws CASCADE | ✅ | ✅ `cost_cap_eur` numeric(10,4) → `decimal:4` | ⚠️ DURE | ✅ aucune donnée personnelle | ❌ pas de trait | ✅ 2 migrations de contenu (`updateOrInsert`) | local 1 | n/a référentiel | n/a | ✅ | ✅ contrôleur |
| 13 | `Media` (`media`) | ✅ ws + RLS + FORCE | ✅ verte | ✅ 2 CHECK (media_type, email_confidence) | ✅ | ⚠️ `$casts` statique ; `email` en `citext` | ✅ `SoftDeletes` | ⚠️ pas de `$hidden` ; `email` de rédaction = donnée pro | ❌ pas de trait | ✅ | local 0 | ❌ aucune | ⚠️ effacement partiel (`media.email` mis à NULL) ; export NON | ✅ | ✅ 20 / 9 |
| 14 | `PersonalAccessToken` | n/a **pas de `workspace_id`** ; pas de RLS | n/a hors scan | ✅ | ✅ | ✅ override `tokenable_id` UUID | ⚠️ DURE ; pas de FK sur `tokenable_id` (polymorphe) | ✅ `token` masqué par Sanctum | n/a (modèle de paquet) | ✅ | local 0 | ❌ pas de purge des jetons expirés | ❌ ni export ni effacement | ✅ | ✅ (Sanctum) |
| 15 | `ProxyProvider` (`proxy_providers_config`) | ✅ ws + RLS + FORCE | ✅ verte | ✅ FK ws CASCADE | ✅ | ✅ | ⚠️ DURE | ⚠️ `metadata` jsonb peut porter des identifiants de proxy — pas de `$hidden` | ❌ pas de trait | ✅ | local 0 | ❌ aucune | n/a | ✅ | ✅ contrôleur |
| 16 | `RgpdRequest` (`rgpd_requests`) | ✅ ws + RLS + FORCE | ✅ verte | ✅ FK ws CASCADE | ✅ | ✅ 3 casts datetime | ⚠️ DURE ; l'effacement se garde lui-même (`type != 'erasure'`) | ❌ `subject_email` + `export_token` sans `$hidden` | ❌ pas de trait | ✅ | local 0 | ⚠️ pas de purge des `export_token` expirés | ✅ dans l'export **et** l'effacement | ✅ | ✅ contrôleur |
| 17 | `ScraperRun` (`scraper_runs`) | ✅ ws + RLS + FORCE | ✅ verte | ✅ 1 CHECK (status), FK ws/company CASCADE | ✅ | ✅ payloads jsonb | ⚠️ DURE | ⚠️ `request_payload`/`response_payload` peuvent contenir des adresses collectées, pas de `$hidden` | ❌ pas de trait | ✅ | local 0 | ✅ `retention:prune-scraper-runs --days=90` + purge des payloads > 90 j | ❌ ni export ni effacement | ✅ | ✅ 8 / 1 |
| 18 | `ScrapingCampaign` (`scraping_campaigns`) | ✅ ws + RLS + FORCE | ✅ verte | ✅ FK ws CASCADE | ✅ | ⚠️ `elapsed_minutes` dépend de `DB_TIMEZONE` (défaut vide → décalage documenté) | ✅ `SoftDeletes` | ⚠️ pas de `$hidden` | ❌ pas de trait | ✅ dont un correctif de type `workspace_id` | local 0 | ❌ aucune | ❌ ni export ni effacement | ✅ | ✅ contrôleur |

### 2.bis Les deux pièces d'isolation

| Objet | 1. ws/RLS | 2. test | 3. contraintes | 4. index | 5. types | 6. suppression | 7. sérialisation | 8. portée globale | 9. migration | 10. volume | 11. rétention | 12. RGPD | 13. `fresh` | 14. vivant ? |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `Concerns/BelongsToWorkspace` | n/a | ⚠️ aucun test ne vérifie **quels** modèles le portent | n/a | n/a | ✅ `validIdOrNull` accepte UUID/ULID/bigint | n/a | n/a | ❌ **posé sur 4 modèles sur les 15 dont la table a `workspace_id`** (B10-006) | n/a | n/a | n/a | n/a | n/a | ✅ mais couverture partielle |
| `Scopes/WorkspaceScope` | n/a | ⚠️ pas de test dédié ; l'inertie est gardée par `RlsTest` | n/a | n/a | n/a | n/a | n/a | ❌ **no-op complet** : `crm.strict_workspace_scope` = `env('CRM_STRICT_WORKSPACE_SCOPE', false)` — absent de l'environnement du conteneur (`11-roles-et-drapeaux.txt`) | n/a | n/a | n/a | n/a | n/a | ✅ code vivant, effet nul |

---

## 3. Grille — les 58 MIGRATIONS

Colonnes : **9a** rejouable (`IF [NOT] EXISTS` / `hasTable` / idempotence) · **9b** `down()`
présent et non vide · **9c** non destructive · **13** passe depuis zéro · **22** seeder appelé et
son verbe · **objet**.

| # | Migration | 9a | 9b | 9c | 13 | 22 — seeder | Objet et remarque mesurée |
|---|---|---|---|---|---|---|---|
| 1 | `2026_05_16_000001_create_extensions_and_helpers` | ✅ `IF NOT EXISTS` ×9 + `DO $$` | ✅ drop des 3 fonctions | ⚠️ `DROP EXTENSION pg_partman` si `part_config` vide | ✅ | — | 11 extensions + 3 fonctions ; porte **aussi** la relocalisation `partman` |
| 2 | `…000002_create_auth_tenant_audit_schema` | ✅ | ✅ | ✅ | ✅ | — | users/workspaces/user_workspaces/audit_logs/sessions |
| 3 | `…000003_create_companies_contacts_scraping_schema` | ✅ | ✅ | ✅ | ✅ | — | companies/contacts/tags/scraper_runs + colonnes générées |
| 4 | `…000004_create_llm_proxies_rotations_schema` | ✅ | ✅ | ✅ | ✅ | — | llm_use_cases, llm_usage, proxy_*, rotations |
| 5 | `…000005_create_referentials_geo_naf_schema` | ✅ | ✅ | ✅ | ✅ | — | naf_*, regions, departments, cities, countries |
| 6 | `…000006_create_coverage_rgpd_aiact_schema` | ✅ | ✅ | ✅ | ✅ | — | coverage_zones, rgpd_requests, ai_act_register, opt_out |
| 7 | `…000007_create_phase2_scaffold_schema` | ✅ | ✅ | ✅ | ✅ | — | squelette phase 2 (deals, pipelines, email_*) |
| 8 | `…000008_enable_rls_policies` | ✅ | ✅ | ✅ | ✅ | — | **policies permissives d'origine** — remplacées par la 36 |
| 9 | `…000009_create_quality_score_triggers` | ✅ `CREATE OR REPLACE` | ✅ | ✅ | ✅ | — | 3 triggers de score |
| 10 | `2026_05_17_000010_phase2_full_scaffold_schema` | ✅ | ✅ | ✅ | ✅ | — | linkedin_*, analytics_*, dnc_*, email_* |
| 11 | `…000011_setup_pg_partman_audit_logs` | ✅ `DO $$` + garde `is_partitioned` | ✅ | ⚠️ `DROP TABLE audit_logs CASCADE` avec sauvegarde/restauration | ✅ 14 partitions créées | — | 🔴 point chaud : l'ordre partitions→INSERT est gardé par un test |
| 12 | `2026_05_18_000001_apply_rls_dynamic` | ✅ | ✅ | ✅ | ✅ | — | liste en dur → a laissé ~10 tables sans policy (corrigé par la 36) |
| 13 | `…000002_add_onboarding_tour_to_users` | ✅ `hasColumn` | ⚠️ down d'1 ligne | ✅ | ✅ | — | 1 colonne |
| 14 | `…000003_create_scraping_campaigns_table` | ✅ | ✅ | ✅ | ✅ | — | table |
| 15 | `…000004_fix_scraping_campaigns_workspace_id_type` | ✅ `hasColumn` ×4 | ❌ `down()` vide | ⚠️ `ALTER TYPE` | ✅ | — | correctif de type |
| 16 | `…000005_add_updated_at_error_message_to_scraper_runs` | ✅ | ❌ vide | ✅ | ✅ | — | 2 colonnes |
| 17 | `…000006_add_prospection_fields_to_companies` | ✅ | ✅ | ✅ | ✅ | — | 10 colonnes + CHECK |
| 18 | `…000007_extend_tags_system` | ✅ | ✅ | ✅ | ✅ | — | category/kind + CHECK |
| 19 | `…000008_create_email_audiences` | ✅ | ✅ | ✅ | ✅ | — | email_audiences + audience_members |
| 20 | `2026_05_19_000001_create_email_verification_logs` | ✅ | ⚠️ 1 ligne | ✅ | ✅ | — | 🔴 crée `email_verif_workspace_isolation`, policy **au nom raccourci** qui survit au durcissement (B10-008) |
| 21 | `…000002_create_business_events` | ✅ | ⚠️ 1 ligne | ✅ | ✅ | — | table |
| 22 | `…000003_switch_llm_use_cases_to_mistral_primary` | ✅ `update` ciblé | ✅ | ✅ | ✅ | — | contenu |
| 23 | `…000004_enable_llm_json_mode_for_axion_use_cases` | ✅ | ✅ | ✅ | ✅ | — | contenu |
| 24 | `2026_07_04_000001_create_health_practitioners` | ✅ | ✅ | ✅ | ✅ | — | 🔴 policy permissive d'origine — **rattrapée**, prouvé par un test nominatif |
| 25 | `2026_07_06_000001_add_website_status_to_companies` | ✅ | ✅ | ✅ | ✅ | — | colonne + CHECK |
| 26 | `…000002_create_media_and_journalists` | ✅ | ✅ | ✅ | ✅ | — | media + journalists |
| 27 | `2026_07_08_000001_add_website_revalidated_at_to_companies` | ✅ | ✅ | ✅ | ✅ | — | colonne |
| 28 | `2026_07_09_000001_add_enseigne_to_companies` | ✅ | ⚠️ 1 ligne | ✅ | ✅ | — | colonne |
| 29 | `2026_07_09_000001_add_extract_journalists_llm_use_case` | ✅ | ✅ | ✅ | ✅ | ⚠️ **`updateOrInsert`** direct sur `llm_use_cases` | ⚠️ **collision d'horodatage** avec la 28 (même préfixe `2026_07_09_000001`) |
| 30 | `…000002_quality_score_terrain` | ✅ | ✅ | ✅ | ✅ | — | fonction de score |
| 31 | `…000003_backfill_media_website_status_pending` | ✅ update ciblé | ❌ vide | ✅ | ✅ | — | backfill |
| 32 | `…000004_add_companies_denomination_btree_index` | ✅ `IF NOT EXISTS` | ⚠️ 1 ligne | ✅ | ✅ | — | index 276 Mo à 2,8 M |
| 33 | `2026_07_14_000001_media_taxonomy_hardening` | ✅ | ✅ | ✅ | ✅ | — | CHECK media_type |
| 34 | `…000002_add_email_confidence` | ✅ | ✅ | ✅ | ✅ | — | colonne + CHECK |
| 35 | `…000003_add_email_confidence_to_media` | ✅ | ✅ | ✅ | ✅ | — | idem media |
| 36 | **`2026_08_14_000001_harden_workspace_isolation`** | ✅ scan dynamique + `DROP POLICY IF EXISTS` | ✅ down complet (repli permissif) | ✅ échec BRUYANT sur orphelins | ✅ | — | 🔴 crée `axion_app`, `ENABLE`+`FORCE` sur **55 tables**, policies strictes. Exclut `sessions`, `user_workspaces`, `audit_logs`, `audit_logs_default`. Filtre `relkind='r'` → **ne voit jamais les tables partitionnées**. **INERTE tant que `CRM_DB_APP_ROLE_ENABLED=false`** |
| 37 | `…000002_crm_socle_taxonomie_business` | ✅ | ✅ | ✅ | ✅ | — | `relation_type`, `lifecycle_stage`, `legal_basis`, `external_ref` + 5 CHECK |
| 38 | `…000003_crm_socle_vivier_candidats` | ✅ | ✅ | ✅ | ✅ | — | `candidates` + trigger `candidates_enforce_vivier_workspace` (refuse tout workspace dont le slug ne commence pas par `vivier`) |
| 39 | `…000004_crm_socle_tags_optout_timeline` | ✅ | ✅ | ✅ | ✅ | — | `activities`, `opt_out.scope`, colonne générée `tags.namespace` |
| 40 | `…000005_crm_socle_index_concurrents` | ✅ `CONCURRENTLY IF NOT EXISTS` | ✅ | ✅ | ✅ | — | index hors transaction |
| 41 | **`…000006_crm_scraping_sources`** | ✅ | ⚠️ 1 ligne | ✅ | ✅ | 🔴 **`ScrapingSourcesSeeder` → `upsert`** | table `scraping_sources` **sans `workspace_id`, sans RLS** (14-droits) |
| 42 | **`…000007_crm_outbound_events`** | ✅ | ⚠️ 1 ligne | ✅ | ✅ | — | file d'oppositions sortantes ; **sans `workspace_id`, sans RLS** — délibéré (une opposition est globale), 5 CHECK dont `origin='crm'` |
| 43 | `2026_08_15_000001_company_tag_assigned_by_backfill` | ✅ | ✅ | ✅ | ✅ | — | backfill |
| 44 | `…000002_companies_hub_temperature_index` | ✅ `CONCURRENTLY IF NOT EXISTS` | ⚠️ 1 ligne | ✅ | ✅ | — | `idx_companies_ws_stage_updated_id` (221 Mo) |
| 45 | **`…000003_contacts_liste_console_index`** | ✅ | ✅ | ✅ | ✅ | — | `idx_contacts_ws_id_desc`, `idx_contacts_ws_lower_last_name`, `idx_contacts_ws_email_status_id`. ⚠️ mesuré : sur 50 000 contacts le planificateur préfère `contacts_pkey` (23-explain) |
| 46 | **`…000004_email_suppressions`** | ✅ | ⚠️ 1 ligne | ✅ | ✅ | — | table **sans `workspace_id`, sans RLS** ; 4 CHECK, 2 UNIQUE partiels ; `email` en clair conservé à côté de `email_hash` |
| 47 | **`…000005_permission_contacts_view_pii`** | ✅ `updateOrInsert` | ✅ | ✅ | ✅ | ⚠️ **`updateOrInsert`** sur `permissions` + `role_has_permissions` | 🔴 doublon avec `PermissionsAndRolesSeeder` ; **`permissions` porte `UNIQUE(name)` seul, pas `(name, guard_name)`** (B10-009) |
| 48 | `…000006_companies_created_at_index` | ✅ | ⚠️ 1 ligne | ✅ | ✅ | — | index 144 Mo |
| 49 | `…100001_seed_implantations_fr_etranger_source` | ✅ | ✅ | ✅ | ✅ | 🔴 **`ScrapingSourcesSeeder` → `upsert`** (2ᵉ appel) | ajoute la source `implantations-fr-etranger` |
| 50 | **`…120001_companies_entites_sans_siren`** | ✅ | ✅ | ✅ | ✅ | — | `country_code`/`foreign_id`/`entity_nature` + CHECK `siren IS NOT NULL OR foreign_id IS NOT NULL` |
| 51 | **`…120002_companies_foreign_id_unique_index`** | ✅ `CONCURRENTLY IF NOT EXISTS`, `withinTransaction=false` | ✅ `DROP … CONCURRENTLY` | ✅ | ✅ | — | index unique partiel — corrige le trou « deux NULL sont distincts ». Aucun contrôle d'`indisvalid` après coup (aucun index invalide constaté) |
| 52 | **`2026_08_16_000001_audit_logs_prev_hash_default`** | ✅ ALTER idempotent | ✅ | ✅ ne réécrit aucune ligne | ✅ | — | défaut `'GENESIS'` → `repeat('0',64)` |
| 53 | **`…200000_fixer_search_path_des_fonctions`** | ✅ ALTER idempotent | ✅ RESET | ✅ | ✅ | — | 🔴 corrige la **non-restaurabilité de la sauvegarde de production** : `normalize_name()` appelait `unaccent()` non qualifié, `pg_dump` pose `search_path=''`. **Trou fermé aujourd'hui : 7/7 fonctions portent `search_path=public, pg_catalog`** (15-fonctions). ❌ **liste EN DUR, aucune garde** (B10-010) |
| 54 | `2026_08_17_000001_companies_hub_tous_index` | ✅ | ⚠️ 1 ligne | ✅ | ✅ | — | `idx_companies_ws_updated_id` (179 Mo) |
| 55 | `2026_08_18_000001_backfill_empreintes_oppositions` | ✅ | ❌ vide (assumé) | ✅ | ✅ | — | remplit `email_hash` sur `opt_out` et `email_suppressions` (0 ligne en local) |
| 56 | **`…100001_partman_dans_son_propre_schema`** | ✅ `DO $$` idempotent | ❌ vide (assumé, écrit) | ✅ abandonne si `part_config` non vide | ✅ **c'est elle qui rend le double `fresh` possible** | — | 🔴 relocalise `pg_partman` ; **inatteignable par `migrate:fresh`** sur une base atteinte (B10-001) |
| 57 | **`2026_08_19_000001_companies_hub_counts_index`** | ✅ `CONCURRENTLY IF NOT EXISTS` | ⚠️ 1 ligne | ✅ | ✅ | — | `idx_companies_ws_counts` (20 Mo). **Rejoué par moi sur 2,8 M** : `Parallel Index Only Scan`, `Heap Fetches: 0`, **878–1 229 ms à chaud, 4 249 ms à froid** (24-explain-chaud) — l'ordre de grandeur annoncé (648–972 ms) est corroboré |
| 58 | **`…000002_crm_activites_et_motifs`** | ✅ | ✅ | ✅ | ✅ | ✅ **`ActivitesEtMotifsSeeder` → `insertOrIgnore`** | crée `crm_activites` + `crm_motifs`, **sans `workspace_id`, sans RLS** ; aucun lecteur applicatif à ce jour |

### 3.bis Piège 22 — verdict par seeder appelé depuis une migration

| Seeder | Appelé par | Verbe | Colonnes réécrites à chaque déploiement | Verdict |
|---|---|---|---|---|
| `ScrapingSourcesSeeder` | migrations **41** et **49** | **`upsert(['slug'], [...])`** | `name`, `kind`, `ttl_days`, `legal_note`, `dedup_key_pattern`, `quota_per_day`, `updated_at` | 🔴 **DANGEREUX** — `enabled` est correctement exclu (le kill-switch survit), mais **six autres colonnes sont écrasées** (B10-011) |
| `ActivitesEtMotifsSeeder` | migration **58** | **`insertOrIgnore`** | aucune | ✅ **SÛR** — plancher, jamais plafond ; commentaire explicite |
| *(inline)* `2026_07_09_000001_add_extract_journalists_llm_use_case` | elle-même | `updateOrInsert` sur `llm_use_cases` | `primary_provider`, `model`, `fallback_chain`, `options` | ⚠️ écrase la configuration LLM si elle est éditée depuis la console `LlmUseCasesController` |
| *(inline)* `2026_08_15_000005_permission_contacts_view_pii` | elle-même | `updateOrInsert` sur `permissions` | `description` | ⚠️ bénin, mais **doublon de source de vérité** avec `PermissionsAndRolesSeeder` |

Aucun autre seeder n'est appelé depuis une migration (`grep -E "Seeder|db:seed|Artisan::call"` sur
les 58 fichiers : 3 occurrences, toutes ci-dessus).

---

## 4. Grille — les RELATIONS EN BASE

Le mandat annonce **104 tables**. La mesure, après les 58 migrations et la double reconstruction :

| Schéma | ordinaires (`r`) | partitionnées (`p`) | vues matérialisées (`m`) | vues (`v`) |
|---|---|---|---|---|
| `public` | **113** | 1 (`audit_logs`) | 1 (`coverage_matrix_cells`) | 2 |
| `partman` | 3 | 0 | 0 | 1 |

**115 relations dans `public`**, dont **14 partitions d'`audit_logs`** que `pg_partman` vient de
créer. Hors partitions : **101 tables ordinaires + 1 partitionnée + 1 vue matérialisée = 103**.
Le nombre « 104 » du mandat correspond à un état antérieur. Preuve : `13-inventaire-apres-fresh.txt`.

Tableau complet ci-dessous : **une ligne par relation**, colonnes = points 1, 3, 4, 6 de la grille.
Format : `workspace_id | RLS | FORCE | policies | FK | CHECK | UNIQUE | index | suppression`.
Les points 2, 5, 7, 8, 9, 10, 11, 12, 13, 14 sont traités **globalement** juste après le tableau
— ils ne se lisent pas table par table sans mentir.

| Table | 1a ws | 1b RLS | 1c FORCE | policies | FK | CHECK | UNIQUE | index | 6. suppr. |
|---|---|---|---|---|---|---|---|---|---|
| `activities` | ws | rls | force | 1 | 3 | 1 | 0 | 4 | dur |
| `ai_act_register` | ws | rls | force | 1 | 1 | 1 | 0 | 1 | dur |
| `analytics_attribution` | ws | rls | force | 1 | 1 | 0 | 0 | 1 | dur |
| `analytics_cohorts` | ws | rls | force | 1 | 0 | 0 | 0 | 1 | dur |
| `analytics_daily_rollups` | ws | rls | force | 1 | 0 | 0 | 0 | 1 | dur |
| `analytics_funnel_snapshots` | - | - | - | 0 | 1 | 0 | 1 | 2 | dur |
| `analytics_funnels` | ws | rls | force | 1 | 0 | 0 | 0 | 1 | dur |
| `analytics_kpis` | ws | rls | force | 1 | 0 | 0 | 0 | 1 | dur |
| `audience_members` | ws | rls | force | 1 | 3 | 0 | 1 | 5 | dur |
| `audit_logs` | ws | - | - | 0 | 1 | 0 | 0 | 3 | dur |
| `audit_logs_default` | ws | - | - | 0 | 1 | 0 | 0 | 3 | dur |
| `axion_offer_targets` | - | - | - | 0 | 0 | 0 | 0 | 1 | dur |
| `business_events` | ws | rls | force | 1 | 2 | 0 | 0 | 3 | dur |
| `campaigns` | ws | rls | force | 1 | 1 | 2 | 0 | 1 | soft |
| `candidate_tag` | ws | rls | force | 1 | 3 | 1 | 0 | 3 | dur |
| `candidates` | ws | rls | force | 1 | 1 | 3 | 0 | 7 | soft |
| `cities` | - | - | - | 0 | 1 | 0 | 0 | 7 | dur |
| `companies` | ws | rls | force | 1 | 1 | 11 | 1 | 27 | soft |
| `company_tag` | ws | rls | force | 1 | 2 | 1 | 0 | 2 | dur |
| `contacts` | ws | rls | force | 1 | 2 | 4 | 1 | 11 | soft |
| `countries` | - | - | - | 0 | 0 | 0 | 1 | 2 | dur |
| `coverage_matrix_cells` | ws | - | - | 0 | 0 | 0 | 0 | 1 | dur |
| `coverage_zones` | ws | rls | force | 1 | 2 | 0 | 1 | 4 | dur |
| `crm_activites` | - | - | - | 0 | 0 | 2 | 1 | 3 | dur |
| `crm_lost_reasons` | ws | rls | force | 1 | 1 | 0 | 1 | 2 | dur |
| `crm_motifs` | - | - | - | 0 | 0 | 3 | 1 | 3 | dur |
| `crm_notes` | ws | rls | force | 1 | 3 | 0 | 0 | 1 | dur |
| `crm_outbound_events` | - | - | - | 0 | 0 | 5 | 1 | 4 | dur |
| `crm_pipelines` | ws | rls | force | 1 | 1 | 0 | 1 | 2 | dur |
| `crm_tasks` | ws | rls | force | 1 | 3 | 0 | 0 | 1 | dur |
| `deal_history` | - | - | - | 0 | 2 | 0 | 0 | 1 | dur |
| `deals` | ws | rls | force | 1 | 6 | 2 | 0 | 1 | dur |
| `departments` | - | - | - | 0 | 1 | 0 | 0 | 3 | dur |
| `dnc_entries` | - | - | - | 0 | 1 | 0 | 0 | 1 | dur |
| `dnc_lists` | ws | rls | force | 1 | 1 | 0 | 0 | 1 | dur |
| `duplicate_flags` | ws | rls | force | 1 | 2 | 2 | 1 | 3 | dur |
| `effectif_ranges` | - | - | - | 0 | 0 | 0 | 0 | 1 | dur |
| `email_audiences` | ws | rls | force | 1 | 2 | 0 | 0 | 3 | soft |
| `email_events` | ws | rls | force | 1 | 2 | 1 | 0 | 2 | dur |
| `email_inboxes` | ws | rls | force | 1 | 1 | 0 | 1 | 2 | dur |
| `email_messages` | - | - | - | 0 | 1 | 1 | 0 | 1 | dur |
| `email_sends` | ws | rls | force | 1 | 4 | 1 | 0 | 3 | dur |
| `email_sequences` | - | - | - | 0 | 2 | 0 | 1 | 2 | dur |
| `email_suppressions` | - | - | - | 0 | 0 | 4 | 0 | 4 | dur |
| `email_templates` | ws | rls | force | 1 | 1 | 0 | 1 | 2 | dur |
| `email_threads` | ws | rls | force | 1 | 2 | 0 | 0 | 1 | dur |
| `email_validations` | - | - | - | 0 | 0 | 0 | 1 | 3 | dur |
| `email_verification_logs` | ws | rls | force | 2 | 1 | 0 | 1 | 4 | dur |
| `email_warmup_pools` | ws | rls | force | 1 | 1 | 0 | 0 | 1 | dur |
| `health_practitioners` | ws | rls | force | 1 | 2 | 0 | 0 | 6 | soft |
| `invitations` | ws | rls | force | 1 | 3 | 0 | 1 | 2 | dur |
| `journalists` | ws | rls | force | 1 | 3 | 0 | 0 | 5 | soft |
| `legal_forms` | - | - | - | 0 | 0 | 0 | 0 | 1 | dur |
| `linkedin_accounts` | ws | rls | force | 1 | 1 | 0 | 0 | 1 | dur |
| `linkedin_health_checks` | - | - | - | 0 | 1 | 0 | 0 | 1 | dur |
| `linkedin_invitations` | ws | rls | force | 1 | 2 | 1 | 0 | 1 | dur |
| `linkedin_messages` | ws | rls | force | 1 | 2 | 1 | 0 | 1 | dur |
| `linkedin_profiles_cache` | ws | rls | force | 1 | 0 | 0 | 0 | 1 | dur |
| `linkedin_sequence_runs` | - | - | - | 0 | 2 | 0 | 0 | 1 | dur |
| `linkedin_sequences` | ws | rls | force | 1 | 1 | 0 | 0 | 1 | dur |
| `llm_usage` | ws | rls | force | 1 | 1 | 0 | 0 | 3 | dur |
| `llm_use_cases` | ws | rls | force | 1 | 1 | 0 | 1 | 2 | dur |
| `magic_links` | - | - | - | 0 | 1 | 0 | 1 | 3 | dur |
| `media` | ws | rls | force | 1 | 3 | 2 | 0 | 12 | soft |
| `migrations` | - | - | - | 0 | 0 | 0 | 0 | 1 | dur |
| `model_has_permissions` | - | - | - | 0 | 2 | 0 | 0 | 1 | dur |
| `model_has_roles` | - | - | - | 0 | 2 | 0 | 0 | 1 | dur |
| `naf_classes` | - | - | - | 0 | 1 | 0 | 0 | 1 | dur |
| `naf_divisions` | - | - | - | 0 | 1 | 0 | 0 | 2 | dur |
| `naf_groups` | - | - | - | 0 | 1 | 0 | 0 | 1 | dur |
| `naf_sections` | - | - | - | 0 | 0 | 0 | 0 | 1 | dur |
| `naf_subclasses` | - | - | - | 0 | 1 | 0 | 0 | 1 | dur |
| `notifications` | ws | rls | force | 1 | 2 | 0 | 0 | 2 | dur |
| `opt_out` | - | - | - | 0 | 0 | 2 | 0 | 4 | dur |
| `password_reset_tokens` | - | - | - | 0 | 0 | 0 | 0 | 1 | dur |
| `permissions` | - | - | - | 0 | 0 | 0 | 1 | 2 | dur |
| `personal_access_tokens` | - | - | - | 0 | 0 | 0 | 1 | 3 | dur |
| `pipeline_stages` | ws | rls | force | 1 | 2 | 0 | 1 | 2 | dur |
| `prompt_template_versions` | - | - | - | 0 | 2 | 0 | 1 | 2 | dur |
| `prompt_templates` | - | - | - | 0 | 1 | 0 | 1 | 2 | dur |
| `proxy_providers_config` | ws | rls | force | 1 | 1 | 1 | 1 | 2 | dur |
| `proxy_usage_log` | ws | rls | force | 1 | 0 | 0 | 0 | 2 | dur |
| `regions` | - | - | - | 0 | 1 | 0 | 0 | 2 | dur |
| `rgpd_requests` | ws | rls | force | 1 | 2 | 2 | 0 | 3 | dur |
| `role_has_permissions` | - | - | - | 0 | 2 | 0 | 0 | 1 | dur |
| `roles` | - | - | - | 0 | 1 | 0 | 1 | 2 | dur |
| `rotations` | ws | rls | force | 1 | 1 | 1 | 1 | 2 | dur |
| `saved_views` | ws | rls | force | 1 | 2 | 0 | 1 | 2 | dur |
| `scraper_runs` | ws | rls | force | 1 | 3 | 1 | 1 | 6 | dur |
| `scraping_campaigns` | ws | rls | force | 1 | 2 | 4 | 0 | 4 | soft |
| `scraping_sources` | - | - | - | 0 | 0 | 4 | 1 | 2 | dur |
| `search_engines` | - | - | - | 0 | 0 | 0 | 0 | 1 | dur |
| `sessions` | ws | - | - | 0 | 2 | 0 | 0 | 3 | dur |
| `spatial_ref_sys` | - | - | - | 0 | 0 | 1 | 0 | 1 | dur |
| `strategic_keywords` | ws | rls | force | 1 | 1 | 0 | 0 | 2 | dur |
| `tags` | ws | rls | force | 1 | 1 | 2 | 1 | 5 | dur |
| `unsubscribes` | ws | rls | force | 1 | 0 | 0 | 1 | 2 | dur |
| `user_agents` | - | - | - | 0 | 0 | 0 | 1 | 2 | dur |
| `user_workspaces` | ws | - | - | 0 | 2 | 1 | 0 | 2 | dur |
| `users` | - | - | - | 0 | 1 | 0 | 1 | 4 | soft |
| `web_vital_samples` | ws | rls | force | 1 | 2 | 0 | 0 | 2 | dur |
| `workspaces` | - | - | - | 0 | 0 | 0 | 1 | 3 | soft |

### Lecture transversale des points restants

**2. Test d'étanchéité par table.** ✅ **Il existe et il est sérieux.**
`backend/tests/Feature/EtancheiteParTableTest.php` (566 lignes) + `tests/Support/EtancheiteWorkspace.php`
+ `tests/Support/SemeurTablesScopees.php`. Rejoué par moi :

```
$ docker exec axion-crm-api sh -c "cd /var/www/html && ./vendor/bin/pest --filter=Etancheite"
   PASS  Tests\Feature\EtancheiteParTableTest        (11 tests / 11 verts)
   FAIL  Tests\Feature\EtancheiteUniversTest         (2 échecs — cf. §5, B10-009)
  Tests: 2 failed, 20 passed (76 assertions) — Duration: 1354.37s
```
(`12-test-etancheite.txt`). Il **sème** les 55 tables scopées (donc pas de vert par vacuité),
il porte **deux témoins négatifs** (sonde qui doit changer d'avis quand on retire la RLS ; `FORCE`
prouvé sur un rôle propriétaire jetable non-superutilisateur), et il **épingle** le défaut connu
d'`email_verification_logs`. C'est la meilleure garde du dépôt sur ce sujet.
⚠️ Ce qu'il ne mesure pas, et qu'il écrit : la barrière est **prête**, pas **armée** — voir §5.

**5. Types.** ✅ **203 colonnes temporelles sur 203 sont `timestamptz`. Zéro `timestamp without
time zone`** (`21-types.txt`). Les 6 colonnes monétaires sont toutes `numeric` avec échelle
explicite (2, 4 ou 6 décimales), et les `casts` Eloquent correspondent. Les énumérations sont en
`CHECK` côté base (24 sur les seules tables cœur, 68 au total). Point le plus solide du modèle.

**6. Suppression.** **11 tables portent `deleted_at`** : `campaigns`, `candidates`, `companies`, `contacts`, `email_audiences`, `health_practitioners`, `journalists`, `media`, `scraping_campaigns`, `users`, `workspaces`. **Seuls 6 modèles sur 18 déclarent `SoftDeletes`** : `Candidate`, `EmailAudience`, `HealthPractitioner`, `Journalist`, `Media`, `ScrapingCampaign`. L'écart porte sur `companies`, `contacts`, `users` et `workspaces` — voir B10-016. Propagation : 85 FK en CASCADE, 36 en SET NULL, 17 NO ACTION, 1 RESTRICT (`20-fk-on-delete.txt`). Orphelins : voir B10-007.

**7. Sérialisation.** **Un seul modèle sur 18 déclare `$hidden` : `User`.** Le masquage des
coordonnées existe (`App\Support\MasquageCoordonnees`, ferme par défaut) mais n'est branché que sur
**3 surfaces** (`CompaniesController`, `ContactsController`, `Crm/ContactsHubController`). Voir B10-005.

**8. Portées globales.** `WorkspaceScope` : **no-op complet** aujourd'hui. `BelongsToWorkspace` :
posé sur **4 modèles sur 15**. Voir B10-006.

**9/13. Migrations.** 58/58 déclarent un `down()` ; **6 l'ont vide** (4 assumés par écrit).
`migrate:fresh` depuis zéro : ✅ (§1.4).

**10. Volumes.** Production (documentée, non touchée) : `companies` ≈ 4 295 349,
`contacts` ≈ 1 319 567, `candidates` = 0, `activities` = 649.
Mesuré localement : `axion_crm` 0 partout après reconstruction ; `axion_crm_perf`
companies 300 000 / contacts 50 000 / activities 500 000 ; `axion_crm_perf4m` companies 2 800 000.
Poids à 2,8 M : tas `companies` **624 Mo**, index **1 491 Mo** (2,4 ×).

**11. Rétention.** `retention:purge` (04:00) ne couvre que **3 objets** : `email_validations` (7 j),
`notifications` (90 j), `scraper_runs.response_payload` (90 j). S'y ajoutent
`retention:prune-scraper-runs --days=90`, `rgpd:anonymize-ips`, `rgpd:purge-vivier`,
`rgpd:purge-business-prospects`. **Environ 90 tables n'ont aucune purge**, dont `audit_logs`
(voir B10-003), `llm_usage`, `business_events`, `email_*`, `web_vital_samples`, `proxy_usage_log`,
`personal_access_tokens`.

**12. RGPD.** Export (`GdprPortabilityService`) : **4 tables**. Effacement (`GdprErasureService`) :
**8 tables**. Voir B10-004.

**14. Vivant ou mort.** Voir B10-012 et B10-013.

---

## 5. Constats

### [B10-001] Une base dont `pg_partman` vit dans `public` ne peut plus JAMAIS être reconstruite par `migrate:fresh`, et la migration qui corrige cela est inatteignable par ce chemin
- Sévérité      : S1 grave
- Domaine       : backend
- Référence     : main e8924b8 (`backend/` inchangé depuis 1145473c)
- Emplacement   : `backend/database/migrations/2026_08_18_100001_partman_dans_son_propre_schema.php` ; `Makefile:93-109`
- Constat       : sur `axion_crm` locale, `php artisan migrate:fresh` échoue **dès la première exécution** (`RC1=1`), pas seulement à la seconde comme l'annonce la documentation de la migration corrective.
- Preuve        : `04_PREUVES/agent-10/06-db-rebuild-check.txt` — `RC1=1`, `RC2=1`, `SQLSTATE[2BP01] … cannot drop table part_config because extension pg_partman requires it`, puis `select … pg_extension … → public` et `relation "partman.part_config" does not exist`.
- Témoin négatif: la MÊME recette rejouée après `php artisan migrate --force` rend `RC1=0`, `RC2=0`, `EXIT_GLOBAL=0`, et `part_config` gère `public.audit_logs` (`08-db-rebuild-check-APRES-relocalisation.txt`). La sonde sait donc distinguer les deux états.
- Impact        : quiconque hérite d'une base dans cet état (dev, préprod, CI à volume persistant) voit `migrate:fresh` **et toute la suite Pest sous `RefreshDatabase`** mourir avant le premier test, et le remède naturel (« je repars de zéro ») est précisément celui qui ne marche pas. `make db-rebuild-check` affiche « ÉCHEC » sans jamais nommer la sortie.
- Reproduction  : sur une base migrée avant le 2026-08-18 : `docker exec axion-crm-api php artisan migrate:fresh --force`.
- Correctif     : ajouter dans la cible `db-rebuild-check` un `php artisan migrate --force` **avant** la première reconstruction, et faire dire au message d'échec « joue `php artisan migrate --force` d'abord ». Coût : 3 lignes de `Makefile`. Optionnellement, un `DROP EXTENSION pg_partman CASCADE` de secours documenté.
- Statut        : ouvert

### [B10-002] Le rôle applicatif peut EFFACER le journal d'audit, qui n'est protégé par aucune RLS
- Sévérité      : S1 grave
- Domaine       : sécurité / conformité
- Référence     : main e8924b8
- Emplacement   : `backend/database/migrations/2026_08_14_000001_harden_workspace_isolation.php:56-66` (liste `EXCLUDED_TABLES`)
- Constat       : `audit_logs` et ses 14 partitions portent `workspace_id` mais **`relrowsecurity = false`, `relforcerowsecurity = false`, 0 policy**, et `axion_app` y détient `SELECT, INSERT, UPDATE, DELETE`.
- Preuve        : `01-rls-par-table.txt` (audit_logs : `f | f | 0`), `13-inventaire-apres-fresh-detail.txt` (les 14 partitions idem), `14-droits-et-rls-tables-chaudes.txt` (`audit_logs | DELETE`, `audit_logs | UPDATE`).
- Témoin négatif: la même requête rend `t | t | 1` pour les 55 autres tables scopées — la sonde sait voir une RLS posée.
- Impact        : (a) un compte du workspace A lit le journal d'audit du workspace B ; (b) le journal à chaînage de hachage, dont la migration `2026_08_16_000001` prend soin de ne « toucher AUCUNE ligne existante » parce que « toucher à un journal d'audit a posteriori serait exactement ce que ce journal existe pour empêcher », est **effaçable et modifiable par le rôle applicatif**. `audit:verify-chain` détecterait une rupture, pas une suppression de la fin de chaîne.
- Reproduction  : `docker exec axion-crm-postgres psql -U axion -d axion_crm -c "SELECT table_name, privilege_type FROM information_schema.role_table_grants WHERE grantee='axion_app' AND table_name LIKE 'audit_logs%';"`
- Correctif     : `REVOKE DELETE, UPDATE ON audit_logs, audit_logs_* FROM axion_app` ; poser `ENABLE`+`FORCE ROW LEVEL SECURITY` sur la table parente (PostgreSQL 16 propage aux partitions) avec la policy stricte standard, en réservant `DELETE` au rôle propriétaire pour la purge de rétention. Coût : une migration, ~40 lignes, plus un test dans `EtancheiteParTableTest` retirant `audit_logs` de `ABSENTES_PAR_CONSTRUCTION`.
- Statut        : ouvert

### [B10-003] Le partitionnement d'`audit_logs` n'est entretenu par personne : la rétention 24 mois ne s'appliquera jamais et les partitions s'arrêtent en février 2027
- Sévérité      : S1 grave
- Domaine       : conformité / backend
- Référence     : main e8924b8
- Emplacement   : `Dockerfile.postgres:49-51` ; `backend/routes/console.php` ; `backend/app/Console/Commands/RetentionPurge.php:10`
- Constat       : `pg_partman` est configuré (`retention = 24 months`, `premake = 6`) mais **aucun ordonnanceur n'appelle `partman.run_maintenance()`** : le worker de fond est désactivé au build (`NO_BGW=1`), `shared_preload_libraries` est vide, et la tâche Laravel censée le remplacer n'existe pas.
- Preuve        : `SHOW shared_preload_libraries;` → `(vide)`. `grep -rn "run_maintenance\|pg_partman_bgw" backend/app backend/routes infra .github` → **0 occurrence d'appel** (les 7 résultats sont des commentaires et des noms d'image). `Dockerfile.postgres:50-51` : « on n'utilise pas le maintenance worker (le cron de partition mgmt **sera** Laravel scheduler) ». `29-etat-final.txt` : `retention = 24 months`, 14 partitions.
- Témoin négatif: le même `grep` trouve bien 33 tâches dans `routes/console.php` (`Schedule::command(...)`), dont `retention:purge`, `rgpd:anonymize-ips`, `rgpd:purge-vivier` — il sait donc voir un ordonnancement quand il en existe un.
- Impact        : (a) la rétention de 24 mois du journal d'audit, annoncée en base et dans `RetentionPurge.php` (« pg_partman gère le detach »), **n'est appliquée par rien** — obligation de limitation de conservation (RGPD art. 5-1-e) non tenue ; (b) au-delà de la dernière partition pré-créée (2027-02), toutes les écritures d'audit tombent dans `audit_logs_default`, et le partitionnement cesse de rendre le moindre service.
- Reproduction  : `docker exec axion-crm-postgres psql -U axion -d axion_crm -c "SELECT relname FROM pg_class WHERE relname LIKE 'audit_logs_p2%' ORDER BY 1 DESC LIMIT 1;"` → `audit_logs_p20270201`.
- Correctif     : une commande `partitions:maintenir` appelant `SELECT partman.run_maintenance(p_analyze := false)`, ordonnancée quotidiennement, plus un test qui rougit si la dernière partition est à moins de 2 mois. Coût : ~60 lignes + 1 test.
- Statut        : ouvert

### [B10-004] L'export de portabilité RGPD couvre 4 tables et l'effacement 8, sur ~40 tables porteuses de données personnelles — `candidates` n'est dans ni l'un ni l'autre
- Sévérité      : S0 bloquant (non-conformité RGPD)
- Domaine       : conformité
- Référence     : main e8924b8
- Emplacement   : `backend/app/Services/Rgpd/GdprPortabilityService.php:24-27` ; `backend/app/Services/Rgpd/GdprErasureService.php:29-76`
- Constat       : l'export (art. 20) lit `contacts`, `email_validations`, `rgpd_requests`, `magic_links`. L'effacement (art. 17) touche ces quatre plus `notifications`, `journalists`, `media.email`, `health_practitioners`. **`candidates` — la table du vivier, qui porte nom, prénom, e-mail, téléphone, expériences et référence de CV — n'est atteinte par aucun des deux.**
- Preuve        : `grep -n "table(" app/Services/Rgpd/GdprPortabilityService.php` → 4 lignes ; `grep -n "table(\|delete\|update" app/Services/Rgpd/GdprErasureService.php` → 8 tables. Le modèle `Candidate` porte `email`, `phone`, `cv_ref`, `experiences`, `attributes` (`app/Models/Candidate.php:44-53`).
- Témoin négatif: le même `grep` trouve bien `health_practitioners` dans l'effacement (ligne 69) — il sait donc voir une table couverte.
- Impact        : une personne ayant candidaté et demandant l'effacement de ses données voit son `contacts` effacé (s'il existe) mais **sa fiche candidat subsiste**. Idem pour la portabilité. Sont également hors d'atteinte : `activities` (la chronologie nominative), `audience_members`, `email_sends`, `email_events`, `email_threads`, `email_verification_logs`, `business_events`, `crm_notes`, `crm_tasks`, `duplicate_flags`, `audit_logs`, `linkedin_*`. La purge programmée `rgpd:purge-vivier` traite l'ancienneté (2 ans), **pas la demande individuelle**.
- Reproduction  : lire les deux services ; comparer à la liste des tables portant une colonne `email`, `phone`, `first_name` ou `last_name` : `SELECT DISTINCT table_name FROM information_schema.columns WHERE table_schema='public' AND column_name IN ('email','phone','first_name','last_name','subject_email');`
- Correctif     : un registre unique des tables porteuses de données personnelles (une constante partagée par les deux services), plus un test qui rougit dès qu'une table du registre n'est visée par ni l'export ni l'effacement — même patron que `EtancheiteWorkspace`. Coût : ~1 j.
- Statut        : ouvert

### [B10-005] Le masquage `contacts.view_pii` protège 3 écrans et laisse passer les journalistes et les candidats en clair
- Sévérité      : S2 défaut
- Domaine       : conformité / interface
- Référence     : main e8924b8
- Emplacement   : `backend/app/Http/Controllers/Api/JournalistsController.php:38` ; `backend/app/Http/Controllers/Api/Crm/CandidatesController.php:197-224`
- Constat       : `MasquageCoordonnees` est appelé dans `CompaniesController`, `ContactsController` et `Crm/ContactsHubController`. `GET /journalists` rend `$page->items()`, c'est-à-dire le modèle `Journalist` sérialisé **sans `$hidden`** (`email`, `phone`, `socials`, `source_url`) ; `GET /crm/candidates` construit explicitement `email`, `phone`, `cv_ref`, `attributes` **sans tester le droit**.
- Preuve        : `grep -rn "MasquageCoordonnees" app/` → 3 contrôleurs, 12 lignes ; `app/Models/Journalist.php` et `app/Models/Candidate.php` n'ont aucun `$hidden` (`grep -n "protected \$hidden" app/Models/*.php` → **une seule occurrence, `User.php:57`**).
- Témoin négatif: le même `grep` trouve `ContactsHubController:276-277` où le masquage EST appliqué — il sait voir une surface protégée.
- Impact        : un compte `viewer` (sans `contacts.view_pii`) voit `p***@acme.fr` sur `/contacts` et **`pierre.durand@acme.fr` sur `/journalists`**. La garde §2.10 (« un viewer ne repart pas avec les adresses ») est contournable en changeant d'écran. Le vivier expose en plus la référence de CV.
- Reproduction  : ouvrir `/console/journalistes` avec un compte `viewer`, comparer à `/console/contacts`.
- Correctif     : soit poser `$hidden` sur `Journalist` et `Candidate` et exposer via une ressource explicite, soit brancher `MasquageCoordonnees::requis()` dans les deux contrôleurs. Coût : ~2 h + 2 tests.
- Statut        : ouvert

### [B10-006] La ceinture applicative d'étanchéité est posée sur 4 modèles sur les 15 concernés, et rien ne l'exige
- Sévérité      : S2 défaut
- Domaine       : backend / sécurité
- Référence     : main e8924b8
- Emplacement   : `backend/app/Models/Concerns/BelongsToWorkspace.php` ; `backend/app/Models/Scopes/WorkspaceScope.php`
- Constat       : 15 des 18 modèles reposent sur une table portant `workspace_id`. **4 seulement** (`Company`, `Contact`, `Candidate`, `Tag`) utilisent `BelongsToWorkspace`. Les 11 autres (`AudienceMember`, `AuditLog`, `EmailAudience`, `HealthPractitioner`, `Journalist`, `LlmUseCase`, `Media`, `ProxyProvider`, `RgpdRequest`, `ScraperRun`, `ScrapingCampaign`) n'ont ni portée globale ni remplissage automatique. Aucun test ne vérifie cette couverture.
- Preuve        : `28-modeles-workspace-id.txt` (15 tables sur 18 portent `workspace_id`) ; `grep -l "use BelongsToWorkspace" app/Models/*.php` → 4 fichiers ; `grep -rn "BelongsToWorkspace" tests/` → **0**.
- Témoin négatif: le même `grep` sur `app/` trouve bien les 5 fichiers (4 modèles + le trait) — il sait trouver.
- Impact        : aujourd'hui l'effet est nul (`crm.strict_workspace_scope` = false, mesuré : la variable est absente de l'environnement du conteneur). **Le jour où le drapeau passe à true**, 4 modèles gagnent le filtre et l'échec bruyant, 11 non — dont `HealthPractitioner` (données de santé, art. 9) et `AuditLog`. L'inégalité de traitement sera invisible : rien ne rougira.
- Reproduction  : `grep -L "use BelongsToWorkspace" app/Models/*.php` puis croiser avec `28-modeles-workspace-id.txt`.
- Correctif     : un test qui énumère les modèles d'`app/Models`, résout leur table, et exige le trait dès que `workspace_id` existe — avec une liste d'exceptions **motivées par écrit**, sur le patron de `EtancheiteWorkspace::EXCLUSIONS_MOTIVEES`. Coût : ~2 h.
- Statut        : ouvert

### [B10-007] 15 tables portent `workspace_id` sans clé étrangère : la suppression d'un workspace y laisse des orphelins invisibles
- Sévérité      : S2 défaut
- Domaine       : backend
- Référence     : main e8924b8
- Emplacement   : base — `activities`, `analytics_attribution`, `analytics_cohorts`, `analytics_daily_rollups`, `analytics_funnels`, `analytics_kpis`, `audience_members`, `company_tag`, `crm_notes`, `crm_tasks`, `email_threads`, `linkedin_invitations`, `linkedin_messages`, `linkedin_profiles_cache`, `proxy_usage_log`, `unsubscribes`, `audit_logs*`
- Constat       : 45 tables ont une FK `workspace_id → workspaces(id)` (44 en CASCADE, `sessions` en SET NULL). Les tables ci-dessus portent la colonne **sans aucune contrainte référentielle**.
- Preuve        : `19-contraintes.txt` (requête « colonnes `*_id` sans FK ») et `20-fk-on-delete.txt` (45 FK vers `workspaces`).
- Témoin négatif: la même requête ne liste PAS `companies`, `contacts`, `candidates`, `tags` — qui ont bien leur FK. Elle sait donc distinguer.
- Impact        : la suppression d'un workspace efface en cascade 44 tables et laisse des lignes à `workspace_id` pendant dans les 15 autres — dont `activities`, la chronologie nominative de l'étape 1a, et `audience_members`. Sous policy stricte, ces lignes deviennent **invisibles à tout le monde tout en existant** : ni lisibles, ni effaçables par l'application, ni comptées par un contrôle RGPD. La migration `2026_08_14_000001` refuse justement de durcir une table contenant des lignes à `workspace_id` NULL, mais rien n'empêche d'en fabriquer après coup.
- Reproduction  : la requête de `19-contraintes.txt`.
- Correctif     : ajouter les FK manquantes (`ON DELETE CASCADE`) après vérification qu'aucune ligne orpheline n'existe ; pour `audit_logs`, préférer un `ON DELETE SET NULL` ou une conservation explicite, un journal d'audit ne devant pas disparaître avec son workspace. Coût : une migration + un contrôle préalable, ~4 h.
- Statut        : ouvert

### [B10-008] `email_verification_logs` fuit sans contexte : une policy permissive survit au durcissement parce que son nom est raccourci
- Sévérité      : S2 défaut
- Domaine       : sécurité
- Référence     : main e8924b8
- Emplacement   : `backend/database/migrations/2026_05_19_000001_create_email_verification_logs.php` (policy `email_verif_workspace_isolation`) ; `2026_08_14_000001_harden_workspace_isolation.php:96` (`DROP POLICY IF EXISTS %s_workspace_isolation`)
- Constat       : la table porte **deux** policies. Le durcissement ne supprime que celle au nom canonique `email_verification_logs_workspace_isolation` ; l'ancienne, nommée `email_verif_workspace_isolation`, garde un repli `COALESCE(NULLIF(current_setting(...), ''), workspace_id::text)` — sans contexte, le prédicat est toujours vrai.
- Preuve        : `01-rls-par-table.txt` — `email_verification_logs | 1 | t | t | 2` (**2 policies**, la seule table du dépôt dans ce cas). Test `DÉFAUT CONNU — email_verification_logs fuit SANS contexte` : **vert**, c'est-à-dire que la fuite est toujours là (`12-test-etancheite.txt`).
- Témoin négatif: le test lui-même en porte un : le détecteur `COALESCE` doit trouver cette policy, sinon il est déclaré aveugle. Il la trouve.
- Impact        : sous rôle applicatif, une lecture de `email_verification_logs` **sans contexte workspace** rend toutes les lignes, tous workspaces confondus. La table journalise les vérifications d'adresses : elle porte des e-mails.
- Reproduction  : `SELECT policyname, qual FROM pg_policies WHERE tablename='email_verification_logs';`
- Correctif     : `DROP POLICY email_verif_workspace_isolation` dans une migration, puis retirer la table de `EtancheiteWorkspace::DEFAUTS_CONNUS` et supprimer le test d'épinglage — le test l'exige explicitement. Coût : 30 min. **Le dépôt a déjà tout préparé pour ce geste ; il ne manque que le geste.**
- Statut        : ouvert

### [B10-009] `permissions` porte `UNIQUE(name)` seul là où le code suppose `(name, guard_name)` — et deux sources de vérité écrivent la même permission
- Sévérité      : S2 défaut
- Domaine       : backend / tests
- Référence     : main e8924b8
- Emplacement   : base, contrainte `permissions_name_key` ; `backend/database/seeders/PermissionsAndRolesSeeder.php:39` ; `backend/database/migrations/2026_08_15_000005_permission_contacts_view_pii.php:30`
- Constat       : la table `permissions` porte `UNIQUE CONSTRAINT permissions_name_key btree (name)` — sur `name` **seul**. Les deux écrivains font `updateOrInsert(['name' => …, 'guard_name' => 'web'], …)`, c'est-à-dire une recherche sur **deux** colonnes suivie d'un INSERT que la contrainte à une colonne peut refuser.
- Preuve        : `18-permissions-contraintes.txt` (`permissions_name_key UNIQUE, btree (name)`). Échec rejoué : `12-test-etancheite.txt` — `SQLSTATE[23505]: Unique violation … permissions_name_key … Key (name)=(contacts.view_pii) already exists`, pile `PermissionsAndRolesSeeder.php:39` ← `EtancheiteUniversTest.php:37`. Second échec du même fichier : `SQLSTATE[42P01] relation "permissions" does not exist`.
- Témoin négatif: les 11 tests d'`EtancheiteParTableTest`, dans la même exécution, sont **tous verts** — la suite n'est pas globalement cassée, ce sont bien ces deux tests-là qui rougissent.
- Impact        : (a) **`EtancheiteUniversTest` est rouge sur `main`** — 2 échecs sur 22 tests ; (b) le jour où une permission existera sous un second `guard_name` (`api`, `sanctum`), tout `updateOrInsert` la concernant lèvera une violation d'unicité en production, pendant un `migrate --force` de déploiement, qui est **bloquant** (`deploy-direct-ssh.yml:204`) ; (c) `contacts.view_pii` a deux sources de vérité qui doivent rester d'accord à la main.
- Reproduction  : `./vendor/bin/pest --filter=EtancheiteUnivers`
- Correctif     : remplacer `permissions_name_key` par `UNIQUE (name, guard_name)` — c'est le contrat du paquet Spatie — et faire du seeder l'unique source, la migration se contentant de l'appeler. Coût : une migration + ~1 h. ⚠️ La cause exacte des deux échecs (ordre aléatoire, pollution inter-tests) reste à trancher avec l'agent du harnais de tests ; le défaut de contrainte, lui, est mesuré et indépendant.
- Statut        : ouvert

### [B10-010] Le correctif qui a rendu la sauvegarde de production restaurable n'est protégé par aucune garde : la prochaine fonction rouvrira le trou
- Sévérité      : S2 défaut
- Domaine       : backend / conformité
- Référence     : main e8924b8
- Emplacement   : `backend/database/migrations/2026_08_16_200000_fixer_search_path_des_fonctions.php:48-58` (liste `FONCTIONS`, 7 signatures en dur)
- Constat       : la migration fixe `search_path = public, pg_catalog` sur **une liste écrite en dur de 7 fonctions**. Aucun test ne vérifie que les fonctions du projet portent un `search_path`. Le trou est **fermé aujourd'hui** — mais rien ne le maintient fermé.
- Preuve        : `15-fonctions-search-path.txt` — les 7 fonctions non issues d'une extension portent toutes `{"search_path=public, pg_catalog"}`, aucune ligne à `proconfig` NULL. `grep -rn "search_path\|proconfig" backend/tests/` → 3 occurrences, **toutes dans des commentaires** de `ReconstructionBaseTest.php`, **aucune assertion**.
- Témoin négatif: le même `grep` trouve bien ces 3 occurrences — il sait voir le mot. La requête `pg_proc`, elle, listerait immédiatement une fonction à `proconfig` NULL (elle trie `NULLS FIRST` exprès).
- Impact        : la conséquence mesurée le 2026-08-16 était qu'**une restauration du dump de production rendait une base sans `companies` ni `contacts`** — 5,6 M de lignes. Une prochaine migration créant une fonction SQL qui appelle un objet non qualifié (`unaccent`, une fonction PostGIS, une table) reproduira exactement ce défaut, en silence, jusqu'au prochain exercice de restauration.
- Reproduction  : la requête `pg_proc` de `15-fonctions-search-path.txt`.
- Correctif     : un test dans `tests/Feature/Database/` : « toute fonction `sql`/`plpgsql` du schéma `public` n'appartenant pas à une extension porte un `search_path` fixé », avec témoin négatif (créer une fonction sans, vérifier que la sonde rougit, la supprimer). Coût : ~1 h.
- Statut        : ouvert

### [B10-011] `ScrapingSourcesSeeder` fait un `upsert` depuis DEUX migrations et réécrit six colonnes du référentiel à chaque déploiement
- Sévérité      : S2 défaut
- Domaine       : backend
- Référence     : main e8924b8
- Emplacement   : `backend/database/seeders/ScrapingSourcesSeeder.php:155-168` ; appelé par `2026_08_14_000006_crm_scraping_sources.php:57` **et** `2026_08_15_100001_seed_implantations_fr_etranger_source.php:22`
- Constat       : le seeder appelle `DB::table('scraping_sources')->upsert([...], ['slug'], ['name','kind','ttl_days','legal_note','dedup_key_pattern','quota_per_day','updated_at'])`. `enabled` est **correctement** exclu — le kill-switch survit, et c'est écrit. **Les six autres colonnes sont écrasées à chaque exécution.**
- Preuve        : `grep -E "Seeder|upsert|insertOrIgnore" backend/database/migrations/*.php` → 3 seeders appelés depuis une migration ; lecture de `ScrapingSourcesSeeder.php:155-168`. La table contient 17 lignes en local (`03-volumes-local.txt`).
- Témoin négatif: le troisième seeder appelé depuis une migration, `ActivitesEtMotifsSeeder`, fait `insertOrIgnore` et documente explicitement pourquoi (« `upsert` remet `label`, `ordre` et `actif` à leur valeur d'usine à chaque exécution… la promesse "extensible depuis la console" serait fausse une fois par déploiement, en silence »). La distinction est donc connue du dépôt, et appliquée à un endroit sur deux.
- Impact        : si `ttl_days` ou `quota_per_day` devient réglable depuis la console — `quota_per_day` est précisément le genre de valeur qu'un exploitant ajuste —, **le réglage est perdu au déploiement suivant**, sans trace. La permission `scraping.config` existe déjà dans le référentiel des droits ; l'écran, lui, n'existe pas encore. Le défaut est **armé, pas encore déclenché**.
- Reproduction  : modifier `scraping_sources.ttl_days` pour `insee`, rejouer `(new ScrapingSourcesSeeder)->run()`, relire la valeur.
- Correctif     : passer `ttl_days`, `quota_per_day` et `name` hors de la liste des colonnes mises à jour (comme `enabled`), ou basculer le seeder en `insertOrIgnore` avec une commande dédiée pour les mises à jour de référentiel voulues. Coût : 15 min + 1 test de gouvernance sur le patron de `GovernedTagsSeeder`.
- Statut        : ouvert

### [B10-012] 42 tables sur 102 ne sont nommées par aucune ligne de code applicatif ni par le frontend — les « six tables mortes » en sous-comptent sept fois
- Sévérité      : S3 finition
- Domaine       : backend
- Référence     : main e8924b8
- Emplacement   : base + `backend/app/`, `backend/routes/`, `backend/config/`, `frontend/src/`
- Constat       : un balayage table par table sur `app/ routes/ config/` et `frontend/src` rend **42 tables à zéro occurrence**. Six d'entre elles n'apparaissent **nulle part** dans le dépôt (`analytics_funnel_snapshots`, `deal_history`, `email_messages`, `email_sequences`, `linkedin_health_checks`, `linkedin_sequence_runs`) ; les 36 autres n'existent que dans les seeders et le semeur du test d'étanchéité.
- Preuve        : `16-usage-tables.txt` (app / routes / frontend) et `17-usage-tables-large.txt` (app+routes+config / seeders / tests / vendor / frontend). Vérification manuelle : `grep -rE "class (EmailTemplate|EmailSequence|Deal|Pipeline|PipelineStage|CrmTask|CrmNote|LinkedinAccount|AnalyticsKpi|DncList|DuplicateFlag|WebVitalSample|AiActRegister)\b" app/` → **0 pour les 13**.
- Témoin négatif: le même balayage rend 23 fichiers `app/` et 25 fichiers frontend pour `companies`, 21/19 pour `contacts`, 20/9 pour `media` — il sait trouver.
- Impact        : ces tables sont durcies (RLS + FORCE + policy), semées par le test d'étanchéité, indexées, sauvegardées et restaurées à chaque exercice. Elles coûtent du temps de reconstruction, de la surface d'audit et de la charge cognitive, sans rendre de service. Le décompte de « six tables mortes » du §3 bis fait croire à un ménage de faible ampleur.
- Reproduction  : rejouer la boucle de `17-usage-tables-large.txt`.
- Correctif     : classer les 42 en trois familles — **à supprimer**, **squelette assumé** (Phase 2, à dater), **référentiel** — et écrire la décision dans une constante partagée que le test d'étanchéité lit, comme `EtancheiteWorkspace::EXCLUSIONS_MOTIVEES`. Coût : ~1 j de décision, la suppression vient après.
- Statut        : ouvert

### [B10-013] Deux autres routes mentent comme `/saved-views` : `/ai-act/register` et la recherche globale rendent 200 avec une liste vide, sans jamais toucher leur table
- Sévérité      : S2 défaut
- Domaine       : conformité / navigation
- Référence     : main e8924b8
- Emplacement   : `backend/app/Http/Controllers/Api/AiActRegisterController.php:15` ; `backend/app/Http/Controllers/Api/GlobalSearchController.php:18` ; `backend/app/Http/Controllers/Api/SavedViewsController.php:15`
- Constat       : approfondissement de **A-002**. `SavedViewsController` **ne référence jamais la table `saved_views`** — aucun modèle, aucun `DB::table('saved_views')` dans tout `app/` (la seule occurrence du nom est une liste de contrôle dans `PentestSelfCheck.php:80`). La table existe pourtant, avec RLS, FORCE, policy stricte et FK CASCADE. **Le même patron se répète deux fois** : `AiActRegisterController::index()` rend `ok(['data' => []])` alors que `ai_act_register` existe et n'est lue par personne, et `GlobalSearchController` rend `ok(['companies'=>[], 'contacts'=>[], 'tags'=>[]])`.
- Preuve        : `grep -rn "saved_views" app/ routes/ frontend/src` → 1 seule ligne, dans `PentestSelfCheck.php` ; lecture intégrale des trois contrôleurs ; `30-grille-tables.txt` (`saved_views | ws | rls | force | 1 | 1 | 0 | 0 | 2 | dur`).
- Témoin négatif: le même `grep` trouve 23 fichiers pour `companies` — il sait voir une table réellement utilisée. Et `ObservabilityController` montre le contraste : il fait 10 `DB::table(...)` réels.
- Impact        : trois surfaces où « la fonction n'existe pas » est indistinguable de « tu n'as rien ». Pour `/ai-act/register` c'est plus grave que pour `/saved-views` : la route est étiquetée `tags={"RGPD"}` et documente un **registre réglementaire AI Act art. 9-15**. Un contrôle qui l'interroge lit « registre vide », c'est-à-dire une déclaration de conformité, là où la vérité est « registre non implémenté ».
- Reproduction  : `GET /api/v1/ai-act/register` → `200 {"data":[]}` ; comparer à `POST` sur la même route → `501`.
- Correctif     : rendre `501` sur les trois `index()` tant que l'implémentation n'existe pas (les `store`/`show`/`update`/`destroy` le font déjà), ou brancher réellement la lecture. Coût : 20 min pour les 501 ; le branchement dépend du chantier.
- Statut        : ouvert

### [B10-014] `companies` porte 1,5 Go d'index pour 624 Mo de données, dont vingt jamais parcourus
- Sévérité      : S3 finition
- Domaine       : performance
- Référence     : main e8924b8
- Emplacement   : base — table `companies`
- Constat       : sur `axion_crm_perf4m` (2 800 000 fiches, 57 migrations sur 58), `companies` porte **27 index pesant 1 491 Mo pour un tas de 624 Mo** (2,4 ×). Les trois plus lourds sont `idx_companies_denom_btree` (276 Mo), `idx_companies_ws_stage_updated_id` (221 Mo) et `idx_companies_ws_updated_id` (179 Mo). Vingt affichent `idx_scan = 0`.
- Preuve        : `22-perf4m-index.txt`, `23-explain-index.txt` (tailles + `idx_scan`), `24-explain-chaud.txt` (`tas 624 MB | index_total 1491 MB`).
- Témoin négatif: deux index affichent un compteur non nul dans la même sortie (`idx_companies_ws_counts` : 3 ; `companies_website_status_index` : 6) — les compteurs ne sont donc pas globalement à zéro, la sonde fonctionne. ⚠️ Réserve honnête : `axion_crm_perf4m` est une base de mesure, pas la production ; `idx_scan = 0` y signifie « jamais parcouru **dans cette base** », pas « inutile en production ».
- Impact        : à l'échelle de production (4 295 349 fiches, soit 1,53 ×), l'ordre de grandeur attendu est **~2,3 Go d'index** — coût d'écriture sur chaque `INSERT`/`UPDATE` de la collecte, de sauvegarde, de restauration et de reconstruction.
- Reproduction  : `SELECT indexrelname, pg_size_pretty(pg_relation_size(indexrelid)), idx_scan FROM pg_stat_user_indexes WHERE relname='companies' ORDER BY 2 DESC;`
- Correctif     : mesurer `idx_scan` **en production** sur une fenêtre d'un mois (lecture seule, `pg_stat_user_indexes`), puis retirer les index confirmés inutiles par `DROP INDEX CONCURRENTLY`. Coût : 1 h de mesure + une migration.
- Statut        : ouvert

### [B10-015] La garde `db-rebuild-check` n'est jouée par aucun job de CI, et `make` n'existe pas sur le poste
- Sévérité      : S2 défaut
- Domaine       : tests
- Référence     : main e8924b8
- Emplacement   : `Makefile:93-109` ; `.github/workflows/`
- Constat       : la seule garde qui exige un double `migrate:fresh` vit dans le `Makefile`. **Aucun des 17 workflows ne l'appelle**, et `make` n'est pas installé sur le poste de développement.
- Preuve        : `grep -rn "db-rebuild-check\|migrate:fresh\|make " .github/workflows/` → **0 résultat** ; `which make` / `where.exe make` → introuvable ; `make db-rebuild-check` → `command not found`, `EXIT=127` (`06-db-rebuild-check.txt`).
- Témoin négatif: le même `grep` sur `artisan migrate` trouve **6 lignes** dans `deploy-direct-ssh.yml` et `deploy-staging.yml` — il sait voir une commande de migration dans un workflow.
- Impact        : la reconstructibilité de la base, défaut qui a coûté un incident le 2026-08-18, **n'est vérifiée par rien d'automatique**. La CI exécute bien `RefreshDatabase` sur une `axion_crm_test` recréée à chaque exécution, ce qui masque exactement le cas que la garde existe pour attraper (une base **persistante** déjà migrée).
- Reproduction  : `grep -rn "db-rebuild-check" .github/`
- Correctif     : un job de CI planifié (hebdomadaire) qui migre une base, puis exige deux `migrate:fresh` de suite — c'est-à-dire la cible existante, jouée sur une base **non neuve**. Coût : ~2 h. Alternative immédiate : un script `infra/scripts/db-rebuild-check.sh` appelé par la cible `make`, exécutable sans `make`.
- Statut        : ouvert

### [B10-016] `companies`, `contacts`, `users` et `workspaces` portent une colonne `deleted_at` que leur modèle ignore : le code lit en soft-delete, Eloquent écrit en dur
- Sévérité      : S1 grave
- Domaine       : backend
- Référence     : main e8924b8
- Emplacement   : `backend/app/Models/Company.php:58-60`, `Contact.php:41-43`, `User.php:38-40`, `Workspace.php:16-18`
- Constat       : **11 tables portent `deleted_at` en base ; seuls 6 modèles sur 18 déclarent `SoftDeletes`.** L'écart porte sur `companies`, `contacts`, `users`, `workspaces` (et `campaigns`, qui n'a pas de modèle). Pendant ce temps, **44 requêtes de `app/` filtrent explicitement `whereNull('deleted_at')`**, dont les trois écrans de liste — le code lit donc comme si le soft-delete était actif.
- Preuve        : `04_PREUVES/agent-10/32-softdelete-divergence.txt` — les 11 tables à `deleted_at`, les 6 modèles à `SoftDeletes` (`grep -ln "SoftDeletes;" app/Models/*.php`), les 44 filtres, et les trois contrôleurs nommés (`CompaniesController:135`, `ContactsController:50`, `ContactsHubController:114`). `grep -n SoftDeletes app/Models/{Company,Contact,User,Workspace}.php` → **rc=1, aucune occurrence**.
- Témoin négatif: le même `grep -ln` trouve bien les 6 modèles qui l'ont — il sait voir le trait. Et la même requête `information_schema` ne liste PAS `tags`, `activities`, `deals` : elle sait distinguer les tables sans `deleted_at`.
- Impact        : `Company::find($id)->delete()` émet un **`DELETE` physique**, pas un `UPDATE deleted_at`. La ligne disparaît, et 8 tables en CASCADE avec elle (`contacts`, `company_tag`, `audience_members`, `scraper_runs`, `deals`…). Sur `companies` (4 295 349 lignes en production) et `contacts` (1 319 567), l'archivage que le reste du code suppose réversible ne l'est pas. Symétriquement, la colonne `deleted_at` n'étant remplie par aucun `SoftDeletes`, les 44 filtres `whereNull('deleted_at')` sont aujourd'hui **toujours vrais** : ils coûtent sans rien filtrer, et masqueront un archivage réel le jour où quelqu'un remplira la colonne à la main.
- Reproduction  : `SELECT table_name FROM information_schema.columns WHERE table_schema='public' AND column_name='deleted_at';` puis `grep -ln "SoftDeletes;" backend/app/Models/*.php` — comparer les deux listes.
- Correctif     : trancher table par table. Soit poser `SoftDeletes` sur `Company`, `Contact`, `User`, `Workspace` (et vérifier que les 44 filtres explicites deviennent redondants, donc à retirer), soit retirer les colonnes `deleted_at` inutilisées et assumer la suppression dure. **Le choix n'est pas neutre pour le RGPD** : `contacts` est la seule table atteinte par l'effacement (B10-004), et un soft-delete y transformerait un effacement en simple masquage. Coût : ~1 j de décision + migration + tests.
- Statut        : ouvert

---

## 6. Ce que je n'ai PAS pu vérifier, et pourquoi

1. **La RLS en production.** `CRM_DB_APP_ROLE_ENABLED=true` y est mesuré par l'agent 08
   (`04_PREUVES/agent-08/04_prod-env-isolation-tz.txt`), ce qui contredit le commentaire de
   `EtancheiteParTableTest.php:31` (« sa valeur en production » = false). **Je n'ai pas rejoué cette
   mesure moi-même** (interdiction d'écrire en production, et je n'ai pas voulu ouvrir de session
   psql distante). À trancher : le commentaire du test est-il périmé, ou l'environnement du
   conteneur diverge-t-il du `.env` ? Une mesure, pas un avis.
2. **`CRM_STRICT_WORKSPACE_SCOPE` en production.** Absente du relevé de l'agent 08 — donc
   probablement à sa valeur par défaut (`false`), mais **l'absence d'une variable dans un relevé
   n'est pas une preuve de son absence**. Non vérifié.
3. **Le comportement réel des écrans sous un compte `viewer`.** B10-005 repose sur la lecture du
   code et sur `grep`, pas sur un appel HTTP authentifié. Le constat A-001 (500 au lieu de 401)
   rend l'appel non authentifié inexploitable, et je n'ai pas créé de session applicative.
4. **Les compteurs `idx_scan` de production.** Réserve écrite dans B10-014 : mes chiffres viennent
   d'une base de mesure. La conclusion « index inutile » exige la production.
5. **La cause exacte des deux échecs d'`EtancheiteUniversTest`.** Le défaut de contrainte
   (B10-009) est mesuré et indépendant ; le mécanisme précis de l'échec (ordre aléatoire de Pest,
   pollution entre fichiers, `relation "permissions" does not exist` en pleine exécution) relève du
   harnais de tests — à croiser avec l'agent 44.
6. **`coverage_matrix_cells`.** Vue matérialisée, écartée du scan d'étanchéité par construction et
   avec un motif écrit. Je n'ai pas vérifié que son rafraîchissement respecte l'isolation : elle
   hérite des droits du propriétaire, qui est superutilisateur.
7. **Le contenu réel des 42 tables « mortes » en production.** Elles sont vides en local. Si l'une
   d'elles porte des lignes en production, la classer « à supprimer » serait une perte de données —
   d'où la proposition de classement en trois familles plutôt qu'une suppression directe.
8. **La restaurabilité effective de la sauvegarde après B10-010.** J'ai vérifié que les 7 fonctions
   portent leur `search_path` ; **je n'ai pas rejoué un exercice de restauration complet** (dump +
   restore d'une base à 4,3 M de lignes), qui dépasse le cadre et la durée de mon passage.
9. **`pg_partman` en production.** B10-003 est mesuré en local et sur le `Dockerfile.postgres`
   commun. Je n'ai pas relevé `shared_preload_libraries` sur le serveur de production.
