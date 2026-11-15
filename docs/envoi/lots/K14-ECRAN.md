# K14-ECRAN — Écran « Inscriptions » (lecture seule, vague 3)

Plan : `docs/envoi/PLAN-FIN-ENVOI.md`, fiche K14-ECRAN. Frontend seul : aucune route serveur, aucune migration, aucun envoi (`ENVOI_REEL_ACTIF=false`).

## Fait
- `InscriptionsPage` : une ligne par service (Postmaster, SNDS/JMRP, Yahoo, Orange, Signal Spam), état Fait / À faire / Non évalué lu du feu vert (`GET /envoi/feu-vert`, codes `manuel.*` inchangés), dernier constat de réputation (`GET /envoi/reputation`), états chargement / vide / panne. Aucun bouton d'écriture, aucun lien externe ; renvoi vers la « Fiche K14 — inscriptions de réputation (pas à pas) » par son titre.
- « Non évalué » : point absent du feu vert ou en attente du serveur réel (jamais « fait » à tort).
- `inscriptionsLibelles.ts` : services, libellés purs.
- Démo (`pilotage.handlers.ts`) : cinq points d'inscription (dont `manuel.yahoo_cfl` et le libellé Orange v4). Le feu vert de démo passe de 19 à 24 points.
- Filet modifié : pourquoi — `routes-envoi-gardees.test.tsx` (ligne 116, hors liste du plan, obligatoire) : la route n’est plus une page d’attente, la ligne reçoit son titre « Inscriptions » (convention des autres lots) et passe du groupe « en attente » au groupe « page remplie » ; aucune assertion retirée.
- Filet modifié : pourquoi — `dm2-pil.spec.ts` (hors liste du plan, obligatoire) : « 14/15 critères sur 19 » devient « 15/16 sur 24 » à cause des cinq points ajoutés à la démo.

## Reste
- Rien côté écran. La fiche K14 reste dans `docs/envoi/` (aucun lien : le dépôt public ne contient pas de liens vers les services).

## Risques
- Aucun écrit, aucun envoi ; `ENVOI_REEL_ACTIF=false` inchangé ; aucune donnée supprimée.

## Tests lancés
Rouge d'abord (8 échecs) puis vert : `tests/screens/envoi/InscriptionsPage.test.tsx` ; `tests/screens/envoi`, `tests/lib`, `tests/non-regression`, typecheck, lint. E2E démo laissés à la CI.
