# Phase 5 — Infrastructure + DevOps

## Constats

| Critère | Cible | Réalité |
|---------|-------|---------|
| `docker-compose.yml` services | ≥ 7 | 11 services (postgres, redis, api, horizon, scheduler, app, 3 workers, caddy) ✅ |
| `docker-compose.prod.yml` | présent | ✅ overlay stage prod |
| `docker-compose.observability.yml` | requis | ✅ 7 services (Prometheus + Alertmanager + Grafana + Loki + Promtail + Tempo + GlitchTip + Kuma) |
| Dockerfiles multi-stage | 3 | ✅ Dockerfile.laravel (5 stages) + Dockerfile.frontend (4 stages) + Dockerfile.worker (4 stages) |
| Caddyfile | requis | ✅ + CSP strict + HSTS preload + COOP/CORP |
| Terraform Hetzner | requis spec 18 | ❌ **absent** (`infra/terraform/` n'existe pas) |
| GH Actions workflows | ≥ 5 (ci+staging+prod+a11y+security) | **1 seul** (`ci.yml`) |
| Backups pgbackrest | requis | ❌ pas configuré (runbook 04 documente cible Hetzner OBS + Backblaze) |
| DR drill script | requis | ✅ `infra/scripts/dr-drill.sh` |
| Prometheus config | requis | ✅ scrape 6 jobs + 8 alerts business + infra |
| Grafana dashboards | 10 | **1** (`axion-overview.json`) |
| Loki retention | requis | ✅ 720h (30j) |
| Tempo retention | requis | ✅ 168h (7j) |
| Alertmanager routes | requis | ✅ severity-based (critical→telegram, warning→slack) |
| Métriques business custom | 4 (P1 audit) | ✅ documentées dans alerts.yml (mais pas exposées côté Laravel) |
| Langfuse self-hosted | requis spec 16 | ❌ pas dans docker-compose.observability |
| Secrets management | Doppler/Infisical | ❌ placeholder `.env` seul |
| Dependabot | requis | ❌ absent |

## Forces

1. **Stack observabilité 7 services Auto-hébergée** — complète selon spec/16.
2. **Caddyfile sécurité maximale** — CSP strict, HSTS preload, COOP/CORP, Permissions-Policy.
3. **3 Dockerfiles multi-stage prod-ready** — composer-deps cached, nginx static prod, Playwright noble image.
4. **DR drill script** + 5 runbooks markdown (workers / disk / site / restore / secrets).
5. **Configs Prometheus + Alertmanager** complètes avec 8 alerts (3 business + 5 infra).

## Faiblesses

1. **Pas de Terraform** — Provisionning Hetzner manuel.
2. **CI 1 workflow seul** — manque `deploy-staging.yml`, `deploy-prod.yml`, `a11y.yml`, `security.yml`.
3. **Grafana 1 dashboard** — Spec attend 10 (overview + scraping + LLM + dedup + coverage + RGPD + queues + DB + workers + business).
4. **Dependabot absent** — pas de surveillance auto vulnérabilités.
5. **Langfuse manquant** — observabilité LLM specifique non couverte.

## P0 bloquants prod

- **CI/CD deploy workflows absents** — pas de pipeline staging/prod automatisé.
- **Pas de Terraform** — provisioning manuel = risque dérive.
- **1 dashboard Grafana** — observabilité métier sous-doté.

## Score Phase 5 : **66 / 100**
