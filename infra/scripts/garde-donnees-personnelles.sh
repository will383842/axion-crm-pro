#!/usr/bin/env bash
#
# GARDE — aucune donnée personnelle nominative ne doit entrer dans ce dépôt.
#
# POURQUOI CETTE GARDE EXISTE
# ---------------------------------------------------------------------------
# Le dépôt `axion-crm-pro` est PUBLIC, et le restera (décision de Will,
# 2026-08-26). Sur un dépôt public, on ne peut pas se corriger soi-même :
# supprimer une branche ne retire rien, GitHub conserve `refs/pull/<n>/head` et
# le diff de la PR reste lisible par tous. Seul le support GitHub peut purger.
#
# Le 2026-08-26, `backend/database/data/presse-linkedin/contacts-2026-08-25.json`
# a exposé 412 journalistes NOMMÉS, avec employeur, audience et un score de
# démarchage explicite (« P1 — À contacter en premier »), en HTTP 200 sans
# authentification, pendant ~21 h. Le fichier n'avait jamais été importé nulle
# part : il n'existait QUE dans le dépôt public.
#
# La prévention n'est donc pas un confort ici. C'est le seul remède disponible.
#
# CE QUE LA GARDE CHERCHE, ET POURQUOI CES CLEFS-LÀ
# ---------------------------------------------------------------------------
# Le critère est DÉRIVÉ D'UNE MESURE, pas deviné :
#
#   - sur `main`, les clefs ci-dessous apparaissent **0 fois** dans les .json/.csv
#     suivis (mesuré le 2026-08-26) ;
#   - le fichier fautif en portait **412**.
#
# ⚠️ Le piège évité : ce fichier utilisait `"nom"`, PAS `first_name`. Une garde
# bâtie sur les seules clefs anglaises aurait été aveugle au fichier même
# qu'elle doit attraper. Ne JAMAIS retirer `nom`/`prenom` de cette liste.
#
# Ce qui a le droit d'être ici : des coordonnées d'ORGANISATIONS.
# `backend/database/data/press-kit/medias.json` contient `contact@bfmtv.com` —
# c'est une adresse de rédaction, pas une personne. La garde ne regarde donc PAS
# les adresses e-mail : elle regarde les clefs qui nomment un individu.
#
# USAGE
#   ./garde-donnees-personnelles.sh            # contrôle le dépôt
#   ./garde-donnees-personnelles.sh --temoin   # prouve que la garde sait rougir
#
set -uo pipefail   # PAS de `-e` : on veut lire le code de sortie, pas mourir dessus.

RACINE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$RACINE" || exit 2

# Clefs qui nomment une PERSONNE PHYSIQUE. Voir l'en-tête avant d'y toucher.
MOTIF='"(nom|prenom|prénom|first_name|last_name|full_name|fullname|nom_complet)"[[:space:]]*:'

# Fichiers explicitement autorisés malgré une correspondance, avec la raison.
# Vide à dessein : aucun fichier n'en a besoin aujourd'hui. Ajouter une ligne
# ici est une DÉCISION, qui doit être justifiée en revue.
AUTORISES=()

# ---------------------------------------------------------------------------
# Témoin négatif — la garde sait-elle seulement échouer ?
# Une garde qui ne rougit jamais ne garde rien. On lui présente un échantillon
# calqué sur le fichier de 2026-08-26 et on EXIGE qu'elle le détecte.
# ---------------------------------------------------------------------------
if [[ "${1:-}" == "--temoin" ]]; then
  echantillon="$(mktemp -t temoin-XXXXXX.json)"
  cat > "$echantillon" <<'ECHANTILLON'
{ "contacts": [ { "rang": 1, "nom": "Prenom Nomdefamille",
  "media_specialite": "Chaine - tech", "score": 102,
  "priorite_libelle": "P1 — À contacter en premier" } ] }
ECHANTILLON
  if grep -qEi "$MOTIF" "$echantillon"; then
    echo "TÉMOIN OK — la garde détecte bien un fichier nominatif."
    rm -f "$echantillon"; exit 0
  fi
  echo "::error::TÉMOIN EN ÉCHEC — la garde ne détecte plus rien. Le motif est cassé :"
  echo "::error::  $MOTIF"
  echo "::error::Un vert de cette garde ne prouverait donc RIEN. Corriger avant tout."
  rm -f "$echantillon"; exit 1
fi

# ---------------------------------------------------------------------------
# Contrôle du dépôt
# ---------------------------------------------------------------------------
mapfile -t FICHIERS < <(git ls-files -- '*.json' '*.csv' 2>/dev/null)

if [[ ${#FICHIERS[@]} -eq 0 ]]; then
  echo "::error::aucun fichier .json/.csv énuméré — le contrôle n'a rien regardé."
  echo "::error::Un vert dans ces conditions ne mesure rien. Vérifier le dépôt git."
  exit 2
fi

echo "Fichiers de données examinés : ${#FICHIERS[@]}"

FAUTIFS=()
for f in "${FICHIERS[@]}"; do
  autorise=0
  for a in ${AUTORISES[@]+"${AUTORISES[@]}"}; do
    [[ "$f" == "$a" ]] && autorise=1 && break
  done
  [[ $autorise -eq 1 ]] && continue
  if grep -qEi "$MOTIF" "$f" 2>/dev/null; then
    n="$(grep -cEi "$MOTIF" "$f" 2>/dev/null)"
    FAUTIFS+=("$f ($n occurrence(s))")
  fi
done

if [[ ${#FAUTIFS[@]} -eq 0 ]]; then
  echo "✓ Aucune donnée nominative dans les fichiers de données suivis."
  exit 0
fi

echo "::error::DONNÉE PERSONNELLE DANS UN DÉPÔT PUBLIC — commit refusé."
echo ""
echo "Fichier(s) en cause :"
for x in "${FAUTIFS[@]}"; do echo "  - $x"; done
echo ""
echo "Ce dépôt est PUBLIC et le restera. Un fichier nominatif poussé ici est"
echo "lisible par tous, et NE PEUT PLUS être retiré par vos propres moyens :"
echo "supprimer la branche ne suffit pas, seul le support GitHub peut purger."
echo ""
echo "Quoi faire :"
echo "  1. Retirer le fichier du commit (il ne doit jamais atteindre l'origine)."
echo "  2. La donnée nominative vit dans la BASE du CRM, qui est privée."
echo "     L'importer depuis un fichier gardé HORS dépôt."
echo "  3. S'il s'agit d'organisations et non de personnes, renommer la clef :"
echo "     une rédaction n'a pas de \"nom\", elle a un \"name\" / \"raison_sociale\"."
echo "  4. Exception réellement justifiée : l'ajouter à AUTORISES dans ce script,"
echo "     avec la raison. C'est une décision de revue, pas un contournement."
exit 1
