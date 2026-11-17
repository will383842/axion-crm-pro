# PO-D — écran de campagne : mots et guides

Cahier PONT-LINKEDIN (#691) § 4 ; consignes FINI (#680). Aucun envoi, aucun drapeau touché.

## Fichiers
M `FiltreReseau.tsx` (listes mots / guides, 10 cases au plus, absentes si 404 ou `actif: false`) ; M `Recapitulatif.tsx` (phrase « Uniquement les personnes venues de : … par le mot … pour le guide … ») ; M `api.ts` (lecture `par-mot` / `par-guide`, sans réessai) ; M `saisie.ts` (`mots`, `guides`, corps trié `{reseaux?, mots?, guides?}`) ; M `FiltreReseau.test.tsx` ; C ce compte rendu.

## ESCALADE (levée par le pilotage dans la PR : `EtapeCible.tsx` autorisé, uniquement ce branchement ; test 18 ajouté)
1. `EtapeCible.tsx` (hors liste) doit passer `mots`, `guides` et `modifierContenu={(p) => modifier({ ...p, reseauxModifies: true })}` à `<FiltreReseau>` : une ligne. Sans elle, les listes ne s'affichent pas dans l'assistant (le composant les rend seulement si `modifierContenu` est fourni ; rien ne change pour l'écran actuel). À faire par un lot qui a ce fichier.
2. Le libellé « hors origine demandée » du compteur `hors_reseau` : aucun écran du dossier `creation/` ne l'affiche (compteur lu côté serveur seulement) ; rien à modifier dans la liste autorisée.
3. Test « 422 `filtre_origine_inactif` affiché, aucun réessai » pour mots/guides : il passe par le branchement ci-dessus (le test réseau n° 5 couvre déjà le chemin d'erreur commun) ; à ajouter avec le point 1.

## Tests rouges d'abord (avant tout code)
```
 × 7 par-mot en 404 … × 9 deux mots cochés … × 10 onze mots … × 11 réseau + mot + guide
 × 13 brouillon relu … × 14 récapitulatif exact … × 15 mots seuls
 Tests 7 failed | 11 passed
```
## Après correction : 18/18 verts (`vitest FiltreReseau.test.tsx`), `tsc` et `eslint` propres.

## Mutations (chacune rougit, puis annulée)
- liste affichée malgré 404 : tests 7 et 8 rouges ; tri retiré : 5 rouges (3, 6, 9, 11, 13) ; borne 10 retirée : tests 4 et 10 rouges.

## Branchement (autorisé après ESCALADE)
Tests 16 et 18 rouges d'abord (2 échecs sur 21), puis 21/21 verts ; test 17 : interrupteur éteint, rien ne s'affiche.
