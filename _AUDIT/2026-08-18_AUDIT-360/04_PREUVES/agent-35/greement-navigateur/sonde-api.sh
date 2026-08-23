#!/usr/bin/env bash
# Sonde large : parcours 6, 7, 8, 9, 11, 13, 15, 16, 20 du §11, côté API.
# On mesure ce que le produit REND, pas ce qu'il promet.
set -uo pipefail
S="$1"; B="http://verif.localhost:8080"; J="$S/cookies.txt"
X() { grep XSRF "$J" | awk '{print $7}' | sed 's/%3D/=/g'; }
c() { # methode chemin [corps-json]
  local m="$1" p="$2" d="${3:-}"
  if [ -n "$d" ]; then printf '%s' "$d" > "$S/_b.json"
    curl -s -b "$J" --resolve verif.localhost:8080:127.0.0.1 -X "$m" -H "Content-Type: application/json" \
      -H "Accept: application/json" -H "Origin: $B" -H "Referer: $B/" -H "X-XSRF-TOKEN: $(X)" \
      --data-binary "@$S/_b.json" -o "$S/_r.json" -w '%{http_code}' "$B/api/v1$p"
  else
    curl -s -b "$J" --resolve verif.localhost:8080:127.0.0.1 -X "$m" -H "Accept: application/json" \
      -H "Origin: $B" -H "Referer: $B/" -H "X-XSRF-TOKEN: $(X)" -o "$S/_r.json" -w '%{http_code}' "$B/api/v1$p"
  fi
}
l() { printf '  %-6s %-40s -> %s  %s\n' "$1" "$2" "$3" "$(head -c "${5:-90}" "$S/_r.json" | tr -d '\n')"; }
g() { local code; code=$(c "$1" "$2" "${3:-}"); l "$1" "$2" "$code" "" "${4:-90}"; }

echo "════════ PARCOURS 6 — ENTREPRISES ════════"
g GET  /companies
g GET  /companies/1
g POST /companies/1/enrich '{}'
g POST /companies/1/recompute-score '{}'
g GET  /companies/1/contacts
g GET  /companies/export

echo
echo "════════ PARCOURS 7 — MÉDIAS, JOURNALISTES, OPPOSITION, EXPORTS ════════"
g GET  /media
g GET  /media/2
g GET  /journalists
g GET  /media/export
g GET  /journalists/export

echo
echo "════════ PARCOURS 8 — COUVERTURE FRANCE ════════"
g GET  /coverage
g GET  /coverage/matrix
g GET  /coverage/departments

echo
echo "════════ PARCOURS 9 — ASSISTANT DE CAMPAGNE ════════"
g GET  /campaigns
g GET  /campaigns/1
g POST /campaigns/1/start '{}'
g GET  /campaigns/1
g POST /campaigns/1/pause '{}'
g POST /campaigns/1/resume '{}'
g POST /campaigns/1/cancel '{}'
g GET  /scraper-runs

echo
echo "════════ PARCOURS 11 — TAGS : cycle complet ════════"
g GET  /tags
g POST /tags '{"name":"Tag du parcours 11","category":"custom"}'
g PUT  /tags/1 '{"name":"Tag renomme"}'
g GET  /tags
g DELETE /tags/1

echo
echo "════════ PARCOURS 13 — AI ACT + CHAÎNE D'AUDIT ════════"
g GET  /ai-act/register
g POST /ai-act/register '{"system_name":"LLM Router","risk_level":"limited"}'
g GET  /audit-logs
g GET  /audit-logs/verify-chain
g POST /audit-logs/verify-chain '{}'

echo
echo "════════ PARCOURS 15 — RÉGLAGES ════════"
g GET  /workspace
g PUT  /workspace '{"name":"Axion-IA renomme par le parcours 15"}'
g GET  /workspace
g GET  /config/features

echo
echo "════════ PARCOURS 16 — OBSERVABILITÉ ════════"
g GET  /admin/observability
g GET  /observability
g GET  /admin/metrics
g GET  /health

echo
echo "════════ PARCOURS 20 — LES ROUTES 501 ET LES ENTRÉES VERROUILLÉES ════════"
for r in /users /crm/persons /notifications/1/read /notifications/read-all; do
  g POST "$r" '{}'
done
g GET  /console/vivier
g GET  /crm/arbitrage
