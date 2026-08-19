#!/usr/bin/env bash
# Joue une commande PHP (pest / phpstan / pint / php -l) sur le CODE DU WORKTREE,
# avec le `vendor` du dépôt principal monté par-dessus — un worktree neuf n'a pas
# de `vendor` et il n'est pas versionné.
#
# Image : celle de la CI (`axion-crm-pro-api`). Base : `axion_crm_test` sur le
# Postgres de la pile locale. Voir README.md de ce dossier.
#
#   ./pest-worktree.sh ./vendor/bin/pest tests/Feature/Crm
#   ./pest-worktree.sh ./vendor/bin/phpstan analyse --memory-limit=4G --no-progress
#   ./pest-worktree.sh ./vendor/bin/pint --test app/Crm/Console/CompteursHub.php
set -uo pipefail

WORKTREE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
DEPOT_PRINCIPAL="${AXION_DEPOT_PRINCIPAL:-$(dirname "$WORKTREE")/Axion-CRM-Pro}"

if [ ! -d "$DEPOT_PRINCIPAL/backend/vendor" ]; then
  echo "ERREUR : aucun vendor trouvé dans $DEPOT_PRINCIPAL/backend/vendor" >&2
  echo "Poser AXION_DEPOT_PRINCIPAL vers un arbre où \`composer install\` a tourné." >&2
  exit 2
fi

# 🔴 Le vendor du dépôt principal ne vaut QUE si les dépendances sont les mêmes.
# Un lockfile différent ferait tourner les tests contre d'autres versions que
# celles que la CI installera — un vert qui ne prouve rien.
if ! cmp -s "$WORKTREE/backend/composer.lock" "$DEPOT_PRINCIPAL/backend/composer.lock"; then
  echo "ERREUR : composer.lock diffère entre le worktree et $DEPOT_PRINCIPAL." >&2
  echo "Le vendor monté ne correspondrait pas au code testé. Faire \`composer install\` dans le worktree." >&2
  exit 2
fi

if [ ! -f "$WORKTREE/backend/.env" ]; then
  echo "ERREUR : backend/.env absent. \`cp .env.example backend/.env\` (cf. README.md)." >&2
  exit 2
fi

# `dirname(base_path())` = /var/www : NeDoitPasRegresserTest y cherche infra/ et
# .github/ à la racine du dépôt. On les monte à l'endroit où il regarde.
MSYS_NO_PATHCONV=1 docker run --rm \
  --network axion-crm \
  -v "$(cygpath -w "$WORKTREE/backend" 2>/dev/null || echo "$WORKTREE/backend")":/var/www/html \
  -v "$(cygpath -w "$DEPOT_PRINCIPAL/backend/vendor" 2>/dev/null || echo "$DEPOT_PRINCIPAL/backend/vendor")":/var/www/html/vendor \
  -v "$(cygpath -w "$WORKTREE/infra" 2>/dev/null || echo "$WORKTREE/infra")":/var/www/infra \
  -v "$(cygpath -w "$WORKTREE/.github" 2>/dev/null || echo "$WORKTREE/.github")":/var/www/.github \
  -e DB_HOST=axion-crm-postgres -e DB_PORT=5432 \
  -e DB_USERNAME=axion -e DB_PASSWORD=axion_dev_only \
  -e REDIS_HOST=axion-crm-redis \
  -e CRM_DB_APP_ROLE_ENABLED=false \
  -e TELESCOPE_ENABLED=false \
  -w /var/www/html \
  --entrypoint "$1" \
  axion-crm-pro-api "${@:2}"
