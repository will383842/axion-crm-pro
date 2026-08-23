#!/usr/bin/env bash
# PARCOURS 14, seconde moitié — un `viewer` tente d'atteindre ce qui lui est
# interdit. Ses TROIS permissions : companies.view, llm.view_usage, rgpd.view.
# Tout le reste doit être refusé.
set -uo pipefail
S="$1"; B="http://verif.localhost:8080"; J="$S/jar-viewer.txt"; rm -f "$J"

curl -s -c "$J" -o /dev/null --resolve verif.localhost:8080:127.0.0.1 "$B/sanctum/csrf-cookie"
X=$(grep XSRF "$J" | awk '{print $7}' | sed 's/%3D/=/g')
CODE=$(curl -s -b "$J" -c "$J" --resolve verif.localhost:8080:127.0.0.1 \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Origin: $B" -H "Referer: $B/login" -H "X-XSRF-TOKEN: $X" \
  -d '{"email":"viewer@verif.localhost","password":"Viewer-Verif!2026"}' \
  -o /dev/null -w '%{http_code}' "$B/api/v1/auth/login")
echo "connexion du viewer : $CODE"
[ "$CODE" != "200" ] && { echo "ARRET : le viewer ne peut pas se connecter"; exit 1; }

X=$(grep XSRF "$J" | awk '{print $7}' | sed 's/%3D/=/g')
appel() { # methode chemin [corps]
  local m="$1" c="$2" d="${3:-}" code
  if [ -n "$d" ]; then
    code=$(curl -s -b "$J" --resolve verif.localhost:8080:127.0.0.1 -X "$m" \
      -H "Content-Type: application/json" -H "Accept: application/json" \
      -H "Origin: $B" -H "Referer: $B/" -H "X-XSRF-TOKEN: $X" -d "$d" \
      -o /dev/null -w '%{http_code}' "$B/api/v1$c")
  else
    code=$(curl -s -b "$J" --resolve verif.localhost:8080:127.0.0.1 -X "$m" \
      -H "Accept: application/json" -H "Origin: $B" -H "Referer: $B/" \
      -H "X-XSRF-TOKEN: $X" -o /dev/null -w '%{http_code}' "$B/api/v1$c")
  fi
  printf '%s\n' "$code"
}

verdict() { # code attendu_refus
  case "$1" in
    401|403) printf 'REFUSE  ' ;;
    404)     printf '404     ' ;;
    405|501) printf 'INEXIST ' ;;
    2*)      printf '>>> PASSE' ;;
    *)       printf '%s     ' "$1" ;;
  esac
}

echo
echo "════ CE QUI DOIT ÊTRE PERMIS (3 permissions) ════"
for x in "GET /companies|companies.view" "GET /llm/usage|llm.view_usage" "GET /rgpd/requests|rgpd.view"; do
  m=${x%% *}; reste=${x#* }; c=${reste%%|*}; perm=${reste##*|}
  code=$(appel "$m" "$c"); printf '  %-34s %-9s %s   (%s)\n' "$m $c" "$(verdict "$code")" "$code" "$perm"
done

echo
echo "════ CE QUI DOIT ÊTRE REFUSÉ ════"
for x in \
  "GET|/users|lister les utilisateurs" \
  "GET|/audit-logs|lire le journal d audit (audit.view)" \
  "GET|/contacts|lire les contacts" \
  "GET|/audiences|lire les audiences" \
  "GET|/campaigns|lire les collectes" \
  "GET|/scraper-runs|lire les journaux de collecte" \
  "GET|/tags|lire les tags" \
  "GET|/media|lire les medias" \
  "GET|/workspace|lire l espace de travail" \
  "GET|/dashboard/stats|le tableau de bord" \
  "GET|/crm/contacts-hub|le hub contacts" \
  ; do
  m=${x%%|*}; reste=${x#*|}; c=${reste%%|*}; quoi=${reste##*|}
  code=$(appel "$m" "$c"); printf '  %-34s %-9s %s   %s\n' "$m $c" "$(verdict "$code")" "$code" "$quoi"
done

echo
echo "════ ÉCRITURES — un lecteur ne doit RIEN pouvoir modifier ════"
for x in \
  "POST|/companies|{\"siren\":\"999999999\",\"denomination\":\"Ecrite par un viewer\"}|creer une fiche" \
  "POST|/tags|{\"name\":\"Tag du viewer\"}|creer un tag" \
  "POST|/audiences|{\"name\":\"Audience du viewer\",\"criteria\":{}}|creer une audience" \
  "DELETE|/companies/2||supprimer une fiche" \
  "POST|/users|{\"email\":\"x@y.z\"}|creer un utilisateur" \
  ; do
  m=${x%%|*}; r1=${x#*|}; c=${r1%%|*}; r2=${r1#*|}; d=${r2%%|*}; quoi=${r2##*|}
  code=$(appel "$m" "$c" "$d"); printf '  %-34s %-9s %s   %s\n' "$m $c" "$(verdict "$code")" "$code" "$quoi"
done

echo
echo "════ COORDONNÉES PERSONNELLES — le viewer n'a PAS contacts.view_pii ════"
X=$(grep XSRF "$J" | awk '{print $7}' | sed 's/%3D/=/g')
echo -n "  GET /companies/5 (fiche avec contact) : "
curl -s -b "$J" --resolve verif.localhost:8080:127.0.0.1 -H "Accept: application/json" \
  -H "Origin: $B" -H "Referer: $B/" -H "X-XSRF-TOKEN: $X" "$B/api/v1/companies/5" \
  | python -c "
import sys,json
try:
    d=json.load(sys.stdin); d=d.get('data',d)
    print('email_generic =', repr(d.get('email_generic')), '| phone =', repr(d.get('phone')))
except Exception as e:
    print('(illisible)', str(e)[:60])"
echo -n "  GET /contacts (liste, courriels) : "
curl -s -b "$J" --resolve verif.localhost:8080:127.0.0.1 -H "Accept: application/json" \
  -H "Origin: $B" -H "Referer: $B/" -H "X-XSRF-TOKEN: $X" "$B/api/v1/contacts" \
  | python -c "
import sys,json
try:
    d=json.load(sys.stdin)
    rows=d.get('data',d) if isinstance(d,dict) else d
    if isinstance(rows,list) and rows:
        for r in rows[:3]: print('   ', r.get('first_name'), r.get('last_name'), '|', repr(r.get('email')), '|', repr(r.get('phone')))
    else: print('(aucune ligne rendue)')
except Exception as e:
    print('(refuse ou illisible)', str(e)[:60])"
