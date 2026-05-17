# Pipeline déploiement direct SSH Hetzner

Sprint 18.9 — workflow `deploy-direct-ssh.yml` qui SSH directement au serveur
Hetzner et exécute la procédure manuelle que Will faisait jusqu'ici. Plus
besoin de SSH manuel après chaque push.

## Vue d'ensemble

```
push main → GitHub Actions
            ├─ ssh-agent
            ├─ ssh root@HETZNER_HOST
            │    ├─ git fetch + reset --hard origin/main
            │    ├─ docker compose up -d --force-recreate api app
            │    ├─ docker compose exec api php artisan migrate --force
            │    └─ docker compose exec api php artisan config:clear
            └─ curl https://api.axion-crm-pro.com/up  (smoke)
```

## Secrets GitHub à configurer (une seule fois)

UI : `Settings` → `Secrets and variables` → `Actions` → `New repository secret`.

| Nom | Valeur | Obligatoire |
|---|---|---|
| `HETZNER_SSH_PRIVATE_KEY` | Contenu OpenSSH d'une clé privée autorisée sur le serveur (`cat ~/.ssh/id_ed25519`) | ✅ Oui |
| `HETZNER_HOST` | IP ou hostname Hetzner (ex. `46.62.248.239`) | ✅ Oui |
| `HETZNER_USER` | Utilisateur SSH | ❌ défaut `root` |
| `HETZNER_PROJECT_PATH` | Chemin du repo sur le serveur | ❌ défaut `/opt/axion-crm-pro` |

### Préparer la clé SSH dédiée GitHub Actions

Sur Will's laptop :

```bash
# 1. Génère une keypair dédiée (sans passphrase pour CI)
ssh-keygen -t ed25519 -f ~/.ssh/axion_crm_deploy_ed25519 -C "github-actions@axion-crm-pro" -N ""

# 2. Pousse la clé publique sur le serveur
ssh-copy-id -i ~/.ssh/axion_crm_deploy_ed25519.pub root@46.62.248.239

# 3. Récupère la clé privée pour la coller dans le secret GitHub
cat ~/.ssh/axion_crm_deploy_ed25519
```

Le contenu (`-----BEGIN OPENSSH PRIVATE KEY-----` … `-----END OPENSSH PRIVATE KEY-----`)
va dans le secret `HETZNER_SSH_PRIVATE_KEY`. Inclure les sauts de ligne.

### Variables (non sensibles)

UI : `Settings` → `Secrets and variables` → `Actions` → `Variables` tab.

| Nom | Valeur défaut | Description |
|---|---|---|
| `HEALTH_URL` | `https://api.axion-crm-pro.com/up` | Endpoint Laravel `health` |
| `APP_URL` | `https://app.axion-crm-pro.com` | Page d'accueil SPA |

## Triggers

- **Auto** : push sur `main` (sauf si seuls des `.md`, `_AUDIT/**`, `_REPORTS/**`, `spec/**`, `docs/**` sont modifiés).
- **Manuel** : `Actions` → `Deploy direct SSH Hetzner` → `Run workflow`. Option `skip_migrate` pour sauter les migrations.

## Différences avec `deploy-staging.yml` / `deploy-prod.yml`

| Aspect | direct-ssh (nouveau) | staging | prod |
|---|---|---|---|
| Cible | Hetzner Coolify-less | Coolify staging webhook | Coolify prod webhook + DR drill |
| Build images | ❌ pas de build (git pull source-mounted) | ✅ GHCR `:staging` | ✅ GHCR `:prod` |
| Migrations | ✅ inline `php artisan migrate --force` | déléguées à Coolify | déléguées à Coolify |
| Smoke | ✅ curl `/up` + app | ✅ curl `/up` + app | ✅ + audit verify-chain |
| Concurrency | sériel `deploy-direct-ssh` | sériel `deploy-staging` | sériel `deploy-prod` |

Le pipeline direct-ssh est volontairement plus simple — il convient quand Coolify
est hors course ou pour itérations rapides. Les pipelines Coolify restent
disponibles pour les déploiements "officiels" (avec build images, pentest, etc.).

## Rollback rapide

```bash
ssh root@46.62.248.239
cd /opt/axion-crm-pro
git log --oneline -10              # repérer le SHA stable
git reset --hard <sha-stable>
docker compose up -d --force-recreate api app
docker compose exec -u root api php artisan migrate:rollback --step=1
```

## Procédure manuelle équivalente (référence)

Si la CI tombe, Will peut toujours exécuter à la main :

```bash
ssh root@46.62.248.239
cd /opt/axion-crm-pro
git pull origin main
docker compose exec -u root api php artisan migrate --force
docker compose up -d --force-recreate api app
docker compose exec -u root api php artisan config:clear
```
