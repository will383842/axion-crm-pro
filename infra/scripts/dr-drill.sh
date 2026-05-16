#!/usr/bin/env bash
# Axion CRM Pro — DR drill trimestriel
# Vérifie : backups récupérables, RPO ≤ 1h, RTO ≤ 4h
# Usage : ./infra/scripts/dr-drill.sh [--no-cleanup]

set -euo pipefail

NO_CLEANUP=${1:-}
SCRATCH=/tmp/axion-crm-dr-drill
NOW=$(date +%s)
mkdir -p "$SCRATCH"

echo "=== Axion CRM Pro — DR drill $(date -Iseconds) ==="

# 1. Vérifier la disponibilité des backups
echo "[1/5] Listing backups Hetzner Object Storage…"
s3cmd ls s3://axion-crm-backups/postgres/ | tail -10

LAST_BACKUP=$(s3cmd ls s3://axion-crm-backups/postgres/ | sort | tail -1 | awk '{print $NF}')
LAST_BACKUP_TS=$(s3cmd info "$LAST_BACKUP" | grep 'Last mod' | awk '{print $3, $4, $5, $6}' | xargs -I{} date -d "{}" +%s)
AGE=$(( (NOW - LAST_BACKUP_TS) / 60 ))
echo "  Dernier backup : ${AGE} min"

if (( AGE > 60 )); then
  echo "  ❌ RPO violé : backup > 1h"
  exit 1
fi
echo "  ✓ RPO OK"

# 2. Restaurer dans un Postgres éphémère
echo "[2/5] Restauration test sur Postgres éphémère…"
START_RESTORE=$(date +%s)
docker run --rm --name axion-crm-dr-pg -d -e POSTGRES_PASSWORD=test postgres:16-alpine
sleep 5
s3cmd get "$LAST_BACKUP" - | gunzip | docker exec -i axion-crm-dr-pg psql -U postgres -d postgres
RESTORE_DUR=$(( $(date +%s) - START_RESTORE ))
echo "  Restauration en ${RESTORE_DUR}s"

# 3. Vérification intégrité (chaîne audit)
echo "[3/5] Vérification chaîne audit hash…"
HASH_OK=$(docker exec axion-crm-dr-pg psql -U postgres -d postgres -tA -c "
  WITH chain AS (
    SELECT id, prev_hash, current_hash,
           LAG(current_hash) OVER (ORDER BY id) AS prev_in_db
    FROM audit_logs
  )
  SELECT COUNT(*) FROM chain WHERE prev_hash <> COALESCE(prev_in_db, 'GENESIS')
")
if [[ "$HASH_OK" != "0" ]]; then
  echo "  ❌ Chaîne audit corrompue : $HASH_OK rows mismatched"
  exit 2
fi
echo "  ✓ Chaîne audit OK"

# 4. Calcul RTO simulé
TOTAL_DUR=$(( $(date +%s) - NOW ))
echo "[4/5] RTO simulé : ${TOTAL_DUR}s ($((TOTAL_DUR / 60)) min)"
if (( TOTAL_DUR > 14400 )); then
  echo "  ❌ RTO violé : > 4h"
  exit 3
fi
echo "  ✓ RTO OK"

# 5. Cleanup
if [[ "$NO_CLEANUP" != "--no-cleanup" ]]; then
  docker rm -f axion-crm-dr-pg
  rm -rf "$SCRATCH"
fi

echo "=== DR drill PASSED ==="
