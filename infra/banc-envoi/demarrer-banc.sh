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
# Secret de banc (mots de passe IMAP des boîtes témoins = temoins.mot_de_passe(BANC_SECRET, adresse)).
# Ordre : variable d'environnement fournie > fichier local .env.banc (ignoré par git, droits 600)
# > génération. Le secret est ainsi PERSISTANT : relancer le script (ou `docker compose up`
# après `source .env.banc`) garde les mêmes mots de passe. Jamais écrit dans le dépôt.
FICHIER_SECRET=".env.banc"
if [ -z "${BANC_SECRET:-}" ] && [ -s "$FICHIER_SECRET" ]; then
  BANC_SECRET="$(sed -n 's/^BANC_SECRET=//p' "$FICHIER_SECRET" | head -n1)"
fi
if [ -z "${BANC_SECRET:-}" ]; then
  BANC_SECRET="$(python3 -c 'import secrets; print(secrets.token_urlsafe(24))')"  # généré dans CE shell
  ( umask 077; printf 'BANC_SECRET=%s\n' "$BANC_SECRET" > "$FICHIER_SECRET" )
  chmod 600 "$FICHIER_SECRET"
  echo "BANC_SECRET généré et enregistré dans infra/banc-envoi/$FICHIER_SECRET (droits 600, ignoré par git)." >&2
fi
export BANC_SECRET
docker compose up -d --wait --wait-timeout 60 dns-banc puits-accepte puits-4xx puits-5xx puits-lent imap-banc compteur temoin-hors-banc
