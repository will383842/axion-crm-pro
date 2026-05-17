#!/usr/bin/env bash
# ============================================================================
# Axion CRM Pro — Setup du backup automatique vers Hetzner Storage Box
# ============================================================================
# Lancé une seule fois après création de la Storage Box.
#
# Workflow :
# 1) Test SSH vers Storage Box (avec clé)
# 2) Crée le dossier distant /home/axion-crm-backups
# 3) Lance un 1er backup manuel (validation)
# 4) Installe le cron quotidien (3h UTC)
# ============================================================================

set -euo pipefail

SB_HOST="${SB_HOST:-u595329.your-storagebox.de}"
SB_USER="${SB_USER:-u595329}"
SB_PORT="${SB_PORT:-23}"
SB_PATH="${SB_PATH:-/home/axion-crm-backups}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_SCRIPT="$SCRIPT_DIR/backup-postgres.sh"
CRON_LOG="/var/log/axion-backup.log"

log() { echo "[setup-backup] $*"; }

# --- 1. Vérifier que le script de backup existe et est exécutable ---
log "Step 1/4 — Validation script backup..."
[ -f "$BACKUP_SCRIPT" ] || { log "❌ $BACKUP_SCRIPT introuvable"; exit 1; }
chmod +x "$BACKUP_SCRIPT"

# --- 2. Test SSH vers Storage Box ---
log "Step 2/4 — Test SSH vers Storage Box ($SB_HOST)..."
if ! ssh -p "$SB_PORT" \
       -o StrictHostKeyChecking=accept-new \
       -o ConnectTimeout=15 \
       -o BatchMode=yes \
       "$SB_USER@$SB_HOST" \
       'echo "SSH OK"' 2>/dev/null; then
    log "❌ SSH failed. Vérifie que :"
    log "   - La clé axion-deploy est bien dans ~/.ssh/authorized_keys"
    log "   - SSH support est activé dans l'interface Hetzner Storage Box"
    log "   - Le port 23 est ouvert (default)"
    exit 1
fi
log "✅ SSH OK"

# --- 3. Création du dossier distant ---
log "Step 3a — Création dossier distant $SB_PATH..."
ssh -p "$SB_PORT" "$SB_USER@$SB_HOST" "mkdir -p $SB_PATH && ls -la $SB_PATH"

# --- 4. 1er backup manuel ---
log "Step 3b — 1er backup test..."
bash "$BACKUP_SCRIPT"

# --- 5. Installation du cron ---
log "Step 4/4 — Installation cron quotidien (3h UTC)..."
CRON_LINE="0 3 * * * $BACKUP_SCRIPT >> $CRON_LOG 2>&1"

# Append au crontab root (idempotent : skip si déjà présent)
( crontab -l 2>/dev/null | grep -v "$BACKUP_SCRIPT" || true ; echo "$CRON_LINE" ) | crontab -

log "Cron installé :"
crontab -l | grep "$BACKUP_SCRIPT"

# Création du fichier log si absent
touch "$CRON_LOG"
chmod 640 "$CRON_LOG"

log ""
log "================================================================"
log "✅ Setup backup terminé."
log "================================================================"
log "  • Backup quotidien à 3h UTC vers $SB_HOST:$SB_PATH"
log "  • Logs : $CRON_LOG"
log "  • Retention locale : 7 jours / distante : 30 jours"
log ""
log "Tests manuels :"
log "  • Backup manuel  : bash $BACKUP_SCRIPT"
log "  • Voir logs cron : tail -50 $CRON_LOG"
log "  • Liste distante : ssh -p $SB_PORT $SB_USER@$SB_HOST 'ls -lh $SB_PATH'"
log "================================================================"
