#!/usr/bin/env bash
# Agent 12 — sondes HTTP reelles. Atelier local uniquement (localhost:58080).
# L'API tourne sous `php -S` mono-processus et est saturee par les autres
# agents : chaque sonde est retentee jusqu'a 20 fois avec 10 s de patience.
B=http://localhost:58080
OUT="$(dirname "$0")"

probe () { # nom, args curl...
  local nom="$1"; shift
  local i code out
  for i in $(seq 1 20); do
    out=$(curl -s --max-time 25 -w "\n__CODE__%{http_code}" "$@" 2>&1)
    code=$(printf '%s' "$out" | sed -n 's/.*__CODE__//p')
    if [ "$code" != "000" ] && [ -n "$code" ]; then
      echo "### $nom -> HTTP $code"
      printf '%s\n' "$out" | sed 's/__CODE__.*//'
      return 0
    fi
    sleep 5
  done
  echo "### $nom -> AUCUNE REPONSE apres 20 tentatives (conteneur sature)"
}

{
echo "===================== SONDES AGENT 12 ====================="
date -u +"UTC %Y-%m-%dT%H:%M:%SZ"

echo; echo "--- 1. A-001 : etendue -------------------------------------"
probe "GET /up (public)"                       "$B/up"
probe "GET / (web.php, public)"                "$B/"
probe "GET /api/v1/auth/me  Accept:json"       -H "Accept: application/json" "$B/api/v1/auth/me"
probe "GET /api/v1/auth/me  Accept:text/html"  -H "Accept: text/html" "$B/api/v1/auth/me"
probe "GET /api/v1/companies Accept:*/*"       "$B/api/v1/companies"
probe "GET /api/v1/companies Accept:text/html" -H "Accept: text/html" "$B/api/v1/companies"
probe "GET /api/v1/crm/contacts-hub Accept:text/html" -H "Accept: text/html" "$B/api/v1/crm/contacts-hub"
probe "POST /api/v1/auth/logout Accept:text/html" -X POST -H "Accept: text/html" "$B/api/v1/auth/logout"

echo; echo "--- 2. Routes publiques : epargnees ? ----------------------"
probe "POST /api/v1/auth/login (vide, json)"   -X POST -H "Accept: application/json" -H "Content-Type: application/json" -d '{}' "$B/api/v1/auth/login"
probe "POST /api/v1/auth/login (vide, html)"   -X POST -H "Accept: text/html" -H "Content-Type: application/json" -d '{}' "$B/api/v1/auth/login"
probe "GET /api/v1/rgpd/export/jeton-bidon"    -H "Accept: application/json" "$B/api/v1/rgpd/export/jetonquinexistepas000000000000000000000000000000"

echo; echo "--- 3. /search : quelle declaration gagne ? ----------------"
probe "GET /api/v1/search?q=ab (non auth, json)" -H "Accept: application/json" "$B/api/v1/search?q=ab"

echo; echo "--- 4. internal : temoin NEGATIF (mauvaise signature) ------"
probe "POST /internal/scraper-result SIG BIDON" -X POST -H "Content-Type: application/json" -H "X-Worker-Signature: 00deadbeef00" -d '{"run_id":1,"source":"audit","status":"ok"}' "$B/api/internal/scraper-result"
probe "POST /internal/scraper-result SANS SIG"  -X POST -H "Content-Type: application/json" -d '{"run_id":1}' "$B/api/internal/scraper-result"
probe "POST /internal/site-sync SIG BIDON"      -X POST -H "Content-Type: application/json" -H "X-Site-Signature: sha256=00deadbeef" -H "X-Site-Timestamp: 1755600000" -d '{"type":"audit"}' "$B/api/internal/site-sync"
probe "POST /internal/site-sync/gdpr SIG BIDON" -X POST -H "Content-Type: application/json" -H "X-Site-Signature: sha256=00deadbeef" -H "X-Site-Timestamp: 1755600000" -d '{"action":"erase"}' "$B/api/internal/site-sync/gdpr"
probe "POST /internal/email/zeptomail SANS JETON" -X POST -H "Content-Type: application/json" -d '{"event_name":"hardbounce"}' "$B/api/internal/email/zeptomail"
probe "POST /internal/email/zeptomail MAUVAIS JETON" -X POST -H "Content-Type: application/json" -d '{"event_name":"hardbounce"}' "$B/api/internal/email/zeptomail?t=mauvais"

echo; echo "--- 5. internal : temoin POSITIF (signature calculee avec secret VIDE) ---"
BODY='{"run_id":424242,"source":"audit-agent-12","status":"ok"}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "" -r | cut -d' ' -f1)
echo "corps  = $BODY"
echo "secret = (chaine vide, valeur de WORKER_INTERNAL_HMAC_SECRET dans .env)"
echo "sig    = $SIG"
probe "POST /internal/scraper-result SIG FORGEE(secret vide)" -X POST -H "Content-Type: application/json" -H "X-Worker-Signature: $SIG" -d "$BODY" "$B/api/internal/scraper-result"

echo; echo "--- 5bis. REJEU a l'identique de la meme requete forgee ----"
probe "POST /internal/scraper-result REJEU #2" -X POST -H "Content-Type: application/json" -H "X-Worker-Signature: $SIG" -d "$BODY" "$B/api/internal/scraper-result"
probe "POST /internal/scraper-result REJEU #3" -X POST -H "Content-Type: application/json" -H "X-Worker-Signature: $SIG" -d "$BODY" "$B/api/internal/scraper-result"

echo; echo "--- 5ter. corps ALTERE, meme signature ---------------------"
probe "POST /internal/scraper-result CORPS ALTERE" -X POST -H "Content-Type: application/json" -H "X-Worker-Signature: $SIG" -d '{"run_id":999999,"source":"altere","status":"ok"}' "$B/api/internal/scraper-result"

echo; echo "--- 6. absence de limitation de debit sur scraper-result ---"
echo "(30 POST consecutifs, signature bidon : on regarde si un 429 apparait)"
for i in $(seq 1 30); do
  printf '%s ' "$(curl -s --max-time 20 -o /dev/null -w '%{http_code}' -X POST -H 'X-Worker-Signature: x' -d '{}' "$B/api/internal/scraper-result")"
done
echo

echo; echo "--- 7. 501 / routes factices (non authentifie => A-001 domine) ---"
probe "ANY /api/v1/cold-email/nimporte"  -H "Accept: application/json" "$B/api/v1/cold-email/nimporte/quoi"
probe "ANY /api/v1/linkedin"             -H "Accept: application/json" "$B/api/v1/linkedin"
probe "GET /api/v1/crm/inexistant (404 attendu, pas 501)" -H "Accept: application/json" "$B/api/v1/crm/inexistant"
probe "GET /api/v1/analytics (404 attendu)" -H "Accept: application/json" "$B/api/v1/analytics"

echo; echo "===================== FIN ================================="
} > "$OUT/sondes-http.txt" 2>&1
echo "ecrit dans $OUT/sondes-http.txt"
