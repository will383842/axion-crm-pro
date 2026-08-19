#!/usr/bin/env bash
# Vérifie qu'AUCUN conteneur de la pile, TEL QU'IL TOURNE, ne sert HTTP par le
# serveur de développement intégré de PHP (`php -S`).
#
# ── Pourquoi ce script existe ───────────────────────────────────────────────
# Le 2026-08-19, la production servait `https://api.axion-crm-pro.com` avec :
#
#   PID 1  www-data  php -S 0.0.0.0:80 -t public
#
# Un seul processus, aucun enfant, `PHP_CLI_SERVER_WORKERS` non posé : le
# serveur intégré de PHP traite UNE requête à la fois. Mesuré sur la production,
# en lecture seule : 50 requêtes simultanées sur `/up` s'exécutent en 1,075 s et
# la latence maximale atteint 0,882 s, contre 0,018 s pour la même requête seule.
# Une sérialisation linéaire, parfaite. La documentation de PHP dit de ce
# serveur : « not intended to be used on a public network ».
#
# Conséquence : le critère « dix sessions simultanées » est structurellement
# inatteignable, et une seule requête lente bloque TOUS les autres utilisateurs,
# healthcheck compris. C'est le constat A-010 (S0).
#
# ── CE QUE CE CONTRÔLE MESURE, ET CE QU'IL REFUSE DE MESURER ────────────────
# 🔴 Il regarde les PROCESSUS DES CONTENEURS QUI TOURNENT, jamais un Dockerfile.
#
# Un contrôle qui lirait `CMD` dans `Dockerfile.laravel` serait irréprochable et
# mesurerait le mauvais objet : la commande réelle peut venir du Dockerfile, de
# `command:` dans un compose, d'un `entrypoint`, ou d'une main sur le serveur.
# Seul `docker exec … ps` dit ce qui sert vraiment les requêtes.
#
# C'est la même leçon que `verifier-ports-publies.sh` : une garde ne vaut que si
# elle rougit SUR L'OBJET QUI CASSE.
#
# Usage, sur n'importe quelle machine qui porte la pile :
#   bash verifier-serveur-http.sh                  # projet axion-crm-pro
#   bash verifier-serveur-http.sh axion-crm-staging
#
# Sortie 0 = conforme · 1 = un conteneur sert HTTP par `php -S` · 2 = mesure impossible
set -uo pipefail

PROJET="${1:-axion-crm-pro}"

if ! command -v docker > /dev/null 2>&1; then
  echo "ERREUR : docker introuvable — mesure impossible." >&2
  exit 2
fi

CONTENEURS="$(docker ps --filter "label=com.docker.compose.project=${PROJET}" \
  --format '{{.Names}}' 2>/dev/null)"

if [ -z "$CONTENEURS" ]; then
  # Témoin de mesure. « Aucun `php -S` » ne vaut rien si le contrôle n'a rien
  # regardé : une pile éteinte passerait au vert, ce qui est exactement le
  # défaut qu'avait la première version de la garde `config-prod`.
  echo "ERREUR : aucun conteneur du projet « ${PROJET} » — la mesure n'a rien vu." >&2
  echo "         (un résultat « conforme » sur zéro conteneur serait un mensonge)" >&2
  exit 2
fi

echo "=== commandes du processus 1 des conteneurs de « ${PROJET} » ==="

FAUTIFS=""
SERVEURS_HTTP=""
MESURES=0

for c in $CONTENEURS; do
  # `/proc/1/cmdline` D'ABORD, et `ps` seulement en repli : les images minimales
  # n'ont PAS `ps` (mesuré sur `ghcr.io/…/axion-crm-pro-postgres`, qui rend
  # « exec: "ps": executable file not found »). Un contrôle qui commence par
  # `ps` laisse donc des conteneurs non mesurés, ou pire, prend le message
  # d'erreur pour une ligne de commande.
  #
  # `docker exec` N'A PAS d'option `-T` (c'est `docker compose exec` qui l'a) :
  # la passer ferait échouer la commande avec « unknown shorthand flag », et le
  # contrôle n'aurait RIEN mesuré tout en paraissant fonctionner.
  CMD="$(docker exec "$c" sh -c 'tr "\0" " " < /proc/1/cmdline' 2>/dev/null)"
  if [ -z "$CMD" ]; then
    CMD="$(docker exec "$c" ps -o args= -p 1 2>/dev/null)"
  fi

  if [ -z "$CMD" ]; then
    echo "  $c :: (illisible — ni ps ni /proc/1/cmdline)"
    continue
  fi

  MESURES=$((MESURES + 1))
  printf '  %-32s :: %s\n' "$c" "$CMD"

  # Un conteneur qui SERT HTTP, quel que soit le moteur. Sert au témoin positif
  # plus bas : si on n'en trouve aucun, c'est la mesure qui est cassée, pas la
  # pile qui est saine.
  case "$CMD" in
    *php\ -S*|*php-fpm*|*caddy*|*nginx*|*apache*|*httpd*)
      SERVEURS_HTTP="$SERVEURS_HTTP $c" ;;
  esac

  # Le cas à attraper. `php -S` sans plus, ET `php -S` avec des workers : ce
  # dernier est moins grave (il fork), mais reste un serveur de développement
  # sans supervision d'enfants. On le signale à part plutôt que de le taire.
  case "$CMD" in
    *php\ -S*)
      WORKERS="$(docker exec "$c" sh -c 'printf %s "${PHP_CLI_SERVER_WORKERS:-}"' 2>/dev/null)"
      if [ -n "$WORKERS" ]; then
        echo "      ⚠️ php -S avec PHP_CLI_SERVER_WORKERS=$WORKERS — atténué, mais toujours le serveur de développement"
      else
        echo "      🔴 php -S SANS PHP_CLI_SERVER_WORKERS — une requête à la fois"
      fi
      FAUTIFS="$FAUTIFS $c"
      ;;
  esac
done

echo

if [ "$MESURES" -eq 0 ]; then
  echo "ERREUR : aucune commande lisible sur aucun conteneur — la mesure n'a rien vu." >&2
  exit 2
fi

# TÉMOIN POSITIF. Si la pile ne contient AUCUN serveur HTTP, ce contrôle ne
# mesure pas ce qu'il croit : mauvais projet, filtre cassé, ou pile réduite.
# Sans lui, une pile de bases de données seules passerait au vert en annonçant
# « aucun php -S » — vrai, et sans aucune valeur.
if [ -z "$SERVEURS_HTTP" ]; then
  echo "ÉCHEC (témoin positif) : aucun serveur HTTP trouvé dans la pile." >&2
  echo "  Une pile qui ne sert rien ne prouve rien sur la façon dont elle sert." >&2
  echo "  Le contrôle ne vaut pas tant que celui-ci ne passe pas." >&2
  exit 2
fi

echo "Conteneurs qui servent HTTP :$SERVEURS_HTTP"

if [ -n "$FAUTIFS" ]; then
  echo >&2
  echo "ÉCHEC : ces conteneurs servent HTTP par le serveur de développement de PHP :$FAUTIFS" >&2
  echo >&2
  echo "  « php -S » traite UNE requête à la fois (sauf PHP_CLI_SERVER_WORKERS)." >&2
  echo "  Mesuré sur la production le 2026-08-19 : 50 requêtes simultanées sur" >&2
  echo "  /up → 1,075 s au total, 0,882 s de latence maximale, contre 0,018 s" >&2
  echo "  pour la même requête seule. Constat A-010." >&2
  echo >&2
  echo "  Correctif de fond — php-fpm est DÉJÀ dans l'image (/usr/local/sbin/php-fpm," >&2
  echo "  PHP 8.3.33 fpm-fcgi), il n'est simplement jamais lancé :" >&2
  echo "    · Dockerfile.laravel, cible prod : CMD [\"php-fpm\"]" >&2
  echo "    · php-fpm.d : listen = 0.0.0.0:9000 et pm.max_children ≥ 20" >&2
  echo "      (le défaut de l'image est 5 : insuffisant pour dix sessions)" >&2
  echo "    · Caddyfile : reverse_proxy api:9000 { transport fastcgi { root /var/www/html/public } }" >&2
  echo "    · le healthcheck curl http://localhost/up doit devenir un test fastcgi" >&2
  echo >&2
  echo "  Repli immédiat, sans reconstruire l'image : poser" >&2
  echo "  PHP_CLI_SERVER_WORKERS=8 dans l'environnement du service, puis" >&2
  echo "  « up -d --force-recreate » (PAS « restart » : il ne relit pas env_file)." >&2
  exit 1
fi

echo "OK : aucun conteneur ne sert HTTP par « php -S » — mesuré sur les processus, pas sur le Dockerfile."
