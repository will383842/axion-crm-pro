#!/usr/bin/env bash
# Verification STATIQUE de nftables.conf : aucun port ouvert hors WireGuard.
# Usage : verifier-nftables.sh [fichier]   (defaut : ../nftables.conf)
# Code 0 = conforme ; 1 = violation ; 2 = fichier illisible.
set -uo pipefail
F="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/nftables.conf}"
[[ -r "$F" ]] || { echo "ILLISIBLE: $F"; exit 2; }
# Sans commentaires ni lignes vides.
C="$(sed -e 's/#.*//' "$F" | grep -v '^[[:space:]]*$')"
ko=0
err() { echo "VIOLATION: $*"; ko=1; }

grep -Eq 'hook input +priority +[a-z0-9]+ *; *policy drop' <<<"$C" || err "chaine input sans policy drop"
grep -Eq 'hook output +priority +[a-z0-9]+ *; *policy drop' <<<"$C" || err "chaine output sans policy drop"
grep -Eq 'hook forward +priority +[a-z0-9]+ *; *policy drop' <<<"$C" || err "chaine forward sans policy drop"
grep -Eq 'policy accept' <<<"$C" && err "policy accept interdite"

# Entree : seules regles 'accept' autorisees = loopback, established, WireGuard UDP, SSH par wg0.
in_block="$(awk '/chain input/,/^[[:space:]]*}/' <<<"$C")"
while IFS= read -r l; do
  [[ "$l" == *accept* ]] || continue
  case "$l" in
    *'iif "lo"'*|*'iifname "lo"'*) ;;
    *'ct state established,related'*) ;;
    *'udp dport $wg_port'*|*'udp dport 51820'*) ;;
    *'iifname "wg0"'*'tcp dport 22'*) ;;
    *) err "entree non autorisee: $l" ;;
  esac
done <<<"$in_block"
# Aucun 'tcp dport' en entree hors wg0.
while IFS= read -r l; do
  [[ "$l" == *'tcp dport'* && "$l" == *accept* && "$l" != *'iifname "wg0"'* ]] && err "port TCP ouvert hors wg0: $l"
done <<<"$in_block"

# Sortie : port 25 uniquement depuis <ip-envoi>.
out_block="$(awk '/chain output/,/^[[:space:]]*}/' <<<"$C")"
grep -q 'tcp dport 25' <<<"$out_block" || err "sortie 25 absente"
while IFS= read -r l; do
  [[ "$l" == *'tcp dport 25'* && "$l" == *accept* ]] || continue
  [[ "$l" == *'ip saddr $ip_envoi'* ]] || err "sortie 25 sans filtre ip saddr \$ip_envoi: $l"
done <<<"$out_block"
grep -Eq '^[[:space:]]*(define|\$)?.*ip_envoi *= *<ip-envoi>' <<<"$C" || err "marqueur <ip-envoi> absent"
exit "$ko"
