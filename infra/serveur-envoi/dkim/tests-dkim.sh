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
body="$(openssl rsa -in "$P1" -noout -modulus 2>/dev/null | cut -d= -f2 | head -c 40)"
if [[ -n "$body" ]] && grep -q "$body" <<<"$out"; then bad "modulus affiche"; else ok "aucun secret dans la sortie"; fi
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
# en-tetes signes (h=)
H="$D/entetes-signes.txt"
exp="From To Subject Date Message-ID List-Unsubscribe List-Unsubscribe-Post MIME-Version Content-Type"
got="$(grep -v '^#' "$H" | grep -v '^$' | sort | tr '\n' ' ')"
want="$(tr ' ' '\n' <<<"$exp" | sort | tr '\n' ' ')"
[[ "$got" == "$want" ]] && ok "en-tetes signes complets" || bad "en-tetes signes complets"
grep -q 'h=' <<<"$out" && ok "h= fourni dans la sortie" || bad "h= fourni dans la sortie"
# depot : aucune cle privee sous infra/
if grep -rIl -e '-----BEGIN [A-Z ]*PRIVATE KEY-----' "$D/.." >/dev/null 2>&1 || find "$D/.." \( -name '*.pem' -o -name '*.key' \) | grep -q .; then bad "cle privee dans le depot"; else ok "aucune cle privee dans le depot"; fi
exit $ko
