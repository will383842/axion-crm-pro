# K14-CAT — Catalogue v4 (Yahoo, libellé Orange)

## Fait
- `CatalogueManuels` passe en **v4** : ajout de `manuel.yahoo_cfl` (« Inscription Yahoo, boucle de plaintes CFL », groupe conformité, source « 30 A11 », `serveur_reel` vrai, bloquant) ; le libellé de `manuel.orange_cfl` devient « Orange : prise de contact délivrabilité ». **Le code `manuel.orange_cfl` ne change pas** : les preuves déjà posées restent lues (décision D13).
- 45 points au total (16 auto, 29 manuels). `FeuVertListeTest` et `FeuVertRoutesTest` passent de 44 à 45 points et de 28 à 29 manuels (l. 136-145, 159, 357-358 ; routes l. 50-58) : seuls les compteurs changent, aucune assertion n'est affaiblie.
- Nouveau `FeuVertCatalogueV4Test` : version 4, 45/29, `manuel.yahoo_cfl` (source, serveur réel, libellé, bloquant, preuve sans effet tant que le vrai serveur manque), libellé Orange sans « CFL », garde avec témoin.

## Garde avec témoin
La preuve déposée sur `manuel.orange_cfl` reste lue (`preuve` rendue, état `attend_serveur_reel` comme avant v4, une seule ligne en base). Le point exige le vrai serveur : il n'est donc pas « fait » avant K17, comme avant v4 ; la garde prouve que la preuve n'est pas perdue. Témoin : renommer le code en `manuel.orange_contact` fait rougir le test (sortie dans la PR).

## Reste
- Écran « Inscriptions » en lecture seule : K14-ECRAN (vague 3), qui adapte aussi la démo frontend.
- K17-FV (vague 3) passera le catalogue en v5.

## Risques
- Un point bloquant de plus au feu vert (Yahoo) : voulu, la CFL est celle de Yahoo.
- `ENVOI_REEL_ACTIF=false` inchangé ; aucun envoi, aucune migration, aucune donnée supprimée.

## Tests lancés
`pint --dirty`, Pest `FeuVertCatalogueV4Test`, `tests/Feature/Envoi`, PHPStan sur `app/Envoi/FeuVert`.
