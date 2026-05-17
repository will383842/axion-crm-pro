#!/usr/bin/env bash
# ==========================================================================
# Axion CRM Pro — Configure .env production avec credentials Will
# ==========================================================================
# À lancer EN TANT QUE ROOT sur le serveur Hetzner, APRÈS setup-hetzner-cpx22.sh :
#   curl -fsSL https://raw.githubusercontent.com/will383842/axion-crm-pro/main/infra/scripts/configure-prod-env.sh | bash
#
# Active les vraies APIs gouv + Mistral. Garde scrapers Google en mock (sources B3+B4).
# ==========================================================================

set -euo pipefail

ENV_FILE="/opt/axion-crm-pro/.env"

echo "[axion-config] Vérification existence $ENV_FILE…"
[ -f "$ENV_FILE" ] || { echo "❌ $ENV_FILE introuvable. Lance setup-hetzner-cpx22.sh d'abord."; exit 1; }

echo "[axion-config] Backup .env existant…"
cp "$ENV_FILE" "${ENV_FILE}.bak.$(date +%s)"

set_env() {
  local key=$1
  local value=$2
  if grep -q "^${key}=" "$ENV_FILE"; then
    sed -i "s|^${key}=.*|${key}=${value}|" "$ENV_FILE"
  else
    echo "${key}=${value}" >> "$ENV_FILE"
  fi
  echo "  ✓ ${key}"
}

echo "[axion-config] Application credentials production…"

# --- Désactivation MOCK pour sources réelles ---
set_env "MOCK_MODE"                  "false"
set_env "MOCK_LLM"                   "false"
set_env "MOCK_INSEE"                 "false"
set_env "MOCK_ANNUAIRE_ENTREPRISES"  "false"
set_env "MOCK_BODACC"                "false"
set_env "MOCK_BAN"                   "false"
set_env "MOCK_FRANCE_TRAVAIL"        "false"

# --- Garder mock pour scrapers Google (Webshare/IPRoyal/2captcha pas encore souscrits) ---
set_env "MOCK_PROXIES"               "true"
set_env "MOCK_SCRAPERS"              "true"
set_env "MOCK_CAPTCHA"               "true"
set_env "MOCK_SMTP"                  "true"

# --- URLs production ---
set_env "APP_URL"                    "https://api.axion-crm-pro.com"
set_env "APP_ENV"                    "production"
set_env "APP_DEBUG"                  "false"
set_env "SANCTUM_STATEFUL_DOMAINS"   "app.axion-crm-pro.com"
set_env "FRONTEND_URL"               "https://app.axion-crm-pro.com"
set_env "SESSION_DOMAIN"             ".axion-crm-pro.com"
set_env "SESSION_SECURE_COOKIE"      "true"
set_env "SESSION_SAME_SITE"          "lax"

# --- Credentials APIs gouv (gratuites) ---
set_env "INSEE_API_KEY"              "b98c0d7c-5d66-48db-8c0d-7c5d6618db49"
set_env "FRANCE_TRAVAIL_CLIENT_ID"   "PAR_axioncrmpro_ac587402c48a18a26d1454b89c6b9da8ce99955c4ef87ebff707dff34bc89f9b"
set_env "FRANCE_TRAVAIL_CLIENT_SECRET" "75d815fe88856084e392b0f98ffa3853a2026a8901e5b434e9c4fa49deb51863"

# --- LLM Mistral ---
set_env "MISTRAL_API_KEY"            "hmRulXWj7WoeDqoAougR8gZKfqb3yqNk"

# --- Owner initial ---
set_env "OWNER_INITIAL_EMAIL"        "williamsjullin@gmail.com"
set_env "OWNER_INITIAL_NAME"         "Williams Jullin"
# OWNER_INITIAL_PASSWORD laissé vide → magic-link only au 1er login (plus sécurisé)

# --- Contraintes CPX22 (4 GB RAM) ---
set_env "WORKER_CONCURRENCY"         "1"

echo "[axion-config] Restart services…"
cd /opt/axion-crm-pro
docker compose restart api horizon scheduler

echo "[axion-config] Attente healthcheck (30s)…"
sleep 30

echo "[axion-config] Vérification finale :"
if curl -fsS http://localhost/up >/dev/null 2>&1; then
  echo "  ✅ /up retourne 200 OK"
else
  echo "  ⚠️  /up ne répond pas — voir docker compose logs api"
fi

echo ""
echo "==============================================="
echo "✅ Configuration prod terminée."
echo "==============================================="
echo ""
echo "Tests à faire depuis ton PC :"
echo "  curl https://api.axion-crm-pro.com/up"
echo "  → doit retourner JSON {'name':'Axion CRM Pro',...}"
echo ""
echo "⚠️  RAPPEL SÉCURITÉ : reset le root password Hetzner MAINTENANT :"
echo "  https://console.hetzner.cloud → axion-crm-edge → Reset Root Password"
echo "  (le password partagé dans le chat est compromis)"
echo ""
echo "⚠️  Régénère aussi : Mistral API Key, France Travail Secret, INSEE Key, Cloudflare Token."
echo "  Voir _AUDIT/AUDIT_3_2026-05-17_real.md pour la procédure."
