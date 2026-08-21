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
log "Starting pg_dump (DB=$DB_NAME, container=$DB_CONTAINER)..."

# ── LE PRÉAMBULE D'EXTENSIONS — constat F39-005 (S1) ────────────────────────
#
# 🔴 CE QUI ÉTAIT ÉCRIT ICI, ET POURQUOI C'ÉTAIT DEUX FOIS FAUX.
#
# Jusqu'au 2026-08-21, ce bloc était un heredoc de NEUF NOMS RECOPIÉS À LA MAIN,
# justifié par ce commentaire (Sprint 19.4) :
#
#     « Le dump pg_dump n'inclut pas les CREATE EXTENSION dans plain text
#       sans --extension flag. »
#
# 1) L'AFFIRMATION EST FAUSSE. Mesure du 2026-08-21, avec les options exactes de
#    ce script, sur une base portant les extensions de l'application :
#
#      $ pg_dump -U axion -Fp --no-owner --clean --if-exists axion_crm_test_lot10 \
#          | grep -E '^(CREATE|DROP) (EXTENSION|SCHEMA)'
#      DROP EXTENSION IF EXISTS vector;
#      …
#      CREATE SCHEMA partman;
#      CREATE EXTENSION IF NOT EXISTS btree_gin WITH SCHEMA public;
#      …
#      CREATE EXTENSION IF NOT EXISTS pg_partman WITH SCHEMA partman;
#
#    `pg_dump` dérive cette liste du CATALOGUE (`pg_extension`) et l'écrit
#    lui-même. Restauration d'une archive SANS aucun préambule, recette de
#    `restore-postgres.sh`, base neuve : 0 erreur, 116 tables, LES 10 EXTENSIONS,
#    `pg_partman` compris, dans son schéma `partman`. Le préambule écrit à la
#    main n'a donc jamais été ce qui rendait l'archive restaurable.
#
# 2) LA LISTE ÉTAIT INCOMPLÈTE, et c'est le motif A08-008 à l'identique. Jouée
#    seule sur une base neuve (mesure du 2026-08-21), elle pose NEUF extensions
#    et laisse dehors `pg_partman` — ainsi que le schéma `partman` qui le porte,
#    lequel n'est pas optionnel (cf. `infra/postgres/init/01-extensions.sql`).
#    Personne ne l'a vu pendant trois mois, parce qu'une liste recopiée ne
#    signale jamais ce qu'on a oublié d'y écrire : le seul moment où elle
#    répond, c'est le jour de la restauration.
#
# ── CE QU'ON FAIT À LA PLACE ────────────────────────────────────────────────
#
# On DEMANDE au catalogue, comme le dépôt vient de le faire pour C21-004
# (`pg_get_functiondef` + `tgattr` plutôt qu'une formule recopiée). La requête
# ci-dessous rend, dans l'ordre de CRÉATION (`e.oid`, qui est un ordre de
# dépendance valide par construction) :
#   · un `CREATE SCHEMA IF NOT EXISTS` par schéma non-`public` hébergeant une
#     extension — sans lui, `WITH SCHEMA partman` échoue ;
#   · un `CREATE EXTENSION IF NOT EXISTS … WITH SCHEMA …` par extension.
# `plpgsql` est exclu : il est présent dans `template1`, donc dans toute base
# fraîchement créée, et `pg_dump` ne l'émet pas non plus.
#
# ⚠️ CONTRAT AVEC LA GARDE. Les deux marqueurs ci-dessous délimitent la requête,
# et `backend/tests/Feature/Infra/SauvegardeEmporteLesExtensionsTest.php` la LIT
# ICI puis la REJOUE contre `pg_extension` pour exiger qu'elle couvre le
# catalogue en entier. Les retirer aveuglerait la garde — elle le refuse et sort
# en rouge.
# >>> AXION-EXTENSIONS-SQL-DEBUT
REQUETE_EXTENSIONS=$(cat <<'EOSQL'
SELECT t.ligne
FROM (
    SELECT 1 AS rang,
           lpad(min(e.oid)::text, 12, '0') AS ordre,
           'CREATE SCHEMA IF NOT EXISTS ' || quote_ident(n.nspname) || ';' AS ligne
    FROM pg_extension e
    JOIN pg_namespace n ON n.oid = e.extnamespace
    WHERE e.extname <> 'plpgsql'
      AND n.nspname <> 'public'
    GROUP BY n.nspname
    UNION ALL
    SELECT 2,
           lpad(e.oid::text, 12, '0'),
           'CREATE EXTENSION IF NOT EXISTS ' || quote_ident(e.extname)
               || ' WITH SCHEMA ' || quote_ident(n.nspname) || ';'
    FROM pg_extension e
    JOIN pg_namespace n ON n.oid = e.extnamespace
    WHERE e.extname <> 'plpgsql'
) t
ORDER BY t.rang, t.ordre
EOSQL
)
# >>> AXION-EXTENSIONS-SQL-FIN

# On l'évalue AVANT le bloc `{ … } | gzip` : dans un pipeline, l'échec du groupe
# ne coupe pas le script, et une archive amputée de son préambule partirait
# quand même hors-site.
EXTENSIONS_SQL=$(docker exec "$DB_CONTAINER" psql \
    -U "$DB_USER" -d "$DB_NAME" -tAX -v ON_ERROR_STOP=1 -c "$REQUETE_EXTENSIONS")

if [ -z "$(printf '%s' "$EXTENSIONS_SQL" | tr -d '[:space:]')" ]; then
    echo "❌ CONSTAT F39-005 : le catalogue de $DB_NAME n'a rendu AUCUNE extension." >&2
    echo "   Ce n'est pas un cas normal : l'application en exige dix. Soit la requête" >&2
    echo "   a échoué, soit ce n'est pas la bonne base. On n'écrit pas d'archive" >&2
    echo "   là-dessus — une archive sans préambule ne se signale pas toute seule." >&2
    exit 1
fi

# Le nom de chaque extension attendue, pour la vérification d'archive plus bas.
# Même source, donc aucune liste à tenir d'accord avec une autre.
EXTENSIONS_ATTENDUES=$(docker exec "$DB_CONTAINER" psql \
    -U "$DB_USER" -d "$DB_NAME" -tAX -v ON_ERROR_STOP=1 -c \
    "SELECT extname FROM pg_extension WHERE extname <> 'plpgsql' ORDER BY extname")

# Stratégie portable : on concatène préambule + globals + dump, en demandant un
# dump *sans* -C, puis le restore se fait par psql sur une DB déjà créée (le
# chemin standard d'un DR sain).
#
# Le préambule reste, bien que `pg_dump` émette lui aussi ses `CREATE EXTENSION`
# (mesuré, cf. plus haut) : il est en tête d'archive, donc lisible d'un coup
# d'œil par l'opérateur, et il dit quelle image Postgres est requise avant même
# qu'on lance la restauration. Ce qui change, c'est qu'il n'est plus RECOPIÉ.
{
    echo "-- ============================================================================"
    echo "-- Axion CRM Pro — backup ${TIMESTAMP}"
    echo "-- DB=${DB_NAME}, container=${DB_CONTAINER}"
    echo "-- ============================================================================"
    echo ""
    echo "-- Extensions, DÉRIVÉES DE pg_extension au moment du dump (constat F39-005)."
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

# --- Vérif EXTENSIONS — constat F39-005 (S1) --------------------------------
#
# Le pendant du contrôle ci-dessus, pour la seconde moitié de ce qui rend une
# base restaurable. On ne fait PAS confiance au préambule qu'on vient d'écrire :
# on relit CE QUI EST DANS L'ARCHIVE et on le compare au catalogue de la base
# source. C'est le seul contrôle qui puisse voir une extension oubliée, parce
# que les deux côtés sont mesurés et qu'aucun n'est une liste écrite à la main.
#
# Une extension manquante ne fait pas échouer la restauration bruyamment : elle
# la fait échouer à la PREMIÈRE REQUÊTE qui s'en sert. C'est exactement la panne
# du 2026-08-16 — `function unaccent(text) does not exist` —, découverte au
# milieu d'un exercice de reprise.
log "Vérification des extensions emportées (constat F39-005)…"
EXTENSIONS_ARCHIVE=$(gzip -dc "$BACKUP_DIR/$BACKUP_FILE" \
    | sed -nE 's/^CREATE EXTENSION IF NOT EXISTS "?([^" ]+)"?.*/\1/p' \
    | sort -u)

EXT_MANQUANTES=""
while IFS= read -r ext; do
    [ -z "$ext" ] && continue
    if ! printf '%s\n' "$EXTENSIONS_ARCHIVE" | grep -qxF "$ext"; then
        EXT_MANQUANTES="${EXT_MANQUANTES} ${ext}"
    fi
done <<EOF
$EXTENSIONS_ATTENDUES
EOF

NB_EXT_ATTENDUES=$(printf '%s\n' "$EXTENSIONS_ATTENDUES" | grep -c . || true)
NB_EXT_ARCHIVE=$(printf '%s\n' "$EXTENSIONS_ARCHIVE" | grep -c . || true)
log "  extensions : ${NB_EXT_ATTENDUES} au catalogue, ${NB_EXT_ARCHIVE} dans l'archive"

EXTENSIONS_OK=1
if [ -n "$EXT_MANQUANTES" ]; then
    EXTENSIONS_OK=0
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

if [ "$EXTENSIONS_OK" -eq 0 ]; then
    log "❌ CONSTAT F39-005 : l'archive ne porte pas toutes les extensions de ${DB_NAME}."
    log "   Absentes de l'archive :${EXT_MANQUANTES}"
    log "   Une restauration à partir de cette archive rendrait une base qui échoue à la"
    log "   PREMIÈRE requête utilisant l'une d'elles — c'est la panne du 2026-08-16,"
    log "   « function unaccent(text) does not exist », vue au milieu d'un exercice."
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
