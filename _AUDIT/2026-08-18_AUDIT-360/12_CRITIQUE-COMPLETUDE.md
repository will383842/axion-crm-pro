# 12 — CRITIQUE DE COMPLÉTUDE (agent 50)

> WARNING **ÉTAT DATÉ — lu au 2026-08-19, AVANT l'ouverture de la passe 2.**
> Ce rapport constate notamment que `08_PASSE-2-ADVERSARIALE.md` n'existe pas. **C'était vrai à sa
> rédaction ; ce ne l'est plus** : la passe 2 a été ouverte le même jour et porte six résultats
> mesurés. De même, le décompte S0 qu'il cite a été recompté depuis (**34**, décisions `D-019` et
> `D-020`).
> **Le reste de ses constats est intact et non contesté** — 13 objets tenus, 12 partiels, 45 non
> tenus, et la passe 3 à regard neuf toujours pas lancée. *Ne pas lire ce fichier comme l'état du
> jour : le lire comme la photographie qui a déclenché la suite.*


> **Cible de ce rapport : l'audit, pas le produit.** Aucun constat ci-dessous ne porte sur Axion CRM Pro.
> Ils portent tous sur `_AUDIT/2026-08-18_AUDIT-360/` : ce qui a été promis, ce qui a été mesuré, et
> l'écart entre les deux.
>
> **Instantané** : **2026-08-19T12:06Z**. **Référence du dépôt à cet instant : `main = 8db8229`**
> (relue par `git log`, pas recopiée) — l'audit lui-même a committé son dossier ; `main` a donc bougé
> **trois fois** sous l'audit : `c0c453d` → `e8924b8` → `8db8229`.
>
> ⚠️ **Le dossier était en écriture pendant ma mesure.** `02_CONSTATS.md` est passé de 1 869 à
> 2 025 lignes, et trois rapports (`agent-33`, `agent-37`, `agent-46`) sont apparus entre mon
> premier et mon dernier passage. Tous les décomptes ci-dessous sont **arrêtés à 12:06Z** et
> deviendront faux dès qu'un agent rendra. C'est une propriété du chantier, pas une excuse : je le
> dis parce qu'un décompte non daté est exactement ce que ce rapport reproche aux autres.

---

## 1. TABLEAU DE GRILLE — un objet du mandat par ligne, aucune case vide

Légende : ✅ tenu et prouvé · ⚠️ partiel · ❌ non tenu · 🔵 sans objet (raison) · **nv** non vérifié (raison).

| # | Objet du mandat | Exigé | Rendu, mesuré | Verdict | Constat |
|---|---|---|---|---|---|
| 1 | §4.1 — 18 modèles | grille §5.3, 14 pts | 18/18, 14 pts, 0 case vide (`tables.md`) | ✅ | — |
| 2 | §4.2 — 42 contrôleurs | audités un par un | 43/44 **nommés** ; **0 grille par contrôleur** | ⚠️ | Z50-002 |
| 3 | §4.3 — 117 routes | grille §5.2, 18 pts | 117 lignes × 20 colonnes, **0 case vide** (vérifié) | ⚠️ | Z50-006 |
| 4 | §4.4 — 84 services | audités | **60/84 nommés**, **24 jamais nommés**, 0 grille | ❌ | Z50-002 |
| 5 | §4.5 — 35 tâches planifiées | grille §5.4, 12 pts | 35/35, 12 pts (3 factorisés) | ✅ | — |
| 6 | §4.5 — 6 jobs + 1 trait | grille §5.4 | 7/7, 12 colonnes | ✅ | — |
| 7 | §4.5 — 49 commandes | grille §5.4, 12 pts | **49/49 classées**, mais 12 pts sur 33 seulement | ⚠️ | Z50-003 |
| 8 | §4.6 — 34 fichiers workers | grille §5.4 | **12/34 nommés**, 0 grille | ❌ | Z50-003 |
| 9 | §4.7 — 37 écrans | grille §5.1, **25 pts** | 37/37 × **8 colonnes** — **17 points jamais couverts** | ❌ | Z50-005 |
| 10 | §4.7 — 34 composants | audités + emploi | 34/34 (`agent-27`) | ✅ | — |
| 11 | §4.9 — 58 migrations | grille §5.3 | 58/58, 5 colonnes ciblées (9a/9b/9c/13/22) | ✅ | — |
| 12 | §4.10 — 17 workflows | ce qu'ils exécutent | 17/17 (`agent-38`) | ✅ | — |
| 13 | §4.11 — côté site | 16 points de capture, 12→17 écrans | rendus (`agent-31/32/33`) | ✅ | — |
| 14 | **11 policies** (§4.4) | 11 × routes × rôles | **2/11 nommées** ; agent 36 a mesuré, **n'a rien rendu** | ❌ | Z50-001 |
| 15 | §5.1 grille ÉCRAN | 25 pts × 37 | 8 pts × 37 | ❌ | Z50-005 |
| 16 | §5.2 grille ROUTE | 18 pts × 117 | 18 pts × 117, 0 vide | ⚠️ | Z50-006 |
| 17 | §5.3 grille MODÈLE/TABLE | 14 pts × 18 + 58 | complète | ✅ | — |
| 18 | §5.4 grille AUTOMATISME | 12 pts × (35+6+49+workers+webhooks) | tâches et jobs ✅ ; **workers et webhooks absents** | ⚠️ | Z50-003 |
| 19 | §5.5 grille FONCTIONNALITÉ + **matrice de raccordement** | 23 fonctionnalités × 12 pts + matrice N×N | **`fonctionnalites.md` ABSENT · `raccordement.md` ABSENT** | ❌ | Z50-004 |
| 20 | §5.6 grille PARCOURS | 13 parcours, clics comptés | **`parcours.md` ABSENT** ; matrice §C : 13/13 lignes ⏳ | ❌ | Z50-004 |
| 21 | §6 — `10_NAVIGATION-CIBLE.md` | produit **et appliqué** | produit (73 Ko) ; **refonte non appliquée, 0 redirection écrite, visite guidée non refaite, test des 10 intentions non rejoué** | ⚠️ | Z50-016 |
| 22 | §7 — 46 spécialistes | 46 rapports | **31 rendus** (dont 1 squelette vide), **15 manquants** | ❌ | Z50-007, Z50-008 |
| 23 | §8 P0 — amorçage | 5 gestes | joué, écarts publiés | ✅ | — |
| 24 | §8 P1 — fan-out | 46 agents, 5 vagues | vagues (a) et (b) ; (c)(d)(e) **incomplètes** | ⚠️ | Z50-007 |
| 25 | §8 P2 — consolidation | dédoublonnage complet | fait à 13:38 local, **périmé de 6 S0 à 12:06Z** | ⚠️ | Z50-010 |
| 26 | §8 P3 — correction | S0→S3, aucun S0/S1 ouvert | **0 correctif, 0 PR, 0 test vu rouge puis vert** | ❌ | Z50-018 |
| 27 | §8 P4 — vérification (+17) | chaque constat rejoué | **0 champ « Vérifié par » au sens P4** | ❌ | Z50-018 |
| 28 | §8 P5 — adversariale (+29) | second audit complet | **jamais lancée** ; `08_…md` absent | ❌ | Z50-018 |
| 29 | §8 P6 — regard neuf | agents neufs, audit de zéro | **jamais lancée** ; `09_…md` absent | ❌ | Z50-018 |
| 30 | §8 P7 — clôture | verdict en toutes lettres | `07_RAPPORT-FINAL.md` **absent** | ❌ | Z50-019 |
| 31 | §9 — registre unique | tous les constats, dédoublonnés | **34 constats hors registre**, dont **1 S0** | ❌ | Z50-009 |
| 32 | §9 — `04_PREUVES/` | sorties brutes, **captures** | 596 fichiers · **12 captures, toutes de l'agent 23**, **0 pour les 37 écrans** | ⚠️ | Z50-012 |
| 33 | §11 — 37 écrans à la main | connecté, TLS, 21 parcours | ouverts **non connectés, en HTTP, API injoignable** ; **0 des 21 parcours joué** | ❌ | Z50-014 |
| 34 | §12 — définition de fini | 16 points | **2 tenus sur 16** | ❌ | §8 ci-dessous |
| 35 | §29 CDC — 25 critères | mesurés, chiffres archivés | **5 mesurés · 8 hors périmètre motivé · 12 en blanc** | ❌ | Z50-011 |
| 36 | Doctrine règle 2 (« vue rougir ») | par garde | agent 45 (le crible) **a rendu un squelette vide** | ❌ | Z50-008 |
| 37 | Doctrine règle 7 (celui qui réalise ne vérifie pas) | rotation | respectée **dès P1** (4 corrections du chef sur lui-même) | ✅ | — |
| 38 | Doctrine règle 8 (on n'invente pas) | appliquée aux correctifs | respectée par les correctifs ; **violée par le produit en 12 endroits mesurés** | ⚠️ | Z50-017 |
| 39 | Solidité des constats | commande jouée + témoin | **13 constats fragiles identifiés**, dont **4 S0** | ❌ | Z50-015 |
| 40 | Cohérence inter-documents | un chiffre = un chiffre | **4 décomptes de A-011 · 3 tailles du même journal · 12 S0 vs 22** | ❌ | Z50-013, Z50-020 |

---

## 2. LE §4, ÉLÉMENT PAR ÉLÉMENT — combien traités, lesquels manquent

**Méthode, et sa limite, déclarée avant le résultat.** J'ai extrait du **code** la liste réelle de
chaque catégorie, puis cherché chaque nom dans le corpus de l'audit (`11_GRILLES/*.md` +
`02_CONSTATS.md` + `01_INVENTAIRE.md` + `10_NAVIGATION-CIBLE.md` + `02bis` + `03_MATRICE`,
**1 990 578 octets**). **Être nommé n'est pas être audité** : ce décompte est donc un **majorant
généreux**. Là où il rend zéro, il n'y a aucun doute possible.

**Témoin de la méthode** : la même passe rend **34/34** sur les composants et **17/17** sur les
workflows — le crible n'accuse donc pas tout le monde en bloc. Et elle rend **0/9** sur les policies,
alors que ces neuf fichiers existent : le crible sait distinguer.

| Catégorie du §4 | Recompté dans le code | **Nommés** dans un rapport | **Dans une grille** | Manquants, nommément |
|---|---|---|---|---|
| §4.1 modèles | 18 | 18 | **18** | — |
| §4.2 contrôleurs | 44 (42 réels) | 43 | **0** | `ReferentielsGeoController` |
| §4.3 routes | 117 | 117 | **117** | — |
| §4.4 services | 84 | 60 | **0** | `AnthropicProvider` · `AutoTagApplier` · `AutoTaggerService` · `EmailConfidenceService` · `EmailFinderService` · `GroqProvider` · `HunterEmailVerifier` · `MistralProvider` · `MockAnnuaireEntreprisesClient` · `MockBanGeocoder` · `MxEmailValidator` · `OpenAIProvider` · `PlaywrightDirectionFinder` · `PlaywrightGoogleMapsScraper` · `PlaywrightPagesJaunesScraper` · `PlaywrightSearchEngine` · `PlaywrightWebsiteScraper` · `ProviderFactory` · `SearchEngineRotator` · `SectorClassifier` · `TogetherProvider` · `TriageAutoService` · `WeightedRoundRobin` · `ZoneRotator` |
| §4.4 policies | 11 | **2** (`AuditLogPolicy`, `BasePolicy`) | **0** | `CompanyPolicy` · `ContactPolicy` · `LlmUseCasePolicy` · `ProxyProviderPolicy` · `RgpdRequestPolicy` · `ScraperRunPolicy` · `TagPolicy` · `UserPolicy` · `WorkspacePolicy` |
| §4.5 tâches planifiées | 35 | 35 | **35** | — |
| §4.5 jobs | 6 (+1 trait) | 7 | **7** | — |
| §4.5 commandes | 49 | 49 | **33** (les planifiées) | 16 commandes non planifiées n'ont pas les 12 points ; les 14 dangereuses n'ont que 4 points |
| §4.6 workers | 34 fichiers | **12** | **0** | `bridge/redis.ts` · `utils/extract.ts` · les **6 mocks** (`MockDirectionFinder`, `MockGoogleMaps`, `MockHttpSource`, `MockPagesJaunes`, `MockSearch`, `MockWebsite`) · les **14 scrapers** (`crunchbase`, `direction-finder`, `france-travail`, `google-maps.playwright`, `google-maps.worker`, `google-search.playwright`, `google-search.worker`, `infogreffe`, `mesri`, `pages-jaunes.playwright`, `pages-jaunes.worker`, `social-light`, `societe-com`, `website.worker`) |
| §4.7 écrans | 37 | 37 | **37** (8 pts sur 25) | — |
| §4.7 composants | 34 | 34 | **34** | — |
| §4.9 migrations | 58 | 58 | **58** | — |
| §4.10 workflows | 17 | 17 | **17** | — |

**Ce que ce tableau dit, en une phrase** : sur les treize listes du §4, **six sont réellement
traitées objet par objet** (modèles, routes, tâches, jobs, écrans, composants, migrations,
workflows), **trois ne le sont qu'en agrégat** (commandes, contrôleurs), et **trois ne le sont pas
du tout** (services, policies, workers). Les **9 policies absentes sont le trou le plus grave** :
elles portent l'autorisation, `B12-003` mesure qu'**aucune n'est jamais appelée**, et l'agent chargé
de les éprouver **a joué les mesures et n'a rien rendu**.

---

## 3. LES SIX GRILLES DU §5 — remplies ? cases vides ? cases absentes ?

Le §5 est explicite : *« une case "non vérifié" est acceptable et honnête ; une case absente ne
l'est pas »*. Les deux se comptent séparément.

| Grille | Fichier | Objets exigés | Objets couverts | Points exigés | Points rendus | **Cases vides** | **Cases absentes** |
|---|---|---|---|---|---|---|---|
| §5.1 **ÉCRAN** | `ecrans.md` | 37 | **37/37** | **25** | **8** | **0** | **37 × 17 = 629** |
| §5.2 **ROUTE** | `routes.md` | 117 | **117/117** | 18 | **18** | **0** *(vérifié)* | **0** |
| §5.3 **MODÈLE/TABLE** | `tables.md` | 18 modèles + 58 migrations | **18/18 · 58/58** | 14 | 14 (modèles) · 5 ciblés (migrations) | **0** | 0 |
| §5.4 **AUTOMATISME** | `automatismes.md` | 35 + 6 + 49 + 34 workers + 5 webhooks | **35/35 · 7/7 · 49/49 classées · 0/34 workers · 0/5 webhooks** | 12 | 12 (3 factorisés, motivés) | **0** | **34 × 12 = 408** (workers) + **5 × 12 = 60** (webhooks) + **16 × 12 = 192** (commandes non planifiées) |
| §5.5 **FONCTIONNALITÉ + RACCORDEMENT** | `fonctionnalites.md`, `raccordement.md` | 23 fonctionnalités × 12 pts + matrice 23×23 | **0** | 12 | **0** | — | **276 + la matrice entière** |
| §5.6 **PARCOURS** | `parcours.md` | 13 parcours | **0** | existe/partiel/absent + clics ordinateur et 375 px | **0** | — | **13 × 3 = 39** |

### 3.1 `routes.md` — vérifié par échantillon, comme demandé, et non cru

```
lignes du tableau (hors en-tête et séparateur) : 117
nombre de « | » par ligne                       : 21 sur 117 lignes  -> 20 colonnes uniformes
                                                  (Méthode + Route + les 18 points)
cases vides (motif « |  | »)                    : 0
```

**La promesse est tenue à la lettre : 117 lignes, 18 points, zéro case vide.** C'est mesuré, pas cru.

**Mais la lettre et l'esprit divergent, et c'est chiffrable.** Nombre de **valeurs distinctes** par
colonne, sur 117 lignes :

| Point | Valeurs distinctes | Ce que cela veut dire |
|---|---|---|
| 1 — authentification | **2** | binaire, légitime |
| **2 — autorisation vérifiée ET testée** | **3** | la même phrase, 117 fois : *« non — 0 `authorize()`/`Gate::` dans les 42 contrôleurs »* |
| 3 — contexte d'espace | 7 | — |
| **4 — autre espace ⇒ 0 ligne (test qui rougit)** | **6** | **18 « non vérifié »** ; les autres portent *« oui par construction — non joué route par route »*. **Le test qui rougit a été joué sur UNE route sur 117** |
| 5 à 9, 11, 12, 14, 18 | 14 à 42 | réellement instruits, route par route |
| **10 — index derrière la requête (`EXPLAIN`)** | **2** | **113/117 « non vérifié — hors budget agent 12 »** |
| 13 — journal d'audit | 2 | binaire, dérivé du middleware |
| 15 — limitation de débit | 7 | — |
| 16 — signature (routes internes) | 4 | 113 « sans objet », 4 instruits |
| **17 — test automatisé, ET VU ROUGE** | **2** | **75 « rougeur non vérifiée » + 42 « aucun test ne cite la route »** → **0 ligne sur 117 où une garde a été vue rougir** |

**Verdict honnête** : `routes.md` est le meilleur livrable du dossier et sa promesse est exacte.
Mais **deux des dix-huit points (4 et 17) sont les deux qui portent la doctrine — « une garde ne vaut
que si on l'a vue rougir » — et ils sont à zéro sur 117 lignes.** L'agent le déclare lui-même au §6
de son rapport, sans se cacher. **Ce n'est pas un défaut de l'agent, c'est un trou de l'audit** : le
budget qui manquait (`EXPLAIN`, suite Pest) était le périmètre des agents **41** et **45** — qui
n'ont, l'un, rendu qu'un répertoire de preuves sans rapport, l'autre, un squelette vide.

### 3.2 `ecrans.md` — 37/37 objets, 8 points sur 25

37 lignes, 9 « | » par ligne, **0 case vide**. Colonnes rendues : *route · composant · données
consommées · actions offertes · permissions · états gérés · atteignable · verdict*.

**Les 17 points du §5.1 qui n'existent pour aucun écran** : 2 (titre en français courant) · 3 (fil
d'Ariane, retour arrière, état des filtres) · 4 (une seule action principale) · 6 (formats : dates,
fuseau, montants, téléphones, casse) · 7 (tri/filtres dans l'URL, partageable, rechargeable) ·
8 (0 / 1 / 100 / 10 000 / 100 000 lignes) · 14 (chaque bouton essayé un par un) · 15 (bouton
désactivé : sait-on pourquoi ?) · 16 (retour immédiat, annulation après action destructrice) ·
17 (actions de masse : décompte exact, aperçu, réversibilité) · 18 (refus silencieux) · 19 (perte de
saisie : rechargement forcé, coupure réseau) · 20 (design system ou balisage recopié) · 21 (mode
sombre, contrastes, densité) · 22 (**375 px**) · 23 (**clavier seul, focus, ARIA**) · 24 (i18n).

Les points 20 et 21 sont **partiellement rattrapés** par l'agent 27 (design system), et le 24 par
les preuves orphelines de l'agent 29. **Les quinze autres ne sont couverts par personne**, parce que
les agents 24, 25, 26, 28, 30 n'ont pas rendu.

---

## 4. LES 25 CRITÈRES DU §29 — mesurés, non mesurables avec raison, laissés en blanc

Source : `03_MATRICE-EXIGENCES.md` §B, **recoupé avec `02_CONSTATS.md`** — parce que la matrice est
périmée de plus d'une heure sur son propre registre.

| État | N | Critères |
|---|---|---|
| ✅ **Mesuré et TENU** | **1** | **11** (sauvegarde restaurée : 724 926 343 o, **20 726 338 lignes, écart nul**, témoin négatif joué — agent 8) |
| ❌ **Mesuré et NON TENU** | **4** | **2** (console §19 inexistante) · **4** (0 `person_key` sur 1 319 567) · **17** (`php -S`, escalier de 15 ms, **inatteignable par construction**) · **18** (3 événements sur 3 en arbitrage, 0 fiche en 5 jours) |
| 🔵 **Non mesurable, raison écrite** | **8** | **7 · 8 · 12 · 15 · 19 · 20 · 21 · 22** (portent sur les étapes 4, 5 et 6 du §27, non ouvertes) |
| ⏳ **LAISSÉS EN BLANC** | **12** | **1 · 3 · 5 · 6 · 9 · 10 · 13 · 14 · 16 · 23 · 24 · 25** |

**Détail des douze blancs, et de l'agent qui devait les remplir** :

| # | Critère | Agent responsable | État de l'agent |
|---|---|---|---|
| 1 | Toute personne retrouvable en < 5 s | 41 / 42 | **41 : preuves seules, aucun rapport · 42 : jamais rendu** |
| 3 | Aucune saisie n'est perdue | 26 | **jamais rendu** |
| 5 | Tout événement traverse en < 1 min | 13 / 14 / 49 | rendus — **mais aucun n'a chiffré la latence** ; I49-008 dit « hors budget par construction » sans mesure de bout en bout |
| 6 | Toute information se modifie sur place | 26 | **jamais rendu** |
| 9 | Toute donnée personnelle s'exporte et s'efface | 15 | rendu (B15-003 : **4 tables sur 31**) — **le critère reste ⏳ dans la matrice** |
| 10 | Chaque parcours du §23.4 dans son budget | 24 | **jamais rendu** → grille §5.6 absente |
| 13 | Le canal se surveille | 14 / 31 | rendus (B14-004 : aucune alerte) — **non reporté en critère** |
| 14 | Aucun écran ne déroge au langage visuel | 27 | rendu — **non reporté en critère** |
| 16 | Les horodatages sont justes | 8 / 29 | **29 : preuves seules** (`bascules-AVEC/SANS-db-timezone.txt`), **aucun rapport** |
| 23 | On ne se perd pas entre les deux consoles | 23 / 32 | rendus (E32-005 : **écart 17 sur 17**) ; I49-004 le déclare **injouable** — **non reporté** |
| 24 | La barre latérale se comprend seule | 23 | rendu (**4 trouvables · 4 avec effort · 5 introuvables · 7 impossibles ; 0 sur 2** sur les exemples littéraux) — **non reporté** |
| 25 | Le retrait des écrans ne casse rien | 32 / 34 | **34 : preuves seules, aucun rapport** ; E32-004 mesure que **le drapeau n'existe pas** |

🔴 **Le fait le plus gênant de cette section n'est pas le nombre de blancs : c'est que sept de ces
douze critères ont été mesurés et ne sont pas rapportés comme tels.** La matrice, dernière écriture
**une heure avant** ma mesure, ignore le critère 11 (TENU, prouvé) et les critères 5, 18, 23, 24
(mesurés par les agents 22, 23, 32, 49). Elle affirme aussi « **9 sont hors périmètre** » quand elle
en porte **8**. *C'est le patron `A-013` — « le problème est un problème de clôture » — appliqué au
document que l'audit a écrit pour s'en prémunir.*

---

## 5. LES AGENTS NON RENDUS — nommément, et ce que leur absence laisse en blanc

**31 spécialistes sur 46 ont produit un livrable** (dont un vide). **15 manquent.**
Six d'entre eux ont **archivé des preuves brutes et n'ont rien conclu** : leur mesure existe, en
fichiers, et n'est reliée à aucun verdict, aucun constat, aucune ligne de grille.

| # | Rôle (§7) | Livrable | Preuves | Ce que l'absence laisse en blanc |
|---|---|---|---|---|
| **20** | Enrichissement / classification / LLM | ❌ | ❌ | Les **5 fournisseurs LLM**, `LLMRouterService`, `ProviderFactory`, les plafonds de coût, et surtout le **tirage pondéré en fractions** que le mandat signale comme piège (« toujours la première clé, en silence ») : `WeightedRoundRobin`, `ZoneRotator`, `SearchEngineRotator` — **les 3 rotateurs ne sont nommés nulle part dans le dossier.** |
| **21** | Qualité des données | ❌ | ✅ **9 fichiers** (`prod_doublons_*`, `prod_encodage`, `prod_casse_sql_vs_php`, `prod_quality_score`) | Doublons sur 4,3 M de lignes, complétude, `QualityScore`, translittération. **Les mesures sont là, la conclusion n'existe pas.** |
| **24** | Parcours | ❌ | ❌ | **La grille §5.6 entière** : les 13 parcours du §23.4, clics comptés, ordinateur et 375 px. C'est le tableau que le mandat désigne comme celui « qui dit si le CDC peut s'ouvrir ». |
| **25** | États d'écran | ❌ | ❌ | Les cinq états sur 37 écrans, listes à 0 / 1 / 100 / 10 000 / 100 000 lignes, URL partageable. |
| **26** | Formulaires et saisie | ❌ | ❌ | **Refus silencieux** (piège nommé), perte de saisie, `CampaignWizardPage` (assistant complet), `AudienceBuilderPage`, `SettingsPage`. → **critères 3 et 6 en blanc**. |
| **28** | Accessibilité | ❌ | ❌ | Clavier, focus, pièges de focus, ARIA, contrastes. **Point 23 de la grille écran, 37 écrans.** |
| **29** | i18n et temps | ❌ | ✅ **13 fichiers** (`bascules-AVEC/SANS-db-timezone`, `chaines-en-dur-detail`, `colonnes-dates-detail`, `par-ecran.md`) | Les **deux bascules d'heure** du critère 16, les chaînes en dur, `fr.json`/`en.json`. **Mesuré, jamais conclu.** |
| **30** | Mobile / responsive | ❌ | ❌ | Les 37 écrans en **375 px**, la barre basse à cinq entrées. **Point 22 de la grille écran.** |
| **34** | Non-régression console | ❌ | ✅ **9 fichiers** (`vitest-full.log`, `couverture-test-directe`) | Devis, factures, échéanciers, Qualiopi, banque d'images : **rien ne dit que les correctifs CRM ne les cassent pas**. → **critère 25**. |
| **36** | Permissions | ❌ | ✅ **9 fichiers**, dont `08-test-qui-rougit.txt` (un `viewer` **exporte 4,29 M de fiches**, 200 au lieu de 403) et `09-policies-inertes.txt` | **Les 11 policies × 117 routes × les rôles.** L'agent a créé un compte restreint, s'est connecté avec, a mesuré l'escalade, **et a vu un test rougir** — le seul de tout l'audit à l'avoir fait sur ce sujet. **Rien n'est publié.** |
| **39** | Sauvegardes et observabilité | ❌ | ✅ 2 fichiers | `infra/monitoring`, `infra/runbooks`, Sentry, seuils d'alerte. Partiellement couvert par l'agent 8. |
| **41** | Requêtes / `EXPLAIN` | ❌ | ⚠️ 1 répertoire | **Les 113 cases « non vérifié » du point 10 de `routes.md`** ; les compteurs du hub ; l'export CSV linéaire ; les index d'août. → **critère 1**. |
| **42** | Performance d'interface | ❌ | ❌ | Taille des paquets, découpage, listes longues, recherche à la frappe, `FranceCoverageMap`. |
| **43** | Charge et concurrence | ❌ | ❌ | `load-tests/`, 10 sessions simultanées, édition concurrente. → **critères 1, 9, 17**. *(Atténué : A-010 rend le critère 17 inatteignable par construction — mais la dégradation reste non chiffrée.)* |
| **45** | **Valeur des tests** | ⚠️ **squelette** | ✅ 6 fichiers | **Le crible que `A-011` lui délègue explicitement** (« passer les gardes existantes au crible, une par une — c'est le périmètre de l'agent 45 »). Voir Z50-008. |

**Lecture d'ensemble** : les blocs **D (interface)** et **G (performance)** sont **quasi entiers en
blanc** — 5 agents sur 9 pour D, **3 sur 3 pour G**. Or ce sont précisément les deux blocs que le
mandat conditionne à un terrain praticable (§8 P1 : *« bloc D après que la console tourne en
local »*, *« bloc G après le jeu de données »*). **La console ne tourne pas** (Z50-014) et l'atelier
sérialise (A-009) : les deux blocs ont été **empêchés, pas oubliés**. C'est une raison, ce n'est pas
un remplacement.

---

## 6. LES CONSTATS FRAGILES — le cœur de ce rapport

Critères de fragilité, appliqués un par un : **(a)** aucune commande jouée · **(b)** pas de témoin
négatif alors que le constat conclut « rien trouvé » · **(c)** lecture de code là où le constat
prétend mesurer un comportement · **(d)** conclusion au-delà de ce que la mesure établit.

**Je les ai cherchés chez le chef de chantier en premier**, puisqu'il s'est déjà fait prendre cinq
fois. **Deux des quatre plus graves sont les siens ou de sa couche de consolidation.**

| # | Constat | Sév. annoncée | Faiblesse **exacte** | Critère | Ce qu'il faut jouer pour le sauver |
|---|---|---|---|---|---|
| **1** | **A-002** — « `GET /saved-views` **répond** 200 avec une liste vide » | S2 | Le champ **Preuve** dit : *« lecture du fichier + `php artisan route:list --path=saved-views »*. **Aucun appel HTTP n'a été joué.** Et la **Reproduction** prescrite — *« `GET /api/v1/saved-views` avec une session valide »* — est **impossible** : `A-012` + `A07-001` établissent qu'aucune session utilisable n'existe. Le constat affirme un **comportement HTTP** sur la foi d'un `return $this->ok(['data' => []])`. | **(c)** | Un `curl` authentifié, une fois `A07-001` corrigé. |
| **2** | **B10-013** — « `/ai-act/register` et la recherche globale mentent exactement comme `/saved-views` » | S2 | Même patron, hérité du précédent. `routes.md` porte *« FACTICE MAIS 200 — `{data:[]}` en dur (GET) »* : c'est une **lecture du corps de la méthode**, pas une réponse observée. Aggravant : il s'agit d'un **registre réglementaire** — la sévérité repose sur un comportement jamais constaté. | **(c)** | idem. |
| **3** | **B12-007** — « Dix routes répondent 200 avec un corps figé, dont un contrôle de santé qui dit toujours "en bonne santé" » | S1 | Le décompte vient de la **colonne 18 générée** de `routes.md`, remplie par lecture de code (`04_PREUVES/agent-12/grille.py` + `grille_data.py`). L'agent déclare lui-même au §6-8 : *« je n'ai pas vérifié quelles routes la console appelle réellement […] cela change la gravité de plusieurs constats ci-dessus — **en particulier B12-007** »*. **Il désigne son propre constat comme le plus exposé.** | **(c)** + **(d)** | 10 appels HTTP, et la liste des routes réellement appelées par le SPA. |
| **4** | **A-005** — « `/cold-email` et `/linkedin` **restent joignables par URL** » | S3 | Preuve = `routeTree.tsx:102` + `api.php:299`. **Reproduction** = *« ouvrir `https://app.localhost/cold-email` »* — **jamais jouée**. Et à l'heure du constat, l'atelier servait un bundle **vieux de 32 h** (`D23-001`) : le routeur du bundle servi n'était pas celui du code lu. | **(a)** + **(c)** | Ouvrir les deux URL sur le bundle reconstruit. |
| **5** | 🔴 **B16-002 et B16-003 portés en production** | **S0** | Les deux sont mesurés proprement — **sur une base jetable locale**. Le registre conclut ensuite : *« ils ne dépendent d'aucun secret — ils tiennent en production comme ailleurs »*. **C'est un raisonnement, pas une mesure**, et il franchit exactement la ligne que le §5 bis-1 du dossier commun interdit (« l'atelier et la production n'exécutent pas la même chose »). Le schéma **diffère** entre les deux : `A08-004` mesure `audit_logs` **partitionnée en CI, ordinaire en production**. Or la troncature par la queue **dépend du partitionnement**. | **(d)** | Rejouer la troncature et la réécriture d'horodatage **sur une copie du schéma de production**, en lecture d'un dump. |
| **6** | 🔴 **B12-001** — « `GET /companies/{id}` rend la fiche d'un autre espace — **200 mesuré** » | **S0** | La mesure est réelle et le témoin positif est joué — **en local**, où `CRM_DB_APP_ROLE_ENABLED = false`, alors qu'il vaut **`true` en production** (`B11-010`). L'agent 12 pose la réserve **lui-même** (§6-4 : *« si la production les surcharge, leur gravité y baisse — et personne ne pourra le dire sans regarder »*). **`02bis` le consolide en S0 distinct sans reporter cette réserve.** | **(d)** — au niveau de la consolidation, pas de l'agent | Rejouer sur une pile armée comme la production, ou une lecture seule en production. |
| **7** | 🔴 **B12-003** — « un `viewer` a supprimé définitivement une entreprise » | **S0** | Même atelier, même réserve non reportée. Aggravant : la preuve la plus proche de ce constat — l'escalade réellement mesurée avec un compte `viewer` — est dans `04_PREUVES/agent-36/`, **d'un agent qui n'a pas rendu**. Le constat le plus important sur les permissions repose donc sur l'agent qui **n'était pas** l'auditeur des permissions. | **(d)** | Publier l'agent 36 ; rejouer en configuration de production. |
| **8** | 🔴 **B12-004** — « signature forgée acceptée » | **S0** | Le constat original repose sur un secret **vide dans `.env.example`**, avec la réserve de l'agent. **Il a depuis été levé par `F37-001`**, qui mesure le secret **vide sur le serveur de production**. **Mais `02bis`, `_DOSSIER-AGENT.md` et la liste des S0 citent encore B12-004 seul**, sans F37-001 : la couche consolidée est **en retard sur sa propre preuve**, et sous-estime un S0 devenu pire. | **(d)** | Mettre `02bis` à jour. Rien à rejouer : la mesure existe. |
| **9** | **A-010, impact 1** — « une seule ouverture de la console après une purge de cache **gèle l'application entière** pendant 17,5 s » | S0 | **La sérialisation est solidement établie** (escalier de 15 ms + témoin séquentiel plat, même conteneur, même minute). **Le 17,5 s ne l'est pas** : il vient du journal d'étape 1a §2.11, mesuré sur **2,8 M de fiches dans un autre système**, avant la pièce 1 (index couvrant + `Cache::flexible`) — que la production porte. La phrase **compose deux mesures prises sur deux systèmes à deux états du code**. Le constat S0 survit entièrement sans elle. | **(d)** | Chronométrer les compteurs du hub **en production, cache froid**, en lecture seule. |
| **10** | **A-003, impact** — « c'est la mécanique exacte qui a rendu un script inexécutable sur le serveur le 19/08 » | S2 | La **mesure** est exemplaire (octets `0x0d`, méthode validée sur un témoin pur LF et un témoin pur CRLF **avant** usage). L'**attribution causale** ne l'est pas : aucun des 8 fichiers listés n'a été exécuté sur un Linux pour observer le `$'\r': command not found`. | **(d)**, mineur | Exécuter `dr-drill.sh` dans un conteneur Linux jetable. |
| **11** | **H47 — « ZÉRO alerte atteignable en production »** | conclusion de « rien trouvé » | Trois axes. **Un seul porte un témoin** (`vite build` rejoué, marqueurs à 0 avec témoin positif `XMLHttpRequest = 16`). Les deux autres — *« `workers/` n'est déployé par aucun compose hors tests »* et *« `@vitest/ui` n'est installé nulle part »* — sont des recherches **à 0 résultat sans témoin négatif déclaré**. La règle 3 exige que le contrôle soit d'abord **prouvé capable de rendre 1**. | **(b)** | Deux témoins : un compose qui déploie bien `workers`, un paquet réellement installé. |
| **12** | **D22-006** — « 33 écrans sur 37 n'interrogent jamais rôle ni permission » | S2 | Le constat est juste **et l'agent est honnête** : il écrit au §4-2 que le point est *« établi par lecture exhaustive du code (grep avec témoin négatif), **pas** par un clic refusé à l'écran »*. **Le registre le présente comme « la moitié interface de B12-003 »** et laisse tomber la réserve. La faiblesse est dans la **reprise**, pas dans la mesure. | **(c)**, au niveau du registre | Rien à rejouer : reporter la réserve. |
| **13** | 🔴 **`02bis` §5 — « Ce qui est SAIN, et qu'aucun correctif ne doit casser » : « La CI backend, bloquante et requise »** | — | **Le document se contredit avec ses propres constats.** `F38-002` mesure **4 contextes requis sur 36 jobs** et **`enforce_admins: false`** ; `A08-005` mesure **3 des 6 jobs de CI qui ne bloquent aucune fusion, dont les gardes nées des deux incidents les plus graves** ; `H44-003` mesure un déploiement de préproduction **sans `needs: ci`**. Classer cette CI parmi ce qu'il ne faut pas toucher, c'est **poser une case ✅ sur un refus de conclure** : le patron `A-013`, rejoué par la couche de résumé qui l'énonce. | **(d)** | Réécrire la ligne : *« la CI **exécute** réellement 780 tests ; elle ne **bloque** presque rien »*. |

### 6.1 Deux constats que j'ai soupçonnés et qui **résistent** — je le dis aussi

- **`D27-002`** (les 4 `!important` qui neutralisent 174 déclarations `dark:`). Le résumé de
  `02_CONSTATS.md` écrit que l'agent 27 *« déclare n'avoir ouvert aucun écran pour de vrai »*, ce qui
  contredit le mot « mesuré en navigateur ». **J'ai ouvert la preuve** :
  `04_PREUVES/agent-27/05_navigateur-important-vs-dark.txt` porte l'identifiant d'onglet, les
  couleurs résolues en sRGB via un canvas 1×1, **et un témoin négatif joué dans la même session**
  (sur `bg-slate-100 dark:bg-slate-800`, une utilitaire hors des 4 règles, **la variante `dark:`
  gagne**). **Le constat tient entièrement. C'est le résumé qui est faux.**
- **`C19-004`** (« aucun collecteur ne lit le `robots.txt` ») porte son témoin négatif — la même
  recherche trouve bien `frontend/public/robots.txt`. **Modèle du genre.**

### 6.2 Un désaccord de mesure entre deux agents, non tranché, et qui a produit une demande au dirigeant

L'agent 22 mesure que **Chrome refuse `https://app.localhost`** (certificat de l'autorité Caddy
absent du magasin Windows) et en tire le geste **C0** de `06_RESTE-WILL.md` : *« sans lui, aucun
agent ne peut exécuter le §11 »*. **L'agent 27, à la même référence, a mesuré dans un vrai
navigateur sur `https://app.localhost`** et en a rapporté des couleurs résolues.
**Les deux ne peuvent pas être vrais.** Aucun des deux ne cite l'autre, et le désaccord n'a pas été
tranché — alors que la règle 9 l'exige, **par une mesure**. Conséquence concrète : **le dirigeant
est sollicité pour une modification de sécurité de sa machine sur la foi d'une mesure peut-être
inutile.** → `Z50-021`.

---

## 7. RÈGLE 8 — ce qui est réinventé

**La question posée** : *un agent a-t-il proposé un correctif là où la brique existe déjà ?*
**Réponse mesurée : non, et c'est à porter au crédit de l'audit.** Les correctifs les plus lourds
nomment explicitement la brique existante avant de proposer quoi que ce soit :

- `A-010` → *« php-fpm **est déjà dans l'image** — la brique existe, il n'y a rien à inventer »* ;
- `A-007` → *« la parade existe déjà et est motivée dans `docker-compose.local.yml` »* ;
- `A-004` → *« la garde CI `caddy validate` existe déjà (`ci.yml`, job `caddyfile-valide`),
  **s'appuyer dessus, ne pas la réinventer** »*.

**Un seul cas discutable** : l'agent 40 a **écrit** `infra/scripts/verifier-serveur-http.sh` pendant
**P1**, alors que `infra/scripts/verifier-ports-publies.sh` existait. Vérifié : les deux mesurent des
objets **différents** (le `/proc/1/cmdline` des conteneurs vs les ports publiés) — c'est une
**extension**, pas une réinvention. Le vrai écart est de **phase**, pas de règle 8 : un auditeur a
réalisé une pièce en P1, ce que le §7 réserve à P3 avec un réalisateur distinct.

**En revanche, la règle 8 est violée douze fois par le produit, et l'audit l'a bien vue** — c'est
l'un de ses meilleurs résultats, et il mérite d'être rassemblé, ce qu'aucun document ne fait :

| # | La brique qui existe | Ce qui a été refait à côté | Constat |
|---|---|---|---|
| 1 | `HmacSignature` (durcie, **fail-closed**) | HMAC **réimplémenté inline** dans `ScraperResultController`, **fail-open** | `B12-004` / `F37-001` (**S0**) |
| 2 | php-fpm, dans l'image | `php -S` mono-processus, en production | `A-010` (**S0**) |
| 3 | `Concerns/RunsInWorkspace` | **jamais emprunté** ; 5 dispatchs omettent le `workspaceId` | `B17-010` |
| 4 | `WorkspaceContext::runWithoutScope()` | **aucun appelant** | `B11-009` |
| 5 | `Stat` (composant) | **recopié à la main dans les 2 écrans qui en avaient besoin — et les 2 copies ont déjà divergé** | `D27-001` |
| 6 | `Input` / `FormField` | **30 champs bruts en 19 variantes** ; `FormField` employé par **1** écran | `D27-008` |
| 7 | `PageShell` / `PageHeader` / `Toolbar` | **23 écrans sur 37 recopient du balisage** (61 occurrences), et les copies **perdent l'anneau de focus, les rôles ARIA et le mode sombre** | `D27-004` |
| 8 | (aucun composant `Table`) | **le même en-tête de 210 caractères copié à l'identique dans 8 fichiers** | `D27-005` |
| 9 | `SsrfGuard` (PHP) | **dupliqué en TypeScript**, et les deux **ont divergé sur 6 entrées sur 13** — le commentaire affirme encore « équivalent fonctionnel » | `C19-002` |
| 10 | Jeton d'ombre du design system | **dupliqué en littéral, et déjà divergé** | `D27-007` |
| 11 | `db-rebuild-check` (garde existante) | **branchée dans aucun workflow** | `B10-015` |
| 12 | `axionia/scripts/e2e-crm-sync/mock-crm.ts` — **le harnais d'aller-retour signé** | **personne, dans tout cet audit, n'a joué l'aller-retour de bout en bout** alors que l'outil existe | `I49` §final |

**Le n° 12 est un manquement de l'audit lui-même, pas du produit**, et c'est l'agent 49 qui le
nomme : *« c'est le contrôle qui manque le plus à ce canal, et il est à portée de main »*.

---

## 8. LA DÉFINITION DE FINI DU §12 — les seize points, un par un

| # | Exigence | État réel au 12:06Z | Verdict |
|---|---|---|---|
| 1 | Chaque élément du §4 dans un tableau de suivi, **aucune ligne vide** | 8 listes sur 13 objet par objet ; **0/11 policies · 0/84 services · 0/34 workers · 0/44 contrôleurs** en grille | ❌ |
| 2 | Chaque grille du §5 remplie, case par case | **3 grilles sur 6 existent complètes** ; écran à 8 points sur 25 ; **fonctionnalités, raccordement, parcours absents** | ❌ |
| 3 | Chaque écran ouvert à la main, **captures archivées** | 37/37 ouverts **non connectés, en HTTP, API injoignable** ; **12 captures dans tout le dossier, toutes de l'agent 23, 0 pour les 37 écrans** | ❌ |
| 4 | `10_NAVIGATION-CIBLE.md` **produit ET la refonte appliquée** | document produit (73 Ko, plan de correspondance complet, 37 écrans tranchés) ; **refonte non appliquée · 8 redirections à écrire, 0 écrite · visite guidée non refaite · test des 10 intentions non rejoué après** | ⚠️ |
| 5 | Chaque S0 et S1 **corrigé**, test vu rouge puis vert | **0 correctif, 0 PR, 0 test vu rouge puis vert.** 22 identifiants S0 ouverts | ❌ |
| 6 | Les 16 lignes de l'étape 0 closes et mesurées | **7 CLOS · 7 PARTIELS · 2 OUVERTS** (agent 6), là où le journal annonçait 15/16 | ❌ |
| 7 | F1 → F15 levées ou arbitrées **sur `main` fusionné** | **5 levées · 7 partielles · 3 encore vraies** (agent 7) | ❌ |
| 8 | PR #174 et #735 fusionnées, `main` vert | **VRAI**, re-vérifié : PR #174 fusionnée le 18/08 18:44 UTC, 16/16 commits ancêtres, CI réelle sur la référence | ✅ |
| 9 | Les 57 alertes arbitrées, corrigées ou gelées | 57/57 mesurées, **0 atteignable en production** (3 mesures indépendantes), politique de gel écrite de 441 l. ; **la seule montée utile (`axios 1.16.1 → 1.18.0`) n'est pas faite**, et H47-005 (`workers/`, 32 alertes) n'est pas tranché | ⚠️ |
| 10 | Aucune route 501, entrée verrouillée ou écran factice sous un nom du CDC | **19 routes 501 + 9 routes « 200 corps figé » + 3 inertes** subsistent ; `/cold-email` et `/linkedin` conservés | ❌ |
| 11 | Matrice exigence → test → preuve **complète** | section C (13 parcours) **entièrement vide** ; section D (D-01→D-13) « ⏳ en cours » ; 12 critères en blanc ; **périmée d'une heure sur son propre registre** | ❌ |
| 12 | Critères du §29 applicables **mesurés**, chiffres archivés | **1 TENU · 4 NON TENUS · 8 hors périmètre motivé · 12 en blanc** | ❌ |
| 13 | **Une sauvegarde restaurée pour de vrai** | **724 926 343 octets, 20 726 338 lignes revenues au nombre exact, écart nul, témoin négatif joué** (agent 8). *Réserve de l'agent : copie locale, pas hors-site ; et `A08-008` — les droits ne sont pas restaurés.* | ✅ |
| 14 | P5 **puis** P6 menées **en entier**, la dernière sans rien ≥ S2 | **aucune des deux lancée** ; `08_…` et `09_…` absents | ❌ |
| 15 | Rapport final, verdict net par domaine | `07_RAPPORT-FINAL.md` **absent** | ❌ |
| 16 | `06_RESTE-WILL.md` en une page, sans redite, chaque ligne avec recommandation | **existe**, 6 sections (A1-A2, B1-B3, C0-C3, D, E, F), **chaque ligne porte une recommandation**, et il déclare en tête ce qu'il ne rouvre pas. Format tenu | ✅ |

**Score : 3 points tenus sur 16** (8, 13, 16), **2 partiels** (4, 9), **11 non tenus**.

---

## 9. L'ÉTAT RÉEL DES SEPT PHASES DU §8

| Phase | Exigé | État réel, mesuré | Preuve |
|---|---|---|---|
| **P0 — Amorçage** | dossier créé · état reconstruit depuis les dépôts · **terrain praticable** · listes recomptées · inventaire publié | ✅ **fait, et bien fait.** Référence relue et non crue (le document avait tort 3 fois) ; **7 écarts d'inventaire publiés** ; 4 constats tombés pendant l'amorçage, comme le §8 le prévoyait. ⚠️ **Le terrain n'est PAS praticable** : `migrate:fresh` ×2 **a échoué au premier passage** (RC1=1, RC2=1) et n'a réussi qu'après rattrapage ; la console **ne s'ouvre pas** ; l'API **sérialise** | `00_JOURNAL` 09:20→09:35Z · `tables.md` §1 |
| **P1 — Fan-out** | 46 spécialistes, 5 vagues | ⚠️ **31 rendus sur 46 (67 %)**, dont **1 squelette vide**. Vagues (a) et (b) parties ; **(c) bloc D incomplet · (d) bloc G jamais lancé · (e) bloc I partiel**. Plafond de 20 agents simultanés atteint, et l'atelier saturé par eux-mêmes (`A-009`, `H44-004`, `B11-005`) | `11_GRILLES/` (31 fichiers) |
| **P2 — Consolidation** | dédoublonnage, sévérités, ordonnancement | ⚠️ **fait et déjà périmé.** `02bis` (dernière écriture 13:38 local) consolide **12 S0 distincts** ; le registre en porte **22 identifiants** à 12:06Z. **6 S0 postérieurs jamais consolidés** : `B15-001`, `B15-003`, `B15-006`, `B15-010`, `D22-001`, `F37-001`. Et **34 constats de 3 agents ne sont jamais entrés au registre** | `02bis` vs `02_CONSTATS` |
| **P3 — Correction** | 1 lot = 1 branche = 1 PR · chaque correctif avec son test **vu rouge** · S0→S3 · aucun S0/S1 ouvert | ❌ **N'A PAS EU LIEU.** 0 branche de correction, 0 PR, 0 test vu rouge puis vert. Le seul commit de l'audit est **le dossier lui-même** (`8db8229`). L'ordonnancement des 11 lots existe (`02bis` §4) : c'est un plan, pas une exécution | `git log`, `gh pr list` |
| **P4 — Vérification (+17)** | chaque constat corrigé rejoué par un **autre** agent ; chaque garde vue rougir par lui | ❌ **N'A PAS EU LIEU** au sens du §8. ⚠️ **Mais une vérification croisée réelle a eu lieu dès P1**, et il faut le dire : l'agent 13 a corrigé `A-001`, l'agent 40 `A-004` et `A-007` et a **réfuté `B16-001` pour la production**, l'agent 22 a fermé la chaîne de `A-012`, l'agent 33 a réfuté deux affirmations du mandat, l'agent 37 a fait passer `B12-004` **de la déduction à la mesure**. **Le chef de chantier a été corrigé cinq fois, dont quatre par ses propres agents.** C'est la règle 7 qui fonctionne — ce n'est pas P4 | `02_CONSTATS`, champs « Vérifié par » |
| **P5 — Adversariale (+29)** | **second audit complet** mené pour réfuter le premier | ❌ **JAMAIS LANCÉE.** `08_PASSE-2-ADVERSARIALE.md` **n'existe pas**. Aucun champ « Réfuté par » n'est rempli dans tout le registre | — |
| **P6 — Regard neuf** | agents **neufs**, sans accès aux rapports, audit refait de zéro, puis comparaison des trois passes | ❌ **JAMAIS LANCÉE.** `09_PASSE-3-REGARD-NEUF.md` **n'existe pas**. Aucun champ « Passe 3 » n'est rempli | — |
| **P7 — Clôture** | `07_RAPPORT-FINAL.md` · matrice complète · restauration rejouée · verdict en toutes lettres | ❌ **N'A PAS EU LIEU.** `07_RAPPORT-FINAL.md` absent. **Aucun verdict de sortie n'existe.** Seule la restauration est faite (point 13) | — |

> **En une phrase : l'audit a fait P0, les deux tiers de P1, un P2 déjà périmé — et rien d'autre.
> Sur les sept phases, une et demie sont terminées.** Ce qui existe est d'une qualité inhabituelle ;
> ce qui n'existe pas, c'est **tout ce qui devait transformer les constats en corrections et les
> corrections en preuves**.

---

## 10. CONSTATS

### [Z50-001] Neuf des onze policies ne sont nommées dans aucun rapport, et l'agent qui les a mesurées n'a rien rendu
- Sévérité      : **S1**
- Domaine       : méthode / sécurité
- Référence     : `main 8db8229`, dossier arrêté au 2026-08-19T12:06Z
- Emplacement   : `backend/app/Policies/` (11 fichiers) vs `_AUDIT/2026-08-18_AUDIT-360/`
- Constat       : sur les 11 policies du §4.4, **2 seulement** (`AuditLogPolicy`, `BasePolicy`) sont nommées dans le corpus de l'audit ; les 9 autres n'y apparaissent pas une seule fois, et l'agent 36 (permissions) a archivé 9 fichiers de preuves sans produire ni rapport, ni grille, ni constat.
- Preuve        : recherche littérale de chaque nom de fichier de `backend/app/Policies/` dans un corpus concaténé de 1 990 578 octets (`11_GRILLES/*.md` + `02_CONSTATS.md` + `01_INVENTAIRE.md` + `10_NAVIGATION-CIBLE.md` + `02bis` + `03_MATRICE`) → **2 trouvées, 9 absentes**. `ls 04_PREUVES/agent-36/` → 10 fichiers, dont `08-test-qui-rougit.txt` et `09-policies-inertes.txt`. `ls 11_GRILLES/ | grep 36` → vide.
- Témoin négatif: la **même** recherche, sur la **même** passe, rend **34/34** sur les composants du §4.7 et **17/17** sur les workflows du §4.10. Le crible n'accuse donc pas tout le monde en bloc : il distingue.
- Impact        : `B12-003` — **S0**, « aucune policy n'est jamais appelée » — est le constat le plus lourd sur l'autorisation, et **aucun document ne dit lesquelles**, ni ce que chacune aurait autorisé. `B12-012` (`sameWorkspace()` toujours vrai) s'arme **au premier `authorize()`**, donc dès qu'on corrigera `B12-003` : sans la liste des 11 policies et de leurs méthodes, ce correctif se fera à l'aveugle. Le §12-1 exige « aucune ligne vide » : il y en a onze.
- Reproduction  : `find backend/app/Policies -name "*.php"` puis rechercher chaque nom dans `_AUDIT/2026-08-18_AUDIT-360/`.
- Correctif     : publier l'agent 36 — ses mesures existent, il manque le verdict. `04_PREUVES/agent-36/08-test-qui-rougit.txt` contient déjà **une garde vue rougir** (un `viewer` obtient 200 au lieu de 403 sur `GET /companies/export`, soit **4,29 M de fiches nominatives**) : c'est la seule de tout l'audit sur ce sujet. Coût : ~2 h de rédaction à partir des preuves existantes.
- Statut        : **ouvert**

---

### [Z50-002] Vingt-quatre des 84 services et aucun des 44 contrôleurs ne passent de grille
- Sévérité      : **S2**
- Domaine       : méthode
- Référence     : `main 8db8229`
- Constat       : le §4.4 impose d'auditer 84 services ; **60 sont nommés quelque part**, **24 ne le sont nulle part**, et **aucun** ne passe une grille. Aucun agent du §7 n'a les services pour périmètre — ils sont supposés couverts par ricochet. De même, 43 des 44 contrôleurs sont nommés (`ReferentielsGeoController` manque) mais aucun ne fait l'objet d'une ligne de grille : `routes.md` audite les **routes**, pas les **classes**.
- Preuve        : même méthode et même corpus que `Z50-001`. Les 24 absents sont nommés au §2 ci-dessus.
- Témoin négatif: `AuditHashChain`, `GdprErasureService`, `SsrfGuard`, `WaterfallOrchestrator`, `MockLLMClient` et 55 autres **sont** trouvés — le crible sait rendre 1.
- Impact        : le trou n'est pas uniforme, il est **thématique** : les 24 absents sont **les 5 fournisseurs LLM + `ProviderFactory` + les 3 rotateurs + les 5 scrapers Playwright + les validateurs d'adresse**. C'est exactement le périmètre de l'agent 20, jamais lancé — dont le mandat signale nommément le piège du **tirage pondéré en fractions** (« toujours la première clé, en silence »). **Ce piège nommé par le mandat n'a été cherché par personne.**
- Reproduction  : voir `Z50-001`.
- Correctif     : lancer l'agent 20 sur les 24 services orphelins, avec la grille §5.5. Coût : ~4 h.
- Statut        : **ouvert**

---

### [Z50-003] La grille AUTOMATISME n'a jamais touché les 34 workers ni les 5 webhooks
- Sévérité      : **S2**
- Domaine       : méthode / collecte
- Référence     : `main 8db8229`
- Emplacement   : `11_GRILLES/automatismes.md` vs `workers/src/` (34 fichiers)
- Constat       : le §5.4 applique ses 12 points « aux 10 tâches, 7 jobs, 49 commandes, **workers, webhooks** ». Les tâches et les jobs ont leur grille complète. **Les 34 fichiers workers n'ont aucune ligne** : 12 sont nommés au fil du texte, **22 ne le sont jamais**. Les 5 canaux et webhooks du §4.5 non plus. Et sur les 49 commandes, seules les **33 planifiées** portent les 12 points ; les 14 dangereuses en portent **4**, les 16 restantes **aucun**.
- Preuve        : `find workers/src -name "*.ts"` → 34 ; recherche de chaque nom de fichier dans le corpus → **12 trouvés, 22 absents** (liste nommée au §2). `awk` sur les tableaux d'`automatismes.md` : **35 lignes de tâches** (12 colonnes), **7 lignes de jobs** (13 colonnes), **0 ligne de worker**.
- Témoin négatif: `main.ts`, `healthcheck.ts`, `base.ts`, `_stub.ts`, `ssrf-guard.ts` **sont** trouvés — la recherche fonctionne sur ce répertoire.
- Impact        : `C18-018` conclut « aucun des 13 scrapers n'est testé, aucun n'est déployé » **en agrégat**, sans dire lequel fait quoi. Or `C18-011` (**S1**) mesure que **le pont Laravel → Node est rompu par le préfixe Redis** et que le défaut « se déclenchera au moment exact où l'on croira réactiver la collecte » : le jour où l'on rallume les workers, on rallumera **34 fichiers dont 22 que personne n'a regardés**. `H47-005` propose par ailleurs de trancher le sort de `workers/` pour éteindre 32 alertes — cette décision se prendrait **sans inventaire**.
- Reproduction  : voir ci-dessus.
- Correctif     : appliquer la grille §5.4 aux 34 fichiers et aux 5 webhooks. Coût : ~3 h.
- Statut        : **ouvert**

---

### [Z50-004] Trois des six grilles obligatoires du §5 n'existent pas — dont la matrice de raccordement, « le cœur de l'harmonie »
- Sévérité      : **S1**
- Domaine       : méthode
- Référence     : `main 8db8229`
- Emplacement   : `11_GRILLES/`
- Constat       : le §9 nomme sept fichiers de grille. Quatre existent (`ecrans.md`, `routes.md`, `tables.md`, `automatismes.md`). **`fonctionnalites.md`, `parcours.md` et `raccordement.md` n'existent pas** — ce ne sont pas des cases vides, ce sont des grilles absentes, ce que le §5 interdit explicitement.
- Preuve        : `ls 11_GRILLES/` → 31 fichiers, aucun de ces trois noms. Recherche des trois noms dans le dossier → 2 occurrences, toutes deux dans `01_INVENTAIRE.md` §5, qui les annonce comme « ouverts en P0, remplis par les agents de P1 ». **Ils n'ont jamais été ouverts.**
- Témoin négatif: les quatre autres noms de la même liste **existent** — la recherche de fichiers fonctionne.
- Impact        : trois manques distincts. **(a)** La grille §5.5 (23 fonctionnalités × 12 points) n'est nulle part : personne n'a instruit « son vocabulaire », « sa réversibilité », « est-elle atteignable depuis la navigation ? ». **(b)** La **matrice de raccordement** — que le mandat appelle « le cœur de l'harmonie », qui cherche la donnée dupliquée sans source unique, l'action qui devrait mettre à jour ailleurs, les deux noms pour une notion — **n'existe pas**. Son sujet est pourtant le mieux instruit de l'audit (`D23` glossaire, `E32-002`, `I49`, `B14`), mais rien ne le rassemble en tableau. **(c)** La grille §5.6 (13 parcours) est vide, et c'est celle dont le mandat dit qu'elle « dit si le CDC peut s'ouvrir » : `03_MATRICE` §C porte 13 lignes, **13 fois ⏳**.
- Reproduction  : `ls _AUDIT/2026-08-18_AUDIT-360/11_GRILLES/`.
- Correctif     : `fonctionnalites.md` et `raccordement.md` sont largement **compilables** à partir des rapports existants (agents 13, 14, 22, 23, 31, 32, 48, 49) — ~4 h. `parcours.md` exige l'agent 24 **et** une console utilisable : il est **bloqué par `A07-001`**, pas par un manque de travail. Le dire, plutôt que de laisser 13 ⏳.
- Statut        : **ouvert**

---

### [Z50-005] La grille ÉCRAN rend 8 points sur 25 : dix-sept points du §5.1 ne sont couverts pour aucun des 37 écrans
- Sévérité      : **S1**
- Domaine       : méthode / interface
- Référence     : `main 8db8229`
- Emplacement   : `11_GRILLES/ecrans.md` §2
- Constat       : 37 écrans sur 37 sont présents, **avec zéro case vide** — mais le tableau porte **8 colonnes**, non les 25 points du §5.1. Dix-sept points ne sont instruits pour **aucun** écran.
- Preuve        : `awk` sur les 8 sous-tableaux d'`ecrans.md` → **37 lignes, 9 « | » par ligne (8 colonnes), 0 case vide**. Colonnes rendues : route · composant · données · actions · permissions · états gérés · atteignable · verdict. Points absents : 2, 3, 4, 6, 7, 8, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24 (liste développée au §3.2).
- Témoin négatif: la même méthode sur `routes.md` rend **18 points sur 18** et sur `tables.md` **14 sur 14** — le crible sait reconnaître une grille complète.
- Impact        : **37 × 17 = 629 cases absentes**, et ce ne sont pas des points mineurs : le **375 px** (point 22), le **clavier seul et l'ARIA** (23), la **perte de saisie** (19), le **refus silencieux** (18), les **actions de masse** (17), le comportement **à 100 000 lignes** (8). Le §12-2 exige la grille « case par case, pour chacun de ses objets ». *À la décharge de l'agent 22 : il a rendu ce qu'il pouvait mesurer sans session, et il déclare précisément ce que sa mesure ne couvre pas (§4 de son rapport). Les 17 points manquants étaient le périmètre des agents 25, 26, 28, 30 — qui n'ont pas rendu.*
- Reproduction  : ouvrir `11_GRILLES/ecrans.md` §2 et compter les colonnes.
- Correctif     : impossible avant `A07-001` pour les points nominaux (14-19). Les points **20, 21, 22, 23, 24** sont mesurables **sans session**, sur le bundle servi : ~6 h pour les 37 écrans. Coût total après déblocage : ~2 j.
- Statut        : **ouvert**

---

### [Z50-006] `routes.md` tient sa promesse à la lettre — et deux de ses dix-huit points sont à zéro sur 117 lignes
- Sévérité      : **S2**
- Domaine       : méthode
- Référence     : `main 8db8229`
- Emplacement   : `11_GRILLES/routes.md` §4
- Constat       : la promesse « 117 lignes × 18 colonnes, **zéro case vide** » est **vraie, vérifiée**. Mais le point 4 (« un utilisateur d'un autre espace obtient-il 0 ligne — **test qui rougit** ») a été joué sur **une** route sur 117, et le point 17 (« test automatisé existant **et vu rouge** ») n'est satisfait par **aucune** ligne.
- Preuve        :
  ```
  lignes du tableau                 : 117
  « | » par ligne                   : 21 sur 117  ->  20 colonnes uniformes
  cases vides                       : 0
  valeurs distinctes, point 2       : 3    (la meme phrase 117 fois)
  valeurs distinctes, point 10      : 2    (113 « non verifie — hors budget agent 12 »)
  valeurs distinctes, point 17      : 2    (75 « rougeur non verifiee » + 42 « aucun test »)
  valeurs distinctes, point 4       : 6    (18 « non verifie » + « oui par construction, non joue »)
  ```
  Le tableau est **généré** : `04_PREUVES/agent-12/grille.py` + `grille_data.py` + `grille-generee.md`.
- Témoin négatif: les points 5, 6, 8, 11, 12, 14 portent respectivement **42, 32, 26, 40, 32, 34** valeurs distinctes sur 117 lignes — ils sont réellement instruits route par route. Le crible distingue donc une colonne mesurée d'une colonne constante.
- Impact        : la case « non vérifié — raison » est **honnête et conforme au §5**, et l'agent la déclare intégralement au §6 de son rapport. Le défaut n'est pas chez lui : **les deux points à zéro sont précisément ceux qui portent la doctrine** — règle 2 (« vue rougir ») et règle 3. Un lecteur pressé retiendra « 117 × 18 sans case vide » et croira l'étanchéité et les tests instruits. **Ils ne le sont pas.** Le budget manquant (`EXPLAIN`, suite Pest) appartenait aux agents **41** et **45**, qui n'ont rien rendu.
- Reproduction  : `awk` sur les lignes 281-399 de `routes.md`, comptage des « | » et des valeurs distinctes par colonne.
- Correctif     : ne pas retoucher la grille — la compléter. Point 10 : ~2 h une fois l'atelier calme. Point 17 : dépend d'une suite Pest rejouable en isolation (`H44-004`). Point 4 : dépend d'une pile armée comme la production (`B11-010`).
- Statut        : **ouvert**

---

### [Z50-007] Six agents ont archivé leurs mesures et n'ont rendu ni rapport, ni verdict, ni constat
- Sévérité      : **S1**
- Domaine       : méthode
- Référence     : `main 8db8229`
- Emplacement   : `04_PREUVES/agent-{21,29,34,36,39,41}/` vs `11_GRILLES/`
- Constat       : six agents ont joué des mesures et déposé leurs sorties brutes ; **aucun n'a produit de rapport, de ligne de grille ni de constat**. Leur travail existe en fichiers et n'est relié à rien.
- Preuve        :
  ```
  agent-21 (qualite des donnees)      9 fichiers   rapport : ABSENT
  agent-29 (i18n et temps)           13 fichiers   rapport : ABSENT
  agent-34 (non-regression console)   9 fichiers   rapport : ABSENT
  agent-36 (permissions)              9 fichiers   rapport : ABSENT
  agent-39 (sauvegardes, observ.)     2 fichiers   rapport : ABSENT
  agent-41 (requetes, EXPLAIN)        1 repertoire rapport : ABSENT
  ```
  Contenus vérifiés à l'ouverture : `agent-36/08-test-qui-rougit.txt` porte **une garde vue rougir**, sortie Pest complète ; `agent-29/bascules-{AVEC,SANS}-db-timezone.txt` porte les **deux bascules d'heure** du critère 16 ; `agent-21/prod_casse_sql_vs_php.txt` porte la divergence `lower()` SQL ↔ `mb_strtolower` PHP.
- Témoin négatif: les 25 autres répertoires de `04_PREUVES/` correspondent bien à un rapport publié — la corrélation preuve↔rapport est la règle, ces six sont l'exception.
- Impact        : ce n'est pas « du travail non fait », c'est **du travail fait et perdu** — la forme la plus coûteuse. Conséquences nommées : les **11 policies** (Z50-001), le **critère 16** (deux bascules), le **critère 25** (non-régression console), le **critère 1** et les **113 cases `EXPLAIN`** de `routes.md`, la qualité des données sur 4,3 M de lignes. Et cela alimente `A-013` : *l'information exacte existe, écrite, au bon endroit, et personne ne la relit.*
- Reproduction  : `ls 04_PREUVES/` puis `ls 11_GRILLES/` et comparer.
- Correctif     : rédiger six rapports **à partir des preuves déjà archivées**, sans rejouer une seule mesure. Coût estimé : ~2 h par agent, soit ~12 h. **C'est le meilleur rapport travail/valeur de tout le reste à faire.**
- Statut        : **ouvert**

---

### [Z50-008] L'agent 45 — celui à qui `A-011` délègue le crible des gardes — a rendu un squelette à balises non remplies
- Sévérité      : **S1**
- Domaine       : méthode / tests
- Référence     : `main 8db8229`
- Emplacement   : `11_GRILLES/agent-45_valeur-tests.md` (3 979 octets)
- Constat       : le fichier porte une introduction méthodologique sérieuse (atelier isolé monté, deux mesures de contention documentées) puis **quatre sections vides**, réduites à des commentaires de gabarit.
- Preuve        :
  ```
  $ tail -20 11_GRILLES/agent-45_valeur-tests.md
  ## 1. …            <!-- TABLEAU-SABOTAGES -->
  ## 2. Les quatre pathologies, recherchees nommement   <!-- PATHOLOGIES -->
  ## 3. Constats     <!-- CONSTATS -->
  ## 4. Ce que je n ai PAS pu verifier, et pourquoi     <!-- NON-VERIFIE -->

  $ grep -oE "\[H45-[0-9]{3}\]" 11_GRILLES/agent-45_valeur-tests.md  ->  0
  ```
- Témoin négatif: le même `grep` sur `agent-35` rend **14** identifiants, sur `agent-46` **12**, sur `agent-48` **8**. Le crible trouve bien des constats quand il y en a.
- Impact        : `A-011` — **le constat systémique de l'audit**, S1, dix cas — prescrit en toutes lettres : *« passer les gardes existantes au crible, une par une — **c'est le périmètre de l'agent 45**, qui reçoit ces six cas comme modèles »*. `02bis` §S-2 reprend cette délégation. **Le crible n'a jamais eu lieu.** Les dix cas connus ont donc été trouvés **par accident, par dix agents qui ne se parlaient pas** — ce qui rend le chiffre « dix » un **plancher sans plafond connu**. Et la doctrine règle 2 n'est vérifiée systématiquement nulle part.
- Reproduction  : ouvrir le fichier.
- ⚠️ **Mesure de contrôle à 12:14Z, huit minutes après mon instantané** : le fichier est passé de
  **3 979 à 7 081 octets** — il **est en cours d'écriture**. Il porte toujours **3 balises de gabarit
  non remplies et 0 identifiant `H45-*`**. Le constat tient donc à l'heure où je le pose, **et il est
  susceptible d'être levé dans l'heure**. Je le laisse ouvert plutôt que de le retirer : c'est à
  l'agent 45, une fois rendu, de le fermer par sa livraison — pas à moi de parier sur elle.
- Correctif     : relancer l'agent 45 avec son propre atelier isolé (sa section 0 explique déjà pourquoi et comment : facteur ~115 sur le montage Windows, contention à 12 processus concurrents). Ces contraintes sont maintenant connues, la mesure est refaisable. Coût : ~6 h.
- Statut        : **ouvert**

---

### [Z50-009] Trente-quatre constats de trois agents ne sont jamais entrés au registre unique, dont un S0
- Sévérité      : **S1**
- Domaine       : méthode
- Référence     : `main 8db8229`, arrêté à 12:06Z
- Emplacement   : `02_CONSTATS.md` vs `11_GRILLES/agent-{35,46,48}.md`
- Constat       : trois agents ont rendu un rapport complet, au format §9, avec identifiants stables — et **aucun de leurs constats n'apparaît dans `02_CONSTATS.md`**, dont le §9 fait pourtant « le registre unique dédoublonné ».
- Preuve        :
  ```
  prefixe  dans 11_GRILLES  dans 02_CONSTATS.md
  F35-*         14                 0        (agent 35, authentification)
  H46-*         12                 0        (agent 46, analyse statique)
  I48-*          8                 0        (agent 48, aptitude au CDC)
  ```
  Sévérités des orphelins : **F35 → 1 S0, 4 S1, 8 S2, 1 S3** · **H46 → 6 S2, 6 S3** · **I48 → 1 S0, 4 S1, 3 S2**.
  Le S0 de F35 est **`F35-002`** : *« trois colonnes de la 2FA n'existent dans aucune migration ; `EnforceFirstLoginSetup` verrouille alors le produit entier »*.
- Témoin négatif: le même comptage sur 24 autres préfixes rend des valeurs **égales ou proches** entre grilles et registre (`B12` 19/19, `B16` 14/14, `B17` 14/14, `F38` 14/14) — le registre a bien intégré les autres.
- Impact        : trois effets. **(a)** `F35-002` est le **même défaut** que `A07-001` et `D22-001`, mesuré une troisième fois de façon indépendante — un dédoublonnage qui n'a pas eu lieu, alors que c'est la fonction de P2. **(b)** Les 8 constats `I48-*` sont cités par `02bis` (I48-001, I48-003, I48-008) **sans être au registre** : le document de consolidation référence des identifiants introuvables dans le registre qu'il consolide. **(c)** Un lecteur qui prendrait `02_CONSTATS.md` pour « tout ce qui a été trouvé » manquerait **34 constats sur ~230**, soit **15 %**.
- Reproduction  : `grep -o "F35-[0-9]\{3\}" 11_GRILLES/*.md 02_CONSTATS.md` et comparer.
- Correctif     : intégrer les trois blocs au registre et dédoublonner `F35-002` avec `A07-001`/`D22-001`. Coût : ~1 h.
- Statut        : **ouvert**

---

### [Z50-010] La consolidation P2 annonce 12 défauts S0 ; le registre en porte 22 identifiants, dont six jamais consolidés
- Sévérité      : **S1**
- Domaine       : méthode
- Référence     : `main 8db8229`, arrêté à 12:06Z
- Emplacement   : `02bis_P2-CONSOLIDATION.md` §1 vs `02_CONSTATS.md`
- Constat       : `02bis` conclut « **→ 12 défauts S0 distincts** ». Le registre porte **22 identifiants marqués S0** à 12:06Z. Six sont arrivés **après** la consolidation et n'y figurent nulle part.
- Preuve        :
  ```
  S0 dans 02_CONSTATS.md (22 identifiants) :
    A07-001 A07-003 B10-004 B11-001 B11-002 B12-001 B12-003 B12-004 B14-002
    B15-001 B15-003 B15-006 B15-010 B16-001 B16-002 B16-003 B16-004 C19-007
    D22-001 E31-001 F37-001 F40-002
  + A-010, A-012, C18-016 (reclasse D-012), I48-001 marques S0 dans leur bloc

  Absents de 02bis : B15-001  B15-003  B15-006  B15-010  D22-001  F37-001
  ```
  Horodatage : `02bis` écrit à 13:38 (heure locale) ; `agent-15_rgpd.md` à 13:45, `ecrans.md` à 13:47, `agent-37_secrets-echanges.md` à 13:56. **La consolidation est antérieure aux rapports qu'elle devrait consolider.**
- Témoin négatif: `02bis` **intègre correctement** les 17 S0 disponibles à son heure et les dédoublonne en 12 — la méthode est bonne, c'est la fraîcheur qui manque. Le document déclare d'ailleurs lui-même « 23 agents rendus sur 46 ; les autres tournent ».
- Impact        : les six S0 manquants ne sont pas mineurs. **`B15-001`** — *une personne effacée par la console revient au vivier à la candidature suivante* — est un **S0 RGPD armé, pas encore déclenché**, qui « se déclenchera au moment exact où l'on ouvrira le vivier ». **`F37-001`** transforme `B12-004` d'une déduction sur `.env.example` en une **faille mesurée en production, secret vide, funnel ouvert**. **`D22-001`** est la moitié interface de `A-012`. **L'ordonnancement de P3 (`02bis` §4), qui pilote toute la correction, a été construit sans eux.** Le rang 5 (« G3 + B10-004 — l'effacement ») ignore les quatre S0 de l'agent 15 ; le rang 6 traite `B12-004` comme « une porte ouverte, peu coûteuse » alors qu'elle est **ouverte en production**.
- Reproduction  : `grep -oE "\*\*[A-Z0-9-]+\*\* \| \*\*S0" 02_CONSTATS.md | sort -u` puis comparer à `02bis` §1.
- Correctif     : rejouer P2 **après** la fin de P1, pas pendant. Recalculer l'ordonnancement avec les 6 S0 manquants. Coût : ~2 h.
- Statut        : **ouvert**

---

### [Z50-011] La matrice exigence → test → preuve est périmée sur son propre registre : sept critères mesurés y restent « en cours »
- Sévérité      : **S2**
- Domaine       : méthode
- Référence     : `main 8db8229`
- Emplacement   : `03_MATRICE-EXIGENCES.md` (dernière écriture 12:49 locale, soit ~1 h avant `02_CONSTATS.md`)
- Constat       : sept critères du §29 sont mesurés dans le registre et portent encore ⏳ dans la matrice ; sa section C (13 parcours) est intégralement vide ; sa section D (défauts D-01→D-13) est « ⏳ en cours » alors que l'agent 7 l'a rendue (8 levés · 2 partiels · 3 encore vrais) ; et son propre décompte est faux.
- Preuve        : critère **11** — la matrice dit ⏳ ; `02_CONSTATS` (agent 8) dit *« le critère 11 du §29 et le point 13 de la définition de fini sont **TENUS** — 20 726 338 lignes, écart nul, témoin négatif joué »*. Idem pour les critères **5, 9, 13, 14, 23, 24**, tous instruits par les agents 15, 22, 23, 32, 49. Décompte : la matrice écrit « **9 sont hors périmètre** » ; elle en porte **8** (7, 8, 12, 15, 19, 20, 21, 22).
- Témoin négatif: la matrice **est** à jour sur les critères 2, 4, 17, 18 (les quatre NON TENUS) et sur les fragilités F5, F9, F12, F14 — elle n'est pas figée en bloc, elle est en retard.
- Impact        : la matrice est le seul document qui relie une exigence à sa preuve. Le §12-11 en fait un point de la définition de fini. **Un lecteur qui s'y fie conclura que la sauvegarde n'a pas été restaurée** — alors que c'est l'un des deux seuls points de fini réellement tenus. C'est le patron `A-013` à l'envers : cette fois le résumé **sous-estime** le travail fait.
- Reproduction  : comparer `03_MATRICE-EXIGENCES.md` §B ligne 11 à `02_CONSTATS.md` bloc « Agent 8 ».
- Correctif     : reprendre la matrice **à la fin** de P1, et ajouter à chaque ligne l'horodatage de sa dernière mise à jour — la même règle que celle que `A-013` réclame pour les lignes de clôture. Coût : ~1 h 30.
- Statut        : **ouvert**

---

### [Z50-012] Aucune capture n'existe pour les 37 écrans, alors que le §5.1 et le §12-3 en exigent une par écran et par état
- Sévérité      : **S2**
- Domaine       : méthode / interface
- Référence     : `main 8db8229`
- Emplacement   : `04_PREUVES/`
- Constat       : le §5.1 point 1 exige « capture archivée » par écran, le §8 P1 « captures archivées, avant/après », le §12-3 « chaque écran ouvert à la main, **captures archivées** ». Le dossier contient **12 fichiers image au total**, tous dans `04_PREUVES/agent-23/` (navigation). L'agent 22, qui déclare avoir ouvert les 37 écrans, a archivé **4 fichiers texte et zéro image**.
- Preuve        :
  ```
  $ find 04_PREUVES -type f \( -name "*.png" -o -name "*.jpg" -o -name "*.webp" \) | wc -l
  12
  $ ... | sed 's#/[^/]*$##' | sort | uniq -c
       12 04_PREUVES/agent-23
  $ ls 04_PREUVES/agent-22/
  00-migrations-avant.txt  01-connexion-reelle.txt  02-bundle-servi.txt
  03-comment-les-ecrans-ont-ete-ouverts.txt
  ```
  Total du répertoire de preuves : **596 fichiers**.
- Témoin négatif: la recherche **trouve** les 12 images de l'agent 23 — elle sait rendre un résultat non nul sur ce dossier.
- Impact        : rien ne permet de rejouer ni de contester ce que l'agent 22 a vu. `D22-003` (« trois écrans de détail rendent une page **entièrement blanche** ») et `D22-002` (« les écrans affirment 0 et aucun sur 12 écrans ») sont des constats **purement visuels** sans aucune trace visuelle. Et le §6.5 exige « captures **avant / après** de chaque section » pour la refonte de navigation : les 12 de l'agent 23 sont des « avant » ; il n'y a pas d'« après », puisqu'il n'y a pas de refonte.
- Reproduction  : la commande `find` ci-dessus.
- Correctif     : archiver une capture par écran et par état atteignable — faisable dès aujourd'hui pour les états vide et erreur, **et seulement après `A07-001`** pour l'état nominal. Coût : ~3 h pour les états dégradés.
- Statut        : **ouvert**

---

### [Z50-013] Quatre décomptes différents du même constat A-011 coexistent dans quatre documents
- Sévérité      : **S2**
- Domaine       : méthode
- Référence     : `main 8db8229`
- Constat       : le constat systémique `A-011` (« les gardes mesurent le mauvais objet ») porte un nombre de cas différent dans chaque document qui le cite, sans qu'aucun ne dise lequel fait foi.
- Preuve        :
  ```
  _DOSSIER-AGENT.md  §5   : « Sept cas mesures independamment »
  00_JOURNAL.md      13:20: « A-011 garde ses HUIT autres cas »
  02bis              §S-2 : « S-2 · Des gardes qui mesurent le mauvais objet (A-011) — NEUF cas »
  02_CONSTATS.md     titre: « dix cas independants »
                     corps: « Six ont ete trouves » + un cas ~~5~~ retire + 2 complements + 2 complements
  ```
- Témoin négatif: les autres décomptes du dossier sont stables d'un document à l'autre (**780 tests / 6 503 assertions**, **58 migrations**, **1 319 567 contacts**, **117 routes**) — l'incohérence est propre à `A-011`.
- Impact        : `A-011` est présenté comme « le constat qui explique les autres », et c'est celui dont le chiffre est le moins fiable. Le corps du constat contredit son propre titre (« dix » en titre, « six » en première phrase). Un lecteur ne peut pas dire combien de cas sont établis — donc ne peut pas dire si le crible de l'agent 45 (Z50-008) en trouverait dix ou cent. **Le constat sur les gardes qui mesurent mal souffre lui-même d'un défaut de mesure.**
- Reproduction  : `grep -n "A-011" _AUDIT/2026-08-18_AUDIT-360/*.md _AUDIT/2026-08-18_AUDIT-360/../../CLAUDE.md 2>/dev/null`.
- Correctif     : figer le décompte dans `02_CONSTATS.md` (10 cas, numérotés 1 à 10, le n° 5 retiré et remplacé), et faire de tous les autres documents des renvois — pas des copies. Coût : 30 min.
- Statut        : **ouvert**

---

### [Z50-014] Le §11 du mandat n'est pas satisfait : les 37 écrans ont été ouverts hors connexion, hors TLS, API injoignable, et aucun des 21 parcours n'a été joué
- Sévérité      : **S1** *(pour l'audit ; le défaut produit sous-jacent est `A07-001`, S0, déjà ouvert)*
- Domaine       : méthode / interface
- Référence     : `main 8db8229`
- Emplacement   : §11 du mandat vs `11_GRILLES/ecrans.md` §0.2 et §0.3
- Constat       : le §11 exige les 37 écrans « un par un, sans exception », **plus** 21 parcours complets, avec la grille §5.1 et « une capture par état ». Les 37 écrans ont bien été ouverts — mais **non connectés, via un proxy HTTP temporaire (TLS retiré), avec tous les appels d'API en échec**. **Aucun des 21 parcours n'a été joué.**
- Preuve        : l'agent 22 l'écrit lui-même, et c'est à son crédit :
  ```
  docker run -d --name a22-proxy ... caddy reverse-proxy --from :80 --to app:5173
  navigation reelle sur http://app.localhost:58122/...  « seul le TLS est retire »
  fetch('https://app.localhost/api/v1/companies')  ->  "Failed to fetch"
  « Les 37 ecrans ont donc ete ouverts NON CONNECTE et API INJOIGNABLE. »
  ```
  Et la chaîne qui l'explique, mesurée de bout en bout :
  ```
  POST /api/v1/auth/login       -> 200   {"first_login_completed_at": null}
  GET  /api/v1/dashboard/stats  -> 403   first_login_required -> /auth/2fa/setup
  POST /api/v1/auth/2fa/setup   -> 500   column "two_factor_secret" does not exist
  ```
- Témoin négatif: un mauvais mot de passe rend **422** — l'authentification fonctionne réellement, le 200 n'est pas un artefact. Et la conformité D-011 est prouvée témoin par témoin (le bundle balayé est bien le bundle officiel).
- Impact        : ce que l'audit a mesuré sur l'interface, ce sont **les états « vide » et « erreur »**, et rien d'autre. **L'état nominal des 37 écrans n'a été vu par personne**, en local comme en production. Conséquences en cascade : les points 14 à 19 de la grille §5.1 (actions, saisie) sont **inmesurables** ; les 13 parcours du §23.4 aussi ; les critères 3, 6, 10, 20, 23, 24 du §29 aussi. **Un tiers du mandat est bloqué par un seul défaut produit, `A07-001`, dont le correctif est une migration.** Ce n'est pas une fatalité, c'est une dépendance non ordonnancée : `02bis` place G4 au rang 2, ce qui est juste — mais P3 n'a pas commencé.
- Reproduction  : `11_GRILLES/ecrans.md` §0.2, §0.3 et §4.
- Correctif     : corriger `A07-001` (les trois colonnes 2FA) **avant tout le reste du bloc D**. Puis rejouer le §11 en entier, avec captures. Coût du déblocage : ~2 h. Coût du §11 ensuite : ~3 j.
- Statut        : **ouvert**

---

### [Z50-015] Treize constats reposent sur une preuve plus faible que ce qu'ils affirment — dont quatre S0
- Sévérité      : **S1**
- Domaine       : méthode
- Référence     : `main 8db8229`
- Constat       : passés au crible des quatre critères de fragilité (aucune commande jouée · pas de témoin négatif sur un « rien trouvé » · lecture de code présentée comme comportement · conclusion au-delà de la mesure), **treize constats du registre cèdent**. Quatre sont des **S0**. Deux tiennent à la couche de consolidation, pas aux agents.
- Preuve        : le tableau complet, avec la faiblesse exacte de chacun et le geste qui le sauverait, est au **§6 de ce rapport**. Résumé :

  | Constat | Sév. | Faiblesse |
  |---|---|---|
  | A-002 · B10-013 · B12-007 | S2·S2·S1 | comportement HTTP affirmé sur lecture de code ; reproduction prescrite **impossible** (aucune session n'existe) |
  | A-005 | S3 | « joignable par URL » jamais ouvert ; bundle servi périmé de 32 h au moment du constat |
  | **B16-002 · B16-003** | **S0** | mesurés en local, **portés en production par raisonnement** ; or `A08-004` mesure un **schéma différent** (partitionné en CI, ordinaire en prod) et la troncature en dépend |
  | **B12-001 · B12-003** | **S0** | mesurés en local où `CRM_DB_APP_ROLE_ENABLED=false` (prod : `true`) ; **l'agent pose la réserve, `02bis` ne la reporte pas** |
  | **B12-004** | **S0** | déduit de `.env.example` ; **levé et aggravé depuis par `F37-001`**, que la couche consolidée ignore |
  | A-010 impact 1 | S0 | la sérialisation est solide ; **le « 17,5 s » vient d'un autre système, à un autre état du code** |
  | A-003 impact | S2 | mesure exemplaire, **attribution causale non rejouée** |
  | H47 « zéro atteignable » | — | deux axes sur trois sont des recherches à 0 résultat **sans témoin négatif** |
  | D22-006 | S2 | établi par lecture de code, l'agent le dit ; **le registre laisse tomber la réserve** |
  | `02bis` §5 « la CI est SAINE » | — | **contredit `F38-002`, `A08-005` et `H44-003`** du même dossier |

- Témoin négatif: le crible **ne condamne pas tout**. `D27-002` a résisté à mon soupçon — sa preuve porte l'identifiant d'onglet, les couleurs résolues en sRGB **et un témoin négatif joué dans la même session** ; c'est le résumé de `02_CONSTATS.md` qui est faux, pas le constat. `C19-004`, `B15-001`, `A-010` (le fait), `A-012`, `B13-001`, `C18-011` et la restauration de sauvegarde de l'agent 8 passent le crible sans réserve. **Sur ~230 constats, treize cèdent : le taux est bas, et il fallait le dire aussi.**
- Impact        : **quatre S0 sur vingt-deux reposent sur une base plus étroite que leur sévérité ne le laisse croire** — non pas faux, mais non établis là où ils sont réputés tenir. Le §5 bis du dossier commun avertit précisément contre ce transfert local → production, et trois des quatre le franchissent. La conséquence pratique est l'ordonnancement de P3 : on corrigerait en priorité des défauts dont la portée réelle en production n'est pas mesurée, pendant que `F37-001` — le seul S0 **mesuré en production** — est arrivé après la consolidation.
- Reproduction  : lire chaque champ « Preuve » et « Reproduction » de `02_CONSTATS.md` et vérifier qu'une commande y est jouée sur l'objet dont le constat parle.
- Correctif     : **ne rien retirer.** Ajouter à chacun des treize une ligne « **portée de la mesure** » disant sur quel système, dans quelle configuration, et à quelle date la preuve a été obtenue — et rejouer les quatre S0 en configuration de production **avant** de les traiter en P3. Coût : ~1 h de rédaction, ~4 h de mesures.
- Statut        : **ouvert**

---

### [Z50-016] Le §6 a produit son plan de navigation et n'en a appliqué aucune ligne
- Sévérité      : **S2**
- Domaine       : méthode / navigation
- Référence     : `main 8db8229`
- Emplacement   : `10_NAVIGATION-CIBLE.md` (73 420 octets)
- Constat       : le §12-4 exige que la refonte soit **appliquée**, les redirections **écrites**, la visite guidée **refaite**, et le test des dix intentions **rejoué après — et qu'il passe**. Le livrable existe et il est complet ; **rien n'en a été appliqué**.
- Preuve        : le document porte le plan (`5 conservées · 8 renommées · 9 déplacées · 4 fusionnées · 2 écrans + 1 groupe supprimés · **8 redirections à écrire** · 1 orpheline réintégrée · 14 créées`) et tranche les 37 écrans un par un. `git log` : **aucun commit de refonte** ; `gh pr list` : **0 PR**. Le test des dix intentions n'existe qu'en « avant » (substitution `D-007` déclarée) : **4 trouvables · 4 avec effort · 5 introuvables · 7 impossibles**, et **0 sur 2** sur les deux exemples littéraux du critère 24.
- Témoin négatif: `10_NAVIGATION-CIBLE.md` **existe** et fait 73 Ko avec 12 captures archivées — le livrable du §6.5 n'est pas manquant, il est **non exécuté**. La distinction compte.
- Impact        : `02bis` place la navigation au **rang 10** de P3, ce qui est cohérent. Mais le §12-4 en fait un point de la définition de fini, et le mandat insiste : « **refaire la visite guidée une seule fois**, sur la barre cible ». Tant que la refonte n'est pas faite, `D23-010` (la visite n'affiche que 5 de ses 7 étapes **et se marque « faite » quand même**) reste ouvert, et chaque nouvel écran ajouté par l'étape 1a s'installe sur l'ancienne barre.
- Reproduction  : `git log --oneline -20` et `ls 10_NAVIGATION-CIBLE.md`.
- Correctif     : exécuter le lot 10 de `02bis` §4. Le plan est prêt ; il ne manque que la réalisation, par un agent **distinct** de l'agent 23 (règle 7).
- Statut        : **ouvert**

---

### [Z50-017] Les phases P3, P4, P5, P6 et P7 n'ont pas eu lieu : aucun constat n'est corrigé, aucun n'est vérifié, aucun n'est réfuté
- Sévérité      : **S1** *(état d'avancement, pas défaut de travail)*
- Domaine       : méthode
- Référence     : `main 8db8229`
- Constat       : sur les sept phases du §8, **P0 est faite**, **P1 est aux deux tiers**, **P2 est faite et périmée**, et **les quatre dernières n'ont pas commencé**.
- Preuve        :
  ```
  $ git log --oneline -1
  8db8229 audit(360): P0 a P2 — 24 agents rendus, 12 defauts S0 distincts, et un defaut de cloture
     -> le seul commit de l audit est LE DOSSIER lui-meme ; aucun code corrige

  $ ls 07_RAPPORT-FINAL.md 08_PASSE-2-ADVERSARIALE.md 09_PASSE-3-REGARD-NEUF.md
     -> les trois sont ABSENTS

  champs « Refute par »  remplis dans 02_CONSTATS.md : 0
  champs « Passe 3 »     remplis                     : 0
  ```
- Témoin négatif: les champs « **Vérifié par** » **sont** remplis sur `A-001`, `A-003`, `A-007`, `A-012`, `A-013` — le registre sait porter un verdict de vérification quand il y en a un. Leur absence ailleurs n'est donc pas un oubli de format.
- Impact        : trois choses qu'il faut dire ensemble. **(a)** Aucun des 22 S0 n'est corrigé : le produit est exactement dans l'état où l'audit l'a trouvé. **(b)** Le §12-14 exige que P5 **et** P6 soient menées « en entier », la dernière ne trouvant plus rien ≥ S2 : on en est à **zéro passe sur deux**. **(c)** Et pourtant, **une vérification croisée réelle a bien eu lieu, dès P1** — le chef de chantier a été corrigé **cinq fois** (`A-001` en-tête `Accept`, `A-004` quota ACME, `A-007` facteur 7, l'hypothèse RGPD, `A-011` cas 5), et l'agent 37 a fait passer `B12-004` de la déduction à la mesure. **Ce n'est pas P4, mais ce n'est pas rien** : cela prouve que la rotation fonctionne quand on la joue. La bonne conclusion n'est pas « l'audit est en retard », c'est « **l'audit s'est arrêté au moment précis où sa méthode commençait à porter** ».
- Reproduction  : commandes ci-dessus.
- Correctif     : dans l'ordre du §8 — finir P1 (Z50-007, Z50-008 : ~18 h, dont 12 h de simple rédaction sur preuves existantes), rejouer P2 (Z50-010), puis ouvrir P3 par le rang 0 de `02bis` §4 (écrire la règle de clôture **avant** tout correctif, 1 h : c'est le seul lot qui protège tous les autres).
- Statut        : **ouvert**

---

### [Z50-018] Deux agents rendent des mesures incompatibles sur l'ouverture de `https://app.localhost`, et le désaccord non tranché a produit une demande au dirigeant
- Sévérité      : **S2**
- Domaine       : méthode
- Référence     : `main 8db8229` / `e8924b8` (les deux mesures sont à la même référence de code)
- Emplacement   : `11_GRILLES/ecrans.md` §0.2 vs `04_PREUVES/agent-27/05_navigateur-important-vs-dark.txt`
- Constat       : l'agent 22 mesure que **Chrome refuse `https://app.localhost`** et en tire un geste demandé au dirigeant ; l'agent 27, à la même référence, **a mesuré dans un vrai navigateur sur `https://app.localhost`** et en rapporte des couleurs résolues. Les deux mesures ne peuvent pas être vraies ensemble, et aucun des deux rapports ne cite l'autre.
- Preuve        :
  ```
  agent 22 :  curl https://app.localhost/  -> exit 60 (SSL certificate problem)
              openssl s_client             -> verify code 20 (unable to get local issuer)
              Cert:\CurrentUser\Root ... *Caddy*  -> AUCUN
              « L extension navigateur ne peut pas s attacher a une page d erreur de Chrome »

  agent 27 :  « MESURE EN NAVIGATEUR REEL — https://app.localhost (conteneur `app`),
                tabId 1780622446 » + couleurs sRGB via canvas 1x1 + temoin negatif joue
  ```
- Témoin négatif: la preuve de l'agent 27 **porte son propre témoin négatif** (sur une utilitaire hors des 4 règles `!important`, la variante `dark:` gagne) — le dispositif n'est pas biaisé, et il a bien rendu deux issues différentes. La mesure existe donc réellement.
- Impact        : `06_RESTE-WILL.md` §C0 demande au dirigeant d'**installer une autorité de certification racine dans le magasin de confiance de sa machine**, avec la justification *« sans elle, aucun agent ne peut exécuter le §11 »*. Si la mesure de l'agent 27 est la bonne, **cette modification permanente de la sécurité du poste est inutile**. La règle 9 impose de trancher un désaccord **par une mesure** : il ne l'a pas été. *(À porter au crédit de l'agent 22 : refuser d'installer une autorité racine sur la machine de quelqu'un d'autre était le bon réflexe, quelle que soit l'issue.)*
- Reproduction  : ouvrir les deux preuves et comparer ; puis rejouer une navigation sur `https://app.localhost` en notant le navigateur, le profil et le canal d'automatisation employés.
- Correctif     : une mesure d'arbitrage de 10 minutes, qui dira **quel canal** d'automatisation accepte l'origine — et, selon l'issue, retirer ou confirmer le geste C0 de `06_RESTE-WILL.md`.
- Statut        : **ouvert**

---

## 11. CE QUE JE N'AI PAS PU VÉRIFIER, ET POURQUOI

Cette liste est un livrable, pas un aveu.

1. **Je n'ai rejoué aucune mesure de produit.** Mon périmètre est le dossier. Quand j'écris qu'un
   constat est fragile, je dis **que sa preuve ne l'établit pas**, jamais **qu'il est faux** — la
   distinction est tout mon propos, et je m'y tiens : les treize constats du `Z50-015` sont
   **peut-être tous vrais**.
2. **« Nommé » n'est pas « audité ».** Mon crible du §4 est un **majorant généreux** : un service cité
   une fois dans une phrase compte comme traité. Les vrais nombres de « réellement audités » sont
   **inférieurs** à ceux du §2. Là où le crible rend zéro (9 policies, 24 services, 22 workers), il
   n'y a en revanche aucune ambiguïté.
3. **Le dossier bougeait pendant que je le mesurais.** Trois rapports sont apparus entre mon premier
   et mon dernier passage, et `02_CONSTATS.md` a grandi de 156 lignes. Tout est arrêté à **12:06Z**.
   Un décompte de `Z50-009` ou `Z50-010` peut être caduc à l'heure où ceci est lu — **c'est
   précisément pourquoi chaque tableau porte son heure**.
4. **Je n'ai pas ouvert les 596 fichiers de `04_PREUVES/`.** J'ai ouvert ceux qui décidaient d'un
   verdict : `agent-27/05`, `agent-36/08`, `agent-12/grille.py`, `agent-22/*`. Une preuve archivée
   mais fausse m'aurait échappé — **c'est exactement le travail de P5, qui n'a pas eu lieu**.
5. **Je n'ai pas relu le CDC v2.7 lui-même.** Les 25 critères du §29 et les 13 parcours du §23.4 me
   viennent de `03_MATRICE-EXIGENCES.md` et du mandat. Si la matrice a mal recopié un critère, je
   reproduis son erreur. *(Elle a déjà mal recopié son propre décompte : « 9 hors périmètre » pour 8.)*
6. **Je n'ai pas vérifié `10_NAVIGATION-CIBLE.md` case par case.** 73 Ko, et il n'était pas dans mon
   mandat de le réauditer ; j'ai vérifié qu'il existe, qu'il porte les livrables du §6.5, et qu'aucune
   de ses lignes n'est appliquée.
7. **Je ne peux pas dire si les 15 agents manquants ont été lancés et n'ont pas rendu, ou n'ont jamais
   été lancés.** `00_JOURNAL.md` s'arrête à 13:26 (heure locale) et ne consigne pas les vagues (c),
   (d) et (e). Les six répertoires de preuves orphelins prouvent qu'**au moins six ont été lancés**.
   *Un journal append-only qui ne consigne pas les lancements ne permet pas de distinguer un agent
   perdu d'un agent jamais parti — et c'est en soi un défaut du journal.*

---

## 12. LE VERDICT DE COMPLÉTUDE, EN UNE PAGE

**Ce que cet audit a réellement produit est de bonne qualité, et il faut le dire en premier**, parce
qu'un rapport de complétude qui ne compte que les trous ment par omission : 31 rapports, **596
fichiers de preuve**, une sauvegarde de 725 Mo **réellement restaurée** avec témoin négatif, une
faille de production **mesurée sans être exploitée**, cinq auto-corrections du chef de chantier, et
plusieurs agents qui ont **retiré leurs propres mesures** en découvrant qu'ils mesuraient la
contention de leurs voisins. Ce dossier sait dire « je n'ai pas pu ».

**Et il n'est pas fini, à un point qu'aucun de ses documents ne dit clairement.**

- Sur les **7 phases** du §8 : **1,5 terminée**. P3, P4, P5, P6, P7 n'ont pas commencé.
- Sur les **16 points** de la définition de fini : **3 tenus**.
- Sur les **6 grilles** du §5 : **3 complètes**, 1 à 8 points sur 25, **2 inexistantes**.
- Sur les **13 listes** du §4 : **8 traitées objet par objet**, 2 en agrégat, **3 pas du tout**.
- Sur les **25 critères** du §29 : **5 mesurés**, 8 hors périmètre motivé, **12 en blanc** — dont
  **7 déjà mesurés ailleurs et jamais reportés**.
- Sur les **46 spécialistes** : **31 rendus**, dont un squelette vide ; **6 ont mesuré sans conclure**.
- Sur les **22 identifiants S0** : **0 corrigé, 0 vérifié en P4, 0 réfuté en P5**, et **4 reposent sur
  une base plus étroite que leur sévérité**.

**Le fil qui relie presque tout ce qui précède est celui que l'audit a lui-même nommé, `A-013` :
*ce dépôt n'a pas un problème de mesure, il a un problème de clôture*.** Le dossier vient de le
rejouer sur lui-même, quatre fois — une consolidation antérieure aux rapports qu'elle consolide, une
matrice qui ignore le seul critère tenu, un décompte de S0 arrêté à 12 quand le registre en porte 22,
et une ligne « la CI est saine » contredite par trois constats du même dossier. **Ce n'est pas une
ironie, c'est la preuve que le patron est réel et qu'il ne se corrige pas en le nommant.**

**Le meilleur reste à faire est aussi le moins cher** : ~12 h de rédaction sur des mesures **déjà
archivées** (les six agents de `Z50-007`) rendraient les 11 policies, les deux bascules d'heure, la
non-régression console et les `EXPLAIN` manquants. Et **le rang 0 de `02bis` §4** — écrire la règle
de clôture avant tout correctif — coûte **une heure** et protège tous les lots suivants.

---

*Rapport de l'agent 50, critique de complétude. Instantané `main = 8db8229`, 2026-08-19T12:06Z.
Aucun fichier du dossier n'a été modifié hors celui-ci. Le worktree `crmpro-wt-etape1a` n'a été ni
lu ni touché. Aucune mesure de produit n'a été jouée.*
