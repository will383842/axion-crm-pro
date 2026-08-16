#!/bin/sh
# =============================================================================
# Entrypoint de l'image `prod` — construit les caches AU DÉMARRAGE.
# =============================================================================
#
# 🔴 POURQUOI CE FICHIER EXISTE.
#
# L'étape `builder` faisait `php artisan config:cache` PENDANT LE BUILD. Or au
# build il n'y a pas de `.env` : ni `DB_HOST`, ni `DB_PASSWORD`, ni `REDIS_HOST`,
# ni `DB_TIMEZONE`. Laravel gelait donc un `bootstrap/cache/config.php` rempli
# de valeurs par défaut — `DB_HOST=127.0.0.1` au lieu de `postgres`, mot de
# passe vide — et, sous config mise en cache, `env()` retourne NUL : plus rien
# ne relit le `.env` au démarrage.
#
# Ce piège a déjà été payé sur ce projet le 2026-08-14 (« env() est NUL sous
# config:cache »). Basculer la production sur l'image `prod` en l'état l'aurait
# mise à terre au premier démarrage, avec une base injoignable.
#
# On construit donc les caches ICI, à l'exécution, quand `env_file` a été
# injecté — c'est le seul moment où ils peuvent être justes.
#
# `config:clear` d'abord : si une image plus ancienne portait encore un cache
# construit au build, il doit disparaître avant qu'on en fabrique un bon.
echo "[entrypoint] purge des caches herites du build"
php artisan config:clear >/dev/null 2>&1 || true
php artisan route:clear  >/dev/null 2>&1 || true
php artisan view:clear   >/dev/null 2>&1 || true

# ⚠️ PAS de `set -e` ici, et c'est délibéré.
#
# Ces trois mises en cache sont une OPTIMISATION, pas une condition de
# fonctionnement : sans elles Laravel relit simplement `.env` et les routes à
# chaque requête — plus lent, parfaitement correct. Les faire échouer bloquer le
# démarrage transformerait un ralentissement en boucle de redémarrage, donc en
# panne totale. On échoue donc BRUYAMMENT dans le journal, et on démarre quand
# même.
#
# Le cas réel : `config:cache` déclenche le crochet de terminaison de Telescope,
# qui écrit en base. Base momentanément injoignable au démarrage (Postgres pas
# encore prêt) = commande bruyante. Ce n'est pas une raison de ne pas servir.
for etape in config route view; do
    if php artisan "${etape}:cache"; then
        echo "[entrypoint] ${etape}:cache OK"
    else
        echo "[entrypoint] ⚠️  ${etape}:cache A ECHOUE — demarrage SANS ce cache (plus lent, pas casse)" >&2
        php artisan "${etape}:clear" >/dev/null 2>&1 || true
    fi
done

echo "[entrypoint] demarrage : $*"
exec "$@"
