# MONITORING — Axion CRM Pro (Sprint 18.8)

## Pile choisie : GlitchTip self-hosted (gratuit, compatible Sentry SDK)

### Pourquoi pas Sentry SaaS

Sentry.io commence à 26€/mois pour la team plan (~50k events/mo). GlitchTip est un fork open source 100% compatible avec le SDK Sentry, self-hostable sur Docker (~50€/mois sur Hetzner CX22 si on veut une VM dédiée, ou colocaté sur le CPX22 actuel pour 0€/mois additionnel).

**Décision : on prévoit l'instrumentation (SDK init + workflow CI release tracking) mais on ne déploie pas GlitchTip immédiatement. Activation conditionnée à un budget Hetzner dédié OU 1er incident sérieux en prod.**

### Décision opérationnelle

| Phase | État | Coût/mois |
|---|---|---|
| Maintenant | SDK init posé, DSN vide → no-op | **0€** |
| Si besoin | Déployer GlitchTip sur CPX22 actuel (~512MB RAM in extra) | **0€** |
| Si volume élevé | VM Hetzner CX22 dédiée (~5€/mois) | **5€** |

## Déploiement GlitchTip sur Hetzner (procédure)

### Option A — Co-hosted sur le CPX22 actuel (0€)

```bash
cd /opt/axion-crm-pro
mkdir -p glitchtip && cd glitchtip
cat > docker-compose.glitchtip.yml <<'EOF'
services:
  glitchtip-redis:
    image: redis:7-alpine
    restart: unless-stopped
    networks: [axion-crm]

  glitchtip-postgres:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: glitchtip
      POSTGRES_USER: glitchtip
      POSTGRES_PASSWORD: ${GLITCHTIP_DB_PASSWORD}
    volumes:
      - glitchtip-pg-data:/var/lib/postgresql/data
    restart: unless-stopped
    networks: [axion-crm]

  glitchtip-web:
    image: glitchtip/glitchtip:latest
    depends_on: [glitchtip-postgres, glitchtip-redis]
    environment:
      DATABASE_URL: postgres://glitchtip:${GLITCHTIP_DB_PASSWORD}@glitchtip-postgres:5432/glitchtip
      SECRET_KEY: ${GLITCHTIP_SECRET_KEY}
      REDIS_URL: redis://glitchtip-redis:6379/0
      EMAIL_URL: smtp://...   # mailgun / postmark
      DEFAULT_FROM_EMAIL: glitchtip@axion-crm-pro.com
      GLITCHTIP_DOMAIN: https://glitchtip.axion-crm-pro.com
    ports:
      - "8001:8000"
    restart: unless-stopped
    networks: [axion-crm]

  glitchtip-worker:
    image: glitchtip/glitchtip:latest
    command: celery -A glitchtip worker
    depends_on: [glitchtip-postgres, glitchtip-redis]
    environment:
      DATABASE_URL: postgres://glitchtip:${GLITCHTIP_DB_PASSWORD}@glitchtip-postgres:5432/glitchtip
      SECRET_KEY: ${GLITCHTIP_SECRET_KEY}
      REDIS_URL: redis://glitchtip-redis:6379/0
    restart: unless-stopped
    networks: [axion-crm]

volumes:
  glitchtip-pg-data:

networks:
  axion-crm:
    external: true
EOF

# Caddy : reverse proxy
cat >> /opt/axion-crm-pro/infra/caddy/Caddyfile <<'EOF'
glitchtip.axion-crm-pro.com {
    encode zstd gzip
    reverse_proxy glitchtip-web:8000
}
EOF

docker compose -f docker-compose.glitchtip.yml up -d
```

### Option B — VM Hetzner CX22 dédiée (5€/mois)

Si Will veut isoler les ressources (recommandé en cas de >100k events/mois), provisionner un CX22 (2vCPU/4GB) à Helsinki/Nuremberg et déployer le compose ci-dessus dessus.

## Variables CI/CD à configurer (GitHub Actions secrets)

| Secret | Description | Exemple |
|---|---|---|
| `GLITCHTIP_AUTH_TOKEN` | Token API GlitchTip (Settings → Auth Tokens) | `gt_xxx...` |
| `GLITCHTIP_ORG` | Slug organisation | `axion-ia` |
| `GLITCHTIP_PROJECT` | Slug projet | `axion-crm-pro-frontend` |
| `SENTRY_URL` | Endpoint GlitchTip | `https://glitchtip.axion-crm-pro.com` |

Une fois ces secrets posés, le workflow `release-tracking.yml` activera automatiquement :
- Création de release sur tag push
- Upload source maps frontend
- Marquage deploy production

## Variables runtime

### Frontend (vite env)

| Variable | Description |
|---|---|
| `VITE_SENTRY_DSN` | DSN GlitchTip projet frontend (ex: `https://abc@glitchtip.axion-crm-pro.com/2`) |
| `VITE_SENTRY_ENVIRONMENT` | env (production/staging/dev) |
| `VITE_SENTRY_RELEASE` | poseé par le workflow CI build |

### Backend (Laravel env)

| Variable | Description |
|---|---|
| `SENTRY_LARAVEL_DSN` | DSN GlitchTip projet backend |
| `SENTRY_ENVIRONMENT` | env |
| `SENTRY_RELEASE` | release (poseé par CI deploy) |
| `SENTRY_TRACING_ENABLED` | `false` par défaut (perf) |
| `SENTRY_TRACES_SAMPLE_RATE` | `0.0` par défaut |

## Tests

- `frontend/src/lib/sentry.ts` : no-op si `VITE_SENTRY_DSN` vide
- `backend/config/sentry.php` : config en place mais `dsn=null` → SDK désactivé
- Workflow `release-tracking.yml` : guard `secrets.GLITCHTIP_AUTH_TOKEN`/`SENTRY_URL` → skip si absent

## Confirmation zéro coût additionnel

| Composant | Coût |
|---|---|
| `@sentry/react` (npm) | 0€ (open source MIT) |
| `@sentry/cli` (npm) | 0€ |
| `sentry/sentry-laravel` (composer) | 0€ |
| GlitchTip Docker images | 0€ |
| Co-host sur CPX22 (RAM disponible) | 0€ |
| GitHub Actions workflow | 0€ (inclus dans plan free) |

**Total : 0€/mois additionnel**.
