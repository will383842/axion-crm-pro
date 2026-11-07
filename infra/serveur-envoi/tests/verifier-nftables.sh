#!/usr/bin/env bash
# Verification STATIQUE de nftables.conf, en liste blanche a correspondance EXACTE.
# Chaque ligne (hors commentaires/vides, espaces normalises) doit etre IDENTIQUE a une
# ligne autorisee de SA chaine ; toute autre ligne (sous-chaine, variante, jump, goto,
# chaine ou table supplementaire, include...) est une violation. Toutes les chaines
# du fichier sont lues. Chaque regle autorisee doit aussi etre presente.
# Usage : verifier-nftables.sh [fichier]   (defaut : ../nftables.conf)
# Code 0 = conforme ; 1 = violation ; 2 = fichier illisible.
set -uo pipefail
F="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/nftables.conf}"
[[ -r "$F" ]] || { echo "ILLISIBLE: $F"; exit 2; }

ko=0
err() { echo "VIOLATION: $*"; ko=1; }

# --- Liste blanche (les variables $... restent LITTERALES : on compare du texte) ---
TOP=(
  'flush ruleset'
  'define ip_envoi = <ip-envoi>'
  'define ip_gestion = <ip-gestion>'
  'define ip_crm_prod = <ip-crm-prod>'
  'define wg_port = <port-wg>'
  'define ip_resolveur = <ip-resolveur>'
)
DECL_INPUT='type filter hook input priority 0; policy drop;'
DECL_FORWARD='type filter hook forward priority 0; policy drop;'
DECL_OUTPUT='type filter hook output priority 0; policy drop;'
# Entree : la 1re regle DOIT etre la premiere de la liste (rien n'entre sur <ip-envoi>).
IN=(
  'ip daddr $ip_envoi ct state new drop'
  'iif "lo" accept'
  'ct state invalid drop'
  'ct state established,related accept'
  'ip saddr $ip_crm_prod ip daddr $ip_gestion tcp dport 443 accept'
  'ip daddr $ip_gestion udp dport $wg_port accept'
  'iifname "wg0" tcp dport 22 accept'
)
OUT=(
  'oif "lo" accept'
  'ct state established,related accept'
  'ip saddr $ip_gestion udp sport $wg_port accept'
  'oifname "wg0" accept'
  'ip saddr $ip_gestion ip daddr $ip_resolveur udp dport 53 accept'
  'ip saddr $ip_gestion ip daddr $ip_resolveur tcp dport 53 accept'
  'ip saddr $ip_gestion tcp dport 443 accept'
  'ip saddr $ip_gestion udp dport 123 accept'
  'ip saddr $ip_envoi tcp dport 25 accept'
)
FWD=()

dans() { local x="$1"; shift; local e; for e in "$@"; do [[ "$e" == "$x" ]] && return 0; done; return 1; }

# Normalisation : sans commentaire, espaces/tabulations compactes, sans lignes vides.
mapfile -t L < <(sed -e 's/#.*//' -e 's/[[:space:]]\+/ /g' -e 's/^ //' -e 's/ $//' "$F" | grep -v '^$')

table=0; chaine=""; vu_decl=""; declare -A vu_in vu_out decl_ok; premiere_in=""; nin=0
for l in "${L[@]}"; do
  if [[ -z "$chaine" ]]; then
    if [[ "$l" == 'table inet filtre {' && $table == 0 ]]; then table=1; continue; fi
    if [[ $table == 1 && "$l" =~ ^chain\ ([a-z_]+)\ \{$ ]]; then
      chaine="${BASH_REMATCH[1]}"; decl_ok[$chaine]=0; nin=0; continue
    fi
    if [[ $table == 1 && "$l" == '}' ]]; then table=2; continue; fi
    if dans "$l" "${TOP[@]}"; then continue; fi
    err "ligne hors liste blanche (niveau fichier/table): $l"; continue
  fi
  # Dans une chaine.
  if [[ "$l" == '}' ]]; then chaine=""; continue; fi
  case "$chaine" in
    input)   decl="$DECL_INPUT";   liste=("${IN[@]}") ;;
    output)  decl="$DECL_OUTPUT";  liste=("${OUT[@]}") ;;
    forward) decl="$DECL_FORWARD"; liste=() ;;
    *)       decl="";              liste=() ;;
  esac
  if [[ -n "$decl" && "$l" == "$decl" ]]; then decl_ok[$chaine]=1; continue; fi
  if dans "$l" "${liste[@]}"; then
    nin=$((nin+1))
    if [[ "$chaine" == input ]]; then
      vu_in["$l"]=1; [[ $nin == 1 ]] && premiere_in="$l"
    elif [[ "$chaine" == output ]]; then vu_out["$l"]=1; fi
    continue
  fi
  err "regle hors liste blanche (chaine $chaine): $l"
done
[[ -n "$chaine" ]] && err "chaine $chaine non refermee"
[[ $table == 2 ]] || err "table inet filtre absente ou non refermee"

for c in input output forward; do
  [[ "${decl_ok[$c]:-0}" == 1 ]] || err "chaine $c absente ou sans la declaration exacte (policy drop)"
done
for l in "${IN[@]}";  do [[ -n "${vu_in[$l]:-}"  ]] || err "regle d'entree requise absente: $l"; done
for l in "${OUT[@]}"; do [[ -n "${vu_out[$l]:-}" ]] || err "regle de sortie requise absente: $l"; done
[[ "$premiere_in" == "${IN[0]}" ]] || err "la 1re regle d'entree doit etre: ${IN[0]}"
for t in "${TOP[@]}"; do
  grep -qxF "$t" < <(printf '%s\n' "${L[@]}") || err "ligne requise absente: $t"
done
exit "$ko"
