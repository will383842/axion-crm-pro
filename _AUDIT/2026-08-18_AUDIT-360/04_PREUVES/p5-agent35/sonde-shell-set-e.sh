#!/bin/sh
# SONDE — `set -euo pipefail` + `VAR="$(pipeline qui echoue)"`.
#
# definir-mot-de-passe-crm.sh passe de `set -uo pipefail` a `set -euo pipefail`
# (commit da994be) ET capture desormais la sortie du conteneur dans
#   SORTIE="$(printf '%s' "$MDP" | docker exec -i ... php artisan tinker ... 2>&1 | tr -d '\r')"
# Le code PHP fait `exit(1)` sur A35_INTROUVABLE et sur A35_ECHEC_MDP_VIDE.
#
# Question : sous `set -e`, l'affectation survit-elle a l'echec de la
# substitution de commande ? Si non, les branches d'erreur du `case` sont
# INATTEIGNABLES et l'operateur ne voit AUCUN message.
#
# Temoin positif : le meme scenario sous `set -uo pipefail` (l'ancien reglage).

echo "== [1] TEMOIN POSITIF : ancien reglage (set -uo pipefail), sortie non nulle =="
sh -c '
set -uo pipefail
SORTIE="$(printf "A35_INTROUVABLE\n"; exit 1)"
VERDICT="$(printf "%s" "$SORTIE" | tail -n 1)"
case "$VERDICT" in
  A35_OK)          echo "  branche OK" ;;
  A35_INTROUVABLE) echo "  branche INTROUVABLE atteinte -> message rendu a l operateur" ;;
  *)               echo "  branche generique" ;;
esac
echo "  fin de script atteinte, code=$?"
'
echo "  code de retour du sous-shell : $?"
echo

echo "== [2] MESURE : nouveau reglage (set -euo pipefail), meme sortie =="
sh -c '
set -euo pipefail
SORTIE="$(printf "A35_INTROUVABLE\n"; exit 1)"
VERDICT="$(printf "%s" "$SORTIE" | tail -n 1)"
case "$VERDICT" in
  A35_OK)          echo "  branche OK" ;;
  A35_INTROUVABLE) echo "  branche INTROUVABLE atteinte -> message rendu a l operateur" ;;
  *)               echo "  branche generique" ;;
esac
echo "  fin de script atteinte"
'
echo "  code de retour du sous-shell : $?  (aucune ligne au-dessus = le script est mort a l affectation)"
echo

echo "== [3] MEME MESURE SOUS bash (le script se lance par 'bash definir-...') =="
bash -c '
set -euo pipefail
SORTIE="$(printf "A35_INTROUVABLE\n"; exit 1)"
echo "  affectation survecue"
'
echo "  code de retour bash : $?"
echo

echo "== [4] TEMOIN : sous bash, une sortie a 0 laisse bien continuer =="
bash -c '
set -euo pipefail
SORTIE="$(printf "A35_OK\n"; exit 0)"
echo "  affectation survecue, SORTIE=$SORTIE"
'
echo "  code de retour bash : $?"
