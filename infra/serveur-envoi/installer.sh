#!/usr/bin/env bash
# Installation IDEMPOTENTE du serveur d'envoi (Debian/Ubuntu, Hetzner).
# A lancer en root, SUR LE SERVEUR D'ENVOI, jamais sur la prod du CRM.
# Sans --oui : simple repetition a blanc (affiche ce qui serait fait).
# Decision D7 : AUCUN tunnel vers la prod du CRM. Le CRM ouvre un HTTPS signe HMAC
# vers <ip-gestion>:443 ; WireGuard = administration seulement (pair = poste admin).
# Valeurs reelles : /etc/axion-envoi/valeurs.env (hors depot) avec
#   IP_ENVOI IP_GESTION IP_CRM_PROD IP_RESOLVEUR IP_TUNNEL IP_TUNNEL_ADMIN PORT_WG CLE_PUBLIQUE_ADMIN
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
for v in IP_ENVOI IP_GESTION IP_CRM_PROD IP_RESOLVEUR IP_TUNNEL IP_TUNNEL_ADMIN PORT_WG CLE_PUBLIQUE_ADMIN; do
  [[ -n "${!v:-}" ]] || { echo "valeur manquante : $v" >&2; exit 1; }
done

# shellcheck source=lib/rendu.sh
source "$SRC/lib/rendu.sh"

agir apt-get install -y --no-install-recommends wireguard nftables docker.io docker-compose-v2
agir install -d -m 0755 "$CIBLE" "$CIBLE/kumomta"
agir install -m 0644 "$SRC/docker-compose.yml" "$CIBLE/docker-compose.yml"
agir install -m 0644 "$SRC/kumomta/init.lua" "$CIBLE/kumomta/init.lua"
# L'image est tiree AVANT de charger le pare-feu : ensuite, depuis la gestion, seul
# le 443 sortant est ouvert (et NTP/DNS) ; rien ne depend d'un tirage apres coup.
agir systemctl enable --now docker.service
agir docker compose -f "$CIBLE/docker-compose.yml" pull

if [[ $REEL == 1 ]]; then
  # Cle privee generee ici, jamais ecrasee, jamais committee, jamais en argument.
  if [[ ! -s /etc/wireguard/cle-privee ]]; then
    install -d -m 0700 /etc/wireguard
    (umask 077; wg genkey >/etc/wireguard/cle-privee)
  fi
  sed -i "s|<ip-envoi>|$IP_ENVOI|g" "$CIBLE/kumomta/init.lua"
  if grep -q '<[a-z-]\+>' "$CIBLE/kumomta/init.lua"; then echo "marqueur non remplace dans init.lua" >&2; exit 1; fi
  (umask 077; rendre "$SRC/wireguard/wg0.conf.modele" /etc/wireguard/wg0.conf); chmod 0600 /etc/wireguard/wg0.conf
  rendre "$SRC/nftables.conf" /etc/nftables.conf.nouveau
  nft -c -f /etc/nftables.conf.nouveau   # validation AVANT toute charge
  mv -f /etc/nftables.conf.nouveau /etc/nftables.conf
  install -m 0644 "$SRC/kumomta-envoi.service" /etc/systemd/system/kumomta-envoi.service
  systemctl daemon-reload
  # NB : le jeu de regles nftables.conf commence par `flush ruleset` (voir RUNBOOK).
  systemctl enable --now wg-quick@wg0.service nftables.service
  systemctl restart nftables.service
  # SSH par le tunnel : seulement si wg0 est monte (sinon on se verrouille dehors).
  if ip link show wg0 >/dev/null 2>&1; then
    rendre "$SRC/sshd-tunnel.conf" /etc/ssh/sshd_config.d/10-tunnel.conf
    install -d -m 0755 /etc/systemd/system/ssh.service.d
    install -m 0644 "$SRC/sshd-apres-wg0.conf" /etc/systemd/system/ssh.service.d/apres-wg0.conf
    # Ubuntu 24.04 : sshd est active par ssh.socket, qui IGNORE ListenAddress.
    # On passe en service classique pour que l'ecoute soit celle du drop-in.
    if systemctl list-unit-files ssh.socket 2>/dev/null | grep -q '^ssh\.socket'; then
      systemctl disable --now ssh.socket
      systemctl enable ssh.service
    fi
    systemctl daemon-reload
    sshd -t && systemctl restart ssh
  else
    echo "wg0 absent : SSH non restreint (a refaire apres le tunnel)" >&2
  fi
  systemctl enable --now kumomta-envoi.service
else
  echo "+ (rendu des modeles, nft -c, systemd, sshd : appliques avec --oui)"
fi
echo "termine."
