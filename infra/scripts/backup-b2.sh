#!/usr/bin/env bash
# ============================================================================
# Axion CRM Pro — Seconde copie HORS-SITE de la sauvegarde → Backblaze B2
# ============================================================================
# Envoie, UNE FOIS PAR SEMAINE (dimanche), la DERNIÈRE archive locale chiffrée
# `axion_crm_<horodatage>.sql.gz.enc` (produite par backup-postgres.sh, déjà
# chiffrée AES-256) vers un bucket Backblaze B2. Coût NUL : 10 Go gratuits.
#
# ⚠️ COMPTE B2 DÉDIÉ, AUCUN AUTRE BUCKET : les 10 Go gratuits valent pour TOUT
# le compte, pas par bucket. Le plafond ci-dessous ne voit que ce bucket.
#
# Choix de l'outil : `curl` + API NATIVE de B2 (+ `jq` pour lire le JSON).
#  · curl est déjà sur le serveur et sert déjà aux scripts du dépôt ; `jq` est
#    un paquet apt de la distribution (installé par setup-backup-b2.sh).
#  · pas de binaire tiers téléchargé, donc aucune empreinte à vérifier ni à
#    maintenir (rclone aurait imposé les deux) ;
#  · l'API native garantit l'intégrité : l'envoi porte le SHA-1 du fichier et
#    B2 le refuse s'il ne correspond pas ; on le recontrôle dans la réponse.
#
# Garde-fous :
#  · B2_KEY_ID / B2_APP_KEY / B2_BUCKET absentes → « non configuré », code 0,
#    pour ne pas casser le cron avant la pose des clés ;
#  · PLAFOND DUR : si le bucket dépasserait 9 Go après envoi, rien n'est envoyé
#    et le script sort en erreur (le coût doit rester NUL). Les copies que la
#    rotation supprimerait de toute façon (au-delà des 3 plus récentes) sont
#    purgées AVANT l'envoi si le plafond l'exige — jamais en dessous de 3 ;
#  · refuse une archive locale de plus de 2 jours (sauvegarde quotidienne en panne) ;
#  · on garde les 4 dernières copies ; les plus anciennes sont supprimées
#    UNIQUEMENT dans le bucket B2 (jamais en local, jamais sur la Storage Box) ;
#  · ne lit ni n'affiche jamais BACKUP_ENCRYPTION_PASSPHRASE ni les clés B2.
#
# Usage : bash /opt/axion-crm-pro/infra/scripts/backup-b2.sh
#   FORCE_B2=1 pour envoyer hors dimanche (test manuel).
# ============================================================================

set -euo pipefail

# Le motif ne couvre QUE les clés B2 : la passphrase de chiffrement n'est pas lue.
if [ -f /opt/axion-crm-pro/.env ]; then
    set -a
    # shellcheck disable=SC1091
    source <(grep -E '^B2_' /opt/axion-crm-pro/.env)
    set +a
fi

BACKUP_DIR="${BACKUP_DIR:-/var/backups/axion-crm}"
B2_KEY_ID="${B2_KEY_ID:-}"
B2_APP_KEY="${B2_APP_KEY:-}"
B2_BUCKET="${B2_BUCKET:-}"
B2_PREFIX="axion-crm/"
B2_COPIES_A_GARDER=4
B2_PLAFOND_OCTETS=9000000000   # 9 Go : 1 Go de marge sous les 10 Go gratuits
B2_AUTH_URL="https://api.backblazeb2.com/b2api/v3/b2_authorize_account"

log() { echo "[backup-b2] $*"; }

# --- 1. Configuré ? ---
if [ -z "$B2_KEY_ID" ] || [ -z "$B2_APP_KEY" ] || [ -z "$B2_BUCKET" ]; then
    log "B2 non configuré (B2_KEY_ID / B2_APP_KEY / B2_BUCKET absentes de .env) — rien à faire."
    exit 0
fi

# --- 2. Hebdomadaire : dimanche seulement ---
if [ "$(date -u +%u)" != "7" ] && [ "${FORCE_B2:-0}" != "1" ]; then
    log "Pas dimanche — envoi hebdomadaire ignoré (FORCE_B2=1 pour forcer)."
    exit 0
fi

for outil in curl jq sha1sum stat; do
    command -v "$outil" >/dev/null 2>&1 || { log "❌ $outil introuvable (apt install -y $outil)"; exit 1; }
done

# --- 3. Dernière archive locale ---
ARCHIVE="$(find "$BACKUP_DIR" -maxdepth 1 -type f -name 'axion_crm_*.sql.gz.enc' -printf '%T@ %p\n' 2>/dev/null | sort -rn | head -1 | cut -d' ' -f2-)"
if [ -z "$ARCHIVE" ]; then
    log "❌ Aucune archive axion_crm_*.sql.gz.enc dans $BACKUP_DIR"
    exit 1
fi
NOM="$(basename "$ARCHIVE")"
if [ -z "$(find "$ARCHIVE" -maxdepth 0 -mmin -2880 2>/dev/null)" ]; then
    log "❌ Archive locale $NOM de plus de 2 jours : la sauvegarde quotidienne est en panne, rien n'est envoyé."
    exit 1
fi
TAILLE="$(stat -c%s "$ARCHIVE")"
log "Archive retenue : $NOM ($((TAILLE / 1048576)) Mo)"

# Les clés ne transitent jamais par la ligne de commande (visible dans `ps`) :
# curl lit sa configuration dans un fichier 0600, supprimé en sortie.
TMP="$(umask 077 && mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

b2_curl() { curl -fsS --max-time 3600 "$@"; }

# --- 4. Autorisation ---
printf 'user = "%s:%s"\n' "$B2_KEY_ID" "$B2_APP_KEY" > "$TMP/auth.cfg"
if ! b2_curl -K "$TMP/auth.cfg" "$B2_AUTH_URL" > "$TMP/auth.json" 2>/dev/null; then
    log "❌ Autorisation B2 refusée (clés invalides ou API injoignable)."
    exit 1
fi
API_URL="$(jq -r '.apiInfo.storageApi.apiUrl' "$TMP/auth.json")"
ACCOUNT_ID="$(jq -r '.accountId' "$TMP/auth.json")"
printf 'header = "Authorization: %s"\n' "$(jq -r '.authorizationToken' "$TMP/auth.json")" > "$TMP/token.cfg"

b2_post() { # b2_post <opération> <json>
    b2_curl -K "$TMP/token.cfg" -H 'Content-Type: application/json' -d "$2" "$API_URL/b2api/v3/$1"
}

BUCKET_ID="$(b2_post b2_list_buckets "$(jq -nc --arg a "$ACCOUNT_ID" --arg n "$B2_BUCKET" '{accountId:$a,bucketName:$n}')" \
    | jq -r '.buckets[0].bucketId // empty')"
if [ -z "$BUCKET_ID" ]; then
    log "❌ Bucket B2 « $B2_BUCKET » introuvable avec cette clé."
    exit 1
fi

# --- 5. Inventaire de TOUTES les versions du bucket (c'est ce qui est facturé) ---
b2_post b2_list_file_versions "$(jq -nc --arg b "$BUCKET_ID" '{bucketId:$b,maxFileCount:1000}')" > "$TMP/versions.json"
if [ "$(jq -r '.nextFileName // empty' "$TMP/versions.json")" != "" ]; then
    log "❌ Plus de 1000 versions dans le bucket : inventaire incomplet, abandon par prudence."
    exit 1
fi
TOTAL="$(jq '[.files[].contentLength] | add // 0' "$TMP/versions.json")"

if jq -e --arg n "$B2_PREFIX$NOM" '.files[] | select(.action == "upload" and .fileName == $n)' "$TMP/versions.json" >/dev/null; then
    log "✅ $NOM est déjà dans le bucket — rien à envoyer."
    exit 0
fi

# --- 6. PLAFOND DUR ---
# Après l'envoi, la rotation ne garde que $B2_COPIES_A_GARDER copies : seules les
# (COPIES-1) plus récentes déjà présentes survivent. Les autres sont déjà
# condamnées ; on les purge AVANT l'envoi si le plafond l'exige, et seulement
# s'il en reste au moins COPIES-1 (=3) ensuite. Le plafond reste dur.
GARDEES_AVANT=$((B2_COPIES_A_GARDER - 1))
jq -r --argjson k "$GARDEES_AVANT" --arg p "$B2_PREFIX" \
    '[.files[] | select(.action == "upload" and (.fileName | startswith($p)))] | sort_by(.uploadTimestamp) | reverse
     | . as $tous | ($tous | length) as $n | if $n > $k then .[$k:][] | "\(.fileId)\t\(.fileName)\t\(.contentLength)" else empty end' \
    "$TMP/versions.json" > "$TMP/purge_avant.tsv"
CONDAMNE=0
while IFS=$'\t' read -r _ _ LONG; do
    [ -n "$LONG" ] && CONDAMNE=$((CONDAMNE + LONG))
done < "$TMP/purge_avant.tsv"

if [ $((TOTAL + TAILLE)) -gt "$B2_PLAFOND_OCTETS" ]; then
    if [ -s "$TMP/purge_avant.tsv" ] && [ $((TOTAL - CONDAMNE + TAILLE)) -le "$B2_PLAFOND_OCTETS" ]; then
        while IFS=$'\t' read -r FILE_ID FILE_NAME _; do
            [ -n "$FILE_ID" ] || continue
            log "Plafond : purge préalable de l'ancienne copie $FILE_NAME (hors des $GARDEES_AVANT plus récentes conservées)"
            b2_post b2_delete_file_version "$(jq -nc --arg i "$FILE_ID" --arg n "$FILE_NAME" '{fileId:$i,fileName:$n}')" >/dev/null
        done < "$TMP/purge_avant.tsv"
        TOTAL=$((TOTAL - CONDAMNE))
    else
        log "❌ PLAFOND : bucket à $((TOTAL / 1048576)) Mo + $((TAILLE / 1048576)) Mo dépasserait $((B2_PLAFOND_OCTETS / 1000000)) Mo, même après rotation."
        log "   Rien n'est envoyé (coût nul exigé). Vider ou purger le bucket B2 à la main, ou relever le plafond en connaissance de cause."
        exit 1
    fi
fi

# --- 7. Envoi + contrôle SHA-1 / taille ---
SHA1="$(sha1sum "$ARCHIVE" | cut -d' ' -f1)"
b2_post b2_get_upload_url "$(jq -nc --arg b "$BUCKET_ID" '{bucketId:$b}')" > "$TMP/upload.json"
UPLOAD_URL="$(jq -r '.uploadUrl' "$TMP/upload.json")"
printf 'header = "Authorization: %s"\n' "$(jq -r '.authorizationToken' "$TMP/upload.json")" > "$TMP/upload.cfg"

log "Envoi vers le bucket B2…"
if ! b2_curl -K "$TMP/upload.cfg" \
        -H "X-Bz-File-Name: $B2_PREFIX$NOM" \
        -H 'Content-Type: b2/x-auto' \
        -H "X-Bz-Content-Sha1: $SHA1" \
        -T "$ARCHIVE" "$UPLOAD_URL" > "$TMP/resultat.json"; then
    log "❌ Envoi B2 en échec."
    exit 1
fi
if [ "$(jq -r '.contentSha1' "$TMP/resultat.json")" != "$SHA1" ] \
    || [ "$(jq -r '.contentLength' "$TMP/resultat.json")" != "$TAILLE" ]; then
    log "❌ Contrôle après envoi : SHA-1 ou taille différents de l'archive locale."
    exit 1
fi
log "✅ Envoyé et vérifié (SHA-1 + taille) : $B2_PREFIX$NOM"

# --- 8. Rotation : 4 dernières copies, SEULEMENT dans B2 ---
b2_post b2_list_file_versions "$(jq -nc --arg b "$BUCKET_ID" --arg p "$B2_PREFIX" '{bucketId:$b,prefix:$p,maxFileCount:1000}')" > "$TMP/apres.json"
jq -r --argjson k "$B2_COPIES_A_GARDER" \
    '[.files[] | select(.action == "upload")] | sort_by(.uploadTimestamp) | reverse | .[$k:][] | "\(.fileId)\t\(.fileName)"' \
    "$TMP/apres.json" > "$TMP/a_supprimer.tsv"
while IFS=$'\t' read -r FILE_ID FILE_NAME; do
    [ -n "$FILE_ID" ] || continue
    log "Rotation B2 : suppression de l'ancienne copie $FILE_NAME"
    b2_post b2_delete_file_version "$(jq -nc --arg i "$FILE_ID" --arg n "$FILE_NAME" '{fileId:$i,fileName:$n}')" >/dev/null
done < "$TMP/a_supprimer.tsv"

log "Terminé."
