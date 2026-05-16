# PROMPT AUTOPILOT — Sprint 1 → S12 (implémentation bout-en-bout)

> **À copier-coller dans une nouvelle conversation Claude Code (CWD = `C:\Users\willi\Documents\Projets\Axion-CRM-Pro`).**
> Durée estimée autopilot : 10-15 heures de travail Claude réparties sur plusieurs sessions (Claude Code peut continuer après compaction automatique du contexte).
> Sortie : codebase Phase 1 complète + 100 % testée mocks + image Docker prête à déployer.

---

## Comment l'utiliser

1. **Sauvegarde** : assure-toi que le repo est clean et pushé (`git status` vide)
2. Ouvre une **nouvelle conversation** Claude Code dans `C:\Users\willi\Documents\Projets\Axion-CRM-Pro`
3. Choisis le **modèle Opus 4.7** (1M tokens) via `/model`
4. Copie tout le contenu entre `<prompt>` et `</prompt>` (sans les balises)
5. Colle et envoie
6. Laisse tourner en autonomie (vérifie commits + push toutes les 30 min)

---

<prompt>

# MISSION — Implémentation Axion CRM Pro Phase 1 (Sprint 1 → S12) en AUTOPILOT TOTAL

Tu es **Senior Full-Stack Engineer** sur le projet Axion CRM Pro. Tu vas coder l'intégralité de la Phase 1 (~14 semaines de dev humain) en autopilot, **avec mocks pour les services externes**.

## CONTEXTE — LIS D'ABORD ÇA

**Repo local :** `C:\Users\willi\Documents\Projets\Axion-CRM-Pro` (déjà cloné, déjà pushé sur GitHub).
**Repo GitHub :** https://github.com/will383842/axion-crm-pro (public, `main` tracking).

**Documents à lire en premier dans l'ordre (lecture obligatoire avant tout code) :**

1. `spec/00_INDEX.md` — sommaire général
2. `spec/01_thinking_executive_naming.md` — vision + 8 risques + 8 décisions
3. `spec/02_architecture_infra.md` — stack technique précise
4. `spec/03_db_schema_phase1.md` — 66 tables Phase 1 (SQL exécutable PostgreSQL 16)
5. `spec/04_db_schema_phase2_scaffold.md` — 35 tables Phase 2 scaffold
6. `spec/05_scrapers_14_sources.md` — 14 sources + Google Search Wrapper + Direction Finder
7. `spec/06_email_finder_validation.md` — patterns + cascade SMTP N1-N5
8. `spec/07_llm_router.md` — LLMClient + 5 providers + 9 use cases
9. `spec/08_waterfall_enrichissement_classification.md` — state machine 10 étapes
10. `spec/13_ui_admin_phase1.md` — 17 pages Phase 1 + 5 pages Phase 2 scaffold
11. `spec/14_api_routes_laravel.md` — ~121 endpoints REST
12. `spec/15_auth_multitenant_rbac.md` — Sanctum SPA + 2FA + RLS + RBAC
13. `spec/17_rgpd_aiact_owasp.md` — DPIA + SSRF + sous-processeurs LLM
14. `spec/18_deploiement_hetzner.md` — docker-compose + Dockerfiles + GH Actions
15. `spec/19_queues_workers_playwright.md` — Horizon + workers + bridge Redis
16. `spec/23_interfaces_phase2_execution_pack.md` § B.4 — 12 prompts détaillés Sprint 1-12
17. `spec/24_frontend_design_system.md` — design tokens + responsive + UX patterns
18. `MOCKS-STRATEGY.md` — convention mocks à appliquer
19. `poc/SYNTHESIS.md` — POC #5 dedup déjà VALIDÉ (p95 35 ms / 10M rows), 4 autres à venir
20. `TODO.md` — état global du reste à faire

## RÈGLES DE TRAVAIL — AUTOPILOT TOTAL

1. **Travaille en autonomie totale.** Ne demande PAS de validation entre étapes. Enchaîne.
2. **Commits Conventional Commits** + push après chaque sous-étape (`feat:`, `fix:`, `test:`, `docs:`, `chore:`).
3. **Pas de --no-verify, pas de force-push main**, pas de commits sur tags.
4. **Mocks par défaut** pour TOUS les services externes (cf. `MOCKS-STRATEGY.md`). Variable maître `MOCK_MODE=true` dans `.env`.
5. **Tests à chaque étape** : Pest (PHP) + Vitest (frontend) + Playwright E2E. **Toujours verts avant de continuer**.
6. **TypeScript strict** + **PHPStan level max** + **ESLint strict**.
7. **Documentation OpenAPI** auto-générée pour toutes les routes.
8. **A11y WCAG 2.2 AA** appliqué via axe-core CI.
9. **Doc inline minimale** — code self-documenting, commentaires uniquement pour WHY non-évident.
10. **Pas de fichiers .md inutiles** (sauf ceux explicitement demandés par la spec).
11. **Pas de TODO dans le code** ; tout commit doit livrer du code fonctionnel.
12. **Sécurité** : applique strictement spec/17_rgpd_aiact_owasp.md (SSRF guard, prompt injection sanitization, audit hash chain, RLS).

## CE QUE TU PEUX FAIRE EN AUTOPILOT

✅ Écrire tout le code Laravel 12 + PHP 8.3 (~30 000 lignes)
✅ Écrire tout le code React 19 + TypeScript 5.6 + Tailwind 4 (~15 000 lignes)
✅ Écrire tout le code Node 22 + Playwright workers (~8 000 lignes) — mode mock
✅ Migrations Laravel pour les 66 tables Phase 1 + 35 Phase 2 scaffold
✅ Mocks pour 14 sources scraping + LLM + SMTP + captcha
✅ Tests Pest + Vitest + Playwright E2E (~500 tests cible)
✅ Docker Compose multi-services + Dockerfiles multi-stage
✅ GitHub Actions CI workflows
✅ Documentation OpenAPI auto-générée
✅ Commits + push à chaque sous-étape

## CE QUE TU NE FAIS PAS

❌ Provisionner Hetzner (Will fera)
❌ Acheter domaine / configurer Cloudflare (Will fera)
❌ Souscrire Doppler/Anthropic/IPRoyal/Webshare/2captcha (Will fera quand prêt)
❌ Signer DPAs juridiques (action humaine)
❌ Faire un pentest (Will manuel S12)
❌ Lancer en production réelle (Will déclenchera)
❌ Supprimer / écraser le travail existant sans accord (spec, POCs, .git)

## STRUCTURE LIVRABLE FINALE

À la fin de Sprint 12, le repo doit contenir :

```
axion-crm-pro/
├── .git/
├── .github/workflows/                  (CI + deploy staging/prod)
├── .gitignore                          (déjà OK)
├── README.md                           (déjà OK, à enrichir avec setup local)
├── MOCKS-STRATEGY.md                   (déjà OK)
├── TODO.md                             (à maintenir à jour)
├── docker-compose.yml                  (NOUVEAU — orchestration locale)
├── docker-compose.prod.yml             (NOUVEAU)
├── infra/                              (NOUVEAU)
│   ├── terraform/                      (Hetzner module)
│   ├── caddy/                          (config reverse proxy)
│   ├── postgres/                       (init scripts)
│   ├── redis/                          (configs)
│   └── monitoring/                     (prometheus, grafana, loki configs)
├── backend/                            (NOUVEAU — Laravel 12)
│   ├── app/
│   │   ├── Console/Commands/
│   │   ├── Contracts/                  (LLMClient, ProxyProvider, etc.)
│   │   ├── Data/                       (Spatie DTOs)
│   │   ├── Domain/                     (Bounded contexts : Scraping, Companies, Enrichment, LLM, RGPD)
│   │   ├── Http/Controllers/Api/
│   │   ├── Http/Middleware/            (SetCurrentWorkspace, EnforceFirstLoginSetup, etc.)
│   │   ├── Jobs/                       (Horizon queues)
│   │   ├── Models/                     (Eloquent)
│   │   ├── Policies/
│   │   ├── Providers/
│   │   ├── Services/                   (LLMRouterService, DeduplicationService, etc.)
│   │   └── States/                     (Spatie state machines)
│   ├── bootstrap/
│   ├── config/
│   ├── database/
│   │   ├── factories/
│   │   ├── migrations/                 (~98 fichiers)
│   │   └── seeders/
│   ├── routes/
│   ├── tests/
│   │   ├── Feature/
│   │   ├── Unit/
│   │   └── fixtures/                   (mocks data)
│   ├── composer.json
│   └── ...
├── frontend/                           (NOUVEAU — React 19 + Vite 6)
│   ├── src/
│   │   ├── app/                        (routes TanStack Router)
│   │   ├── components/
│   │   │   ├── ui/                     (shadcn/ui)
│   │   │   └── brand/
│   │   ├── features/
│   │   │   ├── auth/
│   │   │   ├── companies/
│   │   │   ├── contacts/
│   │   │   ├── coverage/               (carte MapLibre)
│   │   │   ├── scraping/
│   │   │   ├── llm/
│   │   │   ├── rgpd/
│   │   │   └── phase2-scaffold/        (5 pages placeholder)
│   │   ├── hooks/
│   │   ├── lib/
│   │   ├── locales/                    (i18next fr/en)
│   │   └── styles/                     (Tailwind 4 @theme + tokens)
│   ├── tests/
│   ├── package.json
│   └── ...
├── workers/                            (NOUVEAU — Node 22 + Playwright)
│   ├── src/
│   │   ├── scrapers/                   (14 sources + Direction Finder)
│   │   ├── mocks/                      (MockGoogleMapsScraper, etc.)
│   │   ├── bridge/                     (Redis Laravel ↔ Node)
│   │   ├── utils/
│   │   └── main.ts                     (router selon WORKER_TYPE)
│   ├── tests/
│   ├── package.json
│   └── ...
├── spec/                               (déjà OK, ne pas toucher)
├── poc/                                (déjà OK, ne pas toucher)
└── _PROMPTS/
    └── PROMPT_AUTOPILOT_SPRINT_1_TO_12.md   (CE FICHIER)
```

## ROADMAP — 12 SPRINTS

Suis exactement la séquence du `spec/23_interfaces_phase2_execution_pack.md` § B.4 (12 prompts), adaptée à l'autopilot mocks :

### Sprint 1 — Bootstrap projet (3-4h)
- Skeleton Laravel 12 dans `backend/`
- Skeleton React 19 + Vite 6 + Tailwind 4 dans `frontend/`
- Skeleton Node 22 + Playwright + TypeScript dans `workers/`
- `docker-compose.yml` maître (Postgres + Redis + Caddy + Laravel + React + Workers)
- `Dockerfile.laravel`, `Dockerfile.frontend`, `Dockerfile.worker` multi-stage
- `.env.example` exhaustif avec `MOCK_MODE=true` par défaut
- GitHub Actions CI (lint + typecheck + tests)
- Premier commit + push

**Done :** `docker-compose up` démarre tous les services sans erreur. `https://api.localhost/up` retourne 200.

### Sprint 2 — DB migrations + RLS + seeders référentiels (4h)
- 98 migrations selon spec 03 + 04 ordre § 14
- Seeders : countries, regions (13 INSEE), departments (101), cities (~2150), naf_*, legal_forms, effectif_ranges (16 codes incl. NN), axion_offer_targets, strategic_keywords, search_engines, user_agents (50+), naf_artisanat_flags
- RLS policies sur toutes les tables workspace_id
- Functions SQL : `normalize_name`, `compute_size_category`, `recompute_company_quality_score` + triggers
- Materialized view `coverage_matrix_cells` + pg_cron schedule
- `OwnerUserSeeder` sécurisé (lit `OWNER_INITIAL_*` env vars, HIBP check, jamais en clair)

**Done :** `php artisan migrate:fresh --seed` exécute sans erreur. Toutes tables présentes + RLS effectif.

### Sprint 3 — Auth + RBAC + Multi-tenant + Audit (5h)
- Sanctum SPA cookie + TOTP 2FA + Magic Link + Password reset
- Spatie Permission (4 rôles : owner, admin, operator, viewer)
- Middleware `SetCurrentWorkspace` + RLS PostgreSQL session var
- Audit log hash chain (table partitionnée mois)
- Policies Eloquent pour Company, Contact, etc.
- React pages : Login, TwoFactor, MagicLinkRequest, PasswordReset
- Tests Pest auth + E2E Playwright (12+ tests)

### Sprint 4 — Patterns transversaux (LLM Router + Proxies + Dedup + Rotations) (6h)
- `LLMClient` interface + `LLMRouterService` + 5 providers + `MockLLMClient` avec fixtures
- `ProxyProvider` interface + `WebshareProvider` + `IPRoyalProvider` + `MockProxyProvider`
- `DeduplicationService` 6 niveaux + tests 100% coverage
- `WeightedRoundRobin` + `ZoneRotator` + `SearchEngineRotator`
- UI admin "LLM Router" (4 tabs) + "Proxy Providers" + "Rotations"
- 9 use cases LLM seedés (Phase 1 v1.1 mergés)
- Prompt templates v1 + Twig renderer + sanitizeExternalInputs (anti prompt-injection)

### Sprint 5 — Sources officielles INSEE/annuaire-entreprises/BODACC + Waterfall (6h)
- `InseeSirenScraper` + `AnnuaireEntreprisesScraper` + `BodaccScraper` (PHP, sans Playwright)
- `MockInseeClient` + `MockAnnuaireEntreprisesClient` + `MockBodaccClient` avec fixtures
- `WaterfallOrchestrator` Spatie state machine (étapes 1+2+9 actives)
- `ZoneRotator` cooldown 24h + advisory lock
- API endpoints `/api/v1/companies/{c}/enrich`
- UI page Companies (liste basique)

### Sprint 6 — Workers Playwright Google Maps + Pages Jaunes + Sites web (8h)
- Workers Node : `worker-google-maps`, `worker-pages-jaunes`, `worker-sites-web`
- Mocks : `MockGoogleMapsScraper`, `MockPagesJaunesScraper`, `MockWebsiteScraper` (lecture HTML fixtures)
- Bridge Redis Laravel ↔ Node (BullMQ + Horizon)
- Endpoint interne `/internal/scraper-result`
- Email extraction exhaustive + classification 4 catégories + détection pattern
- Use case LLM `extract_team_from_page` (avec mocks)
- UI page "Scraper Runs"

### Sprint 7 — Google Search Wrapper + Direction Finder + Sources secondaires (10h)
- Workers : `worker-google-search`, `worker-direction-finder`
- Mocks complets : `MockSearchEngine` (3 moteurs) + `MockDirectionFinder` (20 ETI fixtures)
- pdf-parse integration (réel — pas mockable utile)
- Workers : `worker-france-travail`, `worker-mesri`, `worker-crunchbase`, `worker-infogreffe`, `worker-societe-com`, `worker-social-light`
- Géocodage BAN dans waterfall (étape 8)
- Étape 5+6+9+10 du waterfall actives

### Sprint 8 — Email finder + Validation SMTP cascade (5h)
- `EmailFinderService` complet (18 patterns + génération candidats)
- `SmtpProber` interface + `RealSmtpProber` + `MockSmtpProber` (retourne status selon email_status_map.json fixture)
- `CatchAllDetector` cache 7j
- Disposable list embarquée
- Job hourly `check_blacklists` (mock dans MOCK_MODE)
- Étape 7 waterfall active

### Sprint 9 — Carte France interactive (5h)
- Import IGN AdminExpress COG 2026 (script artisan)
- Génération tuiles MVT tippecanoe (commit dans `frontend/public/tiles/admin/`)
- Composant React `<FranceCoverageMap />` 3 modes (Visu/Search/Action)
- Endpoint `/api/v1/coverage` + cache Redis 60s
- Sub-composants `<SearchMode />` + `<ActionMode />`

### Sprint 10 — Classification LLM + UI complète 17 pages (7h)
- `ClassifierService` : 4 use cases LLM (classify_company_axion mergé + auto_tag + extract_strategic_keywords)
- Recompute `companies.quality_score` via SQL function trigger
- `AutoTagApplier` (rules DSL JSONB)
- UI : implémente toutes les 17 pages Phase 1 selon spec 13 + design system spec 24
- Composants partagés : QualityBadge, SizeCategoryBadge 6 catégories, DiscoverySourceBadge, PrioritySelect, NafSelector
- `<EmptyState />`, `<CompaniesTableSkeleton />`, error boundaries 3 niveaux
- Toast patterns + form patterns react-hook-form + zod
- Responsive full mobile→desktop
- Onboarding tour react-joyride 1er login
- Recherche globale ⌘K cmdk
- Notifications header 🔔 + WebSocket Laravel Reverb

### Sprint 11 — Scaffold Phase 2 + RGPD UI + Monitoring (5h)
- 5 pages Phase 2 stubs (Campaigns, Cold Email, LinkedIn, CRM, Analytics)
- Routes API Phase 2 → 501 avec types Spatie Data
- Triggers SQL Phase 2 créés (firent jamais Phase 1)
- UI RGPD requests complète + GdprErasureService (transaction multi-tables atomique)
- GdprPortabilityService (export JSON encrypted)
- AI Act register UI
- docker-compose.observability.yml (Prometheus + Grafana + Loki + Tempo + GlitchTip + Uptime Kuma)
- 10 dashboards Grafana provisionnés (JSON Git)
- Alertmanager rules + Slack/Telegram routing (mock dans MOCK_MODE)
- Anomaly detector job 15 min
- OpenTelemetry SDK PHP + Node + Browser instrumenté
- Langfuse self-hosted (mock dans MOCK_MODE)

### Sprint 12 — Tests E2E + Doc + Polish (6h)
- 50+ tests E2E Playwright (auth + CRUD entreprises + scraping mock + RGPD + map)
- Tests load k6 (100 req/s API tient)
- Documentation auto-générée OpenAPI Swagger UI à `/docs`
- Runbooks `/infra/runbooks/` (restart workers, disk plein, site down, restore DR, rotation secrets)
- Penetration test stub (commande artisan `app:pentest-self-check` qui lance ZAP local + check headers + check SSRF)
- DR drill script (`infra/scripts/dr-drill.sh`)
- Tag git `phase1-mocks-complete-2026-XX-XX`

## CRITÈRES "DONE" GLOBAUX (à la fin)

✅ `docker-compose up` démarre tous les services sans erreur
✅ `https://api.localhost/up` → 200 OK
✅ `https://app.localhost` affiche page Login fonctionnelle
✅ `cd backend && composer test` → tous tests Pest verts
✅ `cd frontend && pnpm test` → tous tests Vitest verts
✅ `cd workers && pnpm test` → tous tests Vitest verts
✅ Tests E2E Playwright 50+ scénarios verts (mode mock)
✅ Lighthouse score frontend ≥ 90 sur les 5 pages principales
✅ A11y CI axe-core 0 violation critical
✅ PHPStan level 9 vert
✅ TypeScript strict vert (`pnpm typecheck`)
✅ ESLint vert
✅ Composer audit + pnpm audit 0 critical
✅ `php artisan migrate:fresh --seed` exécute en < 30s
✅ Bundle frontend < 500 KB gz (route principale)
✅ Documentation OpenAPI à `/docs`
✅ Tous les commits Conventional + pushés sur main
✅ Tag git `phase1-mocks-complete-2026-XX-XX` créé

## RAPPORTING AU FUR ET À MESURE

Après chaque sprint, ajoute une ligne dans `TODO.md` :

```
| Sprint X | YYYY-MM-DD HH:MM | ✅ Done | Commits xxxx..yyyy | Tests : X passed / 0 failed |
```

Et un commit `chore(sprint X): done — résumé court`.

À la fin de Sprint 12, fais une synthèse complète dans un fichier `_REPORTS/SPRINT_1_12_REPORT.md`.

## EN CAS DE BLOCAGE TECHNIQUE

Si tu détectes un blocage qui requiert une décision humaine :

1. Documente clairement dans un fichier `_REPORTS/BLOCKER_<sprint>_<topic>.md`
2. Implémente l'option par défaut indiquée dans la spec (cf. STOP & ASK § dans chaque fichier spec)
3. Marque le blocker dans `TODO.md`
4. Continue avec le sprint suivant sans bloquer le pipeline

**Ne demande PAS de validation. Avance.**

## SÉCURITÉ — INVIOLABLE

- **AUCUN secret en clair dans le code ou Git.** Tout via `.env` (gitignored) ou Doppler placeholder.
- **AUCUN appel réseau réel** par défaut. `MOCK_MODE=true` partout.
- **SSRF guard obligatoire** sur toute URL externe (cf. spec 17 § A10).
- **Prompt injection sanitization** sur tous les inputs LLM externes (préfixe `ext_` variables).
- **RLS PostgreSQL** activée sur toutes les tables workspace_id.
- **2FA TOTP obligatoire** pour tous les users (middleware `EnforceFirstLoginSetup`).
- **Hash chain audit log** vérifiable.
- **Cookies HttpOnly + Secure + SameSite=lax**.

## DÉMARRE MAINTENANT

1. Lis les 20 documents de la spec dans l'ordre indiqué.
2. Crée `_REPORTS/PROGRESS.md` avec un tableau des 12 sprints.
3. Démarre Sprint 1 — Bootstrap projet.
4. Marque le sprint completed dans `_REPORTS/PROGRESS.md` après commit + push.
5. Enchaîne Sprint 2, 3, ... jusqu'à Sprint 12.

**Pas de blabla. Code, commit, push. Si tu doutes, applique le défaut spec et continue.**

GO.

</prompt>
