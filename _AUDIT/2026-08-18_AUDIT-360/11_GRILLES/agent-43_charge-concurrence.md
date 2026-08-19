# AGENT 43 — Charge et concurrence

> **Référence** : `main = a3c42d6` (relue par `git log`, pas copiée d'un document).
> Le code de l'API est inchangé depuis `e8924b8` ; les commits intervenus depuis
> ne touchent que `_AUDIT/`.
> **Date des mesures** : 2026-08-19, 12:29 → 13:05 UTC.
> **Preuves** : `04_PREUVES/agent-43/`.

---

## 0. Ce que cet agent n'a PAS fait, et pourquoi — à lire avant les chiffres

**Aucune charge n'a été envoyée vers la production, ni vers la préproduction.**
Toutes les mesures de ce rapport ont été jouées **en local**, sur des bases
jetables (`axion_crm_perf`, `axion_crm_perf4m`, et une copie `axion_crm_g43`
créée pour cet agent).

**Aucun chiffre de charge HTTP n'est produit.** La décision `D-010` du chef de
chantier est appliquée telle quelle : le constat **A-010** établit que la
production sert toute l'API par `php -S`, **un seul processus**, sans
`PHP_CLI_SERVER_WORKERS` — j'ai vérifié que cette variable n'apparaît **nulle
part** dans le dépôt hors du script de garde `infra/scripts/verifier-serveur-http.sh`
(preuve : recherche `--no-ignore` sur tout le dépôt, piège 23 paré). Une mesure
HTTP jouée aujourd'hui mesurerait la file d'attente. Je ne la produis pas.

**Ce que je produis à la place, et qui est mesurable aujourd'hui :**

1. le **protocole du critère 17**, prêt à jouer le jour où A-010 sera corrigé (§2) ;
2. la **concurrence de la couche base**, mesurée de bout en bout à 1 et à 10
   sessions — Postgres, lui, n'est pas sérialisé (§3) ;
3. le **verdict sur l'édition concurrente**, avec témoin positif (§4) ;
4. l'**état des verrous** du planificateur (§5) ;
5. l'**état de `load-tests/`** (§6) ;
6. la **projection du gel en secondes**, déclarée comme projection (§7).

**Déclaration exigée par le §5 bis n° 0** : chaque mesure ci-dessous dit à combien
de sessions elle a été jouée. Aucune n'est présentée comme une mesure « du produit »
sans dire quelle couche elle traverse.

**Déclaration exigée par le §5 bis n° 1** : le cloisonnement local et le
cloisonnement de production divergent (`CRM_DB_APP_ROLE_ENABLED` = `false` local,
`true` production, constat **B11-010**). J'ai donc **armé le dispositif comme en
production** : toutes les mesures de charge base sont jouées **deux fois**, sous
le rôle `axion` (BYPASSRLS, l'atelier) **et** sous le rôle `axion_app` (RLS en
FORCE, la production). **C'est ce doublement qui a produit le constat principal
de cet agent** — et il n'aurait pas existé sans cette consigne.

---

## 1. Grille — une ligne par objet de mon périmètre

| # | Objet | Vérifié ? | Méthode | Résultat | Constat |
|---|---|---|---|---|---|
| 1 | Critère 17 §29 : dix sessions, dégradation ≤ 20 % — **couche HTTP** | ❌ **non mesuré — raison déclarée** | Interdit par `D-010` : `php -S` mono-processus sérialise (A-010). Une mesure rendrait la file d'attente. | Protocole écrit à la place (§2) | — |
| 2 | Critère 17 §29 — **couche base**, 1 vs 10 sessions | ✅ mesuré | `pgbench` 4 scénarios pondérés, `-c 1` puis `-c 10`, 40 s, `-l` + percentiles calculés | **p95 global 1 413 ms → 5 794 ms = +310 %** sous RLS de production | **G43-002** |
| 3 | Idem, sous le rôle de l'atelier (BYPASSRLS) | ✅ mesuré (témoin de divergence) | même scénario, rôle `axion` | **p95 337 ms → 658 ms = +95 %** | **G43-002** |
| 4 | La **mesure de référence** du §29 (`_REPORTS/2026-08-18_…-REFERENCE.md`) est-elle jouable comme référence de production ? | ✅ mesuré | même requêtes, même base, deux rôles, `EXPLAIN ANALYZE` | **Non.** Sous RLS le planificateur **perd l'index** : recherche globale 39 ms → 3 589 ms (**×92**) | **G43-001** |
| 5 | Compteurs du hub au volume de production (2,8 M), 1 vs 10 sessions | ✅ mesuré | `pgbench` sur `axion_crm_perf4m`, index `idx_companies_ws_counts` en place | p95 **2 192 ms → 6 169 ms = +181 %** | **G43-003** |
| 6 | Édition concurrente sur le même champ : **détectée** ? | ✅ mesuré | 2 sessions `psql` réellement simultanées + **témoin positif** (verrou optimiste) | **Aucun mécanisme.** Une saisie disparaît, les deux `UPDATE` rendent `UPDATE 1` | **G43-005** |
| 7 | Verrou optimiste / `ETag` / `If-Match` / colonne de version | ✅ mesuré | recherche sur `backend/app` **et** `frontend/src`, avec témoin négatif (le contrôle trouve bien `lockForUpdate` et `lock: [...]`) | **Zéro occurrence.** 11 routes `PUT`, aucune n'accepte de champ de concurrence | **G43-005** |
| 8 | `withoutOverlapping()` sans argument = 24 h : combien de tâches ? | ✅ mesuré | comptage sur `routes/console.php` + lecture de `ManagesAttributes.php:145` | **28 tâches sur 28**, `expiresAt = 1440` min pour toutes | **G43-006** |
| 9 | Que se passe-t-il si un processus meurt entre la prise et le relâchement ? | ✅ mesuré (lecture du framework) | `CacheEventMutex::create` → `lock($nom, expiresAt*60)` | La tâche est **gelée 24 h, en silence** | **G43-006** |
| 10 | `load-tests/` : contenu, a-t-il déjà tourné, contre quoi ? | ✅ mesuré | `git log`, présence de `results/`, `grep artillery` sur les `package.json` et la CI | **Jamais joué.** 1 commit (2026-05-17), pas de `results/`, `artillery` dans aucun manifeste, aucune référence CI | **G43-007** |
| 11 | Existe-t-il une garde qui éprouve la concurrence ? | ✅ mesuré | recherche `concurren|simultan|409|conflict` sur `backend/tests/` | **Aucune.** Le seul test « 409 » est **séquentiel** ; il éprouve l'idempotence, pas la concurrence | **G43-008** |
| 12 | Les verrous pessimistes existants (`lockForUpdate`) | ✅ mesuré | lecture des 2 occurrences | 2 seulement : `ArbitrageController:257`, `Crm/BulkController:173`. **Sains**, mais éprouvés séquentiellement | **G43-008** |
| 13 | Le verrou borné de `CompteursHub` (30 s) | ✅ mesuré | lecture `CompteursHub.php:79-85` + `Repository::flexible` | **Sain, et c'est la brique à réutiliser** pour G43-006 | §8 |
| 14 | Coût de la sérialisation en secondes, à partir des mesures existantes | ✅ calculé, **déclaré comme projection** | arithmétique sur A-010 + `MESURE-COMPTEURS-HUB.md` + mes chiffres | **17,5 s à 26,8 s** de gel par ouverture de console, selon l'état déployé | §7 |
| 15 | Écritures concurrentes de la collecte vs console (contention) | ⚠️ **non mesuré — raison déclarée** | Aurait exigé de rejouer un lot de collecte en parallèle d'une charge console ; hors budget de temps de cet agent | — | §9 |
| 16 | Concurrence de la couche **queue/Horizon** (jobs simultanés) | ⚠️ **non mesuré — raison déclarée** | Redis local partagé avec 8 autres piles (`docker ps` : 18 conteneurs) — une mesure aurait été contaminée | — | §9 |
| 17 | Effet réel du **cache** `Cache::flexible` sous 10 sessions | ⚠️ **non mesuré — raison déclarée** | Exige la couche HTTP, donc `php -S`, donc `D-010` | Protocole écrit (§2, étape 5) | §9 |
| 18 | Sous-produit : `company_tag` écrit sans `workspace_id` | ✅ mesuré (avec témoin positif) | `INSERT` sous `axion_app` : `new row violates row-level security policy` | `POST /companies/tags/bulk` est **cassé en production** | **G43-004** |

---

## 2. Le protocole du critère 17 — prêt à jouer le jour où A-010 sera corrigé

> Le §29 n° 17 exige : « **dix sessions actives**, aucune dégradation de plus de
> 20 % ; **aucun p95 du critère 1 ni de la mesure de référence (§A.1 n° 6) ne se
> dégrade de plus de 20 %** ; **une édition concurrente sur le même champ est
> détectée** et aucune valeur n'est perdue. »
>
> Ce protocole **étend** `_REPORTS/2026-08-18_MESURE-PERFORMANCE-REFERENCE.md` et
> `_REPORTS/2026-08-19_MESURE-COMPTEURS-HUB.md`. Il ne les réinvente pas (règle 8) :
> il reprend **leurs** endpoints, **leur** jeu de données, **leurs** scripts.

### 2.0 Préalable BLOQUANT — sans lui, le protocole ne mesure rien

**A-010 doit être soldé avant la première itération.** Tant que l'API est servie
par `php -S` mono-processus, la mesure rend la file d'attente. La garde qui le
vérifie **existe déjà** : `infra/scripts/verifier-serveur-http.sh`. Elle doit
**rendre 0** sur la cible avant de lancer quoi que ce soit, et sa sortie est le
premier fichier de preuve du protocole.

Second préalable : **la cible doit être armée comme la production**, c'est-à-dire
`CRM_DB_APP_ROLE_ENABLED=true` (B11-010). Une mesure faite sous BYPASSRLS ne
mesure pas le produit — le §4 de ce rapport le chiffre à un facteur 92 sur une
requête.

### 2.1 Où l'on mesure

**Préproduction** (`staging-api.axion-crm-pro.com`), jamais la production. Les
deux rapports de référence le disent déjà tous les deux, en toutes lettres.
Le jeu de données est celui du cahier des charges, rejouable :
`backend/database/perf/seed_reference_50k.sql` (300 000 fiches) puis
`seed_volume_production_4m.sql` (volume de production).

### 2.2 La référence à laquelle on compare — elle existe, ne la réinventez pas

| Grandeur | Valeur de référence | Source |
|---|---|---|
| Ligne de base HTTP (`GET /config/features`) | à re-mesurer sur la cible : sur le poste de mesure d'origine, **5,90 s p50 / 8,31 s p95** — c'est le démarrage de Laravel à travers un montage Windows, **pas le produit** | REFERENCE §2 |
| `GET /crm/contacts-hub?per_page=50` | p50 7,56 s / p95 9,37 s → **+1,66 s / +3,46 s** au-dessus de la ligne de base | REFERENCE §2 |
| `GET /crm/contacts-hub/counts` | p50 4,89 s / p95 5,46 s (**−1,01 / −0,45** : sous la ligne de base) | REFERENCE §2 |
| `GET /crm/persons/{key}/timeline` | p50 4,67 s / p95 5,86 s | REFERENCE §2 |
| `GET /search?q=…` | p50 4,73 s / p95 5,84 s | REFERENCE §2 |
| `GET /companies/export?relation_type=client` | p50 **37,41 s** / p95 **41,56 s** (16 Mo, 9 092 l.) | REFERENCE §2 |
| Compteurs, SQL seul, 2,8 M, **avec** index | **721 / 648 / 798 / 972 ms** | COMPTEURS §3.2 |
| Compteurs, SQL seul, 2,8 M, **sans** index, froid | **17 504 ms** | COMPTEURS §3.2 |

⚠️ **Ces valeurs HTTP sont des valeurs de POSTE, pas de produit** — le rapport de
référence le dit lui-même et impose de raisonner **en delta**. Le protocole
ci-dessous compare donc **des deltas**, jamais des absolus, et **re-mesure la
ligne de base sur la cible** au début de chaque itération.

⚠️ **Et ces valeurs ont été obtenues sous BYPASSRLS** (constat **G43-001**). La
toute première action du protocole est donc de **rejouer la mesure de référence
sous `CRM_DB_APP_ROLE_ENABLED=true`** et d'adopter **ce** résultat comme
référence. Comparer une charge de production à une référence d'atelier
produirait un verdict faux dans le sens rassurant.

### 2.3 Ce qu'on ouvre — les huit requêtes, et pourquoi celles-là

Elles sont choisies pour couvrir **le critère 1** (« une fiche est retrouvable en
moins de 5 s ») et **le chemin réel d'une session de console** :

| # | Requête | Pourquoi elle est dans le lot | Poids |
|---|---|---|---|
| 1 | `GET /crm/contacts-hub?per_page=50` | la liste, écran d'entrée, la plus lourde | 25 % |
| 2 | `…&temperature=froids` | fait travailler le filtre de température sur la base froide | 10 % |
| 3 | `…&q=<préfixe>` | la recherche dans la liste — **critère 1** | 15 % |
| 4 | `GET /search?q=<préfixe>` | la recherche globale — **critère 1** | 10 % |
| 5 | `GET /crm/contacts-hub/counts` | rendu **à chaque affichage** de navigation ; c'est le point chaud identifié par les deux rapports | 20 % |
| 6 | `GET /crm/persons/{person_key}/timeline` | la fiche 360° — **critère 4** | 10 % |
| 7 | `PUT /companies/{id}` (un champ) | l'écriture, indispensable au volet « édition concurrente » | 8 % |
| 8 | `GET /companies/export?relation_type=client` | le seul endpoint dont le coût propre était visible (**+31,5 s**) ; **1 seule session**, jamais 10 | 2 % |

Les `person_key` et les identifiants sont tirés au hasard à chaque itération : un
lot fixe mesurerait le cache, pas le produit.

### 2.4 Le témoin, et le protocole de comparaison

**Trois passes, dans cet ordre. La deuxième est le témoin, et elle est obligatoire.**

| Passe | Sessions | Ce qu'elle établit |
|---|---|---|
| **A — ligne de base** | 1 | le p95 de chaque requête à un seul utilisateur. **C'est le dénominateur** de la règle des 20 %. |
| **B — témoin séquentiel** | 1, mais **10 fois plus de requêtes** que la passe C | Prouve que la dégradation observée en C vient de **la concurrence** et non du volume total de requêtes. **Sans ce témoin, un p95 dégradé ne prouve rien** — c'est exactement l'erreur qu'A-010 a évitée en jouant son escalier de 15 ms contre un séquentiel plat. |
| **C — dix sessions** | **10**, dix jetons distincts, dix utilisateurs distincts du même workspace | La mesure du critère 17. |

**Verdict** : le critère 17 est tenu si, pour **chacune** des 8 requêtes,
`p95(C) ≤ 1,20 × p95(A)`. Un seul dépassement suffit à le faire rougir. Le résultat
se rend **requête par requête**, jamais en moyenne : une moyenne globale masquerait
qu'une seule requête a triplé.

**Ce qu'il faut relever en plus du p95** — sans quoi le chiffre ment :
- le **débit** (requêtes/s) en A et en C. Si le débit en C n'est pas ≈ 10 × celui
  de A, **le serveur ne parallélise pas** : c'est le symptôme direct d'A-010, et
  il se lit sans même regarder les latences ;
- le **taux d'erreur** (doit être 0 ; toute 5xx invalide l'itération) ;
- l'**empreinte disque** : le journal est en `LOG_LEVEL=debug` sans rotation et
  pèse déjà 270 Mo (A-007). Dix sessions le feront grossir vite.

### 2.5 Le volet « édition concurrente » — le protocole, et sa garde

Le §29 n° 17 exige deux choses distinctes, et il faut les mesurer séparément :

**(a) Le conflit est DÉTECTÉ.**
Deux jetons, deux utilisateurs, **la même fiche, le même champ** :

1. A lit `GET /companies/{id}` → note la valeur de concurrence rendue (aujourd'hui
   **il n'y en a aucune** — voir G43-005) ;
2. B lit la même fiche ;
3. A écrit `PUT /companies/{id}` avec `denomination = "A"` → attendu **200** ;
4. B écrit `PUT /companies/{id}` avec `denomination = "B"`, **en présentant la
   valeur de concurrence qu'il a lue à l'étape 2** → attendu **409 Conflict**.

**Garde** : l'étape 4 doit rendre **409**. Si elle rend 200, le critère n'est pas
tenu. Aujourd'hui elle rend 200 : mesuré au §4.

**(b) Aucune valeur n'est perdue.**
Après l'étape 4, `GET /companies/{id}` doit rendre `"A"`, et l'écran de B doit
avoir été averti. **Le témoin positif de cette garde est déjà écrit et joué** —
il est en `04_PREUVES/agent-43/12_perte-de-mise-a-jour.txt` : la même séquence
avec la condition `AND updated_at = <valeur lue>` rend **`UPDATE 0`**, ce qui
prouve que le conflit est détectable et que le contrôle sait le voir.

### 2.6 Ce qu'il faut archiver à chaque itération

Sortie brute de l'outil de charge, `git log --oneline -1` de la cible, la sortie
de `verifier-serveur-http.sh`, la valeur de `CRM_DB_APP_ROLE_ENABLED` **relevée
sur le conteneur** (`docker inspect`, jamais le fichier — piège 18), le nombre de
fiches de la base, et les trois passes A/B/C côte à côte dans un seul tableau.

---

## 3. La concurrence de la couche base — mesurée, à 1 et à 10 sessions

**Ce que cette mesure vaut, et ne vaut pas.** Elle mesure **Postgres seul**, sans
PHP, sans HTTP, donc **sans la sérialisation d'A-010**. C'est la seule couche du
produit où la concurrence est mesurable de bout en bout aujourd'hui. Elle ne dit
rien du temps de réponse ressenti ; elle dit ce que la base **peut** rendre, et
elle donne un **plancher** : le produit ne pourra jamais faire mieux.

**Dispositif** : `pgbench` 16.9 dans `axion-crm-postgres`, base `axion_crm_g43`
(copie `TEMPLATE axion_crm_perf`, **300 000 fiches**, 50 000 contacts, 500 000
activités), 4 scénarios pondérés construits **à partir des requêtes du rapport de
référence** (`backend/database/perf/explain_reference.sql`), 40 s par passe,
percentiles calculés sur le journal `-l`. Scripts archivés dans
`04_PREUVES/agent-43/scenarios/`.

### 3.1 Sous le rôle `axion_app` — RLS en FORCE, **la configuration de la production**

| requête | n (c=1) | p50 c=1 | p95 c=1 | n (c=10) | p50 c=10 | **p95 c=10** | **dégradation p95** |
|---|---|---|---|---|---|---|---|
| liste du hub | 27 | 725 ms | 1 174 ms | 99 | 1 759 ms | **6 015 ms** | **+412 %** |
| compteurs | 11 | 565 ms | 1 724 ms | 39 | 1 357 ms | **2 871 ms** | **+67 %** |
| recherche préfixe | 9 | 575 ms | 1 033 ms | 36 | 1 995 ms | **4 550 ms** | **+340 %** |
| timeline | 10 | 302 ms | 1 413 ms | 16 | 1 140 ms | **6 201 ms** | **+339 %** |
| **toutes** | **57** | **591 ms** | **1 413 ms** | **190** | **1 704 ms** | **5 794 ms** | **+310 %** |

Débit : **1,40 tps** à 1 session → **4,55 tps** à 10 sessions.

### 3.2 Sous le rôle `axion` — BYPASSRLS, **la configuration de l'atelier local**

| requête | p50 c=1 | p95 c=1 | p50 c=10 | p95 c=10 | dégradation p95 |
|---|---|---|---|---|---|
| liste du hub | 202 ms | 337 ms | 239 ms | 723 ms | +115 % |
| compteurs | 215 ms | 504 ms | 298 ms | 1 018 ms | +102 % |
| recherche préfixe | **2 ms** | **12 ms** | 2 ms | 18 ms | +50 % |
| timeline | 76 ms | 323 ms | 78 ms | 421 ms | +30 % |
| **toutes** | **188 ms** | **337 ms** | **213 ms** | **658 ms** | **+95 %** |

Débit : **5,59 tps** → **36,36 tps**.

### 3.3 Ce que ces deux tableaux disent, ensemble

1. **Postgres n'est PAS sérialisé.** Le débit monte de ×3,2 (RLS) à ×6,5
   (BYPASSRLS) quand on passe de 1 à 10 clients. C'est le contraste exact avec
   A-010, où 12 requêtes simultanées formaient un escalier de pas 15 ms —
   c'est-à-dire un débit **plat**. La couche base parallélise ; la couche HTTP
   non.
2. **Le critère 17 échouerait sur la base SEULE.** +310 % sous RLS, +95 % sous
   BYPASSRLS : dans les deux configurations, très au-dessus des 20 % autorisés.
   **Corriger A-010 ne suffira pas** à tenir le critère 17.
3. **L'atelier et la production ne mesurent pas le même produit** : ×4,0 sur le
   débit, ×4,2 sur le p95, à **un seul utilisateur**. Voir G43-001.

⚠️ **Honnêteté de la mesure** : les échantillons de la passe `c=1` sous RLS sont
petits (9 à 27 transactions en 40 s, parce que chaque transaction coûte près
d'une seconde). Un p95 sur 9 points est **le 9ᵉ point**, pas un percentile
robuste. L'ordre de grandeur et le sens de la dégradation sont solides ; les
pourcentages à l'unité près ne le sont pas, et je ne les présente pas comme tels.
Le protocole du §2 impose 40 s minimum **et** un nombre de transactions ≥ 200 par
passe pour rendre un p95 opposable.

---

## 4. L'édition concurrente — le verdict

**Il n'existe aucun mécanisme. Ni verrou optimiste, ni colonne de version, ni
`ETag`, ni `If-Match`, ni comparaison d'`updated_at`. Zéro occurrence, backend
et frontend confondus.** Voir **G43-005**, mesuré avec témoin positif et témoin
négatif.

---

## 5. Les verrous du planificateur

**28 tâches planifiées portent `withoutOverlapping()`. Aucune ne passe
d'argument. Toutes valent donc 1 440 minutes — 24 heures.** Voir **G43-006**.

---

## 6. `load-tests/` — l'état

Le répertoire contient **deux fichiers** : `audience-refresh.yml` (scénario
Artillery, 3 scénarios pondérés) et `LOAD-TEST-RUNBOOK.md`.

**Il n'a jamais tourné.** Voir **G43-007**.

---

## 7. Ce que la sérialisation coûte — **projection, pas mesure de charge**

> ⚠️ **Ceci est un calcul arithmétique à partir de mesures déjà faites. Ce n'est
> pas une mesure de charge.** Aucune requête n'a été envoyée en parallèle vers
> une couche HTTP pour l'établir. Les entrées du calcul sont : le fait mesuré
> qu'un serveur `php -S` sans workers traite **une requête à la fois** (A-010),
> et les temps de service mesurés des compteurs du hub.

### 7.1 Le raisonnement, en une ligne

Sur un serveur qui traite une requête à la fois, **le temps de service d'une
requête est exactement le temps pendant lequel aucun autre utilisateur n'est
servi**. Une requête de 17,5 s ne « ralentit » pas les autres : elle les **gèle**,
pendant 17,5 s.

### 7.2 Combien de temps une seule ouverture de console gèle les autres

| État du produit | Temps de service des compteurs | **Gel infligé à tous les autres utilisateurs** |
|---|---|---|
| **Sans** l'index `idx_companies_ws_counts`, cache Postgres froid, 2,8 M — *mesuré* | 17 504 ms | **17,5 s** |
| Idem, extrapolé à 4,29 M (facteur 1,53 déclaré par le rapport du 19/08) | ≈ 26 800 ms | **≈ 26,8 s** |
| **Avec** l'index, cache Postgres froid, 2,8 M — *mesuré par moi, p95, c=1* | 2 192 ms | **2,2 s** |
| Idem, extrapolé à 4,29 M | ≈ 3 350 ms | **≈ 3,4 s** |
| Recalcul différé de `Cache::flexible` (`defer()`) | même coût | **le même à nouveau** ‡ |

‡ `Repository::flexible` appelle `defer($refresh, …)` (vérifié :
`vendor/laravel/framework/src/Illuminate/Cache/Repository.php:659`). Le rappel
différé s'exécute dans la phase de terminaison **du même processus PHP**. Sur
`php -S` mono-processus, cette phase se déroule **avant que le processus
n'accepte la connexion suivante** : le recalcul « qui ne coûte rien à personne »
coûte, en réalité, exactement une fois de plus le même gel au visiteur suivant.
*Ce n'est pas un défaut de `CompteursHub`, qui est bien construit — c'est A-010
qui transforme un différé en attente.*

### 7.3 Dix utilisateurs qui ouvrent la console au même moment

C'est la lettre du critère 17. Le dixième arrivant attend que les neuf devant lui
soient servis, **avant que sa propre requête ne commence** :

| Hypothèse sur les neuf requêtes qui précèdent | Attente du dixième, avant sa propre requête |
|---|---|
| p50 mesuré du scénario mixte, couche base seule, RLS armée, 300 000 fiches (591 ms) | 9 × 0,591 = **5,3 s** |
| p95 du même scénario (1 413 ms) | 9 × 1,413 = **12,7 s** |
| Compteurs au volume de production, avec index, p95 (2 192 ms) | 9 × 2,192 = **19,7 s** |
| Compteurs au volume de production, **sans** index, froid (17 504 ms) | 9 × 17,5 = **157,5 s** |

**Et ce ne sont que les temps de la couche base.** La ligne de base HTTP mesurée
par le rapport du 18/08 est de **5,90 s p50** sur le poste de mesure — c'est-à-dire
que, sur ce poste, **le seul démarrage de Laravel** ajouterait 9 × 5,9 = **53 s**
d'attente au dixième arrivant. Sur la production (Linux, opcache), cette ligne de
base est « de l'ordre de quelques dizaines de millisecondes » selon le même
rapport — **valeur non mesurée**, et le protocole du §2 impose de la re-mesurer
avant tout verdict.

---

## 8. Ce qui est SAIN dans mon périmètre, et qu'aucun correctif ne doit casser

- **`App\Crm\Console\CompteursHub`** — `Cache::flexible` plutôt que `remember`
  *à dessein*, pour éviter que dix sessions ne déclenchent dix balayages à la même
  seconde ; **verrou borné à 30 s** avec la raison écrite au-dessus ; contexte de
  workspace **posé par le calcul lui-même**, parce que le rafraîchissement différé
  s'exécute après que le middleware a retiré la variable de session — sans quoi
  la requête aurait mis « total : 0 » en cache pour une heure, sans erreur nulle
  part. **C'est la seule pièce du dépôt qui raisonne juste sur la concurrence, et
  c'est la brique à réutiliser pour corriger G43-006.**
- **`ArbitrageController::lockedActivity`** — verrou de ligne `lockForUpdate()`,
  avec le motif écrit : « deux opérateurs (ou deux onglets) qui rattachent le même
  événement doivent produire un 409, pas deux fiches personne ». Le raisonnement
  est bon ; il lui manque une garde qui l'éprouve **en concurrence** (G43-008).
- **`Crm\BulkController`** — `lockForUpdate()` sur les lignes visées **et**
  `workspace_id` renseigné à l'insertion du pivot. C'est le contre-exemple exact
  de G43-004 : le patron correct existe, à quelques mètres du code fautif.
- **Le volume de référence** — 300 000 et 2 800 000 fiches, versionnés, rejouables,
  et `EXPLAIN` archivé. Sans lui, cet agent n'aurait rien pu mesurer.

---

## 9. Ce que je n'ai PAS pu vérifier, et pourquoi

1. **Le critère 17 sur la couche HTTP** — interdit par `D-010` tant qu'A-010 n'est
   pas soldé. C'est le motif d'existence du protocole du §2.
2. **La contention collecte ↔ console** (la collecte insère par centaines de
   milliers pendant qu'un opérateur lit) — aurait demandé de rejouer un lot de
   collecte en parallèle d'une charge console. Hors budget de temps. **C'est le
   scénario le plus proche de la réalité de production, et il reste à mesurer.**
3. **La concurrence de la couche queue / Horizon** — le Redis local est partagé
   par 8 autres piles (18 conteneurs relevés par `docker ps` au moment de la
   mesure). Toute mesure aurait été contaminée par le voisin, exactement comme
   `axion_crm_test` l'est pour les tests (B11-005).
4. **L'effet réel du cache `CompteursHub` sous dix sessions** — exige la couche
   HTTP. Étape 5 du protocole.
5. **La valeur de `CRM_DB_APP_ROLE_ENABLED` sur le conteneur de production** — la
   production est en lecture seule et je n'y ai pas d'accès `docker inspect`. Je
   m'appuie sur **B11-010**, qui l'a mesurée à `true`. **Toute la sévérité de
   G43-001 et de G43-004 dépend de ce fait** : s'il était faux, les deux
   tomberaient. Il doit être re-confirmé en P4, **sur le conteneur** (piège 18).
6. **La ligne de base HTTP réelle de la production** (Linux + opcache). Le rapport
   du 18/08 l'estime « de l'ordre de quelques dizaines de millisecondes » — c'est
   une estimation, pas une mesure, et le §7.3 en dépend.
7. **Le comportement au-delà de 10 sessions** (50, 100). Non demandé par le §29,
   non mesuré.
8. **`max_connections = 100`** sur la base locale : à 10 sessions je n'en ai pas
   approché la limite, mais **le pool de connexions de l'application n'a pas été
   examiné** — c'est un point de sérialisation potentiel distinct d'A-010, et il
   reste à auditer.

---

# CONSTATS

### [G43-001] La mesure de référence du §29 a été jouée sous BYPASSRLS ; sous la RLS de production, le planificateur perd l'index et la même recherche coûte 92 fois plus cher
- Sévérité      : S1 grave
- Domaine       : performance
- Référence     : main a3c42d6
- Emplacement   : `_REPORTS/2026-08-18_MESURE-PERFORMANCE-REFERENCE.md` §3 · `backend/database/migrations/2026_05_16_000008_enable_rls_policies.php` · `backend/.env:51`
- Constat       : les huit `EXPLAIN ANALYZE` qui servent de référence aux critères 1 et 17 du §29 ont été joués sous le rôle `axion` (BYPASSRLS, la configuration de l'atelier) ; sous le rôle `axion_app` (RLS en FORCE, la configuration de production selon B11-010), le planificateur abandonne l'`Index Scan` pour un `Parallel Seq Scan` et la recherche globale passe de **39 ms à 3 589 ms**.
- Preuve        : `04_PREUVES/agent-43/13_jit-sous-rls.txt` et `02_plan-liste-par-role.txt`.
  Même requête, même base `axion_crm_g43`, même instant :
  - rôle `axion` : `Index Scan using idx_companies_ws_updated_id`, `cost=0.42..289.16 rows=20`, **Execution Time 39,4 ms**
  - rôle `axion_app`, JIT actif (défaut) : `Parallel Seq Scan on companies`, `cost=0.00..13645.50 rows=1`, **Execution Time 3 589,1 ms**
  - rôle `axion_app`, `SET jit=off` : `Parallel Seq Scan`, **Execution Time 632,7 ms**

  Cause racine, lisible dans le plan : le prédicat RLS `(workspace_id)::text = NULLIF(current_setting('app.current_workspace_id', true), '')` n'est pas indexable et détruit l'estimation de sélectivité — le planificateur estime **`rows=1`** là où il en sortira 3 125. Sur la liste du hub, la même cause fait exploser le coût estimé de `1 030` à `217 897`, ce qui **franchit `jit_above_cost = 100 000`** : Postgres compile alors le plan, et ajoute **390,8 ms de JIT** (`Generation 120,5 ms · Optimization 19,1 ms · Emission 251,2 ms`) à chaque exécution.
  Effet agrégé sur le scénario complet, à **un seul utilisateur** : **5,59 tps / p95 337 ms** (`axion`) contre **1,40 tps / p95 1 413 ms** (`axion_app`) — `06_` et `08_`.

  **Comment je sais que la référence a été jouée sous `axion`** — trois faits concordants, aucun supposé : ① le mode d'emploi de rejeu du rapport (§5) donne `docker exec axion-crm-postgres psql -U axion …` ; ② `backend/.env:51` porte `CRM_DB_APP_ROLE_ENABLED=false`, donc l'API locale se connecte en `axion` (B11-010) ; ③ **surtout**, les plans publiés au §3 du rapport sont ceux que je retrouve **sous `axion` et sous lui seul** — `Index Scan using idx_companies_ws_updated_id` pour les listes, `Parallel Seq Scan` à 210 ms pour les compteurs. Sous `axion_app`, aucune des huit requêtes ne rend le plan publié.
- Témoin négatif: le contrôle est capable de voir un plan sain — c'est précisément ce qu'il rend sous `axion` (`Index Scan`, 39 ms) et sous `axion_app` avec `jit=off` (632 ms au lieu de 3 589). Les trois passes sont dans le même fichier de preuve, jouées à la suite.
- Impact        : **toute conclusion de performance de ce dépôt est bâtie sur une référence qui ne décrit pas le serveur qui tourne.** Le rapport du 18/08 conclut « le critère n° 1 est tenu avec une marge d'un ordre de grandeur » : sur la référence corrigée, la marge disparaît. Et l'erreur va dans le sens rassurant, ce qui est la pire direction.
- Reproduction  : `docker exec -e PGPASSWORD=axion_app_dev_only axion-crm-postgres psql -h 127.0.0.1 -U axion_app -d axion_crm_perf -c "SET app.current_workspace_id='20cd81e4-…';" -c "EXPLAIN (ANALYZE) <requête du §3 du rapport de référence>"`, puis la même sous `-U axion`.
- Correctif     : trois gestes, indépendants et de coût croissant.
  ① **Rejouer la mesure de référence sous `CRM_DB_APP_ROLE_ENABLED=true`** et adopter ce résultat comme référence (½ j) — sans cela, aucun des deux autres ne se mesure.
  ② **`SET jit_above_cost` élevé (ou `jit = off`) sur la connexion applicative** : gain mesuré **×5,7** sur la recherche globale, pour une ligne de configuration (1 h). Le JIT n'apporte rien à des requêtes qui rendent 20 lignes.
  ③ **Rendre le prédicat RLS indexable** — soit en le figeant dans une fonction `STABLE` marquée `LEAKPROOF` que le planificateur peut pousser dans l'index, soit en typant la comparaison en `uuid` des deux côtés plutôt qu'en `text`. Coût : 2 à 3 j, avec re-mesure obligatoire des 8 requêtes. **À traiter avant le lot 1 de P3** : mesurer la performance après avoir sorti la production de `php -S`, mais sur une référence d'atelier, referait la même erreur d'un cran plus haut.
- Statut        : ouvert

---

### [G43-002] À dix sessions, la couche base seule dégrade le p95 de 310 % : le critère 17 échouerait même si A-010 était corrigé
- Sévérité      : S1 grave
- Domaine       : performance
- Référence     : main a3c42d6
- Emplacement   : `backend/app/Http/Controllers/Api/Crm/ContactsHubController.php` (les 4 requêtes mesurées) · base `axion_crm_g43` (300 000 fiches)
- Constat       : sur la seule couche base, sans PHP ni HTTP — donc **sans la sérialisation d'A-010** — passer de 1 à 10 sessions simultanées porte le p95 du scénario console de **1 413 ms à 5 794 ms**, soit **+310 %**, quand le §29 n° 17 autorise **+20 %**.
- Preuve        : `04_PREUVES/agent-43/06_pgbench-c1-vs-c10-RLS.txt` (sortie brute) et `07_percentiles-c1-c10.txt` (percentiles calculés). Dispositif : `pgbench` 16.9, rôle `axion_app` (RLS armée comme en production), 4 scénarios pondérés 50/20/20/10 repris de `backend/database/perf/explain_reference.sql`, 40 s par passe, warmup 20 s non compté. Détail par requête : liste **+412 %**, recherche **+340 %**, timeline **+339 %**, compteurs **+67 %**.
- Témoin négatif: **le débit**. Si la mesure ne mesurait qu'un effet de volume et non la concurrence, le débit n'aurait pas bougé. Il passe de **1,40 tps à 4,55 tps** (×3,2) : les dix clients sont bien servis en parallèle, et la dégradation du p95 est bien celle de la contention, pas d'un artefact de comptage. Le même dispositif sous `axion` rend ×6,5 (5,59 → 36,36 tps) — le contrôle sait donc distinguer deux régimes de parallélisme.
  Contre-témoin de la sérialisation : A-010 a mesuré, sur la couche HTTP, un débit **plat** (escalier de 15 ms). La couche base parallélise, la couche HTTP non : les deux mesures se lisent ensemble.
- Impact        : **corriger A-010 ne suffira pas à tenir le critère 17.** Le plan de P3 place A-010 au rang 1 en écrivant « tout le reste se mesure dessus » — c'est exact, mais il faut savoir dès maintenant qu'une seconde marche l'attend derrière, et qu'elle est dans la base. Pour l'utilisateur : à dix opérateurs, la console rend une liste en 6 s là où elle en rend une en 1,2 s à un seul.
- Reproduction  : scripts dans `04_PREUVES/agent-43/scenarios/`. `pgbench -h 127.0.0.1 -U axion_app -d axion_crm_g43 -f s1_liste.sql@50 -f s2_compteurs.sql@20 -f s3_recherche.sql@20 -f s4_timeline.sql@10 -c 1 -j 1 -T 40 -n -l`, puis `-c 10 -j 10`.
- Correctif     : G43-001 ② et ③ d'abord — ils portent sur la même cause et le gain est mesuré. Ensuite seulement, re-mesurer : il est possible qu'une part importante de la dégradation à 10 sessions disparaisse avec le JIT, chaque session ayant payé 390 ms de compilation. Coût du seul geste ② : 1 h. **Ne pas dimensionner de machine avant d'avoir rejoué la mesure après ②.**
- Statut        : ouvert

---

### [G43-003] Les compteurs du hub au volume de production : p95 6,2 s à dix sessions, index posé
- Sévérité      : S2 défaut
- Domaine       : performance
- Référence     : main a3c42d6
- Emplacement   : `backend/app/Crm/Console/CompteursHub.php:148-153` · base `axion_crm_perf4m` (2 800 000 fiches) · index `idx_companies_ws_counts` (migration `2026_08_19_000001`) en place
- Constat       : l'agrégat des compteurs, **avec** l'index couvrant livré à l'étape 1a, coûte **p50 826 ms / p95 2 192 ms** à une session et **p50 2 181 ms / p95 6 169 ms** à dix — soit **+181 %** sur le p95.
- Preuve        : `04_PREUVES/agent-43/10_compteurs-2M8-c1-c10.txt`. `pgbench`, 30 s par passe, warmup 20 s. Débit 1,03 tps → 3,57 tps. Présence de l'index vérifiée dans la même sortie (`select indexname from pg_indexes … = idx_companies_ws_counts`).
- Témoin négatif: la même mesure sur la base de référence à 300 000 fiches rend p95 1 724 ms / 2 871 ms — l'écart entre les deux volumes prouve que le contrôle est bien sensible au nombre de lignes, et n'a donc pas mesuré une constante.
- Impact        : le rapport du 19/08 conclut, à juste titre, que le cache est le correctif principal et que le plancher de ≈ 1 à 1,5 s est « acceptable **une fois** ». **Cette mesure chiffre ce que « une fois » veut dire à dix sessions** : le premier arrivant après expiration paie 2,2 s à une session, **6,2 s** si neuf autres travaillent au même moment. La fenêtre de 5 min de `Cache::flexible` fait que cela se produit **au plus une fois toutes les cinq minutes par workspace** — c'est ce qui empêche ce constat d'être un S1.
- Reproduction  : `pgbench -U axion -d axion_crm_perf4m -f s2_compteurs.sql -c 1 -T 30 -n -l`, puis `-c 10 -j 10`.
- Correctif     : aucun geste nouveau **avant** d'avoir rejoué la mesure après G43-001 ②. Si le plancher reste au-dessus de 2 s après cela, la piste écartée à raison par le rapport du 19/08 (table de compteurs entretenue par déclencheur) redevient discutable — mais **seulement** avec une mesure de la contention d'écriture de la collecte, qui n'existe pas encore (§9 n° 2).
- Statut        : ouvert

---

### [G43-004] `POST /companies/tags/bulk` insère sans `workspace_id` : refusé par la RLS de production, accepté en local avec une ligne invisible
- Sévérité      : S1 grave
- Domaine       : backend
- Référence     : main a3c42d6
- Emplacement   : `backend/app/Http/Controllers/Api/CompanyTagsBulkController.php:120-134` (méthode `poser()`), route `backend/routes/api.php:193`
- Constat       : `poser()` construit les lignes du pivot `company_tag` avec `company_id`, `tag_id` et `assigned_by` **et rien d'autre** ; la colonne `workspace_id` est laissée à `NULL`, alors que la table porte une policy RLS en FORCE dont le `WITH CHECK` exige l'égalité avec le workspace courant.
- Preuve        : `04_PREUVES/agent-43/05_rls-insert-sans-workspace.txt`. Sous le rôle `axion_app` (RLS armée comme en production), la même transaction :
  - **avec** `workspace_id` : `INSERT 0 1`
  - **sans** `workspace_id` : `ERROR: new row violates row-level security policy for table "company_tag"`

  Et `04_PREUVES/agent-43/04_rls-company_tag-null.txt` : les 300 000 lignes `company_tag` du jeu de référence, toutes à `workspace_id IS NULL`, sont vues **300 000 fois** par `axion` et **0 fois** par `axion_app`.
- Témoin négatif: la première branche de la preuve est un témoin positif joué dans la même session, à la même seconde, sur la même table : l'insertion **avec** `workspace_id` passe (`INSERT 0 1`). Le refus n'est donc pas un défaut de droits ni de connexion. Le patron correct existe d'ailleurs dans le dépôt, à `backend/app/Http/Controllers/Api/Crm/BulkController.php:98-110`, qui renseigne bien `'workspace_id' => $workspaceId`.
- Impact        : selon B11-010, la production tourne avec `CRM_DB_APP_ROLE_ENABLED=true`. **La pose d'étiquettes en masse y rend donc une erreur**, alors qu'elle réussit en local et dans la suite de tests — nouvelle instance du défaut systémique S-3 (« l'atelier ne reproduit pas la production »), et nouvelle instance d'A-011 (les tests qui la couvrent tournent dans une configuration où le défaut n'existe pas). Le contrôleur documente pourtant, en 30 lignes, trois refus délibérés destinés à protéger la traçabilité RGPD — l'attention était là ; c'est la colonne de cloisonnement qui manque.
  Effet second, en local et dans toute base où la RLS n'est pas armée : la ligne est créée **avec `workspace_id` nul**, donc définitivement invisible le jour où la RLS sera armée. Le segment de campagne constitué la veille disparaît sans message.
- Reproduction  : `docker exec -i -e PGPASSWORD=axion_app_dev_only axion-crm-postgres psql -h 127.0.0.1 -U axion_app -d axion_crm_perf`, puis `SET app.current_workspace_id='…'; INSERT INTO company_tag (company_id, tag_id, assigned_by) VALUES (…);`
- Correctif     : ajouter `'workspace_id' => $workspaceId` (déjà résolu en tête de `__invoke()`) et `'assigned_at' => now()` au tableau de `poser()`. **Une ligne.** Puis une garde qui rougit **sur l'objet qui casse** : un test qui joue l'insertion sous le rôle applicatif avec la RLS armée, et non sous `axion`. Coût : 2 h avec la garde. ⚠️ **Chercher le même patron ailleurs** : tout `insertOrIgnore` sur un pivot cloisonné est suspect.
- Statut        : ouvert

---

### [G43-005] Aucun mécanisme ne détecte une édition concurrente : une saisie disparaît en silence, et les deux enregistrements répondent « succès »
- Sévérité      : S1 grave — **relevable en S0 par le chef de chantier** (voir Impact)
- Domaine       : backend
- Référence     : main a3c42d6
- Emplacement   : `backend/app/Http/Controllers/Api/CompaniesController.php:365-377` · les 11 routes `PUT` de `backend/routes/api.php` · `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php:1355-1360`
- Constat       : `PUT /companies/{company}` valide cinq champs puis appelle `$company->update($validated)` sans aucune condition sur l'état lu par le client ; `Model::performUpdate()` construit sa clause via `setKeysForSaveQuery()`, qui pose **`WHERE id = ?` et rien d'autre**. Aucune colonne de version, aucun `ETag`, aucun `If-Match`, aucune comparaison d'`updated_at` n'existe dans le dépôt.
- Preuve        : `04_PREUVES/agent-43/12_perte-de-mise-a-jour.txt`. Deux sessions `psql` **réellement simultanées** (deux processus lancés en parallèle), chacune lisant la fiche `id=100006`, attendant 2 s, puis écrivant :
  ```
  [A] A a lu : VALEUR INITIALE      [B] B a lu : VALEUR INITIALE
  [A] UPDATE 1                      [B] UPDATE 1
  valeur finale : PAUL (session B)
  ```
  Les deux écritures rendent `UPDATE 1`. La saisie de A a disparu. Aucune erreur, aucun avertissement, aucune entrée de journal.
  Et `04_PREUVES/agent-43/11_edition-concurrente.txt` : recherche de `if-match|etag|lock_version|version_column|optimistic` sur `backend/app` **et** `frontend/src` → **zéro occurrence** ; recherche d'un champ de concurrence dans les règles de validation des 11 `update()` → **zéro**.
- Témoin négatif: **deux témoins, tous deux joués.**
  ① *Le contrôle sait détecter le conflit quand la garde existe* : la même séquence, avec la seule condition `AND updated_at = <valeur lue>`, rend `UPDATE 1` pour A et **`UPDATE 0` pour B**, et la valeur finale est `MARIE`. La détection est donc à une clause de distance, et le dispositif de mesure est capable de la voir.
  ② *La recherche de code sait trouver un verrou quand il y en a un* : le même filtre trouve bien les deux `lockForUpdate()` (`ArbitrageController:257`, `Crm/BulkController:173`) et le `lock: ['seconds' => 30]` de `CompteursHub:85`. Un « rien trouvé » d'un contrôle aveugle aurait rendu la même chose sur ces trois-là.
  ③ Première tentative de mesure **rejetée par moi-même** : jouée sur `id = 1`, elle rendait `UPDATE 0` — non pas parce que le conflit était détecté, mais parce que la fiche n'existait pas (les identifiants du jeu de référence commencent à 100 006). La mesure a été refaite sur un identifiant relevé, pas supposé.
- Impact        : le §29 n° 17 exige explicitement qu'« une édition concurrente sur le même champ soit **détectée** et qu'**aucune valeur ne soit perdue** ». **Les deux moitiés sont fausses, et le seront encore le jour où A-010 sera corrigé** : la sérialisation de `php -S` ne protège de rien ici, elle ordonne simplement les deux écritures. Pour l'utilisateur : deux opérateurs qui corrigent la même fiche depuis la liste — le cas nominal d'un CRM à plusieurs — l'un des deux perd son travail sans le savoir, et croit l'avoir enregistré.
  **Pourquoi S1 et pas S0** : la grille met la perte de données en S0, et cette perte est silencieuse. Je le laisse en S1 parce qu'aujourd'hui **personne ne peut se trouver à deux sur la même fiche** — un seul compte existe, aucune session n'a jamais été ouverte (A-012), et l'écran d'édition lui-même reste à écrire. Le défaut est **armé, pas déclenché**. Il se déclenchera **le jour exact où le deuxième utilisateur entrera**, c'est-à-dire au moment où G4 sera corrigé. **Je le signale ici pour que l'arbitre puisse le remonter en S0 s'il retient le même raisonnement que pour `B15-001`, classé S0 sur ce motif précis.**
- Reproduction  : `04_PREUVES/agent-43/12_perte-de-mise-a-jour.txt` contient la commande complète, rejouable telle quelle sur une base jetable.
- Correctif     : verrou optimiste sur `updated_at`, qui existe déjà sur les 203 colonnes temporelles en `timestamptz` du schéma — **aucune migration n'est nécessaire**.
  ① la réponse de lecture porte `updated_at` (`ContactsHubController:270` le rend déjà) ;
  ② les `update()` acceptent un champ `expected_updated_at` **obligatoire** ;
  ③ l'écriture devient `->where('updated_at', $expected)->update(...)` et rend **409** si zéro ligne touchée ;
  ④ l'écran présente le conflit et propose de recharger.
  Coût : **1 j pour `PUT /companies/{id}`** (la seule route d'édition réellement implémentée aujourd'hui — les autres rendent 501), **3 à 4 j** pour les 11 routes et l'écran. **À faire avant l'ouverture des comptes (rang 2 de P3, G4)**, pas après : un verrou optimiste ajouté après coup exige de reprendre chaque écran déjà écrit.
- Statut        : ouvert

---

### [G43-006] Les 28 tâches planifiées prennent un verrou de 24 heures ; un processus tué gèle la tâche une journée entière, en silence
- Sévérité      : S2 défaut
- Domaine       : backend
- Référence     : main a3c42d6
- Emplacement   : `backend/routes/console.php` (28 appels) · `vendor/laravel/framework/src/Illuminate/Console/Scheduling/ManagesAttributes.php:145` · `CacheEventMutex.php:41-52`
- Constat       : les 28 appels à `withoutOverlapping()` de `routes/console.php` sont **tous** sans argument ; la signature du framework est `withoutOverlapping($expiresAt = 1440)` et `CacheEventMutex::create()` pose le verrou pour `$event->expiresAt * 60` secondes — soit **86 400 s, 24 h**, pour chacune des 28 tâches.
- Preuve        : `grep -rn "withoutOverlapping" backend/routes/console.php | wc -l` → **28** appels effectifs (2 lignes de commentaire exclues, comptées séparément) ; `grep -rEn "withoutOverlapping\(\s*[0-9]" backend/app backend/routes` → **aucune** occurrence avec argument ; `ManagesAttributes.php:145` : `public function withoutOverlapping($expiresAt = 1440)` ; `CacheEventMutex.php:45` : `->lock($event->mutexName(), $event->expiresAt * 60)`.
- Témoin négatif: le même filtre `withoutOverlapping\(\s*[0-9]` trouve bien un argument quand il y en a un — vérifié sur `Repository::withoutOverlapping($key, $callback, $lockFor = 0, …)` du framework, que le motif capture. Et la recherche d'un verrou **borné** dans le dépôt en trouve un : `CompteursHub.php:85`, `lock: ['seconds' => 30]`. Le contrôle est donc capable de distinguer un verrou borné d'un verrou par défaut.
- Impact        : trois tâches à cadence courte sont exposées de façon disproportionnée — `campaigns:start-scheduled` (**toutes les minutes**), `crm:flush-outbound` (**toutes les 5 minutes**, c'est la mini-outbox qui fait converger les oppositions RGPD nées dans la console vers le site) et `media:find-websites` (toutes les 30 min). Un conteneur `scheduler` redéployé, un OOM-kill, un `docker compose down` au mauvais instant : le verrou reste posé, `mutex->exists()` rend `true`, et la tâche est **sautée à chaque passage pendant 24 h**. Rien ne le signale — `withoutOverlapping` fait un `skip()`, et un run sauté n'est pas un run en échec. Pour `crm:flush-outbound`, cela signifie **288 passages sautés** et des oppositions RGPD qui ne convergent pas, sous un ordonnanceur parfaitement vert.
  ⚠️ Ce constat est **armé, pas déclenché** : les redéploiements sont fréquents et la fenêtre est étroite, mais le coût du diagnostic le jour où il tombe est élevé, précisément parce qu'il est muet.
- Reproduction  : arrêter le conteneur `axion-crm-scheduler` pendant l'exécution d'une tâche verrouillée, puis observer `php artisan schedule:list` et l'absence d'exécution sur 24 h. *Non joué par moi : cela aurait demandé d'arrêter un service partagé de l'atelier.*
- Correctif     : borner chaque verrou à un multiple raisonnable de la durée d'exécution attendue — `withoutOverlapping(5)` pour les tâches à la minute, `withoutOverlapping(60)` pour les tâches horaires, `withoutOverlapping(180)` pour les imports hebdomadaires longs. **Le raisonnement est déjà écrit dans ce dépôt**, à `CompteursHub.php:79-84`, et il est juste : « un processus tué entre la prise et le relâchement le laisserait posé pour toujours […] une panne muette, celle qui coûte le plus cher à diagnostiquer ». Il n'a simplement jamais été porté au planificateur. Coût : **2 h**, plus une garde qui rougit si un `withoutOverlapping()` sans argument réapparaît (règle 8 : c'est la même brique).
- Statut        : ouvert

---

### [G43-007] `load-tests/` n'a jamais tourné, et son mode d'emploi prescrit de charger la production avec le mot de passe du dirigeant
- Sévérité      : S2 défaut
- Domaine       : tests
- Référence     : main a3c42d6
- Emplacement   : `load-tests/LOAD-TEST-RUNBOOK.md` · `load-tests/audience-refresh.yml`
- Constat       : le répertoire contient un scénario Artillery et son mode d'emploi, tous deux introduits par un unique commit du 2026-05-17 et jamais retouchés depuis ; rien n'indique qu'ils aient jamais été exécutés, et le mode d'emploi donne comme geste n° 1 un `curl` de connexion contre `https://app.axion-crm-pro.com` avec l'adresse et le mot de passe du dirigeant, puis un run contre cette même cible.
- Preuve        : `git log --oneline -- load-tests/` → **une seule ligne**, `437520c test(load): Artillery scenario + runbook load test API (H5 commit 2)` ; `load-tests/results/` **n'existe pas**, alors que le runbook §« Quoi enregistrer » impose d'y déposer chaque run ; `grep -rn "artillery" --include=package.json .` → **aucune occurrence** (les deux manifestes du dépôt sont `frontend/package.json` et `workers/package.json`) ; `grep -rn "load-test\|artillery" .github/` → **aucune occurrence** ; `grep -rn "load-tests" .gitignore` → **aucune occurrence**, alors que le runbook affirme que les résultats sont « gitignored sauf le runbook ».
- Témoin négatif: `git log` sur le même répertoire trouve bien le commit d'introduction, et `grep -rn "artillery"` trouve bien les 5 occurrences internes à `load-tests/` — le contrôle n'est donc pas aveugle au mot cherché ; il ne le trouve nulle part **ailleurs**. Piège 23 paré : la recherche des manifestes nomme des fichiers `package.json` explicitement, et les deux qui existent ont bien été lus.
- Impact        : quatre défauts distincts, du plus grave au moins grave.
  ① **Le mode d'emploi envoie la charge vers la production** (`--target https://app.axion-crm-pro.com`), avec pour seule protection une consigne de ne pas le faire « entre 9 h et 18 h ». Sur un serveur qui traite **une requête à la fois** (A-010), un run à 20 req/s pendant 300 s **rendrait le produit indisponible**, à l'heure qu'on voudra.
  ② **Ses garanties décrivent un serveur qui n'existe pas** : « constitue cache PHP-FPM », « saturation FPM par défaut (10 workers) », « CPX42 = 8 vCPU partagés ». La production sert par `php -S`, **un processus** — nouvelle instance de la mesure de performance de l'étape 0 qui « conclut sur une production php-fpm qui est en réalité `php -S` » (défaut systémique S-2). *La classe de machine annoncée (CPX42) n'a pas été vérifiée par moi : la production est en lecture seule et je n'y ai pas d'accès `docker inspect`. Le défaut porte sur le serveur HTTP, qui est mesuré (A-010), pas sur la machine.*
  ③ **Sa garde mesure le mauvais objet** : `ensure: p95: 800` est commenté « KO si p95 **list companies** > 800ms », alors qu'Artillery applique `ensure.p95` au **p95 global, tous scénarios confondus** — dont `GET /tags`, dont le runbook attend lui-même 50 ms. Le p95 d'une petite table tirerait la moyenne vers le bas et masquerait une liste dégradée. **Instance n° 11 d'A-011.**
  ④ **Il ne mesure pas le produit du §29** : ses trois scénarios visent `/companies`, `/audiences/preview` et `/tags` — routes qui existent bien (`routes/api.php:116, 200, 185`), mais qui relèvent du périmètre de collecte. Aucun des endpoints des critères 1, 4 et 17 (`/crm/contacts-hub`, `/crm/contacts-hub/counts`, `/crm/persons/{key}/timeline`, `/search`) n'y figure.
- Reproduction  : lecture des deux fichiers ; commandes de preuve ci-dessus.
- Correctif     : ne pas jeter le répertoire — **l'outil est le bon** et le protocole du §2 de ce rapport peut s'y couler presque tel quel. Trois gestes : ① remplacer les trois scénarios par les huit requêtes du §2.3 ; ② remplacer `ensure.p95` global par un `ensure` **par scénario** (Artillery le permet via `expect`/plugin `metrics-by-endpoint`), sans quoi la garde restera fausse ; ③ retirer du runbook toute cible de production et tout identifiant, et y écrire le préalable bloquant du §2.0. Coût : **1 j**, à faire **en même temps que le lot 1 de P3** (sortie de `php -S`), puisque c'est le premier moment où l'outil aura un sens.
- Statut        : ouvert

---

### [G43-008] Aucune garde du dépôt n'éprouve la concurrence ; les deux verrous pessimistes du produit ne sont couverts que séquentiellement
- Sévérité      : S2 défaut
- Domaine       : tests
- Référence     : main a3c42d6
- Emplacement   : `backend/tests/Feature/Crm/ConsoleV2Test.php:510-517` · `backend/app/Http/Controllers/Api/Crm/ArbitrageController.php:248-264` · `backend/app/Http/Controllers/Api/Crm/BulkController.php:169-175`
- Constat       : la recherche de `concurren|simultan` dans les 780 tests du dépôt ne rend qu'**un commentaire**, sans rapport avec la concurrence du produit ; le seul test qui attend un `409` (`arbitrage : rattacher deux fois répond 409`) joue **deux appels HTTP l'un après l'autre dans la même session**, ce qui éprouve l'idempotence et jamais le verrou.
- Preuve        : `grep -rniE "concurrent|simultan" backend/tests/` → une seule ligne, `tests/Unit/Legal/MentionsLegalesScraperServiceTest.php:111`, qui parle d'un pool HTTP. `grep -rlniE "409|conflict" backend/tests/` → 2 fichiers. Lecture de `ConsoleV2Test.php:510-517` : les deux `postJson` sont séquentiels.
- Témoin négatif: le même filtre trouve bien les tests qui existent sur d'autres sujets — il rend 2 fichiers sur `409|conflict` et il a bien localisé le test d'arbitrage. Il n'est donc pas aveugle ; il n'y a rien d'autre à trouver.
- Impact        : `ArbitrageController::lockedActivity()` porte un commentaire qui énonce précisément le cas à protéger — « deux opérateurs (ou deux onglets) qui rattachent le même événement doivent produire un 409, pas deux fiches personne ». **Le raisonnement est juste, le verrou est bien posé, et rien ne prouve qu'il tient.** Le test vert qui l'accompagne mesure une propriété différente (rejouer deux fois de suite), et **resterait vert si le `lockForUpdate()` était retiré** — un `SELECT` sans verrou suivi d'un test d'état produit exactement la même sortie en séquentiel. C'est le défaut systémique S-2 : la garde est irréprochable et mesure le mauvais objet. **Douzième instance d'A-011.**
- Reproduction  : retirer `->lockForUpdate()` de `ArbitrageController:257` et relancer `ConsoleV2Test` : le test « rattacher deux fois répond 409 » reste vert. *Non joué par moi — je ne modifie pas le code du dépôt pendant l'audit ; c'est le geste de vérification à confier à P4.*
- Correctif     : une garde de concurrence réelle, sur le patron du témoin positif déjà joué au constat G43-005 : deux connexions distinctes, une lecture chacune, une temporisation, puis deux écritures — et l'assertion que **l'une des deux est refusée**. Pest sait le faire sans processus séparés en ouvrant deux connexions `DB::connection()` et en pilotant les transactions à la main. Coût : **½ j** pour les deux verrous existants, et c'est le même harnais qui servira ensuite à éprouver le verrou optimiste de G43-005.
- Statut        : ouvert

---

## 10. Nettoyage

La base `axion_crm_g43` (606 Mo, copie jetable de `axion_crm_perf`, `company_tag`
réparé pour que la mesure sous RLS soit honnête) est **laissée en place** pour que
les mesures soient rejouables en P4. Elle se supprime par
`docker exec axion-crm-postgres psql -U axion -d postgres -c "DROP DATABASE axion_crm_g43"`.
`axion_crm_perf` et `axion_crm_perf4m` **n'ont pas été modifiées** : toutes les
écritures de test ont été jouées dans des transactions annulées ou sur la copie.
