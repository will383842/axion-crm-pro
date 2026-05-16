# 02 — ARCHITECTURE & INFRASTRUCTURE

## Vue d'ensemble

Axion CRM Pro est déployée sur un **compte Hetzner Cloud dédié** ("Compte 2"), **strictement isolé** du compte qui héberge `axion-ia.com` (Compte 1). Le seul lien entre les deux est organisationnel (même fondateur) — aucun lien réseau, DNS, code, déploiement, secret, ou base de données. Tous les serveurs Compte 2 sont dans la **localisation `fsn1` (Frankfurt, Allemagne)** pour conformité RGPD UE et latence sous 30 ms depuis la France.

L'architecture distingue clairement **3 couches de runtime** :

1. **Couche API + Console (PHP/JS, stateful)** — Laravel API + frontend React + scheduler. Pilotée depuis HTTPS.
2. **Couche Workers asynchrones (PHP + Node.js)** — Horizon workers PHP pour HTTP léger, BD, LLM, SMTP. Node.js + Playwright pour scraping headless. Communique via Redis queues.
3. **Couche Données & Observabilité (Postgres, Redis, Grafana, Loki, etc.)** — services stateful avec volumes persistants + sauvegardes.

---

## Diagramme ASCII détaillé

```
─────────────────────────────────────────────────────────────────────────────────────────────────
                                   HETZNER CLOUD — Compte 2 (axion-crm@axion-ia.com)
                                   DC : fsn1 (Frankfurt, Allemagne)
                                   vSwitch : axion-crm-private (10.20.0.0/16, gratuit)
─────────────────────────────────────────────────────────────────────────────────────────────────

  Internet ──HTTPS──► Cloudflare proxy (free)
                         │  bot-fight + AI scrapers OFF + HSTS 12mo + SSL Full strict
                         ▼
  ┌─────────────────────────────────────────────────────────────────────────────────────────────┐
  │  edge-01  (CCX23, 4 vCPU dedicated, 16 Go RAM, 240 Go NVMe, ~30€/mois)                       │
  │  - Caddy 2.x reverse proxy + HTTPS Let's Encrypt                                              │
  │  - vhosts : crm.axion-ia.com (admin), api.crm.axion-ia.com (API)                              │
  │  - Forwards vers app-01 / app-02 sur réseau privé 10.20.0.10:8080                             │
  │  - Health check + rate limit per IP                                                            │
  └────────────────────────────────────┬─────────────────────────────────────────────────────────┘
                                       │ vSwitch privé 10.20.0.0/16
                                       ▼
  ┌──────────────────────────────────────────┐       ┌─────────────────────────────────────────┐
  │  app-01  (CCX23, 4 vCPU, 16 Go, 240 Go)   │       │  app-02  (CCX23, 4 vCPU, 16 Go, 240 Go) │
  │  - Laravel 12 (PHP 8.3-FPM, octane swoole)│       │  - Laravel scheduler (1 instance only)  │
  │  - Frontend React static (Nginx serving)  │       │  - Laravel Horizon master              │
  │  - Sanctum cookie SPA                     │       │  - GlitchTip ingest endpoint           │
  │  - HTTPS via Caddy upstream               │       │  - Prometheus exporters                 │
  │  - 8 vCPU FPM workers max                 │       │                                         │
  └──────────────────────────────────────────┘       └─────────────────────────────────────────┘
                       │                                              │
                       └─────────────────── Redis queues ─────────────┘
                                              │
       ┌──────────────────────────────────────┴────────────────────────────────────────────────┐
       ▼                          ▼                       ▼                       ▼            ▼
  ┌─────────┐   ┌───────────────────────┐   ┌────────────────────┐   ┌─────────────────────┐  ┌────────────────────┐
  │  db-01  │   │   redis-01            │   │ worker-php-01       │   │ worker-node-01      │  │ worker-node-02      │
  │ CCX33   │   │  CCX13 (2vCPU,8Go,80) │   │  CCX23 (4vCPU/16/240│   │  CPX31 (4vCPU AMD,  │  │ CPX31 idem          │
  │ 8 vCPU  │   │  Redis 7              │   │  Horizon workers    │   │  8Go, 240Go)        │  │ Playwright stealth  │
  │ dedi    │   │  ~12€/mois            │   │  HTTP léger / BD /  │   │  Playwright 1.49+   │  │ ~16€/mois           │
  │ 32 Go   │   │  Persistance AOF      │   │  LLM / SMTP / docs  │   │  BullMQ workers     │  │                     │
  │ 480 Go  │   │                       │   │  Concurrency 32     │   │  Concurrency 8/IP   │  │                     │
  │ Postgres│   │                       │   │  ~30€/mois          │   │  ~16€/mois          │  │                     │
  │  16     │   └───────────────────────┘   └────────────────────┘   └─────────────────────┘  └────────────────────┘
  │ +pg_trgm│
  │ +postgis│   ┌───────────────────────┐   ┌────────────────────┐   ┌─────────────────────┐
  │ +pgvect │   │  obs-01               │   │  llm-gpu-01        │   │ backup-vol-01       │
  │+pg_part │   │  CCX23 (4vCPU/16/240) │   │  GPU "EX44-NVMe"   │   │ Hetzner Volume      │
  │~60€/mois│   │  Grafana + Prometheus │   │  RTX 4000 SFF Ada  │   │ 1 To, 40€/mois      │
  │ AOF +   │   │  Loki + Tempo         │   │  Ollama Llama 3.3  │   │ Snapshots + dumps   │
  │ replica?│   │  Uptime Kuma          │   │  70B Q4 4-bits     │   │ + Backblaze B2      │
  │ S2      │   │  GlitchTip            │   │  + Mistral 7B      │   │ offsite ~5€/mois    │
  └─────────┘   │  ~30€/mois            │   │  Hetzner dedi      │   │                     │
                │                       │   │  ~70€/mois         │   └─────────────────────┘
                └───────────────────────┘   └────────────────────┘

  Note : llm-gpu-01 est OPTIONNEL en V1 (Ollama local non strict). On peut démarrer Phase 1 sans GPU
  et router tous les LLM use cases vers APIs externes (Anthropic, OpenAI, Mistral, OpenRouter).
  Le GPU n'est activé que si la volumétrie le justifie (>15M tokens/mois sur Llama-bound use cases).

─────────────────────────────────────────────────────────────────────────────────────────────────
```

---

## Découpage en modules logiques

### Module 1 — `axion-crm-api` (Laravel 12)

| Sous-module | Responsabilité |
|---|---|
| `App\Modules\Auth` | Sanctum cookie SPA, TOTP 2FA, magic link, audit log |
| `App\Modules\Tenancy` | Workspaces, users, invitations, middleware RLS injection |
| `App\Modules\Rbac` | Spatie Permission wrapper, 4 rôles, policies métier |
| `App\Modules\Companies` | CRUD, search, bulk export, override scores, attache tags |
| `App\Modules\Contacts` | CRUD, search, lien company, attache emails/téléphones |
| `App\Modules\Scraping` | Orchestrateur waterfall, dispatch queues, `scraper_runs` tracking |
| `App\Modules\Sources` | 14 implémentations de `ScraperPlugin` (INSEE, annu-ent, BODACC, etc.) |
| `App\Modules\EmailFinder` | Génération patterns + extraction sites + validation SMTP cascade |
| `App\Modules\LlmRouter` | Service unifié multi-providers + cost tracking + A/B testing |
| `App\Modules\Proxies` | Interface `ProxyProvider` + 4 implémentations + routeur intelligent |
| `App\Modules\Rotations` | 5 dimensions (proxies, user-agents, géo/sectoriel, LinkedIn, LLM) |
| `App\Modules\Coverage` | Materialized view, dedup, algo "prochaine zone à attaquer" |
| `App\Modules\Geo` | INSEE référentiel (régions/dept/villes/communes), géocodage BAN |
| `App\Modules\Classification` | Use cases LLM : maturité IA, offre Axion-IA, tags, mots-clés stratégiques |
| `App\Modules\Signals` | BODACC + France Travail + scraping news → `company_business_signals` |
| `App\Modules\Gdpr` | Registre RGPD, droits d'accès/effacement, opt-out cross-workspace |
| `App\Modules\AiAct` | Registre des modèles utilisés pour profilage/scoring |
| `App\Modules\AuditLog` | Append-only hash chain, vérification d'intégrité |
| `App\Modules\Monitoring` | Métriques Prometheus, anomaly detection nightly |
| `App\Modules\Phase2\Campaigns` | **Scaffold seul** — orchestrateur futur multi-canal |
| `App\Modules\Phase2\ColdEmail` | **Scaffold seul** — séquences, templates, sending domains, SMTP IPs, warmup |
| `App\Modules\Phase2\LinkedIn` | **Scaffold seul** — campagnes, templates, Sales Nav rotation |
| `App\Modules\Phase2\Crm` | **Scaffold seul** — pipeline, deals, activités, tâches |
| `App\Modules\Phase2\Analytics` | **Scaffold seul** — funnels, cohorts, ROI |

### Module 2 — `axion-crm-frontend` (React 19 + Vite 6)

```
src/
├── api/
│   ├── client.ts            # axios instance + interceptors Sanctum + retry
│   ├── auth.ts              # endpoints login/2fa/logout
│   ├── companies.ts         # endpoints liste/détail/search/export
│   ├── coverage.ts          # endpoints matrix + zones
│   ├── llm.ts               # endpoints use cases + templates
│   ├── rotations.ts         # endpoints proxies/linkedin/user-agents
│   ├── scraper.ts           # endpoints runs / sources / targets
│   ├── gdpr.ts              # endpoints requests
│   └── ...
├── components/
│   ├── ui/                  # shadcn/ui (Button, Card, Dialog, etc.)
│   ├── companies/           # CompaniesTable, CompanyDetail, FiltersSidebar
│   ├── coverage/            # FranceCoverageMap (MapLibre wrapper)
│   ├── llm/                 # UseCasesEditor, TemplateEditor, CostsDashboard
│   ├── rotations/           # ProxiesPanel, LinkedInAccountsPanel
│   ├── monitoring/          # MetricsGauges, AlertsCenter
│   └── ...
├── hooks/                   # useCompany, useCoverageMatrix, useLlmRouter, ...
├── pages/                   # 22 pages (17 Phase 1 + 5 Phase 2 placeholders)
├── lib/                     # utils, formatters, datetime, csv-export
├── i18n/                    # FR canonique (EN miroir Phase 2)
├── router.tsx               # React Router 7 tree
└── main.tsx                 # entrypoint Vite
```

### Module 3 — `axion-crm-workers` (Node.js 22 + Playwright + BullMQ)

```
workers/
├── package.json             # bullmq, playwright, playwright-extra, cheerio, pino
├── tsconfig.json
├── src/
│   ├── config.ts            # Redis URL, concurrency, proxies endpoint
│   ├── proxies/             # Client HTTP vers /api/internal/proxies/next
│   ├── stealth/             # config playwright-extra stealth
│   ├── plugins/             # 1 fichier par scraper Playwright
│   │   ├── gmaps-scraper.ts
│   │   ├── pj-scraper.ts
│   │   ├── website-crawler.ts
│   │   ├── linkedin-pb-bridge.ts
│   │   ├── crunchbase-scraper.ts
│   │   ├── infogreffe-scraper.ts
│   │   ├── societe-scraper.ts
│   │   ├── mesri-onisep-scraper.ts
│   │   └── social-light-scraper.ts
│   ├── queues/              # BullMQ queue definitions (1 par scraper)
│   ├── workers/             # 1 worker BullMQ par plugin
│   ├── lib/                 # logger, retry, http-with-proxy, captcha-detect
│   └── index.ts             # bootstrap (charge tous workers en parallèle)
└── Dockerfile               # base mcr.microsoft.com/playwright:v1.49.0-noble
```

### Module 4 — `axion-crm-infra` (Docker, Compose, k8s manifests)

```
infra/
├── docker-compose.dev.yml   # dev local Will (postgres, redis, app, frontend, workers, mailpit)
├── docker-compose.prod.yml  # prod stack (utilisé par Coolify ou k3s manifest generator)
├── docker/
│   ├── php/                 # Dockerfile multi-stage Laravel + octane
│   ├── node-playwright/     # Dockerfile workers (base playwright:noble)
│   ├── frontend/            # Dockerfile build Vite + serve static via nginx alpine
│   ├── caddy/               # Caddyfile prod
│   └── postgres/            # init scripts (extensions, RLS roles, etc.)
├── k8s/                     # optionnel S12+ (manifests k3s si on quitte Coolify)
└── scripts/
    ├── backup-postgres.sh   # pg_dump chiffré → B2
    ├── restore-postgres.sh
    ├── migrate-prod.sh
    ├── seed-geo.sh          # import IGN + INSEE
    └── healthcheck.sh
```

---

## Stack précise (versions)

| Couche | Composant | Version | Notes |
|---|---|---|---|
| **PHP** | PHP-FPM | 8.3.x | + Composer 2.x |
| **Backend** | Laravel | 12.x | LTS path |
| | Laravel Octane | 2.x | Swoole driver (perf gain x3) |
| | Laravel Horizon | 5.x | Dashboard supervision queues |
| | Laravel Sanctum | 4.x | Cookie SPA |
| | Spatie Permission | 6.x | RBAC |
| | Spatie Data | 4.x | DTOs typés |
| | Spatie Model States | 2.x | Waterfall state machine |
| | Pragmarx Google2FA | 8.x | TOTP 2FA |
| **DB** | PostgreSQL | 16.x | + extensions pg_trgm, postgis 3.4, pgvector 0.7, pg_partman 5.x |
| **Cache/Queues** | Redis | 7.4.x | AOF persistance |
| **Workers Node** | Node.js | 22 LTS | |
| | Playwright | 1.49+ | Chromium only |
| | playwright-extra | 4.x | + plugin-stealth |
| | BullMQ | 5.x | |
| | Cheerio | 1.x | parsing HTML léger |
| | Pino | 9.x | logs JSON |
| **Frontend** | React | 19.x | |
| | TypeScript | 5.6+ | strict mode |
| | Vite | 6.x | |
| | Tailwind CSS | 4.x | + Tailwind UI + shadcn/ui |
| | React Router | 7.x | |
| | TanStack Query | 5.x | |
| | TanStack Virtual | 3.x | |
| | MapLibre GL JS | 4.x | |
| | Recharts | 2.x | |
| | @dnd-kit | 6.x | Phase 2 pipeline CRM |
| **Edge** | Caddy | 2.x | HTTPS auto Let's Encrypt |
| | Cloudflare | Free | proxy, bot fight, HSTS |
| **Observability** | Prometheus | 2.55+ | |
| | Grafana | 11.x | dashboards |
| | Loki | 3.x | logs JSON labels |
| | Tempo | 2.x | traces OpenTelemetry |
| | GlitchTip | 4.x | OSS Sentry alternative |
| | Uptime Kuma | 1.23+ | synthetic monitors |
| **Secrets** | Infisical | self-hosted | ou Doppler |
| **CI/CD** | GitHub Actions | n/a | + cache + reusable workflows |
| **Container orch** | Docker Compose | v2 | dev |
| | Coolify | v4 | prod (default) |
| | k3s | 1.30+ | option Phase 2 si volumétrie justifie |

---

## Dimensionnement Hetzner Compte 2 — V1

> Tarifs Hetzner Cloud constatés 2026-05 (HT, en euros, hors Volume).

| Serveur | Type | vCPU | RAM | Stockage | IPv4 | Rôle | Coût €/mois |
|---|---|---|---|---|---|---|---|
| `edge-01` | CCX23 | 4 dedi | 16 Go | 240 Go NVMe | ✅ | Caddy reverse proxy + TLS | **30,00** |
| `app-01` | CCX23 | 4 dedi | 16 Go | 240 Go NVMe | ✅ | Laravel API + Octane + frontend static | **30,00** |
| `app-02` | CCX23 | 4 dedi | 16 Go | 240 Go NVMe | ✅ | Scheduler + Horizon master + GlitchTip ingest | **30,00** |
| `db-01` | CCX33 | 8 dedi | 32 Go | 480 Go NVMe | ✅ | PostgreSQL 16 primary + replica logique S2 | **60,00** |
| `redis-01` | CCX13 | 2 dedi | 8 Go | 80 Go NVMe | ✅ | Redis 7 cache + queues | **12,00** |
| `worker-php-01` | CCX23 | 4 dedi | 16 Go | 240 Go NVMe | ✅ | Horizon workers PHP (HTTP léger, BD, LLM, SMTP) | **30,00** |
| `worker-node-01` | CPX31 | 4 AMD | 8 Go | 240 Go NVMe | ✅ | Workers Node.js + Playwright (Chromium stealth) | **16,00** |
| `worker-node-02` | CPX31 | 4 AMD | 8 Go | 240 Go NVMe | ✅ | idem (parallélisme + IPs distinctes) | **16,00** |
| `obs-01` | CCX23 | 4 dedi | 16 Go | 240 Go NVMe | ✅ | Grafana + Prometheus + Loki + Tempo + Uptime Kuma + GlitchTip storage | **30,00** |
| `backup-vol-01` | Volume 1 To | n/a | n/a | 1 To HDD | n/a | Snapshots + dumps Postgres avant push B2 | **40,00** |
| `llm-gpu-01` | EX44-NVMe (dedi) | Ryzen 5 | 64 Go | 2 To NVMe + RTX 4000 SFF Ada | ✅ | OPTIONNEL Phase 1 — Ollama Llama 3.3 70B Q4 + Mistral 7B | **70,00** |
| **Sous-total Cloud + Volume** | | | | | | | **264,00** (sans GPU) |
| **Avec GPU dédié** | | | | | | | **334,00** |

### Notes de dimensionnement

- **app-01/app-02 en CCX23 dedicated** : Octane consomme du CPU constant, on évite shared. 4 vCPU × 2 = capacité ~600 req/s sur API CRUD basique, largement suffisant en V1.
- **db-01 en CCX33 dedicated** : critical path. 32 Go RAM permettent un `shared_buffers = 8 Go`, `effective_cache_size = 24 Go`, `work_mem = 128 Mo` (relevé suite audit P0 #5). Tient ~50M lignes sans souci.
- **🔑 PgBouncer co-localisé sur `db-01`** (container Docker dédié, port 6432) — audit P0 #5. Mode `transaction pooling`, `MAX_CLIENT_CONN=500`, `DEFAULT_POOL_SIZE=25`. Multiplexe ~500 connexions clientes Octane/Horizon vers ~25 connexions Postgres réelles. Sans ce pooler, `max_connections=100` Postgres saturait dès S6 avec 32 workers Horizon + Octane + scheduler.
- **redis-01 en CCX13** : Redis est I/O-bound léger. 8 Go RAM permet de stocker tous les jobs en queue + le cache TanStack Query côté serveur. Persistance AOF activée.
- **worker-php-01 CCX23** : PHP-FPM tient bien sur dedicated. 32 workers Horizon concurrent par défaut.
- **worker-node-01/02 en CPX31 (AMD shared)** : Playwright Chromium consomme de la RAM (~300 Mo par contexte), 8 Go = ~24 contextes parallèles confortables. AMD shared OK car les bursts sont courts (page load, scroll, attente network).
  > **Pourquoi pas CAX ARM ?** Playwright sur ARM Linux a parfois des soucis avec certains plugins stealth qui sniffent `process.arch`. On reste sur x64 AMD pour fiabilité.
- **obs-01 CCX23** : Loki + Prometheus + Grafana + Tempo tournent confortablement en Go. 240 Go NVMe permet ~3 mois de logs JSON avec retention.
- **backup-vol-01 (Volume 1 To)** : montée sur `db-01` ou `worker-php-01`. Reçoit les `pg_dump` chiffrés horaires + snapshots full quotidiens. Push offsite vers Backblaze B2 nightly.
- **llm-gpu-01 OPTIONNEL** : ne provisionner que si la volumétrie le justifie (>15M tokens/mois sur use cases routés Llama). V1 peut démarrer sans, tout via APIs externes (~150-250€/mois LLM API).

### Coûts récapitulatifs

| Phase | Configuration | Coût Hetzner €/mois |
|---|---|---|
| V1 démarrage (sans GPU) | edge + app×2 + db + redis + worker-php + worker-node×2 + obs + backup vol | **264 €** |
| V1 confort (avec GPU Ollama) | + llm-gpu-01 | **334 €** |
| Phase 2 scale (S15+, +1M entreprises/mois) | + app-03 + worker-php-02 + worker-node-03/04 + replica db | **~480 €** |

> Cf. fichier `21_couts_roadmap.md` pour le coût mensuel **total tout compris** (Hetzner + proxies + PhantomBuster + LLM APIs + B2 + domaine), estimé **600-700€/mois** en V1.

---

## Réseau privé vSwitch

- **vSwitch ID :** `axion-crm-private`
- **Range :** `10.20.0.0/16`
- **Coût :** 0€ (gratuit chez Hetzner)
- **IP assignment :**

| Serveur | IP privée |
|---|---|
| edge-01 | 10.20.0.10 |
| app-01 | 10.20.0.20 |
| app-02 | 10.20.0.21 |
| db-01 | 10.20.0.30 |
| redis-01 | 10.20.0.40 |
| worker-php-01 | 10.20.0.50 |
| worker-node-01 | 10.20.0.60 |
| worker-node-02 | 10.20.0.61 |
| obs-01 | 10.20.0.70 |
| llm-gpu-01 | 10.20.0.80 |

**Bénéfices :**
- Latence inter-serveurs < 0,5 ms
- Pas de trafic entrant sur IPv4 publique pour DB/Redis (firewall : drop everything except port 22 SSH from Will's IP + port 80/443 sur edge-01 + port 6443 Coolify si appliqué)
- Pas de coût bandwidth interne

**Firewall règles globales (Hetzner Cloud Firewall) :**
- Tous les serveurs : SSH 22 → uniquement IP Will + GitHub Actions runners (whitelist via OIDC)
- edge-01 : 80 + 443 → 0.0.0.0/0
- app-01/app-02 : 8080 → vSwitch 10.20.0.0/16 only
- db-01 : 5432 → vSwitch only
- redis-01 : 6379 → vSwitch only
- worker-* : pas d'inbound
- obs-01 : 3000 (Grafana) → vSwitch only (accès Will via SSH tunnel)

---

## Isolation totale d'axion-ia.com (justifications)

| Élément | Compte 1 (axion-ia.com) | Compte 2 (Axion CRM Pro) | Note |
|---|---|---|---|
| Compte Hetzner | `axion-ia@axion-ia.com` | `axion-crm@axion-ia.com` | Email facturation séparé |
| Datacenter | fsn1 (Frankfurt) | fsn1 (Frankfurt) | Même DC OK, comptes séparés |
| Domaine | `axion-ia.com` | `crm.axion-ia.com` (sous-domaine DNS) | DNS Namecheap commun mais résolu vers IPs distinctes |
| IP publique | `178.105.55.15` (CPX42) | nouvelles IPs (edge-01) | Aucun chevauchement |
| Postgres | partagé avec axion-ia.com app | dédié Compte 2 | Pas de schéma cross-projet |
| Redis | partagé axion-ia.com | dédié Compte 2 | Idem |
| Secrets vault | `.secrets/api-tokens.env` Axion-IA | `.secrets/api-tokens.env` séparé Compte 2 | Tokens Hetzner/Cloudflare ≠ |
| Code repo | `axionia/` (Next.js) | `axion-crm-pro/` (Laravel+React+Node) | Pas de monorepo |
| Logs / monitoring | obs-01 Compte 1 | obs-01 Compte 2 | Pas de dashboards mélangés |
| Backups offsite | B2 bucket `axionia-backups` | B2 bucket `axion-crm-pro-backups` | Buckets séparés, clés séparées |

**Bénéfices d'isolation :**
1. Une faille de sécurité sur Axion CRM Pro (scraping, attaque) n'expose pas axion-ia.com (vitrine commerciale).
2. Un bug DB ou un drop de table sur Axion CRM Pro n'impacte pas le site marketing.
3. Compta Hetzner séparée = lecture coût simplifiée.
4. Si Williams vend un jour Axion CRM Pro à un partenaire, transfert compte Hetzner = clean.

**Coût supplémentaire de l'isolation :** ~0€ (pas de coût d'avoir 2 comptes Hetzner). Le seul "coût" est de gérer 2 sets de credentials Hetzner — résolu par fichier `.secrets/api-tokens.env` séparé.

---

## Schéma des flux de données critiques

### Flux 1 — Scraping nouvelle entreprise (waterfall complet)

```
[Will clique "Scraper département 75 (Paris)" sur la carte]
   │
   ▼
[POST /api/scraper/zones/75/launch  → Laravel API valide policies + audit log]
   │
   ▼
[Laravel dispatch job → Redis queue `insee-fetch` pour chaque code commune Paris]
   │
   ▼
[Horizon worker PHP consomme `insee-fetch`]
   ├─► [Call API INSEE Sirene → liste SIREN]
   │   └─► [Pour chaque SIREN inconnu en DB : INSERT companies + dispatch enrichment]
   │
   ▼
[Dispatch `annuaire-entreprises-enrich` pour chaque SIREN]
   │
   ▼
[Horizon worker PHP → Call annuaire-entreprises.data.gouv.fr API → dirigeants + CA + bilans]
   │
   ▼
[Dispatch `bodacc-check` (signaux), `gmaps-scrape` (BullMQ → Node), etc.]
   │
   ▼
[Workers Node.js Playwright stealth → scrape Google Maps + Pages Jaunes + Site web entreprise]
   │
   ▼
[Site web crawl → extract TOUS emails classifiés (nominative/role_based/generic/no_reply)]
   │
   ▼
[Dispatch `email-validate` → SMTP cascade]
   │
   ▼
[Dispatch `llm-tasks` → classification (use cases ia_maturity_scoring + axion_offer_match + auto_tag_generation)]
   │
   ▼
[UPDATE companies SET priority_score = calculé, axion_offer = matched, tags = LLM-generated]
   │
   ▼
[Insert audit_log + scraper_runs final OK]
   │
   ▼
[Carte coverage matrix : refresh materialized view nightly OU déclencheur sur seuil]
   │
   ▼
[Will voit l'entreprise enrichie dans la console < 30 secondes après le clic]
```

### Flux 2 — Détection signaux business nightly

```
[Cron 02:00 → Laravel Scheduler → dispatch job `nightly-bodacc-poll`]
   │
   ▼
[Horizon worker PHP itère sur SIREN actifs en DB (~200k entreprises)]
   │   └─► batch 5000 SIREN / requête BODACC
   │
   ▼
[Détection : changement dirigeant, levée fonds, redressement, création/radiation]
   │
   ▼
[INSERT company_business_signals + UPDATE companies.priority_score]
   │
   ▼
[Si signal CRITIQUE (levée > 1M€ ou nouveau DSI) → notification Slack + Telegram]
   │
   ▼
[Dispatch éventuel `axion-offer-match` re-classification si signal change matching]
```

---

## Indépendance technique stricte : vérifications à faire en S1

- [ ] Compte Hetzner Compte 2 créé (`axion-crm@axion-ia.com`)
- [ ] Token API Hetzner Compte 2 généré et stocké dans `.secrets/api-tokens.env` séparé
- [ ] Cloudflare zone `axion-ia.com` mise à jour avec record A `crm.axion-ia.com → IP edge-01 Compte 2`
- [ ] vSwitch créé `axion-crm-private` (10.20.0.0/16)
- [ ] Firewall Cloud Hetzner créée + rules appliquées
- [ ] Bucket Backblaze B2 `axion-crm-pro-backups` créé (clé séparée)
- [ ] Compte DNS Cloudflare : pas de mélange de zones DNS (1 seule zone, mais records séparés clairs)
- [ ] Secrets vault Infisical : projet Axion CRM Pro distinct du projet axion-ia.com
- [ ] Repo GitHub `axion-crm-pro` créé (déjà fait) — pas de monorepo
- [ ] GitHub Actions secrets `axion-crm-pro` : `HETZNER_TOKEN_C2`, `COOLIFY_API_TOKEN_C2`, `B2_KEY_ID_C2`, `B2_APP_KEY_C2`, etc.

→ Voir fichier `18_deploiement_hetzner.md` pour la procédure complète et runbooks.

---

## Prochaine étape

→ Lire `03_db_schema_phase1.md` pour le schéma DB complet Phase 1 (~30 tables PostgreSQL avec FK, indexes, RLS, partitionnement).
