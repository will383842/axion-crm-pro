#!/usr/bin/env bash
# Installation IDEMPOTENTE du serveur d'envoi (Debian/Ubuntu, Hetzner).
# A lancer en root, SUR LE SERVEUR D'ENVOI, jamais sur la prod du CRM.
# Sans --oui : simple repetition a blanc (affiche ce qui serait fait).
# Valeurs reelles : /etc/axion-envoi/valeurs.env (hors depot) avec
#   IP_ENVOI IP_RESOLVEUR IP_TUNNEL IP_TUNNEL_CRM PORT_WG CLE_PUBLIQUE_CRM
set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CIBLE=/opt/axion-envoi
VALEURS=/etc/axion-envoi/valeurs.env
REEL=0; [[ "${1:-}" == "--oui" ]] && REEL=1
agir() { echo "+ $*"; if [[ $REEL == 1 ]]; then "$@"; fi; }

[[ $REEL == 0 ]] && echo "== repetition a blanc (ajouter --oui pour appliquer) =="
[[ $REEL == 1 && $EUID -ne 0 ]] && { echo "root requis" >&2; exit 1; }
[[ -r $VALEURS ]] || { echo "valeurs absentes : $VALEURS" >&2; exit 1; }
# shellcheck disable=SC1090
source "$VALEURS"
for v in IP_ENVOI IP_RESOLVEUR IP_TUNNEL IP_TUNNEL_CRM PORT_WG CLE_PUBLIQUE_CRM; do
  [[ -n "${!v:-}" ]] || { echo "valeur manquante : $v" >&2; exit 1; }
done

rendre() { # rendre <modele> <sortie>
  sed -e "s|<ip-envoi>|$IP_ENVOI|g" -e "s|<ip-resolveur>|$IP_RESOLVEUR|g" \
      -e "s|<ip-tunnel-crm>|$IP_TUNNEL_CRM|g" -e "s|<ip-tunnel>|$IP_TUNNEL|g" \
      -e "s|<port-wg>|$PORT_WG|g" -e "s|<cle-publique-crm>|$CLE_PUBLIQUE_CRM|g" \
      -e "s|<cle-privee-serveur>|$(cat /etc/wireguard/cle-privee 2>/dev/null || echo '<cle-privee-serveur>')|g" \
      "$1" >"$2"
}

agir apt-get install -y --no-install-recommends wireguard nftables docker.io docker-compose-v2
agir install -d -m 0755 "$CIBLE" "$CIBLE/kumomta"
agir install -m 0644 "$SRC/docker-compose.yml" "$CIBLE/docker-compose.yml"
agir install -m 0644 "$SRC/kumomta/init.lua" "$CIBLE/kumomta/init.lua"

if [[ $REEL == 1 ]]; then
  # Cle privee generee ici, jamais ecrasee, jamais committee.
  if [[ ! -s /etc/wireguard/cle-privee ]]; then
    install -d -m 0700 /etc/wireguard
    (umask 077; wg genkey >/etc/wireguard/cle-privee)
  fi
  sed -i "s|<ip-envoi>|$IP_ENVOI|g" "$CIBLE/kumomta/init.lua"
  rendre "$SRC/wireguard/wg0.conf.modele" /etc/wireguard/wg0.conf; chmod 0600 /etc/wireguard/wg0.conf
  rendre "$SRC/nftables.conf" /etc/nftables.conf.nouveau
  nft -c -f /etc/nftables.conf.nouveau   # validation AVANT toute charge
  mv -f /etc/nftables.conf.nouveau /etc/nftables.conf
  install -m 0644 "$SRC/kumomta-envoi.service" /etc/systemd/system/kumomta-envoi.service
  systemctl daemon-reload
  systemctl enable --now wg-quick@wg0.service nftables.service
  systemctl restart nftables.service
  # SSH par le tunnel : seulement si wg0 est monte (sinon on se verrouille dehors).
  if ip link show wg0 >/dev/null 2>&1; then
    rendre "$SRC/sshd-tunnel.conf" /etc/ssh/sshd_config.d/10-tunnel.conf
    sshd -t && systemctl reload ssh
  else
    echo "wg0 absent : SSH non restreint (a refaire apres le tunnel)" >&2
  fi
  systemctl enable --now kumomta-envoi.service
else
  echo "+ (rendu des modeles, nft -c, systemd, sshd : appliques avec --oui)"
fi
echo "termine."
