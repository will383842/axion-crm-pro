# Le banc ne se parallélise pas : `ALTER ROLE` est global au cluster

**Mesuré le 2026-08-21**, en rejouant la suite complète en cinq lots parallèles
(bases `axion_crm_test_lot1..lot5`, conteneur `a35r`).

## Ce qui est arrivé

Trois tests de `tests/Feature/Api/CorpsCodeEnDurTest.php` ont rougi :

```
FAILED  Tests\Feature\Api\CorpsCodeEnDurTest > B12-007 …   QueryException
SQLSTATE[XX000]: Internal error: 7 ERROR:  tuple concurrently updated
(Connection: pgsql, Database: axion_crm_test_lot1,
 SQL: ALTER ROLE axion_app NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE)

  database/migrations/2026_08_14_000001_harden_workspace_isolation.php:254
  database/migrations/2026_08_14_000001_harden_workspace_isolation.php:86
```

## La cause, et elle n'est pas dans le produit

`createApplicationRole()` (l. 245-262 de cette migration) émet `CREATE ROLE`
puis `ALTER ROLE axion_app …`. **Un rôle Postgres est un objet du CLUSTER**, il
vit dans `pg_authid`, pas dans la base. Cinq `migrate:fresh` simultanés sur cinq
bases distinctes écrivent donc tous les cinq **la même ligne de `pg_authid`**, et
Postgres refuse la deuxième écriture concurrente.

**« Une base de test est une ressource exclusive » était une règle trop faible.**
La règle juste : *une base de test est exclusive, et le CLUSTER l'est aussi dès
qu'une migration touche un rôle, une extension ou un tablespace.*

## Ce que ça dit du produit, et ce que ça n'en dit pas

- ❌ **Ce n'est pas un défaut des trois tests** : rejoués seuls, ils passent (cf.
  ci-dessous). Compter ces trois rouges comme une régression aurait été une
  erreur de lecture du banc.
- ⚠️ **C'est une contrainte réelle sur le déploiement, non mesurée** : deux
  `php artisan migrate` concurrents sur le même cluster — un déploiement rejoué,
  deux conteneurs qui démarrent ensemble, un `--force-recreate` pendant qu'un
  `entrypoint` migre — feraient échouer l'un des deux **sur cette même ligne**.
  L'`entrypoint` de production joue `prisma migrate deploy` puis
  `artisan migrate` en série, donc le cas ne se présente pas aujourd'hui. Il se
  présenterait au premier déploiement bleu-vert.
  Non réparé : aucune mesure ne montre que ça arrive, et poser un verrou
  d'avis (`pg_advisory_lock`) autour d'une migration est un choix d'exploitation,
  pas un correctif de test.

## Le rejeu, seul

Voir `banc-ALTER-ROLE-rejeu-seul.txt`.
