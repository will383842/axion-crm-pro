#!/usr/bin/env bash
# Lanceur cron de l'exercice de restauration (dr-drill.sh).
#
# ⚠️ POURQUOI CE LANCEUR EXISTE. `dr-drill.sh` est écrit pour être lancé « depuis
# un poste ayant l'accès SSH » : il rapatrie l'archive via `ssh $SRV`, puis
# restaure dans un conteneur Postgres LOCAL. Sur un poste de travail ce
# conteneur n'existe pas ; sur le serveur, `ssh localhost` doit fonctionner.
# On le lance donc ICI, sur le serveur, avec SRV=localhost — le seul endroit où
# les deux moitiés du script parlent de la même machine.
#
# Deux conditions ont longtemps rendu l'exercice inexécutable, et toutes deux
# sont désormais tenues :
#   · 25 Go libres exigés (garde du script) → purge du cache de build en cron ;
#   · `ssh localhost` pour root → clé publique de root dans ses authorized_keys.
set -euo pipefail
set -a
# shellcheck disable=SC1091
source <(grep -E '^(SB_|BACKUP_ENCRYPTION_PASSPHRASE)' /opt/axion-crm-pro/.env)
set +a
export SRV=localhost
exec bash /opt/axion-crm-pro/infra/scripts/dr-drill.sh "$@"
