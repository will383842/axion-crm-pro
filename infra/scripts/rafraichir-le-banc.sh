#!/usr/bin/env bash
# Rafraichit les copies HORS `backend/` dans le conteneur du banc.
#
# POURQUOI CE SCRIPT EXISTE (mesure du 2026-08-23).
#
# Le conteneur `a35r` ne MONTE que `backend/`. Tout le reste — `.github/`,
# `spec/`, `docs/`, `README.md`, `CONTRIBUTING.md`, `_REPORTS/`, `_AUDIT/` — y
# arrive par `docker cp`, donc en COPIE FIGEE.
#
# Consequence mesuree : huit gardes ont rougi sur des documents pourtant
# corriges. Elles lisaient la copie de la veille.
#
# ⚠️ ET LEUR « TEMOIN DE PRESENCE » NE VOIT RIEN. Ces gardes verifient que le
# fichier EXISTE avant de le juger — c'est bien, mais une copie PERIMEE existe.
# Le temoin passe au vert et la garde juge un texte mort. Un temoin de presence
# ne protege pas de la vetuste.
#
# A jouer avant toute suite qui lit un fichier hors `backend/`.
set -euo pipefail
CONTENEUR="${1:-a35r}"
RACINE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

for chemin in .github spec docs _REPORTS _AUDIT README.md CONTRIBUTING.md; do
  [ -e "$RACINE/$chemin" ] || continue
  docker exec "$CONTENEUR" sh -lc "rm -rf '/var/www/$chemin'"
  docker cp "$RACINE/$chemin" "$CONTENEUR:/var/www/$chemin"
  echo "  rafraichi : $chemin"
done
echo "Le banc voit desormais l'arbre tel qu'il est."
