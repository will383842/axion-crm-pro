**BROUILLON** — PR 5a : propositions pour `website` et `phone`

## Pourquoi
Un automatisme ne pouvait proposer que `email_generic`. Un site `probable-ia` ou un téléphone en conflit ne pouvait donc pas devenir une proposition.

## Fichiers touchés
- `backend/app/Crm/Propositions/Propositions.php` : `CHAMPS_AUTOMATISMES['entreprise']` reçoit `website` et `phone`.
- `backend/tests/Feature/Crm/PropositionsSiteTelAutomatismeTest.php` : 4 tests (nouveau).

## Choix
- Pas de migration : le CHECK de `propositions_champs` ne limite pas les champs (simple regex sur `champ`).
- Pas de nouvelle origine `claude-recherche` : non retenue pour cette PR, donc `Taxonomy.php` n'est pas touché. À décider avec la PR qui écrit les propositions.
- Accepter un site venu d'un automatisme ne pose pas `website_method` : le site n'est pas rendu fiable (seule une source officielle le fait).

## Tests rouges / verts : NON CONSTATÉS ICI
Cause du blocage : l'environnement n'a pas de Postgres avec PostGIS (hôte `postgres` absent ; Postgres 18 local sans l'extension `postgis`, pas de droits root pour l'installer). Les tests de base ne démarrent pas, ni avant ni après le code.
- Fait : `php -l`, `pint` OK, `composer analyse` OK.
- À faire par le pilote : lancer `vendor/bin/pest tests/Feature/Crm/PropositionsSiteTelAutomatismeTest.php` avec la base de la CI. Attendu : rouge sans le changement de code, vert avec.

## Ce qui reste
Brancher les automatismes qui appellent `proposerAutomatisme` pour ces champs (hors périmètre).
