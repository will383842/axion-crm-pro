#!/usr/bin/env bash
# ============================================================================
# Axion CRM Pro — Restore Postgres depuis dump produit par backup-postgres.sh
# ============================================================================
# Usage : bash restore-postgres.sh /path/to/axion_crm_YYYYMMDD.sql.gz [target_db]
#
# Le dump contient :
#   1) CREATE EXTENSION IF NOT EXISTS x 9 (préfixe extensions.sql Sprint 19.4)
#   2) Schema + data + clean if exists
#
# Le restore se fait sur une DB déjà créée (CREATE DATABASE séparé) — c'est le
# chemin standard pour un DR sain (pas de privileges superuser implicite).
# ============================================================================

set -euo pipefail

DUMP_FILE="${1:-}"
TARGET_DB="${2:-axion_crm}"
DB_CONTAINER="${DB_CONTAINER:-axion-crm-postgres}"
DB_USER="${DB_USER:-axion}"

if [ -z "$DUMP_FILE" ] || [ ! -f "$DUMP_FILE" ]; then
    echo "Usage: $0 <dump_file.sql.gz> [target_db]" >&2
    echo "Exemple : $0 /var/backups/axion-crm/axion_crm_20260517T020000Z.sql.gz" >&2
    exit 1
fi

log() { echo "[$(date -u +%FT%TZ)] $*"; }

log "Restore depuis $DUMP_FILE → $DB_CONTAINER:$TARGET_DB"

# 1) Crée la DB si absente (pas dans le dump, voulu — sécurité prod)
log "Étape 1/3 : ensure DB $TARGET_DB exists"
docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d postgres -tc \
    "SELECT 1 FROM pg_database WHERE datname = '$TARGET_DB'" | grep -q 1 \
    || docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d postgres -c "CREATE DATABASE $TARGET_DB"

# 2) Restore : ungzip + psql (les CREATE EXTENSION sont au début du dump)
log "Étape 2/3 : streaming gunzip → psql"
gunzip -c "$DUMP_FILE" | docker exec -i "$DB_CONTAINER" psql -U "$DB_USER" -d "$TARGET_DB" --single-transaction -v ON_ERROR_STOP=1

# 3) Vérif : tables existent
log "Étape 3/3 : vérification post-restore"
TABLE_COUNT=$(docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$TARGET_DB" -tAc \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public'")
log "Tables publiques après restore : $TABLE_COUNT"

if [ "$TABLE_COUNT" -lt 10 ]; then
    log "Restore semble incomplet ($TABLE_COUNT tables < 10 attendues)" >&2
    exit 1
fi

log "Restore complet. DB $TARGET_DB prête."
