# Harnais de test dans un worktree git

> Écrit le 2026-08-19 pendant l'étape 1a du chantier « CRM cible ».
> Ces scripts vivaient dans un dossier temporaire de session ; ils sont versionnés
> ici pour qu'une reprise après fermeture les retrouve (§28.1 du cahier des
> charges : « l'autopilote reconstruit son état depuis le dépôt et le journal »).

## Le problème qu'ils résolvent

Un worktree git neuf **n'a ni `backend/vendor`, ni `frontend/node_modules`, ni
`backend/storage`, ni `.env`** — rien de tout cela n'est versionné. On ne peut
donc rien y jouer tel quel.

L'étape 0 contournait ça avec des jonctions NTFS (`mklink /J`). Pour le backend,
**monter le `vendor` du dépôt principal par-dessus le code du worktree, dans le
conteneur**, est plus propre : rien n'est écrit dans le worktree, et le montage
disparaît avec le conteneur.

⚠️ Cela ne vaut que si les `composer.lock` des deux arbres sont identiques.
`pest-worktree.sh` le vérifie et refuse de partir sinon.

## Préparer le worktree (une fois)

```bash
mkdir -p backend/bootstrap/cache \
         backend/storage/app/private \
         backend/storage/framework/cache/data \
         backend/storage/framework/sessions \
         backend/storage/framework/testing \
         backend/storage/framework/views \
         backend/storage/logs
cp .env.example backend/.env      # Laravel lit .env au démarrage ; sans lui,
                                  # `failOnWarning="true"` fait rougir la suite
cp .env.example .env              # exigé par `docker compose config` (env_file du service api)
```

Pour les contrôles frontend (`tsc`, `eslint`, `vitest`), une jonction suffit —
**à retirer en fin de tâche** :

```
cmd /c mklink /J "<worktree>\frontend\node_modules" "<depot-principal>\frontend\node_modules"
cmd /c rmdir     "<worktree>\frontend\node_modules"
```

## La base de test

`RefreshDatabase` a besoin d'une base `axion_crm_test` **récente**. Une base
créée avant la migration `2026_08_18_100001_partman_dans_son_propre_schema`
échoue sur `cannot drop table part_config because extension pg_partman requires
it`. La recréer, dans la **même locale que la production** :

```bash
docker exec axion-crm-postgres psql -U axion -d postgres \
  -c "DROP DATABASE IF EXISTS axion_crm_test" \
  -c "CREATE DATABASE axion_crm_test WITH ENCODING 'UTF8' LC_COLLATE 'C' LC_CTYPE 'C' TEMPLATE template0"
```

La locale n'est pas un détail : en `en_US.utf8`, `lower()` abaisse les majuscules
accentuées, pas en `C`. La CI validait une sémantique que la production n'a pas
(empreintes d'opposition, tris) — corrigé à l'étape 0.

## Les scripts

| Script | Usage |
|---|---|
| `pest-worktree.sh` | `./pest-worktree.sh ./vendor/bin/pest tests/Feature/Crm` · `./pest-worktree.sh ./vendor/bin/phpstan analyse --memory-limit=4G --no-progress` · `./pest-worktree.sh ./vendor/bin/pint --test app/…` |
| `artisan-worktree.sh` | `./artisan-worktree.sh axion_crm_perf migrate --force` · `./artisan-worktree.sh axion_crm_test migrate:status` |
| `garde-ports-prod.sh` | Rejoue à la main le job CI `config-prod` (aucun port publié en production hors 80/443). |

## Pièges mesurés, à ne pas redécouvrir

1. **Ne pas remplir une grosse base ET jouer la suite de tests en même temps.**
   Les deux finissent en attente `WALWrite` sur le même disque virtuel et les
   durées deviennent ininterprétables (un test à 3,9 s passe à 8,1 s).
2. **Postgres sous Docker Desktop tombe** sur un `INSERT` de plusieurs millions
   de lignes en une seule transaction (`exited with exit code 2`, redo de
   plusieurs minutes). Insérer par lots commités.
3. `docker exec` tué côté client **continue** de tourner dans le conteneur : un
   `psql -f` interrompu peut finir son travail plus tard et faire échouer la
   tentative suivante sur un doublon.
4. Sous Git Bash, préfixer par `MSYS_NO_PATHCONV=1` tout `docker` qui reçoit un
   chemin absolu, sinon `/var/www/html` devient `C:/Program Files/Git/var/...`.
