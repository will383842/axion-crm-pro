#!/usr/bin/env bash
# K5 : genere SUR LE SERVEUR D'ENVOI les cles DKIM RSA 2048 (selecteur principal + selecteur
# de secours publie d'avance, pour une rotation sans interruption) et sort les TXT publics.
# La cle privee reste dans un fichier 0600 (dossier 0700) : jamais affichee, jamais journalisee,
# jamais dans le depot. Ne remplace JAMAIS une cle existante.
# Usage : generer-dkim.sh --domaine D --selecteur S --secours S2 [--repertoire /etc/axion-envoi/dkim]
set -euo pipefail
set +x
umask 077

DIR_SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REP=/etc/axion-envoi/dkim
DOM=""; SEL=""; SEC=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --domaine) DOM="${2:-}"; shift 2 ;;
    --selecteur) SEL="${2:-}"; shift 2 ;;
    --secours) SEC="${2:-}"; shift 2 ;;
    --repertoire) REP="${2:-}"; shift 2 ;;
    *) echo "argument inconnu : $1" >&2; exit 2 ;;
  esac
done
[[ "$DOM" =~ ^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$ ]] || { echo "domaine invalide" >&2; exit 2; }
for s in "$SEL" "$SEC"; do
  [[ "$s" =~ ^[a-z0-9]([a-z0-9-]{0,30}[a-z0-9])?$ ]] || { echo "selecteur invalide" >&2; exit 2; }
done
[[ "$SEL" != "$SEC" ]] || { echo "le selecteur de secours doit differer du principal" >&2; exit 2; }
command -v openssl >/dev/null || { echo "openssl requis" >&2; exit 1; }

DOSSIER="$REP/$DOM"
mkdir -p "$DOSSIER"; chmod 0700 "$REP" "$DOSSIER"
for s in "$SEL" "$SEC"; do
  if [[ -e "$DOSSIER/$s.pem" ]]; then echo "cle deja presente pour $s : refus d'ecraser" >&2; exit 1; fi
done

# Coupe en chaines de 255 octets au plus (meme regle que PlanDns::decouperTxt).
decouper() { # decouper <valeur>
  local v="$1" sortie="" 
  while [[ -n "$v" ]]; do sortie+="\"${v:0:255}\" "; v="${v:255}"; done
  printf '%s' "${sortie% }"
}

h=""
while IFS= read -r l; do [[ -z "$l" || "$l" == \#* ]] && continue; h+="${h:+:}${l,,}"; done <"$DIR_SCRIPT/entetes-signes.txt"

for s in "$SEL" "$SEC"; do
  tmp="$(mktemp "$DOSSIER/.tmp.XXXXXX")"
  openssl genrsa -out "$tmp" 2048 >/dev/null 2>&1
  chmod 0600 "$tmp"; mv -n "$tmp" "$DOSSIER/$s.pem"
  pub="$(openssl rsa -in "$DOSSIER/$s.pem" -pubout -outform DER 2>/dev/null | base64 -w0)"
  txt="$(decouper "v=DKIM1; k=rsa; p=$pub")"
  nom="$s._domainkey.$DOM"
  printf '%s IN TXT %s\n' "$nom" "$txt" >"$DOSSIER/$s._domainkey.txt"
  chmod 0644 "$DOSSIER/$s._domainkey.txt"
  echo "$nom IN TXT $txt"
done
echo "signer avec : s=$SEL (principal) ; secours publie d'avance : s=$SEC ; h=$h"
echo "cles privees : $DOSSIER/*.pem (0600, non affichees)"
