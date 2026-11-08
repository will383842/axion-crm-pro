#!/usr/bin/env bash
# Bibliotheque PARTAGEE : l'installateur ET les tests sourcent ce fichier (une seule
# logique de rendu, jamais dupliquee). A sourcer ; ne rien executer a la source.
# Variables attendues : IP_ENVOI IP_GESTION IP_CRM_PROD IP_RESOLVEUR IP_TUNNEL
#                       IP_TUNNEL_ADMIN PORT_WG CLE_PUBLIQUE_ADMIN
# CLE_PRIVEE_FICHIER (defaut /etc/wireguard/cle-privee) : cle du serveur, lue PAR FICHIER.

# --- Forme des valeurs : une IPv4 UNIQUE par valeur (jamais /masque, plage, liste, adresse generique).
_octet='(25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])'
ip4_valide() { # ip4_valide <valeur>
  [[ "$1" =~ ^${_octet}\.${_octet}\.${_octet}\.${_octet}$ ]] || return 1
  case "$1" in 0.*|127.*|255.*) return 1 ;; esac   # generique, loopback, reservee/diffusion
  return 0
}
port_valide() { [[ "$1" =~ ^[0-9]{1,5}$ ]] && (( 10#$1 >= 1 && 10#$1 <= 65535 )); }
cle_wg_valide() { [[ "$1" =~ ^[A-Za-z0-9+/]{43}=$ ]]; } # cle WireGuard : base64 de 32 octets = 44 car.

# Lecture SANS execution du fichier de valeurs (jamais `source` : un `$(...)`, `;`, `|` ou `&`
# dans une valeur ne doit rien lancer). Format : CLE=valeur, guillemets simples/doubles facultatifs.
charger_valeurs() { # charger_valeurs <fichier>
  local k v l
  while IFS= read -r l || [[ -n "$l" ]]; do
    l="${l%%#*}"; [[ "$l" =~ ^[[:space:]]*$ ]] && continue
    k="${l%%=*}"; v="${l#*=}"; k="${k//[[:space:]]/}"
    [[ "$l" == *=* ]] || { echo "ligne invalide dans $1" >&2; return 1; }
    v="${v#"${v%%[![:space:]]*}"}"; v="${v%"${v##*[![:space:]]}"}"
    if [[ "$v" =~ ^\"(.*)\"$ || "$v" =~ ^\'(.*)\'$ ]]; then v="${BASH_REMATCH[1]}"; fi
    case "$k" in
      IP_ENVOI|IP_GESTION|IP_CRM_PROD|IP_RESOLVEUR|IP_TUNNEL|IP_TUNNEL_ADMIN|PORT_WG|CLE_PUBLIQUE_ADMIN) printf -v "$k" '%s' "$v" ;;
      *) echo "cle inconnue dans $1 : $k" >&2; return 1 ;;
    esac
  done <"$1"
}

# valider_valeurs : code 0 si tout est conforme ; sinon nomme chaque variable fautive.
valider_valeurs() {
  local v ko=0
  for v in IP_ENVOI IP_GESTION IP_CRM_PROD IP_RESOLVEUR IP_TUNNEL IP_TUNNEL_ADMIN; do
    ip4_valide "${!v:-}" || { echo "valeur invalide : $v (une IPv4 unique attendue, ni /masque, ni plage, ni adresse generique)" >&2; ko=1; }
  done
  port_valide "${PORT_WG:-}" || { echo "valeur invalide : PORT_WG (entier 1-65535)" >&2; ko=1; }
  cle_wg_valide "${CLE_PUBLIQUE_ADMIN:-}" || { echo "valeur invalide : CLE_PUBLIQUE_ADMIN (cle WireGuard base64, 44 caracteres)" >&2; ko=1; }
  [[ "${IP_ENVOI:-}" != "${IP_GESTION:-x}" ]] || { echo "valeur invalide : IP_ENVOI et IP_GESTION doivent differer" >&2; ko=1; }
  [[ "${IP_TUNNEL:-}" != "${IP_TUNNEL_ADMIN:-x}" ]] || { echo "valeur invalide : IP_TUNNEL et IP_TUNNEL_ADMIN doivent differer" >&2; ko=1; }
  return "$ko"
}

# Rend un modele. Rien de secret en argument de commande : la cle privee du serveur
# est lue PAR FICHIER (awk getline), jamais via $(...) ni sur une ligne de commande.
# Marqueur = motif ferme <[a-z][a-z0-9-]*> : « <-> » ou « <=> » en commentaire n'en est pas un.
rendre() { # rendre <modele> <sortie>
  local cle="${CLE_PRIVEE_FICHIER:-/etc/wireguard/cle-privee}"
  sed -e "s|<ip-envoi>|$IP_ENVOI|g" -e "s|<ip-gestion>|$IP_GESTION|g" \
      -e "s|<ip-crm-prod>|$IP_CRM_PROD|g" -e "s|<ip-resolveur>|$IP_RESOLVEUR|g" \
      -e "s|<ip-tunnel-admin>|$IP_TUNNEL_ADMIN|g" -e "s|<ip-tunnel>|$IP_TUNNEL|g" \
      -e "s|<port-wg>|$PORT_WG|g" -e "s|<cle-publique-admin>|$CLE_PUBLIQUE_ADMIN|g" "$1" \
    | CLE_FICHIER="$cle" awk 'BEGIN { if ((getline k < ENVIRON["CLE_FICHIER"]) <= 0) k = "<cle-privee-serveur>" }
           { gsub(/<cle-privee-serveur>/, k) } 1' >"$2"
  if grep -Eq '<[a-z][a-z0-9-]*>' "$2"; then echo "marqueur non remplace dans $2" >&2; return 1; fi
}

# Le serveur d'envoi est Ubuntu (docker-compose-v2 n'existe pas sous Debian).
os_ubuntu() { ( # shellcheck disable=SC1090
  . "${OS_RELEASE:-/etc/os-release}" 2>/dev/null && [[ "${ID:-}" == ubuntu ]] ); }

# Le port 80 sortant est ferme : les sources APT passent en https (idempotent).
# Lignes « deb ... http:// » et champ deb822 « URIs: ... » (plusieurs URI possibles).
apt_en_https() { # apt_en_https [repertoire apt, defaut /etc/apt]
  local d="${1:-/etc/apt}" f
  for f in "$d/sources.list" "$d"/sources.list.d/*.list "$d"/sources.list.d/*.sources; do
    [[ -f "$f" ]] || continue
    sed -i -E -e '/^(deb|deb-src)[[:space:]]/ s#http://#https://#g' -e '/^URIs:/ s#http://#https://#g' "$f"
  done
}
