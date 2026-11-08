#!/usr/bin/env bash
# Tests K5 (DKIM cote serveur). Aucun reseau, cle generee dans un dossier temporaire.
set -uo pipefail
D="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
G="$D/generer-dkim.sh"
ko=0
ok()  { echo "ok   $*"; }
bad() { ko=1; echo "ROUGE $*"; }
tmpd="$(mktemp -d)"; trap 'rm -rf "${tmpd:?}"' EXIT
DOM=exemple-envoi.test
out="$(bash "$G" --domaine "$DOM" --selecteur k5a --secours k5b --repertoire "$tmpd/cles" 2>&1)"; rc=$?
[[ $rc == 0 ]] && ok "generation" || bad "generation (rc=$rc) $out"
P1="$tmpd/cles/$DOM/k5a.pem"; P2="$tmpd/cles/$DOM/k5b.pem"
# permissions 0600 (fichiers) et 0700 (dossiers)
[[ "$(stat -c %a "$P1" 2>/dev/null)" == 600 && "$(stat -c %a "$P2" 2>/dev/null)" == 600 ]] && ok "cles en 0600" || bad "cles en 0600"
[[ "$(stat -c %a "$tmpd/cles/$DOM" 2>/dev/null)" == 700 ]] && ok "dossier en 0700" || bad "dossier en 0700"
# RSA 2048
[[ "$(openssl rsa -in "$P1" -noout -text 2>/dev/null | head -1)" == *"2048 bit"* ]] && ok "RSA 2048" || bad "RSA 2048"
# cle privee jamais affichee
if grep -q 'PRIVATE KEY' <<<"$out"; then bad "cle privee affichee"; else ok "cle privee non affichee"; fi
# selecteurs distincts, deux enregistrements
n="$(grep -c '_domainkey' <<<"$out")"
[[ "$n" == 2 ]] && ok "deux enregistrements TXT (principal + secours)" || bad "deux enregistrements TXT ($n)"
bash "$G" --domaine "$DOM" --selecteur k5a --secours k5a --repertoire "$tmpd/x" >/dev/null 2>&1 && bad "secours identique accepte" || ok "secours identique refuse"
# TXT : chaines <= 255, recollees = v=DKIM1; k=rsa; p=<cle publique>
T="$tmpd/cles/$DOM/k5a._domainkey.txt"
[[ -f "$T" ]] && ok "fichier TXT public" || bad "fichier TXT public"
maxlen="$(grep -o '"[^"]*"' "$T" | awk '{print length($0)-2}' | sort -n | tail -1)"
[[ -n "$maxlen" && "$maxlen" -le 255 ]] && ok "chaines <= 255 (max $maxlen)" || bad "chaines <= 255 ($maxlen)"
[[ "$(grep -o '"[^"]*"' "$T" | wc -l)" -ge 2 ]] && ok "TXT decoupe en plusieurs chaines" || bad "TXT decoupe"
recolle="$(grep -o '"[^"]*"' "$T" | tr -d '"\n')"
pub="$(openssl rsa -in "$P1" -pubout -outform DER 2>/dev/null | base64 -w0)"
[[ "$recolle" == "v=DKIM1; k=rsa; p=$pub" ]] && ok "TXT recolle identique" || bad "TXT recolle identique"
# pas de cle privee dans le TXT public, TXT lisible (0644)
grep -q PRIVATE "$T" && bad "cle privee dans TXT" || ok "TXT sans cle privee"
# non ecrasement
bash "$G" --domaine "$DOM" --selecteur k5a --secours k5b --repertoire "$tmpd/cles" >/dev/null 2>&1 && bad "ecrasement accepte" || ok "ecrasement refuse"
# valeurs invalides
bash "$G" --domaine 'a b;c' --selecteur k5a --secours k5b --repertoire "$tmpd/y" >/dev/null 2>&1 && bad "domaine invalide accepte" || ok "domaine invalide refuse"
# en-tetes signes (h=) : ORDRE REEL (celui de DkimSigneur::ENTETES_SIGNES), pas un ensemble trie
H="$D/entetes-signes.txt"
exp="From Reply-To To Subject Date Message-ID MIME-Version Content-Type List-Unsubscribe List-Unsubscribe-Post"
got="$(grep -v '^#' "$H" | grep -v '^$' | tr '\n' ' ' | sed 's/ $//')"
[[ "$got" == "$exp" ]] && ok "en-tetes signes dans l'ordre" || bad "en-tetes signes dans l'ordre ($got)"
got="$(grep -v '^#' "$D/entetes-sursignes.txt" | grep -v '^$' | tr '\n' ' ' | sed 's/ $//')"
[[ "$got" == "From Subject List-Unsubscribe" ]] && ok "en-tetes sur-signes dans l'ordre" || bad "en-tetes sur-signes ($got)"
hexp="from:reply-to:to:subject:date:message-id:mime-version:content-type:list-unsubscribe:list-unsubscribe-post:from:subject:list-unsubscribe"
[[ "$out" == *"h=$hexp"* ]] && ok "h= de la sortie : signes puis sur-signes" || bad "h= de la sortie"
grep -q 'h=' <<<"$out" && ok "h= fourni dans la sortie" || bad "h= fourni dans la sortie"
# depot : aucune cle privee sous infra/
if grep -rIl -e '-----BEGIN [A-Z ]*PRIVATE KEY-----' "$D/.." >/dev/null 2>&1 || find "$D/.." \( -name '*.pem' -o -name '*.key' \) | grep -q .; then bad "cle privee dans le depot"; else ok "aucune cle privee dans le depot"; fi

# ---- nom TXT avec point final ; etat des fichiers
grep -q "^k5a\._domainkey\.$DOM\. IN TXT" "$T" && ok "nom TXT avec point final" || bad "nom TXT avec point final"
[[ "$(cat "$tmpd/cles/$DOM/principal" 2>/dev/null)" == k5a && "$(cat "$tmpd/cles/$DOM/secours" 2>/dev/null)" == k5b ]] && ok "principal/secours notes" || bad "principal/secours notes"
find "$tmpd/cles" -name '.tmp*' | grep -q . && bad "fichier temporaire laisse" || ok "aucun fichier temporaire"

# ---- rotation : le secours devient principal, UN nouveau secours, principal NON regenere
sum1="$(sha256sum "$P2" | cut -d' ' -f1)"
outr="$(bash "$G" --rotation --domaine "$DOM" --secours k5c --repertoire "$tmpd/cles" 2>&1)"; rc=$?
[[ $rc == 0 ]] && ok "rotation" || bad "rotation (rc=$rc) $outr"
[[ "$(cat "$tmpd/cles/$DOM/principal")" == k5b && "$(cat "$tmpd/cles/$DOM/secours")" == k5c ]] && ok "rotation: k5b principal, k5c secours" || bad "rotation: etat"
[[ "$(sha256sum "$P2" | cut -d' ' -f1)" == "$sum1" ]] && ok "rotation: ancien secours non regenere" || bad "rotation: ancien secours regenere"
[[ -f "$tmpd/cles/$DOM/k5c.pem" && "$(stat -c %a "$tmpd/cles/$DOM/k5c.pem")" == 600 ]] && ok "rotation: nouveau secours en 0600" || bad "rotation: nouveau secours"
[[ "$(grep -c '_domainkey' <<<"$outr")" == 1 ]] && ok "rotation: UN seul nouveau TXT" || bad "rotation: un seul TXT"
bash "$G" --rotation --domaine "$DOM" --secours k5c --repertoire "$tmpd/cles" >/dev/null 2>&1 && bad "rotation: nom deja pris accepte" || ok "rotation: nom deja pris refuse"
bash "$G" --rotation --domaine "$DOM" --secours k5b --repertoire "$tmpd/cles" >/dev/null 2>&1 && bad "rotation: nom du principal accepte" || ok "rotation: nom du principal refuse"
bash "$G" --rotation --domaine autre.exemple-envoi.test --secours k5d --repertoire "$tmpd/cles" >/dev/null 2>&1 && bad "rotation: domaine inconnu accepte" || ok "rotation: domaine inconnu refuse"

# ---- repertoire : jamais de chmod sur un dossier non cree par le script
mkdir "$tmpd/etranger"; touch "$tmpd/etranger/fichier"; chmod 0755 "$tmpd/etranger"
bash "$G" --domaine "$DOM" --selecteur k5a --secours k5b --repertoire "$tmpd/etranger" >/dev/null 2>&1 && bad "repertoire existant non vide accepte" || ok "repertoire existant non vide refuse"
[[ "$(stat -c %a "$tmpd/etranger")" == 755 ]] && ok "repertoire etranger intact (0755)" || bad "repertoire etranger modifie"
mkdir "$tmpd/vide"; chmod 0755 "$tmpd/vide"
bash "$G" --domaine "$DOM" --selecteur k5a --secours k5b --repertoire "$tmpd/vide" >/dev/null 2>&1 && ok "repertoire existant vide accepte" || bad "repertoire existant vide refuse"
[[ "$(stat -c %a "$tmpd/vide")" == 755 ]] && ok "repertoire vide existant: perms inchangees" || bad "repertoire vide existant modifie"
bash "$G" --domaine autre.exemple-envoi.test --selecteur k5a --secours k5b --repertoire "$tmpd/vide" >/dev/null 2>&1 && ok "2e domaine dans le meme repertoire dedie" || bad "2e domaine refuse"
bash "$G" --domaine "$DOM" --selecteur k5x --secours k5y --repertoire "$tmpd/cles" >/dev/null 2>&1 && bad "domaine deja initialise accepte" || ok "domaine deja initialise refuse"

# ---- option -- et chemin commencant par un tiret
! grep -nE '^\s*(mkdir|chmod|chgrp|rmdir|mv|rm|ls|cat)( -[a-zA-Z]+)* [^-]' "$G" | grep -v -- ' -- ' | grep -q . && ok "commandes sur chemins: option -- presente" || bad "commandes sur chemins sans --"
( cd "$tmpd" && bash "$G" --domaine "$DOM" --selecteur k5a --secours k5b --repertoire ./-tiret >/dev/null 2>&1 ) && ok "chemin ./-tiret accepte" || bad "chemin ./-tiret refuse"
( cd "$tmpd" && bash "$G" --domaine "$DOM" --selecteur k5a --secours k5b --repertoire -tiret2 >/dev/null 2>&1 ) && bad "chemin commencant par - sans -- accepte" || ok "chemin commencant par - sans -- refuse"

# ---- longueur max du domaine : 253 (et libelles <= 63)
l63="$(printf 'a%.0s' {1..63})"
TLD=fr; l57="$(printf 'a%.0s' {1..57})"
d253="$l63.$l63.$l63.${l57}b.${TLD}"        # 63*3 + 58 + 3 points + « fr » = 253
d254="x$d253"
[[ ${#d253} -le 253 ]] || bad "temoin 253 mal construit (${#d253})"
[[ ${#d253} == 253 ]] && ok "temoin de 253 caracteres" || bad "temoin 253 (${#d253})"
bash "$G" --domaine "$d254" --selecteur k5a --secours k5b --repertoire "$tmpd/l1" >/dev/null 2>&1 && bad "domaine > 253 accepte" || ok "domaine > 253 refuse"
bash "$G" --domaine "a.$l63$l63.test" --selecteur k5a --secours k5b --repertoire "$tmpd/l2" >/dev/null 2>&1 && bad "libelle > 63 accepte" || ok "libelle > 63 refuse"

# ---- echec de la 2e cle : aucune cle ni fichier temporaire ne reste, repertoire cree par nous retire
fauxbin="$tmpd/fauxbin"; mkdir "$fauxbin"; vrai="$(command -v openssl)"
cat >"$fauxbin/openssl" <<EOS
#!/usr/bin/env bash
if [[ "\$1" == genrsa ]]; then
  n=\$(cat "$tmpd/compteur" 2>/dev/null || echo 0); n=\$((n+1)); echo \$n >"$tmpd/compteur"
  [[ \$n -ge 2 ]] && { echo "panne simulee" >&2; exit 1; }
fi
exec "$vrai" "\$@"
EOS
chmod +x "$fauxbin/openssl"; rm -f "$tmpd/compteur"
PATH="$fauxbin:$PATH" bash "$G" --domaine "$DOM" --selecteur k5a --secours k5b --repertoire "$tmpd/echec" >/dev/null 2>&1 && bad "echec 2e cle: succes annonce" || ok "echec 2e cle: code d'erreur"
if [[ -e "$tmpd/echec" ]] && [[ -n "$(find "$tmpd/echec" -type f 2>/dev/null)" ]]; then bad "echec 2e cle: fichiers laisses ($(find "$tmpd/echec" -type f | tr '\n' ' '))"; else ok "echec 2e cle: aucune cle ni temporaire laisse"; fi

# ---- concurrence : deux lancements simultanes, un seul gagne, aucune cle melangee
for i in 1 2 3 4; do bash "$G" --domaine "$DOM" --selecteur k5a --secours k5b --repertoire "$tmpd/conc" >"$tmpd/conc$i.out" 2>&1 & done; wait
nb=0; for i in 1 2 3 4; do grep -q "_domainkey" "$tmpd/conc$i.out" && nb=$((nb+1)); done
[[ $nb == 1 ]] && ok "concurrence: un seul lancement reussit" || bad "concurrence: $nb lancements ont reussi"
[[ "$(find "$tmpd/conc" -name '*.pem' | wc -l)" == 2 ]] && ok "concurrence: exactement 2 cles" || bad "concurrence: nombre de cles"

# ---- groupe dedie : 0640 / 0750, jamais plus large
grp="$(id -gn)"
bash "$G" --domaine "$DOM" --selecteur k5a --secours k5b --repertoire "$tmpd/grp" --groupe "$grp" >/dev/null 2>&1 && ok "generation avec groupe" || bad "generation avec groupe"
[[ "$(stat -c %a "$tmpd/grp/$DOM/k5a.pem")" == 640 && "$(stat -c %a "$tmpd/grp/$DOM")" == 750 && "$(stat -c %G "$tmpd/grp/$DOM/k5a.pem")" == "$grp" ]] && ok "groupe: cle 0640, dossier 0750, groupe applique" || bad "groupe: permissions"
bash "$G" --domaine "$DOM" --selecteur k5a --secours k5b --repertoire "$tmpd/grp2" --groupe 'inexistant-zz9' >/dev/null 2>&1 && bad "groupe inexistant accepte" || ok "groupe inexistant refuse"

# ---- cote serveur KumoMTA : dkim.lua charge par init.lua, memes en-tetes, compose
K="$D/../kumomta"; DC="$D/../docker-compose.yml"
grep -qE "require[ (]*['\"]dkim['\"]" "$K/init.lua" && ok "init.lua charge dkim.lua" || bad "init.lua ne charge pas dkim.lua"
[[ -s "$K/dkim.lua" ]] && ok "dkim.lua present" || bad "dkim.lua absent"
liste_lua() { awk -v t="$1" '$0 ~ "^local "t" = \\{" {on=1;next} on&&/^\}/ {on=0} on' "$K/dkim.lua" | grep -o "'[^']*'" | tr -d "'"; }
[[ "$(liste_lua ENTETES_SIGNES | tr '\n' ' ')" == "$(grep -v '^#' "$H" | grep -v '^$' | tr '\n' ' ')" ]] && ok "dkim.lua: ENTETES_SIGNES = entetes-signes.txt" || bad "dkim.lua: ENTETES_SIGNES diverge de entetes-signes.txt"
[[ "$(liste_lua ENTETES_SURSIGNES | tr '\n' ' ')" == "$(grep -v '^#' "$D/entetes-sursignes.txt" | grep -v '^$' | tr '\n' ' ')" ]] && ok "dkim.lua: ENTETES_SURSIGNES = entetes-sursignes.txt" || bad "dkim.lua: ENTETES_SURSIGNES diverge"
grep -qE '^\s*headers = liste_h\(\),' "$K/dkim.lua" && grep -A4 '^local function liste_h' "$K/dkim.lua" | grep -q ENTETES_SIGNES && sed -n '/^local function liste_h/,/^end/p' "$K/dkim.lua" | grep -q ENTETES_SURSIGNES && ok "dkim.lua: headers = signes + sur-signes" || bad "dkim.lua: headers n'inclut pas les sur-signes"
grep -q 'rsa_sha256_signer' "$K/dkim.lua" && grep -q 'principal' "$K/dkim.lua" && ok "dkim.lua: signe avec le selecteur principal" || bad "dkim.lua: signeur/principal"
grep -qE "BEGIN [A-Z ]*PRIVATE KEY|MII[A-Za-z0-9+/]{40}" "$K/dkim.lua" && bad "dkim.lua: valeur de cle" || ok "dkim.lua sans valeur de cle"
grep -qE "^\s*- /etc/axion-envoi/dkim:/etc/axion-envoi/dkim:ro\s*$" "$DC" && ok "compose: cles montees en lecture seule" || bad "compose: montage des cles en :ro"
grep -qE "^\s*- \./kumomta/dkim\.lua:/opt/kumomta/etc/policy/dkim\.lua:ro\s*$" "$DC" && ok "compose: dkim.lua monte en :ro" || bad "compose: dkim.lua non monte"
grep -qE "group_add" "$DC" && ok "compose: groupe dedie lisible par kumod" || bad "compose: group_add absent"
grep -qE "dkim\.lua" "$D/../installer.sh" && ok "installeur: dkim.lua installe" || bad "installeur: dkim.lua non installe"
exit $ko
