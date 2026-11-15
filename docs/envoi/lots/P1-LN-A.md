# P1-LN-A — Registre de refus relu à l'injection (D8, seconde moitié)

## Fait
- `DepotSocleDb::adresseOpposee` lit aussi le registre R13 de l'espace (domaine de l'adresse et domaines parents), via `RegistreOppositionManuel::refuse`, en lecture seule. `PasserelleLocale` l'appelle déjà sous le verrou du jour, juste avant l'injection : une opposition ajoutée entre le temps A et l'injection gagne.
- La portée adresse reste vue par `opt_out` et `email_suppressions` (D14 : tous espaces).
- Interface `DepotSocle` et `DepotMemoire` inchangées.

## Limite assumée
La portée **SIREN** n'est pas relue à l'injection (`adresseOpposee` ne reçoit que l'adresse) : elle est appliquée au temps A par `EnvoiAutorise` (contrôle `opposition_registre_manuel`).

## Reste
Import en lot des refus : P1-LN-B.

## Risques
Aucune écriture vers le site, aucune migration. `ENVOI_REEL_ACTIF` reste `false`.

## Tests lancés
`OppositionAuMomentDeLInjectionTest` (7 tests) : 3 rouges avant le code (domaine après le temps A, sous-domaines, isolation par espace), verts après. Témoins : retirer le filtre `workspace_id` fait rougir la garde « espace B » ; remplacer la première condition par `false` fait rougir les gardes « adresse inscrite » et D14.
