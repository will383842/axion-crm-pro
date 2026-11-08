#!/usr/bin/env bash
# Installation IDEMPOTENTE du serveur d'envoi (Ubuntu 24.04 UNIQUEMENT, Hetzner).
# (docker-compose-v2 n'existe pas sous Debian : l'installateur refuse un autre systeme.)
# A lancer en root, SUR LE SERVEUR D'ENVOI, jamais sur la prod du CRM.
# Sans --oui : repetition a blanc (valide et RENDU les modeles dans un dossier temporaire,
# affiche ce qui serait fait, ne modifie rien).
# Decision D7 : AUCUN tunnel vers la prod du CRM. Le CRM ouvre un HTTPS signe HMAC
# vers <ip-gestion>:443 ; WireGuard = administration seulement (pair = poste admin).
# Valeurs reelles : /etc/axion-envoi/valeurs.env (hors depot) avec
#   IP_ENVOI IP_GESTION IP_CRM_PROD IP_RESOLVEUR IP_TUNNEL IP_TUNNEL_ADMIN PORT_WG CLE_PUBLIQUE_ADMIN
set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CIBLE=/opt/axion-envoi
VALEURS="${AXION_VALEURS:-/etc/axion-envoi/valeurs.env}"
REEL=0; for a in "$@"; do [[ "$a" == "--oui" ]] && REEL=1; done   # n'importe quel ordre des drapeaux
# Log hook K7 : DESACTIVE par defaut (aucun relais en service, K7-D5). Jamais active sans ce drapeau explicite.
LOGHOOK=0; for a in "$@"; do [[ "$a" == "--avec-loghook" ]] && LOGHOOK=1; done
agir() { echo "+ $*"; if [[ $REEL == 1 ]]; then "$@"; fi; }
# shellcheck source=lib/rendu.sh
source "$SRC/lib/rendu.sh"

[[ $REEL == 0 ]] && echo "== repetition a blanc (ajouter --oui pour appliquer) =="
[[ $REEL == 1 && $EUID -ne 0 ]] && { echo "root requis" >&2; exit 1; }
os_ubuntu || { echo "Ubuntu requis (docker-compose-v2 absent de Debian)" >&2; exit 1; }
[[ -r $VALEURS ]] || { echo "valeurs absentes : $VALEURS" >&2; exit 1; }
charger_valeurs "$VALEURS"   # lecture sans execution (jamais `source` d'un fichier de valeurs)
for v in IP_ENVOI IP_GESTION IP_CRM_PROD IP_RESOLVEUR IP_TUNNEL IP_TUNNEL_ADMIN PORT_WG CLE_PUBLIQUE_ADMIN; do
  [[ -n "${!v:-}" ]] || { echo "valeur manquante : $v" >&2; exit 1; }
done
valider_valeurs   # forme de CHAQUE valeur ; set -e arrete tout si une seule est fautive

tmpd="$(umask 077; mktemp -d)"; trap 'rm -r -- "${tmpd:?}"' EXIT

# Rendu complet du pare-feu : verifie PAR LA LISTE BLANCHE (--rendu) puis par nft -c.
verifier_rendu_nft() { # verifier_rendu_nft <fichier rendu> <exiger-nft: 0|1>
  IP_ENVOI="$IP_ENVOI" IP_GESTION="$IP_GESTION" IP_CRM_PROD="$IP_CRM_PROD" PORT_WG="$PORT_WG" IP_RESOLVEUR="$IP_RESOLVEUR" \
    bash "$SRC/tests/verifier-nftables.sh" --rendu "$1" >&2 || { echo "pare-feu rendu non conforme (liste blanche)" >&2; return 1; }
  if [[ "$2" == 1 ]]; then nft -c -f "$1"   # validation AVANT toute charge
  elif command -v nft >/dev/null 2>&1; then nft -c -f "$1" 2>/dev/null || echo "(a blanc : nft -c non concluant sans droits ; fait pour de bon avec --oui)" >&2
  fi
}

# PRE-VOL (a blanc ET reel) : les 4 modeles doivent rendre, avec une cle fictive, AVANT
# toute modification du systeme ; un modele qui ne rend pas arrete tout sans rien casser.
printf 'A%.0s' {1..43} >"$tmpd/cle-fictive"; echo '=' >>"$tmpd/cle-fictive"
CLE_PRIVEE_FICHIER="$tmpd/cle-fictive" rendre "$SRC/wireguard/wg0.conf.modele" "$tmpd/prevol-wg0.conf"
CLE_PRIVEE_FICHIER="$tmpd/cle-fictive" rendre "$SRC/sshd-tunnel.conf" "$tmpd/prevol-sshd.conf"
CLE_PRIVEE_FICHIER="$tmpd/cle-fictive" rendre "$SRC/kumomta/init.lua" "$tmpd/prevol-init.lua"
CLE_PRIVEE_FICHIER="$tmpd/cle-fictive" rendre "$SRC/nftables.conf" "$tmpd/prevol-nft.conf"
verifier_rendu_nft "$tmpd/prevol-nft.conf" 0
echo "pre-vol : 4 modeles rendus et pare-feu conforme"

# Les sources APT passent en https : le port 80 sortant est ferme (voir RUNBOOK).
if [[ $REEL == 1 ]]; then apt_en_https; else echo "+ apt_en_https (sources APT http:// -> https://)"; fi
# Relance : on note si le compose a change AVANT de l'ecraser (init.lua, wg0, unite : plus bas).
changement_wg=0; changement_kumo=0
if [[ $REEL == 1 ]]; then cmp -s "$SRC/docker-compose.yml" "$CIBLE/docker-compose.yml" 2>/dev/null || changement_kumo=1; fi
agir apt-get update
agir apt-get install -y --no-install-recommends wireguard nftables docker.io docker-compose-v2
agir install -d -m 0755 "$CIBLE" "$CIBLE/kumomta"
# L'image est tiree AVANT de charger le pare-feu : ensuite, depuis la gestion, seul
# le 443 sortant est ouvert (et NTP/DNS) ; rien ne depend d'un tirage apres coup.
agir install -m 0644 "$SRC/docker-compose.yml" "$CIBLE/docker-compose.yml"
agir systemctl enable --now docker.service
agir docker compose -f "$CIBLE/docker-compose.yml" pull

if [[ $REEL == 1 ]]; then
  # Cle privee generee ici, jamais ecrasee, jamais committee, jamais en argument.
  if [[ ! -s /etc/wireguard/cle-privee ]]; then
    install -d -m 0700 /etc/wireguard
    (umask 077; wg genkey >/etc/wireguard/cle-privee)
  fi
  # Rendu FINAL (cle reelle lue par fichier) dans le dossier temporaire 0700.
  rendre "$SRC/wireguard/wg0.conf.modele" "$tmpd/wg0.conf"
  rendre "$SRC/kumomta/init.lua" "$tmpd/init.lua"
  rendre "$SRC/nftables.conf" "$tmpd/nftables.conf"
  verifier_rendu_nft "$tmpd/nftables.conf" 1

  # Detection des changements (relance) : on compare AVANT d'ecraser.
  cmp -s "$tmpd/wg0.conf" /etc/wireguard/wg0.conf 2>/dev/null || changement_wg=1
  cmp -s "$tmpd/init.lua" "$CIBLE/kumomta/init.lua" 2>/dev/null || changement_kumo=1
  cmp -s "$SRC/kumomta/dkim.lua" "$CIBLE/kumomta/dkim.lua" 2>/dev/null || changement_kumo=1
  cmp -s "$SRC/kumomta/loghook.lua" "$CIBLE/kumomta/loghook.lua" 2>/dev/null || changement_kumo=1
  cmp -s "$SRC/kumomta-envoi.service" /etc/systemd/system/kumomta-envoi.service 2>/dev/null || changement_kumo=1
  wg_actif=0; systemctl is-active --quiet wg-quick@wg0.service && wg_actif=1
  kumo_actif=0; systemctl is-active --quiet kumomta-envoi.service && kumo_actif=1

  install -m 0644 "$tmpd/init.lua" "$CIBLE/kumomta/init.lua"
  install -m 0644 "$SRC/kumomta/dkim.lua" "$CIBLE/kumomta/dkim.lua"   # K5 : signature DKIM (sans cle)
  # Interrupteur du log hook : ecrit a chaque passage (0 sans --avec-loghook), lu par docker compose (.env).
  echo "AXION_LOGHOOK_ACTIF=$LOGHOOK" >"$tmpd/compose.env"
  cmp -s "$tmpd/compose.env" "$CIBLE/.env" 2>/dev/null || changement_kumo=1
  install -m 0644 "$tmpd/compose.env" "$CIBLE/.env"
  install -d -m 0750 -o root -g 10025 /etc/axion-envoi/loghook   # dossier du secret Lua -> relais ; groupe 10025 = Kumo (sinon illisible) ; le secret lui-meme est pose a la main, jamais ici
  install -m 0644 "$SRC/kumomta/loghook.lua" "$CIBLE/kumomta/loghook.lua"   # K7 : log hook (require par init.lua)
  # Dossier des cles DKIM (genere par dkim/generer-dkim.sh) : cree vide s'il manque, jamais touche s'il existe.
  install -d -m 0755 /etc/axion-envoi
  install -d -m 0700 /etc/wireguard
  install -m 0600 "$tmpd/wg0.conf" /etc/wireguard/wg0.conf
  install -m 0644 "$tmpd/nftables.conf" /etc/nftables.conf
  install -m 0644 "$SRC/kumomta-envoi.service" /etc/systemd/system/kumomta-envoi.service
  systemctl daemon-reload
  # NB : le jeu de regles nftables.conf commence par `flush ruleset` (voir RUNBOOK).
  systemctl enable --now wg-quick@wg0.service nftables.service
  # Relance : wg0.conf change -> reload (sans couper le tunnel) ; pare-feu recharge.
  if [[ $wg_actif == 1 && $changement_wg == 1 ]]; then systemctl reload wg-quick@wg0.service; fi
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
  # Relance : init.lua / compose / unite modifies -> kumomta-envoi doit les relire.
  if [[ $kumo_actif == 1 && $changement_kumo == 1 ]]; then systemctl restart kumomta-envoi.service; fi
else
  echo "+ (rendu final, verifier-nftables --rendu, nft -c, relance conditionnelle, systemd, sshd : appliques avec --oui)"
fi
echo "termine."
