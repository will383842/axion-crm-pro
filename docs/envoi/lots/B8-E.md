# Lot B8-E — assistant : joignables vrais et pré-remplissage par `?audience=`

Cahier : `docs/envoi/lots/B6-B8-cahier.md`, § 11.1. Écran seulement ; aucune migration, aucun drapeau.

## Ce que fait le lot
- `NouvelleCampagnePage` lit `?audience=` (chiffres, moins de 18 caractères ; toute autre valeur est ignorée) et le passe à `useCibles` (paramètre `audience` de `GET /envoi/creation/cibles`). La cible de cette audience est cochée à l'ouverture si elle est listée ; sinon rien n'est coché et aucune erreur n'est affichée. Le nom reste « Campagne <libellé> ». `?etiquette=` et `?entreprise=` sont inchangés.
- `EtapeCible` : chaque carte affiche « N membres » (le total), plus jamais un nombre de joignables présenté comme certain. La zone d'estimation affiche « Joignables : N » (le `restant` de l'estimation), « au moins N sur M examinés » si `complet` est faux, et « Joignables non évalués » si `exclusions_evaluees` est faux (le total n'est alors jamais montré comme joignable).
- Le faux serveur de démonstration rend `exclusions_evaluees` et `complet`.
- `Assistant.tsx` n'a pas eu besoin de changer : il applique déjà la cible initiale et le nom.

## Tests
`frontend/tests/screens/envoi/AssistantCibleAudience.test.tsx` (7) : audience cochée avec une seule estimation, audience inconnue, audience mal formée non transmise, `joignables: null` sans « 0 », estimation complète, `complet: false`, exclusions non évaluées. Tests existants inchangés.

Mutations prouvées rouges : retrait de la cible initiale par audience ; retrait de la branche « au moins ».
