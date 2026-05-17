# Patches P1 — Qualité prod (à fixer post-P0)

## P1-1 — 10/17 pages frontend sont stubs PageShell
**Effort :** 20-30 h (2-3 jours/page complète)
Liste : ContactsList, CompanyDetail, Dashboard, Users, Settings, LlmRouter, ProxyProviders,
Rotations, RgpdRequests, AiActRegister, AuditLogs (-1 déjà OK).

## P1-2 — Horizon supervisors 2 → 7
**Fichier :** `backend/config/horizon.php`
Spec/19 § 9 : 7 supervisors (default, scraping, email, llm, audit, coverage, signals).

## P1-3 — Dashboards Grafana 1 → 10
**Fichier :** `infra/monitoring/grafana/dashboards/`
Ajouter : scraping-by-source, llm-cost-workspace, dedup-matrix, coverage-france,
rgpd-workflow, queues-horizon, db-postgres, workers-playwright, business-funnel.

## P1-4 — CI workflows 1 → 5
Ajouter : `deploy-staging.yml`, `deploy-prod.yml`, `a11y.yml`, `security.yml`.

## P1-5 — Terraform Hetzner module
Spec/18. `infra/terraform/main.tf` avec 7 servers + vSwitch + firewall + backend S3 OBS.

## P1-6 — Fixtures 5 → 60 (≥20/service)
**Fichier :** `backend/tests/fixtures/`
Multiplier × 12 pour LLM (9 use cases × 5 variantes), INSEE (10 SIRENs réels),
Annuaire (10 entreprises avec dirigeants), Google Maps (10 HTML), SMTP (50 emails labellés).

## P1-7 — Tests Vitest frontend 1 → 80
Tests composants : QualityBadge, SizeCategoryBadge × 6 catégories, EmptyState, ErrorBoundary
3 niveaux, Skeleton, PageShell, FormField (à créer), FranceCoverageMap mock MapLibre.

## P1-8 — Playwright E2E 16 → 50 scénarios
Login flow complet 2FA, onboarding tour, recherche ⌘K, saved views, RGPD erasure 5 étapes,
Direction Finder 1 ETI mockée, mobile viewport tests.

## P1-9 — Migrations Phase 2 11 → 35 tables
**Fichier :** nouvelle migration `000010_phase2_full_scaffold.php`
Tables manquantes : email_events, unsubscribes, dnc_lists, linkedin_invitations,
linkedin_sequences, crm_pipelines, crm_lost_reasons, analytics_funnels, analytics_cohorts, etc.

## P1-10 — OpenAPI annotations endpoints
**Fichier :** ~80 routes dans controllers
Ajouter `@OA\Get/@OA\Post(path, tags, parameters, responses)` sur chaque méthode.

## P1-11 — FormField composant frontend
Spec/24 § 7. Wrapper react-hook-form + zod + label + helpText + error.

## P1-12 — Dark mode frontend
Toggle dans Header. Variables CSS @theme `dark:` Tailwind 4.

## P1-13 — Anonymization IPs > 30j
`backend/app/Console/Commands/AnonymizeOldIps.php`. Schedule daily.

## P1-14 — Email finder waterfall step 7 (déjà P0-3 partial)
Branchage complet avec retry + cache validation 30j.

## P1-15 — Lazy-load MapLibre dynamic import
**Fichier :** `frontend/src/features/coverage/CoveragePage.tsx`
```tsx
const FranceCoverageMap = lazy(() => import('./FranceCoverageMap'));
```

## P1-16 — pgbouncer transaction mode
Pour scale Horizon workers concurrence.

## P1-17 — pg_partman audit_logs/scraper_runs/llm_usage
Réactiver partitioning retiré pour MVP.

## P1-18 — Dependabot config
`.github/dependabot.yml`.

## P1-19 — Langfuse self-hosted
`docker-compose.observability.yml` ajout service.

## P1-20 — DPIA document
`spec/17_rgpd_aiact_owasp.md` § DPIA — rédiger document conforme CNIL.

**Effort total P1 :** ~80-120 h (2-3 sprints).
