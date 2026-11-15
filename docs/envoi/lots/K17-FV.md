# K17-FV — Feu vert final (serveur) (vague 3)

Plan : `docs/envoi/PLAN-FIN-ENVOI.md`, fiche K17-FV et décision D12. `ENVOI_REEL_ACTIF=false` : aucun envoi, aucune configuration modifiée, aucune migration (la table vient de S1-B).

## Fait
- `POST /envoi/feu-vert/decision` `{decision: accorde|retire, motif}` : propriétaire seul (`workspaces.manage`, administrateur ⇒ 403). Décision prise sous `pg_advisory_xact_lock` par espace (clé `envoi_feu_vert_decision|<espace>`, distincte de celle des preuves) ; l'empreinte est recalculée sous ce verrou avant l'ajout.
- « accorde » : 428 sans `If-Match`, 412 si l'empreinte est périmée (forme entre guillemets acceptée), 409 tant qu'un point bloquant hors `auto.reel_actif_faux` est rouge. « retire » : toujours accepté (sans en-tête, empreinte périmée, bloquants rouges) et consigné à chaque appel.
- `empreinte_liste` (sha-256) : points (code, fait, état, date de preuve) sans `auto.reel_actif_faux`, plus l'identifiant de la dernière décision (ou `aucune`) ; aucun horodatage de calcul. `GET /envoi/feu-vert` rend `empreinte_liste` et `decision` (nulle sans décision).
- Consignation en ajout seul dans `envoi_feu_vert_decisions`, avec la photo des points (code, état, fait, bloquant : aucun texte libre). Motif de 10 à 500 caractères, sans adresse (422).
- Catalogue **v5** : 48 points (19 auto, 29 manuels), trois points `auto.*` calculés : `auto.essai_a_blanc_recent` (essai réussi de moins de 7 jours), `auto.plafond_butee_base` (fonction de butée présente en base), `auto.adaptateurs_domaines` (chaque domaine non `faux` a un DNS vert du jour).
- Au palier 0 les points `serveur_reel` restent rouges : « accorde » est impossible par la route (voulu). Le signal K17 qui les rendrait prouvables n'est pas dans ce plan.

## Chiffres constatés dans le code
v4 : 45 points (16 auto, 29 manuels) → v5 : 48 (19 auto, 29 manuels). Bloquants de `FeuVertListeTest` « tout vert » : `3 + 29 = 32` avant et après ; `toutVert()` pose désormais un essai à blanc récent, sinon le nouveau point serait rouge et « tout vert » ne le serait plus.

## Preuves
- Concurrence : deux processus réels (`tests/Support/ouvrier_feu_vert.php`, deux connexions) lancent « accorde » sur la même empreinte ; exactement un 200 et un 412. Sans le verrou, le test est rouge (deux 200).
- Succès prouvé sur une liste simulée (aucun envoi).

## Fichiers hors liste du plan (obligatoires)
`tests/Support/ouvrier_feu_vert.php` (ouvrier de la preuve à deux processus) ; `tests/Feature/Envoi/FeuVertCatalogueV4Test.php` et `ContratsS2Test.php` (assertions rendues fausses par v5 et par le trou comblé).

## Risques
- Après sept jours sans essai à blanc réussi, `auto.essai_a_blanc_recent` rougit : « accorde » est refusé jusqu'à un nouvel essai (voulu : un essai par semaine).
- L'aiguilleur (K17-AIG) devra relire « aucun bloquant rouge maintenant », pas seulement la dernière décision.

## Tests lancés
`pint --test`, PHPStan, Pest `FeuVertDecisionTest`, `FeuVertListeTest`, `FeuVertRoutesTest`, `tests/Feature/Envoi` (1465 verts ; `DepotSocleDbTest` et `DurcissementL0Test` ont échoué une fois en mode parallèle puis passent seuls et en série).
