# 18 — Déploiement Hetzner + DR

> **Stack :** Hetzner Cloud Frankfurt + Coolify v4 + Caddy + docker-compose multi-stage + GH Actions auto-deploy + pgbackrest → Hetzner OBS + Backblaze B2.
> **RPO 1h / RTO 4h.**

---

## §1 — Schéma IPs (compte Hetzner CRM-Pro dédié)

| Serveur | Type | IP publique | IP privée vSwitch | Réservé pour |
|---------|------|-------------|-------------------|--------------|
| edge | CAX21 | `<IPv4-1>` / `<IPv6-1>` | 10.0.0.10 | Caddy reverse proxy + fail2ban |
| app | CPX31 | — | 10.0.0.20 | Laravel + Horizon |
| data | CCX13 | — | 10.0.0.30 | Postgres + Redis |
| worker-1 | CPX31 | — | 10.0.0.40 | Workers Node Playwright (sources masse) |
| worker-2 | CPX31 | — | 10.0.0.41 | Workers Node Playwright (sources ciblées + Direction Finder) |
| observability | CPX21 | — | 10.0.0.50 | Prometheus + Grafana + Loki + Tempo |
| staging | CCX13 | `<IPv4-2>` | 10.0.0.60 | Env iso-prod |
| gpu-ollama (opt.) | GEX44 | — | 10.0.0.70 | LLM local |

vSwitch ID `axion-crm-pro-vswitch` (10.0.0.0/16 private).

---

## §2 — Bootstrap initial

### Script `infra/bootstrap.sh`

```bash
#!/usr/bin/env bash
set -euo pipefail

HETZNER_TOKEN="${HETZNER_API_TOKEN}"
SSH_KEY="axion-crm-pro-key"
NETWORK="axion-crm-pro-vswitch"
LOCATION="fsn1"

# 1. Create network
hcloud network create --name $NETWORK --ip-range 10.0.0.0/16 --token $HETZNER_TOKEN
hcloud network add-subnet $NETWORK --type cloud --ip-range 10.0.0.0/24 --network-zone eu-central

# 2. Create servers
declare -A SERVERS=(
  [edge]="cax21"
  [app]="cpx31"
  [data]="ccx13"
  [worker-1]="cpx31"
  [staging]="ccx13"
)
for name in "${!SERVERS[@]}"; do
  hcloud server create \
    --name "$name" \
    --type "${SERVERS[$name]}" \
    --image "debian-12" \
    --location "$LOCATION" \
    --network "$NETWORK" \
    --ssh-key "$SSH_KEY" \
    --label "tag=$name" \
    --token "$HETZNER_TOKEN"
done

# 3. Firewall
hcloud firewall create --name "axion-crm-pro-fw" --token $HETZNER_TOKEN
hcloud firewall add-rule axion-crm-pro-fw --direction in --protocol tcp --port 22 --source-ips "$IP_HOME_WILL/32"
hcloud firewall add-rule axion-crm-pro-fw --direction in --protocol tcp --port 80 --source-ips 0.0.0.0/0,::/0
hcloud firewall add-rule axion-crm-pro-fw --direction in --protocol tcp --port 443 --source-ips 0.0.0.0/0,::/0
hcloud firewall apply-to-resource axion-crm-pro-fw --type label_selector --label-selector "tag=edge"

# 4. Reserved IPs (statiques)
hcloud floating-ip create --type ipv4 --home-location $LOCATION --name "edge-ipv4"
hcloud floating-ip assign edge-ipv4 edge

# 5. DNS Cloudflare (CLI flarectl)
flarectl dns create --zone axion-pro.com --name crm --type A --content "$(hcloud floating-ip describe edge-ipv4 -o json | jq -r .ip)"

echo "Bootstrap done. Next: provision Coolify on app server."
```

### Provision Coolify (sur `app`)

```bash
ssh root@10.0.0.20 'bash -s' <<'EOF'
# Install docker + docker-compose
curl -fsSL https://get.docker.com | sh
# Install Coolify
curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash
EOF
```

Accès Coolify : http://10.0.0.20:8000 (via SSH tunnel pour 1er accès, après HTTPS via Caddy).

---

## §3 — docker-compose maître

### Structure repo

```
infra/
├── docker-compose.app.yml         (sur server "app")
├── docker-compose.data.yml        (sur server "data")
├── docker-compose.worker.yml      (sur worker-1 + worker-2)
├── docker-compose.observability.yml
├── docker-compose.edge.yml
├── caddyfile                      (sur server "edge")
└── env/
    ├── app.env
    ├── data.env
    └── workers.env
```

### `docker-compose.app.yml` (extraits)

```yaml
version: '3.9'
name: axion-crm-pro-app

services:
  laravel:
    image: ghcr.io/axion-pro/laravel:${TAG:-latest}
    restart: unless-stopped
    env_file: env/app.env
    networks: [axion-net]
    ports: ["127.0.0.1:8080:80"]
    healthcheck:
      test: ["CMD-SHELL", "curl -fsS http://127.0.0.1/up || exit 1"]
      interval: 30s
      timeout: 5s
      retries: 3
    deploy:
      resources: { limits: { memory: 2G, cpus: '2' } }

  horizon:
    image: ghcr.io/axion-pro/laravel:${TAG:-latest}
    command: php artisan horizon
    restart: unless-stopped
    env_file: env/app.env
    networks: [axion-net]
    deploy:
      resources: { limits: { memory: 1.5G, cpus: '1.5' } }

  scheduler:
    image: ghcr.io/axion-pro/laravel:${TAG:-latest}
    command: php artisan schedule:work
    restart: unless-stopped
    env_file: env/app.env
    networks: [axion-net]
    deploy:
      resources: { limits: { memory: 256M, cpus: '0.2' } }

  meilisearch:
    image: getmeili/meilisearch:v1.10
    restart: unless-stopped
    environment:
      MEILI_MASTER_KEY: ${MEILI_MASTER_KEY}
      MEILI_ENV: production
    volumes: [meili-data:/meili_data]
    networks: [axion-net]
    deploy:
      resources: { limits: { memory: 1G } }

  promtail:
    image: grafana/promtail:3.2.0
    restart: unless-stopped
    volumes:
      - /var/lib/docker/containers:/var/lib/docker/containers:ro
      - /var/run/docker.sock:/var/run/docker.sock
      - ./promtail-config.yml:/etc/promtail/promtail-config.yml
    command: -config.file=/etc/promtail/promtail-config.yml
    networks: [axion-net]

networks:
  axion-net: { driver: bridge }

volumes:
  meili-data:
```

### `docker-compose.data.yml`

```yaml
version: '3.9'
name: axion-crm-pro-data

services:
  postgres:
    image: postgres:16-alpine
    restart: unless-stopped
    environment:
      POSTGRES_USER: axion_app
      POSTGRES_PASSWORD_FILE: /run/secrets/pg_password
      POSTGRES_DB: axion_crm_pro
    volumes:
      - pgdata:/var/lib/postgresql/data
      - ./postgresql.conf:/etc/postgresql/postgresql.conf:ro
      - ./pg_hba.conf:/etc/postgresql/pg_hba.conf:ro
    secrets: [pg_password]
    command: >-
      postgres
        -c config_file=/etc/postgresql/postgresql.conf
        -c hba_file=/etc/postgresql/pg_hba.conf
    ports: ["10.0.0.30:5432:5432"]
    networks: [data-net]
    deploy:
      resources: { limits: { memory: 4G, cpus: '2' } }
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U axion_app"]
      interval: 10s

  redis:
    image: redis:7-alpine
    restart: unless-stopped
    command: redis-server /usr/local/etc/redis/redis.conf
    volumes:
      - redisdata:/data
      - ./redis.conf:/usr/local/etc/redis/redis.conf:ro
    ports: ["10.0.0.30:6379:6379"]
    networks: [data-net]
    deploy:
      resources: { limits: { memory: 2G } }

  pgbouncer:
    image: bitnami/pgbouncer:1.22
    restart: unless-stopped
    environment:
      POSTGRESQL_HOST: postgres
      POSTGRESQL_USERNAME: axion_app
      POSTGRESQL_PASSWORD_FILE: /run/secrets/pg_password
      PGBOUNCER_POOL_MODE: transaction
      PGBOUNCER_MAX_CLIENT_CONN: 200
      PGBOUNCER_DEFAULT_POOL_SIZE: 50
    ports: ["10.0.0.30:6432:6432"]
    secrets: [pg_password]
    networks: [data-net]

  pgbackrest:
    image: pgbackrest/pgbackrest:latest
    restart: "no"
    volumes:
      - pgdata:/var/lib/postgresql/data:ro
      - ./pgbackrest.conf:/etc/pgbackrest/pgbackrest.conf
      - /var/lib/pgbackrest:/var/lib/pgbackrest
    networks: [data-net]
    profiles: [backup]      # invoqué via cron, pas démarré

secrets:
  pg_password: { file: ./secrets/pg_password.txt }

networks:
  data-net: { driver: bridge }

volumes:
  pgdata:
  redisdata:
```

### `docker-compose.worker.yml` (sur worker-1 + worker-2)

Cf. `02_architecture_infra.md` § Worker. Workers Node + Playwright cache.

### `docker-compose.observability.yml`

```yaml
version: '3.9'
name: axion-crm-pro-obs

services:
  prometheus:
    image: prom/prometheus:v2.55.0
    restart: unless-stopped
    volumes:
      - ./prometheus.yml:/etc/prometheus/prometheus.yml:ro
      - ./alert.rules.yml:/etc/prometheus/alert.rules.yml:ro
      - prometheus-data:/prometheus
    ports: ["127.0.0.1:9090:9090"]
    command:
      - '--config.file=/etc/prometheus/prometheus.yml'
      - '--storage.tsdb.retention.time=30d'
      - '--web.enable-lifecycle'

  grafana:
    image: grafana/grafana-oss:11.3.0
    restart: unless-stopped
    environment:
      GF_SECURITY_ADMIN_PASSWORD_FILE: /run/secrets/grafana_admin
      GF_AUTH_ANONYMOUS_ENABLED: "false"
      GF_SERVER_ROOT_URL: https://grafana.axion-pro.com
    volumes: [grafana-data:/var/lib/grafana, ./grafana-provisioning:/etc/grafana/provisioning:ro]
    ports: ["127.0.0.1:3000:3000"]
    secrets: [grafana_admin]

  loki:
    image: grafana/loki:3.2.0
    restart: unless-stopped
    volumes: [loki-data:/loki, ./loki-config.yml:/etc/loki/local-config.yaml:ro]
    ports: ["127.0.0.1:3100:3100"]
    command: -config.file=/etc/loki/local-config.yaml

  tempo:
    image: grafana/tempo:2.6.0
    restart: unless-stopped
    volumes: [tempo-data:/var/tempo, ./tempo-config.yml:/etc/tempo.yaml:ro]
    ports: ["127.0.0.1:3200:3200"]
    command: -config.file=/etc/tempo.yaml

  alertmanager:
    image: prom/alertmanager:v0.27.0
    restart: unless-stopped
    volumes: [./alertmanager.yml:/etc/alertmanager/alertmanager.yml:ro, alertmanager-data:/alertmanager]
    ports: ["127.0.0.1:9093:9093"]

  glitchtip:
    image: glitchtip/glitchtip:v4.1
    restart: unless-stopped
    environment: { DATABASE_URL: ..., SECRET_KEY: ... }
    ports: ["127.0.0.1:8000:8000"]

  uptime-kuma:
    image: louislam/uptime-kuma:1.23
    restart: unless-stopped
    volumes: [uptime-data:/app/data]
    ports: ["127.0.0.1:3001:3001"]

volumes:
  prometheus-data: {}
  grafana-data: {}
  loki-data: {}
  tempo-data: {}
  alertmanager-data: {}
  uptime-data: {}

secrets:
  grafana_admin: { file: ./secrets/grafana_admin.txt }
```

---

## §4 — Dockerfile multi-stage Laravel

```dockerfile
# infra/Dockerfile.laravel
ARG PHP_VERSION=8.3
ARG NODE_VERSION=22

# === Stage 1: composer dependencies ===
FROM composer:2.8 AS composer-deps
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# === Stage 2: frontend build (React) ===
FROM node:${NODE_VERSION}-alpine AS frontend
WORKDIR /app
COPY frontend/package.json frontend/pnpm-lock.yaml ./
RUN corepack enable && pnpm install --frozen-lockfile
COPY frontend/ ./
RUN pnpm build

# === Stage 3: php-fpm ===
FROM php:${PHP_VERSION}-fpm-alpine AS php
RUN apk add --no-cache nginx supervisor postgresql-dev oniguruma-dev libzip-dev icu-dev \
 && docker-php-ext-install pdo pdo_pgsql intl zip bcmath opcache pcntl

WORKDIR /var/www/html
COPY --from=composer-deps /app/vendor ./vendor
COPY --from=frontend /app/dist ./public
COPY backend/ ./
RUN composer dump-autoload --classmap-authoritative \
 && php artisan config:cache \
 && php artisan route:cache \
 && php artisan view:cache \
 && chown -R www-data:www-data storage bootstrap/cache

COPY infra/nginx.conf /etc/nginx/nginx.conf
COPY infra/supervisord.conf /etc/supervisor.d/laravel.ini
COPY infra/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-custom.conf

EXPOSE 80
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
```

### Dockerfile Node worker

```dockerfile
# infra/Dockerfile.worker
FROM mcr.microsoft.com/playwright:v1.49.0-jammy
WORKDIR /app
COPY workers/package.json workers/pnpm-lock.yaml ./
RUN corepack enable && pnpm install --frozen-lockfile
COPY workers/ ./
RUN pnpm build
CMD ["node", "dist/main.js"]
```

---

## §5 — GitHub Actions workflows

### `.github/workflows/ci.yml`

```yaml
name: CI
on: [push, pull_request]
jobs:
  laravel:
    runs-on: ubuntu-24.04
    services:
      postgres: { image: postgres:16-alpine, env: { POSTGRES_PASSWORD: secret, POSTGRES_DB: axion_test }, ports: ['5432:5432'] }
      redis: { image: redis:7-alpine, ports: ['6379:6379'] }
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: 8.3, extensions: pgsql, redis, intl, zip }
      - run: composer install --prefer-dist --no-progress
      - run: cp .env.example .env.testing && php artisan key:generate --env=testing
      - run: php artisan migrate --env=testing
      - run: vendor/bin/pest --parallel
      - run: vendor/bin/phpstan analyse

  frontend:
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4
      - uses: pnpm/action-setup@v4
      - uses: actions/setup-node@v4
        with: { node-version: 22, cache: pnpm }
      - run: pnpm install --frozen-lockfile
        working-directory: ./frontend
      - run: pnpm typecheck
        working-directory: ./frontend
      - run: pnpm test
        working-directory: ./frontend
      - run: pnpm build
        working-directory: ./frontend

  workers:
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4
      - uses: pnpm/action-setup@v4
      - uses: actions/setup-node@v4
        with: { node-version: 22, cache: pnpm }
      - run: pnpm install --frozen-lockfile
        working-directory: ./workers
      - run: pnpm typecheck && pnpm test
        working-directory: ./workers

  security:
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4
      - uses: aquasecurity/trivy-action@master
        with: { scan-type: fs, severity: 'CRITICAL,HIGH' }
      - run: composer audit || true
      - run: pnpm audit --audit-level=high --recursive || true
```

### `.github/workflows/deploy-staging.yml`

```yaml
name: Deploy staging
on:
  push: { branches: [main] }
jobs:
  build-push:
    runs-on: ubuntu-24.04
    steps:
      - uses: actions/checkout@v4
      - uses: docker/setup-buildx-action@v3
      - uses: docker/login-action@v3
        with: { registry: ghcr.io, username: ${{ github.actor }}, password: ${{ secrets.GITHUB_TOKEN }} }
      - uses: docker/build-push-action@v6
        with:
          file: infra/Dockerfile.laravel
          tags: ghcr.io/axion-pro/laravel:${{ github.sha }},ghcr.io/axion-pro/laravel:latest-staging
          cache-from: type=gha
          cache-to: type=gha,mode=max
          push: true
  deploy:
    needs: [build-push]
    runs-on: ubuntu-24.04
    steps:
      - name: Trigger staging redeploy via Coolify API
        run: |
          curl -X POST \
            -H "Authorization: Bearer ${{ secrets.COOLIFY_API_TOKEN }}" \
            "${{ secrets.COOLIFY_URL }}/api/v1/deploy?uuid=${{ secrets.COOLIFY_STAGING_APP_UUID }}"
  smoke:
    needs: [deploy]
    runs-on: ubuntu-24.04
    steps:
      - run: |
          for i in {1..30}; do
            if curl -sf https://staging.axion-pro.com/up; then break; fi
            sleep 10
          done
          curl -sf https://staging.axion-pro.com/up || exit 1
```

### `.github/workflows/deploy-prod.yml`

Trigger : `workflow_dispatch` manuel (avec confirmation typage), depuis tag `vX.Y.Z`.

Identique au staging mais target prod app + Coolify prod app UUID + smoke `crm.axion-pro.com`.

---

## §6 — Backups pgbackrest → Hetzner Object Storage

### Configuration

```ini
# infra/pgbackrest.conf
[global]
repo1-type=s3
repo1-s3-endpoint=fsn1.your-objectstorage.com
repo1-s3-bucket=axion-crm-pro-backups
repo1-s3-region=fsn1
repo1-s3-key=${HETZNER_OBS_KEY}
repo1-s3-key-secret=${HETZNER_OBS_SECRET}
repo1-cipher-type=aes-256-cbc
repo1-cipher-pass=${PGBACKREST_CIPHER_KEY}
repo1-retention-full=4
repo1-retention-diff=14
process-max=2
log-level-console=info

[main]
pg1-path=/var/lib/postgresql/data
pg1-port=5432
pg1-user=postgres
```

### Cron

```cron
# /etc/cron.d/pgbackrest
# Full weekly Sundays 03:00
0 3 * * 0 root docker compose -f /srv/axion-data/docker-compose.data.yml run --rm pgbackrest --stanza=main backup --type=full
# Diff daily 03:00 (except Sunday)
0 3 * * 1-6 root docker compose -f /srv/axion-data/docker-compose.data.yml run --rm pgbackrest --stanza=main backup --type=diff
# Expire old backups
0 4 * * * root docker compose ... run --rm pgbackrest --stanza=main expire
```

### Backblaze B2 réplication

```cron
# Daily 04:00 — rclone copy OBS → B2
0 4 * * * root rclone copy hetzner-obs:axion-crm-pro-backups b2:axion-crm-pro-backups-offsite --transfers=4 --bwlimit 20M
```

---

## §7 — Disaster Recovery

### Cibles

- **RPO 1 h** : perte max 1h de données (via WAL archiving continu Postgres)
- **RTO 4 h** : reconstruction full < 4h depuis backups

### Scénarios

#### Scénario A : Server data crash, backup OBS intact

1. Provision nouveau server CCX13 (10 min via `bootstrap.sh`)
2. Pull image postgres + pgbackrest config
3. `pgbackrest --stanza=main restore` (latest full + diff + WAL → ~30 min pour 50 GB)
4. Démarrer postgres avec `recovery.signal`
5. Update DNS interne 10.0.0.30 → nouveau server
6. Restart laravel + horizon (point Postgres au nouveau host)

**Durée estimée : 1-2 h.**

#### Scénario B : Datacenter Frankfurt down

1. Provision new compte Hetzner Helsinki (manuel, ~30 min)
2. Restore depuis Backblaze B2 (peut prendre 1-2h selon volume)
3. Update DNS Cloudflare vers IP Helsinki
4. Smoke tests
5. Reactiver workers

**Durée estimée : 2-4 h.**

#### Scénario C : Wholesale data corruption

1. Identification point-in-time corruption
2. PITR pgbackrest restore avec `--target-time='2026-05-16 12:00:00 UTC'`
3. Verification audit log hash chain
4. Diff data perdue (1h max RPO)
5. Communication aux users impactés si nécessaire

**Durée estimée : 1-2 h.**

### DR drill

Trimestriel : restore complet sur server temporaire (`disaster-test`), smoke tests, mesure RTO réel, post-mortem.

---

## §8 — Runbooks

### Runbook : "Site down 5xx massif"

1. Check Caddy logs : `ssh edge 'docker logs caddy --tail 200'`
2. Check Laravel up : `curl https://crm.axion-pro.com/up`
3. Si DB down : `ssh data 'docker ps' && 'docker logs postgres'`
4. Si Horizon stuck : `ssh app 'docker exec laravel php artisan horizon:status'` + restart container
5. Escalation : tag P0 incident channel #axion-crm-pro-prod

### Runbook : "Disk plein"

1. Identifier le serveur via Grafana
2. SSH + `docker system prune -af` (sauf data server)
3. Si data server : `docker exec postgres VACUUM FULL` (en heures creuses) ou attach volume bloc supplémentaire
4. Si grafana/loki : ajuster rétention

### Runbook : "Restart workers"

1. `ssh worker-1 'docker compose restart worker-google-maps worker-sites-web'`
2. Wait healthcheck (60s)
3. Monitor Horizon dashboard pour reprise queues

---

## §9 — Plan déploiement initial (S1-S12)

### S1

1. Création compte Hetzner CRM-Pro
2. Achat domaine `axion-pro.com` (Namecheap/OVH)
3. Setup Cloudflare compte distinct
4. Bootstrap script (créa serveurs + firewall + DNS)
5. Provision Coolify sur app
6. Setup Postgres + Redis sur data (manuel d'abord, Coolify ensuite)
7. Pousser repo GitHub initial
8. Premier deploy CI → staging

### S2-S4

Activation progressive workers, observability stack, monitoring complet.

### S12

Promotion staging → prod publique + Cloudflare orange + DNSSEC + HSTS preload + monitoring 100% UP.

---

## §10 — Cloudflare configuration (compte distinct)

- DNS : Full strict SSL, orange clouds
- Cache rules : bypass `/api/*` + `/sanctum/*` + `/tiles/*` (long cache)
- WAF : Bot Fight ON modéré, AI Scrapers OFF
- Page rules : redirect www.axion-pro.com → axion-pro.com
- Rate limiting : 600 req/min/IP
- DDoS protection : ON (gratuit Cloudflare Free tier)

---

## Lecture suivante

→ `19_queues_workers_playwright.md` (queues Horizon + workers Laravel + workers Node Playwright + bridge Redis).
