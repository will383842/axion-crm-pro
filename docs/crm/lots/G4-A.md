# G4-A — refus de démarrer sans le rôle applicatif (code seulement, drapeau éteint)

**But** : en production, l'application refuse de démarrer si la garde est allumée et que le rôle applicatif n'est pas en place.

## Comportement
`GardeRoleApplicatif::verifier` ne fait rien hors production, ni quand `crm.garde_role_applicatif` est faux (défaut, `CRM_GARDE_ROLE_APPLICATIF=false`). Sinon, trois refus, sans aucune requête SQL et sans citer une valeur :
1. `CRM_DB_APP_ROLE_ENABLED` éteint ;
2. la connexion `pgsql` porte le nom du propriétaire (`pgsql_owner`) ;
3. mot de passe du rôle applicatif vide (`DB_APP_PASSWORD`).

Exception unique : `artisan migrate --database=pgsql_owner` passe toujours. Toute autre commande est soumise à la garde.

## Allumage (geste de production, hors de ce lot)
Ordre imposé : lire la sortie de G4-0 (`VERDICT=role_applicatif_actif` dans `api`, `horizon` et `scheduler`) ; poser `CRM_GARDE_ROLE_APPLICATIF=true` ; recréer les conteneurs ; relancer G4-0. Retour arrière : `CRM_GARDE_ROLE_APPLICATIF=false` et la même recréation. `CRM_DB_APP_ROLE_ENABLED` n'est jamais modifié par ce lot.

## Tests
`backend/tests/Unit/GardeRoleApplicatifTest.php`. Filet NR-16 : `nr16-drapeaux.json` +1.

## Mutation prouvée rouge
`||` remplacé par `&&` dans la condition d'entrée : tests 1 et 2 rouges. Exception `migrate` rendue toujours vraie : tests 3, 4 et 5 rouges.
