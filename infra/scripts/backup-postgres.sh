#!/usr/bin/env bash
# ============================================================================
# Axion CRM Pro — Backup quotidien Postgres → Hetzner Storage Box
# ============================================================================
# Workflow :
# 1) pg_dump compressé (gzip)
# 2) scp vers Storage Box via SSH key
# 3) Rotation locale (7 jours) + distante (30 jours)
#
# Lancé via cron (cf. setup-backup.sh) ou manuellement :
#   bash /opt/axion-crm-pro/infra/scripts/backup-postgres.sh
# ============================================================================

set -euo pipefail

# --- Config ---
DB_CONTAINER="${DB_CONTAINER:-axion-crm-postgres}"
DB_USER="${DB_USER:-axion}"
DB_NAME="${DB_NAME:-axion_crm}"

SB_HOST="${SB_HOST:-u595329.your-storagebox.de}"
SB_USER="${SB_USER:-u595329}"
SB_PORT="${SB_PORT:-23}"
SB_PATH="${SB_PATH:-/home/axion-crm-backups}"

BACKUP_DIR="${BACKUP_DIR:-/var/backups/axion-crm}"
RETENTION_LOCAL_DAYS=7
RETENTION_REMOTE_DAYS=30
MIN_SIZE_BYTES=10000   # rejette si dump < 10KB (DB vide ou crashed)

# --- Préparation ---
TIMESTAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP_FILE="axion_crm_${TIMESTAMP}.sql.gz"
mkdir -p "$BACKUP_DIR"

log() { echo "[$(date -u +%FT%TZ)] $*"; }

# --- pg_dump ---
log "Starting pg_dump (DB=$DB_NAME, container=$DB_CONTAINER)..."
docker exec "$DB_CONTAINER" pg_dump \
    -U "$DB_USER" \
    -Fp \
    --no-owner \
    --no-acl \
    --clean \
    --if-exists \
    "$DB_NAME" \
    | gzip -9 > "$BACKUP_DIR/$BACKUP_FILE"

# --- Vérif taille ---
SIZE=$(stat -c%s "$BACKUP_DIR/$BACKUP_FILE")
log "Dump produced: $BACKUP_FILE ($SIZE bytes)"

if [ "$SIZE" -lt "$MIN_SIZE_BYTES" ]; then
    log "❌ Dump too small ($SIZE bytes < $MIN_SIZE_BYTES). Aborting."
    rm -f "$BACKUP_DIR/$BACKUP_FILE"
    exit 1
fi

# --- Upload Storage Box ---
log "Uploading to Storage Box ($SB_HOST:$SB_PATH)..."
scp -P "$SB_PORT" \
    -o StrictHostKeyChecking=accept-new \
    -o ConnectTimeout=30 \
    "$BACKUP_DIR/$BACKUP_FILE" \
    "$SB_USER@$SB_HOST:$SB_PATH/"

log "✅ Upload OK"

# --- Rotation locale (garde les N derniers jours) ---
log "Rotation locale (>$RETENTION_LOCAL_DAYS jours)..."
find "$BACKUP_DIR" -name "axion_crm_*.sql.gz" -mtime "+$RETENTION_LOCAL_DAYS" -delete -print || true

# --- Rotation distante (via SSH find -mtime) ---
log "Rotation distante (>$RETENTION_REMOTE_DAYS jours)..."
ssh -p "$SB_PORT" \
    -o StrictHostKeyChecking=accept-new \
    -o ConnectTimeout=30 \
    "$SB_USER@$SB_HOST" \
    "find $SB_PATH -name 'axion_crm_*.sql.gz' -mtime +$RETENTION_REMOTE_DAYS -delete" \
    || log "⚠️  Rotation distante échouée (non bloquant)"

# --- Inventory ---
log "Backups locaux actuels :"
ls -lh "$BACKUP_DIR" | tail -n +2

log "✅ Backup completed successfully: $BACKUP_FILE ($SIZE bytes)"
