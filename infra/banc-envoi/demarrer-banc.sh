#!/usr/bin/env bash
# Démarre le banc d'envoi K10 (7 conteneurs légers, réseau internal, AUCUN port publié).
# Lancé depuis le workflow banc-envoi.yml ; le banc ne publie rien, donc la garde des
# ports publiés (infra/scripts/verifier-ports-publies.sh) n'a pas de pile à mesurer ici.
set -euo pipefail
cd "$(dirname "$0")"
# Garde-fou local : le fichier compose ne doit déclarer aucun port publié.
if grep -Eq '^[[:space:]]*ports:' docker-compose.yml; then
  echo "ERREUR : le banc ne doit publier aucun port." >&2
  exit 1
fi
docker compose up -d --wait --wait-timeout 60 dns-banc puits-accepte puits-4xx puits-5xx puits-lent imap-banc compteur
