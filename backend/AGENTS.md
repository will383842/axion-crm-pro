# backend/AGENTS.md — checklist du filet (à vérifier dans le code réel)

Lire aussi `tests/Feature/NonRegression/LISEZ-MOI.md` (référence du filet). Chemins relatifs à `backend/`.

## Nouvelle table
- Table portant `workspace_id` : l'ajouter à `SemeurTablesScopees::semer()` ET à
  `SemeurTablesScopees::ORDRE` (parents d'abord) dans `tests/Support/SemeurTablesScopees.php`.
- Table en ajout seul : voir `APPEND_SEUL` et `APPEND_SEUL_AVEC_FK` dans le même fichier.
- Ajouter la RLS (policy sur `app.current_workspace_id`). Modèle et tests : `tests/Feature/RlsTest.php`
  (le rôle propriétaire est SUPERUSER, donc tester via la connexion applicative). Migration modèle : à vérifier.

## Fonctions SQL
- Toute fonction `SECURITY DEFINER` porte un `SET search_path` fixe
  (exemple : `database/migrations/2026_10_01_000010_presse_harmonisee.php`,
  `SET search_path = pg_catalog, public`).

## Nouvelle migration
- Elle fait bouger les instantanés NR-07, NR-08, NR-17 et NR-20 (`tests/Feature/NonRegression/instantanes/`).
  Les régénérer SEULEMENT si le changement est voulu (commande décrite dans `LISEZ-MOI.md`, refusée en CI) :

```bash
cd backend
NR_REGENERER_INSTANTANES=1 php vendor/bin/pest --group=non-regression
git diff tests/Feature/NonRegression/instantanes/   # relire le diff
```

- Mettre « Filet modifié : pourquoi » dans la description de la PR.
- NR-17 : une migration nouvelle exige un `down()` réel (aller-retour-aller) ; les retours inertes
  voulus sont une liste fermée (`NR17_RETOUR_INERTE_VOULU` dans `Nr17MigrationsTest.php`).
- Ajouts seuls : jamais de DROP ni de DELETE de données.

## Nouveau test du filet
- Dans `tests/Feature/NonRegression/`, titre commençant par `NR-xx`, avec son témoin.
- Le déclarer dans `tests/Feature/NonRegression/LISTE.json` (`ListeFigeeTest` rougit sinon).
- Jamais `->skip(`, `->todo(`, `markTestSkipped` ni `markTestIncomplete` dans ce dossier.

## Nouvelle tâche planifiée
- La déclarer dans `routes/console.php` ; NR-07 (`nr07-taches-planifiees.json`) et NR-08
  (`nr08-taches-sous-rls.json`) doivent suivre.

## Nouvelle route
- Appel frontend vers une route nouvelle : régénérer `instantanes/nr20-routes.json`.

## Qualité
- Style Pint obligatoire : `vendor/bin/pint --dirty` (config : `pint.json`).
- PHPStan niveau 8 (`phpstan.neon`, baseline `phpstan-baseline.neon`) :
  `composer analyse` (= `phpstan analyse --memory-limit=2G`).
- Tests ciblés : `vendor/bin/pest --filter=...` ; filet : `php vendor/bin/pest --group=non-regression --no-coverage`.

## Horloge
- Tout test dépendant de l'heure fige l'horloge : `Carbon::setTestNow(...)` en UTC, puis
  `Carbon::setTestNow()` pour la rendre. Sans cela, la CI échoue entre 22 h et minuit UTC.

## Envoi
- Clés de configuration dans `config/envoi.php` : `reel_actif` (`ENVOI_REEL_ACTIF`, faux par défaut),
  `plafond_jour`, `plafond_global_jour`, `cle_empreinte`, `smtp_delai_4xx_minutes`.
- Aucun envoi réel, aucun serveur touché par un test (simulateurs `MOCK_*`).
