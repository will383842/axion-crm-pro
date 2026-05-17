# PROMPT AUDIT E2E — Vérification forensique exhaustive post-implémentation Phase 1

> **À copier-coller dans une NOUVELLE conversation Claude Code** (CWD = `C:\Users\willi\Documents\Projets\Axion-CRM-Pro`).
> **À utiliser APRÈS** que l'autopilot Sprint 1→12 ait terminé son travail (tag `phase1-mocks-complete-*` créé).
> **Modèle :** Opus 4.7 (1M tokens) — `/model` avant de coller.
> **Durée estimée :** 4-8h Claude (10 agents //, ~15 000 critères auditables, rapport ~6 000 mots).
> **Sortie :** dossier `_AUDIT/AUDIT-E2E-PHASE1-YYYY-MM-DD/` avec 12 fichiers de rapport + verdict GO/NO-GO PROD.

---

## Comment l'utiliser

1. Vérifie que le tag `phase1-mocks-complete-*` existe (`git tag | grep phase1`)
2. Ouvre une **nouvelle conversation** Claude Code dans `C:\Users\willi\Documents\Projets\Axion-CRM-Pro`
3. Sélectionne **Opus 4.7** via `/model`
4. Copie tout le contenu entre `<prompt>` et `</prompt>` (sans les balises)
5. Colle et envoie
6. Laisse tourner 4-8h. Claude Code fait l'audit complet en autonomie + commit + push.

---

<prompt>

# MISSION — AUDIT FORENSIQUE E2E PHASE 1 — VERDICT PROD READY

Tu es **Architecte Principal en mission d'audit indépendant** sur le projet Axion CRM Pro. L'autopilot a livré Phase 1 complète avec mocks (Sprint 1→12). Ton job : **vérifier que TOUT est parfaitement implémenté, opérationnel, cohérent, sécurisé, performant — production ready**.

Tu n'es PAS le développeur de cette codebase. Tu débarques sur un projet inconnu. **Posture : suspect, exhaustif, sans complaisance.**

## CONTEXTE — LECTURE OBLIGATOIRE AVANT TOUT AUDIT

**Repo local :** `C:\Users\willi\Documents\Projets\Axion-CRM-Pro`
**Repo GitHub :** https://github.com/will383842/axion-crm-pro
**Tag implémentation :** `git tag | grep phase1-mocks-complete` (doit exister, sinon STOP)

**Lis dans l'ordre AVANT de commencer l'audit (lecture obligatoire) :**

### Documents stratégiques (15 min)
1. `spec/00_INDEX.md` — sommaire général
2. `spec/01_thinking_executive_naming.md` — vision + 8 risques + 8 décisions
3. `spec/AUDIT_v1.md` — audit critique de la spec elle-même (16 P0 + 9 P1 fixés)
4. `MOCKS-STRATEGY.md` — convention mocks adoptée
5. `TODO.md` — état global du projet
6. `poc/SYNTHESIS.md` — POC #5 dedup validé en réel

### Documents spec implémentation (30 min)
7. `spec/02_architecture_infra.md` — stack précise + dimensionnement
8. `spec/03_db_schema_phase1.md` — 66 tables Phase 1
9. `spec/04_db_schema_phase2_scaffold.md` — 35 tables Phase 2 scaffold
10. `spec/05_scrapers_14_sources.md` — 14 sources + GSW + Direction Finder
11. `spec/06_email_finder_validation.md` — patterns + SMTP cascade
12. `spec/07_llm_router.md` — LLMClient + 9 use cases
13. `spec/08_waterfall_enrichissement_classification.md` — state machine 10 étapes
14. `spec/09_proxy_pluggable_system.md` — ProxyProvider
15. `spec/10_rotations_universelles.md` — 5 dimensions
16. `spec/11_carte_france_interactive.md` — MapLibre + IGN + BAN
17. `spec/12_coverage_matrix_deduplication.md` — anti-doublon 6 niveaux
18. `spec/13_ui_admin_phase1.md` — 17 pages Phase 1
19. `spec/14_api_routes_laravel.md` — ~121 endpoints
20. `spec/15_auth_multitenant_rbac.md` — Sanctum + 2FA + RLS
21. `spec/16_monitoring_observabilite.md` — Prometheus + Grafana + Loki + Tempo + Langfuse
22. `spec/17_rgpd_aiact_owasp.md` — DPIA + SSRF + sous-processeurs LLM + OWASP top 10
23. `spec/18_deploiement_hetzner.md` — Terraform + docker-compose + CI/CD + DR
24. `spec/19_queues_workers_playwright.md` — Horizon + workers + bridge Redis
25. `spec/20_detection_nouveaux_prospects_signaux.md` — jobs nightly
26. `spec/21_couts_roadmap.md` — budget + 12 sprints + critères GO/NO-GO
27. `spec/22_risques_mitigations.md` — 20 risques + mitigation
28. `spec/23_interfaces_phase2_execution_pack.md` — interfaces Phase 2 + 12 prompts code
29. `spec/24_frontend_design_system.md` — design tokens + responsive + UX

**Volume total spec : ~80 000 mots. Ne saute aucune section.**

## RÈGLES DE TRAVAIL — AUTOPILOT AUDIT TOTAL

1. **Pas de complaisance.** Si quelque chose est faible, dis-le. Si quelque chose est cassé, dis-le. Cite les fichiers + lignes.
2. **Pas de validation entre étapes.** Tu enchaînes les 12 phases d'audit.
3. **Multi-agent //.** Dès que possible, lance plusieurs sub-agents en parallèle (Agent tool subagent_type=Explore pour exploration, general-purpose pour analyse).
4. **Croisements croisés.** Backend ↔ Frontend ↔ Workers ↔ Spec ↔ Tests : détecte les incohérences.
5. **Tests réels.** Lance les commandes de validation (lint, typecheck, tests, build). Ne fais pas confiance aveuglement aux fichiers `RESULTS.md` éventuels.
6. **Scoring quantifié.** Chaque catégorie est notée /100, total final /1000.
7. **Citations précises.** `path/to/file.ts:42` + extrait code.
8. **Recommandations actionnables.** Pas « il faudrait améliorer X » mais « remplacer ligne Y du fichier Z par W ».
9. **Pas de duplications.** Si tu as déjà cité une faiblesse, ne la répète pas dans 3 sections différentes.
10. **Sortie structurée.** Tous les rapports dans `_AUDIT/AUDIT-E2E-PHASE1-YYYY-MM-DD/` (créé par toi).

## 12 PHASES D'AUDIT (à exécuter SÉQUENTIELLEMENT, sub-agents // au sein de chaque phase)

### PHASE 0 — Setup audit + reality check (15 min)

1. Vérifie que le tag `phase1-mocks-complete-*` existe. Sinon → STOP et rapport `00_BLOCKER_NO_IMPL.md`.
2. Crée le dossier `_AUDIT/AUDIT-E2E-PHASE1-$(date +%Y-%m-%d)/`.
3. Crée le fichier `_AUDIT/AUDIT-E2E-PHASE1-*/MANIFEST.md` avec :
   - Date début audit
   - Sha commit audité
   - Liste des 12 phases prévues
   - Méthodologie
4. Quick stats du repo : `find . -type f | wc -l`, `cloc backend/ frontend/ workers/`, taille DB migrations, nombre routes, nombre tests.
5. Vérifie que les dossiers attendus existent et sont non-vides : `backend/`, `frontend/`, `workers/`, `infra/`. Si l'un est vide → rapport `00_BLOCKER_MISSING_FOLDER.md`.

### PHASE 1 — Cohérence Spec ↔ Code (Agent dédié, 45 min)

**Sub-agent type Explore.** Pour chaque section de la spec, vérifier que l'implémentation correspond.

Pour chaque fichier spec, croiser avec le code livré :

| Spec | Vérification code |
|------|---------------------|
| `03_db_schema_phase1.md` 66 tables | Compter migrations `backend/database/migrations/*.php` ≥ 98 fichiers. Pour chaque table, vérifier `CREATE TABLE <name>` dans une migration. |
| `04_db_schema_phase2_scaffold.md` 35 tables | Idem |
| `05_scrapers_14_sources.md` 14 sources | Pour chaque source, fichier `workers/src/scrapers/<source>.ts` + classe `<Source>Scraper` |
| `07_llm_router.md` 9 use cases | Seeder `LlmUseCaseSeeder` contient les 9 use_case_slug listés |
| `08_waterfall_enrichissement_classification.md` 10 étapes | `WaterfallOrchestrator` a 10 étapes dans `runStep()` |
| `13_ui_admin_phase1.md` 17 pages | Pour chaque page, fichier React `frontend/src/features/*/Page.tsx` |
| `14_api_routes_laravel.md` ~121 endpoints | `php artisan route:list --json` retourne ≥ 121 entries |
| `15_auth_multitenant_rbac.md` 4 rôles | Seeder `RolesSeeder` contient `owner`, `admin`, `operator`, `viewer` |
| `16_monitoring_observabilite.md` 48 métriques | `app/Services/Metrics/` enregistre ≥ 48 metric names |
| `17_rgpd_aiact_owasp.md` SSRF guard | Fichier `app/Services/Security/SsrfGuard.php` ou `workers/src/utils/ssrf-guard.ts` |

**Livrable :** `_AUDIT/AUDIT-E2E-PHASE1-*/01_spec_code_coherence.md` avec tableau de couverture + lacunes.

**Scoring :** /100 = (matches confirmés / éléments spec attendus) × 100.

### PHASE 2 — Backend Laravel exhaustif (Sub-agent, 60 min)

**Sub-agent type general-purpose.** Audite la structure + qualité Laravel.

#### A. Structure (10 pts)
- Convention bounded contexts `app/Domain/<Context>/` (Scraping, Companies, Enrichment, LLM, RGPD, Auth) ? Cf. spec 24 § 11.2.
- Modèles Eloquent avec relations bidirectionnelles ?
- Migrations ordonnées avec dépendances respectées ?
- Seeders idempotents ?
- Factories pour tous les modèles (utiles pour tests + démo) ?

#### B. Routes & Controllers (15 pts)
- Compte routes : `php artisan route:list --json | jq length` ≥ 121
- Pour chaque endpoint spec 14, vérifier qu'il existe ET retourne le bon code HTTP (200/201/204/501)
- Middleware appliqués correctement : `auth:sanctum`, `set.workspace`, `can:*`
- Rate limiting configuré sur `login`, `scraping_run`, `llm_test`
- Phase 2 stubs retournent bien 501 avec body typé

#### C. Services métier (20 pts)
- `LLMRouterService` : signature `LLMClient`, fallback chain, cache Redis, cost tracking, sanitizeExternalInputs anti-prompt-injection
- `DeduplicationService` : 6 niveaux implémentés (SIREN, contact hash, scraper_runs TTL, coverage cells cooldown, email_verifications TTL 30j, opt_out cross-workspace)
- `WaterfallOrchestrator` : Spatie state machine, 10 étapes, parallélisation `runParallel`
- `EmailFinderService` : 18 patterns, cascade SMTP N1-N5, scoring 0-100, catch-all detection, TTL 30j
- `ClassifierService` : 4 use cases LLM mergés (classify_company_axion + auto_tag + extract_strategic_keywords)
- `AuditLog` : hash chain Genesis → ... → record_hash vérifiable
- `compute_size_category()` SQL function : 6 catégories (artisan/commercant/tpe/pme/eti/ge) avec règles NAF + RM/RCS + effectif
- `recompute_company_quality_score()` SQL function : critères assouplis pour artisan (LinkedIn optionnel)

#### D. Jobs Horizon (10 pts)
- Configuration `config/horizon.php` : 7 supervisors avec concurrence appropriée
- `EnrichCompanyJob`, `DispatchPlaywrightScraperJob` existent
- Schedule `app/Console/Kernel.php` : 17 commands programmées (cf. spec 19 § 9)
- Retry policies appropriées par type job

#### E. Auth + Sécurité (15 pts)
- Sanctum SPA cookie configuré
- 2FA TOTP : `pragmarx/google2fa-laravel` installé, secret chiffré AES-256-GCM
- Magic Link : token SHA-256 hashé, expire 15 min, single-use
- Brute force protection : 5 fails → lock 15 min
- Middleware `EnforceFirstLoginSetup` : force 2FA setup + change password 1er login
- `OwnerUserSeeder` : lit `OWNER_INITIAL_*` env vars, HIBP check (api.pwnedpasswords.com), refuse password < 12 chars
- RLS PostgreSQL : `ENABLE ROW LEVEL SECURITY` sur toutes tables workspace_id (au moins 40 ALTER TABLE)
- `SetCurrentWorkspace` middleware : `SET LOCAL app.current_workspace_id`
- SSRF guard `workers/src/utils/ssrf-guard.ts` : BLOCKED_CIDRS contient 169.254.0.0/16 (cloud metadata), 10/8, 172.16/12, 192.168/16, 127/8

#### F. Tests Pest (15 pts)
- Run : `cd backend && composer test`
- Compte tests ≥ 200 (cible Phase 1)
- Coverage ≥ 70 % (via `--coverage` si configured)
- Tests critiques présents :
  - `test('user A cannot access workspace B')`
  - `test('audit hash chain valid after 100 inserts')`
  - `test('dedup niveau 6 opt-out blocks scraping')`
  - `test('SSRF guard blocks 169.254.169.254')`
  - `test('prompt injection adverse pattern sanitized')`
  - `test('compute_size_category returns artisan for RM + effectif 5')`
  - `test('recompute_company_quality_score returns complete only with email + director + phone + linkedin')`
  - `test('owner seeder rejects password < 12 chars')`
  - `test('Phase 2 routes return 501')`

#### G. Qualité code (15 pts)
- Run : `cd backend && vendor/bin/phpstan analyse` → level 9, 0 errors
- Run : `composer audit` → 0 critical
- PSR-12 respecté (`vendor/bin/pint --test`)
- Pas de `var_dump` / `dd()` / `dump()` oublié
- Pas de fichier `.env` committé (git log search)
- Pas de credentials hardcodés (grep `sk-ant-`, `ghp_`, password = `)

**Livrable :** `_AUDIT/AUDIT-E2E-PHASE1-*/02_backend_laravel.md` (scoring /100).

### PHASE 3 — Frontend React exhaustif (Sub-agent, 50 min)

**Sub-agent type general-purpose.**

#### A. Structure (10 pts)
- Convention `src/features/<feature>/` (auth, companies, contacts, coverage, scraping, llm, rgpd, workspace, alerts, phase2-scaffold)
- Composants UI shadcn copiés dans `src/components/ui/`
- Hooks custom dans `src/hooks/`
- Lib utils dans `src/lib/`
- i18n locales `src/locales/{fr,en}/` (cf. P1 audit i18next dès S1)

#### B. Routes (10 pts)
- TanStack Router v1 configuré
- 17 routes Phase 1 + 5 routes Phase 2 scaffold = 22 routes minimum
- Layouts : `AppLayout` (sidebar + topbar) et `AuthLayout`
- Error boundaries : 3 niveaux (App, Route, Section) cf. spec 24 § 5

#### C. Composants partagés (10 pts)
- `<QualityBadge />` 3 états 🟢🟡🔴
- `<SizeCategoryBadge />` **6 catégories** (artisan/commercant/tpe/pme/eti/ge) cf. spec v1.1 P1 audit
- `<DiscoverySourceBadge />`, `<PrioritySelect />`, `<NafSelector />`, `<DateRangePicker />`
- `<EmptyState />` réutilisable cf. spec 24 § 3
- Skeletons cf. spec 24 § 4
- `<FormField />` cf. spec 24 § 7

#### D. Pages 17 Phase 1 (15 pts)

Pour chacune, vérifier que le fichier React existe + n'est pas un placeholder vide :
- LoginPage + TwoFactorPage + MagicLinkRequest + PasswordReset
- DashboardPage (KPIs temps réel, distribution 6 catégories)
- CoveragePage (carte MapLibre + 3 modes)
- CompaniesListPage (filtres 11 dimensions v1.1 + table virtualisée)
- CompanyDetailPage (badge qualité + 6 catégories + override + historique)
- ContactsListPage (filtres seniority + discovery_source + size)
- ContactDetailPage (mention discovery source détaillée)
- ScrapingSourcesPage + LLMRouterPage + RotationsPage + ProxyProvidersPage + ScraperRunsPage
- AuditLogViewerPage (hash chain verification)
- RgpdRequestsPage (workflow erasure 5 étapes)
- WorkspaceUsersPage + WorkspaceSettingsPage
- AnomaliesPage

#### E. 5 Pages Phase 2 scaffold (5 pts)

Placeholder "🟡 Module Phase 2 — bientôt disponible" :
- CampaignsPage + ColdEmailPage + LinkedInPage + CrmPage + AnalyticsPage

#### F. Carte France (10 pts)
- MapLibre GL JS v4+ intégré
- Tuiles servies depuis `public/tiles/admin/` ou OpenFreeMap
- Polygones IGN AdminExpress COG 2026 importés (régions + departments + cities >5k)
- 3 modes (Visualisation choropleth + Recherche auto-suggest + Action panneau)
- Lazy-load dynamic import
- Tests E2E Playwright sur `<FranceCoverageMap />`

#### G. Responsive Full (10 pts)
- Layout mobile drawer + bottom tab bar
- Tables → cards stacked < 640px
- Coverage Map → bottom sheet mobile
- Détail entreprise → accordions mobile
- Tests visuels Playwright sur 4 viewports (mobile 390, tablet 820, desktop 1440, XL 2560)

#### H. Design System v1.2 (10 pts)
- Tokens Tailwind 4 `@theme` dans `styles/tokens.css` (couleurs brand + 6 size_category + sémantique + typography + spacing + radii + shadows)
- Dark mode + Light mode persistant localStorage
- `<Logo />` placeholder typographique `axion/crm`
- Toast patterns sonner (8 conventions)
- Form validation react-hook-form + zod

#### I. A11y WCAG 2.2 AA (10 pts) — P1 audit
- Axe-core CI configuré `.github/workflows/a11y.yml`
- 9 nouveaux critères 2.2 documentés (Focus Not Obscured, Target Size 24×24, Consistent Help, Accessible Authentication...)
- Drag-and-drop kanban CRM Phase 2 : alternative clavier (Space, flèches, Enter)

#### J. Tests + Build (10 pts)
- Run : `cd frontend && pnpm test` → tous verts (Vitest)
- Run : `cd frontend && pnpm typecheck` → 0 errors strict
- Run : `cd frontend && pnpm lint` → 0 errors
- Run : `cd frontend && pnpm build` → succès + bundle ≤ 500 KB gz (route principale)
- Lighthouse stub via `pnpm exec lhci autorun` ou check manuel ≥ 90 perf + a11y + best practices + SEO

**Livrable :** `_AUDIT/AUDIT-E2E-PHASE1-*/03_frontend_react.md` (scoring /100).

### PHASE 4 — Workers Node + Playwright + Mocks (Sub-agent, 40 min)

**Sub-agent type general-purpose.**

#### A. Architecture mocks (15 pts)
- `MOCK_MODE=true` par défaut dans `.env.example`
- Pour chaque service externe (cf. MOCKS-STRATEGY.md tableau) : interface + 2 implémentations (prod + mock) injectables
- Mocks lisent depuis `tests/fixtures/<service>/*.json`
- DI Laravel `MockServicesProvider` route correctement selon env vars

#### B. Workers Node (15 pts)
- 8 worker types : google-maps, pages-jaunes, sites-web, google-search, direction-finder, crunchbase, social-light, france-travail
- Concurrence revue v1.1 : 2/2/4/2/2/1/3 (vs 4/3/6/3/2/2/4 v1.0)
- Restart périodique 500 jobs (`MAX_JOBS_BEFORE_RESTART=500` + memory check 4.8 GB) cf. P11 audit
- Graceful shutdown SIGTERM
- OpenTelemetry SDK PHP + Node + Browser instrumenté cf. P1 audit

#### C. Bridge Redis (10 pts)
- Split Redis cf. P10 audit : `redis-queues` (noeviction, port 6379) + `redis-cache` (allkeys-lru, port 6380)
- BullMQ côté Node connecté à DB 1
- Horizon côté Laravel connecté à DB 0
- Endpoint interne `/internal/scraper-result` protégé par token interne

#### D. Mocks scrapers (20 pts)
- `MockGoogleMapsScraper` lit `tests/fixtures/google-maps/*.html` + retourne `ScraperResult` typé
- `MockSearchEngine` 3 moteurs lit `tests/fixtures/google-search/*.html`
- `MockDirectionFinder` 4 sources lit `tests/fixtures/direction-finder/*.json` + retourne C-level pour 20 ETI test
- `MockSmtpProber` lit `tests/fixtures/smtp/email_status_map.json` + retourne status simulé
- `MockLLMClient` lit `tests/fixtures/llm/<use_case_slug>.json` ou retourne fixtures génériques
- Fixtures cohérentes (au moins 20 fixtures par service principal)

#### E. Tests workers (10 pts)
- Run : `cd workers && pnpm test` (Vitest) → tous verts
- Tests par scraper : input fixture → output validé
- Test bridge Redis : message envoyé Laravel → reçu Node → confirmation reçue Laravel
- Test backpressure stream COPY

#### F. Anti-bot durci POC #1/#2 patterns (10 pts) — P0 audit
- `playwright-extra-plugin-fingerprint-randomizer` ou équivalent installé
- Canvas + WebGL + Audio randomization activée
- Cookie warehouse persistance sticky session 30 min
- Détection `unusual_traffic` + `cf_challenge` + `captcha_v3_blocked`
- 2captcha integration `puppeteer-extra-plugin-recaptcha` configurée

#### G. Direction Finder mocks (10 pts)
- Worker `worker-direction-finder` existe avec 4 sources
- Mocks pour 20 ETI test : TotalEnergies, Veolia, Sanofi, Carrefour, BNP, Capgemini, etc. (liste cf. POC #3)
- Cap PDF 10 MB respecté (mock ignore PDFs > 10 MB)
- Fallback `no_directory_page` implémenté

#### H. Qualité code (10 pts)
- TypeScript strict (`strict: true`)
- ESLint 0 errors
- Pas de `console.log` oublié (uniquement `logger.*` Pino)
- Pas de credentials hardcodés

**Livrable :** `_AUDIT/AUDIT-E2E-PHASE1-*/04_workers_mocks.md` (scoring /100).

### PHASE 5 — Infrastructure + DevOps (Sub-agent, 35 min)

**Sub-agent type general-purpose.**

#### A. Docker (15 pts)
- `docker-compose.yml` maître (orchestration locale dev) : Postgres + Redis-queues + Redis-cache + Caddy + Laravel + Frontend + Worker-1 + Worker-2
- `docker-compose.prod.yml` (overrides prod)
- `docker-compose.observability.yml` : Prometheus + Grafana + Loki + Tempo + Alertmanager + GlitchTip + Uptime Kuma + Langfuse
- Dockerfiles multi-stage : Laravel (php-fpm + nginx + supervisor), Frontend (Vite build), Worker (Playwright)
- Healthchecks configurés sur tous services critiques
- Volumes persistents : pgdata, redis*data, meili-data, langfuse-data
- Réseau interne Docker `axion-net`
- Run : `docker-compose config` → parse sans erreur

#### B. Caddy reverse proxy (5 pts)
- Caddyfile avec common_headers + rate_limit + reverse_proxy + healthcheck
- HSTS + X-Frame-Options + X-Content-Type-Options + Permissions-Policy

#### C. Terraform Hetzner module (10 pts) — P1 audit
- `infra/terraform/main.tf` avec providers hcloud + cloudflare
- 7 servers déclarés (edge CAX21 + app CPX31 + data CCX13 + worker-1/-2 CPX31 + observability CPX21 + staging CCX13)
- vSwitch 4011 + firewall + floating IPs
- Backend S3 Hetzner OBS pour tfstate
- `terraform validate` succès

#### D. CI/CD GitHub Actions (15 pts)
- `.github/workflows/ci.yml` : lint + typecheck + tests Pest/Vitest/Playwright + composer audit + pnpm audit + Trivy scan + Semgrep
- `.github/workflows/deploy-staging.yml` : build images + push GHCR + trigger Coolify API
- `.github/workflows/deploy-prod.yml` : workflow_dispatch manuel + smoke prod
- `.github/workflows/a11y.yml` : axe-core scan staging
- `.github/workflows/security.yml` : OWASP ZAP + Semgrep + Trivy
- `dependabot.yml` : weekly updates

#### E. Backups + DR (10 pts)
- pgbackrest configuré (`infra/pgbackrest.conf` + cron) avec retention 30j + WAL archiving
- Hetzner Object Storage backend
- Backblaze B2 réplication quotidienne via rclone
- Script DR drill `infra/scripts/dr-drill.sh`
- Runbook `infra/runbooks/restore-from-backup.md`

#### F. Observability stack (15 pts)
- Prometheus config + retention 30j
- Grafana 10 dashboards JSON provisionnés (`grafana-provisioning/dashboards/`)
- Loki + Promtail config
- Tempo config
- Alertmanager rules YAML (au moins 10 alertes : ScrapingSourceErrorSpike, ProxyProviderDegraded, AllSearchEnginesBlocked, LLMCostSpike, LLMProviderDown, LLMWorkspaceBudgetReached, EnrichmentQualityDropping, ProspectsPipelineDisette, DiskSpaceLow, RedisQueueBacklog)
- GlitchTip configuré
- Uptime Kuma probes externes

#### G. Métriques business (10 pts) — P1 audit
- `axion_crm_fresh_complete_prospects_gauge` exposée
- `axion_crm_size_category_distribution` 6 catégories
- `axion_crm_pipeline_health_score_gauge`
- `axion_crm_enrichment_velocity_per_day_gauge`
- Alerte `ProspectsPipelineDisette` configurée

#### H. Langfuse evals (10 pts) — P1 audit
- Container Langfuse dans docker-compose.observability.yml
- Intégration `logToLangfuse()` dans `LLMRouterService`
- Job hebdo `app:llm-evals` avec dataset de référence
- Page admin "LLM Evals" UI

#### I. Secrets management (10 pts)
- Doppler/Infisical placeholder dans `.env.example`
- Pas de fichier `.env` committé
- Owner password jamais dans Git (cf. P0 audit `OwnerUserSeeder`)
- Tokens API tous via env vars

**Livrable :** `_AUDIT/AUDIT-E2E-PHASE1-*/05_infra_devops.md` (scoring /100).

### PHASE 6 — Sécurité forensique (Sub-agent dédié, 45 min)

**Sub-agent type general-purpose.** OWASP Top 10 méticuleux + au-delà.

#### A. A01 Broken Access Control (10 pts)
- RLS PostgreSQL effective : test concret `SET app.current_workspace_id = wsA; SELECT * FROM companies WHERE workspace_id = wsB;` doit retourner 0 rows
- Policies Eloquent existent pour Company, Contact, EmailVerification, ScraperRun, AuditLog
- Authorization explicite par route via `->middleware('can:*')`
- Test E2E "user A cannot access workspace B" passe

#### B. A02 Cryptographic Failures (10 pts)
- TLS 1.3 forced (Caddy)
- HSTS preload 12 mois
- Bcrypt rounds 12 confirmé `config('hashing.bcrypt.rounds')`
- TOTP secrets `Crypt::encryptString()` AES-256-GCM
- Sessions HttpOnly + Secure + SameSite=lax
- `APP_KEY` rotation procédure documentée

#### C. A03 Injection (10 pts)
- Eloquent parameterized queries (audit grep raw `DB::raw` + `whereRaw` justifié)
- Spatie Query Builder whitelisted filters
- Validation Spatie Data sur tous DTOs
- Twig autoescape configuré pour prompts LLM
- SSRF guard utilisé partout fetch externe (grep `fetch(` dans workers, vérifier `ssrfGuard()` call)
- **Prompt injection mitigation** : `sanitizeExternalInputs()` PHP + variables `ext_*` documentées + `<EXTERNAL_UNTRUSTED_INPUT>` delim

#### D. A04 Insecure Design (10 pts)
- Rate limiting login, scraping_run, llm_test
- Cost cap LLM (kill-switch workspace `cost_cap_eur`)
- Opt-out global avant scraping
- Anti-doublon 6 niveaux pour éviter gaspillage

#### E. A05 Security Misconfiguration (10 pts)
- `APP_DEBUG=false` en prod (vérifier `.env.production` ou config build prod)
- Headers Caddy : HSTS, X-Frame-Options DENY, X-Content-Type-Options nosniff, Referrer-Policy strict-origin-when-cross-origin, Permissions-Policy restrictif
- CORS strict (origin = crm.axion-pro.com)
- Postgres user `axion_app` NOT superuser
- Pas de port DB exposé public (only intra-vSwitch ou 127.0.0.1)

#### F. A06 Vulnerable Components (10 pts)
- Dependabot config weekly
- `composer audit` → 0 high/critical
- `pnpm audit --audit-level=high` → 0 high
- Trivy scan images Docker en CI

#### G. A07 Auth Failures (10 pts)
- 2FA TOTP obligatoire (middleware EnforceFirstLoginSetup)
- Brute force protection 5 fails → lock 15 min
- HIBP password check sur seeder owner + change password user
- Magic link SHA-256 hashé, expire 15 min, single-use

#### H. A08 Software & Data Integrity (10 pts)
- Audit log hash chain vérifiable (test `php artisan audit:verify-chain`)
- CI/CD signed commits (gitsign Sigstore) — optionnel mais valoriser si présent
- Docker image content trust — optionnel

#### I. A09 Security Logging Failures (10 pts)
- Logs structurés JSON Monolog → stdout → Promtail → Loki
- Audit log immuable partitionné
- Alertes critiques Slack + Telegram routées
- GlitchTip error tracking

#### J. A10 SSRF (10 pts)
- `ssrfGuard()` function existe et bloque BLOCKED_CIDRS (10/8, 172.16/12, 192.168/16, 127/8, 169.254/16 cloud metadata, 100.64/10 CGNAT, fc00::/7, fe80::/10)
- DNS resolution + IP check avant chaque fetch user-fed URL
- Test concret : crée un test qui passe `http://169.254.169.254/...` et vérifie qu'il est bloqué
- Outbound firewall Hetzner restreint (Terraform)

**Bonus (au-delà OWASP) :**
- CSP strict Caddy (au moins basique sans `unsafe-inline` ou justifié)
- Subresource Integrity sur scripts externes
- Cookies Permissions-Policy

**Livrable :** `_AUDIT/AUDIT-E2E-PHASE1-*/06_security_forensic.md` (scoring /100).

### PHASE 7 — Conformité RGPD + AI Act (Sub-agent, 30 min)

**Sub-agent type general-purpose.**

#### A. RGPD (40 pts)
- Base légale documentée (intérêt légitime art. 6.1.f) — table `data_processing_log` seedée
- Opt-out cross-workspace : table sans workspace_id, query AVANT chaque scraping/enrichissement
- Droit accès/suppression : `GdprErasureService.execute()` transaction atomique multi-tables (contacts anonymized + email_verifications deleted + opt_out global added)
- `GdprPortabilityService.export()` JSON encrypted
- DPO email `contact@axion-ia.com` configuré
- Conservation 90j data scraping (job nightly `app:purge-stale-records`)
- IP anonymization > 30j (job `app:anonymize-old-ips`)
- Section "Sous-processeurs LLM documentés" dans spec 17 implémentée (tableau Anthropic/Mistral/OpenAI/etc. avec statut DPA)
- **DPIA** : section `spec/17_rgpd_aiact_owasp.md` § DPIA présente (rédaction réelle = à faire hors-autopilot)

#### B. AI Act (30 pts)
- Table `ai_act_register` seedée avec entrées : `classify_company_axion`, `extract_team_from_page`, `auto_tag_generation` (3 use cases)
- `risk_category` correctement classé (`limited` pour profilage indirect)
- `is_profiling`, `human_oversight`, `transparency_notice` remplis
- Widget transparency notice UI dans CompanyDetailPage (P1 audit)
- `fiche_quality_scoring` NON présent (retiré v1.1, scoring déterministe SQL)

#### C. OWASP cross-référencé (30 pts)
- Check : pour chaque item du Top 10 cf. spec 17 § 8, le code applique la mesure

**Livrable :** `_AUDIT/AUDIT-E2E-PHASE1-*/07_compliance_rgpd_ai_act.md` (scoring /100).

### PHASE 8 — Tests automatisés exhaustifs (Sub-agent, 40 min)

#### A. Tests Pest backend (25 pts)
- Run : `cd backend && composer test` → exit 0
- Compte tests : `vendor/bin/pest --list-tests` ≥ 200
- Tests par module :
  - Auth : ≥ 12 tests
  - DB schema : ≥ 5 tests (RLS, partitions, functions)
  - Companies CRUD + filters : ≥ 15 tests
  - Contacts CRUD + filters : ≥ 10 tests
  - DeduplicationService 6 niveaux : ≥ 12 tests
  - Email finder 18 patterns : ≥ 18 tests
  - LLM Router + fallback : ≥ 8 tests
  - Waterfall orchestrator : ≥ 8 tests
  - Scrapers (mock) : ≥ 14 tests (1 par source)
  - RGPD erasure + portability : ≥ 6 tests
  - SSRF guard : ≥ 8 tests
  - Prompt injection sanitization : ≥ 5 tests
  - Audit hash chain : ≥ 4 tests
  - Anomalies + alertes : ≥ 4 tests

#### B. Tests Vitest frontend (15 pts)
- Run : `cd frontend && pnpm test` → exit 0
- Compte tests ≥ 80
- Composants critiques testés : QualityBadge, SizeCategoryBadge (6 cat), CompaniesTable, FranceCoverageMap, FormField + validation, GlobalCommand (⌘K)

#### C. Tests Playwright E2E (25 pts)
- Run : `cd frontend && pnpm exec playwright test` → exit 0
- Compte scenarios ≥ 50
- Critical user journeys :
  - Login + 2FA setup + magic link
  - Création workspace + invitation user
  - Liste entreprises + filtres avancés + 10 vues organisation
  - Détail entreprise + relancer enrichissement + override scores
  - Carte France : visu + recherche ville + clic zone + action scraping
  - Direction Finder pour 1 ETI mockée
  - RGPD erasure flow complet
  - Saved views save + load
  - Recherche globale ⌘K
  - Onboarding tour 1er login
- Tests responsive 4 viewports (390, 820, 1440, 2560)
- Tests a11y axe-core sur 5 pages principales

#### D. Tests Workers Vitest (10 pts)
- Run : `cd workers && pnpm test` → exit 0
- Compte tests ≥ 40

#### E. Tests load k6 (10 pts)
- Script `infra/loadtests/api-100rps.js` existe
- Smoke run : 100 req/s sur API tient sans dégradation > 200ms p95

#### F. Coverage (15 pts)
- Backend coverage ≥ 70 % (lignes critiques 90 %)
- Frontend coverage ≥ 60 %
- Workers coverage ≥ 60 %

**Livrable :** `_AUDIT/AUDIT-E2E-PHASE1-*/08_tests.md` (scoring /100).

### PHASE 9 — Performance & Optimisation (Sub-agent, 25 min)

#### A. Frontend bundle (20 pts)
- Run : `cd frontend && pnpm build` → succès
- Bundle main route ≤ 500 KB gz
- Lazy-load MapLibre (dynamic import) confirmé
- Code splitting par route confirmé (vite chunks)
- Images optimisées (webp + responsive sizes)
- Pas de duplicates lourds (`pnpm exec source-map-explorer dist/assets/*.js`)

#### B. Lighthouse (20 pts)
- Performance ≥ 90 sur DashboardPage
- Accessibility ≥ 95 sur 5 pages principales
- Best Practices ≥ 90
- SEO ≥ 90

#### C. Backend (20 pts)
- N+1 detection : utiliser `barryvdh/laravel-debugbar` ou pest `assertDatabaseQueryCount`
- Eloquent eager loading via `with()` sur tous controllers index
- Index DB couvrent les filtres principaux (cf. spec 03 indexes)
- Materialized view `coverage_matrix_cells` refresh hourly via pg_cron
- pgbouncer transaction mode configuré

#### D. Workers (20 pts)
- Concurrence revue v1.1 (P0 audit) : 2/2/4/2/2/1/3
- Restart 500 jobs configuré
- Memory threshold preemptive shutdown (4.8 GB) configuré
- Pas de leak Chromium documenté

#### E. DB (20 pts)
- Partitionnement pg_partman sur tables hot (audit_logs, scraper_runs, llm_usage, proxy_usage_log)
- `idx_runs_dedup` confirmé (P0 audit) — déjà validé POC #5 en réel
- Stats VACUUM par-table tunées (`autovacuum_vacuum_scale_factor 0.05` sur companies, contacts, email_verifications)

**Livrable :** `_AUDIT/AUDIT-E2E-PHASE1-*/09_performance.md` (scoring /100).

### PHASE 10 — Documentation + DX (Sub-agent, 20 min)

#### A. README + setup (20 pts)
- README.md à jour avec instructions setup local 5 min
- Commande `docker-compose up` documentée
- Variables env documentées (`.env.example` exhaustif)
- Liens vers spec/00_INDEX.md

#### B. Documentation API (20 pts)
- OpenAPI Swagger UI accessible à `/docs`
- Tous les ~121 endpoints documentés avec paramètres + responses
- Auth Sanctum cookie expliquée
- Codes HTTP par cas documentés

#### C. Runbooks (20 pts)
- `infra/runbooks/restart-workers.md`
- `infra/runbooks/disk-full.md`
- `infra/runbooks/site-down-5xx.md`
- `infra/runbooks/restore-from-backup.md`
- `infra/runbooks/rotate-secrets.md`

#### D. Onboarding dev (20 pts)
- Doc `CONTRIBUTING.md` : conventions Git, branches, commits
- Doc `ARCHITECTURE.md` synthèse haut niveau (renvoie spec)
- Comments WHY (pas WHAT) sur sections complexes (LLM router, dedup, SSRF guard)

#### E. Reports (20 pts)
- `_REPORTS/PROGRESS.md` à jour : 12 sprints marqués done
- `_REPORTS/SPRINT_1_12_REPORT.md` synthèse complète
- Blockers documentés dans `_REPORTS/BLOCKER_*.md` si applicable

**Livrable :** `_AUDIT/AUDIT-E2E-PHASE1-*/10_docs_dx.md` (scoring /100).

### PHASE 11 — Cohérence inter-fichiers + détection régressions (Sub-agent, 30 min)

**Sub-agent type Explore.**

#### A. Cross-références (40 pts)
- Modèle Eloquent référencé dans une migration ? Présent dans `app/Models/` ?
- Route définie ? Controller method existe ? Tests présents ?
- Use case LLM listé dans `LlmUseCaseSeeder` ? Prompt template présent ? Méthode `LLMClient::complete($slug)` appelée quelque part ?
- Composant React utilisé dans une route ? Importé correctement ?
- Variable env `.env.example` utilisée dans le code ?

#### B. Détection code mort (15 pts)
- Routes définies jamais testées
- Méthodes service publiques jamais appelées
- Composants React jamais importés
- Migrations qui annulent les précédentes (DROP COLUMN puis ADD COLUMN identique)

#### C. Incohérences spec v1.1/v1.2 vs implémentation (25 pts)
- 6 catégories taille effectivement implémentées (artisan/commercant ajoutés vs 4 v1.0) ?
- Use case `classify_company_axion` mergé (vs `ia_maturity_scoring` + `axion_offer_match` séparés) ?
- Use case `fiche_quality_scoring` retiré ?
- `OwnerUserSeeder` sécurisé email williamsjullin@gmail.com ?
- Métriques business v1.1 présentes ?
- WCAG 2.2 (vs 2.1) ?
- i18next dès S1 ?
- Terraform module Hetzner ?
- Langfuse self-hosted ?

#### D. Anti-patterns détectés (20 pts)
- `dd()`, `dump()`, `console.log()` oubliés
- Magic numbers hardcodés (au lieu de constantes / config)
- TODO / FIXME / XXX dans le code
- Fonctions > 100 lignes
- Fichiers > 500 lignes
- Cyclomatic complexity > 10

**Livrable :** `_AUDIT/AUDIT-E2E-PHASE1-*/11_coherence_regressions.md` (scoring /100).

### PHASE 12 — Synthèse + verdict + plan d'action (45 min)

#### A. Calcul score global (/1000)

```
Phase 1 cohérence spec-code  : /100  × 1.0 = /100
Phase 2 backend Laravel       : /100  × 1.5 = /150
Phase 3 frontend React        : /100  × 1.0 = /100
Phase 4 workers Node          : /100  × 1.0 = /100
Phase 5 infra DevOps          : /100  × 1.0 = /100
Phase 6 sécurité              : /100  × 1.5 = /150  ← pondéré
Phase 7 conformité RGPD+AI    : /100  × 1.0 = /100
Phase 8 tests                 : /100  × 1.5 = /150  ← pondéré
Phase 9 performance           : /100  × 0.5 = /50
Phase 10 docs + DX            : /100  × 0.5 = /50
Phase 11 cohérence + régress  : /100  × 0.5 = /50
                                Total : /1100   (puis ramener /1000)
```

#### B. Verdict global

| Score /1000 | Verdict |
|-------------|---------|
| ≥ 950 | 🟢 **PROD READY** — aucun bloquant, livraison validée |
| 850-949 | 🟡 **PROD CONDITIONAL** — corrections P0 mineures, prod possible après quick fix |
| 700-849 | 🟠 **SPRINT CORRECTIF** — 1-2 semaines fix avant prod |
| 500-699 | 🔴 **REFONTE PARTIELLE** — modules entiers à reprendre |
| < 500 | ❌ **NO-GO** — implémentation insuffisante, restart partiel |

#### C. Patches P0 / P1 / P2 (prioritisés)

Format pour chaque patch :
```
P0-N — Titre court
Fichier(s) : path/to/file:lineXX
Problème : description précise
Correction : extrait code à appliquer
Effort : X heures
Risque si non corrigé : description
```

Cible : maximum 20 patches P0 (sinon on est en refonte), 30 P1, illimité P2.

#### D. Tests réels lancés

Avant verdict final, **lance toi-même les commandes critiques** :

```bash
# Backend
cd backend && composer install --no-progress
cd backend && composer test
cd backend && vendor/bin/phpstan analyse
cd backend && composer audit

# Frontend
cd frontend && pnpm install --frozen-lockfile
cd frontend && pnpm typecheck
cd frontend && pnpm lint
cd frontend && pnpm test
cd frontend && pnpm build
cd frontend && pnpm audit --audit-level=high

# Workers
cd workers && pnpm install --frozen-lockfile
cd workers && pnpm typecheck
cd workers && pnpm test

# Infra
docker-compose config  # parse OK
cd infra/terraform && terraform validate

# Smoke
docker-compose up -d
sleep 30
curl -fsS http://localhost/up
curl -fsS http://localhost:3000  # frontend
docker-compose down
```

**Si une commande échoue, marquer P0 et continuer.**

#### E. Critères GO PROD finaux

✅ **GO PROD** si TOUS les critères suivants sont remplis :
- Score global ≥ 950/1000
- 0 P0 ouvert
- ≤ 5 P1 ouverts
- Tous les tests CI verts (Pest + Vitest + Playwright + Trivy + Semgrep + composer audit + pnpm audit)
- Bundle frontend ≤ 500 KB gz
- Lighthouse ≥ 90 sur 5 pages principales
- A11y axe-core 0 violation critique
- PHPStan level 9 green
- Pas de secret en clair dans Git (grep audit)
- `docker-compose up` start sans erreur
- Pentest stub passe (commande `app:pentest-self-check`)
- DR drill réussi (RTO < 4h simulé)

#### F. Livrables finaux

Dossier `_AUDIT/AUDIT-E2E-PHASE1-YYYY-MM-DD/` :

```
00_VERDICT.md                          (verdict + score global + critères PROD)
01_spec_code_coherence.md              (/100 + lacunes)
02_backend_laravel.md                  (/100 + détails)
03_frontend_react.md                   (/100 + détails)
04_workers_mocks.md                    (/100 + détails)
05_infra_devops.md                     (/100 + détails)
06_security_forensic.md                (/100 + détails)
07_compliance_rgpd_ai_act.md           (/100 + détails)
08_tests.md                            (/100 + détails + commandes lancées)
09_performance.md                      (/100 + détails)
10_docs_dx.md                          (/100 + détails)
11_coherence_regressions.md            (/100 + détails)
PATCHES_P0.md                          (liste P0 prioritisés avec patches actionnables)
PATCHES_P1.md
PATCHES_P2.md
EXEC_SUMMARY_WILL.md                   (1 page TL;DR : verdict + 5 forces + 5 faiblesses + next steps)
MANIFEST.md                            (date, sha, méthodologie, agents utilisés)
```

#### G. Commit + push final

```bash
git add _AUDIT/
git commit -m "audit(e2e-phase1): verdict <score>/1000 <emoji> <verdict>

X P0 / Y P1 / Z P2 identifiés.
<top 3 forces>
<top 3 faiblesses>

Voir _AUDIT/AUDIT-E2E-PHASE1-YYYY-MM-DD/EXEC_SUMMARY_WILL.md"
git push origin main
git tag -a "audit-e2e-phase1-YYYY-MM-DD" -m "Score <X>/1000 — <verdict>"
git push origin --tags
```

#### H. Notification finale à l'utilisateur

Dans ton dernier message à l'utilisateur, affiche obligatoirement :

```
═══════════════════════════════════════════════════════════
AUDIT E2E PHASE 1 — VERDICT
═══════════════════════════════════════════════════════════

Score global : XXX / 1000  [emoji]
Verdict      : [PROD READY | PROD CONDITIONAL | SPRINT CORRECTIF | REFONTE | NO-GO]

📊 Scoring par phase :
  1. Cohérence spec ↔ code  : XX/100
  2. Backend Laravel        : XX/100
  3. Frontend React         : XX/100
  4. Workers Node + mocks   : XX/100
  5. Infra DevOps           : XX/100
  6. Sécurité (pondéré 1.5×): XX/100
  7. Conformité RGPD+AI Act : XX/100
  8. Tests (pondéré 1.5×)   : XX/100
  9. Performance            : XX/100
  10. Docs + DX             : XX/100
  11. Cohérence + régress.  : XX/100

🚨 Critiques :
  - P0 ouverts : X (cible 0)
  - P1 ouverts : Y (cible ≤ 5)
  - P2 ouverts : Z

✅ Tests réels lancés :
  - Pest backend         : <X> passed / 0 failed
  - Vitest frontend      : <X> passed / 0 failed
  - Vitest workers       : <X> passed / 0 failed
  - Playwright E2E       : <X> scenarios passed
  - PHPStan              : <level 9> 0 errors
  - composer audit       : 0 high/critical
  - pnpm audit           : 0 high
  - docker-compose up    : start OK
  - curl /up             : 200 OK
  - terraform validate   : OK
  - frontend build       : OK, bundle <X> KB gz

Top 3 forces :
  1. ...
  2. ...
  3. ...

Top 3 faiblesses :
  1. ...
  2. ...
  3. ...

🚀 Prochaine étape recommandée :
  [si GO PROD]      → Provisionner Hetzner + lancer POCs #1-4 réels + déploiement S12bis
  [si CONDITIONAL]  → Appliquer les <X> patches P0 (effort estimé : <Y>h)
  [si CORRECTIF]    → Sprint 13 correctif avant prod (1-2 semaines)
  [si REFONTE]      → Modules <X, Y, Z> à reprendre
  [si NO-GO]        → Refonte majeure, voir _AUDIT/.../EXEC_SUMMARY_WILL.md

Tag git créé : audit-e2e-phase1-YYYY-MM-DD
Rapports complets : _AUDIT/AUDIT-E2E-PHASE1-YYYY-MM-DD/
GitHub : https://github.com/will383842/axion-crm-pro/tree/main/_AUDIT
═══════════════════════════════════════════════════════════
```

## RAPPELS ULTRA-IMPORTANTS

- **N'écris JAMAIS de code applicatif.** Tu auditez, tu ne corriges pas. (Sauf pour fichiers `_AUDIT/`)
- **N'altère JAMAIS le code source** (`backend/`, `frontend/`, `workers/`, `infra/`, `spec/`, `poc/`).
- **Pas de blabla.** Le rapport est dense, factuel, structuré.
- **Tu cites toujours** : `path:line` + extrait code.
- **Tu lances réellement** les commandes (composer test, pnpm test, docker-compose config, etc.).
- **Sous-agents //** dès que possible pour accélérer l'audit (10 sub-agents en // OK).
- **Si tu doutes** sur une partie de la spec, retourne au fichier spec original et cite la section exacte.
- **Honnêteté brutale.** Si le code est faible, tu le dis. Si le code est excellent, tu le dis aussi (sobrement).

## DÉMARRAGE

1. **Crée le dossier audit :** `mkdir -p _AUDIT/AUDIT-E2E-PHASE1-$(date +%Y-%m-%d)`
2. **Lis les 29 documents** dans l'ordre indiqué (~45 min de lecture)
3. **Lance Phase 0** (setup + reality check)
4. **Lance Phases 1-11** avec sub-agents // dès que possible
5. **Compile Phase 12** (synthèse + verdict)
6. **Commit + push + tag** final

**Pas de validation entre phases. Pas de blabla. Audit complet en autopilot.**

GO.

</prompt>
