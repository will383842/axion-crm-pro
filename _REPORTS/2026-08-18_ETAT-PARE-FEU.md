# État du pare-feu — Axion CRM Pro (production)

> **Date :** 2026-08-18
> **Auteur :** agent (ligne 14 du §3 du plan de préalables — volet **F12**)
> **Statut :** à valider par Will
> **Objet :** consigner l'état du pare-feu du serveur de production, et poser au
> runbook la procédure de vérification (`ufw status`, `fail2ban-client status`).

---

## 🔴 AVERTISSEMENT — CE DOCUMENT NE CONSTATE RIEN SUR LE SERVEUR

**L'état réel du pare-feu de production n'a PAS été mesuré, faute d'accès.**

L'agent qui a rédigé ce document **n'a jamais ouvert de session sur le serveur de
production**. `ssh` vers la production est refusé par le classificateur de
l'autopilote, et cet accès est de toute façon une habilitation qui appartient à
Will (cf. `_REPORTS/2026-08-18_ARBITRAGES-PREALABLES-SECTION-4.md`, décision 1).

Par conséquent :

- **aucune** ligne de ce document ne dit ce que le serveur FAIT ;
- toutes les lignes des §1 et §2 disent ce que **le dépôt PRÉTEND** qu'il devrait
  faire, et ce que la composition de production **contredit** ;
- le §4 est la procédure à jouer **par Will, sur le serveur**, pour transformer
  ces prétentions en constat.

Si vous lisez ce document dans six mois : il n'est **pas** un état des lieux. Il
le deviendra le jour où quelqu'un aura collé la sortie du §4 dans le §5, qui est
aujourd'hui **VIDE**.

---

## 1. Ce que le dépôt PRÉTEND

### 1.1 UFW — `infra/scripts/setup-hetzner-cpx22.sh`

Chemin absolu :
`C:\Users\willi\Documents\Projets\crmpro-wt-etape0\infra\scripts\setup-hetzner-cpx22.sh`

Verbatim, lignes 55-64 :

```bash
# --- 3. Pare-feu UFW --------------------------------------------------------
log "[3/8] Configuration UFW…"
ufw --force reset > /dev/null
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp comment "SSH"
ufw allow 80/tcp comment "HTTP"
ufw allow 443/tcp comment "HTTPS"
ufw --force enable
ufw status verbose | tee -a "$LOG"
```

Donc, si ce script a été joué sur le serveur courant : politique par défaut
`deny incoming`, trois ports ouverts (22, 80, 443), sortie non restreinte.

⚠️ **NON VÉRIFIÉ** : rien ne prouve que ce script a été joué sur le serveur de
production actuel, ni qu'il l'a été **dans son intégralité** (il est `set -euo
pipefail` : une erreur avant l'étape 3 aurait laissé UFW non configuré). Le
script écrit la sortie de `ufw status verbose` dans `/var/log/axion-setup.log` :
c'est là qu'est la preuve de la pose initiale, si elle existe.

### 1.2 fail2ban — même script, lignes 43 et 66-73

Verbatim :

```bash
apt-get -yq install ca-certificates curl git nano htop ufw fail2ban jq unzip wget
...
# --- 4. Fail2ban + durcissement SSH -----------------------------------------
log "[4/8] Fail2ban + hardening SSH…"
systemctl enable --now fail2ban

# Désactive login root par password (clé SSH only). PermitRootLogin sans-password = OK.
sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config
systemctl restart ssh
```

**Oui, `fail2ban` figure dans le script.** Mais :

- 🔴 **aucune jail n'est configurée** — pas de `/etc/fail2ban/jail.local`, pas de
  `jail.d/*.conf` dans le dépôt (recherche : aucun fichier `jail*` sous
  `infra/`). Le service est installé et démarré avec sa configuration
  **distribution par défaut**, rien de plus. Ce qui est réellement banni dépend
  donc entièrement de ce qu'Ubuntu 24.04 active par défaut — **NON VÉRIFIÉ**.
- 🔴 **le dépôt promet ailleurs beaucoup plus** :
  `spec/02_architecture_infra.md:182` — *« Fail2ban : jails pour `sshd`,
  `caddy-401`, `caddy-403`, `caddy-429`. Ban 1h après 5 echecs en 10 min. »* —
  et `spec/15_auth_multitenant_rbac.md:448` — *« IP-based : 50 failed logins /h
  → fail2ban ban 1h (Edge layer) »*. **Aucune de ces jails n'existe dans le
  dépôt.** Les trois jails Caddy en particulier supposeraient un filtre custom et
  un accès aux logs Caddy : ni l'un ni l'autre n'est versionné.

**Trace indirecte que fail2ban a bien tourné en production** : le commit
`0923585` du **2026-07-06**, *« fix(distributed): retire ssh-keyscan (déclenchait
fail2ban) »*. Un `ssh-keyscan` massif depuis des runners GitHub s'est fait bannir
— donc au moins la jail SSH était **active et efficace** à cette date. C'est le
seul témoin **positif** que ce travail a trouvé, et il est indirect.

### 1.3 Pare-feu Hetzner Cloud — `infra/terraform/main.tf`

Chemin absolu :
`C:\Users\willi\Documents\Projets\crmpro-wt-etape0\infra\terraform\main.tf`,
lignes 44-71 : une ressource `hcloud_firewall "axion_fw"` qui n'ouvre en entrée
que **22** (restreint à `var.ssh_allowed_cidrs`), **80**, **443** et **ICMP**.

🔴 **Ce pare-feu ne s'applique très probablement PAS au serveur de production
actuel.** Le fichier décrit une cible à **7 serveurs** (`edge`, `app`, `data`,
`worker_1`, `worker_2`, `observable`, `staging`, lignes 76-86), alors que la
production tourne sur **un seul CPX22** posé par `setup-hetzner-cpx22.sh`. Il n'y
a dans le dépôt ni état Terraform, ni `terraform.tfvars` (seulement
`terraform.tfvars.example`).

→ **NON VÉRIFIÉ, et présumé non appliqué.** À trancher par Will dans la console
Hetzner (§4.5) : un pare-feu Hetzner Cloud est la seule couche qui **filtre en
amont de Docker** et qui rendrait le §2 sans objet.

### 1.4 La note du 04/07 « pas de firewall » — **INTROUVABLE**

Recherchée et **non trouvée** :

- `grep -rn -i "pas de firewall|aucun firewall|sans firewall|pas de pare-feu|aucun pare-feu"`
  sur tout le dépôt (`*.md`) → **0 résultat** ;
- `grep -rn -i "firewall|pare-feu|ufw|fail2ban"` sur `_REPORTS/**` → **0
  résultat textuel** (deux captures PNG matchent en binaire, sans rapport) ;
- `git log --all -i --grep=firewall --grep=pare-feu --grep=ufw` → 2 commits
  seulement, `62df59a` (2026-05-17, création du script de setup) et `116bf29`
  (Terraform) ;
- `git log --all --since=2026-07-03 --until=2026-07-06` → 30 commits, tous de
  prospection / médias, aucun ne parle de pare-feu.

**Conclusion honnête : cette note n'est pas dans ce dépôt.** Elle existe
peut-être ailleurs (mémoire de session, dépôt `axionia`, échange hors dépôt),
mais je ne l'ai pas trouvée et je ne la cite donc pas. Si Will la retrouve, elle
doit être recollée ici — c'est exactement le genre de constat qu'un document
comme celui-ci doit porter.

---

## 2. 🔴 CE QUE LA COMPOSITION DE PRODUCTION CONTREDIT

C'est le point le plus important de ce document.

### 2.1 Les ports réellement publiés

`C:\Users\willi\Documents\Projets\crmpro-wt-etape0\docker-compose.yml` publie :

| Service    | Ligne | `ports:`         | Interface d'écoute |
| ---------- | ----- | ---------------- | ------------------ |
| `postgres` | 22-23 | `"55432:5432"`   | **0.0.0.0** (toutes) |
| `redis`    | 43-44 | `"56379:6379"`   | **0.0.0.0** (toutes) |
| `api`      | 130-131 | `"8080:8080"`  | **0.0.0.0** (toutes) |
| `caddy`    | 193-195 | `"80:80"`, `"443:443"` | 0.0.0.0 (voulu) |

`C:\Users\willi\Documents\Projets\crmpro-wt-etape0\docker-compose.prod.yml` ne
contient **aucune** clause `ports:` (`grep -n -A4 "ports:"` → 0 résultat). Il
redéfinit `restart: unless-stopped` sur `postgres` et `redis` (lignes 109-113),
et rien d'autre les concernant.

Or **Docker Compose FUSIONNE la liste `ports` entre fichiers** — l'overlay lui-
même le rappelle pour `volumes` (lignes 22-27 : *« Docker Compose FUSIONNE les
listes `volumes` entre fichiers, il ne les remplace pas »*, d'où le `!override`).
**Aucun `!override` n'est posé sur `ports`.**

Et la production utilise bien les deux fichiers :
`.github/workflows/deploy-direct-ssh.yml:149` →
`export COMPOSE_FILE="docker-compose.yml:docker-compose.prod.yml"` ;
`infra/scripts/setup-hetzner-cpx22.sh:115-116` → `docker compose -f
docker-compose.yml -f docker-compose.prod.yml up -d`.

**→ En production, Postgres écoute sur le port hôte 55432 et Redis sur 56379,
sur toutes les interfaces.**

Confirmation croisée, indépendante : le workflow
`.github/workflows/prospection-find-websites-distributed.yml:136` ouvre un tunnel
`-L 15432:127.0.0.1:55432 "$USER@$HOST"` — le port 55432 **existe bien** sur
l'hôte de production.

### 2.2 Pourquoi UFW ne protège pas ces ports

Sur une installation Docker par défaut, le démon écrit ses règles de
publication dans les chaînes `nat/PREROUTING` (`DOCKER`) et `filter/FORWARD`
(`DOCKER`) de `iptables`. Le trafic destiné à un conteneur est **routé**, pas
délivré localement : il ne traverse donc **pas** la chaîne `INPUT` où UFW pose
ses règles. Un `ufw deny` sur un port publié par Docker **ne bloque rien**.

**Le dépôt en tient-il compte ? NON.** Recherche exhaustive :
`grep -rn "DOCKER-USER|iptables|ufw-docker|127\.0\.0\.1:5"` sur tout le dépôt →
**aucune règle `DOCKER-USER`, aucune mention de `ufw-docker`, aucun
`iptables=false`**. Le seul endroit du dépôt qui applique la bonne pratique est
un POC :

```
C:\Users\willi\Documents\Projets\crmpro-wt-etape0\poc\05_dedup_performance\docker-compose.yml:13
      - "127.0.0.1:55432:5432"
```

Le motif est donc **connu du dépôt** — et n'est appliqué **qu'au POC**, pas à la
composition qui tourne en production.

### 2.3 🔴 Ce qui aggrave le point 2.1 : le mot de passe

`docker-compose.yml:14-18` :

```yaml
    environment:
      POSTGRES_DB: axion_crm
      POSTGRES_USER: axion
      POSTGRES_PASSWORD: axion_dev_only
```

La valeur est **littérale**, pas `${POSTGRES_PASSWORD:-...}`. L'overlay de
production ne la redéfinit pas. Le rôle `axion` est par ailleurs **SUPERUSER +
BYPASSRLS**, fait vérifié en production le 2026-08-14 et documenté dans
`backend/database/migrations/2026_08_14_000001_harden_workspace_isolation.php:14-18`.

De même, le service `redis` (`docker-compose.yml:32-51`) est démarré **sans
`requirepass`** : aucune authentification.

**Si le §2.1 est exact et si aucune couche amont ne filtre (§1.3), alors la base
de production est joignable depuis Internet avec un mot de passe connu de tout
lecteur du dépôt, sur un rôle superutilisateur.** Cela concerne toutes les
personnes dont les données sont dans cette base — dont, potentiellement, la table
`health_practitioners` (donnée de l'article 9). C'est traité comme risque majeur
dans `_REPORTS/AIPD_2026-08-18.md`, §4.

⚠️ **Ce paragraphe est un raisonnement sur des fichiers, pas une mesure.** Il est
peut-être faux : un pare-feu Hetzner Cloud posé à la main dans la console, ou une
règle `DOCKER-USER` ajoutée manuellement sur le serveur, suffiraient à le rendre
sans objet. **C'est précisément ce que le §4 sert à trancher — en 5 minutes.**

---

## 3. Synthèse

| # | Affirmation du dépôt | État |
| --- | --- | --- |
| 1 | UFW `deny incoming` + 22/80/443 | Script existe. **Application au serveur : NON VÉRIFIÉ** |
| 2 | fail2ban installé et activé | Script existe ; **aucune jail versionnée** ; trace indirecte d'activité le 2026-07-06 |
| 3 | Jails `sshd` + `caddy-401/403/429`, ban 1 h (spec/02) | 🔴 **N'existent nulle part dans le dépôt** |
| 4 | Pare-feu Hetzner Cloud 22/80/443 (Terraform) | 🔴 **Présumé non appliqué** (décrit 7 serveurs, la prod en a 1 ; pas d'état TF) |
| 5 | Note du 04/07 « pas de firewall » | **Introuvable dans ce dépôt** (recherches au §1.4) |
| 6 | Postgres 55432 et Redis 56379 publiés sur 0.0.0.0 en prod | 🔴 **Établi par lecture des fichiers** ; contourne UFW ; **non mesuré sur le serveur** |
| 7 | `POSTGRES_PASSWORD=axion_dev_only` en production | 🔴 **Établi par lecture des fichiers** ; rôle SUPERUSER+BYPASSRLS |
| 8 | Redis sans `requirepass` | 🔴 **Établi par lecture des fichiers** |
| 9 | Prise en compte de l'interaction Docker/UFW | 🔴 **Absente** — le bon motif n'existe que dans `poc/05` |

---

## 4. PROCÉDURE DE VÉRIFICATION — à jouer par Will, sur le serveur

> Exécutable telle quelle. Copier-coller bloc par bloc. Chaque bloc dit ce qu'une
> sortie **saine** montre et ce qui doit **alerter**.

### 4.0 Se connecter

```bash
ssh root@<IP-DU-SERVEUR-PROD>
```

### 4.1 UFW — le pare-feu est-il seulement actif ?

```bash
ufw status verbose
```

**Sortie saine :**

```
Status: active
Logging: on (low)
Default: deny (incoming), allow (outgoing), disabled (routed)
New profiles: skip

To                         Action      From
--                         ------      ----
22/tcp                     ALLOW IN    Anywhere                   # SSH
80/tcp                     ALLOW IN    Anywhere                   # HTTP
443/tcp                    ALLOW IN    Anywhere                   # HTTPS
```

**🔴 Alerter si :**

- `Status: inactive` → **il n'y a pas de pare-feu hôte du tout** ;
- `Default: allow (incoming)` → la politique par défaut est ouverte ;
- une règle `ALLOW IN` sur autre chose que 22/80/443 ;
- la commande répond `ufw: command not found` → l'étape 3 du script de setup n'a
  jamais été jouée.

### 4.2 fail2ban — tourne-t-il, et que garde-t-il ?

```bash
systemctl is-active fail2ban
fail2ban-client status
```

**Sortie saine :**

```
active
Status
|- Number of jail:      1
`- Jail list:   sshd
```

**🔴 Alerter si :**

- `inactive` / `failed` → aucun bannissement ;
- `Number of jail: 0` → le service tourne **et ne garde rien** ;
- `Jail list:` ne contient pas `sshd`.

Puis le détail de la jail SSH :

```bash
fail2ban-client status sshd
```

**Sortie saine :** des compteurs **non nuls** dans `Total failed` et `Total
banned` — sur un serveur exposé depuis des mois, `Total banned: 0` signifie
presque toujours que la jail ne lit pas le bon journal, **pas** que personne n'a
essayé.

**🔴 Alerter si** `Total banned: 0` alors que le serveur est exposé depuis plus
de quelques jours.

### 4.3 🔴 LE CONTRÔLE QUI COMPTE — les ports réellement à l'écoute

```bash
ss -tlnp
```

**Sortie saine :** aucune ligne `0.0.0.0:55432`, `[::]:55432`, `0.0.0.0:56379`,
`[::]:56379`, `0.0.0.0:8080`. Seuls **22**, **80** et **443** doivent apparaître
sur `0.0.0.0` / `[::]`.

**🔴 ALERTE MAJEURE si** une des lignes suivantes apparaît :

```
LISTEN 0 4096 0.0.0.0:55432 0.0.0.0:*  users:(("docker-proxy",...))
LISTEN 0 4096 0.0.0.0:56379 0.0.0.0:*  users:(("docker-proxy",...))
```

C'est exactement ce que le §2.1 prédit. `docker-proxy` dans la colonne `users`
confirme que c'est une publication Docker — donc **invisible pour UFW**.

Contrôle complémentaire, plus court :

```bash
docker ps --format 'table {{.Names}}\t{{.Ports}}'
```

Toute ligne de la forme `0.0.0.0:PORT->…` est une publication non filtrée par
UFW. Une ligne `127.0.0.1:PORT->…` est sûre.

### 4.4 Le contrôle DEPUIS L'EXTÉRIEUR — le seul qui prouve

À jouer **depuis le poste de Will**, pas depuis le serveur (depuis le serveur,
tout répond) :

```bash
# Remplacer <IP> par l'IP publique de production
nc -zv -w 5 <IP> 55432   # Postgres
nc -zv -w 5 <IP> 56379   # Redis
nc -zv -w 5 <IP> 8080    # API en direct
```

**Sortie saine :** `Connection timed out` ou `Connection refused` pour **les
trois**.

**🔴 ALERTE MAJEURE si** l'un répond `succeeded!` / `open`. Dans ce cas, et en
particulier pour 55432 :

1. considérer la base comme **potentiellement compromise** (mot de passe
   `axion_dev_only` publiquement lisible dans le dépôt, rôle superutilisateur) ;
2. fermer immédiatement (§4.6) ;
3. changer `POSTGRES_PASSWORD` ;
4. examiner `audit_logs` et les journaux Postgres à la recherche de connexions
   d'origine inconnue ;
5. évaluer l'obligation de notification CNIL sous 72 h (art. 33 RGPD) — la base
   contient des données personnelles, et potentiellement de l'article 9.

### 4.5 Le pare-feu Hetzner Cloud (couche amont)

Console Hetzner Cloud → projet → **Firewalls**.

**Sain :** un pare-feu existe, il est **appliqué au serveur de production**
(onglet « Resources »), et il n'ouvre en entrée que 22 (idéalement restreint à
l'IP de Will), 80, 443.

**🔴 Alerter si :** aucun pare-feu, ou pare-feu existant mais **appliqué à aucune
ressource** (piège classique : la ressource existe, elle ne protège rien).

C'est la couche qui rend le §2.2 sans objet : le pare-feu Hetzner filtre **avant**
que le paquet n'atteigne la machine, donc **avant** `iptables` et donc avant
Docker.

### 4.6 Si le §4.3 ou le §4.4 est rouge — comment fermer

Deux remèdes, du plus rapide au plus propre. **Le premier est immédiat.**

**(a) Pare-feu Hetzner Cloud (immédiat, sans toucher au serveur)** — créer dans
la console un pare-feu n'autorisant en entrée que 22 / 80 / 443 et l'appliquer au
serveur. Effet instantané, aucun redémarrage de conteneur, réversible.

**(b) Correctif de fond, dans le dépôt** — lier les publications à la boucle
locale, comme le fait déjà `poc/05_dedup_performance/docker-compose.yml:13`. Dans
`docker-compose.prod.yml`, en utilisant `!override` (indispensable : sans lui,
Compose **fusionne** les listes `ports` et la publication `0.0.0.0` survit) :

```yaml
  postgres:
    restart: unless-stopped
    ports: !override
      - "127.0.0.1:55432:5432"

  redis:
    restart: unless-stopped
    ports: !override
      - "127.0.0.1:56379:6379"

  api:
    ports: !override []
```

⚠️ **Avant d'appliquer (b)**, vérifier ce qui consomme ces ports depuis
l'extérieur : `.github/workflows/prospection-find-websites-distributed.yml:136`
ouvre un tunnel SSH `-L 15432:127.0.0.1:55432` — celui-là **continue de
fonctionner** après (b), puisqu'il sort de `127.0.0.1` **vu du serveur**. Un
éventuel client qui se connecterait en direct sur `<IP>:55432`, lui, casserait :
c'est le comportement voulu.

⚠️ Et **prouver le correctif avant de déployer**, sans serveur :

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml config | grep -A3 "published"
```

La sortie doit montrer `host_ip: 127.0.0.1` sur 55432 et 56379. C'est le seul
contrôle qui distingue un `!override` qui marche d'un `ports: []` qui, comme le
`volumes: []` de 2026-08-16, serait un **no-op silencieux**.

**(c) Dans tous les cas** — changer `POSTGRES_PASSWORD` (aujourd'hui
`axion_dev_only`, en clair dans un dépôt) et poser un `requirepass` sur Redis.
Ces deux points sont indépendants du pare-feu : ils valent même sur une base
correctement isolée.

### 4.7 À archiver ici

Coller la sortie **brute** de 4.1, 4.2, 4.3 et 4.4 dans le §5 ci-dessous, avec la
date et l'IP masquée. **Sans ces sorties, ce document reste un document de
prétentions.**

---

## 5. CONSTAT MESURÉ — *(VIDE : aucune mesure n'a été faite)*

```
(à remplir par Will après exécution du §4)

Date de la mesure   :
Serveur             :
4.1 ufw status verbose         → [coller]
4.2 fail2ban-client status     → [coller]
4.3 ss -tlnp                   → [coller]
4.4 nc depuis l'extérieur      → [coller]
4.5 pare-feu Hetzner Cloud     → [oui / non / appliqué à quelles ressources]
```

---

## 6. Entrée de runbook

À reporter dans `infra/runbooks/` (nouveau fichier `06-verifier-pare-feu.md`, ou
en section des runbooks existants) — **rédaction hors périmètre d'écriture de cet
agent, qui n'écrit que dans `_REPORTS/`** :

```bash
# Contrôle mensuel du pare-feu (5 min) — cf. _REPORTS/2026-08-18_ETAT-PARE-FEU.md §4
ufw status verbose                                  # attendu : active, deny incoming, 22/80/443
fail2ban-client status                              # attendu : au moins la jail sshd
fail2ban-client status sshd                         # attendu : Total banned > 0
ss -tlnp                                            # attendu : AUCUN 0.0.0.0:55432 / 56379 / 8080
docker ps --format 'table {{.Names}}\t{{.Ports}}'   # attendu : aucun 0.0.0.0:PORT->
# depuis le poste de Will :
nc -zv -w 5 <IP> 55432 ; nc -zv -w 5 <IP> 56379     # attendu : timed out / refused
```

**Candidat à l'automatisation** : les quatre premières lignes tiennent dans un
script du même genre que `infra/scripts/verifier-sauvegarde.sh`, branché sur le
même mécanisme d'alerte. Ce script a été écrit **parce qu'une sauvegarde a échoué
91 fois sans que personne ne le voie** ; un pare-feu qui tombe est tout aussi
silencieux. **Décision Will.**

---

## 7. Ce que Will doit décider

1. **Jouer le §4** (5 min) et coller les sorties au §5. Tant que ce n'est pas
   fait, F12 n'est **pas** soldé : le plan demande l'état **réel**, pas l'état
   supposé.
2. **Si 4.3/4.4 sont rouges** : appliquer §4.6(a) immédiatement, puis (b), puis
   (c) ; et trancher s'il faut instruire une violation de données (art. 33).
3. **Trancher §4.5** : veut-on un pare-feu Hetzner Cloud en couche amont ? C'est
   la seule protection qui ne dépend ni d'UFW ni de Docker.
4. **Décider du sort de `infra/terraform/`** : soit il décrit la cible et on
   l'annote comme tel, soit il décrit la production et il faut l'y appliquer.
   Aujourd'hui il décrit 7 serveurs pour une production qui en a 1 — un lecteur
   pressé y voit un pare-feu qui n'existe pas.
5. **Décider du sort des jails de `spec/02` et `spec/15`** (`caddy-401/403/429`,
   ban 1 h) : les écrire, ou les retirer de la spec. Une spec qui promet quatre
   jails là où il en existe une est un faux témoin.
6. **Retrouver, ou déclarer perdue, la note du 04/07 « pas de firewall »**
   (cf. §1.4). Si elle disait vrai à cette date, la question devient : qu'a-t-on
   changé depuis ?
7. **Automatiser ou non le contrôle** (§6).

---

*Ce document ne mesure rien. Il liste ce que le dépôt affirme, ce que la
composition de production contredit, et la procédure exacte pour trancher. Son
§5 est vide, et doit le rester tant que personne n'a joué le §4.*
