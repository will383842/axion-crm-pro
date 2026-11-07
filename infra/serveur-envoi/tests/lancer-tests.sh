#!/usr/bin/env bash
# Tests K3 (statiques, sans reseau, sans serveur). Code 0 = tout vert.
set -uo pipefail
D="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
T="$D/tests"
ko=0; n=0
ok()  { n=$((n+1)); echo "ok   $*"; }
bad() { n=$((n+1)); ko=1; echo "ROUGE $*"; }
chk() { local nom="$1"; shift; if "$@" >/dev/null 2>&1; then ok "$nom"; else bad "$nom"; fi; }
nochk() { local nom="$1"; shift; if "$@" >/dev/null 2>&1; then bad "$nom"; else ok "$nom"; fi; }

# 1. nftables : conforme, et le temoin negatif sait rougir.
chk  "nftables conforme"                 bash "$T/verifier-nftables.sh" "$D/nftables.conf"
tmp="$(mktemp)"; trap 'rm -f "$tmp"' EXIT
sed 's/hook input priority 0; policy drop/hook input priority 0; policy accept/' "$D/nftables.conf" >"$tmp"
nochk "temoin: policy accept detectee"   bash "$T/verifier-nftables.sh" "$tmp"
{ cat "$D/nftables.conf"; } | sed 's/^\(\s*\)udp dport \$wg_port accept/\1tcp dport 8080 accept\n&/' >"$tmp"
nochk "temoin: port 8080 detecte"        bash "$T/verifier-nftables.sh" "$tmp"
sed 's/ip saddr \$ip_envoi //' "$D/nftables.conf" >"$tmp"
nochk "temoin: sortie 25 sans source"    bash "$T/verifier-nftables.sh" "$tmp"

# 2. Image figee par empreinte, jamais par tag mobile.
chk  "image figee par sha256"            grep -Eq 'image: *ghcr\.io/kumocorp/kumomta@sha256:[0-9a-f]{64}$' "$D/docker-compose.yml"
nochk "aucun tag mobile (latest/:tag)"   grep -Eq 'image: *[^@ ]+:[A-Za-z0-9._-]+ *$' "$D/docker-compose.yml"

# 3. API d'administration en local seulement.
chk  "trusted_hosts = localhost"         grep -Eq 'trusted_hosts *= *\{ *"127\.0\.0\.1", *"::1" *\}' "$D/kumomta/init.lua"
chk  "API http sur 127.0.0.1"            grep -Eq 'listen *= *"127\.0\.0\.1:8000"' "$D/kumomta/init.lua"
nochk "API jamais sur 0.0.0.0"           grep -Eq '0\.0\.0\.0' "$D/kumomta/init.lua" "$D/docker-compose.yml"

# 4. SSH uniquement par le tunnel ; WireGuard avec marqueurs.
chk  "sshd ecoute sur l'IP du tunnel"    grep -Eq '^ListenAddress <ip-tunnel>$' "$D/sshd-tunnel.conf"
chk  "sshd sans mot de passe"            grep -Eq '^PasswordAuthentication no$' "$D/sshd-tunnel.conf"
chk  "sshd sans root par mot de passe"   grep -Eq '^PermitRootLogin prohibit-password$' "$D/sshd-tunnel.conf"
chk  "wg0 : marqueurs de cles"           grep -Eq 'PrivateKey = <cle-privee-serveur>' "$D/wireguard/wg0.conf.modele"
chk  "wg0 : pair en marqueur"            grep -Eq 'PublicKey = <cle-publique-crm>' "$D/wireguard/wg0.conf.modele"

# 5. Aucune IP ni secret reel (hors loopback/marqueurs) dans le dossier.
ips="$(grep -rEn '\b([0-9]{1,3}\.){3}[0-9]{1,3}\b' "$D" --exclude-dir=tests | grep -Ev '127\.0\.0\.1|0\.0\.0\.0' || true)"
[[ -z "$ips" ]] && ok "aucune IP litterale" || { bad "IP litterale: $ips"; }
sec="$(grep -rEn '(PrivateKey|PresharedKey) *= *[A-Za-z0-9+/]{43}=|BEGIN [A-Z ]*PRIVATE KEY|password *= *[^<[:space:]]' "$D" --exclude-dir=tests || true)"
[[ -z "$sec" ]] && ok "aucun secret reel" || bad "secret: $sec"
if command -v gitleaks >/dev/null; then chk "gitleaks (dossier)" gitleaks dir --no-banner "$D"; else echo "skip gitleaks (absent ; la CI le fait)"; fi

# 6. Installation idempotente, jamais destructive ; redemarrage propre decrit.
chk  "installer: set -euo pipefail"      grep -q '^set -euo pipefail' "$D/installer.sh"
chk  "installer: refuse hors root/--oui" grep -q -- '--oui' "$D/installer.sh"
chk  "installer: syntaxe bash"           bash -n "$D/installer.sh"
nochk "installer: pas de rm -rf"         grep -Eq 'rm +-rf|mkfs|dd +if' "$D/installer.sh"
chk  "installer: nft -c avant charge"    grep -q 'nft -c -f' "$D/installer.sh"
chk  "systemd: Restart=on-failure"       grep -q '^Restart=on-failure' "$D/kumomta-envoi.service"
chk  "systemd: arret propre"             grep -Eq '^ExecStop=.*compose.* down|^ExecStop=.*stop' "$D/kumomta-envoi.service"
chk  "systemd: ordre apres wg0+nft"      grep -Eq '^After=.*wg-quick@wg0\.service.*nftables\.service' "$D/kumomta-envoi.service"
chk  "systemd: delai d'arret"            grep -Eq '^TimeoutStopSec=[0-9]+' "$D/kumomta-envoi.service"
chk  "runbook present"                   test -s "$D/RUNBOOK.md"

# 7. Rien hors du dossier (garde de perimetre, best effort).
if git -C "$D" rev-parse >/dev/null 2>&1; then
  hors="$(git -C "$D" diff --name-only origin/main... 2>/dev/null | grep -v '^infra/serveur-envoi/' || true)"
  [[ -z "$hors" ]] && ok "perimetre infra/serveur-envoi/" || bad "hors perimetre: $hors"
fi
echo "--- $n verifications, $([[ $ko = 0 ]] && echo VERT || echo ROUGE)"
exit "$ko"
