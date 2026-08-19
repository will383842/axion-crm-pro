#!/usr/bin/env bash
# Agent 41 — harnais de mesure EXPLAIN (ANALYZE, BUFFERS)
# Usage : mesure.sh <db> <id> "<sql>"
# Sortie : 04_PREUVES/agent-41/<db>/<id>.txt   (froid = 1re exec, chaud = 4e exec)
set -u
DB="$1"; ID="$2"; SQL="$3"
OUT="/c/Users/willi/Documents/Projets/Axion-CRM-Pro/_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/agent-41/${DB}"
mkdir -p "$OUT"
F="$OUT/${ID}.txt"
{
  echo "############ $ID"
  echo "### base   : $DB   (datcollate = C)"
  echo "### date   : $(date -Is)"
  echo "### SQL    :"
  echo "$SQL"
  echo
  echo "===== FROID (1re exécution de cette requête, session neuve) ====="
} > "$F"
docker exec axion-crm-postgres psql -U axion -d "$DB" -X -c "explain (analyze,buffers) $SQL" >> "$F" 2>&1
for i in 2 3; do
  docker exec axion-crm-postgres psql -U axion -d "$DB" -X -c "explain (analyze,buffers) $SQL" > /dev/null 2>&1
done
{ echo; echo "===== CHAUD (4e exécution) ====="; } >> "$F"
docker exec axion-crm-postgres psql -U axion -d "$DB" -X -c "explain (analyze,buffers) $SQL" >> "$F" 2>&1
echo "--- $DB/$ID"
grep -E "Execution Time|Planning Time" "$F" | sed 's/^/    /'
