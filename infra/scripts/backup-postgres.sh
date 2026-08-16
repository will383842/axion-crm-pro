#!/usr/bin/env bash
# ============================================================================
# Axion CRM Pro — Backup quotidien Postgres → Hetzner Storage Box
# ============================================================================
# Workflow :
# 1) pg_dump compressé (gzip)
# 2) scp vers Storage Box (sshpass auth)
# 3) Rotation locale 7j (find côté serveur, OK)
# 4) Rotation distante 30j (sftp - 'rm' commands, Storage Box n'a pas find)
#
# Lancé via cron (cf. setup-backup.sh) ou manuellement :
#   bash /opt/axion-crm-pro/infra/scripts/backup-postgres.sh
# ============================================================================

set -euo pipefail

# Charge .env pour SB_PASSWORD
if [ -f /opt/axion-crm-pro/.env ]; then
    set -a
    # shellcheck disable=SC1091
    source <(grep -E '^SB_' /opt/axion-crm-pro/.env)
    set +a
fi

# --- Config ---
DB_CONTAINER="${DB_CONTAINER:-axion-crm-postgres}"
DB_USER="${DB_USER:-axion}"
DB_NAME="${DB_NAME:-axion_crm}"

SB_HOST="${SB_HOST:-u595329.your-storagebox.de}"
SB_USER="${SB_USER:-u595329}"
SB_PORT="${SB_PORT:-23}"
SB_PATH="${SB_PATH:-/home/axion-crm-backups}"
SB_PASSWORD="${SB_PASSWORD:-}"

BACKUP_DIR="${BACKUP_DIR:-/var/backups/axion-crm}"
RETENTION_LOCAL_DAYS=7
RETENTION_REMOTE_DAYS=30
MIN_SIZE_BYTES=10000

# --- Validation ---
if [ -z "$SB_PASSWORD" ]; then
    echo "❌ SB_PASSWORD non défini (vérifie /opt/axion-crm-pro/.env)" >&2
    exit 1
fi
if ! command -v sshpass >/dev/null 2>&1; then
    echo "❌ sshpass non installé. Lance : apt install -y sshpass" >&2
    exit 1
fi

# --- Préparation ---
TIMESTAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP_FILE="axion_crm_${TIMESTAMP}.sql.gz"
mkdir -p "$BACKUP_DIR"

log() { echo "[$(date -u +%FT%TZ)] $*"; }

# Wrapper sshpass
sb_scp() { sshpass -p "$SB_PASSWORD" scp -P "$SB_PORT" -o StrictHostKeyChecking=accept-new -o ConnectTimeout=30 "$@"; }
sb_sftp_batch() { sshpass -p "$SB_PASSWORD" sftp -P "$SB_PORT" -o StrictHostKeyChecking=accept-new -o ConnectTimeout=30 -b - "$SB_USER@$SB_HOST"; }

# --- pg_dump ---
# Sprint 19.4 : -C (CREATE DATABASE) + préfix extensions.sql pour restore brut sans pré-création
# Le dump pg_dump n'inclut pas les CREATE EXTENSION dans plain text sans --extension flag.
# On préfixe donc avec un script SQL qui pose les 9 extensions requises (cf. infra/postgres/init/01-extensions.sql).
log "Starting pg_dump (DB=$DB_NAME, container=$DB_CONTAINER)..."

# Heredoc extensions, idempotent
EXTENSIONS_SQL=$(cat <<'EOSQL'
-- Axion CRM Pro — bootstrap extensions (Sprint 19.4 backup prefix)
CREATE EXTENSION IF NOT EXISTS "pg_trgm";
CREATE EXTENSION IF NOT EXISTS "unaccent";
CREATE EXTENSION IF NOT EXISTS "btree_gin";
CREATE EXTENSION IF NOT EXISTS "btree_gist";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";
CREATE EXTENSION IF NOT EXISTS "citext";
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "postgis";
CREATE EXTENSION IF NOT EXISTS "vector";
EOSQL
)

# pg_dump -C produit CREATE DATABASE + \connect, on injecte les extensions juste après le \connect.
# Stratégie portable : on concatène extensions + dump, en demandant un dump *sans* -C, puis
# le restore se fait par psql sur une DB déjà créée (le path standard d'un DR sain).
{
    echo "-- ============================================================================"
    echo "-- Axion CRM Pro — backup ${TIMESTAMP}"
    echo "-- DB=${DB_NAME}, container=${DB_CONTAINER}"
    echo "-- ============================================================================"
    echo ""
    echo "${EXTENSIONS_SQL}"
    echo ""
    echo "-- ============================================================================"
    echo "-- pg_dump payload (schema + data)"
    echo "-- ============================================================================"
    docker exec "$DB_CONTAINER" pg_dump \
        -U "$DB_USER" \
        -Fp \
        --no-owner \
        --no-acl \
        --clean \
        --if-exists \
        "$DB_NAME"
} | gzip -9 > "$BACKUP_DIR/$BACKUP_FILE"

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
sb_scp "$BACKUP_DIR/$BACKUP_FILE" "$SB_USER@$SB_HOST:$SB_PATH/"
log "✅ Upload OK"

# --- Rotation locale (find marche sur Ubuntu) ---
log "Rotation locale (>$RETENTION_LOCAL_DAYS jours)..."
find "$BACKUP_DIR" -name "axion_crm_*.sql.gz" -mtime "+$RETENTION_LOCAL_DAYS" -delete -print || true

# --- Rotation distante (Storage Box n'a pas `find`, on liste via sftp et on rm les anciens) ---
log "Rotation distante (>$RETENTION_REMOTE_DAYS jours)..."
CUTOFF_TIMESTAMP=$(date -u -d "$RETENTION_REMOTE_DAYS days ago" +%Y%m%dT%H%M%SZ)
log "  Cutoff: garder uniquement les fichiers > $CUTOFF_TIMESTAMP"

# Liste les fichiers .sql.gz via sftp ls
REMOTE_LIST=$(sshpass -p "$SB_PASSWORD" sftp -P "$SB_PORT" -o StrictHostKeyChecking=accept-new "$SB_USER@$SB_HOST" <<EOF 2>/dev/null | grep '\.sql\.gz$' | awk '{print $NF}' || true
cd $SB_PATH
ls
EOF
)

# Pour chaque fichier, parse le timestamp dans le nom et supprime si trop ancien
DELETED_COUNT=0
echo "$REMOTE_LIST" | while IFS= read -r filename; do
    [ -z "$filename" ] && continue
    # Extract timestamp from "axion_crm_YYYYMMDDTHHMMSSZ.sql.gz"
    ts=$(echo "$filename" | sed -nE 's/^axion_crm_([0-9]+T[0-9]+Z)\.sql\.gz$/\1/p')
    if [ -n "$ts" ] && [ "$ts" \< "$CUTOFF_TIMESTAMP" ]; then
        log "  Suppression distante: $filename (ts=$ts)"
        sshpass -p "$SB_PASSWORD" sftp -P "$SB_PORT" -o StrictHostKeyChecking=accept-new "$SB_USER@$SB_HOST" <<EOF >/dev/null 2>&1 || true
cd $SB_PATH
rm $filename
EOF
        DELETED_COUNT=$((DELETED_COUNT + 1))
    fi
done
log "  Rotation distante : terminée"

# --- Inventory ---
log "Backups locaux actuels :"
ls -lh "$BACKUP_DIR" | tail -n +2

log "✅ Backup completed: $BACKUP_FILE ($SIZE bytes)"
