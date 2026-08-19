# 05 — REGISTRE DES DÉCISIONS D'AUTOPILOTE

> Le §1 du mandat autorise le chef de chantier à décider seul et lui impose de consigner
> chaque décision avec sa justification. Format : question · options · décision · raison · date.
> Les six réserves du dirigeant ne sont **jamais** décidées ici : elles vont dans `06_RESTE-WILL.md`.

---

## D-001 — Sur quelle référence l'audit tient-il ?
- **Date** : 2026-08-19
- **Question** : le prompt d'audit donne `main = 7a0bfb2` (v2.0), puis `e577828` et `65e39a6` (v2.1) ; le journal de construction donne `d4910c8`. Lequel ?
- **Options** : (a) suivre la révision 2.1 du prompt ; (b) suivre le journal, plus récent ; (c) mesurer soi-même.
- **Décision** : **(c)**. Référence de l'audit : **`main = c0c453d`**, mesurée le 19/08 à 09:25Z, `origin/main` identique, 0 PR ouverte.
- **Raison** : la règle 6 de la doctrine a été réécrite **précisément** parce que le document s'était trompé. Les trois sources proposées sont périmées, la plus récente de 5 PR. Aucun SHA de document n'est réutilisé nulle part dans cet audit.

## D-002 — Que faire du worktree `crmpro-wt-etape1a` ?
- **Date** : 2026-08-19
- **Question** : le §3 ter interdit de tourner en parallèle d'une session de construction ; le dirigeant interdit explicitement de toucher à ce worktree.
- **Décision** : **périmètre d'audit strictement limité au dépôt principal et au dépôt du site.** Aucune lecture en écriture, aucune modification, aucune suppression du worktree `crmpro-wt-etape1a` ni de sa branche `travail`. Consigne répétée dans le dossier remis à chaque agent.
- **Raison** : consigne explicite. Et la mesure la justifie : le worktree est à `c0c453d`, donc **au même point que `main`** — l'auditer n'apporterait rien et le toucher risquerait de voler une branche de construction (incident du 18/08, une heure perdue).
- **Conséquence acceptée** : le §3 ter est respecté « à l'envers » — ce n'est pas l'audit qui attend la construction, c'est la construction qui s'est arrêtée pour l'audit (journal §9 : « Will lance l'audit 360° avant de continuer à construire »).

## D-003 — Les listes du §4 sont fausses : lesquelles font foi ?
- **Date** : 2026-08-19
- **Question** : le §4 annonce 10 tâches planifiées, 54 migrations, 5 stubs Phase 2, 39 écrans, 21 modèles, 68 services. Le code en donne 35, 58, 3, 37, 18, 84.
- **Options** : (a) auditer les listes du document ; (b) auditer le code recompté ; (c) auditer l'union des deux.
- **Décision** : **(c)** — le code recompté **plus** la liste des éléments que le document nomme et qui n'existent pas (ils deviennent chacun un constat « nommé dans un plan, absent du code »).
- **Raison** : le §4.0 impose d'ajouter au périmètre tout élément présent dans le code et absent du document. Et un élément que le document nomme mais qui n'existe pas est **aussi** une information : il dit que quelqu'un a cru le livrer. Les deux sens de l'écart comptent.

## D-004 — Sévérité du constat A-001 (500 au lieu de 401)
- **Date** : 2026-08-19
- **Question** : S0 ou S1 ? Le défaut touche **toutes** les routes protégées et il est **en production**.
- **Décision initiale** : **S1**, avec réexamen obligatoire en P2, et cette clause explicite : *« reclassé S0 si l'agent 22 montre qu'un visiteur déconnecté n'atteint pas l'écran de connexion »*.
- **Décision révisée le 2026-08-19 : S2.** C'est **l'inverse** qui s'est produit.
- **Raison de la révision** : l'agent 13 a obtenu un **401 propre** là où j'avais mesuré un 500, et a eu la rigueur de ne pas conclure à la réfutation — il a écrit « écart de protocole, pas réfutation ». En cherchant la variable, je l'ai trouvée : ce n'est ni la route, ni Caddy, c'est **l'en-tête `Accept`**. Sans `Accept: application/json` → 500 ; avec → **401 correct**. Et `frontend/src/lib/api.ts:5-8` **pose cet en-tête**, `:30-31` redirige vers `/login` sur 401. **La console n'est donc pas touchée, et la clause de reclassement en S0 tombe d'elle-même.**
- **Ce que je retiens contre moi** : j'avais mesuré avec `curl` nu et généralisé à « tous les clients ». **Un `curl` n'est pas un navigateur.** C'est exactement le genre de généralisation que la passe adversariale (P5) doit traquer — elle l'a été ici par un agent de P1, ce qui vaut mieux.
- **Ce qui reste, et qui justifie S2** : toute sonde de supervision, intégration tierce ou script reçoit **500** là où le contrat HTTP impose 401 ; chaque appel de ce type écrit une trace complète en production et alimente **A-007** ; et le produit **ment sur son état**.

## D-005 — Ce que l'audit fait des trois arbitrages déjà tranchés par le dirigeant
- **Date** : 2026-08-19
- **Décision** : trois sujets sont **clos** et ne sont ni re-proposés, ni re-argumentés par aucun agent :
  1. **Rotation des secrets de production : REFUSÉE** (décision du 19/08, §10.8 du journal). Le risque résiduel est assumé et documenté. Un agent qui la reproposerait rendrait un travail nul.
  2. **Multi-types : REMIS au périmètre** (§10.1) — la conclusion inverse écrite une heure plus tôt est caduque.
  3. **Dossiers : séquencés, pas reportés** (§10.2) — le rattachement entre dans la pièce qui touche `activities`, l'écran arrive avec la fiche.
- **Raison** : règle 9 — un désaccord se tranche par une mesure, mais une décision **du dirigeant** ne se re-tranche pas du tout. L'audit peut mesurer les **conséquences** de ces décisions ; il ne les rouvre pas.

## D-006 — Les 20 branches `dependabot/*` sans PR
- **Date** : 2026-08-19
- **Constat de départ** : `gh pr list --state open` rend **0**, mais `git branch -r` montre **20 branches `dependabot/*`** encore sur `origin`, et GitHub annonce **57 alertes** de vulnérabilité.
- **Décision** : le périmètre de l'agent 47 est **déplacé** des PR (qui n'existent plus) vers **les alertes** et vers la question « qu'est-il advenu de ces 20 PR ? ». Une PR Dependabot **fermée sans traitement** laisse l'alerte ouverte : c'est cela qu'il faut établir.
- **Raison** : auditer 20 PR fermées serait auditer un état révolu — exactement l'erreur que la révision 2.1 du prompt reprochait à sa version 2.0.

## D-007 — Substitution acceptable pour le « test des dix intentions » (§6.3-4, critère 24)
- **Date** : 2026-08-19
- **Question** : le CDC exige **trois personnes extérieures**. L'autopilote ne peut pas en mobiliser.
- **Décision** : substitution explicitement prévue par le §6.3-4 — **trois agents distincts, sans accès au code ni à la carte de navigation, ne recevant que des captures d'écran**. Elle sera **déclarée comme substitution** dans le rapport, jamais présentée comme un test utilisateur.
- **Raison** : le prompt l'autorise nommément. La déclarer est la condition : un test de substitution présenté comme un test réel serait un mensonge de mesure, ce que la passe adversariale doit précisément traquer.

## D-008 — Le sort des deux écrans factices `/cold-email` et `/linkedin` (constat A-005)
- **Date** : 2026-08-19
- **Question** : la fragilité **F8** du §A.1 exige « **retirées ou réalisées** ; aucune route factice sous un nom que ce document utilise ». L'étape 0 a retiré `/crm` et `/analytics` (avec une garde), mais a **conservé à dessein** `/cold-email` et `/linkedin`, en motivant le choix dans un commentaire de `routes/api.php`.
- **Options** : (a) valider la conservation ; (b) exiger le retrait ; (c) exiger un ADR.
- **Décision** : **(c)**. La conservation est **défendable sur le fond** — ces deux noms n'entrent en collision avec aucun mot du CDC, et c'est bien le seul critère que la seconde moitié de F8 énonce. Mais l'exigence a **deux membres**, et le premier (« retirées ou réalisées ») n'est pas satisfait. Trois gestes : les deux routes SPA **redirigent** vers `/` plutôt que de rendre un écran de promesse ; les deux contrôleurs 501 **restent** ; et l'arbitrage passe d'un commentaire à un **ADR daté**.
- **Raison** : un commentaire dans `routes/api.php` n'est pas une décision opposable — personne ne le relira au moment de trancher. Et le §6.3-9 du mandat est explicite : *« ce qui disparaît ne se perd pas »* — une redirection, jamais un 404, jamais un écran qui promet. Sévérité maintenue à **S3** : plus aucune navigation n'y mène.

## D-009 — L'audit se contamine lui-même : mesures en concurrence
- **Date** : 2026-08-19
- **Constat de départ** : trois agents indépendants ont buté sur le même mur. L'agent 5 a **refusé** de mesurer la suite Pest (« plus de vingt processus concurrents, dont un `migrate:fresh` »). L'agent 44 a **retiré une mesure déjà écrite** après avoir découvert par `ps aux` que sa lenteur venait des autres agents, et a **arrêté sa propre exécution** en voyant qu'elle détruisait leurs bases. L'agent 11 n'a **pas obtenu de rouge provoqué propre** pour la même raison. Causes mesurées : **A-009** (API locale mono-processus) et **H44-004 / B11-005** (`tests/bootstrap.php` épingle toute exécution sur l'unique base `axion_crm_test`, où `RefreshDatabase` fait `DROP TABLE … CASCADE`).
- **Décision**, en quatre points :
  1. **Ces trois refus sont validés et portés au crédit des agents**, pas à leur débit. Un agent qui dit « je n'ai pas pu mesurer, et voici pourquoi » rend un travail **supérieur** à celui qui publie un chiffre contaminé. C'est la règle 5 appliquée correctement, et le §5 du mandat le dit : *une case « non vérifié » est acceptable et honnête ; une case absente ne l'est pas.*
  2. **Base isolée obligatoire** pour tout agent qui écrit en base : `axion_crm_<id-agent>`, jamais `axion_crm_test`, jamais `axion_crm`. Porté au dossier commun (§5 bis).
  3. **Tout verdict de performance ou de concurrence doit déclarer sa charge** — à un seul utilisateur, ou en charge. Sans quoi il ne vaut rien : c'est précisément ainsi que **A-010** a traversé tous les contrôles verts du produit.
  4. **P4, P5 et P6 seront sérialisés** sur les travaux qui passent par HTTP ou par la base. Le parallélisme a servi P1 (recherche large) ; il **fausserait** les passes de vérification, dont l'objet est justement de mesurer proprement.
- **Raison** : la contamination n'est pas un incident, c'est une **propriété de l'atelier** — et `H44-004` en fait un constat de produit à part entière, pas seulement une gêne d'audit. Un harnais où deux personnes ne peuvent pas lancer les tests en même temps est un harnais qui **perd des défauts**, exactement comme il vient d'en perdre ici.

## D-010 — `A-010` passe S0 et devient le premier lot de correction
- **Date** : 2026-08-19
- **Question** : la production sert l'API par `php -S`, un seul processus, requêtes sérialisées (mesuré, escalier de 15 ms sur 12 requêtes, témoin positif séquentiel plat). S0 ou S1 ?
- **Décision** : **S0**, et **premier lot de P3**, avant tous les autres correctifs.
- **Raison** : le S0 est défini notamment par « **blocage du chantier cible** ». Ici ce n'est pas une extrapolation : le **principe directeur 8** du CDC (« conçu pour **dix utilisateurs** dès le premier jour ») et le **critère 17 du §29** sont **inatteignables par construction**, et le §0 précise que les principes « priment sur toute fonctionnalité prise isolément ». S'y ajoute un effet mesuré et concret : les compteurs du hub ont été chronométrés à **17,5 s cache froid sur 2,8 M de fiches**, et la production en porte **4,29 M** — une seule requête de ce type **gèle l'application entière** pour tout le monde. Le correctif est en outre **peu coûteux** (php-fpm existe déjà dans l'image ; `PHP_CLI_SERVER_WORKERS` en repli immédiat), ce qui rend tout report difficile à défendre.
- **Conséquence sur l'ordonnancement** : corriger A-010 **avant** de mesurer quoi que ce soit en charge (bloc G). Mesurer la performance sur une pile sérialisée reviendrait à mesurer la file d'attente, pas le produit.

## D-011 — L'atelier servait une interface morte : ce que deviennent les mesures du bloc D
- **Date** : 2026-08-19
- **Constat de départ** : l'agent 23 a mesuré que l'image `axion-crm-app` de l'atelier date du **17/08 07:12**, alors que le commit qui refond la barre latérale (`da97826`) date du **18/08 17:39**. `https://app.localhost` servait donc une interface **vieille de 32 heures** : 10 sections, « Runs de scraping », « Phase 2 », et des entrées vers `/crm` et `/analytics` **retirées du routeur**.
- **Vérification faite par le chef de chantier avant de décider** : le bundle de **production** porte, lui, la barre **neuve** (« Journaux de collecte » ×2, « Runs de scraping » **×0**). **La production n'est pas touchée** ; c'est l'atelier seul qui était périmé.
- **Décision**, en quatre points :
  1. **L'image locale est reconstruite** (`docker compose build app`). Fait par le chef de chantier.
  2. **Toute mesure d'interface faite sur `app.localhost` avant cette reconstruction est déclarée NON VALIDE** et doit être rejouée. Cela concerne le bloc D en priorité (agents 22, 24, 25, 26, 28, 30) — **et les constats de l'agent 23 lui-même**, qui a eu la lucidité de mesurer la péremption plutôt que de la subir.
  3. **Mon propre constat A-006 est nuancé** : il a raison sur le **code** (j'avais lu `Sidebar.tsx`), tort sur **l'atelier**. *Le code n'est pas l'écran servi* — c'est une variante du piège 19 que je n'avais pas vue.
  4. **Une garde est à écrire en P3** : refuser un atelier dont l'image servie est plus ancienne que le dernier commit touchant `frontend/`. Elle doit rougir **sur l'image qui tourne**, pas sur le `Dockerfile`.
- **Raison** : le §11 du mandat exige d'ouvrir les 37 écrans « à la main, dans un vrai navigateur ». Un écran ouvert sur un bundle périmé n'est pas un écran ouvert — c'est **le pire des cas**, celui qui produit des constats faux avec des captures à l'appui. Mieux vaut trois heures de mesures perdues qu'un rapport qui décrit une interface que personne n'exécute.
- **Ce que cet incident dit de l'atelier, et qui va au-delà du bloc D** : c'est la **quatrième** fois que l'atelier local diverge de la production — après `CRM_DB_APP_ROLE_ENABLED` (cloisonnement, B11-010), `DB_TIMEZONE` (fuseau, A05-008) et `AUDIT_HASH_CHAIN_SECRET` (chaîne d'audit, B16-001). **Ce n'est plus une série de coïncidences, c'est une propriété de l'atelier** : il ne reproduit pas la production, et rien ne le signale. À porter au rapport final comme constat de méthode.

## D-012 — `MockServicesProvider` : reclassement en S0
- **Date** : 2026-08-19
- **Question** : l'agent 18 classe C18-016 en S1. Le mandat dit pourtant, au §4.6 : « **un mock qui fuit en production est un défaut de sévérité S0** ».
- **Décision** : **S0**.
- **Raison** : les trois conditions sont réunies et mesurées. (a) **La fuite est possible sans rien faire** : `env('MOCK_MODE', true)` — une variable **absente** suffit, et il n'y a **aucune garde d'environnement** ; `APP_ENV=production` ne change rien. (b) **L'effet n'est pas l'inertie** : `MockLLMClient` alimente `step10_llm_classify`, qui **écrit dans `companies.signals` puis `save()`** — donc des **classifications fabriquées atterrissent en base de production, sans marqueur qui permette de les distinguer**. (c) **La valeur réelle de `MOCK_MODE` sur le serveur n'a pas pu être lue** par cet agent (secrets hors de sa portée). Un défaut dont on ne peut pas prouver qu'il ne s'est pas produit, sur des données qu'on ne peut pas distinguer, se classe au maximum.
- **Ce qui reste à faire pour trancher définitivement** : lire `MOCK_MODE` en production (agent 37 a l'accès) et, si le mock a pu tourner, **compter les lignes de `companies.signals` écrites par `step10_llm_classify`**. Porté en P4.

## D-013 — Ce que l'audit fait de la règle 7 quand elle se retourne contre lui
- **Date** : 2026-08-19
- **Constat de départ** : à la fin de P1, **le chef de chantier a été corrigé cinq fois** — A-001 (S1→S2, la variable était l'en-tête `Accept`), A-004 (S2→S3, quota ACME mal compris), A-007 (56 erreurs/min → **5,5** ; le journal de construction avait raison, pas moi), l'hypothèse RGPD du canal (**morte** : je lisais des lignes écrites par la suite de tests), et A-011 (le cas fondateur du mandat **n'en était pas un** → A-013). Et **A07-004** a été réfuté dans l'autre sens, par moi.
- **Décision** : **les cinq corrections restent visibles dans le registre, avec leur énoncé d'origine.** Aucune n'est effacée au profit de la version corrigée.
- **Raison** : un registre qui efface ses erreurs ne permet pas de juger sa propre fiabilité. Le lecteur du rapport final doit pouvoir mesurer **combien de fois l'audit s'est trompé et comment il l'a su** — c'est la seule information qui lui dise ce que valent les constats **non** corrigés. Et cela vaut aussi bien contre l'audit : mes constats passeront **P5** comme les autres, sans exemption de chef de chantier.
- **Ce que ces cinq corrections ont en commun, et c'est instructif** : quatre sur cinq viennent d'avoir **généralisé une mesure au-delà de ce qu'elle mesurait** — un `curl` pris pour un navigateur, une occurrence de chaîne prise pour un événement, une ligne de journal prise pour du trafic réel, un quota pris pour un autre. **La cinquième vient d'avoir cru un document** (le mandat) sur la nature d'un incident. *C'est exactement le catalogue de fautes que la doctrine énumère — et le fait de les avoir commises malgré cela dit qu'énumérer ne suffit pas : il faut mesurer.*

## D-014 — 🔴 Le dossier d'audit est committé mais **NON POUSSÉ** : le dépôt est public et les failles sont ouvertes
- **Date** : 2026-08-19
- **Question** : le §1 du mandat m'autorise à « ouvrir des branches et des PR, fusionner ». Le dossier d'audit est prêt (branche `audit/360-p1-p2`, commit `8db8229`, 565 fichiers). Le pousser ?
- **Mesure faite avant de décider** :
  ```
  $ gh repo view will383842/axion-crm-pro --json visibility
  {"isPrivate": false, "visibility": "PUBLIC"}

  $ grep -rl "signature forgée|BYPASSRLS|axion_dev_only|secret vide" _AUDIT/2026-08-18_AUDIT-360/
  31 fichiers
  ```
- **Décision** : **le commit reste LOCAL. Je ne pousse pas, et je n'ouvre pas de PR.**
- **Raison** : ce dossier réunit, en un document unique, vérifié et daté, **douze défauts S0 actuellement OUVERTS** sur une production vivante qui porte les données personnelles de **1 319 567 personnes**. Notamment : *comment forger une signature acceptée* par `/internal/scraper-result` et pourquoi elle passe · que `GET /audit-logs` rend le journal **de tous les espaces à tout compte authentifié** · que la chaîne d'audit est **tronquable sans détection** · qu'un compte `viewer` **supprime définitivement** une entreprise · que le mot de passe Postgres est **celui du dépôt public** et que le mécanisme en place **empêche de le corriger**. S'y ajoutent l'adresse de production, les noms de conteneurs et la topologie. **Publier cela sur un dépôt public, c'est publier un mode d'emploi d'attaque qui fonctionne.**
- **Ce n'est pas une interprétation extensive du mandat, c'est son application** : le §1 énumère six réserves, et la première est de ne pas porter atteinte aux données de production. Publier le moyen d'y accéder est du même ordre que d'y toucher.
- **Et le dépôt a déjà tranché ce cas exact**, dans le même sens. Le 19/08, le journal de construction (§C bis) a **retenu une poussée** pour cette raison précise — « le dépôt est PUBLIC, et ces commits réunissent en un mode d'emploi vérifié une faille de production **alors ouverte** » — et n'a poussé qu'une fois le trou fermé, en écrivant : *« ce qui est publié décrit un trou fermé, ce qui est le cas normal d'un correctif de sécurité »*. **Je suis la même règle, appliquée à un dossier bien plus lourd.**
- **Ce que je fais à la place** :
  1. Le commit **existe** (`8db8229`) : le travail est **sous historique local**, donc protégé de la perte — ce qui était le premier objectif, et la leçon de `A06-010`.
  2. **Rien n'est perdu et rien n'est publié.** Le dossier est lisible sur le poste, dans `_AUDIT/2026-08-18_AUDIT-360/`.
  3. **Le geste revient au dirigeant**, avec trois options que je pose sans en choisir une à sa place (voir `06_RESTE-WILL.md`, section F).
- **Ce que je recommande** : **rendre le dépôt privé**, puis pousser. C'est le seul chemin qui préserve à la fois l'historique, la collaboration et la confidentialité — et un dépôt qui porte le code d'un CRM contenant 1,3 M de personnes n'a, de toute façon, pas de raison d'être public.

## D-015 — Ce que le critique de complétude a trouvé contre l'audit, et ce que j'en fais
- **Date** : 2026-08-19
- **Contexte** : l'agent 50 (§7 du mandat : *« qu'est-ce qui manque ? ce qu'il trouve **devient du travail** »*) a audité **l'audit**. Il a daté son instantané (**12:06Z**) et signalé que le dossier était en écriture pendant sa mesure. **Son rapport est le plus utile du lot, et il est sévère.**
- **Ce qu'il a trouvé contre moi, et que j'accepte sans réserve** :
  1. 🔴 **J'ai reproduit `A-013` dans le document qui le dénonce.** Le §5 de `02bis` affirmait « la CI backend, **bloquante et requise** » — ce qui **contredit trois constats du même dossier** (`F38-002`, `A08-005`, `H44-003`). **Corrigé** : c'est **la suite** qui est saine, pas son câblage. *Un résumé qui transforme un détail mesuré en affirmation rassurante — exactement le défaut que j'avais nommé une heure plus tôt.*
  2. **J'avais laissé tomber une réserve d'agent.** L'agent 12 avait écrit que `B12-001` et `B12-003` étaient mesurés là où `CRM_DB_APP_ROLE_ENABLED=false` alors que la production est à `true`. **Ma consolidation a gardé les constats et perdu la réserve.** **Rétablie**, avec obligation de rejouer en P4.
  3. **`B12-004` était périmé** : `F37-001` l'a mesuré sur le serveur et l'aggrave. **La couche consolidée l'ignorait.** **Corrigé.**
  4. 🔴 **Une garde vue ROUGE dormait dans les preuves, publiée nulle part** : un compte `viewer` obtient **200 au lieu de 403** sur l'export des **4 295 349 fiches nominatives**. **C'était la seule garde de permission vue rouge de tout l'audit.** **Publiée en `F36-011`, S0.** *Sans l'agent 50, elle était perdue — `A-013` appliqué à l'audit lui-même.*
- **Ce que je corrige de sa critique, mesure à l'appui** : il classe l'agent 45 comme « squelette, 0 constat ». **C'était vrai à 12:06Z, faux depuis 14:10Z** — le rapport porte **dix constats étayés**. Ce n'est pas une faute de sa part : il avait daté son instantané. **C'est le décalage qui parle.**
- **Ce que sa critique m'oblige à écrire noir sur blanc sur l'état réel** :
  - **P1 : 31 agents rendus sur 46.** Pas 46.
  - **P3, P5, P6, P7 : jamais lancées.** **0 correctif, 0 PR, 0 test vu rouge en correction.**
  - **La grille ÉCRAN est remplie à 8 points sur 25** — **629 cases absentes**. Les grilles **FONCTIONNALITÉ**, **RACCORDEMENT** et **PARCOURS** **n'existent pas**.
  - **§12, définition de fini : 3 points tenus sur 16.**
  - **§11 : non satisfait.** 37 écrans ouverts **non connectés**, **0 des 21 parcours joué**, **12 captures dans tout le dossier**.
  - **La grille ROUTE est le contre-exemple** : 117 lignes, **0 case vide, vérifié et non cru**. Mais ses deux points de doctrine sont à zéro — `EXPLAIN` **non vérifié sur 113 routes**, « vu rouge » sur **0 ligne sur 117**.
- **Décision** : **aucun de ces chiffres n'est arrondi ni euphémisé dans le rapport final.** Un audit qui annonce « 50 agents, trois passes » et livre 31 agents et une passe doit le dire en ces termes. Le §12 est une **définition de fini** : elle n'est pas atteinte, et le dire est la seule façon de rendre le travail utilisable.
- **Et le plus rentable, que je retiens comme prescription** : l'agent 50 chiffre à **~12 h de rédaction sur des mesures déjà archivées** ce qui rendrait les 11 policies, les deux bascules d'heure, la non-régression de la console et les `EXPLAIN`. **Le travail est fait ; il n'est pas écrit.** C'est, une fois de plus, un défaut de clôture — pas de mesure.

## D-016 — `D-009` était insuffisant : le rôle Postgres est global au cluster
- **Date** : 2026-08-19
- **Constat** : j'avais prescrit en `D-009` « une base jetable par agent ». L'agent 45 a mesuré que **le rôle `axion_app` est GLOBAL au cluster** et que **chaque `migrate` en repose le mot de passe pour tout le monde** : deux exécutions concurrentes se détruisent **même sur des bases distinctes** (`H45-008`).
- **Témoin négatif retenu** : **8 sabotages sur 15 comptent 0 échec d'authentification** — la panne n'est **pas structurelle**, elle survient quand un **autre** processus migre entre-temps.
- **Décision** : `D-009` est **complétée**, pas remplacée. Pour P4, P5 et P6 : **sérialisation** des travaux qui migrent, **et** correctif `H45-008` appliqué d'abord (~1 h : `force="true"` sur `DB_APP_PASSWORD`, nom de rôle dérivé de la base).
- **Raison** : trois agents ont rendu des rouges qu'ils n'ont pas pu attribuer. **Un rouge qu'on apprend à ignorer est une garde perdue** — et les passes de vérification n'ont de valeur que si leurs rouges sont interprétables.

## D-017 — Mes décisions `D-009` et `D-016` n'étaient PAS exécutables, et un agent me l'a dit
- **Date** : 2026-08-19
- **Ce que j'avais prescrit** : « base jetable obligatoire par agent : `axion_crm_<id>`, jamais `axion_crm_test` ».
- **Ce que l'agent 26 a mesuré, et que j'ai vérifié** : `backend/tests/bootstrap.php:26-33` **épingle `axion_crm_test` en dur**, dans **`$_SERVER`, `$_ENV` ET `putenv()`**, **avant tout démarrage de l'application** — le commentaire du fichier explique même pourquoi (« la suite continuait de viser `axion_crm` malgré `force="true"` »). **Aucune configuration PHPUnit dédiée ne peut donc rediriger une exécution Pest.** L'agent a créé sa base, fabriqué sa configuration : **sans effet**.
- **Conséquence, qu'il rapporte plutôt que de la taire** : il a tourné sur la base partagée et **a pu détruire la mesure d'un autre agent**. *C'est la raison mécanique pour laquelle `B11-005` et `H44-004` persistent malgré ma consigne.*
- **Décision, corrigée** : ma prescription **ne vaut que pour le travail en SQL direct** (`psql`, `pgbench`, mesures de schéma), où elle a bien fonctionné — les agents 11, 13, 15, 16, 17, 19, 36 et 43 l'ont appliquée sans peine. **Pour Pest, elle est inapplicable tant que `H45-008` et `H44-004` ne sont pas corrigés** (~1 h : `force="true"` sur `DB_APP_PASSWORD`, nom de rôle dérivé de la base, et `TEST_DATABASE_NAME` paramétrable avec garde-fou de préfixe).
- **Ce que j'en retiens contre moi** : j'ai prescrit une isolation **sans vérifier qu'elle était atteignable**. C'est la même faute que celles du §« corrections » — *prescrire n'est pas mesurer*. Et c'est la **troisième** de mes décisions qu'un agent corrige, après `D-004` (sévérité) et `D-009` (portée).
- **Point positif à conserver** : `TestCase::setUp()` **valide le PRÉFIXE et non l'égalité** (`axion_crm_test_test_1` passe). La brique du correctif est donc **déjà à moitié posée** — il ne manque que de rendre `TEST_DATABASE_NAME` paramétrable.
