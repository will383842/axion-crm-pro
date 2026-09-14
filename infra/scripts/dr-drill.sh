#!/usr/bin/env bash
# =============================================================================
# Axion CRM Pro — exercice de restauration (DR drill)
# =============================================================================
# ⚠️ RÉÉCRIT LE 2026-08-16. La version précédente n'a JAMAIS pu s'exécuter, et
# ce n'était pas un détail : c'est en tentant de la lancer qu'on a découvert que
# la sauvegarde de production n'était ni produite ni restaurable.
#
# Ce qu'elle avait de faux :
#   - elle lisait `s3cmd ls s3://axion-crm-backups/` — du stockage S3 — alors
#     que `backup-postgres.sh` écrit en SFTP sur une Storage Box Hetzner. Deux
#     backends sans rapport ;
#   - `s3cmd` n'est pas installé sur le serveur : le script s'arrêtait à la
#     première ligne utile ;
#   - elle exigeait un RPO ≤ 1 h alors que la sauvegarde est QUOTIDIENNE : elle
#     aurait échoué même avec des sauvegardes parfaites ;
#   - elle restaurait dans `postgres:16-alpine`, sans PostGIS : la restauration
#     serait morte au premier `CREATE EXTENSION`.
#
# ---------------------------------------------------------------------------
# CE QUE CE SCRIPT VÉRIFIE, ET POURQUOI CHAQUE POINT COMPTE
# ---------------------------------------------------------------------------
#   1. la copie HORS-SITE se récupère et son empreinte correspond
#      → si le serveur brûle, c'est elle qui sauve, pas la copie locale ;
#   2. la restauration se termine SANS ERREUR
#      → mesuré le 2026-08-16 : 17 erreurs suffisaient à rendre une base VIDE ;
#   3. les COMPTAGES restaurés égalent la production
#      → une restauration « sans erreur » mais à 0 ligne reste un échec ;
#   4. le RÔLE APPLICATIF peut lire les tables restaurées  ← ajouté 2026-08-20
#      → constat A08-008 (S1) : les points 1 à 3 se jouent tous en
#        SUPERUTILISATEUR (`psql -U axion`, mesuré `rolsuper=t rolbypassrls=t`),
#        qui lit tout quels que soient les GRANT. Ils rendaient donc le même
#        chiffre sur une base saine et sur une base que l'application ne peut
#        PAS lire. Cet exercice a validé pendant des mois une sauvegarde qui
#        n'emportait ni rôles ni GRANT ;
#   5. le RTO reste sous la cible.
#
# ⚠️ La restauration NE SE FAIT PAS sur le serveur de production : la base pèse
# 16 Go pour 14 Go libres. Ce script attend donc un hôte Docker disposant de la
# place, et refuse de tourner si elle manque.
#
# Usage :
#   ./infra/scripts/dr-drill.sh                 # depuis un poste ayant l'accès SSH
#   ./infra/scripts/dr-drill.sh --no-cleanup    # garde la base restaurée
# =============================================================================

set -euo pipefail

NO_CLEANUP="${1:-}"
DEBUT=$(date +%s)

SRV="${SRV:-root@46.62.248.239}"
SRV_BACKUPS="${SRV_BACKUPS:-/var/backups/axion-crm}"
SB_HOST="${SB_HOST:-u595329.your-storagebox.de}"
SB_USER="${SB_USER:-u595329}"
SB_PORT="${SB_PORT:-23}"
SB_PATH="${SB_PATH:-/home/axion-crm-backups}"

PG_CONTENEUR="${PG_CONTENEUR:-axion-crm-postgres}"
BASE_DRILL="${BASE_DRILL:-axion_crm_dr}"
SCRATCH="${SCRATCH:-/tmp/axion-crm-dr-drill}"

# Le rôle NON-PROPRIÉTAIRE par lequel l'application se connecte (migration
# `2026_08_14_000001_harden_workspace_isolation`). Cf. étape 5.
ROLE_APPLICATIF="${ROLE_APPLICATIF:-axion_app}"

# ⚠️ CONTRAT PARTAGÉ AVEC `backup-postgres.sh` ET `restore-postgres.sh`.
# Les trois fichiers portent ces marqueurs à l'identique.
MARQUEUR_GLOBALS_DEBUT="-- >>> AXION-GLOBALS-DEBUT"
MARQUEUR_GLOBALS_FIN="-- >>> AXION-GLOBALS-FIN"

# Cible RTO : 4 h. Mesure de référence du 2026-08-16 : 21 min pour 16 Go.
RTO_CIBLE_S=14400
# La sauvegarde est QUOTIDIENNE (cron 03:00). On tolère 36 h pour absorber un
# décalage d'exécution sans crier au loup — mais pas deux jours de silence.
RPO_CIBLE_S=129600

echo "=== Exercice de restauration — $(date -Iseconds) ==="

# --- 0. Place disponible ----------------------------------------------------
# Refuser tôt plutôt que remplir un disque à mi-restauration.
#
# ⚠️ On mesure DEPUIS LE CONTENEUR, pas depuis l'hôte. La version du 2026-08-16
# lisait `df /var/lib/docker` côté hôte : ce chemin n'existe ni sous Docker
# Desktop (Windows/macOS, le démon tourne dans une VM) ni sous Docker distant.
# `df` échouait, `awk` ne recevait rien, LIBRE_GO valait "" → 0 → le script
# refusait de tourner sur le SEUL poste qui avait la place. Une garde qui
# bloque tout le monde ne garde rien.
#
# Le volume de données du conteneur est exactement le système de fichiers qui
# va recevoir les ~16 Go restaurés : c'est lui qu'il faut interroger.
if ! docker inspect -f '{{.State.Running}}' "$PG_CONTENEUR" 2>/dev/null | grep -q true; then
    echo "❌ Le conteneur « $PG_CONTENEUR » ne tourne pas — démarre-le avant l'exercice." >&2
    exit 1
fi
# Le chemin passe par `sh -c` et non en argument direct : sous Git Bash (MSYS),
# tout argument commençant par « / » est réécrit en chemin Windows avant d'être
# remis à docker — `/var/lib/postgresql/data` devenait
# « C:/Program Files/Git/var/lib/postgresql/data ». Enfermé dans la chaîne du
# `sh -c`, l'argument ne commence plus par « / » et traverse intact.
LIBRE_GO=$(docker exec "$PG_CONTENEUR" sh -c 'df -Pk /var/lib/postgresql/data' \
    | awk 'NR==2{print int($4/1048576)}')
if [ "${LIBRE_GO:-0}" -lt 25 ]; then
    echo "❌ Moins de 25 Go libres sur le volume de « $PG_CONTENEUR » (${LIBRE_GO:-0} Go)" >&2
    echo "   — la base restaurée en pèse ~16. Lancer l'exercice depuis un hôte qui a la place." >&2
    exit 1
fi
echo "  Place disponible sur le volume de restauration : ${LIBRE_GO} Go"

# --- 1. La copie hors-site est-elle récupérable ET intacte ? -----------------
echo "[1/6] Récupération de la dernière sauvegarde depuis la Storage Box…"
mkdir -p "$SCRATCH"

# ⚠️ Depuis le 2026-09-03 les archives sont chiffrées (`.sql.gz.enc`). Le glob
# couvre les DEUX formes : une archive antérieure au chiffrement doit encore
# pouvoir être exercée, sinon l'exercice devient aveugle pendant 7 jours.
DERNIER=$(ssh "$SRV" "ls -1t ${SRV_BACKUPS}/*.sql.gz ${SRV_BACKUPS}/*.sql.gz.enc 2>/dev/null | head -1 | xargs -r basename")
if [ -z "$DERNIER" ]; then
    echo "❌ Aucune sauvegarde locale sur le serveur — la sauvegarde ne tourne pas." >&2
    exit 1
fi
echo "  Dernière archive : $DERNIER"

# Âge : le RPO se mesure sur ce qui EXISTE, pas sur ce qui est planifié.
AGE_S=$(ssh "$SRV" "echo \$(( \$(date +%s) - \$(stat -c %Y ${SRV_BACKUPS}/${DERNIER}) ))")
echo "  Âge : $((AGE_S / 3600)) h"
if [ "$AGE_S" -gt "$RPO_CIBLE_S" ]; then
    echo "❌ RPO violé : la dernière sauvegarde a plus de $((RPO_CIBLE_S / 3600)) h." >&2
    exit 1
fi

# Empreinte de la copie locale, puis de la copie RAPATRIÉE depuis le hors-site.
# Comparer les deux est le seul moyen de prouver que le transfert n'a pas
# silencieusement tronqué l'archive.
EMPREINTE_LOCALE=$(ssh "$SRV" "sha256sum ${SRV_BACKUPS}/${DERNIER} | cut -d' ' -f1")
ssh "$SRV" "set -a; . <(grep -E '^SB_' /opt/axion-crm-pro/.env); set +a; \
    mkdir -p ${SCRATCH} && cd ${SCRATCH} && \
    sshpass -p \"\$SB_PASSWORD\" sftp -o StrictHostKeyChecking=no -P ${SB_PORT} \
        ${SB_USER}@${SB_HOST}:${SB_PATH}/${DERNIER} . >/dev/null"
EMPREINTE_DISTANTE=$(ssh "$SRV" "sha256sum ${SCRATCH}/${DERNIER} | cut -d' ' -f1")

if [ "$EMPREINTE_LOCALE" != "$EMPREINTE_DISTANTE" ]; then
    echo "❌ La copie hors-site DIFFÈRE de la copie locale." >&2
    echo "   locale=$EMPREINTE_LOCALE" >&2
    echo "   distante=$EMPREINTE_DISTANTE" >&2
    exit 2
fi
echo "  ✓ Copie hors-site récupérée, empreinte identique"

# --- 2. Comptages de RÉFÉRENCE, pris en production AVANT la restauration ----
echo "[2/6] Relevé des comptages de production…"
REFERENCE=$(ssh "$SRV" "docker exec ${PG_CONTENEUR} psql -U axion -d axion_crm -tAc \"
    SELECT 'companies='||count(*) FROM companies
    UNION ALL SELECT 'contacts='||count(*) FROM contacts
    UNION ALL SELECT 'scraper_runs='||count(*) FROM scraper_runs
    UNION ALL SELECT 'company_tag='||count(*) FROM company_tag
    UNION ALL SELECT 'journalists='||count(*) FROM journalists\" | sort")
echo "$REFERENCE" | sed 's/^/    /'

# 🔴 LES EXTENSIONS DE PRODUCTION — constat F39-005 (S1), ajouté le 2026-08-21.
#
# Les comptages ci-dessus ne voient pas une extension manquante : une base privée
# d'`unaccent` porte exactement le même nombre de lignes, et l'étape 5 lui trouve
# exactement les mêmes droits. C'est pourtant ce qui a fait tomber l'exercice du
# 2026-08-16 — `function unaccent(text) does not exist` —, et il a fallu lire le
# journal de restauration pour le comprendre.
#
# On relève donc `pg_extension` EN PRODUCTION, avec le schéma de chaque
# extension (`pg_partman` vit dans `partman`, et s'il atterrit ailleurs la suite
# de tests meurt — cf. `infra/postgres/init/01-extensions.sql`). Aucune liste
# n'est écrite ici : les deux côtés sont mesurés, ce qui est le seul moyen de
# voir une extension qu'on a oublié d'inscrire quelque part.
REFERENCE_EXTENSIONS=$(ssh "$SRV" "docker exec ${PG_CONTENEUR} psql -U axion -d axion_crm -tAc \"
    SELECT e.extname||'@'||n.nspname
    FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace\" | sort")
echo "  extensions en production : $(printf '%s\n' "$REFERENCE_EXTENSIONS" | grep -c . || true)"

# --- 3. Restauration --------------------------------------------------------
echo "[3/6] Restauration dans « ${BASE_DRILL} »…"
scp "$SRV:${SCRATCH}/${DERNIER}" "$SCRATCH/" >/dev/null
DEBUT_RESTAURE=$(date +%s)

docker exec "$PG_CONTENEUR" psql -U axion -d postgres -q \
    -c "DROP DATABASE IF EXISTS ${BASE_DRILL} WITH (FORCE);" \
    -c "CREATE DATABASE ${BASE_DRILL} OWNER axion;"

# Le journal est CONSERVÉ : c'est lui qui a permis d'identifier la panne du
# 2026-08-16 (`function unaccent(text) does not exist`).
#
# ⚠️ LA SECTION DES RÔLES EST RETIRÉE DU FLUX — constat A08-008 (2026-08-20).
#
# Depuis le correctif, l'archive porte `pg_dumpall --globals-only` entre deux
# marqueurs. Rejouée telle quelle ICI, elle produirait `CREATE ROLE axion;` sur
# un cluster où `axion` existe déjà : autant de lignes `ERROR`, donc un exercice
# en échec pour une raison qui n'en est pas une. L'exercice restaure sur le
# cluster de départ, où les rôles sont déjà en place — c'est justement ce que
# l'étape 5 vérifie.
JOURNAL="$SCRATCH/restauration.log"
# Une archive chiffrée passe d'abord par openssl ; une archive en clair
# (d'avant le 2026-09-03) est lue directement. On ne devine pas au contenu :
# on se fie à l'extension, seul contrat stable entre les deux scripts.
if [ "${DERNIER%.enc}" != "${DERNIER}" ]; then
    if [ -z "${BACKUP_ENCRYPTION_PASSPHRASE:-}" ]; then
        echo "❌ Archive chiffrée mais BACKUP_ENCRYPTION_PASSPHRASE absente." >&2
        echo "   L'exercice ne peut rien prouver sans elle — c'est justement" >&2
        echo "   ce qu'il est censé vérifier. Renseigne-la et relance." >&2
        exit 1
    fi
    lire_archive() {
        openssl enc -d -aes-256-cbc -salt -pbkdf2 -iter 100000 \
            -pass "env:BACKUP_ENCRYPTION_PASSPHRASE" -in "$SCRATCH/${DERNIER}" | zcat
    }
else
    lire_archive() { zcat "$SCRATCH/${DERNIER}"; }
fi

lire_archive \
    | sed "\|${MARQUEUR_GLOBALS_DEBUT}|,\|${MARQUEUR_GLOBALS_FIN}|d" \
    | docker exec -i "$PG_CONTENEUR" psql -U axion -d "$BASE_DRILL" -q \
    > "$JOURNAL" 2>&1 || true
DUREE_RESTAURE=$(( $(date +%s) - DEBUT_RESTAURE ))

ERREURS=$(grep -c '^ERROR' "$JOURNAL" || true)
echo "  Restauration en ${DUREE_RESTAURE}s — ${ERREURS} erreur(s)"
if [ "$ERREURS" -gt 0 ]; then
    echo "  Erreurs distinctes :" >&2
    grep '^ERROR' "$JOURNAL" | sort | uniq -c | sort -rn | head -10 | sed 's/^/    /' >&2
    echo "❌ Restauration en erreur — journal complet : $JOURNAL" >&2
    exit 3
fi
echo "  ✓ Restauration sans erreur"

# --- 4. Les comptages correspondent-ils ? -----------------------------------
# LE test. Une restauration « sans erreur » mais vide reste un échec : c'est
# exactement ce qui s'est produit le 2026-08-16 avant le correctif search_path.
echo "[4/6] Comparaison des comptages…"
RESTAURE=$(docker exec "$PG_CONTENEUR" psql -U axion -d "$BASE_DRILL" -tAc "
    SELECT 'companies='||count(*) FROM companies
    UNION ALL SELECT 'contacts='||count(*) FROM contacts
    UNION ALL SELECT 'scraper_runs='||count(*) FROM scraper_runs
    UNION ALL SELECT 'company_tag='||count(*) FROM company_tag
    UNION ALL SELECT 'journalists='||count(*) FROM journalists" | sort)

if [ "$REFERENCE" != "$RESTAURE" ]; then
    echo "❌ ÉCART entre production et restauration :" >&2
    diff <(echo "$REFERENCE") <(echo "$RESTAURE") | sed 's/^/    /' >&2
    exit 4
fi
echo "$RESTAURE" | sed 's/^/    /'
echo "  ✓ Comptages identiques à la production"

# 🔴 Et les EXTENSIONS — constat F39-005. Même comparaison, autre moitié du mur.
RESTAURE_EXTENSIONS=$(docker exec "$PG_CONTENEUR" psql -U axion -d "$BASE_DRILL" -tAc "
    SELECT e.extname||'@'||n.nspname
    FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace" | sort)

# Témoin de couverture : une comparaison entre deux ensembles VIDES est vraie et
# ne prouve rien. Si la production n'a rendu aucune extension, c'est la mesure
# qui a échoué, pas la base qui est saine.
if [ -z "$(printf '%s' "$REFERENCE_EXTENSIONS" | tr -d '[:space:]')" ]; then
    echo "❌ Le relevé des extensions de production est VIDE — la mesure a échoué." >&2
    echo "   Comparer deux ensembles vides serait vert et ne prouverait rien." >&2
    exit 4
fi

if [ "$REFERENCE_EXTENSIONS" != "$RESTAURE_EXTENSIONS" ]; then
    echo "❌ CONSTAT F39-005 : ÉCART d'extensions entre production et restauration :" >&2
    diff <(echo "$REFERENCE_EXTENSIONS") <(echo "$RESTAURE_EXTENSIONS") | sed 's/^/    /' >&2
    echo "   Les comptages de lignes ci-dessus sont identiques et ne prouvent RIEN là-dessus :" >&2
    echo "   une extension manquante n'échoue pas à la restauration, elle échoue à la PREMIÈRE" >&2
    echo "   requête qui s'en sert — c'est la panne du 2026-08-16, « function unaccent(text)" >&2
    echo "   does not exist »." >&2
    exit 4
fi
echo "  ✓ Extensions identiques à la production ($(printf '%s\n' "$RESTAURE_EXTENSIONS" | grep -c . || true))"

# --- 5. 🔴 LES DROITS — constat A08-008 (S1), ajouté le 2026-08-20 -----------
#
# CE QUE CET EXERCICE NE POUVAIT PAS VOIR, ET POURQUOI.
#
# Les étapes 2 et 4 jouent `psql -U axion`. Mesuré sur le cluster :
#
#     SELECT rolname, rolsuper, rolbypassrls FROM pg_roles WHERE rolcanlogin;
#     axion      | t | t
#     axion_app  | f | f
#
# `axion` est SUPERUTILISATEUR et porte BYPASSRLS : il lit tout, quels que
# soient les GRANT et quelle que soit la RLS. Un comptage joué avec lui rend le
# même chiffre sur une base saine et sur une base où l'application ne peut RIEN
# lire. L'exercice vérifiait donc la seule chose qui ne pouvait pas échouer.
#
# C'est le défaut le plus coûteux de la famille : un contrôle qui rassure. La
# sauvegarde a passé cet exercice pendant tout le temps où elle produisait des
# archives sans un seul GRANT (`--no-acl`) et sans un seul rôle (pas de
# `pg_dumpall --globals-only`).
#
# `has_table_privilege` répond exactement à la question posée par le constat —
# « le rôle applicatif peut-il lire cette table » — et à aucune autre : elle ne
# dépend ni du contenu, ni de la RLS (qui filtre des lignes, pas l'accès).
echo "[5/6] Droits du rôle applicatif « ${ROLE_APPLICATIF} » sur la base restaurée…"

ROLE_PRESENT=$(docker exec "$PG_CONTENEUR" psql -U axion -d postgres -tAc \
    "SELECT count(*) FROM pg_roles WHERE rolname = '${ROLE_APPLICATIF}'")
if [ "$ROLE_PRESENT" -eq 0 ]; then
    echo "❌ Le rôle applicatif « ${ROLE_APPLICATIF} » n'existe pas sur ce cluster." >&2
    echo "   L'archive doit le porter (section \`pg_dumpall --globals-only\`) : sur un" >&2
    echo "   serveur RECONSTRUIT après sinistre, rien d'autre ne le recréerait, et" >&2
    echo "   l'application ne pourrait même pas ouvrir de session." >&2
    exit 6
fi

ILLISIBLES=$(docker exec "$PG_CONTENEUR" psql -U axion -d "$BASE_DRILL" -tAc "
    SELECT count(*)
    FROM pg_class c
    JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE n.nspname = 'public'
      AND c.relkind IN ('r', 'p')
      AND NOT has_table_privilege('${ROLE_APPLICATIF}', c.oid, 'SELECT')")

if [ "$ILLISIBLES" -gt 0 ]; then
    echo "❌ CONSTAT A08-008 : ${ILLISIBLES} table(s) publique(s) illisibles par « ${ROLE_APPLICATIF} »." >&2
    echo "   La restauration a rendu les DONNÉES mais pas les DROITS : l'application" >&2
    echo "   échouerait sur « permission denied for table … » dès la première requête." >&2
    echo "   Les comptages de l'étape 4 sont identiques et ne prouvent rien — ils ont" >&2
    echo "   été pris en superutilisateur." >&2
    echo "   Cause la plus probable : archive produite avec \`--no-acl\`." >&2
    exit 6
fi
echo "  ✓ Aucune table illisible par le rôle applicatif (${ROLE_APPLICATIF})"

# --- 6. RTO -----------------------------------------------------------------
DUREE_TOTALE=$(( $(date +%s) - DEBUT ))
echo "[6/6] RTO simulé : ${DUREE_TOTALE}s ($((DUREE_TOTALE / 60)) min)"
if [ "$DUREE_TOTALE" -gt "$RTO_CIBLE_S" ]; then
    echo "❌ RTO violé : > $((RTO_CIBLE_S / 3600)) h" >&2
    exit 5
fi
echo "  ✓ RTO sous la cible"

# --- Nettoyage --------------------------------------------------------------
if [ "$NO_CLEANUP" != "--no-cleanup" ]; then
    docker exec "$PG_CONTENEUR" psql -U axion -d postgres -q \
        -c "DROP DATABASE IF EXISTS ${BASE_DRILL} WITH (FORCE);"
    ssh "$SRV" "rm -rf ${SCRATCH}"
    rm -rf "$SCRATCH"
    echo "Nettoyage fait (--no-cleanup pour conserver la base restaurée)."
fi

echo "=== EXERCICE RÉUSSI ==="
