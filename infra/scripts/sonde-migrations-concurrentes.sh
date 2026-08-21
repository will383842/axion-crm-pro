#!/bin/sh
# ─────────────────────────────────────────────────────────────────────────────
# SONDE : trois `migrate:fresh` LANCÉS ENSEMBLE sur trois bases DISTINCTES
# du MÊME cluster Postgres.
#
# Pourquoi elle existe (mesuré le 2026-08-21) : `CREATE ROLE` et `ALTER ROLE`
# écrivent dans `pg_authid`, table **partagée par toutes les bases** de
# l'instance. Deux migrations concurrentes réécrivent la même ligne, et Postgres
# refuse la seconde :
#
#   SQLSTATE[XX000]: Internal error: 7 ERROR:  tuple concurrently updated
#   (Database: axion_crm_test_lot4,
#    SQL: ALTER ROLE axion_app NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE)
#
# Ce n'est pas qu'un confort de banc : la même collision attend le premier
# déploiement où deux `php artisan migrate` se recouvrent — un déploiement
# rejoué, deux conteneurs démarrés ensemble, un bleu-vert.
#
# ⚠️ CE QUE CETTE SONDE NE FAIT PAS : elle n'est PAS dans la suite de tests, et
# c'est un choix — trois `migrate:fresh` coûtent une minute et une base chacun.
# Elle se joue à la main, avant de toucher `2026_08_14_000001`. Le corollaire
# doit être dit : **rien ne rougira** si quelqu'un rend de nouveau l'écriture
# inconditionnelle. La protection est ce fichier plus la lecture, pas une porte.
#
# USAGE :  sh infra/scripts/sonde-migrations-concurrentes.sh [conteneur] [lots...]
# ATTENDU : aucune ligne « Internal error » / « SQLSTATE ». Sinon, la collision
#           est de retour.
# ─────────────────────────────────────────────────────────────────────────────
set -u

CONTENEUR="${1:-a35r}"
shift 2>/dev/null || true
LOTS="${*:-12 13 14}"

echo "Sonde : migrate:fresh simultanés sur les lots [$LOTS] du conteneur $CONTENEUR"

SORTIE="$(mktemp)"

for L in $LOTS; do
  docker exec "$CONTENEUR" sh -c "cd /var/www/html && \
    DB_DATABASE=axion_crm_test_lot$L APP_ENV=testing DB_CONNECTION=pgsql \
    DB_APP_USERNAME=axion_app DB_APP_PASSWORD=axion_app_test_only \
    APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= SKIP_ENV_VALIDATION=true \
    php artisan migrate:fresh --force --no-interaction 2>&1 | sed 's/^/[lot$L] /'" >>"$SORTIE" 2>&1 &
done
wait

ERREURS="$(grep -cE 'Internal error|SQLSTATE' "$SORTIE" || true)"

if [ "$ERREURS" -eq 0 ]; then
  echo "OK : les migrations concurrentes se sont toutes appliquées."
  rm -f "$SORTIE"
  exit 0
fi

echo "ÉCHEC : $ERREURS ligne(s) d'erreur. La collision sur pg_authid est de retour."
grep -E 'Internal error|SQLSTATE|SQL: ' "$SORTIE" | head -20
rm -f "$SORTIE"
exit 1
