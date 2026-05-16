# 18 — DÉPLOIEMENT HETZNER

## Vue d'ensemble

Déploiement V1 sur **compte Hetzner Cloud dédié Compte 2** (cf fichier 02). Stratégie : **Docker Compose en dev** identique à **Docker Compose en staging** identique à **Coolify v4 en prod** (pour la simplicité opérationnelle d'un dev solo). Évolution Phase 2 : migration vers k3s si volumétrie justifie.

Caddy en reverse proxy avec HTTPS auto Let's Encrypt. GitHub Actions pour CI/CD. Backups quotidiens chiffrés vers Backblaze B2 offsite.

---

## 1. Schéma infrastructure Compte 2 récap

Cf fichier 02 pour le diagramme complet. IPs privées vSwitch `10.20.0.0/16` :

| Serveur | Public IPv4 | Privée | Rôle |
|---|---|---|---|
| edge-01 | publique | 10.20.0.10 | Caddy reverse proxy HTTPS |
| app-01 | privée | 10.20.0.20 | Laravel API Octane + frontend static |
| app-02 | privée | 10.20.0.21 | Scheduler + Horizon master |
| db-01 | privée | 10.20.0.30 | PostgreSQL 16 |
| redis-01 | privée | 10.20.0.40 | Redis 7 |
| worker-php-01 | privée | 10.20.0.50 | Horizon workers PHP |
| worker-node-01 | publique | 10.20.0.60 | Workers Node Playwright |
| worker-node-02 | publique | 10.20.0.61 | Workers Node Playwright |
| obs-01 | privée | 10.20.0.70 | Grafana/Prometheus/Loki/Tempo |
| backup-vol-01 | n/a | montée db-01 | Volume 1 To backups |

Firewall Cloud Hetzner :
- edge-01 : 80/443 → 0.0.0.0/0, 22 → IP Will + GH Actions runners
- app-*, db-01, redis-01, worker-*, obs-01 : 22 → IP Will, autres ports → vSwitch only

---

## 2. `docker-compose.yml` maître (prod via Coolify)

```yaml
# infra/docker-compose.prod.yml
version: '3.9'

x-app-base: &app-base
  image: ghcr.io/will383842/axion-crm-pro/app:${RELEASE_SHA}
  restart: unless-stopped
  env_file: .env.prod
  networks: [axion-crm-net]
  logging:
    driver: loki
    options:
      loki-url: http://obs-01:3100/loki/api/v1/push
      labels: service,env

services:
  caddy:
    image: caddy:2-alpine
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./caddy/Caddyfile:/etc/caddy/Caddyfile:ro
      - caddy-data:/data
      - caddy-config:/config
    networks: [axion-crm-net]
    depends_on: [app-api, frontend]

  app-api:
    <<: *app-base
    command: php artisan octane:start --server=swoole --host=0.0.0.0 --port=8080
    deploy:
      replicas: 2
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8080/api/monitoring/health"]
      interval: 30s
      timeout: 5s
      retries: 3

  scheduler:
    <<: *app-base
    command: php artisan schedule:work
    deploy:
      replicas: 1

  horizon:
    <<: *app-base
    command: php artisan horizon
    deploy:
      replicas: 1
    healthcheck:
      test: ["CMD", "php", "artisan", "horizon:status"]

  worker-php:
    <<: *app-base
    command: php artisan queue:work --queue=high,default,low --tries=3 --backoff=30
    deploy:
      replicas: 4

  worker-node:
    image: ghcr.io/will383842/axion-crm-pro/workers:${RELEASE_SHA}
    restart: unless-stopped
    env_file: .env.workers
    networks: [axion-crm-net]
    deploy:
      replicas: 4

  frontend:
    image: ghcr.io/will383842/axion-crm-pro/frontend:${RELEASE_SHA}
    restart: unless-stopped
    networks: [axion-crm-net]

  postgres:
    image: postgis/postgis:16-3.4
    restart: unless-stopped
    environment:
      POSTGRES_DB: axion_crm_pro
      POSTGRES_USER: axion_crm_app
      POSTGRES_PASSWORD_FILE: /run/secrets/postgres_password
    volumes:
      - pgdata:/var/lib/postgresql/data
      - ./postgres/init:/docker-entrypoint-initdb.d
    secrets: [postgres_password]
    networks: [axion-crm-net]
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U axion_crm_app"]
    # ⚠️ Postgres NE DOIT PAS être joignable directement par l'app — toujours via pgbouncer
    # Exposer uniquement au container pgbouncer
    expose: ["5432"]

  # 🔑 Audit P0 #5 — PgBouncer transaction pooling OBLIGATOIRE
  # Sans pooler, Laravel Octane Swoole + 32 Horizon workers + scheduler saturent
  # max_connections=100 défaut Postgres. PgBouncer multiplexe ~500 client conns → ~25 server conns.
  pgbouncer:
    image: edoburu/pgbouncer:1.23
    restart: unless-stopped
    environment:
      DB_HOST: postgres
      DB_PORT: 5432
      DB_USER: axion_crm_app
      DB_PASSWORD_FILE: /run/secrets/postgres_password
      DB_NAME: axion_crm_pro
      POOL_MODE: transaction                       # transaction pooling (critical pour Octane)
      MAX_CLIENT_CONN: 500                         # 500 connexions clientes simultanées
      DEFAULT_POOL_SIZE: 25                        # 25 connexions vers Postgres réutilisées
      RESERVE_POOL_SIZE: 5
      RESERVE_POOL_TIMEOUT: 3
      SERVER_IDLE_TIMEOUT: 60
      SERVER_LIFETIME: 3600
      QUERY_WAIT_TIMEOUT: 30
      IGNORE_STARTUP_PARAMETERS: extra_float_digits
      # ⚠️ En mode transaction pooling, certaines features Postgres sont incompatibles :
      # - prepared statements côté serveur (Laravel doit utiliser PDO::ATTR_EMULATE_PREPARES=true)
      # - SET LOCAL pour RLS workspace_id OK (transaction-scoped)
      # - LISTEN/NOTIFY → utiliser canal direct postgres si besoin Phase 2
    ports: ["6432:6432"]
    secrets: [postgres_password]
    networks: [axion-crm-net]
    depends_on:
      postgres:
        condition: service_healthy
    healthcheck:
      test: ["CMD-SHELL", "psql -h localhost -p 6432 -U axion_crm_app -d pgbouncer -c 'SHOW POOLS;' || exit 1"]
      interval: 30s

  redis:
    image: redis:7-alpine
    restart: unless-stopped
    command: ["redis-server", "--appendonly", "yes", "--requirepass", "$REDIS_PASSWORD"]
    volumes:
      - redisdata:/data
    networks: [axion-crm-net]

  prometheus:
    image: prom/prometheus:v2.55.0
    restart: unless-stopped
    volumes:
      - ./prometheus:/etc/prometheus
      - promdata:/prometheus
    networks: [axion-crm-net]

  grafana:
    image: grafana/grafana:11.2.0
    restart: unless-stopped
    environment:
      GF_SECURITY_ADMIN_PASSWORD_FILE: /run/secrets/grafana_admin_password
    volumes:
      - grafdata:/var/lib/grafana
      - ./grafana/provisioning:/etc/grafana/provisioning
      - ./grafana/dashboards:/var/lib/grafana/dashboards
    secrets: [grafana_admin_password]
    networks: [axion-crm-net]

  loki:
    image: grafana/loki:3.0.0
    restart: unless-stopped
    volumes:
      - lokidata:/loki
      - ./loki/loki.yml:/etc/loki/loki.yml
    command: -config.file=/etc/loki/loki.yml
    networks: [axion-crm-net]

  tempo:
    image: grafana/tempo:2.5.0
    restart: unless-stopped
    volumes:
      - tempodata:/tmp/tempo
      - ./tempo/tempo.yml:/etc/tempo.yml
    command: -config.file=/etc/tempo.yml
    networks: [axion-crm-net]

  glitchtip:
    image: glitchtip/glitchtip:v4.2
    restart: unless-stopped
    env_file: .env.glitchtip
    volumes: [glitchtip:/data]
    networks: [axion-crm-net]

networks:
  axion-crm-net:
    driver: bridge

volumes:
  pgdata: { driver: local }
  redisdata: { driver: local }
  caddy-data: { driver: local }
  caddy-config: { driver: local }
  promdata: { driver: local }
  grafdata: { driver: local }
  lokidata: { driver: local }
  tempodata: { driver: local }
  glitchtip: { driver: local }

secrets:
  postgres_password:
    file: ./secrets/postgres_password.txt
  grafana_admin_password:
    file: ./secrets/grafana_admin_password.txt
```

> **Note Coolify v4** : Coolify gère lui-même le déploiement à partir de ce compose. On le déclenche via Coolify API (token Will dans GitHub Secrets).

---

## 3. Dockerfile PHP-FPM Laravel (multi-stage)

```dockerfile
# infra/docker/php/Dockerfile
ARG PHP_VERSION=8.3

FROM composer:2.7 AS composer

FROM node:22-alpine AS frontend-build
WORKDIR /app
COPY frontend/package*.json ./frontend/
RUN cd frontend && npm ci
COPY frontend/ ./frontend/
RUN cd frontend && npm run build

FROM php:${PHP_VERSION}-cli-alpine AS php-build
RUN apk add --no-cache git curl libpng-dev libjpeg-turbo-dev libwebp-dev oniguruma-dev libxml2-dev zip unzip postgresql-dev linux-headers \
    && docker-php-ext-install pdo_pgsql mbstring exif pcntl bcmath gd opcache \
    && pecl install redis swoole openswoole && docker-php-ext-enable redis openswoole
COPY --from=composer /usr/bin/composer /usr/bin/composer
WORKDIR /var/www
COPY backend/composer.* ./
RUN composer install --no-dev --optimize-autoloader --no-scripts --prefer-dist
COPY backend/ ./
RUN composer dump-autoload --optimize --classmap-authoritative \
    && php artisan event:cache \
    && php artisan view:cache

FROM php:${PHP_VERSION}-cli-alpine AS runtime
RUN apk add --no-cache libpng libjpeg-turbo libwebp postgresql-libs oniguruma \
    && docker-php-ext-enable opcache
RUN apk add --no-cache --virtual .build-deps autoconf gcc g++ make linux-headers postgresql-dev libpng-dev libjpeg-turbo-dev libwebp-dev oniguruma-dev \
    && docker-php-ext-install pdo_pgsql mbstring exif pcntl bcmath gd opcache \
    && pecl install redis swoole openswoole && docker-php-ext-enable redis openswoole \
    && apk del .build-deps
WORKDIR /var/www
COPY --from=php-build --chown=www-data:www-data /var/www /var/www
COPY --from=frontend-build --chown=www-data:www-data /app/frontend/dist /var/www/public/
EXPOSE 8080
USER www-data
CMD ["php", "artisan", "octane:start", "--server=swoole", "--host=0.0.0.0", "--port=8080"]
```

---

## 4. Dockerfile Workers Node Playwright

```dockerfile
# infra/docker/node-playwright/Dockerfile
FROM mcr.microsoft.com/playwright:v1.49.0-noble AS runtime

# Sécurité : run as non-root
RUN useradd -m -u 1000 worker && mkdir -p /workers && chown worker:worker /workers
WORKDIR /workers

# Install deps
COPY workers/package*.json ./
RUN npm ci --omit=dev

# Copy compiled TS (assume CI a fait tsc avant)
COPY workers/dist/ ./dist/
COPY workers/playwright.config.ts ./

USER worker
CMD ["node", "dist/index.js"]
```

---

## 5. Caddyfile

```
# infra/docker/caddy/Caddyfile
{
    email contact@axion-ia.com
    admin off
    servers {
        protocols h1 h2 h3
    }
}

crm.axion-ia.com {
    encode zstd gzip
    request_body { max_size 10mb }
    @nonStatic not path /assets/* /api/geo/*.geojson
    rate_limit @nonStatic { zone api { events 600 window 1m } }

    handle_path /api/* {
        reverse_proxy app-api:8080 {
            health_uri /api/monitoring/health
            health_interval 30s
        }
    }
    handle /assets/* {
        reverse_proxy frontend:80
        header Cache-Control "public, max-age=31536000, immutable"
    }
    handle /api/geo/*.geojson {
        reverse_proxy app-api:8080
        header Cache-Control "public, max-age=604800"   # 7j navigateur
    }
    handle {
        reverse_proxy frontend:80
    }

    log {
        output stdout
        format json
    }

    header {
        Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
        X-Frame-Options "DENY"
        X-Content-Type-Options "nosniff"
        Referrer-Policy "strict-origin-when-cross-origin"
        Permissions-Policy "geolocation=(), microphone=(), camera=()"
        -Server
    }
}
```

---

## 6. GitHub Actions workflows

### `.github/workflows/ci.yml`

```yaml
name: CI
on:
  push: { branches: [main, develop] }
  pull_request:

jobs:
  backend-tests:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgis/postgis:16-3.4
        env: { POSTGRES_DB: axion_test, POSTGRES_USER: axion, POSTGRES_PASSWORD: pass }
        options: --health-cmd="pg_isready -U axion" --health-interval=10s
      redis:
        image: redis:7-alpine
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', extensions: pdo_pgsql,redis,bcmath,gd }
      - run: composer install --no-interaction --prefer-dist
        working-directory: backend
      - run: php artisan migrate --force --env=testing
        working-directory: backend
      - run: ./vendor/bin/pest --coverage --min=80
        working-directory: backend

  frontend-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: '22' }
      - run: npm ci
        working-directory: frontend
      - run: npm run typecheck
        working-directory: frontend
      - run: npm run test
        working-directory: frontend
      - run: npm run build
        working-directory: frontend

  e2e-tests:
    runs-on: ubuntu-latest
    needs: [backend-tests, frontend-tests]
    steps:
      - uses: actions/checkout@v4
      - run: docker compose -f infra/docker-compose.test.yml up -d --build
      - run: npx playwright test
        working-directory: e2e
      - if: failure()
        uses: actions/upload-artifact@v4
        with: { name: playwright-report, path: e2e/playwright-report }

  security-audit:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3' }
      - run: composer audit --no-interaction || true
        working-directory: backend
      - run: npm audit --omit=dev
        working-directory: frontend
      - run: docker run --rm -v $PWD:/repo aquasec/trivy:latest fs --severity HIGH,CRITICAL /repo
```

### `.github/workflows/deploy.yml`

```yaml
name: Deploy
on:
  push: { branches: [main] }
  workflow_dispatch:

jobs:
  build-push:
    runs-on: ubuntu-latest
    permissions: { packages: write, contents: read }
    steps:
      - uses: actions/checkout@v4
      - uses: docker/setup-buildx-action@v3
      - uses: docker/login-action@v3
        with: { registry: ghcr.io, username: ${{ github.actor }}, password: ${{ secrets.GITHUB_TOKEN }} }
      - name: Build & push app image
        uses: docker/build-push-action@v6
        with:
          context: .
          file: infra/docker/php/Dockerfile
          push: true
          tags: ghcr.io/${{ github.repository }}/app:${{ github.sha }},ghcr.io/${{ github.repository }}/app:latest
          cache-from: type=gha
          cache-to: type=gha,mode=max
      - name: Build & push workers image
        uses: docker/build-push-action@v6
        with:
          context: .
          file: infra/docker/node-playwright/Dockerfile
          push: true
          tags: ghcr.io/${{ github.repository }}/workers:${{ github.sha }},ghcr.io/${{ github.repository }}/workers:latest
      - name: Build & push frontend image
        uses: docker/build-push-action@v6
        with:
          context: .
          file: infra/docker/frontend/Dockerfile
          push: true
          tags: ghcr.io/${{ github.repository }}/frontend:${{ github.sha }},ghcr.io/${{ github.repository }}/frontend:latest

  deploy:
    runs-on: ubuntu-latest
    needs: build-push
    environment: production
    steps:
      - name: Trigger Coolify redeploy
        run: |
          curl -X POST "${{ secrets.COOLIFY_URL }}/api/v1/deploy?uuid=${{ secrets.COOLIFY_APP_UUID }}&force=true" \
            -H "Authorization: Bearer ${{ secrets.COOLIFY_API_TOKEN }}"
```

---

## 7. Backups PostgreSQL (Hetzner Volume + Backblaze B2)

Script `infra/scripts/backup-postgres.sh` :

```bash
#!/bin/bash
set -euo pipefail

TS=$(date -u +%Y%m%dT%H%M%SZ)
DUMP_PATH="/mnt/backup/pg/axion_crm_pro_${TS}.sql.gz.gpg"
WAL_PATH="/mnt/backup/wal/${TS}.wal"

# 1. pg_dump chiffré (AES-256 via gpg)
docker exec axion-crm-postgres-1 pg_dump -U axion_crm_app axion_crm_pro \
  | gzip -9 \
  | gpg --batch --passphrase-file /etc/axion/gpg-passphrase --symmetric --cipher-algo AES256 \
  > "$DUMP_PATH"

# 2. Vérification taille raisonnable (> 1 Mo)
SIZE=$(stat -c%s "$DUMP_PATH")
if [ "$SIZE" -lt 1048576 ]; then
  echo "ERROR backup taille suspecte: $SIZE bytes"
  exit 1
fi

# 3. Push vers Backblaze B2 offsite
b2 upload-file axion-crm-pro-backups "$DUMP_PATH" "pg/$(basename $DUMP_PATH)"

# 4. Notification Slack
curl -X POST -H 'Content-type: application/json' \
  --data "{\"text\":\":floppy_disk: PG backup OK $(basename $DUMP_PATH) — $(numfmt --to=iec $SIZE)\"}" \
  "$SLACK_WEBHOOK_URL"

# 5. Cleanup local > 7 jours
find /mnt/backup/pg -mtime +7 -delete
```

Crontab `db-01` :

```
0 2 * * * /usr/local/bin/backup-postgres.sh >> /var/log/axion-backup.log 2>&1
0 * * * * /usr/local/bin/backup-wal.sh        # archivage WAL hourly pour PITR
```

---

## 8. Disaster recovery

### RPO 1h / RTO 4h

- **RPO (Recovery Point Objective)** = 1h : grâce aux WAL archivés hourly + dump quotidien → on perd au pire 1h de données.
- **RTO (Recovery Time Objective)** = 4h : temps de reprovisionner + restaurer.

### Runbook restore complet

```bash
# 1. Snapshot Hetzner Volume (en cas où on touche au courant)
hcloud volume create-snapshot $BACKUP_VOL_ID --name "pre-restore-$(date +%Y%m%dT%H%M%S)"

# 2. Récupérer dernier dump chiffré depuis B2
b2 download-file-by-name axion-crm-pro-backups pg/axion_crm_pro_LATEST.sql.gz.gpg /tmp/restore.sql.gz.gpg

# 3. Déchiffrer
gpg --batch --passphrase-file /etc/axion/gpg-passphrase --decrypt /tmp/restore.sql.gz.gpg | gunzip > /tmp/restore.sql

# 4. Recréer DB (sur nouvelle instance ou existante)
docker exec axion-crm-postgres-1 dropdb --if-exists axion_crm_pro
docker exec axion-crm-postgres-1 createdb axion_crm_pro
docker exec -i axion-crm-postgres-1 psql -U axion_crm_app axion_crm_pro < /tmp/restore.sql

# 5. Replay WAL si dispo
# (psql en mode recovery.conf avec restore_command pointant /mnt/backup/wal/)

# 6. Verify
docker exec axion-crm-postgres-1 psql -U axion_crm_app -c "SELECT COUNT(*) FROM companies;"

# 7. Restart app
docker compose -f infra/docker-compose.prod.yml restart app-api horizon scheduler
```

### Runbook autres incidents

- **Redis perdu** : restart container (volume `redisdata` persistant). Si volume corrompu : `FLUSHALL` + repopulate via re-scheduling job (Horizon clear puis dispatch tasks).
- **Edge-01 down** : Coolify peut spawn nouveau edge-01 + DNS cutover (manuel) ou Cloudflare load balancer (futur).
- **Cloudflare down** : DNS de secours (Namecheap DNS direct vers IP edge-01, sans proxy CF).

---

## 9. Variables d'env critiques

`.env.prod` (côté Coolify) :

```env
APP_NAME="Axion CRM Pro"
APP_ENV=production
APP_KEY=base64:CHANGEME_via_artisan_key_generate
APP_URL=https://crm.axion-ia.com
APP_DEBUG=false
LOG_CHANNEL=loki
LOG_LEVEL=info

# DB — ⚠️ Audit P0 #5 : connexion via PgBouncer (transaction pooling) PAS directement Postgres
DB_CONNECTION=pgsql
DB_HOST=pgbouncer                                 # ← pas 'postgres' direct
DB_PORT=6432                                       # ← port PgBouncer, pas 5432
DB_DATABASE=axion_crm_pro
DB_USERNAME=axion_crm_app
DB_PASSWORD=CHANGEME
# Required avec PgBouncer transaction pooling :
DB_PREPARE_STATEMENTS=false                        # PDO::ATTR_EMULATE_PREPARES=true côté Laravel

# Redis
REDIS_HOST=redis
REDIS_PASSWORD=CHANGEME
REDIS_PORT=6379

# Sanctum
SANCTUM_STATEFUL_DOMAINS=crm.axion-ia.com
SESSION_DRIVER=redis
SESSION_DOMAIN=.axion-ia.com
SESSION_SECURE_COOKIE=true

# LLM
ANTHROPIC_API_KEY=via_vault
OPENAI_API_KEY=via_vault
MISTRAL_API_KEY=via_vault

# Monitoring
LOKI_URL=http://obs-01:3100
PROMETHEUS_BASIC_AUTH_USER=prometheus
PROMETHEUS_BASIC_AUTH_PASS=via_vault

# Secrets vault
INFISICAL_TOKEN=via_workflow_secret
INFISICAL_API=https://app.infisical.com

# Backup
BACKBLAZE_B2_KEY_ID=via_vault
BACKBLAZE_B2_APP_KEY=via_vault
BACKBLAZE_B2_BUCKET=axion-crm-pro-backups
```

---

## 10. Critères de done déploiement (S1 + S12)

- [ ] Compte Hetzner Compte 2 créé (`axion-crm@axion-ia.com`)
- [ ] 10 serveurs provisionnés + vSwitch 10.20.0.0/16
- [ ] Firewall Cloud Hetzner appliquée
- [ ] Coolify v4 installé sur app-01
- [ ] DNS `crm.axion-ia.com` → IP edge-01
- [ ] TLS Let's Encrypt actif (Caddy)
- [ ] Cloudflare proxy ON (bot fight + HSTS + SSL Full strict)
- [ ] GitHub Actions CI/CD fonctionnel (push main → deploy auto)
- [ ] Backups quotidiens chiffrés vers B2 testés (restore staging OK)
- [ ] Healthchecks + alerting opérationnels
- [ ] Domain `validator.axion-ia.com` configuré (rDNS + SPF + DKIM + DMARC)

---

## 11. Anti-patterns interdits

- ❌ Push secrets dans `.env.prod` versionné Git (utiliser Coolify secrets ou Infisical)
- ❌ Pas de healthcheck Docker (silent failures)
- ❌ Run as root dans containers
- ❌ Backup non chiffré
- ❌ Backup sans test restore (vérifier que ça remonte une fois par mois minimum)
- ❌ Déploiement manuel SSH (toujours via Coolify API)
- ❌ Migration DB en heures ouvrées sans review

---

## Prochaine étape

→ Lire `19_queues_workers_playwright.md` pour les 16 queues Horizon + workers.
