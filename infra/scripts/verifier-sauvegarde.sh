#!/usr/bin/env bash
# =============================================================================
# Axion CRM Pro — la sauvegarde d'hier soir a-t-elle vraiment eu lieu ?
# =============================================================================
# ⚠️ POURQUOI CE SCRIPT EXISTE
#
# Le 2026-08-16 on a découvert que la sauvegarde de production n'avait JAMAIS
# tourné : 91 exécutions cron, 91 échecs, pendant trois mois. Le correctif
# (#136) a réparé la sauvegarde. Il n'a PAS réparé la raison pour laquelle
# personne ne s'en était aperçu : cron écrit dans `/var/log/axion-backup.log`,
# que personne ne lit.
#
# Tant qu'aucune garde ne REGARDE, la prochaine panne sera aussi silencieuse
# que la précédente — mise à jour de `sshpass`, Storage Box pleine, disque
# saturé, conteneur Postgres renommé : les façons de casser ne manquent pas.
#
# ---------------------------------------------------------------------------
# CE QU'IL VÉRIFIE, ET POURQUOI CHAQUE POINT COMPTE
# ---------------------------------------------------------------------------
#   1. la copie HORS-SITE existe
#      → c'est elle qui sauve si le serveur brûle ; la copie locale ne prouve
#        rien sur ce scénario ;
#   2. elle est RÉCENTE (≤ 36 h, comme le RPO toléré par dr-drill.sh)
#      → une sauvegarde de la semaine dernière n'est pas une sauvegarde ;
#   3. elle est GROSSE (≥ 100 Mo)
#      → 🔑 le point le moins évident. Pendant trois mois la Storage Box
#        contenait bien un fichier `axion_crm_*.sql.gz` : **18 497 octets**.
#        Une garde qui se contente de « le fichier existe » aurait été VERTE
#        tout du long. Le dump réel pèse ~692 Mo.
#
# Un échec sort en code 1 avec un message explicite : c'est le workflow
# `surveillance-sauvegarde.yml` qui le transforme en issue GitHub.
#
# Usage (sur le SERVEUR) :
#   bash /opt/axion-crm-pro/infra/scripts/verifier-sauvegarde.sh
#
# Les deux seuils sont réglables — non pas par confort, mais pour pouvoir
# PROUVER que la garde rougit :
#   SEUIL_AGE_H=0 bash …          → doit échouer (trop vieux)
#   SEUIL_TAILLE_MO=999999 bash … → doit échouer (trop petit)
# Une garde qu'on n'a jamais vue rouge ne garde rien.
# =============================================================================

set -euo pipefail

RACINE="${RACINE:-/opt/axion-crm-pro}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/axion-crm}"

SEUIL_AGE_H="${SEUIL_AGE_H:-36}"
SEUIL_TAILLE_MO="${SEUIL_TAILLE_MO:-100}"

# Charge SB_* depuis le .env du serveur (jamais versionné).
if [ -f "$RACINE/.env" ]; then
    set -a
    # shellcheck disable=SC1090
    source <(grep -E '^SB_' "$RACINE/.env")
    set +a
fi

SB_HOST="${SB_HOST:-u595329.your-storagebox.de}"
SB_USER="${SB_USER:-u595329}"
SB_PORT="${SB_PORT:-23}"
SB_PATH="${SB_PATH:-/home/axion-crm-backups}"

echoerr() { echo "$@" >&2; }

if [ -z "${SB_PASSWORD:-}" ]; then
    echoerr "❌ SB_PASSWORD introuvable dans $RACINE/.env — impossible de vérifier le hors-site."
    exit 1
fi
if ! command -v sshpass >/dev/null 2>&1; then
    echoerr "❌ sshpass absent du serveur — impossible d'interroger la Storage Box."
    exit 1
fi

echo "=== Vérification des sauvegardes — $(date -u +%FT%TZ) ==="
echo "    seuils : âge ≤ ${SEUIL_AGE_H} h, taille ≥ ${SEUIL_TAILLE_MO} Mo"

# --- Inventaire hors-site ----------------------------------------------------
# `ls -1` et NON `ls` : `ls` affiche en colonnes complétées d'espaces, ce qui a
# déjà cassé la rotation en silence (cf. #139). Une entrée par ligne.
INVENTAIRE=$(sshpass -p "$SB_PASSWORD" sftp -P "$SB_PORT" \
    -o StrictHostKeyChecking=accept-new -o ConnectTimeout=30 \
    "$SB_USER@$SB_HOST" <<EOF 2>/dev/null | grep -E 'axion_crm_[0-9]+T[0-9]+Z\.sql\.gz$' || true
cd $SB_PATH
ls -l
EOF
)

if [ -z "$INVENTAIRE" ]; then
    echoerr "❌ AUCUNE sauvegarde sur la Storage Box ($SB_HOST:$SB_PATH)."
    echoerr "   La copie hors-site est le seul recours si le serveur est perdu."
    exit 1
fi

# La plus récente : le nom porte un horodatage UTC triable lexicographiquement.
DERNIERE_LIGNE=$(printf '%s\n' "$INVENTAIRE" | sort -k9 | tail -1)
NOM=$(printf '%s' "$DERNIERE_LIGNE" | awk '{print $NF}')
TAILLE_O=$(printf '%s' "$DERNIERE_LIGNE" | awk '{print $5}')

echo "    dernière archive hors-site : $NOM"

# --- Âge ---------------------------------------------------------------------
HORO=$(printf '%s' "$NOM" | sed -nE 's/^axion_crm_([0-9]{8})T([0-9]{6})Z\.sql\.gz$/\1 \2/p')
if [ -z "$HORO" ]; then
    echoerr "❌ Nom d'archive inattendu, horodatage illisible : $NOM"
    exit 1
fi
D=${HORO% *}; H=${HORO#* }
INSTANT="${D:0:4}-${D:4:2}-${D:6:2} ${H:0:2}:${H:2:2}:${H:4:2} UTC"
EPOCH_ARCHIVE=$(date -u -d "$INSTANT" +%s)
AGE_H=$(( ( $(date -u +%s) - EPOCH_ARCHIVE ) / 3600 ))

# --- Taille ------------------------------------------------------------------
TAILLE_MO=$(( TAILLE_O / 1048576 ))

echo "    âge    : ${AGE_H} h"
echo "    taille : ${TAILLE_MO} Mo (${TAILLE_O} octets)"

ECHECS=0

if [ "$AGE_H" -gt "$SEUIL_AGE_H" ]; then
    echoerr "❌ ÂGE : la dernière sauvegarde hors-site a ${AGE_H} h (> ${SEUIL_AGE_H} h)."
    echoerr "   La sauvegarde quotidienne de 03:00 UTC ne produit plus rien."
    ECHECS=$((ECHECS + 1))
fi

if [ "$TAILLE_MO" -lt "$SEUIL_TAILLE_MO" ]; then
    echoerr "❌ TAILLE : ${TAILLE_MO} Mo (< ${SEUIL_TAILLE_MO} Mo attendus)."
    echoerr "   Un fichier présent mais minuscule n'est PAS une sauvegarde :"
    echoerr "   pendant trois mois la Storage Box en a hébergé un de 18 497 octets."
    ECHECS=$((ECHECS + 1))
fi

# --- Copie locale (secondaire, mais elle situe la panne) ---------------------
# Si le local est frais et le hors-site vieux, c'est l'ENVOI qui casse ; si les
# deux sont vieux, c'est le dump. Le diagnostic vaut d'être posé tout de suite.
LOCALE=$(ls -1t "$BACKUP_DIR"/axion_crm_*.sql.gz 2>/dev/null | head -1 || true)
if [ -z "$LOCALE" ]; then
    echo "    ⚠️  aucune copie locale dans $BACKUP_DIR (rotation à 7 j — anormal si le cron tourne)"
else
    AGE_LOCALE_H=$(( ( $(date -u +%s) - $(stat -c %Y "$LOCALE") ) / 3600 ))
    echo "    copie locale la plus récente : $(basename "$LOCALE") (${AGE_LOCALE_H} h)"
    if [ "$AGE_H" -gt "$SEUIL_AGE_H" ] && [ "$AGE_LOCALE_H" -le "$SEUIL_AGE_H" ]; then
        echoerr "   → le dump local est frais mais pas le hors-site : c'est l'ENVOI qui échoue."
    fi
fi

if [ "$ECHECS" -gt 0 ]; then
    echoerr ""
    echoerr "❌ $ECHECS contrôle(s) en échec — la sauvegarde de production n'est pas fiable."
    exit 1
fi

echo "✅ Sauvegarde hors-site présente, récente et de taille plausible."
