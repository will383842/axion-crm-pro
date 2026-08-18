# Reconstruction de la base — `migrate:fresh` deux fois de suite

**Date** : 2026-08-18 · **Branche** : `chore/etape-0-prealables` · **Ligne 2 du §3** du plan de préalables.

**Critère de sortie** : « `migrate:fresh` (ou une commande unique équivalente) réussit **de zéro**,
y compris `pg_partman` ; **joué deux fois de suite**. » — **ATTEINT**, preuves ci-dessous.

---

## 1. Méthode d'exécution

Le code backend monté dans `axion-crm-api` vient de `Axion-CRM-Pro/backend`, **pas** du worktree.
Pour exécuter les migrations du worktree sans jamais écrire dans le dépôt principal, on lance un
**conteneur jetable** depuis l'image `axion-crm-pro-api`, sur le réseau `axion-crm`, avec le
`backend` du dépôt principal monté pour son `vendor/`, et le `database/migrations` **du worktree**
monté **en lecture seule PAR-DESSUS** :

```
docker run --rm --network axion-crm \
  -v .../Axion-CRM-Pro/backend:/var/www/html \
  -v .../crmpro-wt-etape0/backend/database/migrations:/var/www/html/database/migrations:ro \
  -w /var/www/html \
  -e DB_HOST=postgres -e DB_DATABASE=axion_crm_fresh -e TELESCOPE_ENABLED=false \
  axion-crm-pro-api php artisan migrate:fresh --force
```

Aucun `docker cp` (il aurait écrit dans le bind-mount, donc dans le dépôt principal).
Base de travail dédiée `axion_crm_fresh`, créée pour l'occasion : `axion_crm` (console) et
`axion_crm_test` (Pest) n'ont reçu que des `SELECT`.

---

## 2. Reproduction des deux pannes — sorties rouges verbatim

### Cause 1 — `part_config` bloque le `DROP TABLE` global

`axion_crm_fresh` créée vide, `migrate:fresh` n°1 **verte** (54 migrations), puis n°2 :

```
  Dropping all tables ............................................... 1 s FAIL
[2026-08-18 12:39:23] local.ERROR: SQLSTATE[2BP01]: Dependent objects still exist: 7 ERROR:  cannot drop table part_config because extension pg_partman requires it
HINT:  You can drop extension pg_partman instead. (Connection: pgsql, Host: postgres, Port: 5432, Database: axion_crm_fresh, SQL: drop table "public"."activities", …
"public"."opt_out", "public"."part_config", "public"."part_config_sub", … cascade)
#4 …/Illuminate/Database/Schema/PostgresBuilder.php(32): Illuminate\Database\Connection->statement('drop table "pub...')
#5 …/Illuminate/Database/Console/WipeCommand.php(75): Illuminate\Database\Schema\PostgresBuilder->dropAllTables()
```

Mécanique confirmée sur pièce : `PostgresBuilder::dropAllTables()` énumère les tables des schémas
du `search_path`, retire la clé de connexion `dont_drop` (**`['spatial_ref_sys']` par défaut** —
c'est pour ça que PostGIS, lui, ne pose pas de problème), et émet **un seul**
`DROP TABLE … CASCADE`. `public.part_config` et `public.part_config_sub` appartiennent à
l'extension → PostgreSQL refuse **tout le lot**.

### Cause 2 — réinjection des lignes avant l'existence d'une partition

`audit_logs` remis à plat avec **une ligne datée de maintenant**, ligne `000011` retirée de
`migrations`, puis `php artisan migrate` :

```
  2026_05_17_000011_setup_pg_partman_audit_logs ..................... 1 s FAIL
[2026-08-18 12:43:04] local.ERROR: SQLSTATE[23514]: Check violation: 7 ERROR:  no partition of relation "audit_logs" found for row
DETAIL:  Partition key of the failing row contains (created_at) = (2026-08-18 10:40:40.634965+00).
CONTEXT:  SQL statement "INSERT INTO audit_logs SELECT * FROM audit_logs_old"
PL/pgSQL function inline_code_block line 37 at SQL statement
```

---

## 3. Ce que la mesure RÉFUTE

1. **« Le correctif de juillet (`de5e684`) n'a traité QUE la cause 2. »** — Faux : il n'en a traité
   **aucune des deux**. La sortie ci-dessus est celle du code d'**après** `de5e684`. Ce commit a
   traité une **troisième** chose, exactement ce que dit son message : le repli sur partition
   DEFAULT quand `create_parent` échoue. Le bloc `EXCEPTION` n'enveloppe **pas** l'`INSERT`.

2. **`pg_partman` n'a JAMAIS géré `audit_logs`.** Mesuré sur `axion_crm` (base de dev, 54
   migrations, 104 tables) : `select count(*) from part_config` → **0**, et le schéma `partman`
   **n'existe pas**. La migration `000011` appelle `partman.create_parent()` depuis l'origine
   alors que l'extension était créée **sans clause `SCHEMA`, donc dans `public`**. L'appel a donc
   toujours échoué, et le repli DEFAULT de juillet a rendu l'échec **silencieux et vert**.
   La cause 1 et le non-fonctionnement de pg_partman ont **la même racine** : le schéma.

3. **`p_type => 'native'` est du pg_partman v4.** L'image embarque **v5.1.0**, dont la signature
   réelle (lue dans `pg_proc`) est
   `create_parent(p_parent_table text, p_control text, p_interval text, p_type text DEFAULT 'range', …)`.
   Même avec le bon schéma, l'appel de `000011` aurait échoué.

4. **La panne n'apparaît pas « sur une base déjà migrée » mais « dès que la table `migrations`
   existe ».** `FreshCommand::handle()` n'appelle `db:wipe` **que si**
   `$this->migrator->repositoryExists()`. Mesuré : sur une base neuve portant déjà les extensions
   (donc `public.part_config`) mais aucune table `migrations`, `migrate:fresh` **ne tente aucun
   DROP** et passe au vert — alors qu'un `php artisan db:wipe` sur la **même** base échoue
   immédiatement en 2BP01. C'est précisément ce qui a masqué la panne en CI (base recréée à
   chaque run) et l'a rendue visible en local (base persistante).

5. **La mise en garde « un premier run rouge avec des `QueryException` partout n'est PAS une vraie
   rougeur » ne s'applique pas ici.** La rougeur mesurée est **une seule** ligne
   `Dropping all tables … FAIL`, avant toute migration. Il n'y a pas de dégât collatéral à trier.

6. **Une base créée par `CREATE DATABASE` n'hérite de rien.** Vérifié :
   `axion_crm_fresh` fraîchement créée ne porte que `plpgsql`. Les 10 extensions viennent
   **uniquement** de la migration `2026_05_16_000001`, jamais de `infra/postgres/init/01-extensions.sql`
   (qui ne s'exécute qu'au premier démarrage d'un **volume**).

---

## 4. Voie corrective retenue, et ce que les autres auraient coûté

**Retenue : installer `pg_partman` dans son propre schéma `partman`, hors du `search_path`.**

Une seule cause traitée, deux symptômes réparés : `dropAllTables()` ne voit plus `part_config`
(cause 1), **et** `partman.create_parent()` — écrit tel quel depuis l'origine — se met enfin à
résoudre. Rien à changer dans le framework, rien dans la configuration de connexion, et
`RefreshDatabase` en bénéficie **sans wrapper** puisqu'il passe par le même `migrate:fresh`.

Trois fichiers, un ajout :

| Fichier | Rôle |
|---|---|
| `infra/postgres/init/01-extensions.sql` | `CREATE SCHEMA partman;` + `CREATE EXTENSION … SCHEMA partman` — pour les **volumes** neufs |
| `backend/database/migrations/2026_05_16_000001_create_extensions_and_helpers.php` | crée **ou relocalise** pg_partman ; s'exécute avant `000011` sur toute base neuve |
| `backend/database/migrations/2026_08_18_100001_partman_dans_son_propre_schema.php` (nouveau) | relocalise sur les bases où `000001` est **déjà enregistrée** (dev, préprod, **production**) |
| `backend/database/migrations/2026_05_17_000011_setup_pg_partman_audit_logs.php` | ordre corrigé (cause 2), schéma et signature `create_parent` résolus dynamiquement |

Pourquoi la relocalisation vit **aussi** dans `000001` et pas seulement dans la migration datée :
sur une base neuve dont le **volume** porte l'ancien script d'init (c'est le cas de
`axion_crm_test` en CI, créée par `POSTGRES_DB`), l'extension existe déjà dans `public` et
`CREATE EXTENSION IF NOT EXISTS … SCHEMA partman` est un **no-op silencieux** (`IF NOT EXISTS` ne
regarde que le nom). Si l'on attendait la migration datée, `000011` aurait entre-temps enregistré
`audit_logs` dans `public.part_config`, et la relocalisation serait devenue impossible sans perte.

**Sûreté production.** `pg_partman` est `relocatable = false` : `ALTER EXTENSION … SET SCHEMA` est
refusé, seul `DROP` + `CREATE` fonctionne, ce qui détruit `part_config`. Les deux blocs
**abandonnent donc la relocalisation** (avec un `RAISE WARNING`, sans faire rougir le déploiement)
dès que `part_config` porte la moindre ligne. Le cas mesuré partout est `part_config` **vide** —
puisque `create_parent` n'a jamais abouti. `000011` reste par ailleurs **enregistrée** en
production : son contenu modifié n'y sera pas rejoué, et il est de toute façon idempotent
(sortie immédiate si `audit_logs` est déjà partitionnée).

### Ce que les autres voies auraient coûté

- **`'dont_drop' => ['spatial_ref_sys', 'part_config', 'part_config_sub']`** dans
  `config/database.php` — une ligne, la réponse « officielle » de Laravel. Écartée pour trois
  raisons : elle est **hors du périmètre d'écriture** de cette ligne du plan ; elle laisse
  `part_config` **survivre** à chaque `migrate:fresh` avec ses lignes, donc `create_parent`
  échouerait au run suivant sur un « already registered » ; et surtout elle **ne répare pas**
  `partman.create_parent`, laissant pg_partman définitivement inerte.
- **Une commande artisan enveloppante `db:rebuild-local`** (drop du schéma puis `migrate:fresh`) —
  écartée : `RefreshDatabase` appelle `Artisan::call('migrate:fresh')` **en dur**. La suite Pest
  n'en aurait pas bénéficié : le problème n'aurait été que déplacé, ce que le cahier des charges
  interdit explicitement.
- **Surcharger `PostgresBuilder::dropAllTables()`** via un resolver de connexion — écartée :
  invasif, hors périmètre (`app/Providers`), et il faudrait le maintenir à chaque montée de
  Laravel.

### Cause 2 — correctif

Dans `000011`, l'ordre est désormais : créer la table partitionnée → nettoyer une éventuelle
configuration `part_config` orpheline → `create_parent` (avec `p_type` choisi selon
`extversion`) → **s'assurer qu'une partition `DEFAULT` existe** → **puis** réinjecter les lignes.
Ajout d'un `setval()` sur `audit_logs_id_seq` : la table étant recréée, la séquence repartait à 1
alors que les lignes réinjectées portaient leurs anciens `id` — la première écriture d'audit
suivante aurait violé la clé primaire.

---

## 5. Preuves vertes

### 5.1 De zéro, puis deux fois de suite

`DROP DATABASE` / `CREATE DATABASE axion_crm_fresh` → `select extname from pg_extension` → `plpgsql` seul.

```
########## RUN 1 : php artisan migrate:fresh ##########
  Creating migration table ..................................... 496.87ms DONE
  2026_05_16_000001_create_extensions_and_helpers ................... 7 s DONE
  2026_05_17_000011_setup_pg_partman_audit_logs ..................... 2 s DONE
  2026_08_17_000001_companies_hub_tous_index .................... 92.22ms DONE
  2026_08_18_100001_partman_dans_son_propre_schema ............... 4.04ms DONE
CODE_DE_SORTIE=0

########## RUN 2 : php artisan migrate:fresh ##########
  Dropping all tables ............................................... 1 s DONE
  Creating migration table ..................................... 182.13ms DONE
  2026_05_16_000001_create_extensions_and_helpers .............. 139.68ms DONE
  2026_05_17_000011_setup_pg_partman_audit_logs ..................... 1 s DONE
  2026_08_17_000001_companies_hub_tous_index .................... 29.73ms DONE
  2026_08_18_100001_partman_dans_son_propre_schema ............... 2.29ms DONE
CODE_DE_SORTIE=0

########## RUN 3 : php artisan migrate:fresh ##########
  Dropping all tables .......................................... 994.13ms DONE
  Creating migration table ..................................... 105.92ms DONE
  2026_05_16_000001_create_extensions_and_helpers .............. 494.32ms DONE
  2026_05_17_000011_setup_pg_partman_audit_logs ..................... 1 s DONE
  2026_08_17_000001_companies_hub_tous_index .................... 45.81ms DONE
  2026_08_18_100001_partman_dans_son_propre_schema ............... 2.79ms DONE
CODE_DE_SORTIE=0
```

`migrate:fresh --seed` a également été joué **deux fois de suite**, `CODE_DE_SORTIE=0` les deux fois.

### 5.2 Extensions de la base reconstruite

Les 10 de `01-extensions.sql` (+ `plpgsql`) :

```
  extname   | extversion |   schema
------------+------------+------------
 btree_gin  | 1.3        | public
 btree_gist | 1.7        | public
 citext     | 1.6        | public
 pg_partman | 5.1.0      | partman
 pg_trgm    | 1.6        | public
 pgcrypto   | 1.3        | public
 plpgsql    | 1.0        | pg_catalog
 postgis    | 3.5.2      | public
 unaccent   | 1.1        | public
 uuid-ossp  | 1.1        | public
 vector     | 0.8.0      | public
(11 rows)
```

### 5.3 « y compris pg_partman » — pour la première fois, il gère réellement `audit_logs`

```
   parent_table    | partition_type | partition_interval | premake | retention
-------------------+----------------+--------------------+---------+-----------
 public.audit_logs | range          | 1 mon              |       6 | 24 months

 nb_partitions_audit_logs
--------------------------
                       14
```

### 5.4 Chemin « base déjà migrée » (le cas de la production)

État reconstitué avec le code d'**avant** le correctif, puis correctif appliqué par un simple
`migrate` :

```
########## 1. Etat ACTUEL reconstitue ##########
  extname   | schema         lignes_part_config
 pg_partman | public                          0

########## 2. migrate:fresh AVANT correctif ##########
  Dropping all tables .......................................... 589.03ms FAIL
CODE_DE_SORTIE=1

########## 3. Correctif applique par un simple migrate ##########
  2026_08_18_100001_partman_dans_son_propre_schema ............. 389.11ms DONE
CODE_DE_SORTIE=0
  extname   | schema
 pg_partman | partman

########## 4. migrate:fresh, 1re fois ##########
  Dropping all tables ............................................... 2 s DONE
CODE_DE_SORTIE=0

########## 5. migrate:fresh, 2e fois de suite ##########
  Dropping all tables ............................................... 1 s DONE
CODE_DE_SORTIE=0
```

### 5.5 Chemin « base neuve de CI » (volume portant l'ancien script d'init)

`CREATE DATABASE` + ancien `01-extensions.sql` (pg_partman dans `public`), aucune migration :

```
########## migrate:fresh n°1 ##########
  2026_05_16_000001_create_extensions_and_helpers ................... 1 s DONE
  2026_05_17_000011_setup_pg_partman_audit_logs ..................... 1 s DONE
CODE_DE_SORTIE=0
pg_partman -> schema partman

########## migrate:fresh n°2 ##########
  Dropping all tables ............................................... 1 s DONE
CODE_DE_SORTIE=0
pg_partman -> schema partman

########## migrate:fresh n°3 ##########
  Dropping all tables .......................................... 956.44ms DONE
CODE_DE_SORTIE=0
pg_partman -> schema partman
```

C'est ce qui garantit que la CI restera verte **sans attendre** la reconstruction de l'image
GHCR `axion-crm-pro-postgres` (qui embarque encore l'ancien `01-extensions.sql`).

---

## 6. Les gardes — vues rouges, puis vertes

Deux tests Pest, dans le job `backend` de la CI (Pest y est **bloquant**) :

- `backend/tests/Feature/Database/ReconstructionBaseTest.php` — cause 1
- `backend/tests/Feature/Database/AuditLogsPartitionnementTest.php` — cause 2

### Garde 1 — rouge sur la base d'avant le correctif

```
   FAILED  Tests\Feature\Database\ReconstructionBaseTest > it n'expose aucun…
  LA RECONSTRUCTION DE LA BASE EST REDEVENUE IMPOSSIBLE.
Ces tables appartiennent à une extension et sont visibles dans le search_path :
  - public.part_config (extension pg_partman)
  - public.part_config_sub (extension pg_partman)
…
Failed asserting that two arrays are identical.
  -Array &0 []
  +Array &0 [
  +    0 => 'public.part_config (extension pg_partman)',
  +    1 => 'public.part_config_sub (extension pg_partman)',
  +]
  Tests:    1 failed (2 assertions)
```

### Garde 2 — rouge avec l'ancien `000011` réintroduit (cause 1 déjà corrigée)

```
   FAILED  Tests\Feature\Database\AuditLogsPartitionnementTe…  QueryException
  SQLSTATE[23514]: Check violation: 7 ERROR:  no partition of relation "audit_logs" found for row
DETAIL:  Partition key of the failing row contains (created_at) = (2026-08-18 14:03:01+02).
CONTEXT:  SQL statement "INSERT INTO audit_logs SELECT * FROM audit_logs_old"
  Tests:    1 failed, 1 passed (2 assertions)
```

### Les deux, vertes, sur le code final

```
   PASS  Tests\Feature\Database\AuditLogsPartitionnementTest
  ✓ it convertit audit_logs en table partitionnée sans perdre les lign… 68.66s

   PASS  Tests\Feature\Database\ReconstructionBaseTest
  ✓ it n'expose aucune table d'extension au DROP TABLE global de migrat… 3.03s

  Tests:    2 passed (5 assertions)
CODE_DE_SORTIE=0
```

La garde 1 n'utilise **pas** `RefreshDatabase` — c'est justement `RefreshDatabase` qui mourait.
Elle appelle `Artisan::call('migrate')` pour ne pas être verte à vide, puis vérifie que
`pg_partman` est bien installé avant de conclure.

### Cible Makefile

`make db-rebuild-check` : `migrate:fresh` **deux fois de suite**, puis deux assertions —
pg_partman doit être dans le schéma `partman`, et `part_config` doit porter la ligne
`public.audit_logs` (sinon `create_parent` est retombé sur le repli DEFAULT).
`make db-rebuild-local` : la commande unique de reconstruction (`migrate:fresh --seed`).
L'en-tête de section du `Makefile` documente les deux causes historiques.

---

## 7. Restes et angles morts

- **`infra/postgres/init/01-extensions.sql` est corrigé mais inerte tant que l'image GHCR
  `ghcr.io/will383842/axion-crm-pro-postgres:16-3.5-vector-partman` n'est pas reconstruite** :
  `Dockerfile.postgres:52` la **copie dans l'image**. Les migrations couvrent ce trou (cf. §5.5),
  mais la reconstruction de l'image reste à faire pour que le script d'init dise la vérité.
- **`axion_crm` et `axion_crm_test` n'ont pas été migrées** : deux autres sessions les occupaient.
  Elles portent encore pg_partman dans `public` et resteront non reconstructibles jusqu'à un
  `php artisan migrate` (qui appliquera `2026_08_18_100001`).
- **`pg_partman` ne tourne toujours pas en tâche de fond** : l'image est compilée `NO_BGW=1`.
  `part_config` est désormais renseignée (rétention 24 mois), mais rien n'appelle
  `partman.run_maintenance_proc()`. Les partitions créées d'avance couvrent 6 mois ; au-delà, les
  lignes tomberont dans la partition `DEFAULT` — fonctionnel, mais ce n'est pas du partitionnement
  utile. **Reste à faire hors de cette ligne** : un `run_maintenance` planifié (cron/Horizon).
- **`backend/database/migrations/2026_08_18_000001_backfill_empreintes_oppositions.php`
  (autre session, même worktree) casse la suite Pest.** Sa ligne 6, `use RuntimeException;`, est
  un import non-composé du namespace global : PHP émet un `Warning`, que le gestionnaire d'erreurs
  de Laravel transforme en `ErrorException` en environnement `testing`. `php artisan migrate` en
  CLI passe, **tout test qui charge les migrations échoue** :
  `ErrorException: The use statement with non-compound name 'RuntimeException' has no effect`.
  Le fichier n'a **pas** été modifié (session en cours) ; les mesures ci-dessus ont été faites sur
  une copie de travail sans cette ligne. **À corriger avant de fusionner** — suppression de la
  ligne 6, sans autre changement.
