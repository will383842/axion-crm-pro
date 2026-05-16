# Changelog — Axion CRM Pro

Format : [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) — SemVer.

## [0.1.0-mocks-complete] — 2026-05-17

### Added
- Sprint 1 — Bootstrap : docker-compose maître + 3 Dockerfiles multi-stage + Caddy + .env.example
- Sprint 1 — Backend Laravel 12 + 14 contracts + 10 DTOs + 3 middlewares + 5 providers + Mock services
- Sprint 1 — Frontend React 19 + Vite 6 + Tailwind 4 + TanStack Router/Query + i18n FR/EN
- Sprint 1 — Workers Node 22 + Playwright 1.49 + BullMQ + bridge Redis HMAC
- Sprint 1 — GitHub Actions CI (4 jobs : backend + frontend + workers + security)
- Sprint 2 — 9 migrations Laravel Phase 1 + Phase 2 scaffold + RLS sur 30 tables + 11 seeders fondateurs
- Sprint 2 — 8 artisan commands (ign:import-admin-express, naf:import, coverage:refresh-matrix,
  audit:verify-chain, retention:purge, anomaly:detect, blacklists:check, signals:nightly-scan)
- Sprint 2 — Triggers SQL recompute_quality_score + updated_at sur 13 tables
- Sprint 3 — Auth réel : login Sanctum SPA + 2FA TOTP + magic-link + password reset
  + AuditHashChain crypto
- Sprint 4 — LLM Router 5 providers HTTP (Anthropic/OpenAI/Mistral/Groq/Together) +
  Webshare/IPRoyal proxies + DeduplicationService 6 niveaux + Rotations WRR/Zone/Search
- Sprint 5 — Sources HTTP réelles (INSEE Sirene V3, AnnuaireEntreprises, BODACC, BAN,
  France Travail) + WaterfallOrchestrator 10 étapes + EnrichCompanyJob / DispatchScrapeJob
- Sprint 6 + 7 — Workers Playwright réels Google Maps + Pages Jaunes + Sites web + Google Search
  Wrapper + Direction Finder (16 titles C-level)
- Sprint 8 — EmailFinderService 18 patterns + RealSmtpProber cascade N1-N5
- Sprint 9 — Carte France MapLibre + CoverageController 3 niveaux + LaunchZoneScrapingJob
- Sprint 10 — ClassifierService 4 use cases LLM + AutoTagApplier DSL JSONB +
  composants UI partagés (QualityBadge, SizeCategoryBadge, EmptyState, ErrorBoundary, Skeleton)
  + CompaniesListPage virtualisée + filters
- Sprint 11 — GdprErasureService art.17 + GdprPortabilityService art.20 + stack observabilité
  (Prometheus + Alertmanager + Grafana + Loki + Promtail + Tempo + GlitchTip + Uptime Kuma)
  + 8 alerts business + infra
- Sprint 12 — 5 specs Playwright E2E + a11y axe-core + 5 runbooks + DR drill script +
  PentestSelfCheck OWASP + OpenAPI Swagger + k6 load test

### Security
- Audit hash chain sha256 vérifiable (`php artisan audit:verify-chain`)
- RLS PostgreSQL sur 30 tables workspace-scoped
- SsrfGuard avec DENY_HOSTS + DENY_CIDR (AWS/GCP metadata, RFC 1918, link-local)
- Headers CSP strict + HSTS preload + COOP/CORP + Permissions-Policy
- Sanctum SPA cookie HttpOnly + Secure + SameSite=lax
- TOTP 2FA RFC 6238 obligatoire (EnforceFirstLoginSetup)
- RateLimiter 5/min login, 3/IP/10min magic-link
- Anti prompt-injection sanitize `ext_` prefix variables
- HMAC sha256 X-Worker-Signature endpoint /internal/scraper-result

### Compliance
- RGPD art. 6 (intérêt légitime B2B)
- RGPD art. 15-22 (accès / portabilité / effacement / rectification / opposition)
- RGPD art. 30 (registre traitements via audit_logs)
- AI Act register (table ai_act_register, profilage documenté)
- OWASP Top 10 checklist via `php artisan app:pentest-self-check`

### Notes
- Mode `MOCK_MODE=true` par défaut — aucun coût provider tant que non basculé en réel
- Cible : 200 000 entreprises/mois M1, 1 M/mois Année 1
- ~275-345 €/mois Phase 1 prod (cf. spec/21)
