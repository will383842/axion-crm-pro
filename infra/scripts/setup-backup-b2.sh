#!/usr/bin/env bash
# ============================================================================
# Axion CRM Pro — Setup de la copie hebdomadaire Backblaze B2
# ============================================================================
# Idempotent : relançable sans doublon de cron. Ne requiert PAS les clés B2
# (backup-b2.sh dit « non configuré » et sort en 0 tant qu'elles manquent).
#
# Will, une fois le compte B2 ouvert : poser dans /opt/axion-crm-pro/.env
#   B2_KEY_ID=…  B2_APP_KEY=…  B2_BUCKET=…   (clé restreinte à ce seul bucket)
# ============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
B2_SCRIPT="$SCRIPT_DIR/backup-b2.sh"
CRON_LOG="/var/log/axion-backup-b2.log"
# Dimanche 04:30 UTC, après la sauvegarde quotidienne de 03:00.
CRON_LINE="30 4 * * 0 $B2_SCRIPT >> $CRON_LOG 2>&1"

log() { echo "[setup-backup-b2] $*"; }

[ -f "$B2_SCRIPT" ] || { log "❌ $B2_SCRIPT introuvable"; exit 1; }
chmod +x "$B2_SCRIPT" "$SCRIPT_DIR/verifier-sauvegarde-b2.sh"

if ! command -v jq >/dev/null 2>&1; then
    log "Installation de jq (paquet de la distribution)…"
    apt-get update -qq && apt-get install -y jq
fi

( crontab -l 2>/dev/null | grep -vF "$B2_SCRIPT" || true ; echo "$CRON_LINE" ) | crontab -

touch "$CRON_LOG"
chmod 640 "$CRON_LOG"

log "Cron installé :"
crontab -l | grep -F "$B2_SCRIPT"
log "Test à blanc : bash $B2_SCRIPT   (dit « non configuré » tant que les clés B2 manquent)"
