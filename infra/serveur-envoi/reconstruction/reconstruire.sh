#!/usr/bin/env bash
# K16 : reconstruction IDEMPOTENTE du serveur d'envoi sur un VPS VIERGE (Ubuntu 24.04).
# A lancer en root SUR LE NOUVEAU serveur d'envoi, jamais sur la prod du CRM.
# Runbook : docs/envoi/runbook-serveur-perdu.md (a suivre dans l'ordre).
#
# Sans --oui : repetition a blanc. Les controles sont FAITS pour de bon, rien n'est modifie.
# Dossier public : aucune valeur reelle ici. Tout vient de l'ENVIRONNEMENT et de
# /etc/axion-envoi/valeurs.env (hors depot). Un marqueur « <...> » encore present = refus.
#
# Variables d'environnement exigees (jamais de valeur par defaut) :
#   DOMAINES_ENVOI         domaines d'envoi separes par des virgules (ex. domaine-1.test)
#   SELECTEUR_PRINCIPAL    NOUVEAU selecteur DKIM (jamais un ancien : l'ancien est brule)
#   SELECTEUR_SECOURS      NOUVEAU selecteur de secours, different du principal
#   CONFIRME_ENVOIS_ARRETES=oui     les envois sont arretes cote CRM (etape 1 du runbook)
#   CONFIRME_HMAC_TOURNE=oui        le secret HMAC a ete change cote CRM (etape 2)
# Options : AXION_VALEURS (defaut /etc/axion-envoi/valeurs.env), REP_DKIM (defaut /etc/axion-envoi/dkim),
#   AXION_CIBLE (defaut /opt/axion-envoi, ou installer.sh pose le socle).
#   GROUPE_DKIM : NON necessaire (defaut axion-dkim, GID 10025, comme docker-compose.yml) ; reserve aux tests.
#
# Cles DKIM : le script cree le groupe axion-dkim (GID 10025) s'il manque, genere les cles en 0640
# root:axion-dkim (dossiers 0750), et VERIFIE/CORRIGE aussi celles deja presentes (une cle 0600 root
# est illisible pour KumoMTA : tout partirait en 451).
#
# Ce script ne REPREND JAMAIS l'envoi : mode temoins et reprise restent des gestes humains
# (etapes 5 et 8 du runbook). Aucun envoi reel (ENVOI_REEL_ACTIF reste faux).
#
# RELANCE. Apres une reconstruction COMPLETE (marque /etc/axion-envoi/.reconstruit) ou un echec AVANT
# que /opt/axion-envoi existe, relancer simplement --oui. Apres un ECHEC PARTIEL (socle pose, marque
# absente), la relance est REFUSEE : serveur « non vierge ». Contournement, a faire en conscience :
#   1. lire l'erreur et corriger sa cause ; ne JAMAIS supprimer de cle DKIM existante ;
#   2. verifier que c'est bien CE serveur neuf (aucun courrier, aucune file) ;
#   3. relancer avec AXION_FORCER_NON_VIERGE=oui (installer.sh est idempotent ; un domaine dont les
#      cles existent est saute, ses droits sont reverifies).
set -euo pipefail
set +x
umask 077

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VALEURS="${AXION_VALEURS:-/etc/axion-envoi/valeurs.env}"
REP_DKIM="${REP_DKIM:-/etc/axion-envoi/dkim}"
CIBLE="${AXION_CIBLE:-/opt/axion-envoi}"
GROUPE="${GROUPE_DKIM:-axion-dkim}"
REEL=0
case "${1:-}" in
  "") ;;
  --oui) REEL=1 ;;
  *) echo "usage : reconstruire.sh [--oui]" >&2; exit 2 ;;
esac
[[ $# -le 1 ]] || { echo "usage : reconstruire.sh [--oui]" >&2; exit 2; }

refus() { echo "REFUS : $*" >&2; exit 1; }
agir() { echo "+ $*"; if [[ $REEL == 1 ]]; then "$@"; fi; }
# shellcheck source=../lib/rendu.sh
source "$SRC/lib/rendu.sh"
# shellcheck source=droits-dkim.sh
source "$SRC/reconstruction/droits-dkim.sh"

# --- 1. Aucun marqueur laisse, aucune valeur absente (variables d'environnement).
for v in DOMAINES_ENVOI SELECTEUR_PRINCIPAL SELECTEUR_SECOURS CONFIRME_ENVOIS_ARRETES CONFIRME_HMAC_TOURNE; do
  [[ -n "${!v:-}" ]] || refus "variable d'environnement absente : $v"
  [[ "${!v}" != *'<'*'>'* ]] || refus "marqueur non remplace dans $v (valeur reelle attendue, fournie par l'environnement)"
done
[[ "$CONFIRME_ENVOIS_ARRETES" == oui ]] || refus "CONFIRME_ENVOIS_ARRETES doit valoir « oui » (arreter les envois cote CRM d'abord)"
[[ "$CONFIRME_HMAC_TOURNE" == oui ]] || refus "CONFIRME_HMAC_TOURNE doit valoir « oui » (changer le secret HMAC d'abord)"
sel_ok() { [[ "$1" =~ ^[a-z0-9]([a-z0-9-]{0,30}[a-z0-9])?$ ]]; }
sel_ok "$SELECTEUR_PRINCIPAL" || refus "SELECTEUR_PRINCIPAL invalide"
sel_ok "$SELECTEUR_SECOURS" || refus "SELECTEUR_SECOURS invalide"
[[ "$SELECTEUR_PRINCIPAL" != "$SELECTEUR_SECOURS" ]] || refus "les deux selecteurs doivent differer"
IFS=, read -r -a LISTE_DOMAINES <<<"$DOMAINES_ENVOI"
[[ ${#LISTE_DOMAINES[@]} -ge 1 ]] || refus "DOMAINES_ENVOI vide"
for d in "${LISTE_DOMAINES[@]}"; do
  [[ "$d" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$ && ${#d} -le 253 ]] || refus "domaine invalide dans DOMAINES_ENVOI"
done

# --- 2. Fichier de valeurs : lecture sans execution, forme verifiee, aucun marqueur.
[[ -r $VALEURS ]] || refus "fichier de valeurs absent : $VALEURS (voir RUNBOOK.md, « Installer »)"
if grep -Eq '<[a-z][a-z0-9-]*>' "$VALEURS"; then refus "marqueur non remplace dans $VALEURS"; fi
charger_valeurs "$VALEURS"
for v in IP_ENVOI IP_GESTION IP_CRM_PROD IP_RESOLVEUR IP_TUNNEL IP_TUNNEL_ADMIN PORT_WG CLE_PUBLIQUE_ADMIN; do
  [[ -n "${!v:-}" ]] || refus "valeur manquante dans $VALEURS : $v"
done
valider_valeurs || refus "valeurs non conformes (voir messages ci-dessus)"

# --- 3. Systeme : Ubuntu, root pour le vrai, machine VIERGE (ou deja reconstruite par ce script).
os_ubuntu || refus "Ubuntu requis"
if [[ $REEL == 1 ]]; then
  [[ $EUID -eq 0 ]] || refus "root requis pour --oui"
fi
ETAT=/etc/axion-envoi/.reconstruit
if [[ -d $CIBLE && ! -e $ETAT && "${AXION_FORCER_NON_VIERGE:-}" != oui ]]; then
  if [[ $REEL == 1 ]]; then
    refus "$CIBLE existe sans marque de reconstruction : serveur non vierge (ou echec partiel), rien n'est touche. Echec partiel de CE script : AXION_FORCER_NON_VIERGE=oui, precautions en tete du script et au runbook"
  fi
  echo "AVERTISSEMENT : $CIBLE existe sans marque de reconstruction : serveur non vierge ; --oui serait refuse (AXION_FORCER_NON_VIERGE=oui seulement apres un echec partiel de ce script)"
fi

echo "== controles passes ($([[ $REEL == 1 ]] && echo reel || echo 'a blanc')) : ${#LISTE_DOMAINES[@]} domaine(s) =="

# --- 4. Socle : l'installateur K3 (pare-feu, WireGuard d'administration, KumoMTA, DKIM-lua).
if [[ $REEL == 1 ]]; then
  AXION_VALEURS="$VALEURS" bash "$SRC/installer.sh" --oui
else
  echo "+ installer.sh --oui (a blanc : non lance ici ; lancer ./installer.sh sans option pour sa propre repetition)"
fi

# --- 5. Cles DKIM NOUVELLES, generees ICI (jamais importees). Groupe axion-dkim (GID 10025) cree si absent ;
# droits 0640/0750 poses par defaut ; domaine deja initialise = saute MAIS ses droits sont verifies/corriges.
agir install -d -m 0755 /etc/axion-envoi
groupe_dkim_assurer "$GROUPE" "$REEL" || exit 1
for d in "${LISTE_DOMAINES[@]}"; do
  if [[ -f "$REP_DKIM/$d/principal" ]]; then
    echo "= $d : cles DKIM deja presentes (droits verifies plus bas)"
    continue
  fi
  echo "+ generer-dkim.sh --domaine $d --selecteur $SELECTEUR_PRINCIPAL --secours $SELECTEUR_SECOURS --groupe $GROUPE"
  if [[ $REEL == 1 ]]; then
    bash "$SRC/dkim/generer-dkim.sh" --domaine "$d" --selecteur "$SELECTEUR_PRINCIPAL" \
      --secours "$SELECTEUR_SECOURS" --repertoire "$REP_DKIM" --groupe "$GROUPE"
  fi
done
droits_dkim_corriger "$REP_DKIM" "$GROUPE" "$REEL" || echo "A BLANC : des droits de cles DKIM sont a corriger (--oui les corrige)"

# --- 6. Marque de fin : la machine est reconstruite, JAMAIS remise en envoi par ce script.
if [[ $REEL == 1 ]]; then
  ( set -o noclobber; : >"$ETAT" ) 2>/dev/null || true
  chmod 0600 "$ETAT"
fi
cat <<'FIN'
== reconstruction terminee ; l'ENVOI N'EST PAS REPRIS ==
Suite (runbook) : les anciens selecteurs ont du etre retires du DNS AVANT (etape 3). Maintenant :
publier les TXT DKIM affiches et mettre PTR/SPF a jour, retirer l'ANCIENNE IP du SPF (etape 4),
remettre la passerelle (etape 4 bis, NON livree : dette K16-D4), mode temoins (5), rattraper les
evenements (6), traiter les oppositions (7), puis seulement reprendre apres feu vert ecrit (8).
FIN
