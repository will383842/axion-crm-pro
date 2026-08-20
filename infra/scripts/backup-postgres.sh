#!/usr/bin/env bash
# ============================================================================
# Axion CRM Pro — Backup quotidien Postgres → Hetzner Storage Box
# ============================================================================
# Workflow :
# 1) pg_dump compressé (gzip)
# 2) scp vers Storage Box (sshpass auth)
# 3) Rotation locale 7j (find côté serveur, OK)
# 4) Rotation distante 30j (sftp - 'rm' commands, Storage Box n'a pas find)
#
# Lancé via cron (cf. setup-backup.sh) ou manuellement :
#   bash /opt/axion-crm-pro/infra/scripts/backup-postgres.sh
# ============================================================================

set -euo pipefail

# Charge .env pour SB_PASSWORD
if [ -f /opt/axion-crm-pro/.env ]; then
    set -a
    # shellcheck disable=SC1091
    source <(grep -E '^SB_' /opt/axion-crm-pro/.env)
    set +a
fi

# --- Config ---
DB_CONTAINER="${DB_CONTAINER:-axion-crm-postgres}"
DB_USER="${DB_USER:-axion}"
DB_NAME="${DB_NAME:-axion_crm}"

# ── LES RÔLES ET LES DROITS — constat A08-008 (S1) ──────────────────────────
#
# Le rôle applicatif, non-propriétaire, par lequel l'application se connecte dès
# que `CRM_DB_APP_ROLE_ENABLED` vaut vrai (migration
# `2026_08_14_000001_harden_workspace_isolation`). Il sert ici à une chose :
# vérifier que l'archive produite contient bien de quoi le recréer.
DB_APP_USER="${DB_APP_USER:-axion_app}"

# ⚠️ CES DEUX MARQUEURS SONT UN CONTRAT ENTRE TROIS SCRIPTS.
# `restore-postgres.sh` et `dr-drill.sh` les cherchent à l'identique pour
# isoler la section des rôles du reste de l'archive. Les changer ici sans les
# propager là-bas casserait la restauration en silence — et on ne s'en
# apercevrait qu'un jour de sinistre.
# Garde : `backend/tests/Feature/Infra/SauvegardeRestaureLesDroitsTest.php`.
MARQUEUR_GLOBALS_DEBUT="-- >>> AXION-GLOBALS-DEBUT"
MARQUEUR_GLOBALS_FIN="-- >>> AXION-GLOBALS-FIN"

SB_HOST="${SB_HOST:-u595329.your-storagebox.de}"
SB_USER="${SB_USER:-u595329}"
SB_PORT="${SB_PORT:-23}"
SB_PATH="${SB_PATH:-/home/axion-crm-backups}"
SB_PASSWORD="${SB_PASSWORD:-}"

BACKUP_DIR="${BACKUP_DIR:-/var/backups/axion-crm}"
RETENTION_LOCAL_DAYS=7
RETENTION_REMOTE_DAYS=30
MIN_SIZE_BYTES=10000

# --- Validation ---
if [ -z "$SB_PASSWORD" ]; then
    echo "❌ SB_PASSWORD non défini (vérifie /opt/axion-crm-pro/.env)" >&2
    exit 1
fi
if ! command -v sshpass >/dev/null 2>&1; then
    echo "❌ sshpass non installé. Lance : apt install -y sshpass" >&2
    exit 1
fi

# --- Préparation ---
TIMESTAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP_FILE="axion_crm_${TIMESTAMP}.sql.gz"
mkdir -p "$BACKUP_DIR"

log() { echo "[$(date -u +%FT%TZ)] $*"; }

# Wrapper sshpass
sb_scp() { sshpass -p "$SB_PASSWORD" scp -P "$SB_PORT" -o StrictHostKeyChecking=accept-new -o ConnectTimeout=30 "$@"; }
sb_sftp_batch() { sshpass -p "$SB_PASSWORD" sftp -P "$SB_PORT" -o StrictHostKeyChecking=accept-new -o ConnectTimeout=30 -b - "$SB_USER@$SB_HOST"; }

# --- pg_dump ---
# Sprint 19.4 : -C (CREATE DATABASE) + préfix extensions.sql pour restore brut sans pré-création
# Le dump pg_dump n'inclut pas les CREATE EXTENSION dans plain text sans --extension flag.
# On préfixe donc avec un script SQL qui pose les 9 extensions requises (cf. infra/postgres/init/01-extensions.sql).
log "Starting pg_dump (DB=$DB_NAME, container=$DB_CONTAINER)..."

# Heredoc extensions, idempotent
EXTENSIONS_SQL=$(cat <<'EOSQL'
-- Axion CRM Pro — bootstrap extensions (Sprint 19.4 backup prefix)
CREATE EXTENSION IF NOT EXISTS "pg_trgm";
CREATE EXTENSION IF NOT EXISTS "unaccent";
CREATE EXTENSION IF NOT EXISTS "btree_gin";
CREATE EXTENSION IF NOT EXISTS "btree_gist";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";
CREATE EXTENSION IF NOT EXISTS "citext";
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "postgis";
CREATE EXTENSION IF NOT EXISTS "vector";
EOSQL
)

# pg_dump -C produit CREATE DATABASE + \connect, on injecte les extensions juste après le \connect.
# Stratégie portable : on concatène extensions + dump, en demandant un dump *sans* -C, puis
# le restore se fait par psql sur une DB déjà créée (le path standard d'un DR sain).
{
    echo "-- ============================================================================"
    echo "-- Axion CRM Pro — backup ${TIMESTAMP}"
    echo "-- DB=${DB_NAME}, container=${DB_CONTAINER}"
    echo "-- ============================================================================"
    echo ""
    echo "${EXTENSIONS_SQL}"
    echo ""

    # ── LES RÔLES DU CLUSTER — constat A08-008 (S1) ─────────────────────────
    #
    # 🔴 CETTE SECTION MANQUAIT. Un `pg_dump` ne contient AUCUN rôle : ni
    # `axion`, ni `axion_app`. Sur un serveur reconstruit après sinistre, le
    # rôle applicatif n'existerait donc pas, et l'application ne pourrait même
    # pas OUVRIR de session — la sauvegarde restaurait les données et rien de ce
    # qui permet de les lire.
    #
    # Mesuré le 2026-08-20 :
    #     $ pg_dumpall -U axion --globals-only
    #     CREATE ROLE axion_app;
    #     ALTER ROLE axion_app WITH NOSUPERUSER … LOGIN … PASSWORD 'SCRAM-SHA-256$…';
    #
    # ⚠️ Le mot de passe est emporté sous sa forme SCRAM (jamais en clair), et
    # c'est nécessaire : sans lui l'application ne s'authentifierait pas après
    # restauration. Cela hausse la sensibilité de l'archive — elle est déjà
    # pleine de données personnelles, et la Storage Box est le seul endroit où
    # elle repose.
    #
    # ⚠️ La section est DÉLIMITÉE parce qu'elle ne peut pas être rejouée comme le
    # reste : sur un cluster où `axion` existe déjà, `CREATE ROLE axion;` est une
    # erreur. `restore-postgres.sh` l'isole et l'applique à part, hors du
    # `--single-transaction -v ON_ERROR_STOP=1` qui protège la charge utile.
    echo "${MARQUEUR_GLOBALS_DEBUT}"
    docker exec "$DB_CONTAINER" pg_dumpall \
        -U "$DB_USER" \
        --globals-only
    echo "${MARQUEUR_GLOBALS_FIN}"
    echo ""

    echo "-- ============================================================================"
    echo "-- pg_dump payload (schema + data + GRANT)"
    echo "-- ============================================================================"
    # 🔴 `--no-acl` RETIRÉ LE 2026-08-20 — constat A08-008 (S1).
    #
    # Il supprimait tous les GRANT du dump. Mesure sur `companies`, avec et sans
    # l'option :
    #     sans --no-acl : GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.companies TO axion_app;
    #                     GRANT SELECT,USAGE ON SEQUENCE public.companies_id_seq TO axion_app;
    #     avec --no-acl : 0 ligne GRANT
    #
    # Le rôle applicatif est NON-PROPRIÉTAIRE : sans GRANT, il ne lit rien. Une
    # restauration « réussie » livrait donc une base pleine et une application
    # aveugle — « permission denied for table companies » sur la première
    # requête.
    #
    # ⚠️ `--no-owner` RESTE, et c'est délibéré : il fait revenir la propriété au
    # rôle qui restaure. C'est déjà l'état de la production (`axion` est
    # propriétaire) et cela évite d'échouer sur un cluster où le nom du
    # propriétaire diffère. Les GRANT vers `axion_app`, eux, sont émis
    # indépendamment de cette option — c'est `--no-acl` qui les tuait, pas
    # `--no-owner`.
    docker exec "$DB_CONTAINER" pg_dump \
        -U "$DB_USER" \
        -Fp \
        --no-owner \
        --clean \
        --if-exists \
        "$DB_NAME"
} | gzip -9 > "$BACKUP_DIR/$BACKUP_FILE"

# --- Vérif taille ---
SIZE=$(stat -c%s "$BACKUP_DIR/$BACKUP_FILE")
log "Dump produced: $BACKUP_FILE ($SIZE bytes)"

if [ "$SIZE" -lt "$MIN_SIZE_BYTES" ]; then
    log "❌ Dump too small ($SIZE bytes < $MIN_SIZE_BYTES). Aborting."
    rm -f "$BACKUP_DIR/$BACKUP_FILE"
    exit 1
fi

# --- Vérif DROITS — constat A08-008 (S1) ------------------------------------
#
# La vérification de taille ne dit rien des droits : l'archive fautive du
# 2026-08-20 pesait 692 Mo et ne contenait pas un seul GRANT. On regarde donc
# CE QUI EST DANS L'ARCHIVE, pas ce que le script croit y avoir mis.
#
# Deux comptages, parce que ce sont deux moitiés indépendantes du même mur :
#   · CREATE ROLE  → `pg_dumpall --globals-only` a bien tourné ;
#   · GRANT … TO   → `--no-acl` n'est pas revenu sur `pg_dump`.
#
# ⚠️ On cherche explicitement le rôle APPLICATIF, pas n'importe quel rôle : un
# `pg_dumpall` qui n'emporterait que `postgres` et `axion` laisserait encore
# l'application incapable d'ouvrir une session.
log "Vérification des droits emportés (constat A08-008)…"
NB_ROLES=$(gzip -dc "$BACKUP_DIR/$BACKUP_FILE" | grep -c "^CREATE ROLE ${DB_APP_USER};" || true)
NB_GRANTS=$(gzip -dc "$BACKUP_DIR/$BACKUP_FILE" | grep -c "^GRANT .* TO ${DB_APP_USER};" || true)
log "  rôle applicatif « ${DB_APP_USER} » : ${NB_ROLES} CREATE ROLE, ${NB_GRANTS} GRANT"

DROITS_OK=1
if [ "$NB_ROLES" -eq 0 ] || [ "$NB_GRANTS" -eq 0 ]; then
    DROITS_OK=0
fi

# --- Upload Storage Box ---
# ⚠️ L'ENVOI A LIEU MÊME SI LES DROITS MANQUENT, ET C'EST VOULU. Une archive
# sans GRANT reste une archive de données : la refuser laisserait ZÉRO copie
# hors-site cette nuit-là, ce qui est strictement pire. Le script sortira en
# erreur juste après, sans faire tourner la rétention — l'alerte part, et rien
# n'est effacé tant que le problème n'est pas réglé.
log "Uploading to Storage Box ($SB_HOST:$SB_PATH)..."
sb_scp "$BACKUP_DIR/$BACKUP_FILE" "$SB_USER@$SB_HOST:$SB_PATH/"
log "✅ Upload OK"

if [ "$DROITS_OK" -eq 0 ]; then
    log "❌ CONSTAT A08-008 : l'archive ne porte pas les droits."
    log "   CREATE ROLE ${DB_APP_USER} : ${NB_ROLES}   ·   GRANT vers ${DB_APP_USER} : ${NB_GRANTS}"
    log "   Une restauration à partir de cette archive rendrait une base pleine et une"
    log "   application AVEUGLE (« permission denied »), voire incapable de se connecter."
    log "   À vérifier : que \`pg_dumpall --globals-only\` tourne, et que \`--no-acl\` n'est"
    log "   pas revenu sur \`pg_dump\`."
    log "   Les données SONT sauvegardées (envoi effectué) ; la rotation est suspendue."
    exit 1
fi

# --- Rotation locale (find marche sur Ubuntu) ---
log "Rotation locale (>$RETENTION_LOCAL_DAYS jours)..."
find "$BACKUP_DIR" -name "axion_crm_*.sql.gz" -mtime "+$RETENTION_LOCAL_DAYS" -delete -print || true

# --- Rotation distante (Storage Box n'a pas `find`, on liste via sftp et on rm les anciens) ---
log "Rotation distante (>$RETENTION_REMOTE_DAYS jours)..."
CUTOFF_TIMESTAMP=$(date -u -d "$RETENTION_REMOTE_DAYS days ago" +%Y%m%dT%H%M%SZ)
log "  Cutoff: garder uniquement les fichiers > $CUTOFF_TIMESTAMP"

# Liste les fichiers .sql.gz via sftp.
#
# ⚠️ `ls` (sans -1) affiche EN COLONNES, chaque colonne complétée par des
# espaces : aucune ligne ne se termine par « .sql.gz », donc le
# `grep '\.sql\.gz$'` d'origine ne retenait RIEN. Mesuré le 2026-08-16 :
# REMOTE_LIST était vide, la rotation distante n'avait jamais supprimé un seul
# fichier depuis sa création — l'archive du 17 mai était toujours là, trois mois
# après une rétention annoncée à 30 jours.
#
# Ce n'est pas cosmétique : à 692 Mo par nuit, une Storage Box qui ne tourne
# jamais finit pleine, l'envoi échoue, et on retombe sur la panne silencieuse
# de #136 — une sauvegarde locale sans copie hors-site.
#
# `ls -1` force une entrée par ligne, sans remplissage.
REMOTE_LIST=$(sshpass -p "$SB_PASSWORD" sftp -P "$SB_PORT" -o StrictHostKeyChecking=accept-new "$SB_USER@$SB_HOST" <<EOF 2>/dev/null | sed -nE 's/^(axion_crm_[0-9]+T[0-9]+Z\.sql\.gz)[[:space:]]*$/\1/p' || true
cd $SB_PATH
ls -1
EOF
)

NB_DISTANTS=$(printf '%s\n' "$REMOTE_LIST" | grep -c . || true)
log "  $NB_DISTANTS archive(s) hors-site listée(s)"

# Filet : ne JAMAIS supprimer la plus récente, même si elle a dépassé la
# rétention. Si la sauvegarde s'arrête (c'est arrivé : 91 échecs sur 91), une
# rotation purement calendaire viderait le hors-site et laisserait ZÉRO copie.
PLUS_RECENTE=$(printf '%s\n' "$REMOTE_LIST" | grep . | sort | tail -1)

# Pour chaque fichier, parse le timestamp dans le nom et supprime si trop ancien
DELETED_COUNT=0
while IFS= read -r filename; do
    [ -z "$filename" ] && continue
    [ "$filename" = "$PLUS_RECENTE" ] && continue
    # Extract timestamp from "axion_crm_YYYYMMDDTHHMMSSZ.sql.gz"
    ts=$(echo "$filename" | sed -nE 's/^axion_crm_([0-9]+T[0-9]+Z)\.sql\.gz$/\1/p')
    if [ -n "$ts" ] && [ "$ts" \< "$CUTOFF_TIMESTAMP" ]; then
        log "  Suppression distante: $filename (ts=$ts)"
        sshpass -p "$SB_PASSWORD" sftp -P "$SB_PORT" -o StrictHostKeyChecking=accept-new "$SB_USER@$SB_HOST" <<EOF >/dev/null 2>&1 || true
cd $SB_PATH
rm $filename
EOF
        DELETED_COUNT=$((DELETED_COUNT + 1))
    fi
done <<< "$REMOTE_LIST"
log "  Rotation distante : terminée ($DELETED_COUNT supprimée(s))"

# --- Inventory ---
log "Backups locaux actuels :"
ls -lh "$BACKUP_DIR" | tail -n +2

log "✅ Backup completed: $BACKUP_FILE ($SIZE bytes)"
