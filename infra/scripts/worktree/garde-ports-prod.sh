#!/usr/bin/env bash
# Rejoue À LA MAIN le job CI `config-prod` : la configuration de PRODUCTION
# fusionnée ne doit publier aucun port hors 80 et 443.
#
# Pourquoi ce contrôle existe — faille mesurée le 2026-08-19 :
# `docker-compose.yml` publie 55432 (Postgres) et 56379 (Redis) pour le confort
# du poste de développement. En production ces ports écoutent sur 0.0.0.0, et
# Docker insère ses règles iptables AVANT celles d'ufw : `ufw status` annonçait
# « fermé » pendant que la base répondait depuis internet, en superutilisateur,
# avec un mot de passe publié dans un dépôt public.
#
# Il lit la configuration FUSIONNÉE, pas le texte d'un fichier : `ports: []`
# sans le tag `!override` est un no-op silencieux (Compose fusionne les listes).
set -uo pipefail

WORKTREE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
cd "$WORKTREE"

if [ ! -f .env ]; then
  echo "ERREUR : .env absent à la racine — exigé par \`docker compose config\`." >&2
  echo "  cp .env.example .env" >&2
  exit 2
fi

FUSION="$(mktemp)"
trap 'rm -f "$FUSION"' EXIT

MSYS_NO_PATHCONV=1 docker compose -f docker-compose.yml -f docker-compose.prod.yml config > "$FUSION"

publies="$(awk -F'published: ' '/published: /{gsub(/"/,"",$2); print $2}' "$FUSION" | sort -u)"
echo "Ports publiés : ${publies:-aucun}"

# TÉMOIN POSITIF — la mesure a-t-elle seulement fonctionné ?
# La production publie forcément 80 et 443 (Caddy). Ne pas les trouver ne veut
# pas dire « tout va bien », ça veut dire « le contrôle est cassé ».
for attendu in 80 443; do
  if ! echo "$publies" | grep -qx "$attendu"; then
    echo "ROUGE — témoin positif en échec : le port $attendu (Caddy) est introuvable." >&2
    echo "L'extraction ne fonctionne pas : ce contrôle ne mesure rien." >&2
    exit 1
  fi
done

inattendus=""
for port in $publies; do
  case "$port" in 80|443) ;; *) inattendus="$inattendus $port" ;; esac
done

if [ -n "$inattendus" ]; then
  echo "ROUGE — ports non autorisés en production :$inattendus" >&2
  echo "Fermer avec « ports: !override [] » dans docker-compose.prod.yml." >&2
  exit 1
fi

echo "VERT — la configuration de production ne publie que 80 et 443."
