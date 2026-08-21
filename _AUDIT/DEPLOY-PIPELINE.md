# Pipeline de déploiement — SSH direct vers Hetzner

> **Ce document est relu par une garde exécutable.**
> `backend/tests/Feature/Infra/DocsDeDeploiementDisentLeVraiTest.php` compare
> chaque commande imprimée ici au contenu réel de
> `.github/workflows/deploy-direct-ssh.yml`, **dans les deux sens** : une
> commande inventée le fait rougir, une commande **omise** aussi.
> Si vous modifiez le workflow, ce fichier rougit tant qu'il n'est pas réécrit.
> C'est voulu.
>
> État mesuré le **2026-08-20** sur la branche `fix/a35-authentification`
> (`.github/workflows/deploy-direct-ssh.yml`, 18 655 octets).

---

## Pourquoi ce document a été entièrement réécrit

La version précédente (constats **A09-001**, S1) décrivait un pipeline qui
n'existait plus. Trois de ses lignes (14, 84, 97) prescrivaient un
`up -d --force-recreate api app` : **sans `--no-deps`, sans `--build`, sans
`horizon`, sans `scheduler`, et sans jamais poser l'overlay de production.**

> La commande fautive n'est volontairement **pas recopiée en toutes lettres**
> ici : la garde qui relit ce fichier compare chaque commande imprimée au
> workflow réel, et un contre-exemple imprimé serait, pour elle, une
> prescription comme une autre. Un runbook n'a pas à contenir la forme qu'il
> interdit — c'est encore du texte qu'un opérateur peut copier.

L'écart n'est pas cosmétique :

| Écart | Conséquence si un opérateur suit le document |
|---|---|
| `--no-deps` absent | `api`, `app`, `horizon` portent `depends_on: [postgres, redis]`. Sans `--no-deps`, Compose **monte aussi la base et le cache**. Dans un shell qui ne porte pas `COMPOSE_FILE`, il les **recrée** depuis le seul `docker-compose.yml`, qui publie 55432 et 56379 sur `0.0.0.0`. C'est **la faille du 2026-08-19** : 4 295 349 fiches `companies` lues depuis internet, Redis sans mot de passe. |
| `--build` absent | Le frontend n'est jamais reconstruit : l'image `app` reste celle du déploiement précédent. |
| `horizon` / `scheduler` absents | Constaté en direct le 2026-08-16 : `api` avait 3 minutes, `horizon` en avait 28. Deux versions du même code dans le même système. |
| `COMPOSE_FILE` jamais mentionné | La pile repart en cible `dev`, avec le montage bind qui **masque le `vendor` de l'image**. Le `vendor` du serveur datait du 17 mai. |

Le même document prescrivait `config:clear` comme dernier geste. Cette commande
a été **retirée** du déploiement : c'est `infra/docker/entrypoint-prod.sh` qui
construit les caches de configuration et de routes au démarrage, avec
l'environnement réel. Les vider juste après annule son travail.

Il annonçait enfin un `deploy-prod.yml` — qui n'a **jamais existé dans ce
dépôt**. Le CRM ne se déploie pas par Coolify : c'est le **site Axion-IA**, un
autre produit dans un autre dépôt, qui le fait. Confusion durable, coûteuse, et
maintenant tenue par une garde.

---

## Vue d'ensemble — ce qui tourne réellement

```
push sur main
   │
   ├─ job « ci » : .github/workflows/ci.yml appelé en workflow_call
   │     6 jobs BLOQUANTS — config-prod · caddyfile-valide · scripts-executables
   │                      · backend (PHPStan 8 + Pint + Pest) · frontend · workers
   │
   └─ job « deploy » — needs: [ci]  +  if: needs.ci.result == 'success'
         │  (aucun déploiement ne part sur une CI rouge : c'est le durcissement
         │   du 2026-08-13, qui a remplacé un déploiement inconditionnel)
         │
         ├─ webfactory/ssh-agent@v0.10.0  +  ssh-keyscan de l'hôte
         ├─ ssh root@HETZNER_HOST 'bash -s'  (heredoc QUOTÉ, 4 arguments)
         │     ├─ export COMPOSE_FILE  ← l'overlay de production, pour TOUT le script
         │     ├─ git fetch + git reset --hard sur le SHA validé par la CI
         │     ├─ upsert INSEE_API_KEY dans le .env du serveur
         │     ├─ pull + up (services applicatifs, puis postgres/redis)
         │     ├─ migrate --force  (BLOQUANT)
         │     ├─ cache:clear
         │     ├─ vérif : santé des 6 conteneurs vitaux
         │     ├─ vérif : ports RÉELLEMENT publiés (verifier-ports-publies.sh)
         │     ├─ vérif : aucune migration Pending
         │     └─ sentinelle AXION_DEPLOY_SCRIPT_COMPLETE
         └─ smoke HTTP : curl sur HEALTH_URL puis APP_URL
```

---

## Le script distant, commande par commande

Ce bloc est **la transcription exacte** de ce que le heredoc `REMOTE` exécute
sur le serveur. Les commandes sont reproduites telles quelles : c'est ce que
la garde compare.

```bash
# L'overlay de production s'applique à TOUS les `docker compose` qui suivent.
# Un `-f` recopié se serait oublié quelque part ; une variable exportée, non.
export COMPOSE_FILE="docker-compose.yml:docker-compose.prod.yml"

# On déploie EXACTEMENT le commit qui vient de passer la CI — plus de
# `reset --hard origin/main`, qui était non déterministe. Le SHA servi est
# ensuite relu et comparé ; s'il diffère, le job échoue.
git fetch --all --prune
git reset --hard "$EXPECTED_SHA"

# Les services applicatifs. `--build` reconstruit aussi le frontend.
# `--no-deps` empêche d'emporter postgres et redis par `depends_on`.
# `reverb` est VOLONTAIREMENT absent : l'ajouter le DÉMARRERAIT.
docker compose pull --ignore-pull-failures || true
docker compose up -d --build --force-recreate --no-deps api app horizon scheduler

# La base et le cache, SANS `--force-recreate` (F40-005, 2026-08-20).
# `up -d` ne recrée un conteneur que si sa configuration fusionnée a changé :
# dans le cas courant c'est un no-op et la base n'est pas coupée. Sans ce pas,
# aucune modification de l'overlay portant sur `postgres` ou `redis` ne pouvait
# atteindre la production — l'écart mesuré le 19/08, où le fichier avait raison
# et le conteneur avait tort.
docker compose up -d --no-deps postgres redis

# 🔴 CADDY DOIT REDÉMARRER APRÈS `api` — panne de production du 2026-08-21.
#
# Recréer `api` lui donne une NOUVELLE adresse sur le réseau interne. Caddy
# n'était ni recréé ni redémarré : il gardait l'ancienne, résolue au démarrage
# de son propre conteneur, et rendait 502 sur tout le domaine `api`.
#
# Mesuré après le déploiement 377febf : les cinq conteneurs `healthy`, les onze
# migrations passées, `app` en 200 — et l'API en 502 pendant treize minutes.
#
# `restart` et NON `up -d --no-deps caddy` : `up -d` ne recrée que si la
# CONFIGURATION a changé. Ici elle n'a pas bougé — c'est l'adresse de l'amont.
# `up -d` serait un no-op, et la panne resterait entière.
docker compose restart caddy

# Migration BLOQUANTE (plus de `|| true`). `--database=pgsql_owner` : les
# migrations doivent tourner avec le rôle PROPRIÉTAIRE des tables.
# `</dev/null` n'est pas décoratif : sans lui, `exec -T` consomme le reste du
# heredoc et tout ce qui suit est silencieusement AVALÉ (déploiement b84100f).
docker compose exec -u root -T api php artisan migrate --force --database=pgsql_owner </dev/null

# Cache APPLICATIF (Redis) seulement. `config:clear` et `route:clear` ont été
# retirés : entrypoint-prod.sh construit ces caches au démarrage.
docker compose exec -u root -T api php artisan cache:clear </dev/null

# Vérification 1 — les 6 conteneurs vitaux (api app horizon postgres redis caddy)
# sont `running` et aucun n'est `unhealthy`.
docker compose ps --format '{{.Service}}|{{.State}}|{{.Status}}'
docker compose logs --tail=50 "$svc"

# Vérification 2 — les ports RÉELLEMENT publiés, lus dans `docker ps`.
# Aucune tolérance : ni `|| true`, ni `|| echo`. `exit 2` (« la mesure n'a rien
# vu ») fait échouer le déploiement, au même titre qu'`exit 1`.
bash "$PROJECT_PATH/infra/scripts/verifier-ports-publies.sh" axion-crm-pro

# Vérification 3 — aucune migration `Pending` après le déploiement.
docker compose exec -u root -T api php artisan migrate:status --no-ansi --database=pgsql_owner </dev/null
```

La dernière ligne du script distant est la sentinelle
`AXION_DEPLOY_SCRIPT_COMPLETE`. Le job la cherche dans `/tmp/deploy-remote.log`
et échoue si elle manque : c'est la seule preuve que les trois vérifications
ci-dessus ont réellement tourné.

Puis, hors SSH :

```bash
curl -fsS --max-time 10 "$HEALTH_URL"
curl -fsS --max-time 10 -o /dev/null "$APP_URL"
```

---

## Déclencheurs

- **Automatique** : `push` sur `main`.
  `paths-ignore` : `_AUDIT/**`, `_REPORTS/**`, `spec/**`, `docs/**`, `**/*.md`,
  et `.github/workflows/**` — un changement purement CI ne redéploie pas l'app.
- **Manuel** : `Actions` → *Deploy direct SSH Hetzner* → *Run workflow*.
  Entrée `skip_migrate` (`true` / `false`, défaut `false`).
- **Sérialisation** : `concurrency: deploy-direct-ssh`, `cancel-in-progress: false`.
- **Environnement GitHub** : `production-direct-ssh`, URL `https://app.axion-crm-pro.com`.

---

## Secrets et variables

Secrets (`Settings` → `Secrets and variables` → `Actions` → *New repository secret*) :

| Nom | Valeur | Obligatoire |
|---|---|---|
| `HETZNER_SSH_PRIVATE_KEY` | Clé privée OpenSSH autorisée sur le serveur | ✅ le job échoue si absent |
| `HETZNER_HOST` | IP ou nom d'hôte Hetzner | ✅ le job échoue si absent |
| `HETZNER_USER` | Utilisateur SSH | ❌ défaut `root` |
| `HETZNER_PROJECT_PATH` | Chemin du dépôt sur le serveur | ❌ défaut `/opt/axion-crm-pro` |
| `INSEE_API_KEY` | Clé INSEE, réinjectée dans le `.env` du serveur à chaque déploiement | ❌ le pas est sauté si vide |

Les deux premiers sont vérifiés par une étape dédiée **avant** toute connexion.
Les deux suivants ont un défaut ; `INSEE_API_KEY` n'en a pas, et son absence est
silencieuse — le `.env` du serveur garde alors sa valeur précédente.

Variables (onglet *Variables*) :

| Nom | Défaut si absente |
|---|---|
| `HEALTH_URL` | `https://api.axion-crm-pro.com/up` |
| `APP_URL` | `https://app.axion-crm-pro.com` |

### Préparer la clé SSH dédiée

```bash
ssh-keygen -t ed25519 -f ~/.ssh/axion_crm_deploy_ed25519 -C "github-actions@axion-crm-pro" -N ""
ssh-copy-id -i ~/.ssh/axion_crm_deploy_ed25519.pub root@<hôte>
cat ~/.ssh/axion_crm_deploy_ed25519
```

Le contenu complet (`-----BEGIN OPENSSH PRIVATE KEY-----` … `-----END OPENSSH
PRIVATE KEY-----`, sauts de ligne compris) va dans `HETZNER_SSH_PRIVATE_KEY`.

---

## Ce qu'il faut savoir avant de toucher au workflow

- **Chaque `exec -T` de Compose doit lire sur `/dev/null`.** Sinon il
  consomme le reste du heredoc, qui arrive par l'entrée standard, et tout ce qui
  suit est avalé **en silence**. C'est ce qui s'est produit au déploiement
  `b84100f` : aucune vérification post-déploiement n'a tourné, et le job est
  resté vert.
- **`reverb` est délibérément hors de toutes les listes de services.** Il est
  arrêté, Echo est désactivé côté frontend et le Caddyfile n'a aucune route
  WebSocket vers lui. L'ajouter le démarrerait. Rallumer le temps réel est une
  décision produit, pas une ligne de plus dans un `up`.
- **`--database=pgsql_owner` sur les migrations.** Aujourd'hui la connexion
  `pgsql_owner` porte les mêmes identifiants que `pgsql` tant que
  `CRM_DB_APP_ROLE_ENABLED` est à `false`. Elle devient indispensable dès que
  l'application bascule sur le rôle applicatif non-propriétaire, qui n'a aucun
  droit DDL. ⚠️ L'état de ce drapeau **en production** est contredit à
  l'intérieur même du dépôt : voir la note en fin de document.
- **Aucune étape ne fait `composer install`.** Le `vendor` servi est celui de
  l'image, et l'overlay de production est ce qui empêche le montage bind de le
  masquer. Retirer `COMPOSE_FILE` rejouerait le défaut du 2026-08-16.

---

## Revenir en arrière

**Il n'existe aucun rollback automatisé, et ce document ne va pas en inventer un.**

Le seul geste prévu par le pipeline est de **redéployer un SHA connu bon** :

1. `Actions` → *Deploy direct SSH Hetzner* → *Run workflow*, sur la référence
   voulue. La CI rejoue, puis le script remet le serveur exactement sur ce SHA.
2. Si la CI de ce SHA est rouge, le job `deploy` ne part pas. C'est le
   comportement voulu ; le contourner demande de comprendre pourquoi elle rouge.

**Le retour arrière du schéma de base est une décision d'exploitant, pas une
étape de runbook.** `php artisan migrate:rollback` défait la dernière fournée de
migrations : sur cette base, cela peut supprimer des colonnes et leurs données.
Aucune ligne exécutable n'est imprimée ici volontairement — la reprise de
données passe par `infra/runbooks/04-restore-dr.md`, qui part des sauvegardes.

---

## Différences avec `deploy-staging.yml`

| Aspect | `deploy-direct-ssh.yml` (production) | `deploy-staging.yml` (préproduction) |
|---|---|---|
| Cible | Hetzner, `/opt/axion-crm-pro` | Hetzner, `/opt/axion-crm-pro-staging` |
| Gate CI | ✅ `needs: [ci]` + `if: needs.ci.result == 'success'` | ❌ `needs: build-and-push` seulement |
| Images | construites sur place (`--build`) | construites et poussées sur GHCR (`:staging`, `:sha`) |
| Migrations | inline, bloquantes | inline |
| Environnement | `production-direct-ssh` | `staging-direct-ssh` |
| Sérialisation | `deploy-direct-ssh` | `deploy-staging` |

Les deux rejouent **le même patron SSH**. C'est délibéré : une préproduction
bâtie sur un autre mécanisme que la production ne prouve rien sur le chemin
réel. Les jobs Coolify de `deploy-staging.yml` ont été retirés le 2026-08-19 —
ils n'avaient jamais tourné.

---

## Note ouverte — l'état du rôle applicatif en production

Ce dépôt se contredit sur `CRM_DB_APP_ROLE_ENABLED`, et le déploiement en
dépend :

- `backend/app/Console/Commands/CoverageRefreshMatrix.php` et
  `backend/database/migrations/2026_08_20_140000_rafraichir_matrice_couverture_par_fonction_definer.php`
  datent le constat A08-001 du 2026-08-20 et l'expliquent par
  « **depuis l'armement du rôle applicatif** » — donc drapeau à `true`.
- `backend/app/Console/Commands/PartmanMaintenir.php` écrit, à la même date,
  « **aujourd'hui `CRM_DB_APP_ROLE_ENABLED` vaut false** ».

L'un des deux est faux. La production n'est pas observable depuis ce dépôt et ce
document **ne tranche pas** : il signale. Tant que ce n'est pas tranché, toute
phrase sur « ce que fait la RLS en production » est à lire comme non établie.
