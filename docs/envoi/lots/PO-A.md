# PO-A — `FiltreOrigineContenu` et lecture des unités par réseau / mot / guide (lecture seule)

Cahier : `docs/envoi/lots/PONT-LINKEDIN-cahier.md` (branche `conception/pont-linkedin`, PR brouillon #691), § 4.
Branche : `feat/pl-po-a`. Aucune migration, aucun drapeau touché, aucune écriture, aucun envoi.

## Fichiers (et seulement ceux-là)
- C `backend/app/Envoi/Campagnes/FiltreOrigineContenu.php` : forme `{"mots":[…],"guides":[…]}` (clés `mots` et/ou `guides`, 1 à 10 jetons
  chacune, `trim`, majuscule refusée, dédoublonnage, tri), `depuisColonne`, `depuisSaisie`, `enColonne`, `actif()` qui APPELLE
  `FiltreOrigine::actif()` (la porte n'est pas recopiée).
- M `backend/app/Crm/Origines/RequetesOrigines.php` : `unitesParOrigine()`, `parGuide()`, clé `guide` dans `conditions()` et
  `filtresVides()`. `reseauxDesUnites()` garde sa signature et son comportement : son corps est partagé (méthode privée `unites()`).
- C `backend/tests/Feature/Envoi/FiltreOrigineContenuTest.php` (35 cas, chacun sous `pgsql_app` ET propriétaire quand il lit la base).
- C `docs/envoi/lots/PO-A.md` (ce compte rendu).

Intouchés : `FiltreOrigine.php`, `SelectionDestinataires.php`, `OriginesController.php`.

## Choix à signaler
- `conditions()` lit `$f['guide'] ?? null` : `OriginesController` (interdit dans ce lot) construit encore ses filtres sans clé `guide`.
  Sans cela, 9 tests `OriginesLectureTest` rougissaient (constaté, corrigé). Absent = pas de critère.
- Un critère posé mais vide, ou sans jeton valide, ne correspond à rien (échec fermé) ; tous critères `null` ⇒ `\LogicException`.
- `parGuide` ne rend que les entrées portant un guide (`guide IS NOT NULL`).

## Tests ROUGES d'abord (avant tout code, sur `main` 83d91b0)
`vendor/bin/pest tests/Feature/Envoi/FiltreOrigineContenuTest.php` → `Tests: 35 failed (13 assertions)` : classe
`FiltreOrigineContenu` absente, méthodes `unitesParOrigine` / `parGuide` absentes (`Error`, « Failed asserting that an instance of class Error
is an instance of class InvalidArgumentException / LogicException »).

## Résultats après code
- `FiltreOrigineContenuTest` + `SelectionFiltreOrigineTest` + `FiltreOrigineTest` + `CreationFiltreOrigineTest` + `OriginesLectureTest` :
  `Tests: 85 warnings (665 assertions)`, 0 échec (les « warnings » sont ceux, déjà présents sur `main`, de la lecture d'un `.env` absent).
- `vendor/bin/pint --dirty` : passed. PHPStan niveau 8 sur les 2 fichiers de `app/` : `[OK] No errors`.

## Mutations (chacune appliquée seule, tests relancés, mutation annulée)
| Mutation | Tests rouges |
|---|---|
| `implode(' AND ', $conditions)` → `' OR '` (la règle « même entrée » n'est plus tenue) | PO-A 2 (guide / combiné) et PO-A 3, les deux rôles : `Tests: 4 failed` |
| `masque_le` retiré des deux branches de `entrees()` | PO-A 4, 5, 7, les deux rôles : `Tests: 6 failed` |
| O1 retirée (`if (false && $p->opposee)`) | PO-A 6, les deux rôles : `Tests: 2 failed` |

Écart au cahier : la mutation « une entrée par critère » est approchée par un `OR` (c'est ce que fait un critère indépendant par entrée
au niveau d'une même ligne) ; PO-A 3 rougit bien.
