# 02 — Architecture & infrastructure

> **Référence :** Hetzner Cloud Frankfurt (datacenter `fsn1`), région UE, RGPD natif.
> **Compte Hetzner :** **dédié** Axion CRM Pro — isolation totale d'`axion-ia.com` (qui tourne sur le compte Hetzner « williams-axion-ia », CPX42 `178.105.55.15`).
> **Prix indicatifs :** Hetzner Cloud 2026, € HT/mois.

---

## Schéma global ASCII

```
                                INTERNET / CLOUDFLARE FREE (compte CF distinct)
                                   ┌────────────────────────────────────┐
                                   │   crm.axion-pro.com  (admin)      │
                                   │   api.axion-pro.com  (API)         │
                                   │   * proxy + WAF + cache statique   │
                                   └────────────────┬───────────────────┘
                                                    │ HTTPS (Full strict)
                                                    │
  ╔═════════════════════════════════════════════════│════════════════════════════════════════╗
  ║                                                 ▼                                         ║
  ║                       HETZNER vSwitch 4011 (privé, 10.0.0.0/24)                          ║
  ║                                                                                            ║
  ║  ┌─────────────────────────────────────────────────────────────────────────────────────┐ ║
  ║  │              EDGE — CAX21 ARM (2vCPU, 4 GB, 40 GB) — €5.59/mois — 10.0.0.10         │ ║
  ║  │  ┌──────────────────────────────────────────────────────────────────────────────┐  │ ║
  ║  │  │ Caddy 2.x — reverse proxy HTTPS auto + WAF basique + rate limit              │  │ ║
  ║  │  │ Fail2ban — bannissement IP automatique                                       │  │ ║
  ║  │  │ IP publique : 1 IPv4 + 1 IPv6                                                │  │ ║
  ║  │  └──────────────────────────────────────────────────────────────────────────────┘  │ ║
  ║  └─────────────────────────────────────────────────────────────────────────────────────┘ ║
  ║                                          │                                                ║
  ║                                          ▼                                                ║
  ║  ┌─────────────────────────────────────────────────────────────────────────────────────┐ ║
  ║  │           APP — CPX31 (4vCPU, 8 GB, 160 GB SSD) — €15.69/mois — 10.0.0.20          │ ║
  ║  │  ┌──────────────────────────────────────────────────────────────────────────────┐  │ ║
  ║  │  │ docker compose                                                                │  │ ║
  ║  │  │   - laravel-app    (php-fpm 8.3 + nginx)                                     │  │ ║
  ║  │  │   - laravel-horizon (queues + monitoring)                                    │  │ ║
  ║  │  │   - laravel-scheduler (cron Laravel)                                         │  │ ║
  ║  │  │   - react-frontend  (Vite build statique servi par Caddy distant)            │  │ ║
  ║  │  │ Docker logs → loki via promtail                                              │  │ ║
  ║  │  └──────────────────────────────────────────────────────────────────────────────┘  │ ║
  ║  └─────────────────────────────────────────────────────────────────────────────────────┘ ║
  ║                                          │                                                ║
  ║                                          ▼                                                ║
  ║  ┌─────────────────────────────────────────────────────────────────────────────────────┐ ║
  ║  │      DATA — CCX13 (2vCPU dédié, 8 GB, 80 GB SSD NVMe) — €15.79/mois — 10.0.0.30   │ ║
  ║  │  ┌──────────────────────────────────────────────────────────────────────────────┐  │ ║
  ║  │  │ postgres:16-alpine + pg_trgm + postgis + pgvector + pg_partman               │  │ ║
  ║  │  │ redis:7-alpine (queues Laravel + BullMQ Node + cache + session)              │  │ ║
  ║  │  │ pg_cron (jobs SQL périodiques)                                               │  │ ║
  ║  │  │ pgbackrest (backup incrémental + WAL archiving → Hetzner Object Storage)     │  │ ║
  ║  │  │ Volume bloc Hetzner 100 GB attaché (+€5/mois pour growth)                    │  │ ║
  ║  │  └──────────────────────────────────────────────────────────────────────────────┘  │ ║
  ║  └─────────────────────────────────────────────────────────────────────────────────────┘ ║
  ║                                          │                                                ║
  ║                                          ▼                                                ║
  ║  ┌─────────────────────────────────────────────────────────────────────────────────────┐ ║
  ║  │   WORKER-1 — CPX31 (4vCPU, 8 GB, 160 GB) — €15.69/mois — 10.0.0.40                 │ ║
  ║  │  ┌──────────────────────────────────────────────────────────────────────────────┐  │ ║
  ║  │  │ Node 22 + Playwright 1.49 + playwright-extra-stealth                         │  │ ║
  ║  │  │   - worker-google-maps     (concurrency 4)                                   │  │ ║
  ║  │  │   - worker-pages-jaunes    (concurrency 3)                                   │  │ ║
  ║  │  │   - worker-sites-web       (concurrency 6)                                   │  │ ║
  ║  │  │ Chromium headless + 2 GB RAM allouée par browser                             │  │ ║
  ║  │  └──────────────────────────────────────────────────────────────────────────────┘  │ ║
  ║  └─────────────────────────────────────────────────────────────────────────────────────┘ ║
  ║                                                                                            ║
  ║  ┌─────────────────────────────────────────────────────────────────────────────────────┐ ║
  ║  │   WORKER-2 — CPX31 (4vCPU, 8 GB, 160 GB) — €15.69/mois — 10.0.0.41                 │ ║
  ║  │  ┌──────────────────────────────────────────────────────────────────────────────┐  │ ║
  ║  │  │ Node 22 + Playwright                                                          │  │ ║
  ║  │  │   - worker-google-search      (concurrency 3)                                │  │ ║
  ║  │  │   - worker-direction-finder   (concurrency 2, LLM-heavy)                     │  │ ║
  ║  │  │   - worker-crunchbase         (concurrency 2)                                │  │ ║
  ║  │  │   - worker-social-light       (concurrency 4)                                │  │ ║
  ║  │  └──────────────────────────────────────────────────────────────────────────────┘  │ ║
  ║  └─────────────────────────────────────────────────────────────────────────────────────┘ ║
  ║                                                                                            ║
  ║  ┌─────────────────────────────────────────────────────────────────────────────────────┐ ║
  ║  │   OBSERVABILITY — CPX21 (3vCPU, 4 GB, 80 GB) — €9.99/mois — 10.0.0.50              │ ║
  ║  │  ┌──────────────────────────────────────────────────────────────────────────────┐  │ ║
  ║  │  │ prometheus (rétention 30j)                                                    │  │ ║
  ║  │  │ grafana (10 dashboards, anonymous off)                                        │  │ ║
  ║  │  │ loki + promtail                                                               │  │ ║
  ║  │  │ tempo (traces, sample 10%)                                                    │  │ ║
  ║  │  │ alertmanager → Slack + Telegram                                               │  │ ║
  ║  │  │ glitchtip (error tracking)                                                    │  │ ║
  ║  │  │ uptime-kuma (uptime probes)                                                   │  │ ║
  ║  │  └──────────────────────────────────────────────────────────────────────────────┘  │ ║
  ║  └─────────────────────────────────────────────────────────────────────────────────────┘ ║
  ║                                                                                            ║
  ║  ┌─────────────────────────────────────────────────────────────────────────────────────┐ ║
  ║  │   STAGING — CCX13 (2vCPU dédié, 8 GB, 80 GB) — €15.79/mois — 10.0.0.60             │ ║
  ║  │   Iso-stack production (sauf observability), DB schéma à jour, données dummy        │ ║
  ║  └─────────────────────────────────────────────────────────────────────────────────────┘ ║
  ║                                                                                            ║
  ║  ┌─────────────────────────────────────────────────────────────────────────────────────┐ ║
  ║  │   GPU-OLLAMA (OPTIONNEL S10+) — GEX44 GPU RTX4000 SFF — €184/mois — 10.0.0.70      │ ║
  ║  │   Ollama + Llama 3.3 70B + Mistral 7B local, activé si LLM API > 60 €/mois          │ ║
  ║  └─────────────────────────────────────────────────────────────────────────────────────┘ ║
  ╚════════════════════════════════════════════════════════════════════════════════════════════╝
                                          │
                                          ▼
                       ┌────────────────────────────────────┐
                       │   HETZNER OBJECT STORAGE           │
                       │   - axion-crm-pro-backups (DB)     │
                       │   - axion-crm-pro-assets           │
                       │   €4.90/TB (~€2/mois Phase 1)      │
                       └────────────────────────────────────┘
                                          │
                                          ▼
                       ┌────────────────────────────────────┐
                       │   BACKBLAZE B2 (off-site secondary)│
                       │   réplication backups quotidienne  │
                       │   €5/TB (~€3/mois Phase 1)         │
                       └────────────────────────────────────┘
```

---

## Composants en détail

### Edge — CAX21 ARM (2vCPU ARM, 4 GB RAM, 40 GB SSD)

**Rôle :** Reverse proxy HTTPS, terminaison TLS, WAF basique, rate limiting global, fail2ban.

**Configuration Caddy :**

```caddyfile
# /etc/caddy/Caddyfile

(common_headers) {
    header {
        Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
        X-Content-Type-Options "nosniff"
        X-Frame-Options "DENY"
        Referrer-Policy "strict-origin-when-cross-origin"
        Permissions-Policy "geolocation=(), microphone=(), camera=()"
        -Server
    }
}

api.axion-pro.com {
    import common_headers
    rate_limit {
        zone api_global {
            key {remote_host}
            events 600
            window 1m
        }
    }
    reverse_proxy 10.0.0.20:8080 {
        health_uri /up
        health_interval 10s
        health_timeout 3s
        flush_interval -1
    }
    log {
        output file /var/log/caddy/api.log {
            roll_size 100MB
            roll_keep 10
        }
        format json
    }
}

crm.axion-pro.com {
    import common_headers
    root * /srv/react-build
    file_server
    try_files {path} /index.html
    encode gzip zstd
    @api path /api/* /sanctum/*
    handle @api {
        reverse_proxy 10.0.0.20:8080
    }
}
```

**Fail2ban :** jails pour `sshd`, `caddy-401`, `caddy-403`, `caddy-429`. Ban 1h après 5 echecs en 10 min.

**Pourquoi ARM (CAX21) ?** Caddy + fail2ban négligeables CPU. ARM 2x moins cher pour cette charge. CAX21 = €5.59 vs CPX21 = €9.99.

### App — CPX31 (4vCPU AMD, 8 GB RAM, 160 GB SSD)

**Rôle :** Laravel application + Horizon + scheduler. Cœur métier.

**Containers docker compose :**

| Service | Image | Mémoire | Notes |
|---------|-------|---------|-------|
| `app` | `axion-crm-pro/laravel:latest` (multi-stage build) | 2 GB | php-fpm 8.3 + nginx |
| `horizon` | idem | 1.5 GB | `php artisan horizon` |
| `scheduler` | idem | 256 MB | `php artisan schedule:work` |
| `meilisearch` | `getmeili/meilisearch:v1.10` | 1 GB | Search admin (scout) |
| `node-bridge` | `node:22-alpine` | 256 MB | Reçoit webhooks workers |

**Réseau interne Docker :** `axion-net` (172.30.0.0/16).

**Volumes persistents :**
- `/srv/laravel/storage` (sessions, logs, uploads workspace)
- `/srv/meilisearch/data`

### Data — CCX13 (2vCPU dédié AMD, 8 GB RAM, 80 GB SSD NVMe)

**Rôle :** PostgreSQL + Redis. Données critiques. CPU dédié (vs partagé sur CPX) pour latence stable.

**PostgreSQL 16 config (`postgresql.conf` overrides) :**

```ini
# Memory
shared_buffers = 2GB                    # 25% RAM
effective_cache_size = 6GB              # 75% RAM
work_mem = 16MB                         # par operation/connexion
maintenance_work_mem = 512MB
wal_buffers = 64MB

# Checkpointing
checkpoint_completion_target = 0.9
max_wal_size = 4GB
min_wal_size = 1GB

# Connections
max_connections = 200                   # Pool côté app : pgbouncer 1.22 mode transaction

# Parallel
max_worker_processes = 4
max_parallel_workers = 4
max_parallel_workers_per_gather = 2

# Statistics
default_statistics_target = 200

# Replication (préparé)
wal_level = replica
max_replication_slots = 4
max_wal_senders = 4
archive_mode = on
archive_command = 'pgbackrest --stanza=main archive-push %p'
```

**Extensions activées :**
```sql
CREATE EXTENSION IF NOT EXISTS pg_trgm;       -- fuzzy matching
CREATE EXTENSION IF NOT EXISTS postgis;       -- géocodage
CREATE EXTENSION IF NOT EXISTS pgvector;      -- embeddings LLM future
CREATE EXTENSION IF NOT EXISTS pg_partman;    -- partitionnement automatique
CREATE EXTENSION IF NOT EXISTS pg_cron;       -- jobs SQL
CREATE EXTENSION IF NOT EXISTS pgcrypto;      -- gen_random_uuid, hash chain
CREATE EXTENSION IF NOT EXISTS unaccent;      -- normalisation accents
CREATE EXTENSION IF NOT EXISTS btree_gin;     -- indexes GIN sur tableaux
```

**Redis 7 config (`redis.conf` overrides) :**

```ini
maxmemory 2gb
maxmemory-policy allkeys-lru
save 900 1
save 300 10
save 60 10000
appendonly yes
appendfsync everysec
tcp-keepalive 60
timeout 0

# Réservé queues
databases 16
# DB 0 = queues Laravel (Horizon)
# DB 1 = queues BullMQ (Node workers)
# DB 2 = cache Laravel
# DB 3 = sessions Laravel
# DB 4 = rate limiting
# DB 5 = LLM cache
```

**Backups pgbackrest :**
- Full backup hebdomadaire dimanche 03:00 UTC
- Diff backup daily 03:00 UTC
- WAL archiving continu → Hetzner Object Storage
- Rétention : 30 jours
- Réplication off-site quotidienne 04:00 UTC → Backblaze B2

### Worker-1 et Worker-2 — CPX31

**Rôle :** Workers Node.js Playwright. 2 machines pour répartir charge + isolation pannes.

**Pourquoi 2 workers identiques au démarrage ?** Pic horaire scraping ~7-10 k entreprises/jour, soit ~80-100 scraping concurrents. 1 worker = bottleneck CPU si une session Playwright fuite. 2 = haute dispo + scale linéaire.

**Workload split :**

**Worker-1** — Sources « masse » (volume élevé, par-cible):
- Google Maps (concurrency 4)
- Pages Jaunes (concurrency 3)
- Sites web (concurrency 6)

**Worker-2** — Sources « ciblées » (faible volume, LLM-heavy ou rate-limit strict):
- Google Search Wrapper (concurrency 3)
- Direction Finder (concurrency 2)
- Crunchbase (concurrency 2)
- Social light (concurrency 4)

**Configuration runtime par worker :**

```yaml
# infra/workers/docker-compose.yml
version: '3.9'
services:
  worker:
    image: axion-crm-pro/worker-node:latest
    restart: unless-stopped
    deploy:
      resources:
        limits:
          memory: 6G   # 8 - 2 (système)
          cpus: '3.5'  # 4 - 0.5 (système)
    environment:
      REDIS_HOST: 10.0.0.30
      REDIS_PORT: 6379
      WORKER_TYPE: ${WORKER_TYPE}
      PROXY_REGION: fr
      LLM_CACHE_DB: 5
    volumes:
      - ./playwright-cache:/ms-playwright
      - ./logs:/app/logs
    healthcheck:
      test: ["CMD", "node", "/app/healthcheck.js"]
      interval: 30s
      timeout: 10s
      retries: 3
```

**playwright-extra plugins :**

```typescript
import { chromium } from 'playwright-extra'
import stealth from 'puppeteer-extra-plugin-stealth'
import recaptcha from 'puppeteer-extra-plugin-recaptcha'

chromium.use(stealth())
chromium.use(recaptcha({
  provider: { id: '2captcha', token: process.env.CAPTCHA_TOKEN },
  visualFeedback: false,
}))
```

### Observability — CPX21 (3vCPU AMD, 4 GB RAM, 80 GB SSD)

**Rôle :** Monitoring auto-hébergé. Isolé pour ne pas impacter prod en cas de surcharge.

**Stack :**

```
prometheus       :9090   — métriques (rétention 30j)
grafana          :3000   — dashboards (10 dashboards)
loki             :3100   — logs centralisés
tempo            :3200   — traces distribuées (sample 10%)
alertmanager     :9093   — routing alertes
glitchtip        :8000   — error tracking (alternative Sentry)
uptime-kuma      :3001   — uptime monitoring externe
```

**Pourquoi ne pas mettre sur le même serveur que l'app ?** Loki+Grafana peuvent consommer 2-3 GB RAM et bouffer disk si pic logs. Sépare la responsabilité.

### Staging — CCX13

**Rôle :** Environnement iso-prod, données dummy, déployé automatiquement à chaque push `main`. Permet tests E2E + smoke avant promotion vers prod.

**Différences avec prod :**
- Pas de monitoring séparé (logs vers stdout, scrappés par GH Actions)
- Pas de backup
- Domaine `staging.axion-pro.com`
- Workers en mode `--dry-run` (pas de scraping réel, mock fixtures)

### GPU-Ollama (optionnel)

**Activation :** Lorsque coût LLM API > 60 €/mois OU latence Claude/OpenAI > 3 sec P95.

**Modèles :**
- Llama 3.3 70B Q4_K_M (~40 GB VRAM) → use case `crm_lead_scoring`, `reply_intent_detection`
- Mistral 7B Q5_K_M (~5 GB VRAM) → use case `sector_classification` haut volume
- Phi-3 mini Q4 (~2.5 GB VRAM) → tâches très courtes/fréquentes

**GEX44** = GPU RTX 4000 SFF (20 GB VRAM). Insuffisant pour Llama 70B full précision mais OK Q4_K_M. Si besoin Llama 405B = passer à GEX130 (RTX 4090, ~€800/mois) → non recommandé Phase 1.

---

## Tableau récapitulatif coûts mensuels infra

| Serveur | Type | vCPU | RAM | Disque | Prix € HT/mois | Phase |
|---------|------|------|-----|--------|----------------|-------|
| Edge | CAX21 ARM | 2 | 4 GB | 40 GB | 5,59 | S1 |
| App | CPX31 | 4 | 8 GB | 160 GB | 15,69 | S1 |
| Data | CCX13 dédié | 2 | 8 GB | 80 GB NVMe | 15,79 | S1 |
| Worker-1 | CPX31 | 4 | 8 GB | 160 GB | 15,69 | S1 |
| Worker-2 | CPX31 | 4 | 8 GB | 160 GB | 15,69 | S6 |
| Observability | CPX21 | 3 | 4 GB | 80 GB | 9,99 | S12 |
| Staging | CCX13 | 2 | 8 GB | 80 GB | 15,79 | S2 |
| Volume bloc DB | — | — | — | 100 GB | 4,76 | S3 |
| IPv4 add (×3) | — | — | — | — | 1,80 | S1 |
| **Sous-total infra core** | | | | | **~100,79** | |
| Hetzner Object Storage (~50 GB) | — | — | — | 50 GB | 0,24 | S3 |
| GPU-Ollama (optionnel) | GEX44 RTX4000 SFF | 16 | 64 GB | 1 TB | 184,90 | S10+ |
| **Sous-total infra étendue (sans GPU)** | | | | | **~101,03** | |
| Domaines (crm.axion-pro.com + api) | — | — | — | — | 0,83 | S1 |
| Cloudflare Free | — | — | — | — | 0 | S1 |
| Backblaze B2 (~50 GB backups réplication) | — | — | — | 50 GB | 0,21 | S3 |
| Captcha 2captcha (optionnel) | — | — | — | — | 20 | S6 |
| Proxies Webshare datacenter | — | — | — | — | 10 | S3 |
| Proxies IPRoyal résidentiels | — | — | — | — | 30 | S6 |
| LLM APIs (Claude+Mistral) | — | — | — | — | 60 | S2 |
| **Sous-total services tiers** | | | | | **~121,04** | |
| **TOTAL Phase 1 (hors GPU)** | | | | | **~222 €/mois** | |
| **TOTAL Phase 1 + GPU Ollama** | | | | | **~407 €/mois** | |

**Note :** Le prompt v6 cible ~265 €/mois. Le delta provient principalement des proxies résidentiels et captcha solving, activés selon usage réel (peuvent être démarrés à 0 et scalés). Cible réaliste S12 sans GPU : **240-280 €/mois**.

---

## Isolation totale d'axion-ia.com

> **Doctrine :** Aucun shared resource entre Axion CRM Pro et axion-ia.com. Conséquence : un incident d'un côté ne peut pas affecter l'autre.

| Ressource | axion-ia.com | Axion CRM Pro |
|-----------|--------------|---------------|
| Compte Hetzner Cloud | `williams-axion-ia` | **`williams-axion-crm-pro`** (à créer) |
| Compte Hetzner Robot/billing | idem | idem |
| Datacenter | `fsn1` (Frankfurt) | `fsn1` (Frankfurt) — *même DC OK, comptes séparés* |
| vSwitch | 4010 (privé Axion-IA) | **4011** (privé Axion CRM Pro) |
| IPs publiques | `178.105.55.15` (CPX42) | nouvelles IPs (allouées au démarrage) |
| Domaine principal | `axion-ia.com` | **`axion-pro.com`** (à acheter Namecheap/OVH) |
| DNS | Cloudflare compte CF Axion-IA | **Cloudflare compte CF distinct** (`axion-crm-pro@beeeditions.gmail.com` alias) |
| DB Postgres | `axion-ia` cluster Coolify | **`axion-crm-pro` cluster dédié** |
| Redis | dédié axion-ia | **dédié Axion CRM Pro** |
| Secrets manager | `.secrets/` local dev + Coolify env | **Infisical self-hosted OU Doppler** + `.secrets/` local dev |
| Audit logs | inclus Axion-IA | **séparés, jamais cross-référencés** |
| Backups | OBS `axion-ia-backups` | **OBS `axion-crm-pro-backups`** + Backblaze B2 |
| Monitoring | Grafana intégré Axion-IA | **Grafana dédié Axion CRM Pro** |
| GitHub repo | `axion-ia` | **`axion-crm-pro`** (nouveau repo privé) |
| CI/CD | GH Actions workflows axion-ia | **workflows distincts** |
| Slack notifications | canal `#axion-ia-prod` | **canal `#axion-crm-pro-prod`** |

**Validation isolation :**
- ✅ Pas de hardcode `axion-ia.com` dans le code CRM (sauf doc README)
- ✅ Pas d'import de packages internes axion-ia (réécriture des helpers nécessaires)
- ✅ Tests E2E vérifient absence de fuite vers axion-ia.com
- ✅ DNS reverse différent (PTR records distincts)

---

## Stack technique précise

### Backend Laravel

| Composant | Version | Rôle |
|-----------|---------|------|
| Laravel Framework | 12.x | HTTP, routing, ORM, queues |
| PHP | 8.3.13+ | Runtime |
| Composer | 2.8+ | Dépendances PHP |
| Laravel Sanctum | 4.x | Auth SPA cookie |
| Laravel Horizon | 6.x | Dashboard queues Redis |
| Laravel Scout | 10.x | Search abstraction (driver Meilisearch) |
| Laravel Telescope | 5.x | **Dev uniquement** — debugging |
| Spatie Laravel Permission | 6.x | RBAC |
| Spatie Laravel Data | 4.x | DTOs typés |
| Spatie Laravel Model States | 2.x | State machine waterfall |
| Spatie Laravel Backup | 9.x | Sauvegardes user-uploaded |
| Spatie Laravel Activitylog | 4.x | Audit log applicatif |
| Spatie Laravel Query Builder | 6.x | Filtres URL → SQL |
| pragmarx/google2fa-laravel | 3.x | TOTP 2FA |
| league/csv | 9.x | Import/export CSV gros volumes |
| guzzlehttp/guzzle | 7.x | HTTP client APIs externes |
| symfony/dom-crawler | 7.x | Parsing HTML serveur-side (annuaire-entreprises, etc.) |
| symfony/css-selector | 7.x | CSS selectors pour DomCrawler |
| anthropic-php/sdk | 0.x (latest) | Client Claude |
| openai-php/laravel | 0.x | Client OpenAI |
| pestphp/pest | 3.x | Test framework |
| larastan/larastan | 3.x | Static analysis (PHPStan) |
| nunomaduro/collision | 8.x | Error reporting CLI |

### Frontend React

| Composant | Version | Rôle |
|-----------|---------|------|
| React | 19.x | UI |
| TypeScript | 5.6+ | Typage |
| Vite | 6.x | Build tool |
| Tailwind CSS | 4.x | Styling |
| @tanstack/react-router | 1.x | Routing |
| @tanstack/react-query | 5.x | Data fetching |
| @tanstack/react-virtual | 3.x | Virtualization listes |
| @tanstack/react-table | 8.x | Data tables |
| zustand | 5.x | State global (UI) |
| react-hook-form | 7.x | Formulaires |
| zod | 3.x | Validation schemas |
| maplibre-gl | 4.x | Carte vectorielle |
| recharts | 2.x | Graphes |
| @dnd-kit/* | 6.x | Drag & drop (Phase 2 CRM pipeline) |
| shadcn/ui | latest | Composants copiables (Radix + Tailwind) |
| lucide-react | latest | Icônes |
| date-fns | 4.x | Dates |
| sonner | 1.x | Toast notifications |
| cmdk | 1.x | Command palette (⌘K) |
| vitest | 2.x | Tests unitaires |
| @testing-library/react | 16.x | Tests composants |
| @playwright/test | 1.49+ | Tests E2E |

### Workers Node

| Composant | Version | Rôle |
|-----------|---------|------|
| Node.js | 22 LTS | Runtime |
| Playwright | 1.49+ | Headless browser |
| playwright-extra | 4.x | Plugin system |
| puppeteer-extra-plugin-stealth | 2.x | Anti-bot |
| puppeteer-extra-plugin-recaptcha | 3.x | Captcha solving |
| bullmq | 5.x | Queues Redis (côté Node) |
| ioredis | 5.x | Client Redis |
| pino | 9.x | Logger structuré JSON |
| zod | 3.x | Validation runtime |
| cheerio | 1.x | Parsing HTML (light, vs DomCrawler) |
| pdf-parse | 1.x | Parsing PDFs (rapports annuels Direction Finder) |
| undici | 6.x | HTTP client performant |
| dotenv | 16.x | Env vars |
| tsx | 4.x | Run TypeScript direct |
| vitest | 2.x | Tests |

### Infrastructure

| Composant | Version | Rôle |
|-----------|---------|------|
| Docker Engine | 27+ | Containers |
| Docker Compose | 2.30+ | Orchestration locale |
| Coolify | 4.x | PaaS (alternative k3s si volume scale) |
| Caddy | 2.8+ | Reverse proxy HTTPS |
| PostgreSQL | 16.4+ | RDBMS |
| Redis | 7.4+ | Cache + queues |
| pgbouncer | 1.22+ | Pool connexions Postgres |
| pgbackrest | 2.53+ | Backup Postgres |
| Meilisearch | 1.10+ | Search engine |
| Prometheus | 2.55+ | Métriques |
| Grafana | 11.x | Dashboards |
| Loki | 3.x | Logs |
| Tempo | 2.6+ | Traces |
| Alertmanager | 0.27+ | Alertes |
| GlitchTip | 4.x | Error tracking |
| Uptime Kuma | 1.23+ | Uptime |

### CI/CD

| Composant | Version | Rôle |
|-----------|---------|------|
| GitHub Actions | runners ubuntu-24.04 | CI |
| Trivy | latest | Scan vulnérabilités images |
| Semgrep | 1.x | SAST |
| Dependabot | GitHub natif | Updates deps |

### Secrets

| Composant | Version | Rôle |
|-----------|---------|------|
| Infisical (self-hosted) OR Doppler | latest | Gestion secrets centralisée |
| sops (mozilla) | 3.x | Backup-encryption local |

---

## Stratégie dimensionnement (volume cible)

### Mois 1 — 200 000 entreprises/mois (~7 000/jour)

- **Worker capacity actuelle** (2× CPX31) : ~12 000 scrapings/heure pour TPE/PME (waterfall 30s) avec 20 sessions Playwright concurrentes
- **Marge** : confortable (×4 capacité dispo)
- **DB writes** : ~50 inserts/s (chemin chaud `companies` + `contacts` + `email_verifications` + `scraper_runs`) — largement sous capacité Postgres CCX13
- **Redis** : ~500 ops/s — sous-utilisé

### Année 1 — 1 000 000 entreprises/mois (~35 000/jour)

- Worker capacity à atteindre : ~60 000 scrapings/heure
- **Scale-out worker-3 et worker-4** (2× CPX31 supplémentaires) = +30 €/mois
- **Scale-up DB** : CCX13 → CCX23 (4vCPU dédié, 16 GB, 160 GB NVMe) = €31.79/mois
- **Read replica DB** sur CCX13 supplémentaire pour requêtes Coverage Matrix lourdes
- **Postgres tuning** : partitionnement déjà en place, indexes BRIN sur scraper_runs.created_at
- **Coût total estimé scale 1M/mois** : ~380 €/mois (hors GPU)

### Scaling 5M+/mois (an 2-3)

- Migration Coolify → k3s cluster (3 control + 3 worker)
- Postgres : Patroni HA (3 nodes) ou Crunchy Data PGO
- Workers : autoscaling KEDA sur queue depth Redis
- Cache : Redis cluster

---

## Sécurité réseau

### Firewall Hetzner Cloud (par défaut)

```yaml
firewall_axion_crm_pro:
  inbound:
    - protocol: tcp
      port: 22         # SSH
      source: [IP_HOME_WILL/32]
    - protocol: tcp
      port: 80         # HTTP (Edge uniquement)
      source: [0.0.0.0/0, ::/0]
      apply_to: [tag:edge]
    - protocol: tcp
      port: 443        # HTTPS (Edge uniquement)
      source: [0.0.0.0/0, ::/0]
      apply_to: [tag:edge]
    - protocol: icmp
      source: [0.0.0.0/0]
  outbound:
    - protocol: tcp
      destination: [0.0.0.0/0]
      ports: [80, 443, 5432, 6379, 587, 465, 25]
```

**Trafic intra-vSwitch :** illimité, gratuit, pas de bande passante facturée.

**SSH :** Port 22 uniquement depuis IP fixe Will (à fixer Cloudflare WARP si dynamique) + jump host éventuel. PasswordAuth: no, fail2ban actif.

### Cloudflare WAF (compte CF distinct)

- SSL: Full strict
- HSTS: 6 mois preload OFF (12 mois preload après 3 mois prod stable)
- WAF: Bot Fight ON (modéré), AI Scrapers OFF (on est nous-mêmes un scraper)
- DDoS protection: ON
- Rate limiting: 600 req/min/IP
- Cache Rules: bypass complet sur `/api/*` et `/sanctum/*`

---

## DNS

```
crm.axion-pro.com.      300  IN  A     <edge_ipv4>
crm.axion-pro.com.      300  IN  AAAA  <edge_ipv6>
api.axion-pro.com.      300  IN  CNAME crm.axion-pro.com.
staging.axion-pro.com.  300  IN  A     <staging_ipv4>

# DMARC/SPF/DKIM (pour notifications transactionnelles internes uniquement, pas pour cold email Phase 2 — ça aura son domaine séparé)
axion-pro.com.          3600 IN  TXT   "v=spf1 include:_spf.amazonses.com -all"
_dmarc.axion-pro.com.   3600 IN  TXT   "v=DMARC1; p=reject; rua=mailto:dmarc@axion-pro.com"
```

> **Note Phase 2 :** Pour le cold email envoyé en masse, **PAS** ce domaine. Achat de 3-5 domaines secondaires (`axion-prospect.com`, `axionoutreach.io`, etc.) configurés avec leurs propres IPs SMTP dédiées, warmup progressif (cf. `04_db_schema_phase2_scaffold.md` table `warmup_states`).

---

## Plan de déploiement initial (S1)

```bash
# 1. Création compte Hetzner CRM-Pro dédié
# 2. Génération clés SSH (séparées d'Axion-IA)
# 3. Création vSwitch 4011

hcloud network create --name axion-crm-pro-vswitch --ip-range 10.0.0.0/16

# 4. Création des 5 servers initiaux (S1, sans worker-2 ni observability ni GPU)
hcloud server create --type cax21 --image debian-12 --name edge       --network axion-crm-pro-vswitch --ssh-key axion-crm-pro-key
hcloud server create --type cpx31 --image debian-12 --name app        --network axion-crm-pro-vswitch --ssh-key axion-crm-pro-key
hcloud server create --type ccx13 --image debian-12 --name data       --network axion-crm-pro-vswitch --ssh-key axion-crm-pro-key
hcloud server create --type cpx31 --image debian-12 --name worker-1   --network axion-crm-pro-vswitch --ssh-key axion-crm-pro-key
hcloud server create --type ccx13 --image debian-12 --name staging    --network axion-crm-pro-vswitch --ssh-key axion-crm-pro-key

# 5. Firewall + reverse DNS + Cloudflare config (cf. 18_deploiement_hetzner.md)
# 6. Bootstrap docker compose + Coolify installation
# 7. Push initial GitHub → trigger CI/CD → premier déploiement
```

Détail complet du bootstrap : `18_deploiement_hetzner.md`.

---

## Décisions implicites (à valider Will)

> **STOP & ASK Will (4 décisions infra) :**
>
> 1. **Domaine** : `axion-pro.com` (option A) ou autre ? **Défaut : `axion-pro.com`**
> 2. **Coolify v4 ou k3s** dès le démarrage ? **Défaut : Coolify v4** (cohérent avec axion-ia.com, moins de courbe d'apprentissage).
> 3. **GPU Ollama maintenant ou plus tard ?** **Défaut : plus tard** (S10+ si LLM API > 60 €/mois).
> 4. **Secrets manager : Infisical self-hosted (ajoute 1 service) OU Doppler (SaaS, gratuit jusqu'à 5 users) ?** **Défaut : Doppler** pour démarrer simple, migration Infisical possible plus tard.

---

## Lecture suivante

→ `03_db_schema_phase1.md` (~32 tables SQL exécutables PostgreSQL 16).
