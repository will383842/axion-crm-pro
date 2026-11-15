# F-FV — Écran du feu vert final

## Fait
- `DecisionFeuVert` (dans `FeuVertPage`) : bloc « Décision finale », dernière décision (décision, motif, date : aucune personne ni adresse), rappel « n'active pas l'envoi réel ».
- « Accorder le feu vert » : désactivé tant qu'un point bloquant hors `auto.reel_actif_faux` est rouge ; confirmation explicite avec motif (10 à 500 caractères) ; `If-Match` = `empreinte_liste` lue.
- 412 : message du serveur, liste relue, aucun réessai silencieux. 428, 409, 422 : message du serveur affiché.
- « Retirer le feu vert » : toujours proposé, sans `If-Match` ; motif obligatoire ; un refus 422 est dit clairement (« le retrait n'a pas été enregistré »).
- Boutons visibles seulement avec `workspaces.manage` lu dans `/auth/me` (rien tant que les droits sont inconnus).
- `ECARTS_CONNUS` : `POST /api/v1/envoi/feu-vert/decision`, « Filet modifié : pourquoi » (appel littéral nouveau, route servie par K17-FV).
- Faux serveur de la démo (`pilotage.handlers.ts`, `POST /envoi/feu-vert/decision`) : mis en conformité avec K17-FV après l'audit : 403 sans `workspaces.manage`, 422 si le motif contient « @ » (après NFKC), 428, 412, 409 ; `bloquants_restants` et le 409 EXCLUENT `auto.reel_actif_faux` (comme `DecisionFeuVert.php`). Le point de démo a été renommé `auto.reel_actif_faux`. État « sans bloquant » : `?demo-feu-vert=sans-bloquant`. Testé en passant par ce faux serveur.
- Écran : l'empreinte est figée à l'ouverture de la fenêtre ; après un 412 la fenêtre se ferme (il faut la rouvrir depuis la liste relue) ; « accorde » sans empreinte et motif avec « @ » refusés en local ; le texte « tant que le motif n'est pas valide » n'apparaît que pour un 422.

## Reste
- Le contrat ne rend que la **dernière** décision et sans auteur : l'« historique » affiché est cette dernière décision (décision, motif, date). Un historique complet et un auteur affichable demandent un champ serveur.
- Le spec `dm3-feu-vert.spec.ts` n'a pas été joué en local (navigateur de démo) : la CI de démo le joue.
- Au palier 0, « accorde » est impossible par la route (points `serveur_reel`) : voulu (D12).

## Tests lancés
- Rouges d'abord : `FeuVertPage.test.tsx` (cas F-FV) et `routes.test.ts` (ligne manquante).
- Verts après : `tests/screens/envoi`, `tests/non-regression`, `tsc --noEmit`, `eslint --max-warnings 0`.
- `ENVOI_REEL_ACTIF=false` : aucun envoi réel, aucune donnée de personne.
