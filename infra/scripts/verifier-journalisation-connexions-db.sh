#!/usr/bin/env bash
#
# La base journalise-t-elle ses connexions ? — mesure n° 7 du registre des
# violations de données (`_REPORTS/REGISTRE-DES-VIOLATIONS-DE-DONNEES.md`).
#
# 🔴 CE SCRIPT INTERROGE LE SERVEUR QUI TOURNE, PAS LE FICHIER COMPOSE.
#
# C'est toute la leçon de l'incident du 19 août, et elle est écrite dans le
# registre : le correctif qui retirait la publication des ports a été fusionné,
# déployé avec succès, et n'avait RIEN FERMÉ — parce qu'un déploiement ne recrée
# pas les conteneurs de base de données. Un contrôle qui lit `docker-compose.yml`
# aurait affiché « conforme » pendant que la porte restait ouverte.
#
# Ici : on demande à PostgreSQL lui-même, par `SHOW`, ce qu'il applique.
#
# Usage :
#   bash infra/scripts/verifier-journalisation-connexions-db.sh [conteneur]
#
# Sortie 0 = les trois réglages sont actifs. Sortie 1 = au moins un manque.
set -uo pipefail

CONTENEUR="${1:-axion-crm-postgres}"
UTILISATEUR="${POSTGRES_USER:-axion}"
BASE="${POSTGRES_DB:-axion_crm}"

echo "── Journalisation des connexions · conteneur « ${CONTENEUR} » ──"

if ! docker inspect "${CONTENEUR}" >/dev/null 2>&1; then
  echo "ERREUR : le conteneur « ${CONTENEUR} » n'existe pas."
  echo "         Passer son nom en argument, ex. : $0 crmverif-postgres"
  exit 1
fi

if [ "$(docker inspect -f '{{.State.Running}}' "${CONTENEUR}" 2>/dev/null)" != "true" ]; then
  echo "ERREUR : le conteneur « ${CONTENEUR} » ne tourne pas."
  echo "         Un serveur arrêté ne prouve rien, ni dans un sens ni dans l'autre."
  exit 1
fi

demander() { # nom_du_reglage
  docker exec "${CONTENEUR}" psql -U "${UTILISATEUR}" -d "${BASE}" -tAc "SHOW $1;" 2>/dev/null | tr -d '\r' | tr -d ' '
}

MANQUE=0

for reglage in log_connections log_disconnections; do
  valeur="$(demander "${reglage}")"
  if [ "${valeur}" = "on" ]; then
    printf '  ✅ %-22s = %s\n' "${reglage}" "${valeur}"
  else
    printf '  ❌ %-22s = %s   (attendu : on)\n' "${reglage}" "${valeur:-<vide>}"
    MANQUE=1
  fi
done

# `%h` est l'adresse d'où vient la connexion. Sans elle, on saurait qu'il y a eu
# des connexions sans pouvoir dire d'où — c'est-à-dire sans pouvoir analyser
# quoi que ce soit. C'est le champ qui a manqué le 19 août.
PREFIXE="$(docker exec "${CONTENEUR}" psql -U "${UTILISATEUR}" -d "${BASE}" -tAc 'SHOW log_line_prefix;' 2>/dev/null | tr -d '\r')"
if printf '%s' "${PREFIXE}" | grep -q '%h'; then
  printf '  ✅ %-22s porte %%h  → « %s »\n' "log_line_prefix" "${PREFIXE}"
else
  printf '  ❌ %-22s SANS %%h  → « %s »\n' "log_line_prefix" "${PREFIXE}"
  echo "     Sans %h, le journal ne dit pas d'OU vient la connexion."
  MANQUE=1
fi

echo
if [ "${MANQUE}" -eq 0 ]; then
  echo "✅ Les connexions à la base sont journalisées, avec leur origine."
  echo "   Les lire :  docker logs ${CONTENEUR} 2>&1 | grep -i 'connection'"
  exit 0
fi

echo "❌ La base ne journalise PAS ses connexions comme elle le devrait."
echo
echo "   Ce n'est pas un défaut d'observabilité de confort. C'est l'absence de"
echo "   ces journaux qui a empêché, le 2026-08-19, de démontrer qu'aucun accès"
echo "   non autorisé n'avait eu lieu — et qui a rendu la décision de l'article"
echo "   33 du RGPD plus lourde à porter qu'elle n'aurait dû l'être."
echo
echo "   Le réglage vit dans le service \`postgres\` de \`docker-compose.yml\`."
echo "   ⚠️ Le poser NE SUFFIT PAS : un déploiement ne recrée pas les conteneurs"
echo "      de base de données. Il faut le geste explicite, une fois :"
echo
echo "        cd /opt/axion-crm-pro"
echo "        export COMPOSE_FILE=\"docker-compose.yml:docker-compose.prod.yml\""
echo "        docker compose up -d --no-deps postgres"
echo "        bash infra/scripts/verifier-journalisation-connexions-db.sh"
echo
exit 1
