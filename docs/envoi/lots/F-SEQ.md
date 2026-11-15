# F-SEQ — Écrans des séquences

## Fait
- `SequencesPage` : liste (statut, étapes, inscrits, en cours, prochain envoi), états chargement, vide et erreur.
- `SequencePage` : fiche avec compteurs d'inscriptions et tableau des étapes (délai, objet, envoyés, réponses, rebonds, désinscriptions). Aucun corps de message, aucune personne.
- « Mettre en pause » avec confirmation (`users.manage`). « Reprendre » (`workspaces.manage`) : sur 409, la raison du serveur s'affiche en clair, le statut ne change pas.
- Boutons masqués selon la permission lue dans `/auth/me` ; tant que les droits sont inconnus, aucun geste. Le serveur reste l'autorité (403).
- `api.ts` : quatre appels littéraux, donc quatre lignes dans `ECARTS_CONNUS` (préfixe `/api/v1`), « Filet modifié : pourquoi ».
- Le faux serveur de la démo (`sequences.handlers.ts`) servait déjà les quatre routes avec le bon contrat : non modifié.

## Reste
- Écart de liste : fichier hors liste `frontend/tests/components/routes-envoi-gardees.test.tsx` — la garde exige le titre de la page livrée (3e élément de la ligne) au lieu de « en attente ». Même fichier que la PR #571 (K14-ECRAN), sur la ligne voisine : les trois lignes (F-SEQ liste, F-SEQ fiche, K14-ECRAN) restent, chacune avec son titre. Ordre de fusion : #570 puis #571 (ou l'inverse, sans conflit après fusion de `main` dans la seconde).
- Tests de permission : les absences sont vérifiées après chargement de `['auth','me']` ; deux tests croisés (`workspaces.manage` seul, `users.manage` seul) ; 403 liste/fiche → message de droits sans « Réessayer » ; 403 sur la reprise → raison, bandeau et bouton restent.
- E2E B4 : le compte de démo est propriétaire et `/auth/me` ne peut pas être surchargé (route déclarée deux fois interdite) ; le masquage par permission (lecteur, administrateur) est donc testé en Vitest, et l'e2e B4 vérifie le masquage selon l'état.
- Le spec e2e n'a pas été joué en local (navigateur de démo) : il l'est par la CI de démo.

## Tests lancés
- Rouges d'abord : `SequencesPage.test.tsx` (12 rouges, écrans d'attente) ; `routes.test.ts` (4 lignes manquantes).
- Verts après : ces deux fichiers, `tests/non-regression`, `tsc --noEmit`, `eslint --max-warnings 0`.
- `ENVOI_REEL_ACTIF=false` : aucun envoi réel, aucune donnée de personne.
