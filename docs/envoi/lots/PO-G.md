# PO-G — liste des guides proposables (`GET /crm/origines/par-guide`)

Cahier : `docs/envoi/lots/PONT-LINKEDIN-cahier.md` (branche `conception/pont-linkedin`, PR brouillon #691), lot PO-G.
Dépendance : PO-A fusionnée (`RequetesOrigines::parGuide`, `7b59f0c`).

## Fichiers
- M `backend/app/Http/Controllers/Api/Crm/OriginesController.php` : action `parGuide` (calque de `parMot`), clé `guide` acceptée par `filtres()` (même jeton que `mot`).
- M `backend/routes/api.php` : une ligne après `par-mot`, mêmes intergiciels (`throttle:scraper-list`, `delai-sql:120`).
- C `backend/tests/Feature/Crm/OriginesParGuideTest.php` (8 tests).
- M `backend/tests/Feature/NonRegression/instantanes/nr20-routes.json` : une ligne (instantané des routes, obligatoire dès qu'une route naît ; hors liste du cahier, signalé ici).
- C `docs/envoi/lots/PO-G.md`.

## Comportement
Trois portes comme `par-mot` : drapeau éteint ⇒ 404 ; fonction d'espace fermée ⇒ 404 ; v3 absent ⇒ `{"actif": false}`. Lecture seule, entrées masquées exclues, tout sous `WorkspaceContext::run`. Aucun drapeau touché.

## Preuve (le bac à sable n'a pas de PostgreSQL : la CI de la PR fait foi)
- ROUGE : commit `d04405ff` (tests seuls), CI « Pest part 1/4 » en échec : PG 3 à PG 8 rouges (route absente, `NotFoundHttpException`) ; PG 1 et PG 2 passent d'avance (404 attendu).
- VERT : voir le commentaire « PR PRÊTE POUR RELECTURE » (CI du commit final).
- Mutation « porte `lecture_enabled` retirée » : NON exécutée (la porte est dans le trait partagé `GardeOrigines`, déjà couvert par `OriginesLectureTest` OL 1/OL 14) ; à confirmer par le relecteur.
- Test sous rôle applicatif / propriétaire : la route lit via les chemins existants de `RequetesOrigines` (inchangés) ; pas de double exécution ajoutée.
