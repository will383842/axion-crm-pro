#!/usr/bin/env bash
# Joue `php artisan` du CODE DU WORKTREE contre une base choisie.
# Sert notamment à prouver la réversibilité d'une migration sur une base jetable.
#
#   ./artisan-worktree.sh axion_crm_perf migrate --force
#   ./artisan-worktree.sh axion_crm_perf migrate:rollback --step=1 --force
#   ./artisan-worktree.sh axion_crm_test  migrate:status
#
# ⚠️ Le premier argument est le NOM DE LA BASE, et il est obligatoire : aucune
# valeur par défaut, pour qu'aucune commande ne parte sur `axion_crm` (la base de
# développement) par simple oubli.
set -uo pipefail

if [ $# -lt 2 ]; then
  echo "usage : $0 <base> <commande artisan…>" >&2
  exit 2
fi

BASE="$1"; shift

case "$BASE" in
  axion_crm|postgres)
    echo "ERREUR : refus de viser « $BASE » — utiliser une base jetable." >&2
    exit 2
    ;;
esac

WORKTREE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
DEPOT_PRINCIPAL="${AXION_DEPOT_PRINCIPAL:-$(dirname "$WORKTREE")/Axion-CRM-Pro}"

MSYS_NO_PATHCONV=1 docker run --rm \
  --network axion-crm \
  -v "$(cygpath -w "$WORKTREE/backend" 2>/dev/null || echo "$WORKTREE/backend")":/var/www/html \
  -v "$(cygpath -w "$DEPOT_PRINCIPAL/backend/vendor" 2>/dev/null || echo "$DEPOT_PRINCIPAL/backend/vendor")":/var/www/html/vendor \
  -e APP_ENV=local -e APP_DEBUG=false \
  -e DB_CONNECTION=pgsql -e DB_HOST=axion-crm-postgres -e DB_PORT=5432 \
  -e DB_DATABASE="$BASE" -e DB_USERNAME=axion -e DB_PASSWORD=axion_dev_only \
  -e CACHE_STORE=array -e REDIS_HOST=axion-crm-redis \
  -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=array \
  -e TELESCOPE_ENABLED=false -e PULSE_ENABLED=false \
  -e CRM_DB_APP_ROLE_ENABLED=false \
  -w /var/www/html --entrypoint php \
  axion-crm-pro-api artisan "$@"
