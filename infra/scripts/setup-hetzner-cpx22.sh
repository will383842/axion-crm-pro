#!/usr/bin/env bash
# ==========================================================================
# Axion CRM Pro — Setup automatique serveur Hetzner CPX22 (Helsinki ou autre)
# ==========================================================================
# Usage (à lancer EN TANT QUE ROOT sur le serveur fresh Ubuntu 24.04) :
#   ssh root@<ip-hetzner>
#   curl -fsSL https://raw.githubusercontent.com/will383842/axion-crm-pro/main/infra/scripts/setup-hetzner-cpx22.sh | bash
#
# OU si tu as déjà cloné :
#   bash /opt/axion-crm-pro/infra/scripts/setup-hetzner-cpx22.sh
#
# Ce script installe Docker + dépendances, clone le repo (si pas déjà fait),
# génère un .env minimal sécurisé, boot la stack, applique les migrations + seeders.
#
# Il NE remplit PAS les credentials sensibles (MISTRAL_API_KEY, etc.) — tu dois
# éditer .env après avec `nano /opt/axion-crm-pro/.env` puis relancer
# `docker compose restart api horizon scheduler`.
# ==========================================================================

set -euo pipefail

REPO_URL="https://github.com/will383842/axion-crm-pro.git"
REPO_DIR="/opt/axion-crm-pro"
LOG="/var/log/axion-setup.log"

log() { echo "[axion-setup] $*" | tee -a "$LOG"; }
fatal() { log "❌ ERREUR : $*"; exit 1; }

[ "$(id -u)" -eq 0 ] || fatal "Lance ce script en tant que root."

log "==============================================="
log "Axion CRM Pro — Setup CPX22 démarré $(date -Iseconds)"
log "==============================================="

# --- 1. Updates système -----------------------------------------------------
log "[1/8] Mise à jour APT…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get -yq upgrade

# --- 2. Outils + Docker -----------------------------------------------------
log "[2/8] Installation outils + Docker…"
apt-get -yq install ca-certificates curl git nano htop ufw fail2ban jq unzip wget

if ! command -v docker >/dev/null 2>&1; then
  curl -fsSL https://get.docker.com -o /tmp/docker-install.sh
  sh /tmp/docker-install.sh
  rm /tmp/docker-install.sh
fi
apt-get -yq install docker-compose-plugin

docker --version | tee -a "$LOG"
docker compose version | tee -a "$LOG"

# --- 3. Pare-feu UFW --------------------------------------------------------
log "[3/8] Configuration UFW…"
ufw --force reset > /dev/null
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp comment "SSH"
ufw allow 80/tcp comment "HTTP"
ufw allow 443/tcp comment "HTTPS"
ufw --force enable
ufw status verbose | tee -a "$LOG"

# --- 4. Fail2ban + durcissement SSH -----------------------------------------
log "[4/8] Fail2ban + hardening SSH…"
systemctl enable --now fail2ban

# Désactive login root par password (clé SSH only). PermitRootLogin sans-password = OK.
sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config
systemctl restart ssh

# --- 5. Clone repo ----------------------------------------------------------
log "[5/8] Clone repo Axion CRM Pro…"
if [ ! -d "$REPO_DIR" ]; then
  git clone "$REPO_URL" "$REPO_DIR"
else
  log "Repo déjà cloné, pull updates…"
  cd "$REPO_DIR" && git fetch && git reset --hard origin/main
fi
cd "$REPO_DIR"

# --- 6. .env initial (placeholders) -----------------------------------------
log "[6/8] Création .env initial…"
if [ ! -f "$REPO_DIR/.env" ]; then
  cp .env.example .env

  # Génère un APP_KEY + AUDIT_HASH_CHAIN_SECRET aléatoires
  APP_KEY="base64:$(openssl rand -base64 32)"
  AUDIT_SECRET="$(openssl rand -hex 32)"

  # Adapte le .env (sed inline)
  sed -i "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|" .env
  sed -i "s|^APP_ENV=.*|APP_ENV=production|" .env
  sed -i "s|^APP_DEBUG=.*|APP_DEBUG=false|" .env
  sed -i "s|^APP_URL=.*|APP_URL=https://api.axion-crm-pro.com|" .env
  sed -i "s|^SANCTUM_STATEFUL_DOMAINS=.*|SANCTUM_STATEFUL_DOMAINS=app.axion-crm-pro.com|" .env
  sed -i "s|^FRONTEND_URL=.*|FRONTEND_URL=https://app.axion-crm-pro.com|" .env
  sed -i "s|^SESSION_DOMAIN=.*|SESSION_DOMAIN=.axion-crm-pro.com|" .env
  sed -i "s|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=true|" .env
  sed -i "s|^AUDIT_HASH_CHAIN_SECRET=.*|AUDIT_HASH_CHAIN_SECRET=${AUDIT_SECRET}|" .env
  sed -i "s|^WORKER_CONCURRENCY=.*|WORKER_CONCURRENCY=1|" .env

  # Reste en MOCK_MODE=true pour le premier boot — Will activera réel après config credentials
  log "✅ .env créé avec APP_KEY + AUDIT_HASH_CHAIN_SECRET aléatoires"
  log "⚠️  Édite ensuite : nano $REPO_DIR/.env pour ajouter MISTRAL_API_KEY + INSEE_API_KEY + FRANCE_TRAVAIL_*"
else
  log ".env existe déjà, je le laisse tel quel"
fi

# --- 7. Boot stack ----------------------------------------------------------
log "[7/8] Boot Docker stack…"
docker compose -f docker-compose.yml -f docker-compose.prod.yml pull
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d

log "Attente 90 secondes pour healthchecks…"
sleep 90
docker compose ps | tee -a "$LOG"

# --- 8. Migrations + seeders ------------------------------------------------
log "[8/8] Migrations + seeders…"
if docker exec axion-crm-api php artisan --version >/dev/null 2>&1; then
  docker exec axion-crm-api php artisan migrate --force 2>&1 | tee -a "$LOG" || log "⚠️  Migrations failed — voir logs"
  docker exec axion-crm-api php artisan db:seed --force 2>&1 | tee -a "$LOG" || log "⚠️  Seeders failed — voir logs"
else
  log "⚠️  artisan pas dispo encore — peut-être que api n'est pas healthy. Vérifier docker compose logs api"
fi

# Healthcheck final
log "Healthcheck /up :"
if curl -fsS http://localhost/up 2>&1 | tee -a "$LOG"; then
  log "✅ /up retourne 200 OK"
else
  log "⚠️  /up échoue — voir docker compose logs"
fi

log "==============================================="
log "✅ Setup terminé."
log "==============================================="
log ""
log "Prochaines étapes manuelles :"
log "  1. nano $REPO_DIR/.env  → renseigne credentials (MISTRAL_API_KEY etc.)"
log "  2. docker compose restart api horizon scheduler"
log "  3. Sur Cloudflare DNS : vérifie que @, api, app pointent vers cette IP"
log "  4. Tester : curl https://api.axion-crm-pro.com/up (depuis ton PC)"
log ""
log "Logs complets : $LOG"
