#!/usr/bin/env bash
# Tests K16 de reconstruire.sh (statiques + a blanc, sans reseau, sans serveur, sans achat).
# Banc K10 : il ne simule pas un serveur Ubuntu complet (voir docs/envoi/runbook-serveur-perdu.md,
# dette K16-D1) ; ici : refus sans marqueurs remplaces, a blanc sur valeurs de documentation,
# idempotence, shellcheck. Code 0 = vert.
set -uo pipefail
D="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
R="$D/reconstruction/reconstruire.sh"
ko=0; n=0
ok()  { n=$((n+1)); echo "ok   $*"; }
bad() { n=$((n+1)); ko=1; echo "ROUGE $*"; }
verifie() { local nom="$1"; shift; if "$@"; then ok "$nom"; else bad "$nom"; fi; }   # verifie <nom> <commande vraie>
tmpd="$(mktemp -d)"; trap 'rm -rf "${tmpd:?}"' EXIT

cle_pub="$(printf 'B%.0s' {1..43})="
# Aucune adresse litterale ici (le scanner de lancer-tests.sh l'interdit) : prefixes RFC 5737 assembles.
A1=192.0.2; A2=198.51.100; A3=203.0.113
ecrire_valeurs() { # ecrire_valeurs <fichier> : valeurs FICTIVES de documentation
  printf 'IP_ENVOI=%s.10\nIP_GESTION=%s.11\nIP_CRM_PROD=%s.20\nIP_RESOLVEUR=%s.53\nIP_TUNNEL=%s.1\nIP_TUNNEL_ADMIN=%s.2\nPORT_WG=51820\nCLE_PUBLIQUE_ADMIN=%s\n' \
    "$A1" "$A1" "$A2" "$A1" "$A3" "$A3" "$cle_pub" >"$1"; }
ecrire_valeurs "$tmpd/valeurs.env"
# lancer <env...> -- <args> : environnement PROPRE (aucune fuite de l'environnement de l'appelant)
lancer() { env -i PATH="$PATH" AXION_VALEURS="$tmpd/valeurs.env" REP_DKIM="$tmpd/dkim" "$@" bash "$R"; }
BON=(DOMAINES_ENVOI=domaine-1.test SELECTEUR_PRINCIPAL=nouveau1 SELECTEUR_SECOURS=nouveau2 CONFIRME_ENVOIS_ARRETES=oui CONFIRME_HMAC_TOURNE=oui)
refuse() { # refuse <nom> <motif attendu> <env...>
  local nom="$1" motif="$2"; shift 2; local out rc
  out="$(lancer "$@" 2>&1)"; rc=$?
  if [[ $rc == 1 && "$out" == *"$motif"* ]]; then ok "refus: $nom"; else bad "refus: $nom (rc=$rc : $out)"; fi
}

# 1. Sans aucune variable : refus (le script ne tourne jamais sur des valeurs par defaut).
refuse "environnement vide" "variable d'environnement absente"
# 2. Marqueurs non remplaces (variables d'environnement, puis fichier de valeurs).
refuse "marqueur dans DOMAINES_ENVOI" "marqueur non remplace" "${BON[@]/DOMAINES_ENVOI=*/DOMAINES_ENVOI=<domaine-1>}"
refuse "marqueur dans le selecteur" "marqueur non remplace" "${BON[@]/SELECTEUR_PRINCIPAL=*/SELECTEUR_PRINCIPAL=<selecteur-1>}"
cp "$tmpd/valeurs.env" "$tmpd/valeurs.bon"
sed -i 's|IP_ENVOI=.*|IP_ENVOI=<ip-envoi>|' "$tmpd/valeurs.env"
refuse "marqueur dans valeurs.env" "marqueur non remplace" "${BON[@]}"
cp "$tmpd/valeurs.bon" "$tmpd/valeurs.env"
# 3. Garde-fous humains : arret des envois et rotation HMAC confirmes ; formes valides.
refuse "envois non arretes" "CONFIRME_ENVOIS_ARRETES" "${BON[@]/CONFIRME_ENVOIS_ARRETES=*/CONFIRME_ENVOIS_ARRETES=non}"
refuse "HMAC non tourne" "CONFIRME_HMAC_TOURNE" "${BON[@]/CONFIRME_HMAC_TOURNE=*/CONFIRME_HMAC_TOURNE=non}"
refuse "selecteurs identiques" "doivent differer" "${BON[@]/SELECTEUR_SECOURS=*/SELECTEUR_SECOURS=nouveau1}"
refuse "domaine invalide" "domaine invalide" "${BON[@]/DOMAINES_ENVOI=*/DOMAINES_ENVOI=pas_un_domaine}"
sed -i 's|PORT_WG=.*|PORT_WG=99999|' "$tmpd/valeurs.env"
refuse "valeurs.env non conforme" "valeurs non conformes" "${BON[@]}"
cp "$tmpd/valeurs.bon" "$tmpd/valeurs.env"
out="$(env -i PATH="$PATH" AXION_VALEURS="$tmpd/absent.env" "${BON[@]}" bash "$R" 2>&1)"; rc=$?
verifie "refus: fichier de valeurs absent (code 1)" test "$rc" = 1
verifie "refus: fichier de valeurs absent (motif)" test "${out#*'fichier de valeurs absent'}" != "$out"
out="$(env -i PATH="$PATH" AXION_VALEURS="$tmpd/valeurs.env" REP_DKIM="$tmpd/dkim" "${BON[@]}" bash "$R" --bizarre 2>&1)"; rc=$?
verifie "refus: option inconnue (code 2)" test "$rc" = 2
# 4. A blanc sur valeurs de documentation : passe, ne cree rien, ne lance rien.
out="$(lancer "${BON[@]}" 2>&1)"; rc=$?
verifie "a blanc: vert sur valeurs de documentation" test "$rc" = 0
verifie "a blanc: controles annonces" test "${out#*'controles passes (a blanc)'}" != "$out"
verifie "a blanc: aucune cle creee" test ! -e "$tmpd/dkim"
verifie "a blanc: l'envoi n'est jamais repris" test "${out#*'PAS REPRIS'}" != "$out"
out2="$(lancer "${BON[@]}" 2>&1)"
verifie "idempotence: deux passages a blanc identiques" test "$out" = "$out2"
# 5. Idempotence DKIM : domaine deja initialise -> saute.
mkdir -p "$tmpd/dkim/domaine-1.test"; : >"$tmpd/dkim/domaine-1.test/principal"
out="$(lancer "${BON[@]}" 2>&1)"
verifie "idempotence: cles DKIM existantes sautees" test "${out#*'deja presentes'}" != "$out"
# 5b. Droits des cles DKIM (bloquant K16) : 0640 root:axion-dkim, dossiers 0750. Des cles 0600 sont
# illisibles pour KumoMTA (group_add 10025) : tout serait refuse en 451, et relancer ne reparait rien.
# shellcheck source=droits-dkim.sh
source "$D/reconstruction/droits-dkim.sh"
GR="$(id -gn)"
mkdir -p "$tmpd/cles/d.test"; : >"$tmpd/cles/d.test/a.pem"; : >"$tmpd/cles/d.test/principal"
chmod 0600 "$tmpd/cles/d.test/a.pem"; chmod 0700 "$tmpd/cles" "$tmpd/cles/d.test"
droits_dkim_corriger "$tmpd/cles" "$GR" 0 >/dev/null; rc=$?
verifie "droits DKIM: cle 0600 signalee a blanc (rouge sans le correctif)" test "$rc" = 3
verifie "droits DKIM: a blanc ne modifie rien" test "$(stat -c %a "$tmpd/cles/d.test/a.pem")" = 600
droits_dkim_corriger "$tmpd/cles" "$GR" 1 >/dev/null
verifie "droits DKIM: cle deja presente 0600 corrigee en 0640" test "$(stat -c %a "$tmpd/cles/d.test/a.pem")" = 640
verifie "droits DKIM: dossiers corriges en 0750" test "$(stat -c %a "$tmpd/cles/d.test")" = 750
verifie "droits DKIM: groupe applique" test "$(stat -c %G "$tmpd/cles/d.test/a.pem")" = "$GR"
droits_dkim_corriger "$tmpd/cles" "$GR" 0 >/dev/null; verifie "droits DKIM: rien a corriger apres correction" test "$?" = 0
# Le script les verifie aussi pour un domaine DEJA initialise (a blanc : signale, sans variable GROUPE_DKIM).
mkdir -p "$tmpd/dkim2/domaine-1.test"; : >"$tmpd/dkim2/domaine-1.test/principal"; : >"$tmpd/dkim2/domaine-1.test/n.pem"
chmod 0600 "$tmpd/dkim2/domaine-1.test/n.pem"
out="$(env -i PATH="$PATH" AXION_VALEURS="$tmpd/valeurs.env" REP_DKIM="$tmpd/dkim2" "${BON[@]}" bash "$R" 2>&1)"
verifie "script: cles existantes 0600 signalees (domaine deja initialise)" test "${out#*'droits a corriger'}" != "$out"
if ! getent group axion-dkim >/dev/null; then
  verifie "script: cree le groupe axion-dkim GID 10025 sans variable" test "${out#*'groupadd -g 10025 axion-dkim'}" != "$out"
fi
verifie "script: --groupe toujours transmis (pas conditionne a une variable)" bash -c "grep -q -- '--groupe \"\$GROUPE\"' '$R' && ! grep -q 'GROUPE_DKIM:+' '$R'"
# Relance apres echec partiel : serveur non vierge signale (avertissement a blanc), contournement nomme.
mkdir -p "$tmpd/opt"
out="$(env -i PATH="$PATH" AXION_VALEURS="$tmpd/valeurs.env" REP_DKIM="$tmpd/dkim" AXION_CIBLE="$tmpd/opt" "${BON[@]}" bash "$R" 2>&1)"
verifie "relance: non vierge signale avec le contournement nomme" test "${out#*'AXION_FORCER_NON_VIERGE=oui'}" != "$out"
verifie "relance: contournement documente en tete du script" bash -c "sed -n 1,45p '$R' | grep -q 'AXION_FORCER_NON_VIERGE=oui'"
# Documents : etapes et dettes exigees par la relecture d'exactitude.
RB="$D/../../docs/envoi/runbook-serveur-perdu.md"
for motif in 'AXION_FORCER_NON_VIERGE' 'K16-D4' 'K16-D5' 'envoi:tout-arreter' 'envoi:reprendre' 'RETIRER' 'sans borne' '4 bis' 'axion-dkim' 'PARTIEL'; do
  verifie "runbook: mentionne « $motif »" grep -q -- "$motif" "$RB"
done
verifie "fiche K14: SNDS nouveau portail et donnees absentes au debut" bash -c "grep -q 'nouveau portail' '$D/../../docs/envoi/fiche-inscriptions-reputation.md'"
# 6. Statique : jamais de trace de commande, aucun envoi reel active, script executable.
verifie "statique: ni set -x ni ENVOI_REEL_ACTIF=true" bash -c "! grep -Eq 'set -x|ENVOI_REEL_ACTIF *= *true' '$R'"
verifie "statique: script executable" test -x "$R"
# 6b. Les deux documents K16/K14 : aucune IP ni aucun nom de domaine reel (marqueurs seulement).
DOCS="$D/../../docs/envoi"
verifie "docs K16/K14 : aucune IPv4 litterale" bash -c "! grep -Eq '\\b([0-9]{1,3}\\.){3}[0-9]{1,3}\\b' '$DOCS/runbook-serveur-perdu.md' '$DOCS/fiche-inscriptions-reputation.md'"
verifie "docs K16/K14 : aucun nom de domaine reel" bash -c "! grep -Eiq '[a-z0-9-]+\\.(com|net|org|io|fr|eu|de|info|biz|dev|app|cloud|xyz|ai|email|pro)\\b' '$DOCS/runbook-serveur-perdu.md' '$DOCS/fiche-inscriptions-reputation.md'"
# 7. shellcheck : obligatoire en CI, saute en local s'il est absent.
SC="$(command -v shellcheck || true)"
if [[ -n "$SC" ]]; then verifie "shellcheck propre" "$SC" -x -P SCRIPTDIR "$R" "${BASH_SOURCE[0]}"
elif [[ -n "${CI:-}" ]]; then bad "shellcheck absent en CI"
else echo "skip shellcheck (absent en local ; obligatoire en CI)"; fi
echo "--- K16 : $n verifications, $([[ $ko = 0 ]] && echo VERT || echo ROUGE)"
exit "$ko"
