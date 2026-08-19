# Passe 2 — contre-vérification adversariale

> **Ouvert le 2026-08-19.** Ce document n'existait pas jusqu'ici, et son absence était le premier
> manque signalé par le rapport final : l'audit s'était arrêté à la fin de P2 avec **P3, P5, P6 et
> P7 non faites**. Il s'ouvre ici.

---

## 0. Ce que cette passe est, et ce qu'elle n'est pas

Le mandat exige **trois passes** : une vérification, une **contre-vérification adversariale
complète**, puis une **troisième passe à regard neuf**. La règle 7 de la doctrine en donne le sens :
*celui qui réalise ne vérifie jamais sa propre pièce.*

**Cette passe n'est donc pas une relecture.** Relire, c'est chercher si un raisonnement se tient.
Contre-vérifier, c'est **essayer de faire tomber le constat par la mesure**, en partant du principe
qu'il est faux jusqu'à preuve du contraire. Les deux exercices ne trouvent pas les mêmes choses.

**Elle s'applique en priorité à ce qui a été mesuré une seule fois, par un seul agent, sur un seul
banc.** Un défaut trouvé deux fois indépendamment (il y en a **sept** au registre) est déjà
contre-vérifié ; le rare est plus suspect que le répété.

**Et elle s'applique à moi.** La moitié des erreurs corrigées dans ce dossier sont les miennes :
sept auto-corrections consignées, dont trois portent sur **le même chiffre**. Une passe adversariale
qui épargnerait le chef de chantier ne vaudrait rien.

---

## 1. Les objets de la passe, par ordre de gravité

| # | Objet | Pourquoi lui | État |
|---|---|---|---|
| **1** | **Les 4 gardes réécrites par l'agent 35** sur `fix/a35-authentification` | Il a écrit les correctifs **et** déclaré ses propres gardes vertes. C'est précisément ce que la règle 7 interdit. **C'est moi qui ai déclaré cette branche bloquée là-dessus** ; la laisser ainsi sans agir serait un blocage de confort | **agent lancé** |
| **2** | **Le décompte S1** — 132 constats jamais dédoublonnés | Le §4 de `02bis` ordonne P3 ; **on ne peut pas ordonner ce qu'on n'a pas compté**. Trou signalé par le recomptage S0 lui-même | **agent lancé** |
| **3** | **Le décompte S0** | Annoncé 12, puis 16, puis 26, puis 27 — **trois fois trop bas** | ✅ **fait**, §2 ci-dessous |
| 4 | **G6 — « l'autorisation n'existe pas »**, 5 S0 | Le groupe qui commande tous les autres, mesuré par **un seul agent** (36) | à faire |
| 5 | **G7 — les temps de l'agent 41** | Le plus gros chiffre de l'audit (3 min 08) repose sur **un seul banc**, et l'agent le déclare lui-même **plancher** | à faire |
| 6 | **Les 15 cas du patron `A-011`** | Le patron le plus structurant du dossier. S'il est vrai, il est très grave ; s'il est sur-appliqué, il décrédibilise le reste | à faire |
| 7 | **Ce que le dossier déclare SAIN** (`02bis` §5) | 🔑 **Personne n'a contre-vérifié les bonnes nouvelles.** Un audit qui ne vérifie que ses accusations est un audit à charge | à faire |

> **Le n° 7 mérite d'être dit.** Onze passes de mesure ont cherché ce qui casse. **Aucune n'a
> cherché si ce qui tient, tient vraiment.** C'est un angle mort de méthode, pas seulement un reste
> de travail : un « point sain » non contre-vérifié est exactement l'endroit où un correctif de P3
> ira casser quelque chose sans que rien ne rougisse.

---

## 2. Résultat n° 1 — le décompte S0 : **29, et non 26, 27 ou 32**

**Objet attaqué** : mon propre tableau, `02bis` §1 bis.
**Déclencheur** : l'agent 35 annonce **32** en clôture ; mon dossier annonçait **26** et **27**.
J'ai refusé d'absorber son chiffre sans mesurer, et refusé de défendre le mien sans recompter.

**Deux erreurs trouvées dans mon tableau** :

1. La ligne « Isolés » comptait **6** défauts S0 dont **`A08-008`** — vérifié à `02_CONSTATS.md:1597`,
   **il est S1**. *Un S1 comptait parmi les S0.* Retiré, la somme retombe sur 26 : **c'était la
   ligne qui était fausse, pas le total.**
2. Le tableau **datait d'avant trois rendus** — `G41-001`, `G41-002`, et le second chemin de
   `F35-002`. Les deux premiers forment **un groupe entier absent** : `G7 — la base ne tient pas le
   volume`.

**→ 29 défauts S0 distincts, ouverts, vrais pour la production.**

**Et l'écart avec l'agent 35 n'est pas un désaccord** : il comptait des **identifiants** (36
étiquettes, moins les alias et le réfuté → 32), je compte des **défauts**. Sept ont été trouvés
deux fois, par deux agents, sur deux bancs : **deux preuves, un seul correctif.**

> **Règle retenue** : **29 pour ordonner P3** — un défaut, un correctif. **36 pour juger la solidité
> du registre** — parce que sept redécouvertes indépendantes sont la meilleure nouvelle qu'un
> registre puisse porter.

**Ce que ce résultat dit de la méthode, et c'est le plus important** : la cause des trois
sous-évaluations n'est pas arithmétique. C'est qu'**un tableau de synthèse ne se rouvrait pas** à
l'arrivée de nouveaux rendus. C'est `A-013`, le défaut de clôture que ce dossier dénonce —
**reproduit une troisième fois dans le document qui le dénonce.** Le §1 bis porte désormais **sa
méthode avant son chiffre**, pour qu'un tiers puisse le recompter sans me croire.

---

## 4. Résultat n° 2 — la première contre-vérification d'une **bonne nouvelle** : elle tient

**Objet attaqué** : `02bis` §5, la ligne *« La suite de tests backend — 780 tests, 6 503 assertions,
PHPStan niveau 8 `[OK]`, et **zéro exclusion silencieuse** »*.

**Pourquoi celle-là d'abord** : c'est la bonne nouvelle **la plus portante** du dossier. Tout le
plan de correction de P3 suppose qu'on dispose d'un filet pour savoir si un correctif casse quelque
chose. Si ce filet est troué, **P3 se fait à l'aveugle** — et c'est le genre d'affirmation
rassurante que `A-013` désigne comme le défaut de clôture typique. J'avais d'ailleurs déjà été pris
une fois sur cette ligne exacte : j'y avais écrit *« la CI backend, bloquante et requise »*, ce qui
contredisait trois constats de mon propre dossier.

**Comment je l'ai attaquée** : par l'angle que le contrôle initial n'avait pas pris.
Le mandat de la CI n'exécute **pas** `phpunit.xml` — le job `backend` de `ci.yml:195` lance
`php vendor/bin/pest --configuration **phpunit-ci.xml**`. *Un contrôle d'exclusions mené sur le
fichier que la CI n'ouvre pas serait le seizième cas du patron `A-011`.*

**Mesuré** :

| Ce qui est affirmé | Mesure | Verdict |
|---|---|---|
| 0 exclusion dans la configuration | `phpunit.xml` **et** `phpunit-ci.xml` : `0 <exclude>`, `0 @group`, `0 #[Group]` | ✅ **vrai sur les deux fichiers** |
| 0 saut silencieux | **1 seul** `markTestSkipped` dans tout `tests/` — `NeDoitPasRegresserTest.php:169` | ✅ **et c'est un contre-exemple parfait** : il nomme le binaire absent, où le contrôle tourne pour de vrai, et écrit *« un `skip` est un aveu, pas une victoire »*. **L'inverse d'un saut silencieux** |
| 0 `->skip()`, `->todo()`, `->only()` | vérifié, **0 des trois** | ✅ |
| La quarantaine est levée | **les 23 fichiers de `QUARANTAINE.md` sont TOUS présents dans `tests/`** — vérifié un par un | ✅ **et plus fort que l'énoncé** : l'en-tête dit « réparés **ou supprimés** » ; en fait **23 réparés, 0 supprimé** |
| Seule différence entre les deux configs | `executionOrder` : `random` en local, `default` en CI | ✅ conforme à ce qui est écrit |

**→ La bonne nouvelle tient. Je la confirme, par un chemin que personne n'avait pris.**

### Ce que l'attaque a rapporté quand même

`executionOrder="default"` en CI n'est pas neutre, et le fichier l'assume : *« Deux exécutions du
MÊME commit avaient donné **262 verts puis 48 rouges** — du couplage entre tests. »* Le couplage
**n'a pas été réparé : il a été contourné par l'ordre.** La porte tourne donc dans l'ordre où elle
passe, et l'ordre aléatoire qui le débusquerait **ne garde rien**.

⚠️ **Et voici l'honnêteté que cette passe se doit** : ce n'est **pas** un constat neuf. C'est
`H44-011` (S2), déjà au registre — et la comparaison des deux fichiers `phpunit` y figure déjà
(`02_CONSTATS.md:1004-1005`). **Mon attaque a redécouvert un constat existant, elle ne l'a pas
trouvé.** C'est un résultat de moindre valeur, et je le dis plutôt que de le présenter comme une
trouvaille.

> **Ce que j'en retiens pour la suite de la passe** : contre-vérifier une bonne nouvelle **coûte peu
> et rapporte deux fois** — soit elle tombe, soit elle devient opposable. Ici elle est devenue
> opposable : on peut désormais écrire *« la suite est saine »* en sachant que c'est vrai **sur le
> fichier que la CI ouvre réellement**, ce qui n'était pas établi. Cela ne change rien au fait que
> **son câblage**, lui, reste troué (`F38-002`, `A08-005`, `H44-003`) : *la suite est saine, la
> porte ne l'est pas, et les deux ne se confondent pas.*

---

## 5. Résultat n° 3 — la tension HMAC du dossier : **les deux affirmations tiennent**, et le défaut est ailleurs

**Objet attaqué** : une **contradiction apparente** que le dossier portait sans la voir.
`02bis` §5 déclare le canal HMAC **exemplaire** — *« le patron à copier »*. `F37-001` (S0) déclare
un canal HMAC **forgeable en production**. *L'un des deux devait être faux.*

**Mesuré — ce sont deux canaux distincts, et la contradiction n'existe pas** :

| Route | Contrôleur | Vérification | Secret vide | Rejeu |
|---|---|---|---|---|
| `/internal/site-sync` · `/site-sync/gdpr` | `SiteSyncController` · `SiteGdprController` | **classe durcie `HmacSignature`** | 🟢 **`return false`** — *fail-closed* | 🟢 horodatage **dans** la signature, fenêtre bornée |
| `/internal/scraper-result` | `ScraperResultController:37-41` | **réimplémentée à la main** | 🔴 **aucune garde** — *fail-open* | 🔴 **aucun horodatage** : une requête interceptée rejouable **indéfiniment** |

✅ **`02bis` §5 tient** : le canal exemplaire est bien `site-sync`, et il l'est vraiment — la signature
est vérifiée **avant** le drapeau, le secret vide **ferme** la porte, l'horodatage est **dans** le
corps signé. **Rien à rouvrir.**
✅ **`F37-001` tient** aussi, et se confirme par un second chemin statique : `WORKER_INTERNAL_HMAC_SECRET`
est **vide dans les trois `.env` du dépôt** (`.env`, `.env.example`, `backend/.env`).

### 🔴 Constat neuf — `P5-HMAC-001` (S2) : **le dépôt documente le canal troué comme étant le patron de référence**

Deux commentaires, écrits par deux mains différentes, désignent au développeur suivant **le mauvais
des deux canaux** :

1. `routes/api.php:310` — `/site-sync` est signée *« **(même patron que scraper-result)** »*.
   **C'est faux, et à l'envers** : `site-sync` emploie la classe durcie ; `scraper-result` est la
   version faible.
2. `app/Support/HmacSignature.php`, docbloc — *« **Reprend le patron déjà en place sur
   `POST /internal/scraper-result`** — le seul canal machine authentifié du CRM »*.
   **La classe écrite pour corriger le défaut présente le code défectueux comme son ancêtre**, et
   le qualifie de *seul canal machine authentifié*.

> **C'est la mécanique de propagation du défaut, prise sur le fait.** `F37-001` n'est pas un
> accident isolé : c'est ce qui arrive quand deux commentaires pointent une porte fail-open comme
> « le patron ». Le prochain canal machine-à-machine sera écrit en copiant ce que ces lignes
> désignent. **Corriger `F37-001` sans corriger ces deux commentaires, c'est réparer la fuite et
> laisser le plan.**
> *Correctif : deux lignes de commentaire, et faire passer `ScraperResultController` par
> `HmacSignature::verify()` — la classe existe déjà, elle est testée, elle est bonne.*

### ⚠️ Et une hypothèse à moi, poursuivie puis **abandonnée** — elle méritait d'être vérifiée, elle est fausse

J'ai cru tenir plus grave. `ScraperResultController:37` lit le secret par **`env()` brut**, et
`WORKER_INTERNAL_HMAC_SECRET` **n'a aucune entrée dans `config/`** — seule occurrence du dépôt.
Or `infra/docker/entrypoint-prod.sh:41-48` exécute **`config:cache` au démarrage du conteneur**, et
le fichier avertit lui-même : *« sous config mise en cache, `env()` retourne NUL »*. J'en ai déduit
que l'endpoint serait forgeable **même avec un secret correctement posé**.

**Vérifié, et c'est non.** `docker-compose.yml` injecte le `.env` par **`env_file:`**, donc les
variables sont dans l'**environnement du processus** — `env()` les lit encore sous config mise en
cache. Le piège du 2026-08-14 vise le `.env` lu par dotenv, pas `env_file`. **Mon hypothèse
n'ajoute aucun mode de défaillance ; `F37-001` reste exactement ce qu'il était.**

*Ce qui subsiste, et que je porte en S3 (`P5-HMAC-002`) : ce secret est **le seul** du dépôt sans
entrée `config/`. Il fonctionne aujourd'hui **par la grâce d'un détail de `docker-compose`**. Le
jour où quelqu'un passe la production en `environment:` explicite ou en secrets Docker, il tombe à
vide — **et il tombera en silence, puisque le contrôle est fail-open.***

> **Pourquoi j'écris une hypothèse morte plutôt que de l'effacer** : elle a coûté quatre mesures, et
> le prochain qui lira `env()` + `config:cache` dans ce dépôt refera le même raisonnement. La
> réponse mérite d'être archivée avec la question. *Règle 3 : le témoin négatif est un livrable.*

---

## 6. Résultat n° 4 — le pire constat du dossier, attaqué : **il tient, et il est pire qu'écrit**

**Objet attaqué** : `B15-002` / dixième cas de `A-011` — *« `AntiReinsertionTest` est vert et mesure
le mauvais objet »*, et le constat `B15-001` (S0) qu'il laisse passer : *« une personne effacée par
la console REVIENT au vivier à la candidature suivante »*.

**Pourquoi celui-là** : c'est l'accusation la plus lourde de tout l'audit — une garde qui
*consacre par une assertion* le réglage exact qui fait revenir une personne effacée. Si elle est
fausse, c'est ma faute la plus grave. **Elle méritait d'être attaquée avant d'être publiée.**

**Méthode** : entièrement **statique**, sans lancer un test — un autre agent mesurait en parallèle
sur la pile locale, et le serveur PHP est mono-processus (`A-010`). *Contrainte assumée : ce qui
suit est prouvé par le code et le schéma, pas par une exécution.*

### La chaîne, bout en bout

| # | Où | Ce qui se passe |
|---|---|---|
| 1 | `GdprErasureService:91` | l'effacement **par la console** appelle `dedup->addOptOut($email, $phone, source: 'gdpr_erasure')` |
| 2 | `DeduplicationService:263` | l'insertion dans `opt_out` **ne pose AUCUNE clé `scope`** — et la méthode **n'a pas de paramètre `scope`** |
| 3 | migration `2026_08_14_000004`, l. 3 | `ALTER TABLE opt_out ADD COLUMN scope TEXT NOT NULL **DEFAULT 'business'**` → la ligne atterrit en **`business`** |
| 4 | `SiteSyncIngestService:112-113` | à la candidature suivante, `oppositionScope()` rend **`vivier`**, et `hasOpposed()` interroge `->where('scope', **'vivier'**)` |
| 5 | — | **aucune correspondance. La personne revient au vivier.** |
| 6 | `AntiReinsertionTest:45` | `->and($ligne->scope)->toBe(**'business'**)` — *« La garde du funnel filtre sur ce scope : sans lui, elle ne voit rien. »* |

**→ `B15-001` et `B15-002` sont CONFIRMÉS, par un chemin que le registre ne nommait pas.**
La garde n'échoue pas à voir le défaut : **elle l'inscrit en assertion.** Elle est verte parce
qu'elle vérifie que l'effacement écrit bien `business` — c'est-à-dire exactement la valeur qui
empêchera la garde du vivier de la trouver. *Elle mesure le funnel de scraping, qui est protégé ;
elle ne touche jamais le chemin de la candidature, qui ne l'est pas.*

### 🔑 Et voici ce que l'attaque a trouvé de neuf, qui rend le constat plus grave

**Ce défaut exact a déjà été trouvé, compris et réparé dans ce dépôt — sur la porte d'à côté.**
`SiteSyncIngestService:455-470` le raconte lui-même, en toutes lettres :

> *« l'opposition était inscrite en `scope = business` → `hasOpposed()`, qui interroge
> `scope = vivier` pour une candidature, ne la voyait pas : **la fiche revenait au vivier au dépôt
> suivant** »* — **constaté en E2E le 2026-08-17.**

Et le correctif est là, bon, soigné : `oppositionScope()` exige **deux** confirmations concordantes
(le `payload.scope` déclaré **et** le `subject_ref`), pour qu'un émetteur compromis ne choisisse pas
son univers d'atterrissage.

> **Le savoir existait. Le correctif existait. Il n'a simplement pas été porté à la seconde porte.**
> Le chemin *site → CRM* est réparé ; le chemin *console → CRM*, qui écrit par
> `DeduplicationService::addOptOut()` **sans paramètre `scope`**, ne l'a jamais été.
> *Ce n'est donc pas un défaut qu'on n'avait pas vu : c'est un défaut qu'on avait vu, nommé, daté et
> réparé — d'un seul côté.* **C'est la meilleure illustration de `A-013` que ce dossier ait
> produite : le problème n'est pas de mesurer, il est de clore.**

**Correctif, et il est petit** : donner un paramètre `scope` à `addOptOut()`, le faire poser par
`GdprErasureService` selon l'univers de la personne effacée — **ou**, plus sûr, écrire **les deux
lignes** (`business` **et** `vivier`) sur un effacement de la console, puisqu'un effacement RGPD
n'est pas une désinscription thématique. **Et réécrire `AntiReinsertionTest` pour qu'il parte d'une
candidature, pas du funnel** — sinon la garde restera verte par-dessus le correctif.

⚠️ **Limite déclarée** : chaîne prouvée **statiquement**. Elle doit être rejouée en exécution — un
effacement console, puis une candidature — avant d'être portée comme close. *Le test qui le prouvera
est exactement celui qui manque.*

---

## 7. Résultat n° 5 — 🔴 **la faille du 19 août est réarmable en un clic. Vérifié, et refermé.**

**Origine** : signalé par l'agent de consolidation S1, qui proposait de reclasser `F38-007` + `F40-007`
de S1 en S0. **J'ai vérifié avant d'arbitrer. Il a raison, et c'est plus large que ce qu'il décrit.**

### Ce qui est mesuré

`.github/workflows/diag-website-status.yml` — déclenchement **`workflow_dispatch` manuel**, pas de
cadence — exécute en SSH sur la production :

```
docker compose up -d          # ← aucun -f, aucun COMPOSE_FILE
```

Sans overlay, `docker compose` ne lit que `docker-compose.yml`, qui publie
**`55432:5432`** (l. 23) et **`56379:6379`** (l. 44).
Le correctif existe : **`ports: !override []`** dans `docker-compose.prod.yml` (l. 111, 147, 156).
**Il n'est chargé que par l'overlay — que ce workflow ne charge pas.**

Au bout du port ainsi rouvert : le mot de passe Postgres **écrit dans ce dépôt PUBLIC**, sur un rôle
**SUPERUSER + BYPASSRLS**, et un **Redis sans aucun mot de passe** — `ci.yml:60-73` le documente
mot pour mot. Sur une base qui porte **1 319 567 personnes physiques**.

**Et le workflow fait une seconde chose que le constat ne disait pas** : avant de relancer la pile,
il **réinscrit une clé SSH dans `/root/.ssh/authorized_keys`**. Un dispatch rouvre donc **les deux
portes à la fois** : la base et la racine.

### ⚖️ Arbitrage — **`F38-007` passe S1 → S0** (`D-019`)

L'échelle ne laisse pas le choix : c'est **le défaut qui a été la faille du 19 août**, encore
atteignable. Un défaut « déjà arrivé et encore atteignable » n'est pas grave, il est **bloquant**.
`F40-007` (le mot de passe public) reste **S1** : il n'est dangereux que par ce chemin, et le
refermer suffit à le neutraliser — mais il devra être **tourné**, et cela n'appartient qu'à Will.

### 🔑 Le troisième cas du même motif, dans la même journée

`deploy-direct-ssh.yml:134-149` **documente ce piège exact**, trouvé et corrigé le **2026-08-16** :
*« Jusqu'ici ce script lançait `docker compose` SANS `-f` »*. Le correctif y est excellent, et son
commentaire explique même pourquoi `COMPOSE_FILE` vaut mieux que des `-f` recopiés. **Il n'a pas été
porté à la porte voisine.**

> C'est le **troisième cas identique en une seule passe** :
> 1. **HMAC** — classe durcie écrite, endpoint faible laissé en place *et désigné comme le patron* ;
> 2. **`opt_out.scope`** — réparé sur le chemin du site, jamais sur celui de la console ;
> 3. **`COMPOSE_FILE`** — posé dans le workflow de déploiement, absent des trois autres portes.
>
> **Le défaut caractéristique de ce dépôt n'est pas de rater un problème. C'est de le résoudre sur
> l'exemplaire qu'on a sous les yeux, et de ne jamais balayer les frères.** C'est `A-013` sous sa
> forme opérationnelle, et c'est la conclusion la plus utile de cette passe.

### ✅ Ce que j'ai corrigé — et le balayage que le dépôt n'avait pas fait

| Fichier | État avant | Correctif |
|---|---|---|
| `.github/workflows/diag-website-status.yml` | `up -d` **sans overlay** → rouvre les deux ports | ✅ `export COMPOSE_FILE=…prod.yml` posé, avec la raison écrite en clair |
| `infra/runbooks/04-restore-dr.md` | *« git clone + `docker compose up -d` »* — **le runbook de reprise après sinistre**, exécuté sur une machine **neuve**, dans l'urgence, quand personne ne vérifie | ✅ l'export rendu **obligatoire et explicite**, + renvoi à la garde de vérification |
| `infra/runbooks/03-site-down.md` | `up -d --force-recreate api caddy` sans overlay — remet la production sur la cible `dev` **et son montage qui masque le `vendor`** (3 mois de retard mesurés le 16/08), **en pleine panne** | ✅ export ajouté avec la mesure citée |
| `Makefile:14` | `docker compose up -d` | ✅ **légitime** — cible de développement local, le fichier de base est le bon |
| `docker-compose.staging.yml` | — | ✅ **sain, et bien fait** : `ports: !override []` partout, et son en-tête **nomme la faille du 19/08**. Rien à reprendre |

*YAML revalidé après correctif.*

### 🔴 Seizième cas de `A-011` — `P5-PORTS-001` (S1) : **les deux gardes nées de la faille ne pouvaient pas la voir**

| Garde | Ce qu'elle mesure | Pourquoi elle rate ce cas |
|---|---|---|
| `ci.yml:88-131` | la **fusion statique** des fichiers compose | Elle vérifie que l'overlay **ferme** les ports. Elle ne peut pas voir un workflow qui **ne charge pas l'overlay** : statiquement, tout est en ordre |
| `infra/scripts/verifier-ports-publies.sh` | les ports **réellement publiés** — la bonne mesure | 🔴 **Elle n'est câblée que dans `deploy-staging.yml:175`, sur `axion-crm-staging`.** Elle mesure la préproduction, **qui est déjà correctement fermée**, et **jamais la production, qui était ouverte** |

> **La garde dynamique est bonne. Elle est simplement braquée sur le seul environnement qui n'avait
> pas le défaut.** C'est le patron `A-011` dans sa forme la plus coûteuse : *l'outil juste, pointé
> sur le mauvais objet.*
> **Correctif à faire en P3** : appeler `verifier-ports-publies.sh` sur `axion-crm-postgres` **après
> chaque `deploy-direct-ssh`**, et non seulement sur la préproduction.

⚠️ **Limite déclarée** : les trois correctifs sont **locaux et non poussés**. Tant qu'ils ne sont pas
sur `main`, **le workflow en ligne reste celui d'avant** — un dispatch de `diag-website-status.yml`
rouvrirait les ports aujourd'hui. **C'est porté à `06_RESTE-WILL.md` : ne pas lancer ce workflow
avant que le correctif soit déployé.**

---

## 8. Résultat n° 6 — **dix-septième cas de `A-011`** : six tests certifient l'absence de contrôle d'accès

**Origine** : l'agent 35 signale, en clôture, un motif qu'il a rencontré sans le chercher — *« cinq
tests du dépôt garantissaient un défaut ; je les ai trouvés parce que mes correctifs les ont fait
rougir »*. Le plus net : `RgpdRequestsControllerTest` **crée un compte sans aucun rôle et attend
200**. Il certifie donc, sans le dire, que **les routes RGPD sont ouvertes à tout compte connecté**.

**Il ajoute : « il y en a probablement d'autres ; je n'ai vu que ceux que mes correctifs ont
heurtés. »** *C'est exactement le genre d'intuition qu'on ne doit pas laisser à l'état d'intuition —
et il ne pouvait pas la vérifier lui-même sans se relire (règle 7).* **Je l'ai balayée.**

**Mesure** — sur `tests/Feature/`, tout fichier qui prend une identité (`actingAs`) **sans jamais
poser un rôle ni une permission** (`assignRole`, `givePermissionTo`, `syncRoles`, `'role' =>`…) **et
qui attend au moins un succès** (200/201/204) :

**→ 17 fichiers.** Dont **six exercent un geste destructeur ou des données personnelles** :

| Fichier | Ce qu'il exerce sans rôle, en attendant un succès |
|---|---|
| **`Rgpd/RgpdRequestsControllerTest`** | `/rgpd`, `/export`, `/audit-logs`, `erasure` — *celui qu'a trouvé l'agent 35* |
| **`CompaniesControllerTest`** | `DELETE`, `PUT` — *c'est la garde de `F36-003`, « un `viewer` supprime définitivement une entreprise »* |
| **`Controllers/NotificationsControllerTest`** | `/audit-logs` — *`B16-004` / `F36-004`, le journal lisible par tout compte* |
| **`CampaignsTest`** | `DELETE`, `PUT` |
| **`Crm/CrmOutboundTest`** | `erasure` |
| **`Controllers/WorkspaceControllerTest`** | `PUT` |

### `P5-ROLES-001` (S1) — et c'est une **espèce** de `A-011` distincte

Les quinze premiers cas étaient des gardes qui **mesurent le mauvais objet**. Ces six-là, comme
`AntiReinsertionTest` (cas 10), sont d'une autre nature : **elles inscrivent le défaut dans une
assertion.** Elles ne ratent pas le problème — *elles le certifient correct.*

> **Conséquence opérationnelle pour P3, et personne ne l'a chiffrée** : `F36-001` et `F36-002`
> (l'autorisation est du code mort) sont au **rang 3** de l'ordonnancement. Le jour où l'on câble
> les 11 policies, **ces six fichiers au moins passeront au rouge**, et ce rouge sera **la preuve
> que le correctif marche**.
>
> 🔴 **Le risque est là, et il est humain** : devant six suites rouges, la pente naturelle est de
> « réparer les tests ». **Les réparer, c'est leur donner un rôle.** Assouplir la garde pour les
> faire passer, c'est défaire le correctif le jour même — et le dépôt vient de montrer trois fois
> qu'il répare sur un exemplaire sans balayer les frères.
>
> **À écrire dans le ticket de `F36-001` avant de l'ouvrir**, pas après.

⚠️ **Limite déclarée** : les 11 autres fichiers ne sont **pas** des défauts par construction — un
tableau de bord ou une recherche peuvent légitimement n'exiger qu'une authentification. **Le balayage
donne une liste à examiner, pas un verdict.** Seuls les six ci-dessus sont qualifiés, sur le critère
« geste destructeur ou donnée personnelle ». *Et la méthode est heuristique : un fichier qui poserait
un rôle par un chemin que mon motif ne connaît pas m'aurait échappé.*

---

## 9. Résultat n° 7 — contre-vérification de `a6aceb0`, **le commit public que personne n'avait relu**

**Pourquoi en urgence** : c'est le correctif le plus dangereux du lot (`F37-001`, S0 — la signature
du canal machine forgeable en production), **il est en ligne sur un dépôt public depuis 16 h 14**,
proposé à la fusion dans la PR #191, et **son auteur est le seul à l'avoir vu**. La règle 7 n'est pas
optionnelle parce qu'un travail est pressé ; elle l'est d'autant moins qu'il est déjà publié.

**Méthode** : lecture du diff publié, statique. *Et j'y ai commis une erreur de mesure que je
consigne parce qu'elle est instructive — voir la note en fin de section.*

### ✅ Ce que le correctif fait bien, et il faut le dire

| Point | Verdict |
|---|---|
| Garde de secret vide | ✅ **`if ($secret === '')` → 503 explicite**, journalisé avec l'IP. *Fail-closed.* Et le choix du **503** plutôt que du 401 est juste et argumenté : *un secret manquant est une faute de configuration, pas une requête malformée* — aligné sur le webhook ZeptoMail |
| Vérification | ✅ **Passe par `HmacSignature::verify()`**, la classe durcie déjà en place sur `SiteSync` — *au lieu de recopier le patron.* **C'est exactement le correctif que `P5-HMAC-001` préconisait**, écrit indépendamment |
| Honnêteté du commentaire | ✅ **Il déclare sa propre limite** : le corps signé reste le **corps brut, sans horodatage**, parce qu'y ajouter la fenêtre casserait les workers Node en place. **« Le rejeu tardif reste donc ouvert, et c'est noté comme tel. »** *Un correctif qui borne ce qu'il ne couvre pas vaut mieux qu'un correctif qui laisse croire qu'il couvre tout.* |

**`F37-001` est donc fermé pour la forge.** Pas pour le rejeu — et c'est écrit.

### ❌ Ce qu'il ne ferme pas, et qui était déjà au dossier

| Constat | État après `a6aceb0` |
|---|---|
| **`P5-HMAC-001`** (S2) — les deux commentaires qui **désignent le canal troué comme le patron de référence** | 🔴 **TOUJOURS LÀ.** `routes/api.php:331` dit encore que `/site-sync` suit *« le même patron que scraper-result »*, et le docbloc de `HmacSignature` dit encore *« reprend le patron déjà en place sur `POST /internal/scraper-result` »*. **Et c'est désormais doublement faux** : la classe durcie déclare dériver de `scraper-result`, qui vient précisément de se mettre à dériver d'elle. *Circulaire, et trompeur pour le suivant.* **Correctif : deux lignes de commentaire.** |
| **`P5-HMAC-002`** (S3) — le secret lu par **`env()` brut**, sans aucune entrée `config/` | 🔴 **TOUJOURS LÀ** : `ScraperResultController:38` reste `env('WORKER_INTERNAL_HMAC_SECRET', '')`, et `config/services.php` n'en porte **aucune** entrée |

> **Quatrième cas du même motif dans la même journée — et cette fois à l'intérieur du correctif.**
> Le commit répare l'endpoint et **ne balaye pas les deux lignes qui rejouteront le défaut au
> prochain canal machine-à-machine écrit dans ce dépôt.** *Le trou est bouché ; le plan qui mène au
> trou est toujours affiché au mur.*

### ⚠️ Mon erreur de mesure, consignée — elle vaut mieux qu'un verdict propre

Mon premier contrôle a conclu que **les deux commentaires étaient corrigés**. Ils ne l'étaient pas.
J'avais cherché `'meme patron que scraper-result'` et `'deja en place sur'` — **sans accents**,
sur un fichier qui écrit *« même »* et *« déjà »*. **Zéro résultat, et j'ai lu ce zéro comme une
absence dans le code alors qu'il était une absence dans ma requête.**

*C'est le patron `A-011` à l'échelle d'une commande shell : la mesure était irréprochable, elle
portait sur le mauvais objet.* Rattrapé au tour suivant parce que le résultat était trop beau — un
correctif d'urgence qui nettoie en passant deux commentaires cosmétiques, cela ne se produit pas.
**Règle que j'en tire : un contrôle en français doit être joué sur une sous-chaîne sans lettre
accentuée, ou pas du tout.**

### Verdict sur `a6aceb0`

**Le correctif est bon et je ne m'y oppose pas.** Il ferme un S0 réel, par le bon moyen, en réemployant
une pièce existante plutôt qu'en la recopiant, et il déclare ce qu'il ne couvre pas.
**Trois compléments avant fusion**, tous petits :

1. les deux lignes de commentaire de `P5-HMAC-001` — **sans elles, le défaut se réécrira** ;
2. une entrée `config/services.php` pour le secret (`P5-HMAC-002`) ;
3. **le rejeu tardif, déclaré ouvert, doit devenir un constat au registre** et non rester une note
   de commentaire — sinon il disparaîtra avec la PR.

---

## 10. Résultat n° 8 — **la contre-vérification adversariale est rendue : PR #191 n'est PAS fusionnable**

**Rapport** : `11_GRILLES/p5_contre-verification-agent-35.md` · **Preuves** : `04_PREUVES/p5-agent35/`

**C'est l'objet n° 1 de cette passe, et la raison pour laquelle j'avais bloqué la branche.** Un agent
indépendant a repris les gardes de l'agent 35 **une par une**, au témoin négatif. Il pose d'emblée
sa limite : la branche a bougé **trois fois** sous lui ; son verdict porte jusqu'à `46848d4`, et
**`a6aceb0` n'est pas mesuré par lui** *(il l'est par moi, §9)*.

### Verdict : **non fusionnable en l'état** — trois blocages, ~1 h 30 de reprise

| Id | Sév. | Ce qui bloque |
|---|---|---|
| **`P5-35-007`** | **S1** | 🔴 **`B16-004` n'est corrigé qu'à moitié** : l'**admin de l'espace A lit toujours le journal d'audit de l'espace B** (mesuré, témoin positif). **Et le nouveau test certifie la fuite restante** |
| **`P5-35-006`** | **S1** | La branche **casse un test du dépôt qu'elle n'a pas mis à jour** : `NotificationsControllerTest > GET /audit-logs` rend **403 au lieu de 200** |
| **`P5-35-004`** | **S1** | Le `set -e` ajouté à `definir-mot-de-passe-crm.sh` rend ses **branches d'erreur inatteignables** : sur un compte inexistant, l'opérateur **ne voit aucun message**. *C'est le script qui rend l'accès au CRM.* Deux témoins positifs. **Correction : 2 minutes** |

**J'ai vérifié les deux premiers moi-même, statiquement, sur la branche publique :**

- **`->not->toBe(403)`** apparaît **trois fois** dans le diff de `46848d4`. Cette assertion ne demande
  qu'une chose : *« est-ce autre chose qu'un 403 ? »* — **elle passe sur un 200 qui rend le journal
  d'un autre espace.** ✅ **Confirmé.**
- `NotificationsControllerTest` **n'apparaît pas** dans les fichiers du commit. ✅ **Confirmé.**

> 🔑 **`P5-35-007` est le dix-huitième cas du patron, et le plus retors de tous** : c'est la garde
> **écrite pour prouver la correction de `B16-004`** qui **entérine ce qui reste ouvert**. Comme
> `AntiReinsertionTest`, elle n'échoue pas à voir le défaut : *elle l'inscrit en assertion.*
> **Le motif s'est reproduit dans le correctif d'un défaut dont ce motif était la cause.**

### 🎯 Et `P5-ROLES-001`, écrit ce matin, s'est réalisé le jour même

J'avais écrit, en balayant les 17 fichiers de test sans rôle :

> *« Le jour où l'on câble les policies, ces six fichiers au moins passeront au rouge, et ce rouge
> sera la preuve que le correctif marche. Le risque est humain : la pente naturelle est d'assouplir
> la garde pour les faire passer. »*

**`NotificationsControllerTest` était le troisième de ma liste.** Il est passé au rouge en quelques
heures, sur cette branche, exactement comme annoncé. **La prédiction est vérifiée, et l'avertissement
qui l'accompagnait devient opérationnel** : ce 403 est **la preuve que la garde marche**. *Il faut
donner un rôle au test, surtout pas relâcher la route.*

### ✅ Ce qui est sain, mesuré, et qu'il faut dire

- **Le risque n° 1 que j'avais nommé est levé.** La chaîne **compte neuf → connexion → enrôlement
  2FA → écran d'accueil** est franchissable **de bout en bout**, mesurée en **8 étapes**. Elle était
  bien bloquée à `bdd25eb` ; `26fa980` l'ouvre. *La crainte que le correctif ferme la console au lieu
  de l'ouvrir est écartée par la mesure.*
- **`EnsureTwoFactorPassed` est réellement branché** — vu **refuser puis accepter**, pas seulement écrit.
- **La migration est réversible** : `up` / `rollback` / `up` joués.
- **Les 4 tests réécrits le sont correctement**, `LoginTest` étant **plus rigoureux** que la garde
  qu'il remplace.
- **13 gardes rougissent** avec message exact ; **8 témoins positifs déclarés**.

### 🔴 Et deux constats du registre corrigés **par la mesure** — dans le bon sens

| Constat | Ce qu'il disait | Ce qui est mesuré |
|---|---|---|
| **`F35-002`** | *« `GET /api/v1/users` est cassé »* | 🔴 **Imprécis** : sur `main` la route rend **`200 {"data":[],"degraded":true}`**, jamais 500. **Elle n'est pas cassée : elle est silencieusement vide** — patron `A-002`. *La garde correspondante ne peut donc pas rougir.* |
| **`F35-009`** | énumération de comptes par le temps | ⚠️ **Mesurait une propriété statique chaude** qui ne survit à aucune requête PHP. Remis en condition de production, l'oracle **subsiste mais inversé et doublé** (76 ms vs 156 ms, rapport **2,04**) — **et le seuil de la garde est à 3,0** : *elle ne peut pas le voir* |

### ⚠️ Ma faute d'atelier, et elle est nette

L'agent signale qu'*« une autre session a committé sur la branche d'audit pendant son travail,
emportant ses preuves en cours d'écriture dans `2a6bd2f`/`dc4bb7a` »*. **Cette autre session, c'est
moi.** Mes `git add -A _AUDIT/…` ont ramassé ses fichiers de preuve **au milieu de leur écriture**.

*Je lui demandais de mesurer proprement pendant que je marchais sur sa table.* Un ajout large sur un
répertoire partagé avec un agent en cours est une faute de méthode, pas une maladresse :
**il faut committer des chemins nommés, pas un répertoire.** Consigné pour toute flotte ultérieure.

---

## 11. Résultat n° 9 — `46f1717` : **le correctif est bon, l'affirmation publique qui l'accompagne est fausse**

**Objet** : le septième commit poussé sur la PR publique #191, sur la chaîne d'audit (`B16-001`).

### ✅ Le défaut qu'il corrige est réel, et c'est même le meilleur de la série

Il ne porte pas sur le secret, mais sur **ce que la vérification répond quand le secret manque** :

> *« Une chaîne hachée sans secret reste parfaitement cohérente avec elle-même. La vérification la
> parcourait, tout concordait, et elle répondait `valid: true`. Mesuré sur le code d'origine :
> "Failed asserting that true is false". »*

**Un contrôle d'intégrité qui affirme « tout va bien » sans pouvoir le savoir est pire qu'un contrôle
absent : il endort celui qui le lit.** C'est la définition même du patron `A-011`, appliquée à
l'organe dont le métier est de prouver. **Le correctif est juste** — la chaîne refuse désormais de se
déclarer valide sans secret utilisable, et l'endpoint dit **pourquoi**, pour qu'un `false` n'envoie
pas chercher une falsification là où il n'y a qu'une variable absente. *Ce dernier détail est une
vraie finesse.*

✅ **Vérifié de mon côté** : le défaut par défaut existe bien —
`AuditHashChain.php:33` fait `env('AUDIT_HASH_CHAIN_SECRET', **'dev-only-secret-change-me'**)`,
une valeur **écrite en clair dans un dépôt public**.

### 🔴 Mais son affirmation sur la production est **fausse**, et elle est publiée

Le commit et son compte rendu affirment : *« le secret est **vide en production** »*.

**Le registre le réfute, et par une mesure faite sur le bon objet.** L'agent 40 — le seul à avoir eu
l'accès — a mesuré **deux fois sur l'application de production en marche**, **sans jamais afficher la
valeur** (`02_CONSTATS.md:669-673`) :

| Contrôle | Résultat |
|---|---|
| longueur de `AUDIT_HASH_CHAIN_SECRET` | **64 caractères** |
| `=== ''` | **non** |
| `=== 'dev-only-secret-change-me'` | **non** |

**C'est précisément pour cela que `B16-001` figure au §1 bis dans la ligne « réfuté pour la
production ».** L'agent 35 **n'a aucun accès à la production** — il l'a écrit lui-même : *« il me
manque un identifiant et un mot de passe »*. Il n'a donc pas pu mesurer ce qu'il affirme : **il a
généralisé un constat d'atelier**.

> 🔑 **C'est l'erreur la plus répétée de tout cet audit, et je l'ai commise le premier.**
> `A-001` : j'avais mesuré au `curl` et généralisé à « tous les clients » — *« un `curl` n'est pas un
> navigateur »*. `F37-002` a failli suivre le même chemin. Ici, **le local n'est pas la production**,
> et la différence tient à une variable d'environnement que quelqu'un a pris la peine de poser.
>
> **Ce qui aggrave le cas** : l'affirmation n'est pas dans un brouillon, elle est **dans un message
> de commit d'une PR publique**. Elle dit au monde que le journal d'audit d'une production vivante
> est falsifiable. *Il ne l'est pas — pas par ce chemin.*

**Arbitrage** : `B16-001` **reste réfuté pour la production**. Le décompte S0 est **inchangé (34)**.
**À demander avant fusion** : rectifier le message de commit et le corps de la PR — *le défaut réel
est que la vérification ment quand le secret manque, pas que le secret manque en production.*

### ✅ Et ce qu'il déclare NE PAS avoir fait — c'est du bon travail

Il nomme trois choses plutôt que de les laisser croire réglées, chacune avec sa raison :

- **l'écriture continue sans secret** — *« ce service tourne sur chaque requête : le faire échouer
  rendrait l'API entièrement indisponible. Perdre la trace serait pire que de la garder faible. »*
  **Arbitrage juste**, et l'alerte part une fois par processus ;
- **`B16-003`** (l'horodatage hors hachage) — *« l'y ajouter invaliderait toutes les chaînes
  existantes : c'est une migration versionnée, pas un correctif de ligne »* ;
- **`B16-002`** (tronquable par la queue) — *« il y faut une ancre externe, donc un choix de
  conception que je ne prends pas au détour d'un correctif de secret. »*

**Les trois restent donc S0 ouverts au registre**, et c'est lui qui le dit. *Un agent qui borne son
propre correctif fait la moitié du travail de son contre-vérificateur.*

---

## 12. Résultat n° 10 — `debc860` : **la mécanique invoquée est réfutée par la mesure**, le défaut qu'elle habille est réel

**Objet** : le dixième commit de la PR publique, sur l'envoi de courriel (`F40-002`, S0).

### La thèse de l'agent

> *« Le court-circuit de simulacre lisait `env('MOCK_MODE')` au moment de la requête — or votre
> entrypoint tente `config:cache` à chaque démarrage, et une configuration en cache signifie que
> Laravel **ne lit plus le `.env`**. `env()` rendait alors son défaut `true` : **la production se
> croyait en simulacre** alors que `MOCK_MODE=false` y était bien posé. »*

**C'est exactement l'hypothèse que j'avais explorée ce matin** sur `WORKER_INTERNAL_HMAC_SECRET`
(§5), et que j'avais écartée. **L'un de nous deux se trompait. J'ai remesuré plutôt que de défendre
ma position.**

### Mesuré dans le conteneur, et c'est net

```
variables_order = EGPCS          <- le E y est
$_SERVER['MOCK_MODE'] -> oui
$_ENV['MOCK_MODE']    -> oui
```

**La variable est dans l'environnement du PROCESSUS, pas seulement dans le `.env`.** `docker-compose`
l'injecte par `env_file:`, PHP la publie dans les deux superglobales (`variables_order` contient
`E`), et le dépôt Dotenv de Laravel lit précisément `$_SERVER` et `$_ENV`.

**→ `env('MOCK_MODE')` rend la vraie valeur, même sous `config:cache`.** Le piège du 2026-08-14 vise
le `.env` lu par dotenv, **pas** les variables posées par `env_file`.

### Deux corroborations indépendantes, dans le même sens

1. **L'agent 40 a mesuré `env('AUDIT_HASH_CHAIN_SECRET')` dans l'application de PRODUCTION en
   marche : 64 caractères.** Si `env()` rendait ses défauts sous configuration en cache, il aurait lu
   `dev-only-secret-change-me`. **Il ne l'a pas lu.** *C'est une preuve directe, sur le bon objet, et
   sur la production.*
2. Ma mesure de `variables_order` ci-dessus explique **pourquoi** : la même image sert les deux
   environnements.

**→ « La production se croyait en simulacre » n'est pas établi, et le mécanisme invoqué est réfuté.**

⚠️ **Ma réserve, et elle est réelle** : j'ai mesuré le **conteneur local**. La preuve de production
est celle de l'agent 40, pas la mienne. *Deux faits indépendants convergent ; ce n'est pas la même
chose qu'une mesure faite là-bas par moi.*

### Ce qui reste vrai, et qui est un S0

**`MAIL_MAILER` n'est défini nulle part** — vérifié dès P1, `config/mail.php:4` fait
`env('MAIL_MAILER', **'log'**)`. **Aucun courriel ne part**, ni lien magique ni réinitialisation.
C'est `F40-002`, et c'est **l'un des quatre verrous de `G4`**. Le correctif ne s'appuie donc pas sur
rien : *il s'appuie sur le bon défaut, avec la mauvaise explication.*

Et son arbitrage produit est bon : la décision de Will — **`MAIL_MAILER` reste `log`** — est
respectée ; il ouvre une clé distincte pour **les deux seules portes de secours d'un compte**, qui
ne sont pas du courrier commercial. *Tant qu'elle vaut `log`, rien ne change.* **Cadrage juste.**

### 🔴 Le motif qui se dessine, et il faut le nommer

**Deux commits de suite** (`46f1717`, `debc860`) portent une **affirmation sur la production que
l'agent ne pouvait pas mesurer** — il n'y a aucun accès, il l'a écrit lui-même — et les deux sont
réfutées par des mesures faites sur le bon objet.

> **Ses correctifs sont bons ; ses assertions de production ne sont pas fiables.**
> La distinction est nette et elle est utilisable : *relire le code qu'il produit avec confiance,
> et ne rien reprendre de ce qu'il affirme sur le serveur sans le remesurer.*
> **Trois messages de commit de la PR publique sont à rectifier avant fusion** (`46f1717`,
> `debc860`, et le corps de #191).

✅ **Et ce qu'il faut porter à son crédit, qui grandit** : il consigne que c'est la **septième fois**
que le rouge venait de sa sonde et non du produit — ici `Mail::fake()` qui ne comptabilise pas
`Mail::raw()`. *« Aucune des sept n'a produit de faux constat, parce qu'à chaque fois j'ai lu le
motif du rouge avant de conclure. »* **C'est précisément la discipline qui manque à ses assertions
de production** : il l'applique à ses sondes, pas à ses affirmations.

---

## 3. Journal de la passe

| Date | Objet | Verdict |
|---|---|---|
| 2026-08-19 | Décompte S0 (`02bis` §1 bis) | 🔴 **Faux, trois fois de suite et toujours trop bas.** Corrigé à **29**, propagé au rapport final et à `06_RESTE-WILL` (qui portait encore **« douze »** — la page que Will lit en premier) |
| 2026-08-19 | `02bis` §5 — « la suite de tests est saine, zéro exclusion silencieuse » | ✅ **Confirmée**, et sur le fichier que la CI ouvre vraiment (`phpunit-ci.xml`), ce qui n'avait pas été fait. Quarantaine levée vérifiée **fichier par fichier : 23/23 présents**. Le couplage entre tests contourné par l'ordre reste ouvert — mais c'est `H44-011`, **déjà connu** : redécouverte, pas trouvaille |
| 2026-08-19 | La tension `02bis` §5 « canal HMAC exemplaire » vs `F37-001` « canal HMAC forgeable » | ✅ **Pas de contradiction : deux canaux distincts**, les deux affirmations tiennent. 🔴 Mais **constat neuf `P5-HMAC-001` (S2)** : deux commentaires — dont le docbloc de la classe durcie — **désignent le canal troué comme le patron de référence**. Une hypothèse à moi (`config:cache` neutralisant le secret) **poursuivie puis réfutée**, archivée avec sa réponse |
| 2026-08-19 | `B15-001` + `B15-002` — « la personne effacée revient au vivier » et la garde qui l'entérine | 🔴 **Confirmés**, chaîne prouvée en 6 maillons du code au schéma. **Et pire qu'écrit** : le même défaut a été trouvé, daté (E2E du 17/08) et **réparé sur la porte voisine** ; le chemin de la console n'a jamais été porté. *Vu, nommé, réparé — d'un seul côté.* |
| 2026-08-19 | `F38-007` — « la faille du 19/08 est réarmable en un clic » (signalé par l'agent S1) | 🔴 **Vérifié, vrai, et plus large : le dispatch rouvre AUSSI l'accès root SSH.** Reclassé **S1 → S0** (`D-019`). **Trois fichiers corrigés**, dont le runbook de reprise après sinistre. **Seizième cas de `A-011`** : la garde dynamique des ports n'est câblée que sur la préproduction, déjà saine |
| 2026-08-19 | *« Il y en a probablement d'autres »* — l'intuition de l'agent 35 sur les tests qui certifient un défaut | 🔴 **Vérifiée et étendue** : **17 fichiers** prennent une identité sans rôle et attendent un succès ; **six** exercent un geste destructeur ou des données personnelles. **`P5-ROLES-001` (S1), dix-septième cas de `A-011`** — espèce « la garde inscrit le défaut en assertion ». **Chiffre le coût caché du correctif `F36-001`** |
| 2026-08-19 | `a6aceb0` — le correctif HMAC **publié sans relecture** (PR #191) | ✅ **Bon** : fail-closed, réemploi de la classe durcie, limite déclarée. `F37-001` fermé **pour la forge, pas pour le rejeu**. ❌ **Ne ferme ni `P5-HMAC-001` ni `P5-HMAC-002`** : les deux commentaires qui propagent le défaut sont toujours là, et **désormais circulaires**. ⚠️ Une erreur de mesure de ma part consignée : grep sans accents lu comme une absence dans le code |
| 2026-08-19 | **Contre-vérification adversariale des correctifs de l'agent 35** — l'objet n° 1 de la passe | 🔴 **NON FUSIONNABLE** : `B16-004` corrigé à moitié et **le nouveau test certifie la fuite** (18ᵉ cas du patron) · un test du dépôt cassé et non mis à jour · `set -e` rendant muettes les erreurs du script d'accès. ✅ **Mais le risque n° 1 est levé** : l'enchaînement complet est franchissable, mesuré en 8 étapes. **Et `P5-ROLES-001`, écrit le matin, s'est vérifié le soir.** ⚠️ Ma faute : mes `git add -A` ont emporté ses preuves en cours d'écriture |
| 2026-08-19 | `46f1717` — la chaîne d'audit se déclarait valide sans secret | ✅ **Défaut réel et bien corrigé** — l'organe qui prouve affirmait sans pouvoir savoir. 🔴 **Mais l'affirmation publique « le secret est vide en production » est FAUSSE** : mesuré **64 caractères**, deux fois, sur la production en marche (`B16-001` réfuté). **Généralisation d'un constat d'atelier — l'erreur la plus répétée de cet audit, et je l'ai commise le premier.** Décompte inchangé : **34** |
| 2026-08-19 | `debc860` — « la production se croyait en simulacre à cause de `config:cache` » | 🔴 **Mécanisme RÉFUTÉ par la mesure** : `variables_order=EGPCS`, `MOCK_MODE` présent dans `$_SERVER` **et** `$_ENV` — `env()` lit la vraie valeur même sous configuration en cache. Corroboré par les **64 caractères** mesurés par l'agent 40 sur la production. ✅ Mais **`MAIL_MAILER` non défini est vrai** (S0), et son arbitrage produit est juste. **Deuxième assertion de production fausse d'affilée** |
