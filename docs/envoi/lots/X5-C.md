# X5-C — fraîcheur de la batterie (compte rendu)

Cahier : `docs/envoi/lots/FINI-cahier.md` (PR brouillon #680), lot X5-C.

## Fait
- Nouveau point `auto.batterie_fraiche` (groupe `dns`, bloquant, sans vrai serveur) : fait seulement si la plus ancienne des
  dernières lignes `envoi_controles_dns` (par domaine) et `envoi_controles_listes` (par ressource) a moins de 15 minutes
  (`ListeFeuVert::BATTERIE_FRAICHEUR_SECONDES = 900`), horloge de la base `now()`. Une table vide ⇒ rouge. Erreur de lecture ⇒ rouge.
- `CatalogueManuels::VERSION` 5 → 6 (point ajouté, aucun libellé existant changé).
- `DecisionFeuVert` : aucun changement de code nécessaire. Il recalcule déjà tous les points sous le verrou, l'empreinte les
  contient, et les bloquants rouges donnent 409 ; « retire » reste toujours accepté (testé).
- Aucun contrôle réseau, aucun drapeau touché (`ENVOI_REEL_ACTIF` etc. inchangés).

## Tests
- `FeuVertBatterieFraicheTest` (nouveau) : 14 min ⇒ fait ; DNS à 16 min ⇒ rouge ; listes à 16 min ⇒ rouge ; une table absente ⇒ rouge ;
  seule la dernière ligne par domaine compte ; espace B frais ne rend pas A vert ; empreinte changée au passage des 15 min
  (ligne vieillie par la base, sans `sleep`) ; décision `accorde` à 16 min ⇒ 409 sans ligne, `retire` ⇒ 200.
  Les scénarios de lecture tournent sous `pgsql` (propriétaire) ET `pgsql_app` (RLS armée).
- Rouge d'abord : 15 échecs (point absent, version 5) avant le code ; vert après (16 réussis).
- Mutations : `900` → `PHP_INT_MAX` ⇒ 7+ échecs ; lecture de la seule table DNS ⇒ 4 échecs ; code restauré.
- `FeuVertListeTest` : comptes seulement (48 → 49 points, 19 → 20 automatiques). Aucune assertion retirée.
- Pint propre ; PHPStan niveau 8 : aucune erreur de code.

## ESCALADE (fichiers hors liste)
Le point ajouté change des comptes figés dans des tests ET un contrat hors des fichiers autorisés du lot. Ils sont rouges
tant qu'ils ne sont pas mis à jour (comptes et version seulement, aucune assertion retirée) :
`FeuVertDecisionTest` (48 → 49, version 5 → 6), `FeuVertRoutesTest` (48 → 49), `FeuVertCatalogueV4Test` (version 5 → 6, 48 → 49),
`FeuVertFreinsSignauxTest` (version 5 → 6), et le contrat `pilotage.yaml` s'il fixe le nombre de points.
Décision demandée : autoriser ces mises à jour de comptes dans ce lot (défaut sans risque : oui, comptes seulement).
