#!/usr/bin/env bash
# PARCOURS 12 du §11 — « RGPD : déposer une demande, la traiter, exporter par
# jeton, effacer, vérifier la propagation vers le site et l'anti-réinsertion. »
#
# ⚠️ Ce parcours REJOUE AU GESTE trois fermetures que le registre déclare
# fermées sur lecture de diff :
#   B15-003  l'export art. 15/20 ne couvrait que 4 tables sur 31
#   B15-006  l'effacement laissait adresse et téléphone dans six tables
#   B15-010  les routes RGPD n'exigeaient aucune permission
set -uo pipefail
S="$1"; B="http://verif.localhost:8080"; J="$S/cookies.txt"
X() { grep XSRF "$J" | awk '{print $7}' | sed 's/%3D/=/g'; }
api() { # methode chemin [fichier_corps]
  local m="$1" c="$2" f="${3:-}"
  if [ -n "$f" ]; then
    curl -s -b "$J" --resolve verif.localhost:8080:127.0.0.1 -X "$m" \
      -H "Content-Type: application/json" -H "Accept: application/json" \
      -H "Origin: $B" -H "Referer: $B/rgpd/requests" -H "X-XSRF-TOKEN: $(X)" \
      --data-binary "@$f" "$B/api/v1$c"
  else
    curl -s -b "$J" --resolve verif.localhost:8080:127.0.0.1 -X "$m" \
      -H "Accept: application/json" -H "Origin: $B" -H "Referer: $B/rgpd/requests" \
      -H "X-XSRF-TOKEN: $(X)" "$B/api/v1$c"
  fi
}
sql() { docker exec crmverif-postgres psql -U axion -d axion_crm -tAc "$1" 2>&1; }

SUJET="jean.dupont@exemple.test"

echo "════════ ÉTAT AVANT ════════"
sql "select 'contact: '||first_name||' '||last_name||' | '||coalesce(email::text,'(null)')||' | '||coalesce(phone,'(null)') from contacts;"

echo
echo "════════ 1. DÉPOSER UNE DEMANDE D'ACCÈS (art. 15) ════════"
printf '%s' "{\"type\":\"access\",\"subject_email\":\"$SUJET\"}" > "$S/rgpd-acces.json"
REP=$(api POST /rgpd/requests "$S/rgpd-acces.json"); echo "  $REP" | head -c 300; echo
ID=$(printf '%s' "$REP" | python -c "import sys,json
try:
  d=json.load(sys.stdin); d=d.get('data',d); print(d.get('id',''))
except Exception: print('')" 2>/dev/null)
echo "  id = $ID"

echo
echo "════════ 2. LA TRAITER ════════"
[ -n "$ID" ] && api POST "/rgpd/requests/$ID/process" | head -c 400; echo
echo "  --- ce que la demande porte en base ---"
[ -n "$ID" ] && sql "select 'statut='||status||' | metadata='||coalesce(metadata::text,'(null)') from rgpd_requests where id=$ID;"

echo
echo "════════ 3. EXPORTER PAR JETON (route PUBLIQUE, sans session) ════════"
JETON=$([ -n "$ID" ] && sql "select coalesce(metadata->>'token', metadata->>'export_token', '') from rgpd_requests where id=$ID;" | tr -d ' \r')
echo "  jeton lu en base : ${JETON:0:24}..."
if [ -n "$JETON" ]; then
  echo -n "  GET /rgpd/export/<jeton> SANS session -> "
  curl -s --resolve verif.localhost:8080:127.0.0.1 -H "Accept: application/json" \
    -o "$S/export.json" -w '%{http_code}\n' "$B/api/v1/rgpd/export/$JETON"
  echo "  tables couvertes par l'export :"
  python -c "
import json
try:
    d=json.load(open(r'$S/export.json'))
    def cles(o,p=''):
        if isinstance(o,dict):
            for k,v in o.items():
                if isinstance(v,list): print('   ',(p+k).ljust(32),len(v),'ligne(s)')
                elif isinstance(v,dict): cles(v,p+k+'.')
    cles(d)
except Exception as e: print('   (illisible)',str(e)[:70])"
  echo -n "  GET /rgpd/export/<jeton BIDON> -> "
  curl -s --resolve verif.localhost:8080:127.0.0.1 -H "Accept: application/json" -o /dev/null -w '%{http_code}  (404 attendu)\n' "$B/api/v1/rgpd/export/jeton-invente-par-un-tiers"
else
  echo "  ⚠️ aucun jeton dans metadata — l'export par jeton n'est pas atteignable par ce chemin"
fi

echo
echo "════════ 4. DÉPOSER ET TRAITER UN EFFACEMENT (art. 17) ════════"
printf '%s' "{\"type\":\"erasure\",\"subject_email\":\"$SUJET\"}" > "$S/rgpd-eff.json"
REP2=$(api POST /rgpd/requests "$S/rgpd-eff.json")
ID2=$(printf '%s' "$REP2" | python -c "import sys,json
try:
  d=json.load(sys.stdin); d=d.get('data',d); print(d.get('id',''))
except Exception: print('')" 2>/dev/null)
echo "  id = $ID2"
[ -n "$ID2" ] && api POST "/rgpd/requests/$ID2/process" | head -c 400; echo

echo
echo "════════ 5. LA PERSONNE A-T-ELLE VRAIMENT DISPARU ? (B15-006) ════════"
sql "select 'contacts    : '||count(*)||' ligne(s) restantes pour ce courriel' from contacts where email::text = '$SUJET';"
sql "select 'contacts    : '||coalesce(first_name,'(null)')||' '||coalesce(last_name,'(null)')||' | mail='||coalesce(email::text,'(null)')||' | tel='||coalesce(phone,'(null)') from contacts;"
echo "  --- traces ailleurs ---"
for t in unsubscribes opt_out dnc_entries email_suppressions; do
  sql "select '  $t : '||count(*) from $t;" 2>/dev/null | grep -v ERROR
done

echo
echo "════════ 6. ANTI-RÉINSERTION — la personne peut-elle revenir ? ════════"
sql "insert into contacts (workspace_id, company_id, first_name, last_name, email, email_status, phone, created_at, updated_at)
     values ('e43437b9-559f-4a35-afc9-4ef0cb2640f6', 5, 'Jean', 'Dupont', '$SUJET', 'valid', '0102030405', now(), now())
     returning 'REINSERE id='||id;" | grep -v "^$"
sql "select '  contacts portant ce courriel apres reinsertion : '||count(*) from contacts where email::text='$SUJET';"

echo
echo "════════ 7. PROPAGATION VERS LE SITE ════════"
echo -n "  POST /api/v1/site-sync/gdpr sans signature -> "
curl -s --resolve verif.localhost:8080:127.0.0.1 -X POST -H "Content-Type: application/json" \
  -H "Accept: application/json" -d '{"type":"erasure","email":"'"$SUJET"'"}' \
  -o /dev/null -w '%{http_code}\n' "$B/api/v1/site-sync/gdpr"
