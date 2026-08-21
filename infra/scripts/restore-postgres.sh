#!/usr/bin/env bash
# ============================================================================
# Axion CRM Pro — Restore Postgres depuis dump produit par backup-postgres.sh
# ============================================================================
# Usage : bash restore-postgres.sh /path/to/axion_crm_YYYYMMDD.sql.gz [target_db]
#
# L'archive contient, dans cet ordre :
#   1) un préambule d'extensions DÉRIVÉ de `pg_extension` au moment du dump —
#      constat F39-005. C'étaient, jusqu'au 2026-08-21, neuf noms recopiés à la
#      main, et il en manquait un : `pg_partman`, avec le schéma qui le porte.
#   2) les RÔLES du cluster, entre deux marqueurs (`pg_dumpall --globals-only`)
#   3) le schéma, les données ET LES GRANT
#
# Le restore se fait sur une DB déjà créée (CREATE DATABASE séparé) — c'est le
# chemin standard pour un DR sain (pas de privileges superuser implicite).
#
# ── 🔴 CE QUI MANQUAIT, ET CE QUE ÇA COÛTAIT — constat A08-008 (S1) ──────────
#
# Ce script ne connaissait que deux choses : « les extensions » et « le dump ».
# Il restaurait donc les DONNÉES et RIEN DE CE QUI PERMET DE LES LIRE :
#
#   · aucun rôle — `pg_dump` n'en contient aucun. Sur un serveur reconstruit
#     après sinistre, `axion_app` n'existerait pas et l'application ne pourrait
#     même pas ouvrir de session ;
#   · aucun GRANT — `backup-postgres.sh` passait `--no-acl`. Le rôle applicatif
#     est NON-PROPRIÉTAIRE (migration `harden_workspace_isolation`) : sans
#     GRANT, il ne lit rien.
#
# Et sa vérification finale — « au moins 10 tables » — ne pouvait pas le voir :
# une base sans un seul GRANT porte exactement le même nombre de tables qu'une
# base saine. Un contrôle qui ne peut pas échouer sur le défaut qu'on répare est
# pire qu'aucun contrôle : il rassure.
#
# Ce script fait donc désormais SIX étapes : la cinquième interroge les droits
# AVEC LE RÔLE APPLICATIF, la sixième les EXTENSIONS.
#
# ── 🔴 ET LA SIXIÈME, POURQUOI — constat F39-005 (S1) ───────────────────────
#
# Le même défaut, sur l'autre moitié de ce qui rend une base utilisable. Une
# extension absente ne fait pas échouer la restauration : elle la fait échouer à
# la PREMIÈRE REQUÊTE qui s'en sert. C'est la panne du 2026-08-16 — `function
# unaccent(text) does not exist` —, découverte au milieu d'un exercice de
# reprise, c'est-à-dire trop tard.
#
# On compare donc CE QUE L'ARCHIVE DÉCLARE à CE QUE LA BASE PORTE. Les deux
# côtés sont mesurés ; il n'y a aucune liste à tenir d'accord avec une autre.
#
# Codes de sortie : 1 = usage / restauration ; 6 = données restaurées mais
# DROITS ou EXTENSIONS ABSENTS (l'application ne lira rien, ou échouera à la
# première requête).
# ============================================================================

set -euo pipefail

DUMP_FILE="${1:-}"
TARGET_DB="${2:-axion_crm}"
DB_CONTAINER="${DB_CONTAINER:-axion-crm-postgres}"
DB_USER="${DB_USER:-axion}"

# Le rôle NON-PROPRIÉTAIRE par lequel l'application se connecte dès que
# `CRM_DB_APP_ROLE_ENABLED` vaut vrai. C'est LUI qu'il faut interroger : le
# rôle `axion` est superutilisateur (mesuré : `rolsuper=t`, `rolbypassrls=t`)
# et lit tout, GRANT ou pas.
DB_APP_USER="${DB_APP_USER:-axion_app}"

# ⚠️ CONTRAT PARTAGÉ AVEC `backup-postgres.sh` ET `dr-drill.sh`.
# Les trois fichiers doivent porter ces marqueurs à l'identique. Les changer
# d'un seul côté casserait la restauration en silence, et on ne s'en
# apercevrait qu'un jour de sinistre.
# Garde : `backend/tests/Feature/Infra/SauvegardeRestaureLesDroitsTest.php`.
MARQUEUR_GLOBALS_DEBUT="-- >>> AXION-GLOBALS-DEBUT"
MARQUEUR_GLOBALS_FIN="-- >>> AXION-GLOBALS-FIN"

if [ -z "$DUMP_FILE" ] || [ ! -f "$DUMP_FILE" ]; then
    echo "Usage: $0 <dump_file.sql.gz> [target_db]" >&2
    echo "Exemple : $0 /var/backups/axion-crm/axion_crm_20260517T020000Z.sql.gz" >&2
    exit 1
fi

log() { echo "[$(date -u +%FT%TZ)] $*"; }

log "Restore depuis $DUMP_FILE → $DB_CONTAINER:$TARGET_DB"

# 1) Crée la DB si absente (pas dans le dump, voulu — sécurité prod)
log "Étape 1/6 : ensure DB $TARGET_DB exists"
docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d postgres -tc \
    "SELECT 1 FROM pg_database WHERE datname = '$TARGET_DB'" | grep -q 1 \
    || docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d postgres -c "CREATE DATABASE $TARGET_DB"

# 2) LES RÔLES, AVANT TOUT LE RESTE — constat A08-008
#
# ⚠️ Cette section s'applique À PART, et hors `ON_ERROR_STOP`. Sur un cluster où
# `axion` ou `postgres` existent déjà, `CREATE ROLE axion;` est une ERREUR — et
# la charge utile est restaurée avec `--single-transaction -v ON_ERROR_STOP=1`,
# donc une seule de ces erreurs annulerait TOUTE la restauration. Ces erreurs-là
# sont attendues et bénignes : ce qui compte est l'état FINAL des rôles, qu'on
# vérifie juste après.
log "Étape 2/6 : rôles du cluster (section globals de l'archive)"
GLOBALS=$(gunzip -c "$DUMP_FILE" | sed -n "\|${MARQUEUR_GLOBALS_DEBUT}|,\|${MARQUEUR_GLOBALS_FIN}|p" | grep -v '^-- >>> AXION-GLOBALS-' || true)

if [ -z "$(printf '%s' "$GLOBALS" | tr -d '[:space:]')" ]; then
    log "⚠️  Cette archive ne contient AUCUNE section de rôles."
    log "    Elle a été produite avant le correctif du constat A08-008 (2026-08-20)."
    log "    La restauration des données continue, mais les GRANT vers « ${DB_APP_USER} »"
    log "    échoueront si le rôle n'existe pas déjà sur ce cluster. L'étape 5 tranchera."
else
    printf '%s\n' "$GLOBALS" \
        | docker exec -i "$DB_CONTAINER" psql -U "$DB_USER" -d postgres -q 2>&1 \
        | sed 's/^/    /' || true
    log "  Rôles appliqués."
fi

# Le rôle applicatif existe-t-il MAINTENANT ? S'il manque, les `GRANT … TO
# axion_app` de la charge utile feront échouer la transaction entière, et
# l'opérateur croirait à une archive corrompue. On le dit avant, pas après.
ROLE_PRESENT=$(docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d postgres -tAc \
    "SELECT count(*) FROM pg_roles WHERE rolname = '${DB_APP_USER}'")
if [ "$ROLE_PRESENT" -eq 0 ]; then
    log "❌ Le rôle applicatif « ${DB_APP_USER} » n'existe pas sur ce cluster, et l'archive"
    log "   ne permet pas de le créer. Les GRANT de la charge utile échoueraient et"
    log "   annuleraient toute la restauration (--single-transaction)."
    log "   Remède : le créer à la main (cf. migration harden_workspace_isolation), ou"
    log "   récupérer une archive produite après le 2026-08-20."
    exit 6
fi
log "  Rôle applicatif « ${DB_APP_USER} » présent."

# 3) Restore : ungzip + psql, SANS la section des rôles (déjà appliquée)
log "Étape 3/6 : streaming gunzip → psql"
gunzip -c "$DUMP_FILE" \
    | sed "\|${MARQUEUR_GLOBALS_DEBUT}|,\|${MARQUEUR_GLOBALS_FIN}|d" \
    | docker exec -i "$DB_CONTAINER" psql -U "$DB_USER" -d "$TARGET_DB" --single-transaction -v ON_ERROR_STOP=1

# 4) Vérif : tables existent
log "Étape 4/6 : vérification post-restore (tables)"
TABLE_COUNT=$(docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$TARGET_DB" -tAc \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='public'")
log "Tables publiques après restore : $TABLE_COUNT"

if [ "$TABLE_COUNT" -lt 10 ]; then
    log "Restore semble incomplet ($TABLE_COUNT tables < 10 attendues)" >&2
    exit 1
fi

# 5) 🔴 LA VÉRIFICATION QUI MANQUAIT — constat A08-008 (S1)
#
# On ne compte pas les lignes en superutilisateur : c'est ce que fait
# `dr-drill.sh`, et c'est exactement pourquoi il n'a jamais rien vu. On demande
# à Postgres, table par table, si LE RÔLE APPLICATIF peut lire.
#
# `has_table_privilege` répond à cette question et à aucune autre : elle ne
# dépend ni de la RLS (qui filtre des lignes, pas l'accès), ni du contenu. Une
# base restaurée sans un seul GRANT rend ici le nombre total de tables.
log "Étape 5/6 : droits du rôle applicatif « ${DB_APP_USER} »"
ILLISIBLES=$(docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$TARGET_DB" -tAc "
    SELECT count(*)
    FROM pg_class c
    JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname = 'public'
      AND c.relkind IN ('r', 'p')
      AND NOT has_table_privilege('${DB_APP_USER}', c.oid, 'SELECT')")

if [ "$ILLISIBLES" -gt 0 ]; then
    log "❌ CONSTAT A08-008 : ${ILLISIBLES} table(s) publique(s) illisibles par « ${DB_APP_USER} »."
    log "   Les données sont là, l'application ne les verra PAS : elle échouera sur"
    log "   « permission denied for table … » à la première requête."
    log "   Cause la plus probable : l'archive a été produite avec \`--no-acl\`, donc sans"
    log "   aucun GRANT (défaut corrigé le 2026-08-20 dans backup-postgres.sh)."
    log "   Remède immédiat, à jouer en tant que propriétaire sur ${TARGET_DB} :"
    log "     GRANT USAGE ON SCHEMA public TO ${DB_APP_USER};"
    log "     GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO ${DB_APP_USER};"
    log "     GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO ${DB_APP_USER};"
    log "     ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO ${DB_APP_USER};"
    exit 6
fi
log "  ✓ Aucune table illisible par le rôle applicatif."

# 6) 🔴 LES EXTENSIONS — constat F39-005 (S1)
#
# L'étape 4 compte des tables ; l'étape 5 interroge des droits. Ni l'une ni
# l'autre ne voit une extension manquante : une base sans `unaccent` porte
# exactement le même nombre de tables, et le rôle applicatif y a exactement les
# mêmes droits. Elle échoue plus tard, sur une requête, en production.
#
# On ne compare pas à une liste écrite ici — c'est précisément ce qui a produit
# le défaut. On lit CE QUE L'ARCHIVE DÉCLARE (les `CREATE EXTENSION` qu'elle
# porte, préambule dérivé ET section `pg_dump`) et on exige que la base
# restaurée les porte toutes.
log "Étape 6/6 : extensions déclarées par l'archive vs extensions de la base"
EXT_ARCHIVE=$(gunzip -c "$DUMP_FILE" \
    | sed -nE 's/^CREATE EXTENSION IF NOT EXISTS "?([^" ]+)"?.*/\1/p' \
    | sort -u)

if [ -z "$(printf '%s' "$EXT_ARCHIVE" | tr -d '[:space:]')" ]; then
    log "⚠️  Cette archive ne DÉCLARE aucune extension : le contrôle ne peut rien"
    log "    comparer et ne prouve donc rien. Archive antérieure au 2026-08-21, ou"
    log "    produite par autre chose que \`backup-postgres.sh\`. À vérifier à la main :"
    log "      docker exec ${DB_CONTAINER} psql -U ${DB_USER} -d ${TARGET_DB} -c '\\dx'"
else
    EXT_BASE=$(docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$TARGET_DB" -tAX -c \
        "SELECT extname FROM pg_extension ORDER BY extname")

    EXT_ABSENTES=""
    while IFS= read -r ext; do
        [ -z "$ext" ] && continue
        if ! printf '%s\n' "$EXT_BASE" | grep -qxF "$ext"; then
            EXT_ABSENTES="${EXT_ABSENTES} ${ext}"
        fi
    done <<EOF
$EXT_ARCHIVE
EOF

    if [ -n "$EXT_ABSENTES" ]; then
        log "❌ CONSTAT F39-005 : l'archive déclare des extensions que la base restaurée n'a pas."
        log "   Absentes :${EXT_ABSENTES}"
        log "   Les tables sont là et les droits sont bons — la base échouera pourtant à la"
        log "   PREMIÈRE requête qui s'en sert. C'est la panne du 2026-08-16,"
        log "   « function unaccent(text) does not exist »."
        log "   Cause la plus probable : l'image Postgres de CE serveur n'est pas"
        log "   ghcr.io/will383842/axion-crm-pro-postgres:16-3.5-vector-partman."
        exit 6
    fi
    log "  ✓ Toutes les extensions déclarées par l'archive sont posées."
fi

log "Restore complet. DB $TARGET_DB prête."
