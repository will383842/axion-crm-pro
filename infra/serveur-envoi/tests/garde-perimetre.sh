#!/usr/bin/env bash
# Garde de PERIMETRE du lot K3 : reservee aux branches feat/envoi-k3-*.
# Sur toute autre branche (PR etrangere, main, HEAD detache) elle SAUTE avec le code 0 :
# une PR sans rapport avec K3 qui touche un fichier hors infra/serveur-envoi/ ne rougit pas.
# Usage : garde-perimetre.sh <branche> [depot]   (code 0 = ok ou sautee ; 1 = hors perimetre)
set -uo pipefail
branche="${1:-}"; depot="${2:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)}"
if [[ "$branche" != feat/envoi-k3-* ]]; then echo "garde de perimetre sautee (branche '${branche:-?}' hors feat/envoi-k3-*)"; exit 0; fi
git -C "$depot" rev-parse >/dev/null 2>&1 || { echo "perimetre: pas un depot git"; exit 1; }
git -C "$depot" rev-parse --verify -q origin/main >/dev/null || { echo "perimetre: origin/main ABSENT (git fetch origin main) - echec explicite"; exit 1; }
hors="$(git -C "$depot" diff --name-only origin/main... | grep -Ev '^(infra/serveur-envoi/|\.github/workflows/ci\.yml$)' || true)"
[[ -z "$hors" ]] || { echo "hors perimetre: $hors"; exit 1; }
sup="$(git -C "$depot" diff origin/main... -- .github/workflows/ci.yml | grep -E '^-[^-]' || true)"
[[ -z "$sup" ]] || { echo "ci.yml : lignes retirees: $sup"; exit 1; }
echo "perimetre infra/serveur-envoi/ (+ etape ci.yml), ci.yml en ajout seulement"
