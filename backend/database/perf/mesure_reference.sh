#!/usr/bin/env bash
# Mesure de référence — étape 0, ligne 11 (F5). Séquentiel (le serveur PHP de dev est mono-thread).
set -u
T="${PERF_TOKEN:?jeton Sanctum de l utilisateur de mesure (voir _REPORTS/2026-08-18_MESURE-PERFORMANCE-REFERENCE.md)}"
B="${PERF_BASE:-http://127.0.0.1:58081}"
N=${N:-15}
PK=$(docker exec axion-crm-postgres psql -U axion -d axion_crm_perf -tAc "select person_key from contacts order by id offset 12345 limit 1" | tr -d '\r\n')
OUT="${OUT:-/tmp/bench.csv}"
: > "$OUT"
declare -A EP=(
  [baseline_features]="/api/v1/config/features"
  [hub_actifs_p1]="/api/v1/crm/contacts-hub?per_page=50"
  [hub_actifs_clients]="/api/v1/crm/contacts-hub?per_page=50&relation_type=client"
  [hub_froids]="/api/v1/crm/contacts-hub?per_page=50&temperature=froids"
  [hub_tous]="/api/v1/crm/contacts-hub?per_page=50&temperature=tous"
  [hub_recherche_prefixe]="/api/v1/crm/contacts-hub?per_page=50&q=Cabinet%20Mar"
  [hub_counts]="/api/v1/crm/contacts-hub/counts"
  [timeline_personne]="/api/v1/crm/persons/${PK}/timeline"
  [recherche_globale]="/api/v1/search?q=Cabinet%20Martin"
  [export_clients_csv]="/api/v1/companies/export?relation_type=client"
)
for name in baseline_features hub_actifs_p1 hub_actifs_clients hub_froids hub_tous hub_recherche_prefixe hub_counts timeline_personne recherche_globale export_clients_csv; do
  url="${EP[$name]}"
  for i in $(seq 1 "$N"); do
    r=$(curl -s -o /dev/null -w "%{http_code} %{time_total} %{size_download}" -H "Accept: application/json" -H "Authorization: Bearer $T" "$B$url")
    echo "$name,$i,${r// /,}" >> "$OUT"
  done
  echo "$name : $(tail -1 "$OUT")"
done
