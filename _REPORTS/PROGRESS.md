# Axion CRM Pro — Sprint 1 → S12 Progress

> Autopilot run started 2026-05-16. Mode `MOCK_MODE=true` par défaut.
> Référence : `_PROMPTS/PROMPT_AUTOPILOT_SPRINT_1_TO_12.md`.

## Tableau de bord global

| Sprint | Status | Commits | Done date | Notes |
|--------|--------|---------|-----------|-------|
| S1 — Bootstrap | ✅ done | `803d13a` + `f3b914e` + `3bd9977` | 2026-05-16 | Infra + backend skeleton + frontend + workers + CI + tests Pest/Vitest |
| S2 — DB migrations + RLS + seeders | 🟡 step A done (core schema) | (en cours) | — | 8 migrations Phase 1 + Phase 2 scaffold + RLS + 10 seeders fondateurs |
| S3 — Auth + RBAC + Multi-tenant + Audit | ⏳ pending | — | — | Controllers stubs déjà posés ; logique métier 2FA/Magic Link à compléter |
| S4 — LLM Router + Proxies + Dedup + Rotations | ⏳ pending | — | — | Mocks fonctionnels ; LLM Router réel + UI 4 tabs |
| S5 — Sources officielles + Waterfall | ⏳ pending | — | — | Mocks fixtures ; HTTP clients INSEE/Annuaire/BODACC réels |
| S6 — Workers Playwright Google Maps / PJ / sites web | ⏳ pending | — | — | Worker base + Mock GMaps fonctionnels ; Playwright réels à coder |
| S7 — Google Search Wrapper + Direction Finder | ⏳ pending | — | — | Stubs Mock posés |
| S8 — Email finder + Validation SMTP cascade | ⏳ pending | — | — | MockSmtpProber + DTO posés ; 18 patterns + cascade N1-N5 |
| S9 — Carte France interactive | ⏳ pending | — | — | Page stub posée ; IGN AdminExpress + tippecanoe + MapLibre |
| S10 — Classification LLM + UI 17 pages | ⏳ pending | — | — | 17 pages stubs déjà posées ; logique métier + composants UI |
| S11 — Scaffold Phase 2 + RGPD UI + Monitoring | ⏳ pending | — | — | 5 stubs Phase 2 + tables Phase 2 déjà créées ; observability stack |
| S12 — E2E + Doc + Polish + Tag | ⏳ pending | — | — | Pest/Vitest configs prêts ; 50+ scénarios Playwright à écrire |

## Sprint 1 — Done (commits `803d13a`, `f3b914e`, `3bd9977`)

**Livré :**
- `docker-compose.yml` + `docker-compose.prod.yml` (Postgres 16 + Redis 7 + Caddy + api + horizon + scheduler + app + 3 workers)
- `Dockerfile.laravel` + `Dockerfile.frontend` + `Dockerfile.worker` (multi-stage prod-ready)
- `.env.example` exhaustif avec `MOCK_MODE=true` par défaut + sous-flags granulaires
- Backend Laravel 12 / PHP 8.3 : skeleton complet (`bootstrap/app.php`, `public/index.php`, `artisan`, routes web/api/console/channels)
- 14 contracts (interfaces DI) : LLMClient, ProxyProvider, CaptchaSolver, SmtpProber, 5 clients sources, 5 scrapers, DirectionFinder
- 10 DTOs Spatie Data : LLM, Proxies, Email, Sources × 5, Scraping × 4
- 3 middlewares fonctionnels : SetCurrentWorkspace (RLS session var), EnforceFirstLoginSetup, AuditHashChainLogger
- `AuditHashChain` service complet (sha256 prev || row || secret, verifyChain())
- 5 providers (App/Auth/Route/Horizon/Telescope) + `MockServicesProvider` (DI bindings mock vs réel par env var)
- 14 services Mock fonctionnels (LLM, Proxy, Captcha, SMTP, INSEE, AnnuaireEntreprises, BODACC, BAN, FranceTravail, GMaps, PJ, Website, Search, DirectionFinder)
- 14 services Real (throw `LogicException` proprement → implémentation Sprint 4-7)
- 10 models Eloquent (User HasApiTokens+HasRoles, Workspace, Company, Contact, ScraperRun, Tag, RgpdRequest, AuditLog, LlmUseCase, ProxyProvider)
- 10 policies (`BasePolicy` workspace-scoped + 4 rôles ; overrides AuditLogPolicy owner-only)
- 27 controllers API (Auth × 4, Companies, Contacts, Coverage, ScraperRuns, LLM × 2, Proxies, Rotations, Audit, RGPD, AI Act, Tags, Workspace, Users, Notifications, SavedViews, GlobalSearch, Phase2 × 5, Internal ScraperResult HMAC sha256)
- Configs Laravel minimum : app, auth, sanctum, database (Postgres + Redis 3 DBs)
- Frontend React 19 / Vite 6 / Tailwind 4 : `package.json`, tsconfig strict, vite.config, vitest.config, eslint flat
- 4 pages auth fonctionnelles (Login, 2FA, MagicLink, PasswordReset) + 15 pages Sprint 1-12 stubs + 5 Phase 2 stubs amber
- TanStack Router (20 routes) + TanStack Query + i18next FR/EN + axios Sanctum + Sonner toasts
- `PageShell` shared component + `RootLayout` sidebar + design tokens OKLCH
- Workers Node 22 + Playwright 1.49 + BullMQ : `package.json`, tsconfig, main.ts router 11 types
- `MockGoogleMapsScraper` fonctionnel (lit fixtures HTML) + `extract.ts` (emails/phones FR) + 1 test Vitest vert
- 10 worker stubs (PJ/web/google-search/direction-finder/france-travail/mesri/crunchbase/infogreffe/societe-com/social-light)
- Bridge Redis convention (BullMQ ↔ Horizon) + result-sender HMAC sha256
- GH Actions CI (`.github/workflows/ci.yml`) : 4 jobs backend Pest/PHPStan/Pint + frontend typecheck/lint/vitest/build + workers + security audit + gitleaks
- `infra/postgres/init/01-extensions.sql` (pg_trgm, postgis, pgvector, citext, unaccent, btree_*, pgcrypto)
- `infra/caddy/Caddyfile` (api.localhost + app.localhost) + `infra/nginx/frontend.conf` (SPA + security headers)
- `phpunit.xml` + Pest config + `TestCase` + `SmokeTest` (2 tests verts sur MockLLMClient + LLMResponseData)
- `phpstan.neon` level 8 + `pint.json` preset Laravel personnalisé

**Décisions appliquées :**
- Mocks par défaut pour tous les services externes (cf. `MOCKS-STRATEGY.md`)
- Real classes Sprint 4-7 lèvent `LogicException` propre → fail-fast clair en MOCK_MODE=false avant implémentation
- Controllers Sprint 5-12 retournent `501` typé (`{error,sprint,message}`) plutôt qu'un stub silent
- Phase 2 (campaigns/cold-email/linkedin/crm/analytics) → 5 `__invoke` retournent `501 Phase 2`
- Postgres ports remappés en local : `55432` (vs 5432 défaut) pour éviter conflit Windows
- RLS PostgreSQL activée sur 30 tables workspace-scoped (Sprint 2 step A)

## Sprint 2 — Step A en cours

**Migrations livrées (8 fichiers Laravel) :**
1. `000001_create_extensions_and_helpers.php` — extensions + `normalize_name()` + `compute_size_category()` + `recompute_company_quality_score()`
2. `000002_create_auth_tenant_audit_schema.php` — workspaces, users, user_workspaces, Spatie tables (roles/permissions/model_has_*/role_has_permissions), invitations, magic_links, password_reset_tokens, sessions, personal_access_tokens, audit_logs
3. `000003_create_companies_contacts_scraping_schema.php` — companies (denomination_normalized GENERATED, quality_badge GENERATED) + contacts (normalized_hash GENERATED dedup) + scraper_runs (dedup_key + uniq) + tags + company_tag
4. `000004_create_llm_proxies_rotations_schema.php` — llm_use_cases + prompt_templates/_versions + llm_usage + proxy_providers_config + proxy_usage_log + rotations + user_agents + search_engines
5. `000005_create_referentials_geo_naf_schema.php` — countries, regions, departments, cities (PostGIS) + NAF 5 niveaux + legal_forms + effectif_ranges + axion_offer_targets + strategic_keywords + opt_out
6. `000006_create_coverage_rgpd_aiact_schema.php` — coverage_zones + materialized view `coverage_matrix_cells` + duplicate_flags + rgpd_requests + ai_act_register + notifications + saved_views + email_validations + web_vital_samples
7. `000007_create_phase2_scaffold_schema.php` — Phase 2 : campaigns + email_templates/sequences/sends + linkedin_accounts/messages + pipeline_stages + deals + activities + analytics_daily_rollups
8. `000008_enable_rls_policies.php` — RLS ON sur 30 tables workspace-scoped + policy `<table>_workspace_isolation` lisant `current_setting('app.current_workspace_id')`

**Seeders livrés (10 fichiers) :**
- `DatabaseSeeder` orchestrateur
- `PermissionsAndRolesSeeder` — 15 permissions Phase 1 + 4 rôles (owner/admin/operator/viewer) + role_has_permissions
- `CountriesSeeder` — FR/BE/CH/LU/EE
- `FrenchRegionsSeeder` — 13 régions métropolitaines + 5 DROM (INSEE 2026)
- `EffectifRangesSeeder` — 16 codes INSEE (NN, 00, 01, …, 53) + size_category mapping
- `AxionOfferTargetsSeeder` — 8 offres Axion-IA (Audit Flash/Essentielle/Approfondie, Mission PME/ETI, Grand programme, IA custom, Maintenance)
- `SearchEnginesSeeder` — Google/Bing/DuckDuckGo
- `NafSectionsSeeder` — 21 sections A-U (divisions/groupes/classes via `php artisan naf:import` Sprint 5)
- `LegalFormsSeeder` — top 20 INSEE
- `UserAgentsSeeder` — 11 UAs récents Chrome/FF/Safari/Edge Win/Mac/Linux/iOS/Android (weighted)
- `LlmUseCasesSeeder` — 9 use cases Phase 1 v1.1 mergés (classify_company_axion, sector_classification, extract_team_from_page, detect_email_pattern, auto_tag, extract_strategic_keywords, summarize_signals, normalize_address, classify_priority)
- `OwnerUserSeeder` — workspace `axion-ia` + user owner (`OWNER_INITIAL_EMAIL`) + user_workspaces + model_has_roles

**Reste Sprint 2 (à venir sessions suivantes) :**
- Import IGN AdminExpress COG 2026 (départements + 35 000 communes) via artisan command
- Import NAF complet (732 codes 5 niveaux) depuis CSV INSEE
- Seeders cities (~2150 communes >5k hab pour pSEO)
- Test `php artisan migrate:fresh --seed` réel en environnement Docker
- pg_partman bootstrap (audit_logs partitioning + retention 24 mois)
- Trigger SQL recompute_company_quality_score sur INSERT/UPDATE contacts.email_status

## Décisions par défaut appliquées (faute de spec STOP & ASK explicite)

- **OWNER_INITIAL_PASSWORD** vide par défaut → owner se connecte uniquement via magic-link au 1er login (sécurité maximale, pas de password en clair en env).
- **CITEXT** pour `users.email` + `workspaces.slug` + `magic_links.email` + `opt_out.email` → comparaisons case-insensitive natives.
- **CHECK constraints** sur enums critiques (`scraper_runs.status`, `rgpd_requests.type/status`, `proxy_providers_config.type`, `user_workspaces.role_slug`, etc.) → garde-fou DB en sus de validation app.
- **GENERATED COLUMNS** pour `companies.denomination_normalized` + `companies.quality_badge` + `contacts.normalized_hash` → invariants DB, pas de drift app/DB.
- **Phase 2 tables créées dès Sprint 2** (pas de migration séparée Phase 2) → permet aux endpoints `501` de bénéficier des types Spatie Data dès maintenant.
- **`coverage_matrix_cells` MATERIALIZED VIEW** créée WITH NO DATA → refresh hourly par `php artisan coverage:refresh-matrix` (scheduler déjà configuré).
- **`current_setting('app.current_workspace_id', true)`** avec `true` (missing_ok) → si pas de session var (migrations, seeders, jobs system), la policy passe.
- **`role_has_permissions` (Spatie)** + non `role_permissions` (spec/03) → conformité Spatie Laravel Permission v6 native.
- **`COVERAGE_MATRIX_CELLS` rollup** par `(workspace_id, postcode, naf, size_category)` au lieu de `(department × naf × tranche)` strict de la spec → granularité finer, agrégeable côté API si besoin.

## Blockers humains (actions Will)

Aucun bloquant à ce stade. Les services payants (Anthropic, Webshare, IPRoyal, 2captcha, port 25 SMTP sortant) restent **inutiles tant que `MOCK_MODE=true`**. Souscriptions Phase 1 réelle : voir `TODO.md` § §3.
