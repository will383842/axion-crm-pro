# 10 — NAVIGATION CIBLE · le plan qui empêche le bordel

> **Agent 23 — Architecte de la navigation.** Livrable du §6.5 du mandat.
> **Référence mesurée : `main = e8924b8`** (relue le 2026-08-19 12:2x ; `c0c453d` du prompt
> était déjà dépassé de 3 commits — cf. `_DOSSIER-AGENT.md` §1).
> Preuves brutes : `04_PREUVES/agent-23/`.

---

## 0. Avertissement de méthode — deux barres latérales coexistent

Ce chapitre distingue systématiquement **deux objets**, et beaucoup de désaccords entre agents
viennent de là :

| | Ce que c'est | Combien de sections | Où on le voit |
|---|---|---|---|
| **La barre du CODE** | `frontend/src/components/layout/Sidebar.tsx` sur `main = e8924b8` | **6** sections, **22** entrées | `pnpm dev` (mesuré ici sur `127.0.0.1:5199`) |
| **La barre SERVIE en local** | le bundle figé dans le conteneur `axion-crm-app` | **10** sections, **28** entrées | `https://app.localhost` |

Le §4.8 et le §6.2 du prompt d'audit décrivent **la seconde**. Le dossier commun (A-006) dit
qu'elle « n'existe plus » : c'est vrai **du code**, c'est **faux de l'atelier**. Voir `D23-001`.

Toutes les mesures de ce chapitre portent sur **la barre du code**, sauf mention contraire.

---

## 1. La barre actuelle, mesurée dans un navigateur

`04_PREUVES/agent-23/cible-v2.json` — sonde Playwright, `console_v2 = true`, `vivier = true`
(l'état le plus riche ; la variante drapeau fermé est traitée ligne à ligne plus bas).

```
AUJOURD'HUI   (1)   Tableau de bord                → /
CONTACTS      (6)   Contacts                       → /console/contacts
                    Vivier candidats               → /console/vivier
                    À arbitrer                     → /console/arbitrage
                    Entreprises                    → /companies
                    Journalistes                   → /journalists
                    Médias (presse)                → /media
COLLECTE      (4)   Couverture France              → /coverage
                    Collectes                      → /campaigns
                    Journaux de collecte           → /scraper-runs
                    Roumanie                       → /international/roumanie
PILOTAGE      (2)   Audiences (segments)           → /audiences
                    Observabilité                  → /admin/observability
CONFORMITÉ    (3)   Requêtes RGPD                  → /rgpd/requests
                    Registre AI Act                → /rgpd/ai-act
                    Journaux d'audit               → /audit-logs
RÉGLAGES      (6)   Utilisateurs                   → /users
                    Paramètres                     → /settings
                    Tags                           → /tags
                    LLM Router                     → /llm/router
                    Proxies                        → /llm/proxy-providers
                    Rotations                      → /llm/rotations
──────────────
PIED DE BARRE       « Réduire »  ← et RIEN d'autre
```

**22 entrées. Aucun compteur. Aucun lien vers la console axionia. Aucune fiche récente.**
La recherche `⌘K` vit dans l'en-tête, pas dans la barre.

Avec `console_v2 = false` (la valeur par défaut du code, `config/crm.php:168`), la section
CONTACTS tombe à **4 entrées** : `Contacts → /contacts` (l'ancienne liste), Entreprises,
Journalistes, Médias. `/console/*` disparaît entièrement de la barre.

---

## 2. L'écart réel à la cible §23.3

La cible : **cinq groupes** (AUJOURD'HUI · CONTACTS · ÉCHANGES · PILOTAGE · RÉGLAGES),
plus **« Collecte »** explicitement autorisé par le §A.1 n°15 tant que la collecte est un
chantier vivant, soit **six groupes** — le compte actuel est bon, **ce sont les noms qui
divergent** : la barre porte « Conformité » (qui doit devenir un sous-groupe de RÉGLAGES) et
**n'a aucun groupe ÉCHANGES**.

| Cible §23.3 | Présent aujourd'hui ? | Écart |
|---|---|---|
| **AUJOURD'HUI** — Tableau de bord du jour | ~ | l'écran existe mais montre des KPI de collecte, pas la journée |
| AUJOURD'HUI — Boîte de réception (n) | ❌ | groupe entier absent |
| AUJOURD'HUI — Mes rendez-vous (n) | ❌ | absent |
| AUJOURD'HUI — Mes tâches (n) | ❌ | absent |
| **CONTACTS** — Tous les contacts | ~ | `/contacts` existe mais **hors menu** quand `console_v2=true` (orpheline) |
| CONTACTS — vues épinglées par type (6, avec compteurs) | ❌ | aucune vue épinglée, aucun compteur |
| CONTACTS — Organisations | ❌ | le mot n'existe nulle part ; deux écrans « d'entreprises » à la place |
| CONTACTS — Vivier candidats | ✅ | libellé exact, conditionné à l'univers — conforme |
| CONTACTS — Prospection | ❌ | absente |
| CONTACTS — À arbitrer **(n)** | ~ | l'entrée existe, **le compteur manque** |
| **ÉCHANGES** (4 entrées) | ❌ | **groupe entier absent** — 0/4 |
| **PILOTAGE** — Tableaux de bord | ❌ | absent |
| PILOTAGE — Canal avec la console | ❌ | absent (l'écran existe côté axionia : « Synchro CRM ») |
| PILOTAGE — Coûts | ❌ | absent |
| **RÉGLAGES** — 8 sous-groupes | ❌ | 6 entrées à plat, aucun sous-groupe |
| **↗ Console axionia** | ❌ | **0 occurrence dans tout `frontend/src`** |
| **Rechercher (⌘K)** | ~ | présent, mais dans l'en-tête, et il n'ouvre pas les fiches (`D23-005`) |
| **Fiches récentes** | ❌ | absentes |

**Bilan : 1 conforme, 4 partiels, 13 absents.** Rien de ce qui manque n'est un détail :
il manque **la journée** (boîte de réception, rendez-vous, tâches), **la conversation**
(ÉCHANGES), et **les deux liens permanents entre les consoles**.

---

## 3. Point 1 du §6.3 — chaque intention rattachée à un emplacement

L'agent 22 n'a pas déposé `11_GRILLES/intentions.md` (répertoire relu à 12:20 :
4 grilles présentes, aucune d'intentions). **La liste ci-dessous est la mienne**, construite
des dix lignes de la table §22.6 du cahier des charges, des treize parcours du §23.4 et des
exemples du critère 24 du §29 — c'est-à-dire des intentions que le produit s'est **engagé** à
servir, pas des intentions inventées.

Barème : **trouvable** (≤ 2 clics, libellé qui le dit) · **trouvable avec effort** (le chemin
existe mais il faut le deviner) · **introuvable** (l'écran existe, rien ne l'annonce) ·
**impossible** (l'écran n'existe pas).

| # | Intention (formulée comme un utilisateur la dit) | Emplacement actuel | Verdict |
|---|---|---|---|
| 1 | « Qu'est-ce que j'ai aujourd'hui ? » | `/` — mais l'écran montre « Total entreprises / Enrichies 24h / Nouvelles 7j / Qualité moyenne » | **introuvable** — l'écran d'accueil ne parle pas de la journée |
| 2 | « Qui m'a écrit ? » (formulaires, messages, demandes de rappel) | nulle part dans le CRM ; c'est la console axionia, groupe « Boîte de réception » | **impossible** |
| 3 | « Mes rendez-vous du jour » | nulle part | **impossible** |
| 4 | « Mes tâches, mes relances » | nulle part | **impossible** |
| 5 | « Qui est cette personne, ce qu'on s'est dit » | `/console/personnes/$personKey` | **introuvable** — voir `D23-005` : ⌘K trouve la personne et ouvre **la liste** |
| 6 | « La liste de tous mes contacts » | `/contacts` | **introuvable** quand `console_v2=true` : la route existe, **aucune entrée de menu n'y mène** |
| 7 | « Les contacts presse » | `/journalists` (personnes) **et** `/media` (organisations) | **trouvable avec effort** — deux écrans, aucun ne dit lequel |
| 8 | « Les entreprises clientes » | `/console/contacts` filtre type=client **ou** `/companies` | **trouvable avec effort** — deux écrans redondants |
| 9 | « Les candidats » | `/console/vivier` | **trouvable** |
| 10 | « Les doublons à trancher » | `/console/arbitrage` | **trouvable** (mais sans le compteur qui devrait appeler l'action) |
| 11 | « Les visios de la semaine » (exemple littéral du critère 24) | nulle part | **impossible** |
| 12 | « Les comptes rendus à valider » | nulle part | **impossible** |
| 13 | « Régler le rappel avant rendez-vous » (exemple littéral du critère 24) | nulle part | **impossible** |
| 14 | « Où en est la synchro avec le site ? » | `/admin/observability` (partiel) ; le vrai tableau est **dans axionia** (« Synchro CRM ») | **introuvable** depuis le CRM |
| 15 | « Combien me coûte l'IA ce mois-ci ? » | `/llm/router` (suivi de coût dans le sous-titre) | **introuvable** — rangé sous « Réglages », libellé technique |
| 16 | « Modifier le site / le blog » | console axionia | **impossible depuis le CRM** — aucun lien (§22.6 en exige un permanent) |
| 17 | « Faire un devis pour ce client » | console axionia | **impossible depuis le CRM** |
| 18 | « Lancer une collecte sur un département » | `/coverage` puis `/campaigns` | **trouvable** |
| 19 | « Voir si une collecte a planté » | `/scraper-runs` | **trouvable** |
| 20 | « Exporter les données d'une personne (RGPD) » | `/rgpd/requests` | **trouvable avec effort** — rangé dans un groupe « Conformité » qu'un utilisateur métier n'ouvre jamais |

**Score : 4 trouvables · 4 trouvables avec effort · 5 introuvables · 7 impossibles sur 20.**
Le critère 24 du §29 exige **10/10 en moins de cinq secondes**. Sur ses deux exemples
littéraux (« voir les visios de la semaine », « régler le rappel avant rendez-vous »),
la barre actuelle marque **0/2**.

---

## 4. Point 2 du §6.3 — tri par carte inversé

Pour chaque entrée actuelle : **ce qu'un nouvel utilisateur croirait y trouver**, puis ce qu'il
trouve. Tout écart est un constat.

| Entrée de menu | Ce qu'on croit y trouver | Ce qu'on y trouve réellement | Écart |
|---|---|---|---|
| **Tableau de bord** | ma journée | 4 compteurs de collecte + un état vide « Lance ton premier scrape » | 🔴 `D23-002` |
| **Contacts** (`/console/contacts`) | une liste de **personnes** | une liste d'**entreprises**, chacune avec ≤ 3 personnes en sous-ligne (`ContactsHubController` : « Unité de ligne : l'ENTREPRISE ») | 🔴 `D23-003` |
| **Contacts** (`/contacts`, drapeau fermé) | des personnes, cliquables | des personnes **non cliquables** : aucun lien vers une fiche dans tout `ContactsListPage.tsx` | 🔴 `D23-003` |
| **Entreprises** | les sociétés | les sociétés — mais c'est **le même objet** que « Contacts » ci-dessus, sous un autre écran | 🔴 `D23-003` |
| **Vivier candidats** | les candidats | les candidats | ✅ conforme |
| **À arbitrer** | quelque chose qui attend **moi**, avec un nombre | des rapprochements douteux, **sans compteur** : rien ne dit s'il y en a 0 ou 400 | ⚠️ `D23-007` |
| **Journalistes** | des journalistes | des journalistes | ✅ (mais doublon conceptuel avec « Médias ») |
| **Médias (presse)** | des articles ? des supports ? | des **organisations de presse** ; le titre d'écran dit « Médias », le fil d'Ariane dit « **Media** » | ⚠️ `D23-006` |
| **Couverture France** | une carte de couverture commerciale | une carte des départements **scrapés** | ⚠️ ambigu |
| **Collectes** | des collectes | l'écran s'appelle « Collectes » et **tout son contenu dit « campagne »** (25 chaînes visibles) | 🔴 `D23-004` |
| **Journaux de collecte** | des journaux de collecte | titre correct ; sous-titre : « **Monitoring des jobs de scraping** en temps réel » | 🔴 `D23-004` |
| **Roumanie** | ?? un pays au premier niveau du menu | une vue géographique de prospection | ⚠️ rangement |
| **Audiences (segments)** | ?? deux mots pour la même chose entre parenthèses | un constructeur de segments d'entreprises ; le sous-titre dit « prêts pour **campagne email** » — le mot que la barre a justement libéré | 🔴 `D23-004` |
| **Observabilité** | la santé du produit | santé pipeline + quota Hunter + 50 derniers « business events » | ⚠️ jargon |
| **Requêtes RGPD** | les demandes des personnes | conforme | ✅ |
| **Registre AI Act** | conforme | conforme | ✅ |
| **Journaux d'audit** | conforme | conforme | ✅ |
| **Utilisateurs** | mes collègues | conforme (sous-titre : « 4 rôles **RBAC** … **Spatie Permission teams** ») | ⚠️ jargon |
| **Paramètres** | **tout** le paramétrage (§19 : 8 sous-groupes) | « Workspace, intégrations, observabilité, apparence » — 4 thèmes sur 8 | 🔴 `D23-008` |
| **Tags** | des étiquettes | conforme — mais rangé dans « Réglages » alors qu'il gouverne les contacts | ⚠️ |
| **LLM Router** | ?? un terme d'ingénieur dans un menu métier | le choix des modèles d'IA et leur coût | 🔴 `D23-006` |
| **Proxies** | ?? | les fournisseurs de proxies ; l'écran s'appelle « **Fournisseurs de proxies** » | ⚠️ `D23-006` |
| **Rotations** | ?? | les rotations d'IP/user-agents de la collecte | 🔴 `D23-006` |

**8 entrées sur 22 promettent autre chose que ce qu'elles contiennent.**

---

## 5. Point 3 du §6.3 — le glossaire réel de l'interface

Sources dépouillées : `Sidebar.tsx`, `AutoBreadcrumbs.tsx`, tous les `PageHeader title=`,
`frontend/src/locales/fr.json`, `features/crm-console/types.ts`, les 30 fils d'Ariane
réellement rendus (`04_PREUVES/agent-23/fil-ariane-par-route.json`), et
`Axion-IA/axionia/src/lib/admin-nav.ts`.

### 5.1 Les quatre mots pour « une personne » — la confusion mère

| Mot | Où | Ce qu'il désigne **exactement** |
|---|---|---|
| **Contact** (`Contact.php`) | modèle, `/contacts`, entrée de menu | une personne **rattachée à une entreprise**. `contacts.company_id` est **`not null`** (vérifié : `\d contacts` sur `axion_crm`). **Une personne sans société ne peut pas exister.** |
| **Candidate** (`Candidate.php`) | `/console/vivier` | une personne **sans** entreprise, dans un workspace étanche. En-tête du modèle : « Entité DÉDIÉE, jamais un `Contact` ». |
| **Person / personne** | `/console/personnes/$personKey`, titre « Fiche 360° » | la **fiche unifiée** — la seule surface où un contact et un candidat sont la même personne |
| **`person_key`** | colonne des deux tables | `sha256` salé de l'e-mail, **calculé côté site**, clé de rapprochement inter-consoles (`SiteSyncEvent.php:192`) |

**Conséquence de navigation, pas de modèle :** l'entrée « Contacts » promet la base de
personnes du §23.3 (« une base, des vues ») et livre « les personnes qui ont une société ».
Presse, partenaires, investisseurs, fournisseurs, candidats — la moitié des vues épinglées
exigées — **ne peuvent pas y entrer**.

### 5.2 « Campagne » — trois objets, un mot, deux consoles

| Objet | Où | Mot employé |
|---|---|---|
| Campagne de **collecte** (scraping) | CRM `/campaigns` | barre : « Collectes » · écran : « **campagne** » ×25, assistant « Nouvelle **campagne** », filtre « Prêt pour une **campagne** » |
| Campagne de **génération de contenu** | axionia `content-gen/coverage` | « **Campagnes** » · `content-gen/campaigns/new` « Nouvelle **campagne** » |
| Campagne **e-mail** (lot L7, pas encore livrée) | — | le mot est réservé pour elle par la refonte de l'étape 0 |

Le renommage de l'étape 0 **s'est arrêté à la barre latérale**. Le mot qu'on voulait libérer
est aujourd'hui pris **deux fois**, dont une dans l'autre console.

### 5.3 Livrable exigé : `notion → mot retenu → mots à éliminer → occurrences`

| Notion | **Mot retenu** | Mots à éliminer | Occurrences à corriger (emplacements) |
|---|---|---|---|
| Une personne | **Contact** | *Person*, *Personne*, *Candidate*, *Fiche 360°*, *touchpoints* | route `/console/personnes/$personKey` ; `PersonTimelinePage.tsx:62` (« Fiche 360° — tous les **touchpoints** connus ») ; fil d'Ariane « Personnes » |
| Une société | **Organisation** | *Entreprise*, *Company*, *Média*, *denomination* | entrée « Entreprises » ; `/companies` ; `/media` ; `ContactsHubPage` |
| Le vivier | **Vivier candidats** | *Candidate family*, *Univers vivier* | ✅ déjà unique dans la barre |
| Collecte de données | **Collecte** | *scrape*, *scraper*, *scraping*, *run*, *job*, *campagne* | `ScraperRunsPage.tsx:330` (« Monitoring des **jobs de scraping** ») ; `DashboardPage.tsx:140` (« Lance ton premier **scrape** ») ; `CampaignWizardPage.tsx:465` (« départements à **scraper** ») ; toasts « **Campagne** mise en pause / reprise / annulée / lancée / créée / supprimée » (×10) ; `locales/fr.json` `nav.scraperRuns = "Scraper runs"` ; `/scraper-runs` (URL) |
| Un segment | **Segment** | *Audience* | entrée « Audiences (segments) » — un libellé qui porte sa propre traduction entre parenthèses est l'aveu écrit qu'aucun des deux mots ne s'impose ; `AudiencesListPage.tsx:104` « prêts pour **campagne email** » |
| Écran d'accueil | **Aujourd'hui** | *Tableau de bord*, *Accueil*, *Dashboard*, *KPI* | titre de section « Aujourd'hui » vs entrée « Tableau de bord » vs fil d'Ariane « **Accueil** » vs visite guidée « le **dashboard** affiche vos **KPIs** » — **quatre mots, un écran** |
| Boîte de réception | **Boîte de réception** | — | ⚠️ **déjà pris** : c'est le nom du groupe des 12 écrans de la console axionia (`ADMIN_NAV_GROUP_LABELS.contacts`). Collision inter-consoles à trancher **avant** de créer l'entrée CRM |
| Le lien vers l'autre console | **CRM** ↔ **Console axionia** | *Prospection* | `AdminSidebarNav.tsx:773` : le lien axionia → CRM s'appelle « **Prospection** » et son `title` dit « outil de prospection ». Or « Prospection » est **une entrée du groupe CONTACTS de la cible** (masse froide). Deux objets, un mot, deux consoles |
| Presse | **Presse** | *Médias*, *Media*, *Journalistes*, *Salle de presse* | barre « Médias (presse) » · titre « Médias » · fil « Media » · entrée « Journalistes » · axionia groupe « Salle de presse » + `/contacts/presse` |
| Client | **Client** | — | ⚠️ **trois objets** : `RELATION_TYPE_LABELS.client` (CRM), `/contacts/clients` (axionia = catégorie de messages), `/qualiopi/clients` « **Clients (CRM)** » (axionia) |
| Couverture | **Couverture** | — | ⚠️ **deux objets** : CRM « Couverture France » (départements collectés) vs axionia « Couverture des villes / par palier de population / par type de contenu » (contenus publiés) |
| Modèles d'IA | **Modèles d'IA** | *LLM*, *LLM Router*, *providers*, *use cases*, *fallback chain* | entrée « LLM Router » ; `LlmRouterPage.tsx:84` |
| Rotation de collecte | **Rotations de collecte** | *Rotations*, *rate-limiting*, *user-agents*, *targets* | entrée « Rotations » ; `RotationsPage.tsx:54` |
| Requête RGPD | **Demande RGPD** | *Requête* | fil d'Ariane « Requêtes » vs barre/écran « Requêtes RGPD » |

### 5.4 Fautes de vocabulaire mesurées, hors table

- **`locales/fr.json` est mort et faux.** 45 lignes ; le bloc `nav` porte encore
  `"scraperRuns": "Scraper runs"` et ignore 15 des 22 entrées. Aucune entrée de la barre ne
  passe par `t()` : seuls 5 fichiers utilisent `useTranslation` (`LoginPage`, `MagicLinkPage`,
  `TwoFactorPage`, `ColdEmailStub`, `LinkedInStub`). Un bloc `phase2.stub` y subsiste alors que
  la section « Phase 2 » a été retirée de la barre.
- **Fil d'Ariane en anglais sur 10 routes** : `Media`, `Journalists`, `Console`, `Vivier`,
  `Arbitrage`, `Personnes`, `Admin`, `Observability`, `International`, `New`
  (`04_PREUVES/agent-23/fil-ariane-par-route.json`).
- **Le fil d'Ariane porte encore les libellés retirés de la barre** : `/cold-email` →
  « E-mails à froid », `/linkedin` → « Prospection LinkedIn ». Les fausses promesses ont
  quitté le menu et **survivent dans le fil**.
- **Tutoiement et vouvoiement dans le même produit** : 19 chaînes tutoient (« Lance ton
  premier scrape », « Configure ta campagne », « Choisis les départements », « Crée ton premier
  segment »…) ; la visite guidée vouvoie (« Vous pouvez quitter », « Cliquez ici », « Pensez à
  activer »). Aucune règle nulle part.

---

## 6. Point 4 du §6.3 — la règle des compteurs

Règle du CDC : « **un compteur seulement s'il appelle une action, jamais un total décoratif** ».

### 6.1 Les compteurs que la cible exige, et qui manquent — **6 sur 6**

| Compteur cible | Appelle quelle action | État |
|---|---|---|
| Boîte de réception **(n)** | répondre à ce qui attend | ❌ écran absent |
| Mes rendez-vous **(n)** | lancer la visio | ❌ écran absent |
| Mes tâches **(n)** | faire la tâche | ❌ écran absent |
| À arbitrer **(n)** | trancher un doublon | ❌ **l'écran existe, le compteur non** |
| Comptes rendus à valider **(n)** | valider | ❌ écran absent |
| Vues épinglées par type (compteurs) | ouvrir la vue qui a bougé | ❌ vues absentes |

Mesure : `cible-v2.json` — **aucune entrée de la barre ne porte d'élément numérique**.

### 6.2 Les compteurs décoratifs qui traînent ailleurs — **6 trouvés**

| Où | Compteur | Appelle une action ? |
|---|---|---|
| `/` (accueil) | « Total entreprises — Toutes périodes confondues » | non |
| `/` | « Enrichies 24h — Fiches enrichies sur 24h » | non |
| `/` | « Nouvelles 7j — Découvertes sur 7 jours » | non |
| `/` | « Qualité moyenne — Score pondéré qualité » | non |
| `/campaigns` | « Total — campagnes » | non |
| `/campaigns` | « Entreprises créées — cumul toutes campagnes » | non |

**L'écran d'accueil du produit est composé à 100 % de totaux décoratifs.** Voir `D23-002`.

---

## 7. Point 5 du §6.3 — profondeur, largeur, nombre de clics

### 7.1 Largeur — « jamais plus de sept entrées par groupe »

| Groupe | Entrées | Verdict |
|---|---|---|
| Aujourd'hui | 1 | ✅ (mais 3 entrées cibles manquent) |
| Contacts | 6 (4 si drapeau fermé) | ✅ |
| Collecte | 4 | ✅ |
| Pilotage | 2 | ✅ |
| Conformité | 3 | ✅ (groupe à dissoudre) |
| Réglages | 6 | ✅ **aujourd'hui** — ⚠️ **la cible en ajoute 5** (les 8 sous-groupes du §19 moins les 3 déjà là) : à plat, ce groupe passerait à **13 entrées**. La règle des sept **impose** les sous-groupes ; ils n'existent pas. |

### 7.2 Profondeur — « aucune fonction courante à plus de deux niveaux »

La barre est un **accordéon à une seule section ouverte** : ouvrir un groupe referme le
précédent (`Sidebar.tsx`, `sectionOuverte`). Mesuré : sur `/`, **une seule** des six sections
est dépliée ; les cinq autres ont leur `<ul>` en `display:none` (`ulHidden: true`).

Conséquence chiffrée : **niveau 1 = groupe, niveau 2 = entrée** → conforme aux deux niveaux ;
mais **le coût en clics n'est pas 1, il est 2** pour 21 entrées sur 22.

### 7.3 Trois clics maximum vers toute action courante

| Parcours §23.4 | Budget | Mesuré | Verdict |
|---|---|---|---|
| Retrouver ce qui a été dit à un contact | recherche → fiche, < 10 s | ⌘K → clic sur la personne → **atterrit sur `/contacts`**, la liste complète | ❌ **la fiche n'est jamais atteinte** (`D23-005`) |
| Ouvrir une fiche depuis les listes | ≤ 3 clics | Contacts (1) → entrée Contacts (2) → nom d'une personne (3) — **et seulement si `person_key` n'est pas nul, et seulement si la personne est dans les 3 affichées de sa ligne** | ⚠️ conditionnel |
| Ouvrir une fiche, drapeau `console_v2` fermé | ≤ 3 clics | **impossible** : `ContactsListPage.tsx` ne contient aucun `Link` vers une fiche | ❌ |
| Lancer la visio d'un rendez-vous | 1 clic depuis l'accueil | écran absent | ❌ |
| Créer un contact complet | 1 clic depuis n'importe quel écran | aucun bouton de création globale dans l'en-tête ni la barre | ❌ |
| Atteindre n'importe quelle entrée hors section courante | — | **2 clics** (déplier + cliquer) | ✅ dans le budget |

---

## 8. Point 6 du §6.3 — 🔴 LE PLAN DE NAVIGATION CIBLE

### 8.1 La barre cible, arrêtée

```
AUJOURD'HUI                          (s'ouvre par défaut)
  Tableau de bord du jour            /              (contenu remplacé)
  Boîte de réception          (n)    /reception     ⟵ NEUF
  Mes rendez-vous             (n)    /rendez-vous   ⟵ NEUF
  Mes tâches                  (n)    /taches        ⟵ NEUF
CONTACTS                             (une base, des vues)
  Tous les contacts                  /contacts
  Prospects·Clients·Presse·Partenaires·Investisseurs·Fournisseurs  (vues épinglées, compteurs) ⟵ NEUF
  Organisations                      /companies
  Vivier candidats                   /console/vivier
  Prospection                        /prospection   ⟵ NEUF
  À arbitrer                  (n)    /console/arbitrage
ÉCHANGES                             ⟵ GROUPE ENTIER NEUF
  Tous les échanges                  /echanges
  Comptes rendus à valider    (n)    /echanges/a-valider
  Enregistrements                    /echanges/enregistrements
  Dossiers ouverts                   /dossiers
COLLECTE                             (§A.1 n°15 — tant que le chantier vit)
  Couverture                         /coverage
  Collectes                          /campaigns
  Journaux de collecte               /scraper-runs
PILOTAGE
  Tableaux de bord                   /pilotage      ⟵ NEUF
  Canal avec la console              /pilotage/canal ⟵ NEUF
  Coûts                              /pilotage/couts ⟵ NEUF
  Santé du système                   /admin/observability
RÉGLAGES                             (§19 — 8 sous-groupes, repliés)
  Personnes et types · Entretiens · Rendez-vous et rappels · Messages et modèles
  Équipe et sécurité · Données et conformité · Apparence · Intégrations
──────────────
  ↗ Console axionia                  https://…/admin   ⟵ NEUF
  Rechercher (⌘K)                    (remonte de l'en-tête dans le pied) ⟵ DÉPLACÉ
  Fiches récentes                    (les cinq dernières)              ⟵ NEUF
```

### 8.2 Le tableau de correspondance — **les six sections, toutes leurs entrées**

Principe directeur : **l'URL d'un écran qui survit ne change pas.** Une redirection n'est
écrite que là où un écran disparaît, fusionne ou change de nature. C'est ce qui garantit
« aucun 404, aucun signet cassé » au **plus bas coût**.

| # | Entrée actuelle | URL | → Emplacement cible | Décision | Redirection à écrire |
|---|---|---|---|---|---|
| **AUJOURD'HUI** |
| 1 | Tableau de bord | `/` | AUJOURD'HUI › **Tableau de bord du jour** | **renommée** (contenu remplacé : rendez-vous, tâches échues, relances, entrants non attribués ; les 4 KPI de collecte descendent dans PILOTAGE › Tableaux de bord) | aucune — même URL |
| **CONTACTS** |
| 2 | Contacts | `/console/contacts` | CONTACTS › **Organisations** | **fusionnée** dans `/companies` : les deux écrans listent la même chose, à l'unité de ligne près | **301 `/console/contacts` → `/companies`** |
| 3 | *(hors menu)* | `/contacts` | CONTACTS › **Tous les contacts** | **réintégrée** — l'orpheline redevient l'entrée principale, unité de ligne = **la personne**, et chaque ligne ouvre la fiche | aucune |
| 4 | Entreprises | `/companies` | CONTACTS › **Organisations** | **renommée** (« Organisation » couvre société, média, école, institution — « Entreprise » non) | aucune — même URL |
| 5 | Journalistes | `/journalists` | CONTACTS › vue épinglée **Presse** de « Tous les contacts » | **fusionnée** — un journaliste est un contact de type presse, pas une famille d'écran | **301 `/journalists` → `/contacts?vue=presse`** |
| 6 | Médias (presse) | `/media` | CONTACTS › vue épinglée **Presse** d'« Organisations » | **fusionnée** — un média est une organisation | **301 `/media` → `/companies?vue=presse`** ; `/media/$id` **conservée** (identifiants distincts : une fusion d'URL de détail exigerait une table de correspondance, coût sans bénéfice) |
| 7 | Vivier candidats | `/console/vivier` | CONTACTS › **Vivier candidats** | **conservée** telle quelle | aucune |
| 8 | À arbitrer | `/console/arbitrage` | CONTACTS › **À arbitrer (n)** | **conservée + compteur** | aucune |
| — | — | — | CONTACTS › **Prospection** | **créée** (masse froide, jamais mélangée — §11.1) | — |
| — | — | — | CONTACTS › **6 vues épinglées** | **créées** (filtres du même écran, réordonnables) | — |
| **ÉCHANGES** — 4 entrées **créées**, 0 correspondance : aucun écran actuel ne porte un échange |
| **COLLECTE** |
| 9 | Couverture France | `/coverage` | COLLECTE › **Couverture** | **renommée** (« France » devient un filtre de pays, pas un mot du menu) | aucune |
| 10 | Collectes | `/campaigns` | COLLECTE › **Collectes** | **conservée** — mais **25 chaînes internes à réécrire** (« campagne » → « collecte ») | aucune |
| 11 | Journaux de collecte | `/scraper-runs` | COLLECTE › **Journaux de collecte** | **conservée** — sous-titre à réécrire | aucune |
| 12 | Roumanie | `/international/roumanie` | COLLECTE › Couverture, **filtre pays** | **fusionnée** — un pays n'est pas une entrée de menu | **301 `/international/roumanie` → `/coverage?pays=RO`** |
| **PILOTAGE** |
| 13 | Audiences (segments) | `/audiences` | PILOTAGE › **Segments** | **renommée** — « Audience » est réservé au lot L7 | aucune (URL conservée, libellé changé) |
| 14 | Observabilité | `/admin/observability` | PILOTAGE › **Santé du système** | **renommée** | aucune |
| — | — | — | PILOTAGE › **Tableaux de bord** | **créée** (recueille les 4 KPI chassés de l'accueil) | — |
| — | — | — | PILOTAGE › **Canal avec la console** | **créée** (§22.7 ; le pendant de « Synchro CRM » côté axionia) | — |
| — | — | — | PILOTAGE › **Coûts** | **créée** (§25.3 ; recueille le suivi de coût aujourd'hui enfoui dans `/llm/router`) | — |
| **CONFORMITÉ — groupe supprimé, ses trois entrées déplacées** |
| 15 | Requêtes RGPD | `/rgpd/requests` | RÉGLAGES › **Données et conformité** | **déplacée + renommée** « Demandes RGPD » | aucune |
| 16 | Registre AI Act | `/rgpd/ai-act` | RÉGLAGES › **Données et conformité** | **déplacée** | aucune |
| 17 | Journaux d'audit | `/audit-logs` | RÉGLAGES › **Données et conformité** | **déplacée** | aucune |
| **RÉGLAGES** |
| 18 | Utilisateurs | `/users` | RÉGLAGES › **Équipe et sécurité** | **déplacée** | aucune |
| 19 | Paramètres | `/settings` | RÉGLAGES › **Apparence** + **Intégrations** + **Équipe et sécurité** | **éclatée** — un écran fourre-tout ne peut pas être 4 sous-groupes | `/settings` **conservée** comme page d'atterrissage des réglages (« ce qui n'est pas encore réglé », §19) |
| 20 | Tags | `/tags` | RÉGLAGES › **Personnes et types** | **déplacée** | aucune |
| 21 | LLM Router | `/llm/router` | RÉGLAGES › **Intégrations**, **Modèles d'IA** | **déplacée + renommée** | aucune |
| 22 | Proxies | `/llm/proxy-providers` | RÉGLAGES › **Intégrations**, **Fournisseurs de proxies** | **déplacée + renommée** (alignée sur le titre de l'écran) | aucune |
| 23 | Rotations | `/llm/rotations` | RÉGLAGES › **Intégrations**, **Rotations de collecte** | **déplacée + renommée** | aucune |
| **PIED DE BARRE** — 3 éléments **créés** : ↗ Console axionia · Rechercher ⌘K · Fiches récentes |
| **ROUTES HORS MENU — la dette de l'étape 0** |
| 24 | *(retirée du menu)* | `/cold-email` | — | **supprimée** | **301 → `/pas-encore-livre?lot=L7`** (écran unique, hors menu, qui nomme le lot et la date) |
| 25 | *(retirée du menu)* | `/linkedin` | — | **supprimée** | **301 → `/pas-encore-livre?lot=L7`** |
| 26 | *(route retirée à l'étape 0)* | `/crm` | — | **déjà supprimée, redirection oubliée** | **301 → `/contacts`** — aujourd'hui : 404 **sans barre latérale** (mesuré) |
| 27 | *(route retirée à l'étape 0)* | `/analytics` | — | **déjà supprimée, redirection oubliée** | **301 → `/pilotage`** — aujourd'hui : 404 sans barre |
| 28 | fiche unifiée | `/console/personnes/$personKey` | — | **conservée**, mais **désenclavée** : atteignable depuis ⌘K, depuis « Tous les contacts », depuis « Organisations » | aucune |

### 8.3 Bilan chiffré

| Décision | Nombre |
|---|---|
| **Conservées** telles quelles (libellé et emplacement cible atteints) | **5** — Vivier candidats, À arbitrer, Collectes, Journaux de collecte, `/console/personnes` |
| **Renommées** | **8** — Tableau de bord, Entreprises, Couverture France, Audiences (segments), Observabilité, LLM Router, Proxies, Rotations |
| **Déplacées** (changent de groupe) | **9** — Requêtes RGPD, Registre AI Act, Journaux d'audit, Utilisateurs, Tags, LLM Router, Proxies, Rotations, Contacts→Organisations |
| **Fusionnées** | **4** — `/console/contacts`→`/companies`, Journalistes→vue Presse, Médias→vue Presse, Roumanie→filtre pays |
| **Supprimées** | **2 écrans** (`/cold-email`, `/linkedin`) **+ 1 groupe** (« Conformité ») |
| **Redirigées** (redirections à écrire) | **8** — `/console/contacts`, `/journalists`, `/media`, `/international/roumanie`, `/cold-email`, `/linkedin`, `/crm`, `/analytics` |
| **Réintégrées** (orpheline remise au menu) | **1** — `/contacts` |
| **Créées** | **14** — 3 (Aujourd'hui) + 2 (Contacts) + 4 (Échanges) + 3 (Pilotage) + 3 (pied de barre), plus les 8 sous-groupes de Réglages |

**Aucune entrée retirée ne se perd** : chacune des 4 fusions et des 4 suppressions porte sa
redirection, et les 6 vues épinglées reprennent le contenu des écrans fusionnés.

---

## 9. Point 7 du §6.3 — les 37 écrans réels, tranchés

`grep -c "path:" frontend/src/app/routeTree.tsx` → **37**. Décision et justification pour
chacun. « On le garde au cas où » n'apparaît nulle part : chaque ligne dit **qui s'en sert**.

| # | Écran | Décision | Justification |
|---|---|---|---|
| 1 | `/login` | **gardé tel quel** | seule porte d'entrée |
| 2 | `/2fa` | **gardé tel quel** | exigé §20 |
| 3 | `/magic-link` | **gardé tel quel** | second facteur d'accès |
| 4 | `/password-reset` | **gardé tel quel** | — |
| 5 | `/` Tableau de bord | **renommé + contenu remplacé** | l'accueil doit ouvrir la journée (§23.3), pas afficher 4 totaux de collecte |
| 6 | `/companies` liste | **renommé** → Organisations | reçoit la fusion de `/console/contacts` et la vue Presse |
| 7 | `/companies/$id` détail | **gardé tel quel** | fiche d'organisation, cible §22.6 |
| 8 | `/contacts` liste | **rangé ailleurs** (remis au menu) + **enrichi** | devient « Tous les contacts » ; chaque ligne doit ouvrir la fiche — aujourd'hui aucun lien |
| 9 | `/international/roumanie` | **fusionné** | un pays est un filtre de Couverture |
| 10 | `/media` liste | **fusionné** | un média est une organisation de type presse |
| 11 | `/media/$id` détail | **gardé tel quel** | identifiants propres ; fusionner l'URL de détail coûterait une table de correspondance |
| 12 | `/journalists` | **fusionné** | un journaliste est un contact de type presse |
| 13 | `/coverage` | **renommé** → Couverture | sert la collecte, chantier vivant (§A.1 n°15) |
| 14 | `/scraper-runs` | **gardé** (libellés internes à réécrire) | seul endroit où l'on voit une collecte échouer |
| 15 | `/llm/router` | **rangé ailleurs + renommé** | Réglages › Intégrations ; « Modèles d'IA » |
| 16 | `/llm/proxy-providers` | **rangé ailleurs + renommé** | idem |
| 17 | `/llm/rotations` | **rangé ailleurs + renommé** | idem |
| 18 | `/rgpd/requests` | **rangé ailleurs** | Réglages › Données et conformité (§19) |
| 19 | `/rgpd/ai-act` | **rangé ailleurs** | idem |
| 20 | `/audit-logs` | **rangé ailleurs** | idem |
| 21 | `/users` | **rangé ailleurs** | Réglages › Équipe et sécurité |
| 22 | `/settings` | **éclaté** | un écran ne peut pas être 4 des 8 sous-groupes du §19 ; devient la page « ce qui n'est pas encore réglé » |
| 23 | `/campaigns` liste | **gardé** (25 chaînes à réécrire) | pilote la collecte |
| 24 | `/campaigns/new` assistant | **gardé + renommé** | « Nouvelle collecte » |
| 25 | `/campaigns/$id` détail | **gardé + renommé** | idem |
| 26 | `/tags` | **rangé ailleurs** | Réglages › Personnes et types — c'est un référentiel gouverné (§19) |
| 27 | `/audiences` liste | **renommé** → Segments | libère « Audience » pour L7 |
| 28 | `/audiences/new` | **renommé** | idem |
| 29 | `/audiences/$id` | **renommé** | idem |
| 30 | `/admin/observability` | **renommé** → Santé du système | remonte dans PILOTAGE ; le préfixe `/admin` n'a plus de sens |
| 31 | `/console/contacts` | **fusionné** | doublon d'`/companies` : même unité de ligne (l'entreprise), même table |
| 32 | `/console/vivier` | **gardé tel quel** | seul écran de l'univers étanche |
| 33 | `/console/arbitrage` | **gardé + compteur** | sans compteur, personne ne sait qu'il y a quelque chose à trancher |
| 34 | `/console/personnes/$personKey` | **gardé + désenclavé** | c'est **la fiche** ; aujourd'hui atteignable par **un seul chemin** |
| 35 | `/cold-email` | **retiré** | bouchon 501, promesse du lot L7 ; redirigé |
| 36 | `/linkedin` | **retiré** | idem |
| 37 | `/*` NotFound | **refait** | rendu **hors du layout** : un signet cassé prive l'utilisateur de toute la navigation (mesuré) |

Répartition : **12 gardés tels quels · 9 renommés · 8 rangés ailleurs · 5 fusionnés ·
2 retirés · 1 éclaté** (certains cumulent renommage et rangement ; l'écran 37 est refait).

---

## 10. Point 8 du §6.3 — la visite guidée

`frontend/src/components/OnboardingTour.tsx` — 7 étapes visant `body`, `sidebar`,
`global-search`, `nav-companies`, `nav-dashboard`, `dark-mode`, `nav-settings`.
`data-tour="nav-campaigns"` est **conservé dans la barre et n'est plus visé par aucune étape**.

### 10.1 Est-elle cohérente avec la barre actuelle ? **Non — mesuré, pas déduit**

La barre est un accordéon à une seule section ouverte. Sur l'écran d'accueil, seule
« Aujourd'hui » est dépliée : `nav-companies` (section Contacts) et `nav-settings`
(section Réglages) ont un `<ul>` parent en `display:none`.

Sonde jouée (`04_PREUVES/agent-23/visite-guidee-code-actuel.json`) :

```
étape 1  Bienvenue                          (Step 1 of 7)  ✅
étape 2  la barre latérale                  (Step 2 of 7)  ✅
étape 3  recherche globale                  (Step 3 of 7)  ✅
   ⚠️ le compteur saute de « Step 3 of 7 » à « Step 5 of 7 »
étape 4  ✖ « parcourir vos entreprises »    JAMAIS AFFICHÉE — cible invisible
étape 5  le dashboard / KPIs                (Step 5 of 7)  ✅
étape 6  mode clair/sombre                  (Step 6 of 7)  ✅
étape 7  ✖ « activez la double authentification » + le mot de la fin
         LA VISITE S'ARRÊTE : plus aucune infobulle
```

**5 étapes sur 7 jouent. Deux ne s'affichent jamais** — dont celle qui recommande la double
authentification, et le mot de la fin. Et `POST /auth/onboarding/complete` **part quand même**
(mesuré, 1 appel) : la visite se marque « faite » après avoir montré 5/7. **Ces deux étapes ne
seront jamais revues.**

**Témoin négatif** : la même sonde relancée avec les sections forcées dépliées
(`display:block`, CSS injecté, aucun fichier produit touché) affiche **7 étapes sur 7**,
étape 4 et étape 7 comprises. La sonde était donc capable de les voir.

### 10.2 Est-elle cohérente avec la barre **cible** ? Non plus

- elle nomme « Contacts, Collecte, Pilotage, Conformité, Réglages » — la cible n'a **pas** de
  groupe « Conformité » et **a** un groupe « ÉCHANGES » ;
- elle dit « le **dashboard** affiche vos **KPIs** » — la cible n'a ni dashboard ni KPI à
  l'accueil, elle a la journée ;
- elle dit « laisser les **scrapers** les remplir » — mot proscrit par le renommage de
  l'étape 0 ;
- elle ne montre ni la boîte de réception, ni les rendez-vous, ni la fiche, ni le lien vers la
  console axionia — c'est-à-dire **rien de ce qui fait la journée**.

### 10.3 Ce qu'il faut faire — **une seule fois, sur la cible**

L'étape 0 exigeait « visite guidée refaite une fois sur la barre cible » (§A.1 n°15). Elle **ne
l'a pas été** : la barre a été refondue, la visite laissée en l'état, et personne ne l'a jouée.
La refaire deux fois (une fois maintenant sur la barre actuelle, une fois sur la cible) serait
exactement ce que l'exigence interdit.

**Décision : ne pas la réparer maintenant.** Deux gestes, et deux seulement :
1. **Aujourd'hui, à coût quasi nul** — corriger la sélection de cible pour qu'une étape dont la
   cible est masquée **ouvre d'abord sa section** (`disableScrolling: false` +
   `spotlightClicks`, ou un `beforeStep` qui déplie). Sinon la visite continuera de mentir.
   *Ou*, plus honnête encore : retirer les deux étapes qui ne s'affichent pas, pour que la
   visite ne prétende plus « 7 » quand elle en montre 5.
2. **À la livraison de la barre cible** — réécriture complète : 8 étapes
   (Aujourd'hui → Boîte de réception → une fiche → Échanges → Recherche ⌘K → Console
   axionia → Réglages → fin), avec les `data-tour` posés **au moment** où la barre est écrite,
   pas rattrapés après.
3. Supprimer `data-tour="nav-campaigns"`, orphelin depuis l'étape 0.

---

## 11. Point 9 du §6.3 — la console axionia et ses 12 écrans « Contacts »

### 11.1 État mesuré

`Axion-IA/axionia/src/lib/admin-nav.ts` — groupe `contacts`, libellé de groupe
**« Boîte de réception »** (renommé le 2026-07-29), **12 entrées** :

| # | Entrée | Route | Objet réel |
|---|---|---|---|
| 1 | Tout | `/contacts` | vue unifiée des 4 sources |
| 2 | Appels réservés | `/contacts/appels` | `calendly_events` |
| 3 | Messages | `/contacts/messages` | `Submission` |
| 4 | ↳ Clients | `/contacts/clients` | `Submission`, catégorie figée |
| 5 | ↳ Presse | `/contacts/presse` | idem |
| 6 | ↳ Partenariats | `/contacts/partenariats` | idem |
| 7 | ↳ Investisseurs | `/contacts/investisseurs` | idem |
| 8 | ↳ Conférences | `/contacts/conferences` | idem |
| 9 | ↳ Recrutement | `/contacts/commercial` | idem |
| 10 | ↳ Podcast | `/podcast` | `PodcastRequest` |
| 11 | ↳ Autres | `/contacts/autres` | `Submission` |
| 12 | Candidatures | `/contacts/candidatures` | `JobApplication` |

À quoi s'ajoutent **2 routes déjà retirées du menu et redirigées** en interne
(`/contacts/calendly/*`, `/contacts/rendez-vous/*`) : **le précédent existe, la console sait
retirer un écran derrière une redirection.**

### 11.2 État du retrait par paliers (§25.1) : **palier 0 sur 4**

| Palier §25.1 | Exigence | État mesuré |
|---|---|---|
| 1 | Le CRM montre tout et **la parité est prouvée** (critère 18) | ❌ non prouvée — le CRM n'a **aucun** écran de boîte de réception |
| 2 | Bandeau « Cette vue existe dans le CRM → ouvrir » sur les 12 écrans | ❌ 0 occurrence dans `axionia/src` |
| 3 | **Redirection derrière un drapeau**, `false` rend les écrans en une minute | ❌ aucun drapeau de ce type. Les drapeaux existants (`CRM_SYNC_ENABLED`, `CRM_SYNC_CANDIDATES_ENABLED`) pilotent **l'émission d'événements**, pas l'affichage |
| 4 | Retrait du code après un mois sans réouverture | ❌ sans objet |

### 11.3 La trajectoire, écrite

**Prérequis absolu** : rien ne bouge côté réception. Formulaires, réservations Calendly,
messages continuent d'arriver, d'être enregistrés, de partir en alerte Telegram et d'être
poussés au CRM **exactement comme aujourd'hui** (critère 25 du §29). Le retrait ne touche
**que la navigation**, jamais `src/features/*/actions.ts` ni les workers.

- **P0 — préalable (CRM).** Livrer AUJOURD'HUI › Boîte de réception. Sans elle, aucun palier
  n'est légitime.
- **P1 — parité prouvée.** Sur une semaine glissante : nombre de réservations, soumissions,
  candidatures et demandes podcast vues par la console = nombre d'événements reçus par le CRM.
  Écart zéro, ou expliqué ligne à ligne. **Tant que ce n'est pas prouvé, la console ne change
  pas.** Preuve archivée et datée.
- **P2 — bandeau, sans rien retirer.** Un composant unique `<VueDeplaceeVersLeCrm>` posé en
  tête des 12 écrans : « Cette vue existe dans le CRM → ouvrir ». Le lien porte le **contexte**
  (le canal, le filtre), pas la racine du CRM. Comparaison côte à côte pendant 3 semaines,
  avec compteur d'ouverture des anciens écrans. **Réversible : on retire un composant.**
- **P3 — redirection derrière un drapeau.**
  `NEXT_PUBLIC_CONTACTS_REDIRECT_CRM` (`z.enum(["true","false"]).optional()` dans `src/env.ts`,
  défaut **`false`**). À `true`, chacune des 12 routes rend un `redirect()` vers la vue CRM
  équivalente — **le mécanisme exact déjà en place** pour `/contacts/calendly` et
  `/contacts/rendez-vous`, donc rien à inventer. Les 12 entrées quittent la sidebar par le même
  drapeau, remplacées par **une seule** entrée « ↗ CRM ».
  **Chemin de retour prouvé avant de lever le drapeau** : le remettre à `false` et redémarrer
  rend les 12 écrans — geste chronométré, exigé sous une minute, rejoué **avant** chaque palier.
- **P4 — retrait du code.** Seulement si le compteur de P2/P3 montre **zéro réouverture pendant
  un mois**. Les routes deviennent des redirections permanentes (jamais des 404).

**Deux corrections de vocabulaire à faire au palier P2, pas après** — sinon le retrait
installe la confusion au lieu de la lever :
1. Le lien axionia → CRM s'appelle **« Prospection »** (`AdminSidebarNav.tsx:773`, `title` :
   « outil de prospection »). Le CDC en fait **« CRM »** (§22.6), et réserve « Prospection » à
   une entrée du groupe CONTACTS du CRM. **À renommer « ↗ CRM ».**
2. Le groupe axionia s'appelle **« Boîte de réception »** — le nom exact de l'entrée cible du
   CRM. Deux boîtes de réception dans deux consoles, c'est la définition du bordel.
   **Trancher** : la boîte de réception est **dans le CRM** ; le groupe axionia devient
   « Entrants du site » au palier P2, puis disparaît au P3.

---

## 12. Constats

### [D23-001] L'atelier local sert une barre latérale vieille de 32 heures — toute mesure d'écran y est fausse
- Sévérité      : S1 grave
- Domaine       : navigation
- Référence     : main e8924b8
- Emplacement   : conteneur `axion-crm-app`, `/srv/app/dist/assets/index-DPQz8SpC.js`
- Constat       : `https://app.localhost` sert un bundle daté du **17 août 07:12**, antérieur de 32 h au commit `da97826` (18 août 17:39) qui refond la barre ; l'écran affiche donc **10 sections et 28 entrées** — dont « Campagnes », « Runs de scraping », « Data », « Phase 2 » et 4 entrées vers `/crm`, `/analytics`, `/email-templates`, `/email-sends` qui **n'existent plus dans le routeur**.
- Preuve        : `docker image inspect` → `imageCreated=2026-08-17T07:12:54Z` ; `grep -c 'Runs de scraping' index-DPQz8SpC.js` → **2** ; `grep -c 'Journaux de collecte'` → **0** ; `grep -c 'Phase 2'` → **2** ; dump navigateur `04_PREUVES/agent-23/barre-v2.json` (10 sections)
- Témoin négatif: la même sonde jouée contre `vite` sur le code de `main` rend **6 sections / 22 entrées** (`04_PREUVES/agent-23/cible-v2.json`) — elle sait donc distinguer les deux barres
- Impact        : tout agent qui applique la doctrine « le geste réel avant l'instrumentation » sur `app.localhost` mesure une interface qui n'existe plus dans le dépôt et rouvre des constats déjà corrigés — c'est très exactement l'erreur que A-006 reproche au prompt d'audit
- Reproduction  : `docker exec axion-crm-app grep -c 'Journaux de collecte' /srv/app/dist/assets/index-DPQz8SpC.js` → `0`, puis ouvrir `https://app.localhost`
- Correctif     : reconstruire l'image `app` sur `main` (≈ 10 min), **et** afficher le SHA du build dans le pied de la barre pour qu'un décalage se voie au lieu de se déduire (≈ 30 min)
- Statut        : ouvert

### [D23-002] L'écran d'accueil montre quatre totaux décoratifs et ne dit rien de la journée
- Sévérité      : S2 défaut
- Domaine       : navigation / UX
- Référence     : main e8924b8
- Emplacement   : `frontend/src/features/dashboard/DashboardPage.tsx:111-207`
- Constat       : le seul écran du groupe « Aujourd'hui » affiche « Total entreprises · Toutes périodes confondues », « Enrichies 24h », « Nouvelles 7j », « Qualité moyenne » et, à vide, « Lance ton premier scrape » — aucun rendez-vous, aucune tâche, aucun entrant.
- Preuve        : `grep -n "KpiCard" -A4 DashboardPage.tsx` ; capture `04_PREUVES/agent-23/cible-v2-accueil.png`
- Témoin négatif: la même sonde relève 6 compteurs numériques et **0** élément d'agenda ou de tâche dans `<main>`
- Impact        : le CDC exige « un compteur seulement s'il appelle une action, jamais un total décoratif » et « écran d'accueil orienté action » ; **4 compteurs sur 4 sont décoratifs** et l'ouverture de journée du §23.3 n'existe pas
- Reproduction  : ouvrir `/`
- Correctif     : déplacer les 4 KPI dans PILOTAGE › Tableaux de bord, reconstruire l'accueil sur rendez-vous / tâches échues / relances / entrants non attribués (chantier étape 1a, ≈ 3 j)
- Statut        : ouvert

### [D23-003] L'entrée « Contacts » liste des entreprises, et deux entrées voisines listent la même chose
- Sévérité      : S2 défaut
- Domaine       : navigation
- Référence     : main e8924b8
- Emplacement   : `backend/app/Http/Controllers/Api/Crm/ContactsHubController.php:23` ; `frontend/src/features/crm-console/ContactsHubPage.tsx:190-224` ; `frontend/src/components/layout/Sidebar.tsx:148-160`
- Constat       : sous le groupe « Contacts », l'entrée « Contacts » (`/console/contacts`) rend une liste dont **l'unité de ligne est l'entreprise** (« Unité de ligne : l'ENTREPRISE avec ses personnes jointes », en-tête du contrôleur), et l'entrée voisine « Entreprises » (`/companies`) rend elle aussi des entreprises ; par ailleurs `contacts.company_id` est **`not null`**, donc une personne sans société ne peut pas figurer dans « Contacts ».
- Preuve        : `docker exec axion-crm-postgres psql -U axion -d axion_crm -c "\d contacts"` → `company_id | bigint | not null` ; lecture de `ContactsHubPage.tsx:190` (`rows.map((company) => …)`)
- Témoin négatif: `ContactsListPage.tsx` (l'autre écran « Contacts », drapeau fermé) rend bien des **personnes** — les deux écrans homonymes n'ont donc pas la même unité, et le contrôle sait faire la différence
- Impact        : le §23.3 promet « CONTACTS — une base, des vues » ; presse, partenaires, investisseurs, fournisseurs et candidats — 5 des 6 vues épinglées exigées — ne peuvent structurellement pas y entrer. Un utilisateur qui cherche une personne ouvre au choix deux écrans d'entreprises
- Reproduction  : ouvrir `/console/contacts` puis `/companies` avec `console_v2=true`
- Correctif     : « Contacts » = les personnes (`/contacts`), « Organisations » = les sociétés (`/companies`), fusion de `/console/contacts` dans `/companies` avec 301 (§8.2 de ce chapitre, ≈ 2 j)
- Statut        : ouvert

### [D23-004] Le renommage de l'étape 0 s'est arrêté à la barre : « campagne » et « scraping » vivent toujours dans les écrans
- Sévérité      : S2 défaut
- Domaine       : navigation / UX
- Référence     : main e8924b8
- Emplacement   : `features/campaigns/*.tsx` (25 chaînes) ; `features/scraping/ScraperRunsPage.tsx:330` ; `features/dashboard/DashboardPage.tsx:140` ; `features/audiences/AudiencesListPage.tsx:104` ; `locales/fr.json:24`
- Constat       : l'écran atteint par l'entrée « Collectes » s'intitule « Collectes » mais dit « campagne » dans ses 10 messages de confirmation, son assistant (« Nouvelle campagne »), ses KPI (« Total — campagnes ») et son filtre (« Prêt pour une campagne ») ; l'écran « Journaux de collecte » se sous-titre « Monitoring des **jobs de scraping** en temps réel » ; `locales/fr.json` porte encore `nav.scraperRuns = "Scraper runs"`.
- Preuve        : `grep -rin "ampagne" features/campaigns/` → 27 lignes ; `grep -n "title=" ScraperRunsPage.tsx` → `title="Journaux de collecte" subtitle="Monitoring des jobs de scraping en temps réel."`
- Témoin négatif: le même `grep` sur `Sidebar.tsx` ne rend que les 2 lignes du commentaire d'étape 0 — le renommage y est bien complet, il ne l'est nulle part ailleurs
- Impact        : le §A.1 n°15 exigeait de libérer « Campagnes » pour les e-mails du lot L7 ; le mot reste pris dans le CRM **et** dans la console axionia (`content-gen/coverage` = « Campagnes »). Le critère 24 (« aucun libellé n'a de synonyme ailleurs dans le produit ») est faux dès le premier clic
- Reproduction  : cliquer « Collectes », puis « Nouvelle campagne »
- Correctif     : 27 chaînes à réécrire dans `features/campaigns`, 4 dans `scraping`, 3 dans `dashboard`, 1 dans `audiences`, purge de `locales/fr.json` (≈ 0,5 j) — et un test de garde qui rougit sur `scrap|campagne` dans les chaînes visibles
- Statut        : ouvert

### [D23-005] La recherche globale trouve une personne et ouvre la liste au lieu de sa fiche
- Sévérité      : S2 défaut
- Domaine       : navigation
- Référence     : main e8924b8
- Emplacement   : `frontend/src/components/ui/GlobalSearch.tsx:117`
- Constat       : dans la palette ⌘K, sélectionner un résultat de la catégorie « Contacts » exécute `navigate({ to: '/contacts' })` — sans identifiant, sans filtre : l'utilisateur atterrit sur la liste complète des contacts. La ligne voisine, pour une entreprise, navigue bien vers `/companies/$companyId`.
- Preuve        : sonde jouée dans un navigateur (`04_PREUVES/agent-23/recherche-vers-fiche.json`) : recherche « Dupont » → la palette affiche « MARIE DUPONT / MARIE@DUPONT.FR » → clic → `urlApresClicSurLaPersonne: "http://127.0.0.1:5199/contacts"`, `titreEcranAtteint: "Contacts"`. Captures `recherche-palette.png`, `recherche-apres-clic.png`
- Témoin négatif: dans la même palette et la même sonde, le résultat « entreprise » navigue vers `/companies/42` — le contrôle sait donc reconnaître une navigation qui aboutit
- Impact        : le parcours §23.4 « Retrouver ce qui a été dit à un contact — recherche → fiche → réponse en moins de 10 s » est **impossible** ; le critère 1 du §29 (« toute personne retrouvable en moins de 5 s ») ne peut pas être satisfait. La recherche ne couvre par ailleurs ni les candidats, ni les médias, ni les journalistes, ni les commandes de navigation
- Reproduction  : ⌘K → taper un nom → cliquer le résultat sous « CONTACTS »
- Correctif     : router vers `/console/personnes/$personKey` quand la clé existe, sinon `/companies/$companyId#contact-$id` ; étendre `/search` aux candidats et aux médias (≈ 1 j)
- Statut        : ouvert

### [D23-006] Le fil d'Ariane parle anglais sur dix routes et porte encore les libellés retirés de la barre
- Sévérité      : S3 finition
- Domaine       : navigation
- Référence     : main e8924b8
- Emplacement   : `frontend/src/components/layout/AutoBreadcrumbs.tsx:11-31`
- Constat       : la table `LABELS` couvre 18 chemins sur 28 ; les autres tombent dans `humanize()` et rendent « Media », « Journalists », « Console », « Vivier », « Arbitrage », « Personnes », « Admin », « Observability », « International », « New ». La table mappe toujours `/cold-email` → « E-mails à froid » et `/linkedin` → « Prospection LinkedIn », deux libellés retirés de la barre à l'étape 0 parce qu'ils promettaient des écrans inexistants.
- Preuve        : les 30 fils réellement rendus dans un navigateur, `04_PREUVES/agent-23/fil-ariane-par-route.json`
- Témoin négatif: sur les 18 chemins mappés, le fil rend bien le français attendu (« Journaux de collecte », « Collectes », « Registre AI Act ») — la sonde sait donc lire un fil correct
- Impact        : le §19 exige « le titre de l'écran dit ce qu'on y fait, en français courant, sans terme technique » ; le fil d'Ariane est le second titre de chaque écran et il n'obéit à aucune de ces règles. Le mot « Média » y devient « Media », « Aujourd'hui » y devient « Accueil »
- Reproduction  : ouvrir `/console/personnes/abcdef0123456789` → « Accueil / Console / Personnes / Abcdef0123456789 »
- Correctif     : compléter `LABELS` (10 chemins), retirer les 2 libellés morts, aligner « Accueil » sur « Aujourd'hui » (≈ 2 h) — et un test qui rougit si une route de `routeTree.tsx` n'a pas de libellé
- Statut        : ouvert

### [D23-007] La barre ne porte aucun compteur : les six que la cible exige manquent, dont un sur une entrée déjà livrée
- Sévérité      : S2 défaut
- Domaine       : navigation
- Référence     : main e8924b8
- Emplacement   : `frontend/src/components/layout/Sidebar.tsx:44-52` (le type `NavItem` n'a pas de champ de compteur)
- Constat       : aucune des 22 entrées ne porte d'élément numérique. « À arbitrer » — la seule entrée de la cible qui exige un compteur **et** dont l'écran est livré — n'en a pas : rien ne distingue 0 rapprochement en attente de 400.
- Preuve        : dump navigateur `04_PREUVES/agent-23/cible-v2.json` — 22 entrées, champ `compteur: null` sur les 22
- Témoin négatif: la même sonde relève **6** éléments purement numériques dans `<main>` sur l'accueil et la liste des collectes — elle sait donc reconnaître un compteur quand il y en a un ; ils sont simplement tous du mauvais côté
- Impact        : la règle du CDC est inversée dans les deux sens — les compteurs qui appellent une action sont absents de la barre, et des totaux décoratifs occupent l'écran d'accueil. Sans compteur sur « À arbitrer », un doublon peut attendre indéfiniment sans que personne ne le sache
- Reproduction  : ouvrir n'importe quel écran, déplier « Contacts »
- Correctif     : ajouter `badge?: number` à `NavItem` et le brancher sur `GET /crm/arbitrage/count` (≈ 3 h) ; les 5 autres compteurs viennent avec leurs écrans
- Statut        : ouvert

### [D23-008] Le groupe « Réglages » ne peut pas devenir les huit sous-groupes du §19 sans dépasser la règle des sept
- Sévérité      : S2 défaut
- Domaine       : navigation
- Référence     : main e8924b8
- Emplacement   : `frontend/src/components/layout/Sidebar.tsx:109-121` ; `frontend/src/features/settings/SettingsPage.tsx:118`
- Constat       : « Réglages » porte 6 entrées à plat et aucun sous-groupe ; le §19 en impose **8** (Personnes et types · Entretiens · Rendez-vous et rappels · Messages et modèles · Équipe et sécurité · Données et conformité · Apparence · Intégrations). En y versant les 3 entrées de « Conformité » (que la cible dissout) le groupe passerait à **13 entrées à plat**, contre 7 maximum. L'écran `/settings` se sous-titre « Workspace, intégrations, observabilité, apparence » — 4 thèmes, dont un (« observabilité ») qui n'est pas un réglage.
- Preuve        : dump `cible-v2.json` (6 entrées) + comptage des 8 sous-groupes du §19 ; `grep -n "subtitle" SettingsPage.tsx`
- Témoin négatif: le même dump montre que les 5 autres groupes tiennent tous sous 7 — la règle est donc respectable et respectée ailleurs
- Impact        : « Réglages » est le groupe que le critère 20 du §29 met à l'épreuve (« trois personnes extérieures modifient 5 réglages courants en moins d'une minute chacun ») ; il n'y a aujourd'hui **aucune** structure pour l'accueillir. Le groupe « Conformité » ne peut pas être dissous tant que les sous-groupes n'existent pas — ce que le commentaire de `Sidebar.tsx:60-63` reconnaît explicitement
- Reproduction  : déplier « Réglages »
- Correctif     : ajouter un niveau de sous-groupe repliable à `NavSection` (le composant `NavSectionBlock` est déjà un accordéon, c'est une récursion, pas une réécriture) et livrer les 8 sous-groupes vides puis remplis (≈ 2 j côté barre, le contenu suit l'étape 2)
- Statut        : ouvert

### [D23-009] Les deux routes retirées à l'étape 0 rendent un 404 sans barre latérale, sans redirection
- Sévérité      : S2 défaut
- Domaine       : navigation
- Référence     : main e8924b8
- Emplacement   : `frontend/src/app/routeTree.tsx:104-107` ; `frontend/src/features/misc/NotFoundPage.tsx`
- Constat       : `/crm` et `/analytics` ont été retirés du routeur (`3feb733`, commentaire : « Ils tombent désormais sur `notFoundRoute` »). Aucune redirection n'a été écrite. `notFoundRoute` a pour parent `rootRoute`, pas `layoutRoute` : l'écran 404 se rend **hors du gabarit**, donc **sans barre latérale**, avec un seul lien.
- Preuve        : sonde sur 30 routes, `04_PREUVES/agent-23/fil-ariane-par-route.json` → `/crm` et `/analytics` : `barreLaterale: "ABSENTE"`, `h1: "(aucun h1)"`, `filDAriane: "(aucun fil)"`. Les 28 autres routes : `barreLaterale: "présente"`
- Témoin négatif: la même sonde voit la barre sur les 28 routes valides — elle sait donc la détecter quand elle est là
- Impact        : le CDC pose « ce qui disparaît ne se perd pas — aucun 404, aucun signet cassé ». Le bundle **encore servi en local et probablement en production** (D23-001) porte des entrées de menu vers ces deux routes : un utilisateur clique dans son menu et perd toute la navigation
- Reproduction  : ouvrir `https://app.localhost/crm`
- Correctif     : 301 `/crm → /contacts` et `/analytics → /pilotage` ; rattacher `notFoundRoute` à `layoutRoute` pour que la barre survive à un signet cassé (≈ 1 h)
- Statut        : ouvert

### [D23-010] La visite guidée n'affiche que 5 de ses 7 étapes et se marque « faite » quand même
- Sévérité      : S2 défaut
- Domaine       : navigation / UX
- Référence     : main e8924b8
- Emplacement   : `frontend/src/components/OnboardingTour.tsx:47,62` ; `frontend/src/components/layout/Sidebar.tsx:300` (`!deplie && 'hidden'`)
- Constat       : la barre étant un accordéon à une seule section ouverte, `[data-tour="nav-companies"]` et `[data-tour="nav-settings"]` ont, sur l'écran d'accueil, un `<ul>` parent en `display:none`. Joyride saute silencieusement l'étape 4 (le compteur passe de « Step 3 of 7 » à « Step 5 of 7 ») et s'arrête après l'étape 6 sans jamais afficher la 7e — celle qui recommande la double authentification et porte le mot de la fin. `POST /auth/onboarding/complete` part malgré tout.
- Preuve        : sonde jouée dans un navigateur, `04_PREUVES/agent-23/visite-guidee-code-actuel.json` + captures `visite-etape-0..4.png` ; `cible-v2.json` → `nav-companies` et `nav-settings` : `visible: false`, `rect {w:0,h:0}`, `parentUlHidden: true`
- Témoin négatif: la même sonde relancée avec les sections forcées dépliées (`display:block` injecté en CSS, aucun fichier produit modifié) affiche **7 étapes sur 7**, avec les textes des étapes 4 et 7 — le contrôle était donc capable de les voir
- Impact        : aucun nouvel utilisateur ne verra jamais l'invitation à activer la double authentification, ni l'étape « Entreprises » ; la visite se marque terminée, donc ne rejouera pas. Elle décrit par ailleurs un groupe « Conformité » que la cible dissout et emploie « scrapers », « dashboard », « KPIs » — trois mots que l'étape 0 a proscrits
- Reproduction  : compte dont `onboarding_tour_completed_at` est `null`, ouvrir `/`, cliquer « Next » jusqu'au bout
- Correctif     : **ne pas la réparer deux fois** (le §A.1 n°15 exige « refaite **une fois** sur la barre cible »). Aujourd'hui : retirer les 2 étapes qui ne s'affichent pas, pour que la visite cesse d'annoncer 7 quand elle en montre 5 (≈ 1 h). À la livraison de la barre cible : réécriture complète en 8 étapes, `data-tour` posés en même temps que la barre (≈ 1 j)
- Statut        : ouvert

### [D23-011] Le lien permanent CRM → console axionia n'existe pas, et celui de l'autre sens s'appelle « Prospection »
- Sévérité      : S2 défaut
- Domaine       : navigation / canal
- Référence     : main e8924b8 (CRM) ; `Axion-IA/axionia` (console)
- Emplacement   : `frontend/src/components/layout/Sidebar.tsx` (pied de barre) ; `Axion-IA/axionia/src/components/admin/ui/AdminSidebarNav.tsx:773`
- Constat       : le §22.6 exige « deux liens permanents (« Console axionia » dans le CRM, « CRM » en tête de la console) ». Côté CRM : `grep -ri "axionia\|axion-ia.com"` sur tout `frontend/src` rend **0 occurrence** ; le pied de barre ne contient que le bouton « Réduire ». Côté console : le lien existe mais s'intitule **« Prospection »**, avec pour `title` « Ouvrir Axion CRM Pro (outil de prospection) » — or « Prospection » est, dans la cible, **une entrée du groupe CONTACTS du CRM** (masse froide, §11.1).
- Preuve        : dump `cible-v2.json` → `pied: ["Réduire"]` ; `grep` à 0 occurrence ; lecture de `AdminSidebarNav.tsx:770-793`
- Témoin négatif: le même motif de `grep`, joué sur `axionia/src/components/admin`, trouve bien `AdminSidebarNav.tsx:773 href="https://app.axion-crm-pro.com"` — il sait donc détecter un lien inter-consoles quand il existe. (Le `grep` sur tout `axionia/src` dépasse le délai d'exécution de ce poste ; je n'affirme donc pas un décompte total.)
- Impact        : le critère 23 du §29 (« une personne extérieure ouvre le bon outil dix fois sur dix ») ne peut pas être tenu : depuis le CRM, il n'y a **aucun** chemin vers la console, ni pour un devis, ni pour une facture, ni pour le contenu du site. Et le seul lien existant réduit le CRM à « un outil de prospection », ce que le CDC lui refuse explicitement (le CRM est l'écran d'ouverture de la journée)
- Reproduction  : ouvrir le CRM, chercher un chemin vers axionia ; ouvrir la console, lire la première entrée de sa barre
- Correctif     : ajouter « ↗ Console axionia » au pied de barre du CRM (≈ 1 h) ; renommer « Prospection » → « ↗ CRM » côté console (≈ 15 min)
- Statut        : ouvert

### [D23-012] Trois notions portent le même mot dans les deux consoles — « Boîte de réception », « Clients », « Couverture »
- Sévérité      : S2 défaut
- Domaine       : navigation / UX
- Référence     : main e8924b8 (CRM) ; `Axion-IA/axionia` (console)
- Emplacement   : `axionia/src/lib/admin-nav.ts` (`ADMIN_NAV_GROUP_LABELS`, groupes `contacts`, `content_gen`) ; `frontend/src/features/crm-console/types.ts:29` ; `frontend/src/components/layout/Sidebar.tsx:88`
- Constat       : (a) **« Boîte de réception »** est le libellé du groupe des 12 écrans d'entrants de la console axionia **et** le libellé de l'entrée cible du CRM (§23.3, AUJOURD'HUI) ; (b) **« Clients »** désigne trois objets : `RELATION_TYPE_LABELS.client` du CRM, `/contacts/clients` (catégorie de soumissions du site) et `/qualiopi/clients` intitulé « Clients (CRM) » — ce dernier n'étant **pas** le CRM ; (c) **« Couverture »** désigne les départements collectés côté CRM et la couverture éditoriale des villes côté console (4 entrées « Couverture — … »).
- Preuve        : extraction des libellés des deux barres, `04_PREUVES/agent-23/cible-v2.json` et `admin-nav.ts` (relu ligne à ligne, groupes et labels extraits)
- Témoin négatif: le même croisement montre des notions **sans** collision (« Vivier candidats », « Registre AI Act », « Journaux d'audit ») — la méthode ne rend donc pas un faux positif sur tout
- Impact        : le §23.2 pose « vocabulaire cohérent — **le même mot désigne la même chose dans les deux consoles** » et le critère 24 « aucun libellé n'a de synonyme ailleurs dans le produit ». Ces trois collisions frappent précisément les endroits où l'utilisateur passe d'un outil à l'autre — c'est-à-dire le moment où il peut se perdre. Elles doivent être tranchées **avant** le palier P2 du retrait §25.1, sinon le retrait installe la confusion au lieu de la lever
- Reproduction  : ouvrir les deux barres côte à côte
- Correctif     : « Boîte de réception » est réservée au CRM, le groupe axionia devient « Entrants du site » ; `/qualiopi/clients` « Clients (CRM) » devient « Clients facturés » ; « Couverture » côté console devient « Couverture éditoriale » (≈ 3 h, plus la propagation dans la palette ⌘K de la console)
- Statut        : ouvert

### [D23-013] Le retrait par paliers des écrans de relation de la console n'a franchi aucun palier, et rien ne le prépare
- Sévérité      : S3 finition (S2 dès que l'étape 1c s'ouvre)
- Domaine       : navigation / conformité au plan
- Référence     : `Axion-IA/axionia`, relu le 2026-08-19
- Emplacement   : `axionia/src/app/[locale]/(admin)/[adminPrefix]/contacts/**` ; `axionia/src/env.ts:73-84`
- Constat       : les 12 écrans du groupe « Boîte de réception » sont intacts ; aucun bandeau « Cette vue existe dans le CRM → ouvrir » (`grep` : 0 occurrence) ; aucun drapeau d'affichage — les seuls drapeaux CRM (`CRM_SYNC_ENABLED`, `CRM_SYNC_CANDIDATES_ENABLED`) pilotent l'**émission d'événements**, pas la navigation ; la parité du critère 18 n'est pas prouvée et ne peut pas l'être, le CRM n'ayant aucun écran de boîte de réception.
- Preuve        : listage des 12 entrées du groupe `contacts` d'`admin-nav.ts` ; listage des 13 dossiers de routes ; `grep -rl "redirect(" contacts/` → 7 fichiers, tous appartenant aux routes **déjà** retirées (`calendly`, `rendez-vous`, plus des redirections de filtre)
- Témoin négatif: `/contacts/calendly` et `/contacts/rendez-vous` **sont** déjà retirées derrière un `redirect()` (refonte du 2026-07-29) — le contrôle sait donc reconnaître un écran retiré quand il l'est, et le mécanisme du palier P3 existe déjà dans ce dépôt
- Impact        : le critère 23 du §29 (« aucun écran de relation n'est atteignable dans la console autrement que par redirection vers le CRM ») est à 0 sur 12. Ce n'est pas grave aujourd'hui — l'étape 1c n'est pas ouverte — mais le palier 1 (parité prouvée) est un **préalable de plusieurs semaines de mesure** : s'il n'est pas armé maintenant, il retardera l'étape 1c d'autant
- Reproduction  : ouvrir la console, groupe « Boîte de réception »
- Correctif     : armer **dès maintenant** le comptage de parité du critère 18 (réservations, soumissions, candidatures, podcast : vus par la console vs reçus par le CRM, par semaine) — c'est la seule pièce qui ne peut pas être rattrapée en fin de chantier (≈ 1 j). Les paliers P2 à P4 sont décrits au §11.3 de ce chapitre
- Statut        : ouvert

---

## 13. Ce que je n'ai PAS pu vérifier, et pourquoi

1. **La barre servie en production** (`app.axion-crm-pro.com`). Le dossier interdit toute écriture
   en production ; je n'y ai pas ouvert de session authentifiée, donc je ne peux pas affirmer
   que le bundle de production porte la même barre périmée que le local (D23-001). **À vérifier
   d'urgence** : si c'est le cas, les utilisateurs voient encore 4 entrées de menu vers des
   routes 404.
2. **Le critère 24 lui-même** (« trois personnes extérieures, dix intentions, dix bons clics en
   moins de cinq secondes »). Il exige trois êtres humains. J'ai instruit les vingt intentions
   par la structure (§3) ; le protocole reste à jouer.
3. **Le comportement de la barre sur téléphone** (« barre basse Aujourd'hui · Contacts ·
   Échanges · Rechercher · Plus », §23.3). Non mesuré : je n'ai pas rejoué la sonde en viewport
   mobile. Le code ne contient aucune barre basse — mais je ne l'affirme pas sans l'avoir vue.
4. **La barre repliée en icônes** (« mêmes positions », §23.3). Le code la prévoit
   (`collapsed`) et affiche alors **toutes** les sections dépliées — donc les positions ne sont
   pas les mêmes qu'en mode déployé. Je n'ai pas mesuré l'écart de position réel.
5. **La teinte de la barre par espace de travail** (business / vivier — §23.3 : « impossible de
   se tromper d'espace sans le voir »). `WorkspaceSelector` existe ; je n'ai pas basculé
   d'espace ni comparé les couleurs.
6. **Le titre de `/admin/observability` et de `/console/personnes/$personKey`** : ma sonde n'a
   trouvé aucun `h1` sur ces deux routes, mais l'API répondait en erreur (origine `127.0.0.1:5199`
   non autorisée par CORS). L'absence de titre est probablement un état de chargement, pas un
   défaut. **Non conclu.**
7. **Le contenu réel des 12 écrans de la console axionia** : je les ai inventoriés par le code
   (`admin-nav.ts`, arborescence des routes), pas ouverts dans un navigateur. Le décompte des
   entrées et des routes est sûr ; leur contenu ne l'est pas.
8. **L'inventaire d'intentions de l'agent 22** (`11_GRILLES/intentions.md`) : absent du
   répertoire au moment de la rédaction. La grille du §3 est la mienne. **Si l'agent 22 dépose
   la sienne, les deux doivent être croisées** — un écart entre elles est lui-même un constat.
