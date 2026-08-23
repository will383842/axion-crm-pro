#!/usr/bin/env bash
# Rafraichit, dans le conteneur du banc, TOUT ce qui vit hors de `backend/`.
#
# ═══════════════════════════════════════════════════════════════════════════
# POURQUOI CE SCRIPT EXISTE (mesures des 2026-08-22 et 2026-08-23)
# ═══════════════════════════════════════════════════════════════════════════
#
# Le conteneur du banc ne MONTE que `backend/`. Tout le reste — `.github/`,
# `spec/`, `ARCHITECTURE.md`, `.gitattributes`, `MOCKS-STRATEGY.md`,
# `load-tests/`, `infra/`, `_REPORTS/` — y arrive par `docker cp`, donc en
# COPIE FIGEE.
#
# Consequence mesuree : DOUZE gardes ont rougi sur des documents pourtant
# corrects. Elles lisaient soit la copie de la veille, soit rien du tout.
#
# ⚠️ ET LEUR « TEMOIN DE PRESENCE » N'Y VOIT RIEN. Ces gardes verifient que le
# fichier EXISTE avant de le juger — c'est bien, et cela attrape le cas « rien
# du tout ». Mais une copie PERIMEE existe : le temoin passe au vert et la garde
# juge un texte mort. Un temoin de presence ne protege pas de la vetuste.
#
# ⚠️ ET `docker cp` COPIE *DANS* LA CIBLE QUAND ELLE EXISTE DEJA. Un
# `docker cp _REPORTS conteneur:/var/www/_REPORTS` sur un dossier deja present
# cree `/var/www/_REPORTS/_REPORTS/` et laisse l'ancien intact — le
# rafraichissement croit avoir travaille, et rien n'a bouge. D'ou le `rm -rf`
# systematique avant chaque copie.
#
# ── POURQUOI UNE LISTE CALCULEE, ET NON ECRITE A LA MAIN ──────────────────
#
# La premiere version enumerait sept chemins. Il en manquait cinq, decouverts
# un par un, chacun au prix d'une garde rouge et d'une enquete. Une liste
# ecrite a la main vieillit en silence : elle ne dit jamais ce qu'elle a oublie.
# On calcule donc l'inverse — tout, SAUF ce qu'on exclut nommement. Ajouter un
# document a la racine n'exige plus de penser a ce script.
#
# A jouer avant toute suite qui lit un fichier hors `backend/`.
set -euo pipefail

CONTENEUR="${1:-a35r}"
RACINE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# Trois familles d'exclusions, et chacune a sa raison :
#
#  1. CE QUI EST DEJA MONTE. `backend/`, `infra/`, les `docker-compose*.yml` et
#     `Dockerfile.laravel` sont des montages bind : ils sont a jour par
#     construction, et `rm -rf` sur un montage rend « Resource busy » — ce qui,
#     sous `set -e`, ARRETE le script au milieu. La premiere version s'y est
#     cassee et a laisse la moitie des chemins non rafraichis.
#  2. CE QUI PESE POUR RIEN : `.git`, `node_modules`, les autres fronts.
#  3. LES SECRETS : `.env` n'a rien a faire dans une copie de confort, et le
#     conteneur a deja le sien.
EXCLUS='^(backend|frontend|workers|node_modules|\.git|\.env|infra|Dockerfile\.laravel|docker-compose.*\.yml)$'

cd "$RACINE"
n=0
for chemin in $(ls -A | grep -vE "$EXCLUS"); do
  docker exec "$CONTENEUR" sh -lc "rm -rf '/var/www/$chemin'"
  docker cp "$RACINE/$chemin" "$CONTENEUR:/var/www/$chemin" >/dev/null
  n=$((n + 1))
done

echo "  $n chemins rafraichis depuis $RACINE"
echo "Le banc voit desormais l'arbre tel qu'il est."
