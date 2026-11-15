# F-LN — Écran d'import des listes de refus

## Fait
- `ImportListeRefus.tsx` (`BoutonImporterListe`, dans `DesinscriptionsPage`) : bouton « Importer une liste de refus » visible seulement avec `workspaces.manage` lu dans `/auth/me` (rien tant que les droits sont inconnus).
- Fichier lu par le navigateur (`File.text()`), envoyé en texte dans un corps JSON (`etape`, `contenu`, `jeton`) : aucun `FormData`.
- Deux étapes visibles : « Simuler l'import » (compte rendu), puis « Confirmer l'import » (jeton de la simulation).
- Compte rendu : adresses valides, déjà présentes, refusées avec numéro de ligne et motif. Aucune adresse du fichier n'est réaffichée (ni le nom du fichier).
- Fichier de plus de 1 048 576 octets refusé avant lecture et avant tout envoi ; message du serveur (409, 422, 403) affiché tel quel, sans réessai silencieux.
- Aucun mot « ouverture » ; les lignes inscrites ne se retirent jamais.
- `routes.test.ts` : ligne `POST /api/v1/envoi/opposition/import` ajoutée à `ECARTS_CONNUS`, avec « Filet modifié : pourquoi ».
- Écrit contre le contrat de S2-CONTRATS et le faux serveur de la démo : aucun appel réel. Le backend est la PR #573 (non touchée).
- Mineurs (suite) : focus replacé sur le compte rendu (`tabIndex=-1`) après la simulation et la confirmation ; champ fichier désactivé pendant l'envoi et réponse d'une requête périmée ignorée ; après un 409, retour à l'étape de simulation et valeur du champ vidée (le même fichier peut être re-choisi) ; fichier vide refusé à l'écran avant tout appel.
- Test du bouton réservé au propriétaire : attend la réponse de `/auth/me` (drapeau du faux serveur) avant de constater l'absence ; vérifié rouge avec `users.manage` à la place de `workspaces.manage`.

## Reste
- Le spec `dm3-listes-refus.spec.ts` (H1 à H4) n'est pas joué en local : la CI de démo le joue.
- Limite de 5 000 lignes : vérifiée par le serveur (422 affiché), pas comptée côté écran.

## Tests lancés
- Rouges d'abord : `ImportListeRefus.test.tsx` et `routes.test.ts`.
- Verts après : `tests/screens/envoi`, `tests/non-regression`, `tsc --noEmit`, `eslint --max-warnings 0`.
- `ENVOI_REEL_ACTIF=false` : aucun envoi réel, aucune donnée de personne.
