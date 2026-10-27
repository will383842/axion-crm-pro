#!/usr/bin/env bash
# ============================================================================
# Axion CRM Pro — La dernière copie Backblaze B2 est-elle récente et complète ?
# ============================================================================
# Rejoué par .github/workflows/surveillance-sauvegarde.yml (job verifier-b2),
# ou à la main sur le serveur : bash infra/scripts/verifier-sauvegarde-b2.sh
#
# Codes de sortie : 0 = OK ou « non configuré » ; 1 = copie trop vieille,
# trop petite, absente, ou B2 injoignable.
#   SEUIL_AGE_J     âge maximum en jours (9 : envoi hebdomadaire + marge)
#   SEUIL_TAILLE_MO taille minimale en Mo (100)
# Ne lit jamais BACKUP_ENCRYPTION_PASSPHRASE.
# ============================================================================

set -euo pipefail

if [ -f /opt/axion-crm-pro/.env ]; then
    set -a
    # shellcheck disable=SC1091
    source <(grep -E '^B2_' /opt/axion-crm-pro/.env)
    set +a
fi

B2_KEY_ID="${B2_KEY_ID:-}"
B2_APP_KEY="${B2_APP_KEY:-}"
B2_BUCKET="${B2_BUCKET:-}"
SEUIL_AGE_J="${SEUIL_AGE_J:-9}"
SEUIL_TAILLE_MO="${SEUIL_TAILLE_MO:-100}"

log() { echo "[verifier-b2] $*"; }

if [ -z "$B2_KEY_ID" ] || [ -z "$B2_APP_KEY" ] || [ -z "$B2_BUCKET" ]; then
    log "Copie B2 non configuré (B2_KEY_ID / B2_APP_KEY / B2_BUCKET absentes) — contrôle ignoré."
    exit 0
fi

for outil in curl jq; do
    command -v "$outil" >/dev/null 2>&1 || { log "❌ $outil introuvable"; exit 1; }
done

TMP="$(umask 077 && mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

printf 'user = "%s:%s"\n' "$B2_KEY_ID" "$B2_APP_KEY" > "$TMP/auth.cfg"
if ! curl -fsS --max-time 60 -K "$TMP/auth.cfg" https://api.backblazeb2.com/b2api/v3/b2_authorize_account > "$TMP/auth.json" 2>/dev/null; then
    log "❌ Autorisation B2 refusée ou API injoignable."
    exit 1
fi
API_URL="$(jq -r '.apiInfo.storageApi.apiUrl' "$TMP/auth.json")"
printf 'header = "Authorization: %s"\n' "$(jq -r '.authorizationToken' "$TMP/auth.json")" > "$TMP/token.cfg"

b2_post() { curl -fsS --max-time 60 -K "$TMP/token.cfg" -H 'Content-Type: application/json' -d "$2" "$API_URL/b2api/v3/$1"; }

BUCKET_ID="$(b2_post b2_list_buckets "$(jq -nc --arg a "$(jq -r '.accountId' "$TMP/auth.json")" --arg n "$B2_BUCKET" '{accountId:$a,bucketName:$n}')" \
    | jq -r '.buckets[0].bucketId // empty')"
if [ -z "$BUCKET_ID" ]; then
    log "❌ Bucket « $B2_BUCKET » introuvable avec cette clé."
    exit 1
fi

# Volume TOTAL du bucket (toutes versions, sans préfixe : c'est ce qui est facturé).
b2_post b2_list_file_versions "$(jq -nc --arg b "$BUCKET_ID" '{bucketId:$b,maxFileCount:1000}')" > "$TMP/versions.json"
TOTAL_OCTETS="$(jq '[.files[].contentLength] | add // 0' "$TMP/versions.json")"
DERNIERE="$(jq -c --arg p "axion-crm/" '[.files[] | select(.action == "upload" and (.fileName | startswith($p)))] | sort_by(.uploadTimestamp) | last // empty' "$TMP/versions.json")"
if [ -z "$DERNIERE" ]; then
    log "❌ Aucune copie dans le bucket B2."
    exit 1
fi

NOM="$(jq -r '.fileName' <<<"$DERNIERE")"
TAILLE_MO=$(( $(jq -r '.contentLength' <<<"$DERNIERE") / 1048576 ))
AGE_J=$(( ($(date -u +%s) - $(jq -r '.uploadTimestamp' <<<"$DERNIERE") / 1000) / 86400 ))
log "Dernière copie : $NOM — ${TAILLE_MO} Mo, âgée de ${AGE_J} jour(s)."

CODE=0
if [ "$TOTAL_OCTETS" -gt 8000000000 ]; then
    log "❌ Bucket B2 à $((TOTAL_OCTETS / 1048576)) Mo : au-delà de 8 Go (10 Go gratuits pour tout le compte)."
    CODE=1
fi
if [ "$AGE_J" -ge "$SEUIL_AGE_J" ]; then
    log "❌ Copie trop vieille : ${AGE_J} j (seuil : moins de ${SEUIL_AGE_J} j)."
    CODE=1
fi
if [ "$TAILLE_MO" -le "$SEUIL_TAILLE_MO" ]; then
    log "❌ Copie trop petite : ${TAILLE_MO} Mo (seuil : plus de ${SEUIL_TAILLE_MO} Mo)."
    CODE=1
fi
[ "$CODE" -eq 0 ] && log "✅ Copie B2 récente et de taille plausible."
exit "$CODE"
