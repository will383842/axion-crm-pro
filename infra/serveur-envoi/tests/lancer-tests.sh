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
tmpd="$(mktemp -d)"; trap 'rm -rf "${tmpd:?}"' EXIT
tmp="$tmpd/t.conf"
V="$T/verifier-nftables.sh"
# temoin <nom> <fichier> <fragment attendu dans la sortie> : doit ROUGIR POUR LA BONNE RAISON.
temoin() {
  local nom="$1" f="$2" frag="$3" out rc
  out="$(bash "$V" "$f" 2>&1)"; rc=$?
  if [[ $rc == 1 && "$out" == *"$frag"* ]]; then ok "temoin: $nom"; else bad "temoin: $nom (rc=$rc, attendu '$frag')"; fi
}

# ---------------------------------------------------------------- 1. nftables
chk "nftables conforme (liste blanche exacte)" bash "$V" "$D/nftables.conf"
# C2 : la ligne exacte prod -> passerelle 443 doit exister ; saddr/daddr manquant rougit.
chk "C2: regle prod->gestion:443 exacte" grep -qxF '    ip saddr $ip_crm_prod ip daddr $ip_gestion tcp dport 443 accept' "$D/nftables.conf"
sed 's/ip saddr \$ip_crm_prod //' "$D/nftables.conf" >"$tmp"; temoin "C2 sans ip saddr" "$tmp" "ip daddr \$ip_gestion tcp dport 443 accept"
sed 's/ip daddr \$ip_gestion tcp dport 443 accept/tcp dport 443 accept/' "$D/nftables.conf" >"$tmp"; temoin "C2 sans ip daddr" "$tmp" "ip saddr \$ip_crm_prod tcp dport 443 accept"
# C3
sed 's/^\(\s*\)ip daddr \$ip_gestion udp dport \$wg_port accept/\1udp dport $wg_port accept/' "$D/nftables.conf" >"$tmp"; temoin "C3 WireGuard sans ip daddr gestion" "$tmp" "udp dport \$wg_port accept"
grep -v 'ip daddr \$ip_envoi ct state new drop' "$D/nftables.conf" >"$tmp"; temoin "C3 sans drop entrant sur ip_envoi" "$tmp" "requise absente"
awk '/ip daddr \$ip_envoi ct state new drop/{d=$0;next} {print} /iif "lo" accept/ && !x && d{print d;x=1}' "$D/nftables.conf" >"$tmp"; temoin "C3 drop ip_envoi pas en tete" "$tmp" "1re regle d'entree"
sed 's/^\(\s*\)ip saddr \$ip_gestion ip daddr \$ip_resolveur udp dport 53/\1ip daddr $ip_resolveur udp dport 53/' "$D/nftables.conf" >"$tmp"; temoin "C3 DNS sans ip saddr gestion" "$tmp" "ip daddr \$ip_resolveur udp dport 53"
sed 's/ip saddr \$ip_gestion udp sport \$wg_port/udp sport $wg_port/' "$D/nftables.conf" >"$tmp"; temoin "C3 sport wg sans ip saddr gestion" "$tmp" "udp sport \$wg_port accept"
# M1 : 443 et NTP sortants depuis la gestion.
chk "M1: sortie 443 depuis gestion" grep -qxF '    ip saddr $ip_gestion tcp dport 443 accept' "$D/nftables.conf"
chk "M1: sortie NTP 123 depuis gestion" grep -qxF '    ip saddr $ip_gestion udp dport 123 accept' "$D/nftables.conf"
# Temoins historiques.
sed 's/hook input priority 0; policy drop/hook input priority 0; policy accept/' "$D/nftables.conf" >"$tmp"; temoin "policy accept" "$tmp" "VIOLATION"
sed 's/ip saddr \$ip_envoi tcp dport 25/tcp dport 25/' "$D/nftables.conf" >"$tmp"; temoin "sortie 25 sans source" "$tmp" "tcp dport 25 accept"
# M3 : les 5 contournements (fichiers temoins) doivent rougir.
temoin "ct state established,related,new accept en entree" "$T/temoins/01-entree-new-accept.conf"   "established,related,new accept"
temoin "tcp dport 443 accept en sortie non prevu"          "$T/temoins/02-sortie-443-ouvert.conf"    "tcp dport 443 accept"
temoin "ip saddr \$ip_envoi accept"                         "$T/temoins/03-saddr-envoi-accept.conf"   "ip saddr \$ip_envoi accept"
temoin "jump vers chaine ouverte (8080)"                   "$T/temoins/04-jump-vers-chaine-ouverte.conf" "jump ouvert"
temoin "jump : la chaine cible est lue aussi"              "$T/temoins/04-jump-vers-chaine-ouverte.conf" "tcp dport 8080 accept"
temoin "iifname wg0 tcp dport 22-65535"                    "$T/temoins/05-wg0-plage-ports.conf"      "22-65535"
printf '%s\ntable ip autre {\n chain x {\n  type filter hook input priority 0; policy accept;\n }\n}\n' "$(cat "$D/nftables.conf")" >"$tmp"; temoin "table supplementaire" "$tmp" "VIOLATION"
sed 's|^\(\s*\)ip saddr \$ip_envoi tcp dport 25 accept|&\n\1goto ouvert|' "$D/nftables.conf" >"$tmp"; temoin "goto" "$tmp" "goto ouvert"

# M4 : rendu avec des IP de documentation (192.0.2.0/24) -> nft -c.
rendre_nft() { sed -e 's|<ip-envoi>|192.0.2.10|g' -e 's|<ip-gestion>|192.0.2.11|g' -e 's|<ip-crm-prod>|192.0.2.20|g' \
  -e 's|<ip-resolveur>|192.0.2.53|g' -e 's|<port-wg>|51820|g' "$1"; }
nftc() { local f="$1"; nft -c -f "$f" 2>"$tmpd/nft.err" && return 0
  if [[ $EUID -ne 0 ]] && grep -qi 'permitted' "$tmpd/nft.err" && sudo -n true 2>/dev/null; then sudo -n nft -c -f "$f"; return $?; fi; return 1; }
if command -v nft >/dev/null; then
  rendre_nft "$D/nftables.conf" >"$tmpd/rendu.nft"
  if nftc "$tmpd/rendu.nft"; then
    ok "nft -c sur rendu 192.0.2.x"
    sed 's|192\.0\.2\.10|192.0.2.999|' "$tmpd/rendu.nft" >"$tmpd/mauvais.nft"
    nochk "temoin: nft -c sait echouer (IP invalide)" nftc "$tmpd/mauvais.nft"
  elif grep -qi 'permitted' "$tmpd/nft.err"; then echo "skip nft -c (droits netlink insuffisants : $(head -1 "$tmpd/nft.err"))"
  else bad "nft -c sur rendu 192.0.2.x : $(head -2 "$tmpd/nft.err")"; fi
  chk "rendu: plus aucun marqueur <...>" bash -c "! grep -q '<[a-z-]*>' '$tmpd/rendu.nft'"
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
chk  "systemd: ordre apres wg0+nft"      grep -Eq '^After=.*wg-quick@wg0\.service.*nftables\.service' "$S"
chk  "systemd: delai d'arret"            grep -Eq '^TimeoutStopSec=[0-9]+' "$S"

# ------------------------------------------- 3. API d'administration locale (M4)
ecouteurs_ok() { # refuse tout listener autre que http 127.0.0.1:8000, [::] et toute adresse non loopback
  local f="$1" v
  [[ "$(grep -c 'start_http_listener' "$f")" == 1 ]] || return 1
  grep -E 'start_[a-z_]*listener' "$f" | grep -qv 'start_http_listener' && return 1
  [[ "$(grep -Ec '^[[:space:]]*listen *=' "$f")" == 1 ]] || return 1
  grep -Eq '^[[:space:]]*listen *= *"127\.0\.0\.1:8000",?[[:space:]]*$' "$f" || return 1
  grep -Eq 'trusted_hosts *= *\{ *"127\.0\.0\.1", *"::1" *\}' "$f" || return 1
  grep -Eq '\[ *::|0\.0\.0\.0' "$f" && return 1
  while read -r v; do [[ "$v" == 127.0.0.1 ]] || return 1; done < <(grep -Eo '([0-9]{1,3}\.){3}[0-9]{1,3}' "$f")
  return 0
}
chk "API locale: seul listener http 127.0.0.1:8000" ecouteurs_ok "$D/kumomta/init.lua"
for v in 'listen = "[::]:8000",' 'listen = "[::1]:8000",' 'listen = "0.0.0.0:8000",' 'listen = "10.1.2.3:8000",' 'listen = "127.0.0.1:9000",'; do
  sed "s|^\( *\)listen = .*|\1$v|" "$D/kumomta/init.lua" >"$tmp.lua"; nochk "temoin: listener refuse ($v)" ecouteurs_ok "$tmp.lua"
done
{ cat "$D/kumomta/init.lua"; echo 'kumo.on("init", function() kumo.start_esmtp_listener { listen = "127.0.0.1:2525" } end)'; } >"$tmp.lua"
nochk "temoin: 2e listener (esmtp) refuse" ecouteurs_ok "$tmp.lua"
nochk "API jamais sur 0.0.0.0"           grep -Eq '0\.0\.0\.0' "$D/kumomta/init.lua" "$D/docker-compose.yml"

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
sed 's|<ip-tunnel-admin>/32|0.0.0.0/0|' "$D/wireguard/wg0.conf.modele" >"$tmp.wg"; nochk "temoin: wg0 AllowedIPs 0.0.0.0/0" wg_ok "$tmp.wg"
nochk "C1 aucun pair CRM (noms, variables, doc)" grep -rEi 'TUNNEL_CRM|PUBLIQUE_CRM|tunnel-crm|publique-crm|pair[^.]*crm|crm[^.]*pair' "$D" --exclude-dir=tests
chk  "C1 variables admin dans installateur+runbook" bash -c "grep -q CLE_PUBLIQUE_ADMIN '$D/installer.sh' && grep -q IP_TUNNEL_ADMIN '$D/installer.sh' && grep -q CLE_PUBLIQUE_ADMIN '$D/RUNBOOK.md' && grep -q IP_TUNNEL_ADMIN '$D/RUNBOOK.md'"
chk  "D7 documente (aucun tunnel vers la prod)" grep -q 'D7' "$D/RUNBOOK.md"

# ---------------------------- 5. aucune IP, domaine ni secret reel (m1) hors marqueurs
litteraux() { # litteraux <dossier> : une ligne par trouvaille
  local d="$1"
  grep -rEno '\b([0-9]{1,3}\.){3}[0-9]{1,3}\b' "$d" --exclude-dir=tests | grep -Ev ':127\.0\.0\.1$'
  grep -rPno '(?<![0-9A-Za-z:])[0-9A-Fa-f:]*:[0-9A-Fa-f]*:[0-9A-Fa-f:]*(?![0-9A-Za-z:])' "$d" --exclude-dir=tests | grep -Ev ':::1$'
  grep -rPno '(?<![A-Za-z0-9_.@-])(?:[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\.)+(?:com|net|org|io|fr|eu|de|uk|us|info|biz|dev|app|cloud|xyz|ai|email|pro)(?![A-Za-z0-9-])' "$d" --exclude-dir=tests | grep -Ev ':(ghcr|docker)\.io$'
  grep -rEnio '(PrivateKey|PresharedKey) *= *[A-Za-z0-9+/]{43}=|BEGIN [A-Z ]*PRIVATE KEY|password *= *[^<[:space:]]' "$d" --exclude-dir=tests
  return 0; }
trouve="$(litteraux "$D")"
[[ -z "$trouve" ]] && ok "aucune IP/IPv6/domaine/secret litteral" || bad "litteral: $trouve"
w="$tmpd/w"; mkdir -p "$w"
printf 'ip 203.0.113.7 et 127.0.0.1\n'   >"$w/a"; [[ "$(litteraux "$w")" == *203.0.113.7* ]] && ok "temoin m1: vraie IP + 127.0.0.1 detectee" || bad "temoin m1: IP + loopback masquee"
printf 'adr 2001:db8::1 ok\n'            >"$w/b"; [[ "$(litteraux "$w")" == *2001:db8* ]] && ok "temoin m1: IPv6 detectee" || bad "temoin m1: IPv6 non detectee"
printf 'hote mail.exemple-reel.com ok\n' >"$w/c"; [[ "$(litteraux "$w")" == *exemple-reel.com* ]] && ok "temoin m1: domaine detecte" || bad "temoin m1: domaine non detecte"
printf 'PASSWORD = hunter2\n'            >"$w/d"; [[ "$(litteraux "$w")" == *PASSWORD* ]] && ok "temoin m1: password majuscules detecte" || bad "temoin m1: password casse"
w="$tmpd/w2"; mkdir -p "$w"; printf 'a.test b.example ::1 127.0.0.1 ghcr.io docker.io <ip-envoi>\n' >"$w/e"
[[ -z "$(litteraux "$w")" ]] && ok "temoin m1: .test/.example/::1/127.0.0.1 toleres" || bad "temoin m1: faux positif: $(litteraux "$w")"
if command -v gitleaks >/dev/null; then chk "gitleaks (dossier)" gitleaks dir --no-banner "$D"; else echo "skip gitleaks (absent ; la CI le fait)"; fi

# ------------------------------------------- 6. installateur (idempotent, jamais destructif)
I="$D/installer.sh"
chk  "installer: set -euo pipefail"      grep -q '^set -euo pipefail' "$I"
chk  "installer: syntaxe bash"           bash -n "$I"
nochk "installer: pas de rm -rf"         grep -Eq 'rm +-rf|mkfs|dd +if' "$I"
chk  "installer: nft -c avant charge"    grep -q 'nft -c -f' "$I"
chk  "M1 installer: pull de l'image AVANT le pare-feu" bash -c "p=\$(grep -n 'compose.* pull' '$I' | head -1 | cut -d: -f1); f=\$(grep -n 'enable --now wg-quick@wg0' '$I' | head -1 | cut -d: -f1); [[ -n \$p && -n \$f && \$p -lt \$f ]]"
nochk "m5 cle privee jamais dans une commande" grep -Eq '\$\(.*cle-privee|-e [^ ]*\$\(cat|PrivateKey *= *\$' "$I"
chk  "m5 cle privee lue par fichier (awk getline)" grep -q 'getline.*cle-privee' "$I"
chk  "m5 wg0.conf cree en 0600 (umask 077)" grep -Eq 'umask 077' "$I"
chk  "installer: refuse un marqueur non remplace" grep -q "marqueur non remplace" "$I"
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

# ------------------------------------------------------- 7. CI + perimetre (M4, m2)
chk  "M4 ci.yml appelle tests/lancer-tests.sh" grep -q 'infra/serveur-envoi/tests/lancer-tests.sh' "$D/../../.github/workflows/ci.yml"
if ! git -C "$D" rev-parse >/dev/null 2>&1; then bad "perimetre: pas un depot git"
elif ! git -C "$D" rev-parse --verify -q origin/main >/dev/null; then bad "perimetre: origin/main ABSENT (git fetch origin main) - echec explicite"
else
  hors="$(git -C "$D" diff --name-only origin/main... | grep -Ev '^(infra/serveur-envoi/|\.github/workflows/ci\.yml$)' || true)"
  [[ -z "$hors" ]] && ok "perimetre infra/serveur-envoi/ (+ etape ci.yml)" || bad "hors perimetre: $hors"
  sup="$(git -C "$D" diff origin/main... -- .github/workflows/ci.yml | grep -E '^-[^-]' || true)"
  [[ -z "$sup" ]] && ok "ci.yml : ajout seulement, aucune ligne retiree" || bad "ci.yml : lignes retirees: $sup"
fi
echo "--- $n verifications, $([[ $ko = 0 ]] && echo VERT || echo ROUGE)"
exit "$ko"
