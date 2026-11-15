# F-FV — Écran du feu vert final

## Fait
- `DecisionFeuVert` (dans `FeuVertPage`) : bloc « Décision finale », dernière décision (décision, motif, date : aucune personne ni adresse), rappel « n'active pas l'envoi réel ».
- « Accorder le feu vert » : désactivé tant qu'un point bloquant hors `auto.reel_actif_faux` est rouge ; confirmation explicite avec motif (10 à 500 caractères) ; `If-Match` = `empreinte_liste` lue.
- 412 : message du serveur, liste relue, aucun réessai silencieux. 428, 409, 422 : message du serveur affiché.
- « Retirer le feu vert » : toujours proposé, sans `If-Match` ; motif obligatoire ; un refus 422 est dit clairement (« le retrait n'a pas été enregistré »).
- Boutons visibles seulement avec `workspaces.manage` lu dans `/auth/me` (rien tant que les droits sont inconnus).
- `ECARTS_CONNUS` : `POST /api/v1/envoi/feu-vert/decision`, « Filet modifié : pourquoi » (appel littéral nouveau, route servie par K17-FV).
- Faux serveur de la démo : déjà conforme à K17-FV (428, 412, 409, 422, état « sans bloquant » `?demo-feu-vert=sans-bloquant`) : non modifié.

## Reste
- Le contrat ne rend que la **dernière** décision et sans auteur : l'« historique » affiché est cette dernière décision (décision, motif, date). Un historique complet et un auteur affichable demandent un champ serveur.
- Le spec `dm3-feu-vert.spec.ts` n'a pas été joué en local (navigateur de démo) : la CI de démo le joue.
- Au palier 0, « accorde » est impossible par la route (points `serveur_reel`) : voulu (D12).

## Tests lancés
- Rouges d'abord : `FeuVertPage.test.tsx` (cas F-FV) et `routes.test.ts` (ligne manquante).
- Verts après : `tests/screens/envoi`, `tests/non-regression`, `tsc --noEmit`, `eslint --max-warnings 0`.
- `ENVOI_REEL_ACTIF=false` : aucun envoi réel, aucune donnée de personne.
