#!/usr/bin/env bash
# PARCOURS 10 du §11 — « Audiences : construire avec `neq` ET `not_in` sur un
# champ vide, comparer l'aperçu au décompte réel, rafraîchir, lister les membres. »
#
# Le mandat nomme ce piège explicitement. La base porte 4 fiches :
#   2 en `sector_main = it_saas`, 1 en `btp`, 1 à **NULL**.
set -uo pipefail
J="$1"; B="http://verif.localhost:8080"
X=$(grep XSRF "$J" | awk '{print $7}' | sed 's/%3D/=/g')

api() { # methode chemin [corps]
  local m="$1" c="$2" d="${3:-}"
  if [ -n "$d" ]; then
    curl -s -b "$J" --resolve verif.localhost:8080:127.0.0.1 -X "$m" \
      -H "Content-Type: application/json" -H "Accept: application/json" \
      -H "Origin: $B" -H "Referer: $B/audiences" -H "X-XSRF-TOKEN: $X" -d "$d" "$B/api/v1$c"
  else
    curl -s -b "$J" --resolve verif.localhost:8080:127.0.0.1 -X "$m" \
      -H "Accept: application/json" -H "Origin: $B" -H "Referer: $B/audiences" \
      -H "X-XSRF-TOKEN: $X" "$B/api/v1$c"
  fi
}

echo "=== ETAT DE LA BASE ==="
docker exec crmverif-postgres psql -U axion -d axion_crm -tAc \
  "select coalesce(sector_main,'(NULL)') as secteur, count(*) from companies group by 1 order by 1;"

echo
echo "=== 1. APERCU : neq sector_main != 'btp' ==="
echo "    attendu si NULL est inclus : 3   ·   si NULL est perdu : 2"
api POST /audiences/preview '{"criteria":{"all":[{"field":"sector_main","op":"neq","value":"btp"}]}}'
echo
echo "=== 2. APERCU : not_in sector_main not in ['btp'] ==="
api POST /audiences/preview '{"criteria":{"all":[{"field":"sector_main","op":"not_in","value":["btp"]}]}}'
echo
echo "=== 3. APERCU : le complement, not(neq) ==="
echo "    attendu : 1 (la seule fiche btp) — si NULL revient ici, les deux se contredisent"
api POST /audiences/preview '{"criteria":{"not":[{"field":"sector_main","op":"neq","value":"btp"}]}}'
echo
echo "=== 4. CREATION de l'audience sur le critere neq ==="
CREA=$(api POST /audiences '{"name":"P10 neq sur champ vide","description":"parcours 10 du §11","criteria":{"all":[{"field":"sector_main","op":"neq","value":"btp"}]}}')
echo "$CREA" | head -c 300
ID=$(echo "$CREA" | python -c "import sys,json;d=json.load(sys.stdin);print((d.get('data') or d).get('id',''))" 2>/dev/null)
echo; echo "    id = $ID"

echo
echo "=== 5. RAFRAICHISSEMENT ==="
api POST "/audiences/$ID/refresh" '{}' | head -c 300
echo
echo "=== 6. DECOMPTE REEL DES MEMBRES, mesure en base ==="
docker exec crmverif-postgres psql -U axion -d axion_crm -tAc \
  "select 'membres = '||count(*) from audience_members where audience_id=$ID;"
docker exec crmverif-postgres psql -U axion -d axion_crm -tAc \
  "select c.siren||'  '||coalesce(c.sector_main,'(NULL)') from audience_members m join companies c on c.id=m.company_id where m.audience_id=$ID order by 1;"

echo
echo "=== 7. CE QUE L'ECRAN ANNONCE ==="
api GET "/audiences/$ID" | python -c "
import sys,json
d=json.load(sys.stdin); d=d.get('data') or d
for k in ('id','name','members_count','company_count','contacts_count','last_refresh_at','is_active','auto_refresh'):
    if k in d: print('   ',k,'=',d[k])" 2>/dev/null || echo "   (lecture directe impossible)"
