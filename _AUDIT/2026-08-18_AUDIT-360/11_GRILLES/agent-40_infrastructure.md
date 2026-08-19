# AGENT 40 — Auditeur infrastructure et exposition

- **Référence mesurée** : `main = e8924b8` (relu par `git rev-parse HEAD` le 2026-08-19, poste ET serveur — les deux sont sur `e8924b8`).
- **Production** : `root@46.62.248.239`, hôte `axion-crm-edge`, en marche depuis 42 jours.
- **Mode d'accès** : SSH, **lecture seule stricte**. Aucun `restart`, aucun `up`, aucune écriture, aucun secret modifié, aucun fichier déposé dans un conteneur.
- **Preuves brutes** : `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/agent-40/` (28 fichiers).

---

## 0. Les trois vérifications confiées en priorité — réponses

| # | Question | Réponse **mesurée** | Preuve |
|---|---|---|---|
| 1 | `AUDIT_HASH_CHAIN_SECRET` est-il vide en production ? | **NON — longueur 64**, ni vide, ni le défaut `dev-only-secret-change-me`. Mesuré deux fois : dans l'environnement du processus (`wc -c` = 64) **et** dans l'application Laravel démarrée (`env()` → longueur 64, `=== ''` → non, `=== 'dev-only…'` → non). **B16-001 ne s'applique PAS à la production.** | `02_secret-audit-hash-chain.txt`, `03_secret-runtime-laravel.txt` |
| 2 | Quel serveur HTTP sert l'API en production ? | **`php -S 0.0.0.0:80 -t public`** — le serveur de développement intégré de PHP, **un seul processus, PID 1, sans enfant**, `PHP_CLI_SERVER_WORKERS` non défini. Le même que le local et que la préproduction. ⚠️ **Le chef de chantier a tranché ce point en parallèle : c'est le constat A-010 (S0), déjà ouvert.** Je ne le re-rapporte donc pas — j'apporte ce qui manquait : **le correctif chiffré (§6) et la garde qui rougit sur le conteneur (§7)**. | `04_processus-api-scheduler-app.txt`, `17_concurrence-php-s.txt`, `18_concurrence-50.txt`, `25_`, `26_` |
| 3 | Le planificateur tourne-t-il ? | **OUI.** `php artisan schedule:work` en PID 1, `RestartCount=0`, et **trace d'exécution réelle** : à `12:35:00` le journal du conteneur porte l'exécution de `campaigns:start-scheduled` (`* * * * *`) et `crm:flush-outbound` (`*/5 * * * *`), avec les verrous Redis correspondants. Le `healthcheck: disable: true` est bien appliqué (`docker inspect` → `Healthcheck.Test = [NONE]`, `State.Health` absent) : rien ne le surveillerait s'il mourait, mais **il n'est pas mort**. | `05_scheduler-logs.txt`, `07_docker-inspect-services.txt` |

---

## 1. Grille — un objet par ligne

### 1.1 Les fichiers de composition

| Fichier | l. | Chargé où ? | Vérifié contre le réel ? | Écart trouvé |
|---|---|---|---|---|
| `docker-compose.yml` | 216 | prod + préprod + test + local (socle) | ✅ `docker inspect` des 7 conteneurs de prod | `POSTGRES_PASSWORD` en dur → F40-007 ; `reverb` déclaré, jamais démarré (voulu, documenté) |
| `docker-compose.prod.yml` | 192 | prod (`config_files` du label Compose le confirme) | ✅ champ par champ | **aucun** — les 8 réglages de l'overlay sont TOUS appliqués (détail §2) |
| `docker-compose.local.yml` | 157 | poste seulement (nom non auto-chargé, vérifié) | n/a (jamais en prod, prouvé par le label) | `TELESCOPE_ENABLED: "false"` présent ICI et **absent du `.env` de prod** → F40-003 |
| `docker-compose.staging.yml` | 256 | préprod | ✅ 6 conteneurs `axion-crm-staging-*` en marche | `caddy` sous profil (évite de tuer le Caddy de prod) — correct |
| `docker-compose.test.yml` | 42 | CI | ⚠️ non rejoué (CI hors périmètre) | `TELESCOPE_ENABLED: "false"` présent ici aussi ; référence 3 services `worker-*` **qui n'existent plus** dans `docker-compose.yml` — `profiles:` sur un service inexistant est un no-op silencieux |
| `docker-compose.observability.yml` | 106 | **nulle part** | ✅ `docker ps` : 0 des 8 services en marche | **9 ports sur `0.0.0.0`, aucun `!override` dans l'overlay de prod** → F40-008 |

### 1.2 Les Dockerfile

| Fichier | Base | Cible prod | Réel mesuré | Écart |
|---|---|---|---|---|
| `Dockerfile.laravel` | `php:8.3-fpm-alpine` | `prod` | `CMD ["php","-S","0.0.0.0:80","-t","public"]` (l. 121) | 🔴 **php-fpm est dans l'image et n'est jamais lancé** → F40-001 |
| `Dockerfile.frontend` | `node:22-alpine` → `caddy:2-alpine` | `prod` | conteneur `app` : `caddy run`, healthcheck `wget :5173` ✅ | cible `prod-nginx` morte (rollback) → F40-012 |
| `Dockerfile.postgres` | `postgis/postgis:16-3.5` + pgvector 0.8.0 + pg_partman 5.1.0 | image GHCR | digest conteneur **identique** au tag tiré (`sha256:7ec90cff…`) ✅ | aucun |
| `Dockerfile.worker` | `mcr.microsoft.com/playwright` | `prod` | **référencé par aucun compose** ; construit par `deploy-staging.yml` et `security.yml` seulement | image construite et jamais exécutée → F40-012 |

### 1.3 `infra/`

| Objet | État | Mesure |
|---|---|---|
| `infra/caddy/Caddyfile` | ✅ en service | 6 blocs de site ; 4 certificats Let's Encrypt détenus par le Caddy de prod, 0 erreur de quota dans ses journaux |
| `infra/docker/entrypoint-prod.sh` | ✅ en service | construit `config/route/view` caches au démarrage ; `bootstrap/cache/config.php` daté de `10:10` = démarrage du conteneur ✅ |
| `infra/nginx/frontend.conf` | ⚠️ mort | ne sert que la cible `prod-nginx`, inutilisée → F40-012 |
| `infra/postgres/init/` | ✅ monté | bind `:ro` présent dans `docker inspect axion-crm-postgres` |
| `infra/monitoring/` | ⚠️ inerte | 9 tableaux Grafana, 1 `alerts.yml`, **aucun service en marche** |
| `infra/runbooks/` (5) | ⚠️ partiellement faux | `05-rotate-secrets.md` et `setup-hetzner-cpx22.sh` supposent `ufw` — **`ufw` n'est pas installé** → F40-009 |
| `infra/scripts/verifier-ports-publies.sh` | ✅ correct, ❌ mal branché | passe ses 4 témoins (§3), **absent du déploiement de production** → F40-004 |
| `infra/scripts/backup-postgres.sh` | ✅ en service | cron 03:00, dump 692 Mo/jour, dépôt hors site OK le 19/08 à 03:03:33, rotation locale 7 j / distante 30 j |
| `infra/scripts/definir-mot-de-passe-crm.sh` | ✅ lu (neuf, jamais audité) | correct sur le fond (mot de passe sur stdin, `password_hash`, témoin `Hash::check`). ⚠️ il **écrit** un fichier dans le conteneur (`docker cp`) puis le retire ; en cas d'interruption entre les deux, `/tmp/definir.php` reste — mais il ne contient pas le mot de passe (lu par `getenv`). Acceptable. |
| `infra/terraform/` | ⚠️ non rejoué | 4 fichiers, aucun `.tfstate` sur le serveur ; l'infrastructure a été montée à la main |
| `infra/loadtest/k6-api.js` | ⚠️ non rejoué | jamais lancé contre la prod par ce mandat (écriture possible) |
| `Makefile` | ✅ lu | `make up` = `docker compose up -d` **sans overlay** → sur un serveur, il lancerait la cible `dev` ; c'est le défaut du 16/08. Sans garde. |

### 1.4 Exposition réseau mesurée depuis le serveur

| Objet | Mesure | Verdict |
|---|---|---|
| Ports en écoute hors boucle locale | `22`, `80`, `443` (`0.0.0.0` + `[::]`) — plus `172.17.0.1:8081` et `:8082` (préprod, passerelle Docker, non routable) | ✅ conforme |
| `postgres` / `redis` publiés ? | `HostConfig.PortBindings = map[]` pour les deux | ✅ **la faille du 19/08 est bien fermée dans le conteneur**, pas seulement dans le fichier |
| `ufw` | `command not found` | ❌ F40-009 |
| `fail2ban` | actif, **1 seule prison : `sshd`** | ⚠️ rien sur HTTP |
| chaîne `iptables DOCKER` | 4 ACCEPT (443/80 → caddy prod ; 80/5173 → préprod sur son pont), 3 DROP | ✅ cohérent |
| Étanchéité prod ↔ préprod | 2 ponts distincts (`172.18` / `172.19`), **aucun conteneur des deux côtés** | ✅ |
| Rôle Postgres applicatif | `axion` : `rolsuper = t`, `rolbypassrls = t` | ❌ (déjà ouvert par l'agent 16) |

---

## 2. Le cœur du mandat — piège 18 : ce qui est écrit dans un fichier et absent du conteneur

**Méthode.** Pour les 7 conteneurs de production, comparaison **champ par champ** entre la configuration fusionnée (`docker compose -f docker-compose.yml -f docker-compose.prod.yml config`, jouée sur le serveur) et `docker inspect`. Champs comparés : `image` (par digest), `command`, `entrypoint`, `environment` (par clé), `healthcheck`, `restart`, `ports`, `binds`, `extra_hosts`, `networks`. Preuves : `07_`, `15_`, `22_`.

### 2.1 Ce qui EST appliqué — et qui aurait pu ne pas l'être

Les huit réglages de l'overlay de production portant sur les services **jamais recréés par le déploiement** sont **tous en place**. Quelqu'un a fait les `--force-recreate` explicites à la main : `postgres` et `redis` créés à `06:12`, `caddy` à `08:05`, les quatre services applicatifs à `10:10`.

| Réglage écrit | Service | Dans le conteneur ? |
|---|---|---|
| `ports: !override []` | `postgres` | ✅ `PortBindings = map[]` |
| `ports: !override []` | `redis` | ✅ `PortBindings = map[]` |
| `ports: !override []` | `reverb` | ✅ sans objet — `reverb` n'existe pas |
| `restart: unless-stopped` | `postgres` | ✅ |
| `restart: unless-stopped` | `redis` | ✅ |
| `extra_hosts: host.docker.internal:host-gateway` | `caddy` | ✅ `[host.docker.internal:host-gateway]` |
| `healthcheck: disable: true` | `scheduler` | ✅ `Test = [NONE]` |
| `healthcheck: horizon:status` | `horizon` | ✅ et `healthy` |
| digest d'image | `postgres`, `redis`, `caddy` | ✅ identiques au tag tiré, aucune dérive |

**Piège 8 vérifié aussi** : `.env` du serveur modifié à `10:10:15`, les trois conteneurs applicatifs créés à `10:10:20` — 5 s après. Ils lisent bien le `.env` courant. Et **aucune dérive entre `api`, `horizon` et `scheduler`** : 122 variables chacun, ensembles de clés strictement identiques, `APP_URL = https://api.axion-crm-pro.com` partout. Le défaut du 2026-08-16 est réellement corrigé.

### 2.2 🔴 Ce qui est écrit dans un fichier et **absent du réel** — la liste demandée

| # | Écrit dans | Absent de | Effet mesuré |
|---|---|---|---|
| **1** | `.env.example:` **`TELESCOPE_ENABLED`** ; posé à `"false"` dans `docker-compose.local.yml:75` **et** `docker-compose.test.yml` | le `.env` de production et les 3 conteneurs applicatifs (122 vars, la clé n'y est pas) | Telescope démarre à `true` sans ses tables → `SQLSTATE[42P01] relation "telescope_entries" does not exist` **à la terminaison de chaque requête et de chaque commande**. **C'est la cause racine de A-007.** → F40-003 |
| **2** | 7 clés `MAIL_*` complètes et valides dans le `.env` du serveur (ZeptoMail : hôte, port 587, TLS, identifiant, mot de passe, expéditeur) | **`MAIL_MAILER` n'existe NI dans le `.env` du serveur, NI dans `.env.example`, NI dans le conteneur** | `config/mail.php:4` → `env('MAIL_MAILER', 'log')` → pilote **`log`**. Toute la configuration SMTP est inerte : **aucun courriel ne quitte la production**. → F40-002 |
| **3** | `deploy-staging.yml:143` : `docker compose up -d --no-deps postgres redis` | `deploy-direct-ssh.yml` (production) — cette ligne n'y est pas | Le correctif du piège 18 a été écrit **pour la préproduction seulement**. Le déploiement de production reste structurellement incapable d'appliquer un changement sur `postgres`, `redis` ou `reverb`. → F40-005 |
| **4** | `deploy-staging.yml:175` : appel de `verifier-ports-publies.sh` | `deploy-direct-ssh.yml` — **aucun appel** (grep sur `port`, `publi`, `55432`, `56379` : 0 résultat pertinent) | La garde écrite mot pour mot pour la panne de PRODUCTION n'est branchée que sur la PRÉPRODUCTION. Et là où elle l'est, son échec est avalé par `|| echo "(contrôle des ports en échec…)"` : il ne touche pas `ok`, donc **il ne fait rougir aucun déploiement, nulle part**. → F40-004 |
| **5** | 20 autres clés de `.env.example` | le `.env` de production | `CRM_OUTBOUND_BATCH`, `CRM_OUTBOUND_MAX_ATTEMPTS`, `CRM_OUTBOUND_TIMEOUT`, `CRM_INGEST_MAX_CLOCK_SKEW`, `CRM_INGEST_BUSINESS_WORKSPACE`, `CRM_SCRAPE_VALIDATE_MX`, `DB_APP_USERNAME`, `SANTE_INGESTION_ENABLED`, `EMAIL_FINDER_SPECULATIVE_ENABLED`, `MOCK_RPPS`, `GOOGLE_PLACES_*` (4), `WEBSHARE_*` (4), `HUNTER_API_KEY`, `BRAVE_SEARCH_API_KEY`. Chacune retombe sur le défaut du code — non mesuré un par un. → F40-003 |
| **6** | `git`, `main = e8924b8` : **58** migrations suivies | rien — c'est l'inverse : **59** fichiers dans le conteneur et **59** lignes dans la table `migrations` | `2026_05_17_195529_create_failed_jobs_table.php` est **non suivi par git** sur le serveur, il est **entré dans l'image** (le contexte de build est `/opt/axion-crm-pro`, pas un checkout propre), et il est **appliqué en base (batch 7)** : `to_regclass('public.failed_jobs')` → `failed_jobs`. **La production exécute une migration qui n'est pas dans `main`.** → F40-006 |
| **7** | `docker-compose.observability.yml` : 9 publications sur `0.0.0.0` (9090, 9093, 3000, 3100, 3200, 4317, 4318, 8080, 3001) | l'overlay de production, qui a fermé `reverb` mais **pas ceux-là** | Latent. Le jour où quelqu'un lance cette pile « pour voir » sur le serveur, 9 ports s'ouvrent, dont Grafana avec `axion_grafana_dev` par défaut et GlitchTip avec `postgres://axion:axion_dev_only@postgres:5432/glitchtip` en dur. Exactement le piège fermé le 19/08 pour `postgres`/`redis`. → F40-008 |
| **8** | `infra/scripts/setup-hetzner-cpx22.sh` et `infra/runbooks/05-rotate-secrets.md` supposent `ufw` | le serveur : `ufw: command not found` | Un runbook joué le jour d'un incident échouerait à sa première commande. → F40-009 |
| **9** | `docker-compose.yml:17` : `POSTGRES_PASSWORD: axion_dev_only`, sous `environment:` | — c'est l'inverse du piège : le fichier **gagne**. Le conteneur porte bien `axion_dev_only`, et `DB_PASSWORD` de l'API aussi. | `environment:` l'emporte sur `env_file:` : **le `.env` du serveur ne PEUT PAS corriger ce mot de passe.** Il est en clair dans un dépôt public. → F40-007 |

**Réponse courte à la question posée** : oui, le patron se répète — **cinq fois** (lignes 1, 2, 3, 4, 7 du tableau), et il ne se limite pas à `docker-compose` : il frappe aussi le `.env` (une clé manquante rend sept clés présentes inertes) et les workflows (un correctif écrit pour la préproduction et jamais rétroporté).

---

## 3. Piège 19 — la garde `verifier-ports-publies.sh` passée au crible

Les deux corrections du 19/08 **tiennent** (script relu sur le serveur, 0 octet `0x0d`) :
- ligne 43 : `AUTORISES="${2-80 443}"` — **sans** les deux-points ✅
- lignes 111-115 : le témoin de mesure exige « une publication de n'importe quelle sorte », **plus** « au moins un port public » ✅

Quatre exécutions jouées en lecture seule sur le serveur (`10_garde-ports-temoins.txt`) :

| Cas | Attendu | Obtenu |
|---|---|---|
| `axion-crm-pro` (défaut `80 443`) | vert | `OK : la pile ne publie que 80 443` — **exit 0** ✅ |
| **Témoin négatif** `axion-crm-pro "80"` | rouge sur 443 | `ÉCHEC : ports publiés sur internet… : 443` — **exit 1** ✅ **elle sait rougir** |
| `axion-crm-staging ""` (préprod) | vert | `OK : la pile ne publie RIEN sur internet` — **exit 0** ✅ le correctif `${2-…}` est prouvé |
| **Témoin négatif** projet inexistant | rouge, pas vert | `ERREUR : aucun conteneur… la mesure n'a rien vu` — **exit 2** ✅ |

**La garde est irréprochable. Son branchement ne l'est pas** : absente du déploiement de production, non bloquante là où elle est appelée. → F40-004.

---

## 4. A-004 approfondi — le quota Let's Encrypt

- Le Caddy **de production** détient 4 certificats ACME : `app.`, `api.`, `staging.`, `staging-api.axion-crm-pro.com`. Renouvellement le plus proche : fenêtre ARI ouvrant le **2026-09-13**, expiration `api.` le **2026-10-14**. **Aucune erreur `rate limit` ni `too many` dans ses journaux.**
- Le Caddy **local** monte **le même `infra/caddy/Caddyfile`** (`docker-compose.yml:197`) : il demande donc, sur le **même compte ACME** `williamsjullin@gmail.com`, les 4 mêmes noms de production. Les validations échouent (le DNS ne pointe pas sur le poste).
- **Quantification honnête** : un échec de validation **ne consomme PAS** le quota « 50 certificats par domaine et par semaine » — aucun certificat n'est émis. Il consomme les limites « **5 validations échouées par compte, par nom d'hôte et par heure** » et « 300 nouvelles commandes par compte et par 3 h ».
- **Le renouvellement de la production est-il menacé ?** **Pas aujourd'hui, et pas de façon permanente** : la limite des validations échouées se réinitialise toutes les heures, et Caddy réessaie un renouvellement pendant 30 jours avant expiration. Le risque réel est **une fenêtre d'une heure** : si un `docker compose up` local tourne au moment exact où la production renouvelle, ce renouvellement-là est repoussé d'une heure. Non bloquant, mais gratuit à supprimer.
- **A-004 reste ouvert et sa sévérité S2 est confirmée** ; sa formulation « consomme leur quota Let's Encrypt » mérite d'être précisée en « consomme la limite horaire de validations échouées, pas le quota d'émission ». Correctif à coût nul : `docker-compose.local.yml` monte un Caddyfile réduit aux deux blocs `*.localhost`.

---

## 5. A-007 approfondi — et **correction de deux de ses chiffres**

| Grandeur | A-007 annonce | **Mesuré le 2026-08-19 à 12:42** | Preuve |
|---|---|---|---|
| Taille de `laravel.log` | 265 Mo | **267 083 835 octets = 254,7 Mio** ✅ confirmé | `12_`, `14_` |
| Croissance | +133 Mo/jour | **≈ 94 Mo/jour** — les 30 derniers Mio couvrent 484 minutes distinctes (04:39 → 12:42), soit 3,9 Mio/h | `14_` |
| Taux d'erreur | 56/minute | **5,8/minute** — 2 824 `production.ERROR` sur ces 484 minutes | `14_` |
| `LOG_LEVEL` | `debug` | ✅ `LOG_LEVEL=debug`, `LOG_CHANNEL=stack`, sur les 3 conteneurs | `22_` |
| Telescope actif sans tables | oui | ✅ confirmé, et **cause racine identifiée** : `TELESCOPE_ENABLED` absent du `.env` | `16_` |
| Rotation | aucune | ✅ un seul fichier, `laravel.log`, ouvert depuis le 2026-08-16 13:34:28, aucun `.1`, aucune entrée logrotate | `12_` |

🔴 **Le chiffre « 56 erreurs/minute » de A-007 est faux d'un facteur 10 ; le journal du 19/08 qui écrivait « 6/min » avait raison.** A-007 tient sur le fond — je propose de corriger ses deux chiffres plutôt que d'ouvrir un doublon.

### Les autres fichiers qui grossissent sans rotation

| Objet | Taille | État |
|---|---|---|
| `/var/log/journal` (systemd) | **3,9 Go** | `journald.conf` ne contient que `[Journal]` : aucun réglage. Le défaut `SystemMaxUse` = 10 % du système de fichiers **plafonné à 4 Go** — il est donc **à son plafond et borné**. Encombrant, pas dangereux. |
| `/var/log/axion-enrich/shard{0..6}.log` | **961 Mo** | 7 fichiers de ~100 Mo, **figés au 12/07 03:05**. Aucune rotation, aucun producteur vivant. Déchet pur. |
| `/var/backups/axion-crm` | **4,1 Go** | 6 dumps de 692 Mo. Rotation locale à 7 jours **fonctionnelle** (journal du 19/08 : « Rotation locale (>7 jours) »). Normal. |
| Journaux JSON des conteneurs | 4,1 Mo | **Non bornés** : pas de `/etc/docker/daemon.json`, donc `json-file` sans `max-size` ni `max-file`. Petits aujourd'hui parce que les conteneurs datent de ce matin ; l'`api` a produit 1,1 Mo en 2 h, soit ≈ 13 Mo/jour × 13 conteneurs. → F40-010 |
| `laravel.log` | 254,7 Mio | **le seul qui grossisse vraiment**, +94 Mo/jour |

### Trajectoire du disque — 75 G, 48 G utilisés, **25 G libres**

| Poste | Taille |
|---|---|
| `/var/lib/docker` | 26 G — dont **volume `postgres-data` 17 G**, images 11,4 G, cache de build 3,8 G (2,45 G récupérables) |
| `/var/log` | 5,1 G |
| `/var/backups` | 4,1 G |
| `/swapfile` | 4,1 G |
| `/usr`, `/opt`, `/root` | 1,5 + 1,2 + 0,7 G |

**Ce qui grossit** : `laravel.log` (+94 Mo/j), les dumps (0 net, rotation active), `postgres-data` (non mesurable en tendance sur une seule observation), les journaux JSON (non bornés, ≈ +150 Mo/j pour la pile entière si les conteneurs ne sont pas recréés).

**Trajectoire** : à cette allure, `laravel.log` seul consommerait les 25 G libres en **≈ 260 jours**. Avec les journaux JSON, **≈ 100 jours**. Il n'y a **pas d'urgence disque** — mais **3,8 G de cache de build et 961 Mo de `axion-enrich` sont récupérables immédiatement**, et le vrai sujet est que **rien ne surveille la trajectoire** : aucun service `axion*` sous systemd, aucune pile d'observabilité en marche, aucune alerte disque.

---

## 6. A-010 — ce qui manquait : le correctif, mesuré

**Je ne re-rapporte pas A-010** (déjà ouvert, S0, tranché par le chef de chantier). Mes 50 requêtes parallèles le confirment de façon indépendante — 1,075 s au total, latence max **0,882 s** contre **0,018 s** pour la même requête seule, soit un facteur **49** (`18_concurrence-50.txt`). J'apporte ici les trois réponses qui manquaient.

### 6.1 L'image contient-elle php-fpm ? **Oui — il est là et il n'est jamais lancé**

| Mesure dans `axion-crm-api` (production) | Résultat |
|---|---|
| `command -v php-fpm` | **`/usr/local/sbin/php-fpm`** — binaire de 20 991 352 octets |
| `php-fpm -v` | **`PHP 8.3.33 (fpm-fcgi)`** |
| Configuration | `php-fpm.conf` (5 356 o) + `php-fpm.d/{docker,www,zz-docker}.conf` — **complète** |
| `php -r 'echo PHP_SAPI;'` | `cli` — c'est bien le SAPI **CLI** qui sert les requêtes |
| Ports réellement en écoute (`netstat -tln`) | **`0.0.0.0:80` seulement**. Le `9000/tcp` visible dans `docker ps` est un `EXPOSE` hérité de l'image de base : **rien n'écoute dessus.** |

L'image de base est `php:8.3-fpm-alpine` : **php-fpm en est la commande native**, délibérément écrasée par `CMD ["php","-S","0.0.0.0:80","-t","public"]` (`Dockerfile.laravel:121`), un `CMD` recopié tel quel depuis la cible `dev` (l. 66). Preuve : `25_php-fpm-et-lecture-seule.txt`, `26_fastcgi-faisabilite.txt`.

### 6.2 Ce que coûte le passage de Caddy en `fastcgi`

Quatre gestes, tous petits, **et un piège chiffré** :

| Geste | Fichier | Coût |
|---|---|---|
| `CMD ["php-fpm"]` sur la cible `prod` | `Dockerfile.laravel:121` | 1 ligne |
| `listen = 0.0.0.0:9000` (le conteneur d'API doit être joignable depuis celui de Caddy, pas seulement en boucle locale) | un `php-fpm.d/zz-axion.ini` à ajouter | 1 fichier |
| 🔴 **`pm.max_children`** | idem | **le défaut de l'image est `pm = dynamic` / `pm.max_children = 5`.** Passer à php-fpm sans y toucher donnerait **cinq** requêtes simultanées, pas dix : le critère 17 resterait manqué de peu. Il faut **≥ 20**. C'est le point qu'on oublie. |
| `reverse_proxy api:9000 { transport fastcgi { root /var/www/html/public } }` dans les blocs `api.*` et `@api` de `app.*` | `infra/caddy/Caddyfile` | 4 blocs |
| Le healthcheck `curl -fsS http://localhost/up` ne vaudra plus rien (php-fpm ne parle pas HTTP) | `docker-compose.prod.yml:55` + `Dockerfile.laravel:116` | à remplacer par un test FastCGI ou `php-fpm -t` |

⚠️ **Piège 18 en embuscade** : le bloc Caddy change, or `caddy` **n'est pas dans la liste `api app horizon scheduler`** du déploiement. Le Caddyfile est un bind, donc son contenu suit — mais Caddy ne le relit pas seul. Il faudra un `up -d --force-recreate --no-deps caddy` explicite, exactement comme pour `extra_hosts`.

**Coût total : ~1 journée**, dont l'essentiel est le rejeu de la mesure de concurrence avant/après (la garde du §7 sert de contrôle d'atterrissage).

### 6.3 `PHP_CLI_SERVER_WORKERS` suffirait-il en repli immédiat ? **Oui, techniquement — et c'est déjà éprouvé**

- `pcntl_fork` est **disponible** dans le binaire de production (`pcntl` est compilé, `Dockerfile.laravel:48`). `php -S` sait donc forker.
- **Preuve empirique, trouvée par accident** : sur le poste, le conteneur jetable `a36-api` d'un autre agent tourne **déjà** avec `PHP_CLI_SERVER_WORKERS=10`, et ma garde le distingue correctement du cas nu (`27_garde-serveur-http-local.txt`). Le drapeau est donc applicable à cette image sans la reconstruire.
- **Mise en œuvre** : `PHP_CLI_SERVER_WORKERS=8` en `environment:` du service `api` dans `docker-compose.prod.yml`, puis `up -d --force-recreate --no-deps api` — **pas `restart`** (piège 8). Coût : **10 minutes**, aucune reconstruction d'image.
- **Ce que ce repli ne donne PAS**, et qu'il faut dire : aucune supervision des enfants (un worker qui meurt n'est pas remplacé), aucun `max_requests` (les fuites mémoire s'accumulent), aucune file d'attente. C'est une rustine qui lève le blocage de sérialisation le jour même, **pas** un serveur de production.

---

## 7. La garde qui manquait — écrite, et **vue rouge**

`infra/scripts/verifier-serveur-http.sh` (nouveau, **165 lignes, 0 octet `0x0d`** — méthode `od` validée sur un témoin CRLF fabriqué qui rend 2 et un témoin LF pur qui rend 0).

**Elle mesure les processus des conteneurs qui tournent, jamais un `Dockerfile`** (piège 19) : `tr "\0" " " < /proc/1/cmdline` d'abord, `ps` en repli seulement — car l'image Postgres n'a **pas** `ps` (mesuré : `exec: "ps": executable file not found`), et la première version prenait ce message d'erreur pour une ligne de commande.

### Quatre exécutions, quatre témoins

| Cas | Attendu | Obtenu |
|---|---|---|
| **PRODUCTION** `axion-crm-pro` | 🔴 rouge tout de suite, le défaut est là | `ÉCHEC : … : axion-crm-api` — **exit 1**. Un seul conteneur signalé sur sept ; `caddy`, `app`, `horizon`, `scheduler`, `postgres`, `redis` **ne le sont pas**. |
| **PRÉPRODUCTION** `axion-crm-staging` | rouge aussi | `ÉCHEC : … : axion-crm-staging-api` — **exit 1** |
| **Témoin négatif** — projet inexistant | exit 2, jamais 0 | `ERREUR : aucun conteneur … la mesure n'a rien vu` — **exit 2** |
| **Témoin positif** — pile réelle sans serveur HTTP (`bookforge` : postgres + redis) | exit 2, jamais 0 | `ÉCHEC (témoin positif) : aucun serveur HTTP trouvé dans la pile` — **exit 2**. Témoin **réel**, pas fabriqué. |

**Discrimination prouvée** : sur l'atelier local, la garde sépare trois cas dans la même exécution — `php -S` nu (🔴), `php -S` avec `PHP_CLI_SERVER_WORKERS=10` (⚠️ atténué), et `caddy run` / `php artisan horizon` / `postgres` (non signalés). Elle ne se contente pas de crier sur tout.

**Exécutée en lecture seule stricte sur la production** : `ssh root@… 'bash -s axion-crm-pro' < infra/scripts/verifier-serveur-http.sh` — **aucun fichier déposé sur le serveur**, aucun `docker cp`. Preuves : `27_garde-serveur-http-local.txt`, `28_garde-serveur-http-production.txt`.

**Il reste à la brancher** — et le §2.2 dit où : `deploy-direct-ssh.yml` n'appelle **aucune** garde de ce type, et `deploy-staging.yml` avale le code de retour de celle qu'il appelle. C'est le même défaut que F40-004, et le même correctif.

---

## 8. CONSTATS

### [F40-002] `MAIL_MAILER` n'est défini nulle part : la production a une configuration SMTP complète et n'envoie aucun courriel
- Sévérité      : **S0**
- Domaine       : backend / conformité
- Référence     : main e8924b8
- Emplacement   : `backend/config/mail.php:4` — `.env` de production (`/opt/axion-crm-pro/.env`) — `.env.example`
- Constat       : le `.env` de production porte sept clés `MAIL_*` valides et complètes (`MAIL_HOST=smtp.zeptomail.eu`, `MAIL_PORT=587`, `MAIL_ENCRYPTION=tls`, `MAIL_USERNAME=emailapikey`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS=crm@axion-ia.com`, `MAIL_FROM_NAME`), mais **`MAIL_MAILER` n'existe ni dans ce `.env`, ni dans `.env.example`, ni dans l'environnement du conteneur** — `config/mail.php:4` retombe donc sur son défaut `'log'`.
- Preuve        : `23_mail.txt`
  ```
  MAIL_HOST=smtp.zeptomail.eu   MAIL_PORT=587   MAIL_ENCRYPTION=tls
  MAIL_USERNAME=emailapikey     MAIL_PASSWORD=<masque>  MAIL_FROM_ADDRESS=crm@axion-ia.com
  === MAIL_MAILER dans le .env du serveur ===   (ABSENT du .env serveur)
  === MAIL_MAILER dans .env.example ===         (ABSENT de .env.example)
  === defaut du code ===  4:    'default' => env('MAIL_MAILER', 'log'),
  ```
- Témoin négatif: la même mesure trouve **huit** clés `MAIL_*` dans le conteneur et **zéro** `MAIL_MAILER` — le filtre `grep "^MAIL"` n'est donc pas aveugle. Et il trouve bien `MAIL_MAILER` dans `config/mail.php` : le nom cherché est le bon.
- Impact        : **aucun courriel ne quitte la production.** Réinitialisation de mot de passe, lien magique, vérification d'adresse, notifications RGPD, alertes : tous écrits dans `laravel.log` au lieu d'être envoyés. C'est la cause, jamais nommée, du constat de `definir-mot-de-passe-crm.sh` (« le propriétaire ne s'est jamais connecté »). En cas d'envoi, le corps du message — **jeton de réinitialisation compris** — atterrirait en clair dans un fichier de 254 Mo. Le journal courant (depuis le 2026-08-16) ne contient **aucun** en-tête MIME : aucun courriel n'a même été **tenté** en trois jours, ce qui confirme que la fonction est morte plutôt que bruyante.
- Reproduction  : `ssh root@46.62.248.239 "grep -c '^MAIL_MAILER' /opt/axion-crm-pro/.env"` → 0 ; `docker exec axion-crm-api grep -n MAIL_MAILER /var/www/html/config/mail.php`.
- Correctif     : ajouter `MAIL_MAILER=smtp` au `.env` de production **et** à `.env.example` (sinon le prochain serveur rejouera le défaut), puis `docker compose … up -d --force-recreate --no-deps api horizon scheduler` (**pas** `restart` — piège 8), puis envoyer un courriel de test. Coût : **15 minutes**. Ajouter une garde CI : toute clé `MAIL_*` dans `.env.example` sans `MAIL_MAILER` fait rougir.
- Statut        : ouvert

---

### [F40-003] `TELESCOPE_ENABLED` est écrit dans `.env.example`, `.local` et `.test` — et absent du `.env` de production : c'est la cause racine de A-007
- Sévérité      : **S1**
- Domaine       : backend / performance
- Référence     : main e8924b8
- Emplacement   : `.env.example` (clé présente) — `/opt/axion-crm-pro/.env` (clé absente) — `docker-compose.local.yml:75` et `docker-compose.test.yml` la posent à `"false"`
- Constat       : la comparaison clé à clé du `.env` de production (115 clés) et de `.env.example` (116 clés) montre **21 clés déclarées dans l'exemple et absentes du serveur**, dont `TELESCOPE_ENABLED` ; `laravel/telescope` étant une dépendance dure dont le défaut est `enabled = true` et dont les migrations ne sont pas publiées dans ce dépôt, la production enregistre à la terminaison de chaque requête et de chaque commande dans une table qui n'existe pas.
- Preuve        : `16_env-serveur-vs-exemple.txt` (liste des 21 clés), `05_scheduler-logs.txt` et `03_secret-runtime-laravel.txt` (l'exception `42P01 relation "telescope_entries" does not exist` apparaît à la terminaison de **chaque** commande jouée, y compris un simple `tinker --execute`), `22_derive-env-conteneurs.txt` (la clé est absente des 122 variables des 3 conteneurs).
- Témoin négatif: la même comparaison `comm` remonte aussi **20 clés dans le sens inverse** (présentes sur le serveur, absentes de l'exemple : `MAIL_*`, `SENTRY_LARAVEL_DSN`, `FRANCE_TRAVAIL_*`…) — le contrôle n'est donc pas biaisé vers un seul côté et sait produire les deux listes.
- Impact        : ≈ **94 Mo/jour** de trace d'exception, 5,8 erreurs/minute, et surtout **une exception silencieuse après chaque unité de travail utile** — le travail aboutit, le journal hurle, et la trace parle de `terminate()`, pas de la route : toute investigation part du mauvais côté. Les 20 autres clés absentes retombent sur les défauts du code, non vérifiés une à une.
- Reproduction  : `ssh root@46.62.248.239 "grep -c '^TELESCOPE_ENABLED' /opt/axion-crm-pro/.env"` → 0 ; puis la comparaison `comm` de `16_`.
- Correctif     : `TELESCOPE_ENABLED=false` dans le `.env` de production, recréation des 3 conteneurs (`up -d`, pas `restart`). Coût : **10 minutes**, et le journal cesse de croître. Puis garde CI : toute clé de `.env.example` absente du `.env` de production fait rougir le déploiement (le déploiement lit déjà le `.env` du serveur pour y injecter `INSEE_API_KEY`, l'accès est acquis).
- Statut        : ouvert. Ne remplace pas A-007 : **en explique la cause**.

---

### [F40-004] La garde `verifier-ports-publies.sh` n'est branchée que sur la préproduction, et son échec y est avalé
- Sévérité      : **S1**
- Domaine       : sécurité
- Référence     : main e8924b8
- Emplacement   : `.github/workflows/deploy-staging.yml:175` (seul appel) — `.github/workflows/deploy-direct-ssh.yml` (aucun appel)
- Constat       : le script écrit le 19/08 en réponse à l'exposition de Postgres **en production** est appelé une seule fois dans tout le dépôt, dans le workflow de **préproduction**, et son code de retour y est neutralisé par `|| echo "(contrôle des ports en échec — voir ci-dessus)"`, qui ne touche pas la variable `ok` évaluée juste après.
- Preuve        : recherche `verifier-ports-publies` sur tout le dépôt → un seul appel exécutable, `deploy-staging.yml:175`. Recherche de `port|publi|55432|56379` dans `deploy-direct-ssh.yml` → seul `_REPORTS/**` et `COMPOSE_FILE` remontent. Exécutions du script archivées dans `10_garde-ports-temoins.txt`.
- Témoin négatif: **la garde elle-même est saine et sait rougir** — jouée en lecture seule sur le serveur, elle rend `exit 1` sur `axion-crm-pro "80"` (« ports publiés sur internet… : 443 ») et `exit 2` sur un projet inexistant. Ses deux corrections du 19/08 tiennent : `${2-80 443}` sans deux-points (l. 43), témoin de mesure n'exigeant plus un port public (l. 111-115). Le défaut n'est **pas** dans le script.
- Impact        : la seule garde qui mesure **les conteneurs** au lieu du fichier — celle qui aurait attrapé la faille de 4,29 M de fiches — **ne peut faire échouer aucun déploiement, ni en production ni en préproduction**. Un futur `docker compose up -d` complet sur le serveur, ou un `--force-recreate` sur `postgres` avec un `docker-compose.yml` non surchargé, republierait 55432 sans que rien ne rougisse.
- Reproduction  : `grep -rn verifier-ports-publies .github/` ; puis lire `deploy-staging.yml:168-178`.
- Correctif     : (a) ajouter l'appel dans `deploy-direct-ssh.yml` après le bloc « santé des conteneurs », avec `bash infra/scripts/verifier-ports-publies.sh axion-crm-pro || exit 1` ; (b) dans `deploy-staging.yml`, remplacer `|| echo …` par `|| ok=0`. Coût : **30 minutes**, témoin négatif déjà disponible (cas n°2 ci-dessus).
- Statut        : ouvert

---

### [F40-005] Le correctif du piège 18 a été écrit pour la préproduction et jamais rétroporté en production
- Sévérité      : **S1**
- Domaine       : sécurité / déploiement
- Référence     : main e8924b8
- Emplacement   : `.github/workflows/deploy-staging.yml:140-143` vs `.github/workflows/deploy-direct-ssh.yml:200`
- Constat       : le déploiement de préproduction fait **deux** appels — `up -d --build --force-recreate --no-deps api app horizon scheduler` **puis** `up -d --no-deps postgres redis` — tandis que le déploiement de production s'arrête au premier ; aucun de ses pas ne peut donc appliquer une modification de `docker-compose*.yml` portant sur `postgres`, `redis` ou `reverb`.
- Preuve        : `grep -rn "no-deps" .github/workflows/` :
  ```
  deploy-direct-ssh.yml:200:  docker compose up -d --build --force-recreate --no-deps api app horizon scheduler
  deploy-staging.yml:140:     docker compose up -d --build --force-recreate --no-deps api app horizon scheduler
  deploy-staging.yml:143:     docker compose up -d --no-deps postgres redis
  ```
- Témoin négatif: la recherche remonte **trois** occurrences dans les workflows et **trois** dans `infra/` — elle n'est donc pas aveugle, et l'absence de la ligne côté production est une absence réelle, pas un défaut de filtre.
- Impact        : le défaut structurel décrit par le piège 18 **reste entier en production**. Aujourd'hui les huit réglages de l'overlay sont en place (§2.1) uniquement parce que quelqu'un a joué les `--force-recreate` **à la main** ce matin. Le prochain changement sur `postgres` ou `redis` ne s'appliquera pas plus que le précédent, et rien ne le dira (F40-004).
- Reproduction  : lire les deux workflows côte à côte.
- Correctif     : ajouter `docker compose up -d --no-deps postgres redis` après la ligne 200 de `deploy-direct-ssh.yml`. `up -d` sans `--force-recreate` ne recrée que si la configuration a réellement changé : aucune interruption dans le cas courant. Coût : **10 minutes**.
- Statut        : ouvert

---

### [F40-006] La production exécute une migration qui n'est pas dans `main` : le contexte de build est le répertoire de travail du serveur
- Sévérité      : **S1**
- Domaine       : backend / déploiement
- Référence     : main e8924b8
- Emplacement   : `/opt/axion-crm-pro/backend/database/migrations/2026_05_17_195529_create_failed_jobs_table.php` (non suivi par git) — `Dockerfile.laravel:73` (`COPY backend/ /var/www/html/`)
- Constat       : le dépôt suit **58** migrations ; le serveur en porte **59**, la 59ᵉ n'étant pas suivie par git ; cette migration est **entrée dans l'image de production** et est **appliquée en base**.
- Preuve        : `19_migrations-cron-sauvegardes.txt`, `21_migration-et-var.txt`
  - poste : `git ls-files backend/database/migrations/ | grep -c '\.php$'` → **58** ; `| grep failed_jobs` → **(ABSENT du depot)**
  - serveur : `git status --porcelain` → `?? backend/database/migrations/2026_05_17_195529_create_failed_jobs_table.php`
  - conteneur : `docker exec axion-crm-api ls …/migrations | grep -c .` → **59**, et le fichier y est
  - base : `select to_regclass('public.failed_jobs')` → **`failed_jobs`** ; `select migration, batch from migrations where migration like '%failed_jobs%'` → **batch 7**
- Témoin négatif: `git ls-files` sait trouver les migrations (58 résultats) et `to_regclass` sait rendre `NULL` — je l'ai éprouvé sur la même requête, qui rend bien la valeur quand la table existe. La mesure n'est donc pas un faux positif de filtre.
- Impact        : le déploiement se targue de servir « **exactement** le commit qui vient de passer la CI » (`git reset --hard "$EXPECTED_SHA"` + comparaison de SHA, l. 155-161). C'est **faux** : `git reset --hard` ne supprime pas les fichiers non suivis, et le build Docker a pour contexte `/opt/axion-crm-pro`. Quatre autres objets non suivis sont dans ce contexte (`enrich-runner.sh`, `find-websites-runner.sh`, `backend/database/database/`, `frontend/public/tiles/`). Toute reconstruction du serveur à partir de `main` produirait une base **différente** de la production — ce qui vise directement le runbook `04-restore-dr.md` (« git clone + docker compose up -d »).
- Reproduction  : les quatre commandes ci-dessus.
- Correctif     : (a) décider du sort de cette migration — la commiter si elle est voulue, la supprimer sinon ; (b) ajouter `git clean -fdx -e .env -e backend/storage` (ou au minimum `git status --porcelain` bloquant) avant le build dans `deploy-direct-ssh.yml`, avec un témoin. Coût : **1 h** avec le rejeu.
- Statut        : ouvert

---

### [F40-007] Le mot de passe Postgres de production est celui, en clair, du dépôt public — et `environment:` empêche le `.env` du serveur de le corriger
- Sévérité      : **S1**
- Domaine       : sécurité
- Référence     : main e8924b8
- Emplacement   : `docker-compose.yml:17` — conteneurs `axion-crm-postgres` et `axion-crm-api`
- Constat       : `POSTGRES_PASSWORD` du conteneur Postgres de production et `DB_PASSWORD` du conteneur d'API valent tous deux **`axion_dev_only`**, la chaîne écrite en clair ligne 17 d'un `docker-compose.yml` de dépôt public ; posée sous `environment:`, elle **l'emporte sur `env_file:`**, donc aucune valeur du `.env` du serveur ne peut la remplacer.
- Preuve        : `15_images-et-env-postgres.txt`, `16_env-serveur-vs-exemple.txt` — comparaison faite dans le conteneur, sans jamais afficher de valeur : `POSTGRES_PASSWORD == "axion_dev_only"` → **OUI**, `DB_PASSWORD == "axion_dev_only"` → **OUI**. Le rôle porteur est `axion`, `rolsuper = t`, `rolbypassrls = t`.
- Témoin négatif: la même commande `awk` rend « non (longueur N) » quand la comparaison échoue — je l'ai éprouvée sur `AUDIT_HASH_CHAIN_SECRET`, qui rend bien une longueur (64) et non « OUI ». Le test sait dire non.
- Impact        : le port n'étant plus publié, l'exploitation exige d'abord un pied dans le réseau Docker (SSRF, RCE dans l'API, conteneur compromis) — mais **à partir de là, le mot de passe n'est pas un obstacle** : il est public depuis toujours, et le compte est `SUPERUSER` avec `BYPASSRLS` sur 4,29 M de fiches personnelles. **Ce n'est pas la rotation refusée (D-005) que je propose** : c'est le fait que **le secret ne soit pas paramétrable du tout**. Tant que la ligne 17 est en dur, la question « faut-il tourner ce secret ? » ne peut même pas se poser : il n'existe aucun endroit où écrire la nouvelle valeur.
- Reproduction  : `docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' axion-crm-postgres | grep '^POSTGRES_PASSWORD='`.
- Correctif     : remplacer `POSTGRES_PASSWORD: axion_dev_only` par `POSTGRES_PASSWORD: ${POSTGRES_PASSWORD:-axion_dev_only}` (la forme déjà retenue pour `APP_URL` ligne 80, avec son commentaire). **Ce seul changement ne tourne rien** et n'a aucun effet sur la production tant que la clé n'est pas posée dans le `.env` du serveur : il rend simplement la rotation **possible** le jour où elle sera décidée. ⚠️ Le changer effectivement exigerait `ALTER ROLE` + recréation de `postgres` (piège 18 : le déploiement n'y touche pas) — **hors périmètre, décision du dirigeant.** Coût du seul rendu-paramétrable : **10 minutes**.
- Statut        : ouvert

---

### [F40-008] `docker-compose.observability.yml` publierait 9 ports sur `0.0.0.0` — et l'overlay de production ne les ferme pas
- Sévérité      : **S2**
- Domaine       : sécurité
- Référence     : main e8924b8
- Emplacement   : `docker-compose.observability.yml` (ports 9090, 9093, 3000, 3100, 3200, 4317, 4318, 8080, 3001) — `docker-compose.prod.yml` (aucun `!override` correspondant)
- Constat       : l'overlay de production ferme explicitement les publications de `postgres`, `redis` et `reverb` — y compris celle de `reverb`, « pour fermer le piège maintenant, tant qu'il ne coûte rien » — mais les huit services d'observabilité, qui publient neuf ports sur toutes les interfaces, n'ont **aucun** `!override`, et deux d'entre eux portent des secrets par défaut en dur (`GF_SECURITY_ADMIN_PASSWORD` → `axion_grafana_dev`, `DATABASE_URL: postgres://axion:axion_dev_only@postgres:5432/glitchtip`).
- Preuve        : lecture intégrale de `docker-compose.observability.yml` (106 l.) ; `01_docker-ps.txt` : **aucun** des 8 services en marche ; `09_pare-feu-et-ports.txt` : seuls 22/80/443 écoutent publiquement.
- Témoin négatif: `verifier-ports-publies.sh`, jouée sur la pile en marche, rend `OK : la pile ne publie que 80 443` — le contrôle voit donc bien l'état actuel, et le risque décrit est **latent, pas actif**. Et la même garde a prouvé (cas n°2) qu'elle rougirait si un port de plus apparaissait.
- Impact        : un `docker compose -f docker-compose.yml -f docker-compose.observability.yml up -d` lancé sur le serveur — geste explicitement proposé en tête du fichier — ouvrirait neuf ports sur internet, dont Grafana avec un mot de passe connu et GlitchTip avec les identifiants Postgres du dépôt. C'est le scénario exact du 19/08. Sans `ufw` (F40-009) et Docker insérant ses règles avant tout pare-feu, rien ne l'arrêterait.
- Reproduction  : lire le fichier ; comparer avec les trois `ports: !override []` de `docker-compose.prod.yml`.
- Correctif     : soit lier les neuf publications à `127.0.0.1:` dans le fichier lui-même, soit poser les `!override` correspondants dans `docker-compose.prod.yml`. Coût : **20 minutes**. Le premier est meilleur : il protège aussi le poste de développement.
- Statut        : ouvert

---

### [F40-009] `ufw` n'est pas installé sur le serveur de production, alors que le script d'installation et un runbook le supposent présent
- Sévérité      : **S2**
- Domaine       : sécurité
- Référence     : main e8924b8
- Emplacement   : `infra/scripts/setup-hetzner-cpx22.sh`, `infra/runbooks/05-rotate-secrets.md`, et les commentaires de `docker-compose.prod.yml:120-127` — serveur `46.62.248.239`
- Constat       : `ufw` renvoie `command not found` sur le serveur ; le seul filtrage est celui d'`iptables` posé par Docker plus le pare-feu de l'hébergeur, et `fail2ban` n'a qu'une prison (`sshd`). `/etc/logrotate.d/ufw` existe pourtant — vestige d'un paquet retiré.
- Preuve        : `09_pare-feu-et-ports.txt` — `bash: line 1: ufw: command not found` ; `fail2ban-client status` → `Number of jail: 1`, `Jail list: sshd` ; `iptables -S DOCKER` → 4 ACCEPT + 3 DROP, cohérents.
- Témoin négatif: la même session lit `fail2ban-client status` sans difficulté et rend `ss -tlnp` complet — l'accès root fonctionne, l'absence de `ufw` n'est pas un défaut de droits.
- Impact        : les commentaires de `docker-compose.prod.yml` construisent tout leur raisonnement sur « `ufw status` annonce 22/80/443 et la chaîne DOCKER dit autre chose » — **ce raisonnement décrit une machine qui n'existe pas**. Le jour d'un incident, un opérateur suivant `05-rotate-secrets.md` bute à sa première commande. Et l'absence de prison HTTP dans `fail2ban` laisse `/api/v1/auth/login` sans aucun freinage réseau.
- Reproduction  : `ssh root@46.62.248.239 'ufw status verbose'`.
- Correctif     : trancher — soit installer `ufw` et le configurer (en sachant qu'il **ne protège pas** des publications Docker, c'est le sens du script `verifier-ports-publies.sh`), soit **corriger les documents** pour qu'ils décrivent la machine réelle. La seconde option coûte **30 minutes** et vaut mieux qu'un pare-feu qui rassure sans filtrer. Ajouter une prison `fail2ban` sur les journaux de Caddy est un chantier distinct.
- Statut        : ouvert

---

### [F40-010] Aucun `/etc/docker/daemon.json` : les journaux des conteneurs sont sans limite de taille
- Sévérité      : **S2**
- Domaine       : disponibilité
- Référence     : main e8924b8
- Emplacement   : serveur `46.62.248.239`, `/etc/docker/daemon.json` (inexistant)
- Constat       : le démon Docker tourne avec le pilote `json-file` par défaut sans `max-size` ni `max-file` ; aucun fichier de configuration ne les pose, et aucune entrée `logrotate` ne couvre `/var/lib/docker/containers`.
- Preuve        : `12_logs-et-rotation.txt` — `cat /etc/docker/daemon.json` → `No such file or directory` ; `ls /etc/logrotate.d/` → 13 entrées, **aucune** pour Docker ; `/var/lib/docker/containers` = 4,1 Mo, dont 1,1 Mo pour le seul conteneur d'API **créé il y a 2 h** (≈ 13 Mo/jour, × 13 conteneurs ≈ 170 Mo/jour).
- Témoin négatif: `ls /etc/logrotate.d/` rend bien 13 entrées (dont `fail2ban`, `rsyslog`, `apt`) — le répertoire est lisible et la liste réelle ; l'absence d'entrée Docker est une absence, pas un échec de lecture.
- Impact        : le chiffre est petit **aujourd'hui uniquement parce que tous les conteneurs ont été recréés ce matin**. Sur les 42 jours de vie de la machine et à ce rythme, ces journaux auraient représenté ≈ 7 Go. Ils grossissent d'autant plus vite que `LOG_LEVEL=debug` et que Telescope hurle (F40-003) : ce sont les **mêmes** traces d'exception, stockées **deux fois** — dans `laravel.log` et dans le journal JSON du conteneur.
- Reproduction  : `ssh root@46.62.248.239 'cat /etc/docker/daemon.json; du -sh /var/lib/docker/containers'`.
- Correctif     : `/etc/docker/daemon.json` avec `{"log-driver":"json-file","log-opts":{"max-size":"50m","max-file":"3"}}`. ⚠️ Prend effet **à la recréation** des conteneurs, pas au redémarrage du démon — même piège que le 18. Coût : **20 minutes**, plus une recréation. Traiter F40-003 d'abord divise le volume par ~10.
- Statut        : ouvert

---

### [F40-011] 961 Mo de journaux morts sans rotation, et 3,8 Go de cache de build : 4,8 Go récupérables sans risque
- Sévérité      : **S3**
- Domaine       : disponibilité
- Référence     : main e8924b8
- Emplacement   : serveur — `/var/log/axion-enrich/`, cache de build Docker
- Constat       : `/var/log/axion-enrich/` contient sept fichiers `shard{0..6}.log` de ~100 Mo chacun (**961 Mo**), tous figés au **2026-07-12 03:05**, sans rotation ni producteur vivant ; le cache de build Docker pèse 3,786 Go dont **2,45 Go déclarés récupérables**, et 826,9 Mo d'images sont également récupérables.
- Preuve        : `12_logs-et-rotation.txt`, `13_disque-detail.txt`, `06_disque.txt` — `du -ah /var/log | sort -rh` ; `docker system df` → `Build Cache 119 / 3.786GB / 2.45GB reclaimable`.
- Témoin négatif: la même commande `du` remonte correctement les postes vivants (`laravel.log` 254,7 Mio, `/var/log/journal` 3,9 Go) — elle n'est pas aveugle aux gros fichiers, et la datation au 12/07 est lisible dans `ls -la`.
- Impact        : mineur aujourd'hui (25 G libres). Mais **`/var/log/journal` est à son plafond de 4 Go** (défaut `journald` : 10 % du système de fichiers, plafonné à 4 Go) — la marge apparente est donc moins grande qu'elle n'en a l'air, et **rien ne surveille la trajectoire** : aucune unité systemd `axion*`, aucune pile d'observabilité en marche, aucune alerte disque. Le runbook `02-disk-full.md` existe ; rien ne le déclenche.
- Reproduction  : `ssh root@46.62.248.239 'du -sh /var/log/axion-enrich; docker system df'`.
- Correctif     : `docker builder prune -f` (2,45 Go) et archivage puis suppression de `/var/log/axion-enrich` (961 Mo) — **gestes du dirigeant, pas de l'audit** (écriture). Puis une alerte disque, quelle qu'elle soit. Coût : **10 minutes** + le choix de l'alerte.
- Statut        : ouvert

---

### [F40-012] Deux artefacts de construction morts : `Dockerfile.worker` et la cible `prod-nginx`
- Sévérité      : **S3**
- Domaine       : finition
- Référence     : main e8924b8
- Emplacement   : `Dockerfile.worker` (108 l.), `Dockerfile.frontend:68-77` (cible `prod-nginx`), `infra/nginx/frontend.conf`
- Constat       : `Dockerfile.worker` n'est référencé par **aucun** des six fichiers de composition — seulement par `deploy-staging.yml:25` (qui construit et pousse l'image) et `security.yml:74` (qui la scanne) ; `infra/nginx/frontend.conf` n'est utilisé que par la cible `prod-nginx` de `Dockerfile.frontend`, conservée « pour rollback » et sélectionnée nulle part.
- Preuve        : `grep -rn "Dockerfile.worker" --include="*.yml"` → 6 résultats, **0** dans un `docker-compose*.yml` ; `grep -rn nginx --include="*.yml" --include="Dockerfile*"` hors `vendor/` → seulement `Dockerfile.frontend:68,70`. Le dossier commun rappelle par ailleurs que 32 des 57 alertes npm viennent de `workers/`, « déployé par aucun compose ».
- Témoin négatif: la même recherche trouve bien `Dockerfile.laravel`, `.frontend` et `.postgres` dans les compose — elle sait donc détecter une référence quand il y en a une.
- Impact        : une image Playwright est construite, poussée et scannée à chaque déploiement de préproduction pour un service que rien ne démarre : du temps de CI et une surface d'alerte de sécurité entretenues pour rien. `docker-compose.test.yml` pose de surcroît `profiles: ["disabled-in-tests"]` sur trois services `worker-*` **qui n'existent plus** dans `docker-compose.yml` — un `profiles:` sur un service inexistant est un no-op parfaitement silencieux.
- Reproduction  : les deux `grep` ci-dessus.
- Correctif     : décider — retirer, ou documenter dans le fichier que l'artefact n'est pas déployé et pourquoi il est conservé. Coût : **1 h**. Aucun effet sur la production.
- Statut        : ouvert

---

### [F40-013] Le système de fichiers du conteneur de production n'est PAS en lecture seule — la protection vient des droits, et la racine du webroot est en 1777
- Sévérité      : **S2**
- Domaine       : sécurité
- Référence     : main e8924b8
- Emplacement   : conteneur `axion-crm-api` en production — `Dockerfile.laravel:114` (`USER www-data`), `:98` (`chown -R www-data:www-data storage bootstrap/cache`)
- Constat       : l'écriture dans `/var/www/html/public` est bien refusée, mais **`HostConfig.ReadonlyRootfs = false`** : la protection ne vient pas d'un montage en lecture seule, elle vient de ce que le processus tourne en `www-data` (uid 82) alors que `public/` appartient à `root:root` en `drwxr-xr-x`. Et **la racine `/var/www/html` est en `drwxrwxrwt` (mode 1777)** : `www-data` **peut** y créer des fichiers — vérifié, `RACINE_ECRIVABLE`.
- Preuve        : `25_php-fpm-et-lecture-seule.txt`
  ```
  ReadonlyRootfs = false | User = www-data
  touch /var/www/html/public/a40-test  → Permission denied → ECRITURE_REFUSEE
  touch /var/www/html/a40-test         → RACINE_ECRIVABLE
  touch /var/www/html/storage/logs/…   → STORAGE_ECRIVABLE
  uid=82(www-data)  drwxrwxrwt … /var/www/html   drwxr-xr-x root root … /var/www/html/public
  ```
- Témoin négatif: la même commande `touch` **réussit** sur `/var/www/html/storage/logs` et sur `/var/www/html` — elle n'est donc pas systématiquement refusée, et le « Permission denied » sur `public/` est un vrai refus, pas un artefact de `docker exec`.
- Impact        : le bon point est **réel mais plus étroit qu'il n'y paraît**. Ce qui protège, ce sont les droits POSIX sur `public/` — le répertoire **effectivement servi** par `php -S … -t public` : une écriture arbitraire ne peut pas y déposer de fichier exécutable par le web. En revanche : (a) `ReadonlyRootfs=false` signifie que **tout le reste du système de fichiers du conteneur est modifiable** par `www-data` là où les droits le permettent ; (b) `/var/www/html` en **1777** est un mode inhabituellement large pour la racine d'une application — il vient du `chmod` implicite de la couche d'image, pas d'une décision. Un fichier PHP déposé là n'est pas servi aujourd'hui, mais il le deviendrait au premier changement de `-t public` ou de `root` Caddy.
- Reproduction  : les cinq commandes ci-dessus, toutes en lecture seule sauf des `touch`/`rm` sur des fichiers créés et détruits dans la même commande.
- Correctif     : (a) poser `read_only: true` sur le service `api` dans `docker-compose.prod.yml`, avec `tmpfs: [/tmp]` — `storage` est déjà un volume nommé, donc l'application continue d'écrire là où elle doit ; (b) `chmod 755 /var/www/html` dans la cible `prod` du Dockerfile. Coût : **1 h**, dont le rejeu d'un démarrage complet (l'entrypoint écrit `bootstrap/cache` : il faudra le déclarer en `tmpfs` ou en volume). ⚠️ `read_only` ne s'applique qu'à la **création** du conteneur — piège 18 : `api` est bien recréé par le déploiement, donc celui-ci passera.
- Statut        : ouvert

---

## 9. Ce que je n'ai PAS pu vérifier, et pourquoi

1. **La valeur de `AUDIT_HASH_CHAIN_SECRET` en préproduction.** Mesurée en production seulement. `docker exec axion-crm-staging-api` était disponible ; je m'en suis tenu au périmètre nommé. **10 minutes** pour la lever, même commande.
2. **Que le secret de production soit *le même* qu'à l'origine de la chaîne.** J'ai mesuré sa longueur (64) et qu'il n'est ni vide ni le défaut. Je n'ai **pas** vérifié qu'il n'a pas changé depuis la première écriture du journal d'audit : si quelqu'un l'avait remplacé, toutes les lignes antérieures seraient invérifiables — et **rien ne le signalerait**. Cela demande de rejouer la vérification de la chaîne sur la base de production, c'est-à-dire une lecture massive : hors périmètre d'un mandat en lecture seule prudente.
3. **La trajectoire réelle de `postgres-data` (17 Go).** Une seule observation ne fait pas une tendance. Il faudrait deux mesures espacées, ou l'historique des dumps (692 Mo compressés, stable sur 4 jours — ce qui **suggère** une croissance faible, sans la prouver).
4. **Le comportement de la garde `config-prod` de la CI.** Lue (`ci.yml:78-115`), non rejouée : elle exige un runner GitHub. Sa logique paraît correcte (extraction fail-closed, témoin positif 80/443), mais **je ne l'ai pas vue rougir** — et la doctrine dit qu'une garde non vue rougir ne vaut rien.
5. **`infra/terraform/`.** Aucun `.tfstate` sur le serveur : l'infrastructure a été montée à la main et le code Terraform ne décrit probablement plus la réalité. Vérifier exigerait un `terraform plan` avec les identifiants Hetzner — écriture potentielle, refusé.
6. **`infra/loadtest/k6-api.js`.** Non joué : un test de charge contre la production est une écriture de fait, et sur un serveur `php -S` mono-processus (F40-001) il **couperait le service**. C'est précisément pour cela que F40-001 a été mesuré par 50 requêtes sur `/up` et pas par k6.
7. **Les 20 clés de `.env.example` absentes du `.env` de production, autres que `TELESCOPE_ENABLED`.** Listées, pas évaluées une par une : chacune demande de lire le défaut du code correspondant. **2 h** de travail.
8. **Le contenu des quatre autres objets non suivis par git sur le serveur** (`enrich-runner.sh`, `find-websites-runner.sh`, `backend/database/database/`, `frontend/public/tiles/`) : signalés, non lus. Ils sont dans le contexte de build au même titre que la migration de F40-006.
9. **`docker-compose.test.yml` rejoué.** Lu seulement ; la CI est hors périmètre de ce mandat.
10. **Que le `!override` de `volumes` tienne encore après un vrai déploiement.** Vérifié sur l'état actuel (`Binds` de l'API = `api-storage` seul, pas de bind `/opt/axion-crm-pro/backend`) — mais je n'ai déclenché aucun déploiement, ce qui serait une écriture.

---

## 10. Effets sur les constats déjà ouverts

| Constat | Effet de mes mesures |
|---|---|
| **A-010** (S0, chef de chantier) | ✅ **confirmé indépendamment** (50 requêtes parallèles, facteur 49 sur la latence max) et **complété** : php-fpm est dans l'image (§6.1), le correctif est chiffré avec son piège `pm.max_children = 5` (§6.2), le repli `PHP_CLI_SERVER_WORKERS` est prouvé applicable (§6.3), et **la garde manquante est écrite et vue rouge sur la production** (§7) |
| **A-009** (S2) | 🔴 **à requalifier S0 ou à fusionner dans A-010** — la production fait comme le local, et la préproduction aussi |
| **A-007** (S1) | ✅ **confirmé** sur le fond, ⚠️ **deux chiffres à corriger** : 5,8 erreurs/min (pas 56), ≈94 Mo/jour (pas 133). Cause racine identifiée : F40-003 |
| **A-004** (S2) | ✅ **confirmé**, portée précisée : consomme la limite horaire de **validations échouées**, pas le quota d'émission de 50/semaine. Renouvellement de la production **non menacé**, fenêtre de retard d'une heure au pire |
| **B16-001** (S0) | 🔴 **ne s'applique PAS à la production** — le secret y vaut 64 caractères. Le constat reste vrai **en local**. Sa sévérité S0 doit être requalifiée en fonction de la portée réelle |
| Faille Postgres/Redis exposés | ✅ **fermée dans le conteneur**, pas seulement dans le fichier (`PortBindings = map[]` pour les deux) — vérifié depuis le serveur |
| Défaut du 2026-08-16 (`APP_URL` divergent) | ✅ **corrigé** : les 3 conteneurs applicatifs portent 122 variables **strictement identiques** |
| Piège 8 (`restart` ne relit pas `env_file`) | ✅ **respecté** : `.env` modifié à 10:10:15, conteneurs créés à 10:10:20 |
| Constat de l'agent 17 (`healthcheck: disable` sur `scheduler`) | ✅ **confirmé** (`Test = [NONE]`), mais **le planificateur tourne** et exécute réellement des tâches |
