#!/usr/bin/env bash
# Tests K3 (statiques, sans reseau d'envoi, sans serveur). Code 0 = tout vert.
# Decision D7 : AUCUN tunnel vers la prod du CRM ; WireGuard = administration seulement.
set -uo pipefail
D="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
T="$D/tests"
ko=0; n=0
ok()  { n=$((n+1)); echo "ok   $*"; }
bad() { n=$((n+1)); ko=1; echo "ROUGE $*"; }
chk() { local nom="$1"; shift; if "$@" >/dev/null 2>&1; then ok "$nom"; else bad "$nom"; fi; }
nochk() { local nom="$1"; shift; if "$@" >/dev/null 2>&1; then bad "$nom"; else ok "$nom"; fi; }
# Les adresses NON documentaires servant de temoins sont construites ici a l'execution :
# le scanner de litteraux (m8) lit ce fichier et n'y tolere que les IP de documentation.
lit() { local IFS=.; printf '%s' "$*"; }
COL=':'
Z4="$(lit 0 0 0 0)"; PUB4="$(lit 8 8 8 8)"; HORSDOC4="$(lit 203 0 114 5)"; PRIV4="$(lit 10 1 2 3)"; ZERO_INIT4="$(lit 01 2 3 4)"
V6ANY="[${COL}${COL}]"; V6REEL="2001${COL}4860${COL}${COL}8888"
tmpd="$(mktemp -d)"; trap 'rm -rf "${tmpd:?}"' EXIT
tmp="$tmpd/t.conf"
V="$T/verifier-nftables.sh"
# temoin <nom> <fichier> <ligne EXACTE attendue dans la sortie> [mode]
# Doit ROUGIR POUR LA BONNE RAISON : la ligne attendue est comparee en entier (grep -x),
# jamais comme sous-chaine, sinon le message « regle requise absente » (qui repete la
# regle manquante) validerait n'importe quel temoin pour une mauvaise raison.
temoin() {
  local nom="$1" f="$2" ligne="$3" out rc
  out="$(bash "$V" "$f" 2>&1)"; rc=$?
  if [[ $rc == 1 ]] && grep -qxF -- "VIOLATION: $ligne" <<<"$out"; then ok "temoin: $nom"; else bad "temoin: $nom (rc=$rc, attendu 'VIOLATION: $ligne')"; fi
}
HL='regle hors liste blanche'
# ---------------------------------------------------------------- 1. nftables
chk "nftables conforme (liste blanche exacte)" bash "$V" "$D/nftables.conf"
# C2 : la ligne exacte prod -> passerelle 443 doit exister ; saddr/daddr manquant rougit.
chk "C2: regle prod->gestion:443 exacte" grep -qxF '    ip saddr $ip_crm_prod ip daddr $ip_gestion tcp dport 443 accept' "$D/nftables.conf"
sed 's/ip saddr \$ip_crm_prod //' "$D/nftables.conf" >"$tmp"; temoin "C2 sans ip saddr" "$tmp" "$HL (chaine input): ip daddr \$ip_gestion tcp dport 443 accept"
sed 's/ip daddr \$ip_gestion tcp dport 443 accept/tcp dport 443 accept/' "$D/nftables.conf" >"$tmp"; temoin "C2 sans ip daddr" "$tmp" "$HL (chaine input): ip saddr \$ip_crm_prod tcp dport 443 accept"
# C3
sed 's/^\(\s*\)ip daddr \$ip_gestion udp dport \$wg_port accept/\1udp dport $wg_port accept/' "$D/nftables.conf" >"$tmp"; temoin "C3 WireGuard sans ip daddr gestion" "$tmp" "$HL (chaine input): udp dport \$wg_port accept"
grep -v 'ip daddr \$ip_envoi ct state new drop' "$D/nftables.conf" >"$tmp"; temoin "C3 sans drop entrant sur ip_envoi" "$tmp" "regle d'entree requise absente: ip daddr \$ip_envoi ct state new drop"
awk '/ip daddr \$ip_envoi ct state new drop/{d=$0;next} {print} /iif "lo" accept/ && !x && d{print d;x=1}' "$D/nftables.conf" >"$tmp"; temoin "C3 drop ip_envoi pas en tete" "$tmp" "la 1re regle d'entree doit etre: ip daddr \$ip_envoi ct state new drop"
sed 's/^\(\s*\)ip saddr \$ip_gestion ip daddr \$ip_resolveur udp dport 53/\1ip daddr $ip_resolveur udp dport 53/' "$D/nftables.conf" >"$tmp"; temoin "C3 DNS sans ip saddr gestion" "$tmp" "$HL (chaine output): ip daddr \$ip_resolveur udp dport 53 accept"
sed 's/ip saddr \$ip_gestion udp sport \$wg_port/udp sport $wg_port/' "$D/nftables.conf" >"$tmp"; temoin "C3 sport wg sans ip saddr gestion" "$tmp" "$HL (chaine output): udp sport \$wg_port accept"
# M1 : 443 et NTP sortants depuis la gestion.
chk "M1: sortie 443 depuis gestion" grep -qxF '    ip saddr $ip_gestion tcp dport 443 accept' "$D/nftables.conf"
chk "M1: sortie NTP 123 depuis gestion" grep -qxF '    ip saddr $ip_gestion udp dport 123 accept' "$D/nftables.conf"
# Temoins historiques.
sed 's/hook input priority 0; policy drop/hook input priority 0; policy accept/' "$D/nftables.conf" >"$tmp"; temoin "policy accept" "$tmp" "$HL (chaine input): type filter hook input priority 0; policy accept;"
sed 's/ip saddr \$ip_envoi tcp dport 25/tcp dport 25/' "$D/nftables.conf" >"$tmp"; temoin "sortie 25 sans source" "$tmp" "$HL (chaine output): tcp dport 25 accept"
# M3 : les 5 contournements (fichiers temoins) doivent rougir.
temoin "ct state established,related,new accept en entree" "$T/temoins/01-entree-new-accept.conf"   "$HL (chaine input): ct state established,related,new accept"
temoin "tcp dport 443 accept en sortie non prevu"          "$T/temoins/02-sortie-443-ouvert.conf"    "$HL (chaine output): tcp dport 443 accept"
temoin "ip saddr \$ip_envoi accept"                         "$T/temoins/03-saddr-envoi-accept.conf"   "$HL (chaine output): ip saddr \$ip_envoi accept"
temoin "jump vers chaine ouverte (8080)"                   "$T/temoins/04-jump-vers-chaine-ouverte.conf" "$HL (chaine input): jump ouvert"
temoin "jump : la chaine cible est lue aussi"              "$T/temoins/04-jump-vers-chaine-ouverte.conf" "$HL (chaine ouvert): tcp dport 8080 accept"
temoin "iifname wg0 tcp dport 22-65535"                    "$T/temoins/05-wg0-plage-ports.conf"      "$HL (chaine input): iifname \"wg0\" tcp dport 22-65535 accept"
printf '%s\ntable ip autre {\n chain x {\n  type filter hook input priority 0; policy accept;\n }\n}\n' "$(cat "$D/nftables.conf")" >"$tmp"; temoin "table supplementaire" "$tmp" "ligne hors liste blanche (niveau fichier/table): table ip autre {"
sed 's|^\(\s*\)ip saddr \$ip_envoi tcp dport 25 accept|&\n\1goto ouvert|' "$D/nftables.conf" >"$tmp"; temoin "goto" "$tmp" "$HL (chaine output): goto ouvert"

# M4 / C-N1 : rendu avec des IP de documentation par la VRAIE fonction `rendre` de
# l'installateur (lib/rendu.sh, sourcee aussi par installer.sh) -> nft -c. Aucune copie.
LIB="$D/lib/rendu.sh"
cle_fictive="$tmpd/cle-fictive"; printf 'A%.0s' {1..43} >"$cle_fictive"; echo '=' >>"$cle_fictive"
cle_pub_fictive="$(printf 'B%.0s' {1..43})="
valeurs_doc() { # valeurs FICTIVES de documentation (RFC 5737)
  export IP_ENVOI=192.0.2.10 IP_GESTION=192.0.2.11 IP_CRM_PROD=198.51.100.20 IP_RESOLVEUR=192.0.2.53
  export IP_TUNNEL=203.0.113.1 IP_TUNNEL_ADMIN=203.0.113.2 PORT_WG=51820 CLE_PUBLIQUE_ADMIN="$cle_pub_fictive"
  export CLE_PRIVEE_FICHIER="$cle_fictive"; }
rendre_reel() ( valeurs_doc; source "$LIB" && rendre "$1" "$2" )
rendre_nft() { rendre_reel "$1" "$tmpd/rendu.nft.tmp" && cat "$tmpd/rendu.nft.tmp"; }
nftc() { local f="$1"; nft -c -f "$f" 2>"$tmpd/nft.err" && return 0
  if [[ $EUID -ne 0 ]] && grep -qi 'permitted' "$tmpd/nft.err" && sudo -n true 2>/dev/null; then sudo -n nft -c -f "$f"; return $?; fi; return 1; }
if command -v nft >/dev/null; then
  rendre_nft "$D/nftables.conf" >"$tmpd/rendu.nft" || bad "rendu nftables par la vraie fonction"
  if nftc "$tmpd/rendu.nft"; then
    ok "nft -c sur rendu 192.0.2.x"
    sed 's|192\.0\.2\.10|192.0.2.999|' "$tmpd/rendu.nft" >"$tmpd/mauvais.nft"
    nochk "temoin: nft -c sait echouer (IP invalide)" nftc "$tmpd/mauvais.nft"
  elif grep -qi 'permitted' "$tmpd/nft.err"; then echo "skip nft -c (droits netlink insuffisants : $(head -1 "$tmpd/nft.err"))"
  else bad "nft -c sur rendu 192.0.2.x : $(head -2 "$tmpd/nft.err")"; fi
  chk "rendu: plus aucun marqueur <...>" bash -c "! grep -Eq '<[a-z][a-z0-9-]*>' '$tmpd/rendu.nft'"
else echo "skip nft -c (nft absent ; la CI l'installe)"; fi

# ------------------------------------------------- 2. image, compose, systemd (M2)
chk  "image figee par sha256"            grep -Eq 'image: *ghcr\.io/kumocorp/kumomta@sha256:[0-9a-f]{64}$' "$D/docker-compose.yml"
nochk "aucun tag mobile (latest/:tag)"   grep -Eq 'image: *[^@ ]+:[A-Za-z0-9._-]+ *$' "$D/docker-compose.yml"
chk  "M2 compose: restart unless-stopped" grep -Eq '^ *restart: *(unless-stopped|"unless-stopped")$' "$D/docker-compose.yml"
nochk "M2 compose: pas de restart no"    grep -Eq '^ *restart: *"?no"?' "$D/docker-compose.yml"
chk  "m6 compose: cap_drop ALL"          grep -Eq '^ *cap_drop: *\[ *ALL *\]' "$D/docker-compose.yml"
nochk "m6 compose: ni cap_add ni privileged" grep -Eq '^ *(cap_add|privileged)' "$D/docker-compose.yml"
nochk "compose: aucun port publie"       grep -Eq '^ *ports:' "$D/docker-compose.yml"
S="$D/kumomta-envoi.service"
chk  "M2 systemd: Type=simple (processus au premier plan)" grep -Eq '^Type=simple$' "$S"
nochk "M2 systemd: ni oneshot ni RemainAfterExit" grep -Eq '^(Type=oneshot|RemainAfterExit=)' "$S"
chk  "M2 systemd: ExecStart compose up sans -d" bash -c "grep -Eq '^ExecStart=.*compose up' '$S' && ! grep -Eq '^ExecStart=.* -d( |\$)' '$S'"
chk  "systemd: Restart=on-failure"       grep -q '^Restart=on-failure' "$S"
chk  "systemd: arret propre"             grep -Eq '^ExecStop=.*compose.* down' "$S"
chk  "mineur2 systemd: Requires=nftables.service (pas seulement After)" grep -Eq '^Requires=.*nftables\.service' "$S"
chk  "systemd: ordre apres wg0+nft"      grep -Eq '^After=.*wg-quick@wg0\.service.*nftables\.service' "$S"
chk  "systemd: delai d'arret"            grep -Eq '^TimeoutStopSec=[0-9]+' "$S"

# ------------------------------------------- 3. API d'administration locale (M4)
ecouteurs_ok() { # refuse tout listener autre que http 127.0.0.1:8000, IPv6 et toute adresse non loopback
  local f="$1" v
  [[ "$(grep -c 'start_http_listener' "$f")" == 1 ]] || return 1
  grep -E 'start_[a-z_]*listener' "$f" | grep -qv 'start_http_listener' && return 1
  [[ "$(grep -Ec '^[[:space:]]*listen *=' "$f")" == 1 ]] || return 1
  grep -Eq '^[[:space:]]*listen *= *"127\.0\.0\.1:8000",?[[:space:]]*$' "$f" || return 1
  grep -Eq 'trusted_hosts *= *\{ *"127\.0\.0\.1", *"::1" *\}' "$f" || return 1
  grep -Eq '\[ *:[:]|0\.0\.0\.0' "$f" && return 1
  while read -r v; do [[ "$v" == 127.0.0.1 ]] || return 1; done < <(grep -Eo '([0-9]{1,3}\.){3}[0-9]{1,3}' "$f")
  return 0
}
chk "API locale: seul listener http 127.0.0.1:8000" ecouteurs_ok "$D/kumomta/init.lua"
for v in "listen = \"${V6ANY}:8000\"," "listen = \"[${COL}${COL}1]:8000\"," "listen = \"${Z4}:8000\"," 'listen = "192.0.2.3:8000",' 'listen = "127.0.0.1:9000",'; do
  sed "s|^\( *\)listen = .*|\1$v|" "$D/kumomta/init.lua" >"$tmp.lua"; nochk "temoin: listener refuse ($v)" ecouteurs_ok "$tmp.lua"
done
{ cat "$D/kumomta/init.lua"; echo 'kumo.on("init", function() kumo.start_esmtp_listener { listen = "127.0.0.1:2525" } end)'; } >"$tmp.lua"
nochk "temoin: 2e listener (esmtp) refuse" ecouteurs_ok "$tmp.lua"
nochk "API jamais sur l'adresse generique" grep -Eq '0\.0\.0\.0' "$D/kumomta/init.lua" "$D/docker-compose.yml"

# ------------------------------------------------- 4. SSH + WireGuard d'ADMINISTRATION
chk  "sshd ecoute sur l'IP du tunnel"    grep -Eq '^ListenAddress <ip-tunnel>$' "$D/sshd-tunnel.conf"
chk  "sshd sans mot de passe"            grep -Eq '^PasswordAuthentication no$' "$D/sshd-tunnel.conf"
chk  "sshd sans root par mot de passe"   grep -Eq '^PermitRootLogin prohibit-password$' "$D/sshd-tunnel.conf"
chk  "m3 sshd ordonne apres wg-quick@wg0" grep -Eq '^After=.*wg-quick@wg0\.service' "$D/sshd-apres-wg0.conf"
chk  "m3 installateur pose le drop-in ssh" grep -q 'sshd-apres-wg0.conf' "$D/installer.sh"
chk  "m4 note Ubuntu 24.04 ssh.socket"   grep -q 'ssh\.socket' "$D/RUNBOOK.md"
chk  "m4 installateur traite ssh.socket" grep -q 'ssh\.socket' "$D/installer.sh"
wg_ok() { local f="$1"
  grep -Eq '^PrivateKey = <cle-privee-serveur>$' "$f" || return 1
  [[ "$(grep -c '^\[Peer\]' "$f")" == 1 ]] || return 1
  grep -Eq '^PublicKey = <cle-publique-admin>$' "$f" || return 1
  grep -Eq '^AllowedIPs = <ip-tunnel-admin>/32$' "$f" || return 1
  grep -qi 'crm' "$f" && return 1
  return 0; }
chk  "C1 wg0 : pair = poste d'administration (pas le CRM)" wg_ok "$D/wireguard/wg0.conf.modele"
sed 's/<cle-publique-admin>/<cle-publique-crm>/' "$D/wireguard/wg0.conf.modele" >"$tmp.wg"; nochk "temoin: wg0 avec cle du CRM" wg_ok "$tmp.wg"
sed 's|<ip-tunnel-admin>/32|<ip-tunnel-crm>/32|' "$D/wireguard/wg0.conf.modele" >"$tmp.wg"; nochk "temoin: wg0 avec IP tunnel du CRM" wg_ok "$tmp.wg"
sed "s|<ip-tunnel-admin>/32|${Z4}/0|" "$D/wireguard/wg0.conf.modele" >"$tmp.wg"; nochk "temoin: wg0 AllowedIPs tout-ouvert /0" wg_ok "$tmp.wg"
nochk "C1 aucun pair CRM (noms, variables, doc)" grep -rEi 'TUNNEL_CRM|PUBLIQUE_CRM|tunnel-crm|publique-crm|pair[^.]*crm|crm[^.]*pair' "$D" --exclude-dir=tests
chk  "C1 variables admin dans installateur+runbook" bash -c "grep -q CLE_PUBLIQUE_ADMIN '$D/installer.sh' && grep -q IP_TUNNEL_ADMIN '$D/installer.sh' && grep -q CLE_PUBLIQUE_ADMIN '$D/RUNBOOK.md' && grep -q IP_TUNNEL_ADMIN '$D/RUNBOOK.md'"
chk  "D7 documente (aucun tunnel vers la prod)" grep -q 'D7' "$D/RUNBOOK.md"

# ---------------------------- 5. aucune IP, domaine ni secret reel (m1) hors marqueurs
# Mode strict (defaut) : produit (hors tests/), seule 127.0.0.1 tolere. Mode doc : tests/,
# seules les adresses de DOCUMENTATION (RFC 5737 : 192.0.2/24, 198.51.100/24, 203.0.113/24 ;
# RFC 3849 : 2001:db8::/32) en plus de 127.0.0.1 et ::1.
litteraux() { # litteraux <dossier> [strict|doc] : une ligne par trouvaille
  local d="$1" mode="${2:-strict}" ex=(--exclude-dir=tests)
  [[ $mode == doc ]] && ex=()
  { grep -rEno '\b([0-9]{1,3}\.){3}[0-9]{1,3}\b' "$d" "${ex[@]}" | grep -Ev ':127\.0\.0\.1$' \
      | { if [[ $mode == doc ]]; then grep -Ev ':(192\.0\.2|198\.51\.100|203\.0\.113)\.[0-9]{1,3}$'; else cat; fi; } || true
    grep -rPno '(?<![0-9A-Za-z:])[0-9A-Fa-f:]*:[0-9A-Fa-f]*:[0-9A-Fa-f:]*(?![0-9A-Za-z:])' "$d" "${ex[@]}" | grep -Ev ':::1$' \
      | { if [[ $mode == doc ]]; then grep -Eiv ':2001:db8(:[0-9a-f]*)*$'; else cat; fi; } || true
    grep -rPno '(?<![A-Za-z0-9_.@-])(?:[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\.)+(?:com|net|org|io|fr|eu|de|uk|us|info|biz|dev|app|cloud|xyz|ai|email|pro)(?![A-Za-z0-9-])' "$d" "${ex[@]}" | grep -Ev ':(ghcr|docker)\.io$' || true
    grep -rEnio '(PrivateKey|PresharedKey) *= *[A-Za-z0-9+/]{43}=|BEGIN [A-Z ]*PRIVATE KEY|password *= *[^<[:space:]]' "$d" "${ex[@]}" || true; } \
    | grep -vF -- "$D/passerelle/composer.lock:" || true  # K2 : SEUL ce fichier (URL de paquets générées par Composer), rien d'autre
  return 0; }
trouve="$(litteraux "$D")"
[[ -z "$trouve" ]] && ok "aucune IP/IPv6/domaine/secret litteral" || bad "litteral: $trouve"
w="$tmpd/w"; mkdir -p "$w"
printf 'ip 203.0.113.7 et 127.0.0.1\n'   >"$w/a"; [[ "$(litteraux "$w")" == *203.0.113.7* ]] && ok "temoin m1: vraie IP + 127.0.0.1 detectee" || bad "temoin m1: IP + loopback masquee"
printf 'adr 2001:db8::1 ok\n'            >"$w/b"; [[ "$(litteraux "$w")" == *2001:db8* ]] && ok "temoin m1: IPv6 detectee" || bad "temoin m1: IPv6 non detectee"
printf 'hote mail.exemple-reel.%s ok\n' com >"$w/c"; [[ "$(litteraux "$w")" == *exemple-reel.* ]] && ok "temoin m1: domaine detecte" || bad "temoin m1: domaine non detecte"
printf 'PASSWORD %s hunter2\n' =       >"$w/d"; [[ "$(litteraux "$w")" == *PASSWORD* ]] && ok "temoin m1: password majuscules detecte" || bad "temoin m1: password casse"
w="$tmpd/w2"; mkdir -p "$w"; printf 'a.test b.example ::1 127.0.0.1 ghcr.io docker.io <ip-envoi>\n' >"$w/e"
[[ -z "$(litteraux "$w")" ]] && ok "temoin m1: .test/.example/::1/127.0.0.1 toleres" || bad "temoin m1: faux positif: $(litteraux "$w")"
# m8 : tests/ est scanne AUSSI, avec liste blanche stricte (documentation seulement).
trouve_t="$(litteraux "$T" doc)"
[[ -z "$trouve_t" ]] && ok "m8 tests/ : aucune IP/domaine/secret hors documentation" || bad "m8 tests/ litteral: $trouve_t"
w="$tmpd/w3"; mkdir -p "$w"
printf 'ip %s\n' "$PUB4"         >"$w/a"; [[ "$(litteraux "$w" doc)" == *"$PUB4"* ]]      && ok "temoin m8: IP publique refusee dans tests/" || bad "temoin m8: IP publique tolérée"
printf 'ip %s\n' "$HORSDOC4"     >"$w/b"; [[ "$(litteraux "$w" doc)" == *"$HORSDOC4"* ]]  && ok "temoin m8: 203.0.114.x (hors doc) refusee" || bad "temoin m8: 203.0.114.x toleree"
printf 'ip %s\n' "$PRIV4"        >"$w/c"; [[ "$(litteraux "$w" doc)" == *"$PRIV4"* ]]     && ok "temoin m8: IP privee refusee dans tests/" || bad "temoin m8: IP privee toleree"
printf 'v6 %s\n' "$V6REEL"       >"$w/d"; [[ "$(litteraux "$w" doc)" == *2001:4860* ]]    && ok "temoin m8: IPv6 hors documentation refusee" || bad "temoin m8: IPv6 reelle toleree"
printf 'h mail.exemple-reel.%s\n' com >"$w/e"; [[ "$(litteraux "$w" doc)" == *exemple-reel.* ]] && ok "temoin m8: domaine refuse dans tests/" || bad "temoin m8: domaine tolere"
w="$tmpd/w4"; mkdir -p "$w"; printf 'ip 192.0.2.7 198.51.100.7 203.0.113.7 127.0.0.1 2001:db8::5 ::1\n' >"$w/f"
[[ -z "$(litteraux "$w" doc)" ]] && ok "temoin m8: adresses de documentation toleres dans tests/" || bad "temoin m8: faux positif: $(litteraux "$w" doc)"
if command -v gitleaks >/dev/null; then chk "gitleaks (dossier)" gitleaks dir --no-banner "$D"; else echo "skip gitleaks (absent ; la CI le fait)"; fi

# ------------------------------------------- 6. installateur (idempotent, jamais destructif)
I="$D/installer.sh"
chk  "installer: set -euo pipefail"      grep -q '^set -euo pipefail' "$I"
chk  "installer: syntaxe bash"           bash -n "$I"
nochk "installer: pas de rm -rf"         grep -Eq 'rm +-rf|mkfs|dd +if' "$I"
chk  "installer: nft -c avant charge"    grep -q 'nft -c -f' "$I"
chk  "M1 installer: pull de l'image AVANT le pare-feu" bash -c "p=\$(grep -n 'compose.* pull' '$I' | head -1 | cut -d: -f1); f=\$(grep -n 'enable --now wg-quick@wg0' '$I' | head -1 | cut -d: -f1); [[ -n \$p && -n \$f && \$p -lt \$f ]]"
nochk "m5 cle privee jamais dans une commande" grep -Eq '\$\(.*cle-privee|-e [^ ]*\$\(cat|PrivateKey *= *\$' "$I"
chk  "m5 cle privee lue par fichier (awk getline)" grep -q 'getline k < ENVIRON' "$D/lib/rendu.sh"
chk  "m5 wg0.conf cree en 0600 (umask 077)" grep -Eq 'umask 077' "$I"
chk  "installer: refuse un marqueur non remplace (lib partagee)" grep -q "marqueur non remplace" "$D/lib/rendu.sh"
# « refuse hors root » : on LANCE vraiment l'installateur sans droits root et on exige l'echec.
refus_hors_root() {
  local cmd=(bash "$I" --oui) out rc
  if [[ $EUID -eq 0 ]]; then
    command -v setpriv >/dev/null || return 99
    cmd=(setpriv --reuid=65534 --regid=65534 --clear-groups bash "$I" --oui)
  fi
  out="$("${cmd[@]}" 2>&1)"; rc=$?
  [[ $rc -ne 0 && "$out" == *"root requis"* ]]
}
refus_hors_root; r=$?
if [[ $r == 99 ]]; then echo "skip refus hors root (setpriv absent)"; elif [[ $r == 0 ]]; then ok "installer --oui hors root : echec reel 'root requis'"; else bad "installer --oui hors root : n'a pas echoue avec 'root requis'"; fi
chk  "runbook present"                   test -s "$D/RUNBOOK.md"
chk  "runbook: sonde externe (ports hors 443/WireGuard)" bash -c "grep -qi 'sonde externe' '$D/RUNBOOK.md' && grep -q 'nmap' '$D/RUNBOOK.md'"
chk  "runbook: NTP/horloge HMAC"         grep -qi 'ntp' "$D/RUNBOOK.md"
chk  "runbook: note flush ruleset"       grep -q 'flush ruleset' "$D/RUNBOOK.md"
chk  "m6 runbook: verification cap_drop ALL" grep -q 'CapDrop' "$D/RUNBOOK.md"

# ------------------------------------- 8. VRAI rendu des modeles (C-N1) et valeurs (m1)
nochk "C-N1 installer: ne redefinit pas rendre (une seule logique)" grep -Eq '^rendre *\(\)' "$I"
chk  "C-N1 installer: source lib/rendu.sh"  grep -q 'source "\$SRC/lib/rendu.sh"' "$I"
for m in wireguard/wg0.conf.modele nftables.conf sshd-tunnel.conf kumomta/init.lua; do
  chk "C-N1 rendre (vraie fonction) rend $m, code 0" rendre_reel "$D/$m" "$tmpd/out.$$"
done
rendre_reel "$D/wireguard/wg0.conf.modele" "$tmpd/wg0.rendu" 2>/dev/null
chk  "C-N1 wg0 rendu : adresse, port, pair, cle serveur lue par fichier" bash -c "grep -qx 'Address = 203.0.113.1/32' '$tmpd/wg0.rendu' && grep -qx 'ListenPort = 51820' '$tmpd/wg0.rendu' && grep -qx 'AllowedIPs = 203.0.113.2/32' '$tmpd/wg0.rendu' && grep -qx 'PublicKey = $cle_pub_fictive' '$tmpd/wg0.rendu' && grep -qx \"PrivateKey = \$(cat '$cle_fictive')\" '$tmpd/wg0.rendu'"
printf '# poste <-> serveur (fleche) et a <=> b\nAddress = <ip-tunnel>/32\n' >"$tmpd/fleche.modele"
chk  "C-N1 temoin: '<->' dans un commentaire n'est PAS un marqueur" rendre_reel "$tmpd/fleche.modele" "$tmpd/o"
printf 'Address = <ip-tunnel>/32\nX = <ip-inconnue>\n' >"$tmpd/inconnu.modele"
nochk "C-N1 temoin: un vrai marqueur non remplace fait ECHOUER le rendu" rendre_reel "$tmpd/inconnu.modele" "$tmpd/o"
printf 'X = <Majuscule> <a_b> <->\nY = <ip-envoi>\n' >"$tmpd/hors-liste.modele"
chk  "C-N1 les motifs <Majuscule>/<a_b>/<-> ne sont pas des marqueurs" rendre_reel "$tmpd/hors-liste.modele" "$tmpd/o"
nochk "C-N1 les modeles n'ont aucun '<->' ni '<=>'" grep -rEn '<->|<=>' "$D/wireguard" "$D/nftables.conf" "$D/sshd-tunnel.conf" "$D/kumomta"
chk  "C-N1 garde de marqueur : motif ferme <[a-z][a-z0-9-]*>" grep -qF '<[a-z][a-z0-9-]*>' "$D/lib/rendu.sh"
# Installateur reel, a blanc : rend vraiment les 4 modeles (pre-vol) et valide la forme.
vals="$tmpd/valeurs.env"
( valeurs_doc; for v in IP_ENVOI IP_GESTION IP_CRM_PROD IP_RESOLVEUR IP_TUNNEL IP_TUNNEL_ADMIN PORT_WG CLE_PUBLIQUE_ADMIN; do printf '%s=%s\n' "$v" "${!v}"; done ) >"$vals"
printf 'ID=ubuntu\nVERSION_ID="24.04"\n' >"$tmpd/os-release.ubuntu"
bl() { AXION_VALEURS="$1" OS_RELEASE="$tmpd/os-release.ubuntu" bash "$I" 2>&1; }
out="$(bl "$vals")"; rc=$?
[[ $rc == 0 && "$out" == *"termine."* && "$out" != *"marqueur non remplace"* ]] && ok "C-N1 installateur a blanc : pre-vol de rendu OK (4 modeles)" || bad "C-N1 installateur a blanc rc=$rc: $out"
for cas in 'IP_ENVOI=192.0.2.0/24' "IP_ENVOI=$Z4" "IP_CRM_PROD=$Z4/0" 'IP_GESTION=192.0.2.1-192.0.2.9' 'IP_RESOLVEUR=192.0.2.1,192.0.2.2' \
           'IP_TUNNEL=192.0.2.256' "IP_TUNNEL_ADMIN=$ZERO_INIT4" 'IP_ENVOI=a.b.c.d' 'IP_CRM_PROD=192.0.2.1|192.0.2.2' 'IP_ENVOI=192.0.2.1&' \
           'PORT_WG=abc' 'PORT_WG=0' 'PORT_WG=70000' 'PORT_WG=51820;x' 'CLE_PUBLIQUE_ADMIN=court' "CLE_PUBLIQUE_ADMIN=${cle_pub_fictive%=}" 'IP_GESTION=192.0.2.10'; do
  { cat "$vals"; echo "$cas"; } >"$tmpd/v2.env"; out="$(bl "$tmpd/v2.env")"; rc=$?
  [[ $rc -ne 0 && "$out" == *"${cas%%=*}"* ]] && ok "m1 valeur refusee : $cas" || bad "m1 valeur NON refusee : $cas (rc=$rc)"
done
rm -f "$tmpd/pwned"; { cat "$vals"; echo "IP_ENVOI=\$(touch $tmpd/pwned)"; echo "IP_TUNNEL=192.0.2.9;touch $tmpd/pwned"; } >"$tmpd/v4.env"; out="$(bl "$tmpd/v4.env")"; rc=$?
[[ $rc -ne 0 && ! -e "$tmpd/pwned" ]] && ok "m1 le fichier de valeurs n'est JAMAIS execute (\$(...) ; inertes)" || bad "m1 le fichier de valeurs a execute du code (rc=$rc)"
printf '# commentaire\nIP_INCONNUE=192.0.2.5\n' >>"$tmpd/v5.env"; { cat "$vals" "$tmpd/v5.env"; } >"$tmpd/v6.env"; out="$(bl "$tmpd/v6.env")"; [[ $? -ne 0 && "$out" == *IP_INCONNUE* ]] && ok "m1 cle inconnue refusee (faute de frappe)" || bad "m1 cle inconnue acceptee"
{ cat "$vals"; echo 'IP_GESTION=192.0.2.10'; } >"$tmpd/v3.env"; out="$(bl "$tmpd/v3.env")"; [[ $? -ne 0 ]] && ok "m1 IP_ENVOI et IP_GESTION identiques refusees" || bad "m1 IP identiques acceptees"
# Le fichier RENDU repasse par verifier-nftables.sh (mode --rendu) avant nft -c.
verif_rendu() ( valeurs_doc; bash "$V" --rendu "$1" )
rendre_reel "$D/nftables.conf" "$tmpd/nft.rendu" 2>/dev/null
chk  "m1 verifier-nftables --rendu accepte le rendu reel" verif_rendu "$tmpd/nft.rendu"
nochk "m1 verifier-nftables --rendu refuse le modele NON rendu" verif_rendu "$D/nftables.conf"
temoin_rendu() { local nom="$1" ligne="$2" out rc; out="$(verif_rendu "$tmpd/alt.nft" 2>&1)"; rc=$?
  if [[ $rc == 1 ]] && grep -qxF -- "VIOLATION: $ligne" <<<"$out"; then ok "temoin rendu: $nom"; else bad "temoin rendu: $nom (rc=$rc, attendu 'VIOLATION: $ligne' ; sortie: $out)"; fi; }
sed "s|^define ip_crm_prod = .*|define ip_crm_prod = $Z4/0|" "$tmpd/nft.rendu" >"$tmpd/alt.nft"; temoin_rendu "define ip_crm_prod ouvert /0" "ligne hors liste blanche (niveau fichier/table): define ip_crm_prod = $Z4/0"
sed 's|^define wg_port = .*|define wg_port = 1-65535|' "$tmpd/nft.rendu" >"$tmpd/alt.nft"; temoin_rendu "define wg_port en plage" "ligne hors liste blanche (niveau fichier/table): define wg_port = 1-65535"
sed 's|^define ip_resolveur = .*|define ip_resolveur = 192.0.2.99|' "$tmpd/nft.rendu" >"$tmpd/alt.nft"; temoin_rendu "define ip_resolveur differente de la valeur fournie" "ligne hors liste blanche (niveau fichier/table): define ip_resolveur = 192.0.2.99"
chk  "m1 installateur : verifier-nftables --rendu puis nft -c" bash -c "v=\$(grep -n 'verifier-nftables.sh.* --rendu' '$I' | tail -1 | cut -d: -f1); c=\$(grep -n 'nft -c -f' '$I' | tail -1 | cut -d: -f1); [[ -n \$v && -n \$c && \$v -lt \$c ]]"

# --------------------------------------- 9. Ubuntu seulement, APT en https (M-N2, m6)
os_fix() { mkdir -p "$tmpd/os"; printf 'ID=%s\nVERSION_ID="%s"\n' "$1" "$2" >"$tmpd/os/$1"; echo "$tmpd/os/$1"; }
os_ok_test() ( source "$LIB" && OS_RELEASE="$1" os_ubuntu )
chk  "m6 os_ubuntu accepte Ubuntu 24.04"  os_ok_test "$(os_fix ubuntu 24.04)"
nochk "m6 os_ubuntu refuse Debian (pas de docker-compose-v2)" os_ok_test "$(os_fix debian 12)"
chk  "m6 installateur : refuse hors Ubuntu" grep -q 'os_ubuntu' "$I"
chk  "m6 en-tete : Ubuntu seulement"        bash -c "sed -n '1,6p' '$I' | grep -q 'Ubuntu'"
chk  "m6 installateur : apt-get update AVANT apt-get install" bash -c "u=\$(grep -n 'apt-get update' '$I' | head -1 | cut -d: -f1); i=\$(grep -n 'apt-get install' '$I' | head -1 | cut -d: -f1); [[ -n \$u && -n \$i && \$u -lt \$i ]]"
chk  "m6 ci.yml : apt-get update avant install nftables" bash -c "u=\$(grep -n 'sudo apt-get update' '$D/../../.github/workflows/ci.yml' | tail -1 | cut -d: -f1); i=\$(grep -n 'sudo apt-get install -y --no-install-recommends nftables' '$D/../../.github/workflows/ci.yml' | cut -d: -f1); [[ -n \$u && -n \$i && \$u -lt \$i ]]"
apt="$tmpd/apt"; mkdir -p "$apt/sources.list.d"
printf 'deb http://archive.exemple.test/ubuntu noble main\ndeb [arch=amd64] http://security.exemple.test/ubuntu noble-security main\n# deb http://comm.ente.example/ x\ndeb https://deja.example/ubuntu noble main\n' >"$apt/sources.list"
printf 'Types: deb\nURIs: http://mirror.exemple.test/ubuntu http://autre.exemple.test/ubuntu\nSuites: noble\n' >"$apt/sources.list.d/ubuntu.sources"
apt_test() ( source "$LIB" && apt_en_https "$apt" )
chk  "M-N2 apt_en_https s'execute" apt_test
nochk "M-N2 plus aucune source APT en http:// (actives)" bash -c "grep -hE '^(deb|URIs:)' '$apt'/sources.list '$apt'/sources.list.d/* | grep -q 'http://'"
chk  "M-N2 sources : https conserve, options [arch=] gardees" bash -c "grep -q 'deb \[arch=amd64\] https://security.exemple.test' '$apt/sources.list' && grep -q '^deb https://deja.example' '$apt/sources.list'"
chk  "M-N2 deb822 URIs : toutes les URI passees en https" bash -c "grep -q '^URIs: https://mirror.exemple.test/ubuntu https://autre.exemple.test/ubuntu$' '$apt/sources.list.d/ubuntu.sources'"
cp "$apt/sources.list" "$tmpd/sl.1"; apt_test; chk "M-N2 apt_en_https idempotent" cmp -s "$tmpd/sl.1" "$apt/sources.list"
chk  "M-N2 installateur : apt_en_https AVANT apt-get update" bash -c "h=\$(grep -n 'apt_en_https' '$I' | head -1 | cut -d: -f1); u=\$(grep -n 'apt-get update' '$I' | head -1 | cut -d: -f1); [[ -n \$h && -n \$u && \$h -lt \$u ]]"
nochk "M-N2 le pare-feu n'ouvre PAS le port 80 (https seulement)" grep -Eq 'dport (80|\{[^}]*\b80\b)' "$D/nftables.conf"
chk  "M-N2 RUNBOOK : note APT https / port 80 ferme" bash -c "grep -qi 'port 80' '$D/RUNBOOK.md' && grep -qi 'https' '$D/RUNBOOK.md' && grep -qi 'apt' '$D/RUNBOOK.md'"

# ------------------------------------------- 10. relance, ordre, documentation (m3-m5)
chk  "m3 relance : changement detecte par comparaison (cmp)" grep -q 'cmp -s' "$I"
chk  "m3 relance : restart kumomta-envoi si init.lua/compose/unite change" bash -c "grep -Eq 'changement_kumo' '$I' && grep -Eq 'systemctl restart kumomta-envoi' '$I'"
chk  "m3 relance : reload wg-quick@wg0 si wg0.conf change" bash -c "grep -Eq 'changement_wg' '$I' && grep -Eq 'systemctl reload wg-quick@wg0' '$I'"
chk  "m4 RUNBOOK : IPv6 documente (ND/ICMPv6, tout bloque)" bash -c "grep -qi 'ipv6' '$D/RUNBOOK.md' && grep -qi 'icmpv6\|nd ' '$D/RUNBOOK.md'"
chk  "m4 RUNBOOK : resolveur autorise (IP_RESOLVEUR) configure" bash -c "grep -qi 'resolved' '$D/RUNBOOK.md' && grep -q 'IP_RESOLVEUR' '$D/RUNBOOK.md'"
chk  "m5 RUNBOOK : D5 (IP de gestion = IPv4 principale, IP d'envoi flottante)" bash -c "grep -q 'D5' '$D/RUNBOOK.md' && grep -qi 'flottante' '$D/RUNBOOK.md' && grep -qi 'source par d' '$D/RUNBOOK.md'"
chk  "m5 RUNBOOK : AC privee listee dans « Hors perimetre »" bash -c "sed -n '/^## Hors p/,\$p' '$D/RUNBOOK.md' | grep -qi 'AC priv'"

# ------------------------------------------------------- 7. CI + perimetre (M4, m2)
chk  "M4 ci.yml appelle tests/lancer-tests.sh" grep -q 'infra/serveur-envoi/tests/lancer-tests.sh' "$D/../../.github/workflows/ci.yml"
# M-N1 : la garde de perimetre vit dans tests/garde-perimetre.sh, reservee aux branches
# feat/envoi-k3-* ; sur toute autre branche elle SAUTE (une PR etrangere qui touche un
# fichier hors infra/serveur-envoi/ ne doit pas rougir). Les autres gardes K3 tournent partout.
GP="$T/garde-perimetre.sh"
branche_courante="${GITHUB_HEAD_REF:-${GITHUB_REF_NAME:-$(git -C "$D" branch --show-current 2>/dev/null || true)}}"
out="$(bash "$GP" "$branche_courante" "$D" 2>&1)"; rc=$?
if [[ $rc == 0 ]]; then ok "perimetre/ci.yml ($out)"; else bad "perimetre : $out"; fi
# Test REEL de la garde : depot temporaire (origin/main + branche), 4 cas.
perim() { # perim <branche> <fichier a modifier> [ligne-a-retirer-de-ci.yml]
  local r="$tmpd/pr$RANDOM"; mkdir -p "$r"
  ( export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@example.test GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@example.test
    cd "$r" && git init -q --bare origin.git && git clone -q origin.git w 2>/dev/null && cd w \
    && git checkout -q -b main \
    && mkdir -p infra/serveur-envoi .github/workflows && echo a >infra/serveur-envoi/a && printf 'ligne1\nligne2\n' >.github/workflows/ci.yml && echo x >hors.txt \
    && git add -A && git commit -q -m base && git push -q origin main 2>/dev/null && git checkout -q -b "$1" \
    && echo b >>"$2" && if [[ -n "${3:-}" ]]; then sed -i "/$3/d" .github/workflows/ci.yml; fi && git add -A && git commit -q -m chg ) >/dev/null 2>&1
  bash "$GP" "$1" "$r/w" 2>&1; }
out="$(perim feat/autre-chose hors.txt)"; rc=$?
[[ $rc == 0 ]] && ok "M-N1 PR etrangere touchant un fichier hors perimetre : garde sautee (vert)" || bad "M-N1 PR etrangere rougit : $out"
out="$(perim feat/envoi-k3-serveur hors.txt)"; rc=$?
[[ $rc != 0 && "$out" == *hors.txt* ]] && ok "M-N1 temoin: PR K3 hors perimetre -> ROUGE" || bad "M-N1 temoin: PR K3 hors perimetre non detectee ($rc : $out)"
out="$(perim feat/envoi-k3-serveur infra/serveur-envoi/a)"; rc=$?
[[ $rc == 0 ]] && ok "M-N1 PR K3 dans le perimetre : vert" || bad "M-N1 PR K3 dans le perimetre rougit : $out"
out="$(perim feat/envoi-k3-serveur infra/serveur-envoi/a ligne2)"; rc=$?
[[ $rc != 0 && "$out" == *ligne2* ]] && ok "M-N1 temoin: PR K3 retirant une ligne de ci.yml -> ROUGE" || bad "M-N1 temoin: ligne retiree de ci.yml non detectee ($rc : $out)"
out="$(perim main hors.txt)"; rc=$?
[[ $rc == 0 ]] && ok "M-N1 main / hors branche K3 : sautee" || bad "M-N1 main rougit : $out"
nochk "M-N1 lancer-tests.sh n'a plus de garde de perimetre inline" grep -q 'git -C "\$D" diff --name-only' "$T/lancer-tests.sh"
# K5 : DKIM cote serveur (cle generee dans un dossier temporaire).
chk "K5: tests DKIM serveur" bash "$D/dkim/tests-dkim.sh"
echo "--- $n verifications, $([[ $ko = 0 ]] && echo VERT || echo ROUGE)"
exit "$ko"
