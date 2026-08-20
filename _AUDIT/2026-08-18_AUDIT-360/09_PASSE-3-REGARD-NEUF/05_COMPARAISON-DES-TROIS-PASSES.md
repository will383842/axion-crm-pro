# P6 — COMPARAISON DES TROIS PASSES

> Écrite le **2026-08-20**, après la première exécution de la phase P6. Quatre agents neufs,
> sans accès à `02_CONSTATS.md`, `07_RAPPORT-FINAL.md`, `08_PASSE-2-ADVERSARIALE.md`, aux
> grilles ni aux preuves. Ils ont reçu le code, le CDC et le mandat.
>
> **Référence mesurée** : `23a0e5f` → `b6fa07f` sur `fix/a35-authentification`. Trois des
> quatre agents ont noté d'eux-mêmes que la branche bougeait sous eux, et l'ont signalé comme
> un défaut de dispositif. Ils ont raison : **mesurer sur un arbre de travail partagé est une
> faute de méthode**, et c'est la mienne.

---

## 1. Ce que la comparaison doit produire

Le §8 du mandat le dit : *« tout écart est **en soi un défaut de méthode** à expliquer ligne à
ligne (pourquoi la passe 1 ne l'a-t-elle pas vu ?) »*. Trois cas :

| Cas | Ce qu'il signifie |
|---|---|
| P6 le trouve, P1 aussi | la passe 1 était bonne sur ce point |
| **P6 le trouve, P1 non** | 🔴 défaut de méthode de P1 |
| P1 le tenait, P6 ne le retrouve pas | soit fermé depuis, soit **surestimé** par P1 |

---

## 2. Le verdict global, sans complaisance

**La passe 1 tient bien.** Sur les automatismes et le schéma, l'agent neuf retrouve
`A08-006`, `B17-001`, `B10-003`, `B11-001`, `B11-003` — et il les retrouve **avec le
mécanisme exact**, ce que P1 n'avait pas toujours. *Une passe indépendante qui converge sur
les mêmes constats est la meilleure nouvelle que ce dossier pouvait recevoir.*

**Mais P6 trouve six choses que ni P1 ni P2 n'avaient vues**, et deux d'entre elles sont dans
le travail de réparation du 20 août lui-même.

---

## 3. 🔴 Ce que P6 trouve et que les deux premières passes ont manqué

### 3.1 `P6-API-001` / `P6-API-002` (S0) — les LISTES ne sont pas cloisonnées

`GET /journalists` et `GET /rgpd/requests` rendaient les lignes de **tous les espaces de
travail** — nom, adresse, téléphone pour l'un ; `subject_email` en clair pour l'autre.
Confirmé par ma propre lecture, puis par une mesure HTTP croisée. Deux autres listes
suivaient : `/proxy-providers` et `/llm/use-cases`.

**Pourquoi P1 ne l'a pas vu** : elle avait relevé la fuite sur `GET /companies/{id}` et
`GET /contacts/{id}` — les **fiches**. Le raisonnement s'est arrêté à la forme du défaut
rencontrée en premier.

**Pourquoi P2 ne l'a pas vu** : elle attaquait les correctifs de P1, donc le périmètre de P1.
*Une passe adversariale hérite des angles morts de celle qu'elle réfute.*

**Pourquoi MA réparation du matin ne l'a pas vu, et c'est le pire des trois** : j'ai écrit un
contrôle de complétude, censé empêcher qu'un site de liaison échappe à la garde. Il énumérait
**les méthodes qui reçoivent un modèle par résolution de route**. Un `index()` n'en reçoit
aucun. Les listes lui étaient **structurellement invisibles** — et il était vert.

> 🔑 **Une garde de complétude qui définit elle-même son périmètre ne prouve rien sur ce
> qu'elle omet.** C'est la troisième fois de la journée que mon énumération est mon angle mort.

*Fermé le jour même* (`cb81284`), avec une garde qui **mesure le corps de la réponse** au lieu
de chercher un appel de méthode — parce que trois balayages statiques successifs avaient rendu
trois comptes différents : 20, 5, 7.

### 3.2 `P6-UI-001` (S0) — l'écran d'accueil dit « aucune entreprise » sur 4,29 millions

`/dashboard/stats` est une **closure dans `routes/api.php`** qui renvoie des zéros en dur ;
aucun `DashboardController` n'existe. Comme `DashboardPage.tsx` teste `companies_total === 0`,
la console affiche en permanence *« Lance ton premier scrape — aucune entreprise collectée »*.
Les quatre vignettes, les trois graphiques et les deux cartes latérales sont du code
injoignable.

**Pourquoi P1 ne l'a pas vu** : le bloc D avait pour consigne d'ouvrir chaque écran à la main
dans un navigateur. **La console ne tourne pas** — donc personne ne l'a ouvert, et le défaut
qui saute aux yeux en trois secondes d'usage a survécu à un audit de 46 agents. *Le §12 point 3
du mandat n'est pas décoratif.*

### 3.3 `P6-UI-002` (S0) — la palette ⌘K ne peut rien trouver

`GET /search` est déclarée **deux fois** et renvoie trois tableaux vides écrits en dur. La
palette est présente sur tous les écrans et vantée par la visite guidée. Le test e2e **mocke
l'endpoint** et reste vert.

> *Un test qui mocke précisément la pièce qui n'existe pas certifie son existence.* C'est le
> patron `A-011` transposé aux tests, et il n'était pas au registre sous cette forme.

### 3.4 `P6-INFRA-001` (S0) — une porte dérobée `root`, réarmable en un clic

`diag-website-status.yml:45-54` : la **première action** du workflow, avant tout diagnostic,
ajoute une clé publique en dur au `authorized_keys` du compte **root** de production.

**P1 l'avait vue** — la file de travail écrit *« et :45-54 pour la clé SSH »* — mais l'a
**repliée dans `F38-007`** au lieu d'en faire un constat. Elle n'a donc jamais eu de sévérité,
jamais de ligne dans `06_RESTE-WILL`, et le correctif de `F38-007` ne la touche pas.

⚠️ **Qualification, et elle compte** : une clé *publique* dans un dépôt public n'est pas une
fuite d'identifiant — seule la privée ouvre. Le défaut est ailleurs : **la retirer du serveur
ne tient pas**, puisqu'un clic la repose ; et la clé s'appelle `claude-code-axion-ia-20260510`,
elle appartient à **un autre produit**.

*Porté à `RESTE-WILL` et délibérément **pas** retiré : c'est un mécanisme d'accès à son
serveur, et le supprimer sans le lui demander pourrait l'enfermer dehors.*

### 3.5 `P6-INFRA-003` (S0) — un douzième chemin vers la faille du 19 août

`docker-compose.observability.yml:2` **prescrit en toutes lettres** une combinaison de
fichiers qui omet l'overlay de production. Rendu joué : elle republie 55432, 56379 **et neuf
ports d'administration** sur `0.0.0.0`, dont un Grafana avec mot de passe en dur.

La garde CI `config-prod` ne rend jamais cette combinaison, **donc ne la voit pas**.

*Encore un frère de `F38-007` — le douzième — et encore un que mon énumération n'atteignait
pas. Elle listait workflows, scripts et runbooks. Pas les fichiers Compose eux-mêmes.*

### 3.6 `P6-UI-019` (S2 au registre, **bloquant en pratique**) — la CI ne pouvait pas passer

`pnpm lint`, déclarée « Lint (BLOQUANT) » dans `ci.yml`, **échouait avec 16 erreurs**, toutes
dans les quatre fichiers frontend que la branche touche. Vérifié : sur `origin/main`, ces
mêmes fichiers passent.

**Ni P1, ni P2, ni moi n'avions joué la porte.** La PR #191 était déclarée fusionnable par
GitHub — au sens de git — et aurait rougi à la première exécution de CI. *Fermé le jour même
(`46ecb80`) ; la porte rend 0.*

---

## 4. ✅ Ce que P6 CONFIRME, et qui grandit la passe 1

L'agent des automatismes, sans avoir lu une ligne du registre, retrouve :

| Constat P1 | Ce que P6 y ajoute |
|---|---|
| `A08-006` — le SQL de `rgpd:anonymize-ips` ne compile pas | **la preuve sur un Postgres tiers** (`operator does not exist: cidr / integer`), avec témoin négatif (`set_masklen` fonctionne), et **pourquoi le défaut a survécu** : la branche `--dry-run` passe par un `count()` et n'atteint jamais le SQL fautif |
| `B17-001` — `retention:purge --dry-run` détruit réellement | **le mécanisme exact** : un `preg_replace` sans modificateur `s` ne franchit pas le saut de ligne du SQL, l'`UPDATE` part intact dans `DB::selectOne()`. Et **PHPStan avait signalé ces deux lignes** — gelées dans la baseline |
| `B10-003` — le partitionnement d'`audit_logs` n'est entretenu par personne | `run_maintenance` n'apparaît **qu'une fois dans tout le dépôt, dans un runbook d'incident manuel**, alors que le `Dockerfile.postgres` compile pg_partman en `NO_BGW=1` **en désignant le scheduler Laravel comme remplaçant** |
| `B11-001` — les tâches planifiées sans contexte d'espace | **aucune des 33**, et la ceinture ne couvre que 4 modèles sur 16 |
| `B15-008` — les commandes destructives sans garde | `media:clean-emails` détruit des adresses **tous les jours à 05:05**, et le trait `RefuseUneSuppressionMassive` **existe dans le dépôt sans être branché** — ce qui confirme que j'ai eu raison de laisser `B15-008` en « partiel » plutôt que de le déclarer fermé |

> **C'est la meilleure nouvelle du dossier.** Une passe indépendante qui converge sur les mêmes
> constats, par ses propres mesures, valide la passe 1 bien mieux qu'une relecture ne le
> ferait.

---

## 5. Ce que P6 n'a pas pu mesurer — et qui reste le trou du dossier

Les quatre agents ont chacun rendu leur section de bornes. Deux reviennent chez tous :

1. **Aucun accès à la production.** Tous ont marqué leurs constats « mesuré sur le dépôt » ou
   « en local ». *C'est exactement la discipline qui manquait aux trois affirmations
   rectifiées de la PR #191.*
2. **Aucun écran ouvert dans un navigateur.** La console ne tourne pas. L'agent frontend le
   déclare comme son plus gros angle mort : *le point 5 de la grille — « la donnée vient-elle
   d'où elle prétend ? » — n'est établi que pour 2 routes sur ~30.*

**Et pourtant c'est un audit statique qui a trouvé les deux S0 de l'interface.** Ce qu'un vrai
navigateur trouverait n'est pas mesurable d'ici — mais il n'y a aucune raison de croire que ce
serait moins.

---

## 6. Le défaut de dispositif, et il est de moi

Trois des quatre agents ont noté que **la branche bougeait pendant leur mesure** — je
committais sur le même arbre de travail. L'un a vérifié lui-même que l'écart ne touchait pas
son périmètre, et l'a consigné.

C'est la même faute que la passe 2 relève au §10 (*« mes `git add -A` ont ramassé ses fichiers
de preuve au milieu de leur écriture »*), sous une autre forme : **on ne fait pas mesurer un
arbre qu'on modifie**. Pour une prochaine vague P6 : un worktree en lecture seule par agent,
figé sur un SHA nommé.

---

## 7. Ce que cette passe change au verdict

**P6 n'est pas terminée.** Quatre périmètres sur les onze du §4 ont été couverts : API et
contrôleurs, automatismes et schéma, infrastructure, frontend. **Restent** : les 68 services,
les 34 fichiers de workers, le côté site (`Axion-IA`), les 23 fonctionnalités et la matrice de
raccordement, les 13 parcours du CDC §23.4.

Le mandat exige en outre de **boucler jusqu'à ce qu'une passe complète ne trouve plus rien de
sévérité ≥ S2**. Cette première vague en a trouvé **cinq S0**. *On en est loin, et il faut le
dire.*

**La définition de fini du §12 reste à 3 points sur 16.**
