#!/usr/bin/env bash
# ============================================================================
# Axion CRM Pro — Setup du backup automatique vers Hetzner Storage Box
# ============================================================================
# Lancé une seule fois après création de la Storage Box.
#
# Prérequis :
# - sshpass installé : apt install -y sshpass
# - SB_PASSWORD dans /opt/axion-crm-pro/.env
#
# Workflow :
# 1) Validation prérequis
# 2) Test SSH vers Storage Box (sshpass)
# 3) mkdir distant pour /home/axion-crm-backups
# 4) 1er backup test
# 5) Install cron daily 3h UTC
# ============================================================================

set -euo pipefail

# Charge .env pour SB_PASSWORD
if [ -f /opt/axion-crm-pro/.env ]; then
    set -a
    source <(grep -E '^SB_' /opt/axion-crm-pro/.env)
    set +a
fi

SB_HOST="${SB_HOST:-u595329.your-storagebox.de}"
SB_USER="${SB_USER:-u595329}"
SB_PORT="${SB_PORT:-23}"
SB_PATH="${SB_PATH:-/home/axion-crm-backups}"
SB_PASSWORD="${SB_PASSWORD:-}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_SCRIPT="$SCRIPT_DIR/backup-postgres.sh"
CRON_LOG="/var/log/axion-backup.log"

log() { echo "[setup-backup] $*"; }

# --- 1. Validation prérequis ---
log "Step 1/5 — Validation prérequis..."

[ -f "$BACKUP_SCRIPT" ] || { log "❌ $BACKUP_SCRIPT introuvable"; exit 1; }
chmod +x "$BACKUP_SCRIPT"

if ! command -v sshpass >/dev/null 2>&1; then
    log "❌ sshpass non installé. Lance : apt install -y sshpass"
    exit 1
fi

if [ -z "$SB_PASSWORD" ]; then
    log "❌ SB_PASSWORD non défini dans /opt/axion-crm-pro/.env"
    log "   Ajoute : echo \"SB_PASSWORD='TON_PASSWORD'\" >> /opt/axion-crm-pro/.env"
    exit 1
fi

log "✅ sshpass installé + SB_PASSWORD défini"

# --- 2. Test SSH vers Storage Box ---
log "Step 2/5 — Test SSH vers Storage Box ($SB_HOST)..."

# Storage Box n'a pas de shell complet, on test via sftp pwd
SSH_TEST=$(sshpass -p "$SB_PASSWORD" sftp -P "$SB_PORT" -o StrictHostKeyChecking=accept-new -o ConnectTimeout=15 "$SB_USER@$SB_HOST" <<EOF 2>&1 || true
pwd
EOF
)

if ! echo "$SSH_TEST" | grep -q "Remote working directory"; then
    log "❌ SSH/SFTP failed. Output :"
    echo "$SSH_TEST"
    log "Vérifie : password Hetzner correct + SSH support activé sur la Storage Box"
    exit 1
fi
log "✅ SSH/SFTP OK"

# --- 3. Création dossier distant ---
log "Step 3/5 — Création dossier distant $SB_PATH..."

sshpass -p "$SB_PASSWORD" sftp -P "$SB_PORT" -o StrictHostKeyChecking=accept-new "$SB_USER@$SB_HOST" <<EOF 2>&1 | tail -10
mkdir $SB_PATH
cd $SB_PATH
pwd
EOF

log "✅ Dossier prêt"

# --- 4. 1er backup manuel ---
log "Step 4/5 — 1er backup test (peut prendre 30s-2min selon taille DB)..."
bash "$BACKUP_SCRIPT"

# --- 5. Installation du cron ---
log "Step 5/5 — Installation cron quotidien (3h UTC)..."
CRON_LINE="0 3 * * * $BACKUP_SCRIPT >> $CRON_LOG 2>&1"

( crontab -l 2>/dev/null | grep -v "$BACKUP_SCRIPT" || true ; echo "$CRON_LINE" ) | crontab -

log "Cron installé :"
crontab -l | grep "$BACKUP_SCRIPT"

touch "$CRON_LOG"
chmod 640 "$CRON_LOG"

log ""
log "================================================================"
log "✅ Setup backup terminé."
log "================================================================"
log "  • Backup quotidien à 3h UTC vers $SB_HOST:$SB_PATH"
log "  • Logs : $CRON_LOG"
log "  • Retention locale 7j / distante 30j"
log ""
log "Tests manuels :"
log "  • Backup manuel  : bash $BACKUP_SCRIPT"
log "  • Voir logs cron : tail -50 $CRON_LOG"
log "  • Liste distante : "
log "      sshpass -p \"\$SB_PASSWORD\" sftp -P $SB_PORT $SB_USER@$SB_HOST <<< 'ls -lh $SB_PATH'"
log "================================================================"
