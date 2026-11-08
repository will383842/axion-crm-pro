#!/usr/bin/env bash
# K5 : genere SUR LE SERVEUR D'ENVOI les cles DKIM RSA 2048 (selecteur principal + selecteur
# de secours publie d'avance, pour une rotation sans interruption) et sort les TXT publics.
# La cle privee reste dans un fichier 0600 (0640 avec --groupe), jamais affichee, jamais
# journalisee, jamais dans le depot, jamais dans une variable ni un fichier temporaire.
# Ne remplace JAMAIS une cle existante.
#
# Creation  : generer-dkim.sh --domaine D --selecteur S --secours S2 [--repertoire R] [--groupe G]
# Rotation  : generer-dkim.sh --rotation --domaine D --secours NOUVEAU [--repertoire R] [--groupe G]
#             (le secours devient principal, UN nouveau secours est genere, le principal actuel
#              n'est pas regenere ; l'ancienne cle principale reste sur disque jusqu'a purge manuelle)
#
# Dossiers : R (defaut /etc/axion-envoi/dkim) ne recoit un chmod QUE si ce script le cree ;
# un R existant doit etre vide ou porter le marqueur .axion-dkim. R/D est cree ici (mkdir
# atomique = verrou contre la concurrence) en 0700 (0750 avec --groupe).
set -euo pipefail
set +x
umask 077
set -o noclobber

DIR_SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REP=/etc/axion-envoi/dkim
DOM=""; SEL=""; SEC=""; GRP=""; ROT=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --domaine) DOM="${2:-}"; shift 2 ;;
    --selecteur) SEL="${2:-}"; shift 2 ;;
    --secours) SEC="${2:-}"; shift 2 ;;
    --repertoire) REP="${2:-}"; shift 2 ;;
    --groupe) GRP="${2:-}"; shift 2 ;;
    --rotation) ROT=1; shift ;;
    --) shift; break ;;
    *) echo "argument inconnu : $1" >&2; exit 2 ;;
  esac
done
[[ $# -eq 0 ]] || { echo "argument inconnu : $1" >&2; exit 2; }

# Domaine : 253 caracteres au plus, libelles de 63 au plus (RFC 1035).
[[ ${#DOM} -le 253 && "$DOM" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$ ]] || { echo "domaine invalide" >&2; exit 2; }
sel_ok() { [[ "$1" =~ ^[a-z0-9]([a-z0-9-]{0,30}[a-z0-9])?$ ]]; }
sel_ok "$SEC" || { echo "selecteur invalide" >&2; exit 2; }
if [[ $ROT == 0 ]]; then
  sel_ok "$SEL" || { echo "selecteur invalide" >&2; exit 2; }
  [[ "$SEL" != "$SEC" ]] || { echo "le selecteur de secours doit differer du principal" >&2; exit 2; }
elif [[ -n "$SEL" ]]; then
  echo "--selecteur interdit avec --rotation (le principal est le secours actuel)" >&2; exit 2
fi
# Nom DNS complet (selecteur + « ._domainkey. » + domaine) <= 253.
for s in "$SEL" "$SEC"; do
  [[ -z "$s" || $(( ${#s} + 12 + ${#DOM} )) -le 253 ]] || { echo "nom DNS trop long" >&2; exit 2; }
done
[[ -n "$REP" && "$REP" != -* ]] || { echo "repertoire invalide (écrire ./-nom pour un nom commencant par « - »)" >&2; exit 2; }
if [[ -n "$GRP" ]]; then
  [[ "$GRP" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] && getent group -- "$GRP" >/dev/null || { echo "groupe inconnu ou invalide" >&2; exit 2; }
  M_CLE=0640; M_DOS=0750
else
  M_CLE=0600; M_DOS=0700
fi
command -v openssl >/dev/null || { echo "openssl requis" >&2; exit 1; }

# ---- nettoyage : tout ce que CE lancement a cree disparait s'il echoue (jamais de cle a moitie).
CREES=(); DOSSIERS_CREES=()
nettoyer() {
  local rc=$? f i
  if [[ $rc != 0 ]]; then
    for ((i=${#CREES[@]}-1; i>=0; i--)); do f="${CREES[$i]}"; [[ -z "$f" ]] || rm -f -- "$f"; done
    for ((i=${#DOSSIERS_CREES[@]}-1; i>=0; i--)); do rmdir -- "${DOSSIERS_CREES[$i]}" 2>/dev/null || true; done
  fi
  for f in "${TEMP_OK[@]:-}"; do [[ -z "$f" ]] || rm -f -- "$f"; done   # M1 : succes OU echec
  [[ -z "${VERROU:-}" ]] || rmdir -- "$VERROU" 2>/dev/null || true
  exit "$rc"
}
TEMP_OK=()
trap nettoyer EXIT

droits() { # droits <mode> <chemin> : mode + groupe
  chmod -- "$1" "$2"; [[ -z "$GRP" ]] || chgrp -- "$GRP" "$2"
}
nouveau_fichier() { # nouveau_fichier <chemin> : cree EXCLUSIVEMENT (noclobber), enregistre pour nettoyage
  [[ ! -e "$1" ]] || { echo "refus d'ecraser : $1" >&2; exit 1; }
  { : >"$1"; } 2>/dev/null || { echo "refus d'ecraser (concurrence) : $1" >&2; exit 1; }
  CREES+=("$1")
}

# ---- repertoire racine : on ne touche QUE ce que l'on cree.
PARENT="$(dirname -- "$REP")"
[[ -d "$PARENT" ]] || { echo "dossier parent absent : $PARENT (le creer d'abord, voir RUNBOOK)" >&2; exit 1; }
if [[ -e "$REP" ]]; then
  [[ -d "$REP" && ! -L "$REP" ]] || { echo "repertoire invalide : $REP" >&2; exit 1; }
  if [[ ! -f "$REP/.axion-dkim" && -n "$(ls -A -- "$REP")" ]]; then
    echo "repertoire existant non vide et non cree par ce script : refuse (chemin dedie requis)" >&2; exit 1
  fi
else
  mkdir -- "$REP"; DOSSIERS_CREES+=("$REP")
  droits "$M_DOS" "$REP"
fi
[[ -f "$REP/.axion-dkim" ]] || { nouveau_fichier "$REP/.axion-dkim"; printf 'dossier gere par generer-dkim.sh\n' >|"$REP/.axion-dkim"; chmod -- 0644 "$REP/.axion-dkim"; }

DOSSIER="$REP/$DOM"
if [[ $ROT == 0 ]]; then
  mkdir -- "$DOSSIER" 2>/dev/null || { echo "domaine deja initialise (ou concurrence) : utiliser --rotation" >&2; exit 1; }
  DOSSIERS_CREES+=("$DOSSIER")
  droits "$M_DOS" "$DOSSIER"
else
  [[ -d "$DOSSIER" && -f "$DOSSIER/principal" && -f "$DOSSIER/secours" ]] || { echo "domaine non initialise : rien a faire tourner" >&2; exit 1; }
  VERROU="$DOSSIER/.verrou"
  mkdir -- "$VERROU" 2>/dev/null || { echo "rotation deja en cours" >&2; unset VERROU; exit 1; }
  # I2 : en rotation, mode et groupe sont repris du dossier existant (jamais 0600 root par defaut).
  MODE_EXIST="$(stat -c %a -- "$DOSSIER")"; GRP_EXIST="$(stat -c %G -- "$DOSSIER")"
  if [[ "$MODE_EXIST" == 750 ]]; then
    if [[ -n "$GRP" && "$GRP" != "$GRP_EXIST" ]]; then
      echo "incoherence : --groupe $GRP different du groupe du dossier ($GRP_EXIST)" >&2; exit 1
    fi
    GRP="$GRP_EXIST"; M_CLE=0640; M_DOS=0750
  elif [[ "$MODE_EXIST" == 700 ]]; then
    [[ -z "$GRP" ]] || { echo "incoherence : le domaine existe sans groupe (0700), --groupe refuse" >&2; exit 1; }
    M_CLE=0600; M_DOS=0700
  else
    echo "mode du dossier inattendu ($MODE_EXIST) : refus" >&2; exit 1
  fi
  ANC_PRINC="$(<"$DOSSIER/principal")"; ANC_SEC="$(<"$DOSSIER/secours")"
  [[ "$SEC" != "$ANC_PRINC" && "$SEC" != "$ANC_SEC" ]] || { echo "ce selecteur est deja utilise" >&2; exit 1; }
  SEL="$ANC_SEC"
fi
[[ ! -e "$DOSSIER/$SEC.pem" ]] || { echo "cle deja presente pour $SEC : refus d'ecraser" >&2; exit 1; }

# Coupe en chaines de 255 octets au plus (meme regle que PlanDns::decouperTxt).
decouper() { # decouper <valeur>
  local v="$1" sortie=""
  while [[ -n "$v" ]]; do sortie+="\"${v:0:255}\" "; v="${v:255}"; done
  printf '%s' "${sortie% }"
}

h=""
while IFS= read -r l; do [[ -z "$l" || "$l" == \#* ]] && continue; h+="${h:+:}${l,,}"; done <"$DIR_SCRIPT/entetes-signes.txt"
while IFS= read -r l; do [[ -z "$l" || "$l" == \#* ]] && continue; h+=":${l,,}"; done <"$DIR_SCRIPT/entetes-sursignes.txt"

generer() { # generer <selecteur> : cle ecrite directement dans son fichier final (jamais en variable)
  local s="$1" pem="$DOSSIER/$1.pem" txtf="$DOSSIER/$1._domainkey.txt" pub txt nom
  nouveau_fichier "$pem"
  openssl genrsa 2048 2>/dev/null >|"$pem"
  [[ -s "$pem" ]] || { echo "echec de generation de la cle $s" >&2; exit 1; }
  droits "$M_CLE" "$pem"
  pub="$(openssl rsa -in "$pem" -pubout -outform DER 2>/dev/null | base64 -w0)"
  [[ -n "$pub" ]] || { echo "cle publique illisible pour $s" >&2; exit 1; }
  txt="$(decouper "v=DKIM1; k=rsa; p=$pub")"
  nom="$s._domainkey.$DOM."                 # point final : nom absolu
  nouveau_fichier "$txtf"
  printf '%s IN TXT %s\n' "$nom" "$txt" >|"$txtf"
  chmod -- 0644 "$txtf"
  echo "$nom IN TXT $txt"
}

if [[ $ROT == 0 ]]; then
  generer "$SEL"; generer "$SEC"
  nouveau_fichier "$DOSSIER/principal"; printf '%s\n' "$SEL" >|"$DOSSIER/principal"; droits 0644 "$DOSSIER/principal"
  nouveau_fichier "$DOSSIER/secours";   printf '%s\n' "$SEC" >|"$DOSSIER/secours";   droits 0644 "$DOSSIER/secours"
else
  generer "$SEC"
  # bascule : fichiers d'etat (un nom de selecteur, aucun secret) ecrits puis renommes
  printf '%s\n' "$SEL" >|"$DOSSIER/.principal.nouveau"; TEMP_OK+=("$DOSSIER/.principal.nouveau")
  printf '%s\n' "$SEC" >|"$DOSSIER/.secours.nouveau";   TEMP_OK+=("$DOSSIER/.secours.nouveau")
  chmod -- 0644 "$DOSSIER/.principal.nouveau" "$DOSSIER/.secours.nouveau"
  mv -f -- "$DOSSIER/.principal.nouveau" "$DOSSIER/principal"
  mv -f -- "$DOSSIER/.secours.nouveau" "$DOSSIER/secours"
fi
CREES=()   # succes : plus rien a defaire
echo "signer avec : s=$SEL (principal) ; secours publie d'avance : s=$SEC ; h=$h"
echo "cles privees : $DOSSIER/*.pem ($M_CLE, non affichees)"
