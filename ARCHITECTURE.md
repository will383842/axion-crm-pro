# Architecture — Axion CRM Pro

> Synthèse haut niveau. **Source de vérité détaillée :** `spec/00_INDEX.md` + 25 fichiers spec.

## Vue 30 secondes

```
                          ┌─────────────────┐
        Internet ───────▶│   Cloudflare    │ (CDN + WAF + DDoS)
                          └────────┬────────┘
                                   ▼
                          ┌─────────────────┐
                          │  Caddy 2 (edge) │ (TLS 1.3, HSTS preload, CSP strict)
                          └────────┬────────┘
                  ┌────────────────┼────────────────┐
                  ▼                ▼                ▼
         ┌────────────┐    ┌────────────┐    ┌────────────┐
         │ Nginx SPA  │    │ Laravel 12 │    │ Reverb WS  │
         │ React 19   │    │ PHP-FPM 8.3│    │ broadcast  │
         └────────────┘    └─────┬──────┘    └────────────┘
                                  ▼
                  ┌────────────────────────────┐
                  ▼                ▼            ▼
         ┌────────────┐  ┌──────────────┐  ┌─────────────┐
         │ Postgres16 │  │ Redis (3 DB) │  │ Horizon     │
         │ pg_trgm    │  │ cache+queue  │  │ supervisor  │
         │ postgis    │  │ session      │  │             │
         │ pgvector   │  └──────┬───────┘  └─────────────┘
         │ pg_partman │         │
         │ RLS 30 tbl │         ▼ axion:scrape:*
         └────────────┘  ┌──────────────────┐
                         │ Node Workers ×N  │ (Playwright stealth + BRPOP)
                         │ 5 scrapers réels │
                         └──────────────────┘
```

## Bounded contexts

| Context | Path backend | Responsabilité |
|---------|--------------|----------------|
| **Auth** | `app/Services/Auth/`, `app/Http/Controllers/Api/Auth/` | Sanctum SPA, 2FA TOTP, magic-link, password reset |
| **LLM** | `app/Services/LLM/`, `app/Contracts/LLMClient` | Router 5 providers + fallback chain + cost cap + idempotency cache |
| **Dedup** | `app/Services/Dedup/DeduplicationService` | Anti-doublon 6 niveaux (SIREN, contact hash, scrape TTL, zone cooldown, email cache, opt-out global) |
| **Waterfall** | `app/Services/Waterfall/WaterfallOrchestrator` | State machine 10 étapes : INSEE → Annuaire → BODACC → Maps/PJ/Web/Search async → Email finder → BAN → FT → Classify |
| **Scraping** | `app/Services/Scraping/`, `app/Services/Insee|Annuaire|Bodacc|Ban|FranceTravail/` | HTTP clients API officielles (PHP) + bridge BullMQ → workers Playwright (Node) |
| **Rgpd** | `app/Services/Rgpd/`, `app/Http/Controllers/Api/RgpdRequestsController` | Erasure art.17 atomique + Portability art.20 chiffré + opt-out global |
| **Audit** | `app/Services/Audit/AuditHashChain`, middleware `AuditHashChainLogger` | Chaîne SHA-256 append-only vérifiable |
| **Classification** | `app/Services/Classification/ClassifierService` + `AutoTagApplier` | 4 use cases LLM séquentiels + DSL rules JSONB |

## Stratégie mocks

Toute interface qui appelle un service externe a **2 implémentations** injectables via
`MockServicesProvider` (DI conditional par env var `MOCK_<SERVICE>`). Voir `MOCKS-STRATEGY.md`.

Pour basculer en production réelle : `MOCK_MODE=false` + credentials providers. Aucun
changement de code requis.

## Sécurité par défaut

- **SSRF** : `SsrfGuard.php` + `ssrf-guard.ts` (Node) bloquent RFC 1918, link-local, AWS/GCP metadata
- **CSP** : strict (no `unsafe-inline` en prod) + COOP/CORP + HSTS preload 12 mois
- **2FA** : TOTP RFC 6238 obligatoire (middleware `EnforceFirstLoginSetup`)
- **Sessions** : HttpOnly + Secure + SameSite=lax + encrypted
- **RLS** : 30+ tables workspace-scoped, policy `current_setting('app.current_workspace_id')`
- **Audit** : chaîne SHA-256 vérifiable via `php artisan audit:verify-chain`
- **Anti prompt-injection** : sanitize `ext_*` variables côté LLM
- **Rate limiting** : login 5/min, magic-link 3/IP/10min, API 60/min

## RGPD + AI Act

- **art. 6.1.f** intérêt légitime B2B documenté
- **art. 15-22** workflow `RgpdRequestsController` + UI dédiée
- **art. 17** `GdprErasureService` transaction multi-tables atomique + opt-out cascade
- **art. 20** `GdprPortabilityService` export JSON chiffré AES-256 TTL 7j
- **AI Act UE 2024/1689** : table `ai_act_register` + risk_class limited + human_oversight systematic

## Observabilité

| Composant | Rôle | Path config |
|-----------|------|-------------|
| Prometheus | metrics scrape (6 jobs) + alerts (8 rules) | `infra/monitoring/prometheus/` |
| Grafana | 8 dashboards provisioned | `infra/monitoring/grafana/dashboards/` |
| Loki + Promtail | logs centralisés retention 30j | `infra/monitoring/loki/` |
| Tempo | traces OTLP retention 7j | `infra/monitoring/tempo/` |
| Alertmanager | routing severity → Slack/Telegram | `infra/monitoring/alertmanager/` |
| GlitchTip | error tracking (alt Sentry) | docker-compose.observability.yml |
| Uptime Kuma | probes externes | docker-compose.observability.yml |

## Infrastructure

- **Cloud** : Hetzner Frankfurt fsn1 (UE/RGPD) via Terraform module
- **Orchestration** : docker-compose maître + Coolify v4 PaaS
- **DNS** : Cloudflare proxied (root + api + staging)
- **Backups** : Hetzner Object Storage hourly + Backblaze B2 réplication off-site (3-2-1)
- **DR** : RPO 1h / RTO 4h drillé via `infra/scripts/dr-drill.sh`

## Stack figée

- Laravel 12, PHP 8.3
- React 19, TypeScript 5.6, Vite 6, Tailwind 4
- Node 22 LTS, Playwright 1.49
- PostgreSQL 16, Redis 7.2
- Caddy 2, nginx 1.27 (frontend prod)

## Liens

- Spec exhaustive : `spec/00_INDEX.md`
- Stratégie mocks : `MOCKS-STRATEGY.md`
- Runbooks ops : `infra/runbooks/`
- Validation plan : `_REPORTS/VALIDATION_PLAN.md`
- Audit E2E : `_AUDIT/AUDIT-E2E-PHASE1-*/`
- Contributing : `CONTRIBUTING.md`
- Changelog : `CHANGELOG.md`
