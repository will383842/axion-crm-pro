# PROMPT — RENDRE LES CONSOLES D'ADMINISTRATION ÉVIDENTES : AUDIT ET REFONTE COMPLÈTE DE L'ORGANISATION, DE LA NAVIGATION, DES ONGLETS, DES PAGES ET DE L'EXPÉRIENCE — EN AUTOPILOTE, PAR 50 AGENTS HIÉRARCHISÉS

> **Version 2.0 — 2026-08-18.** À coller dans une session Claude Code neuve ouverte sur `C:\Users\willi\Documents\Projets\Axion-CRM-Pro`, avec `/add-dir C:\Users\willi\Documents\Projets\Axion-IA`.
>
> **Ce prompt se joue dans le navigateur, pas dans l'éditeur.** On ne juge pas une interface en lisant du JSX : on l'ouvre, on clique, on se perd, on chronomètre, on corrige, on rouvre, on remesure.
>
> **Objectif de sortie, en une phrase :** *quelqu'un qui n'a jamais vu ces consoles comprend chaque page en cinq secondes, trouve ce qu'il cherche du premier coup, enchaîne ses tâches sans friction, et ne se retrouve jamais coincé.* Tant que ce n'est pas vrai **et mesuré, chiffre avant et chiffre après**, le travail n'est pas fini.
>
> **Les listes du §5 sont des listes de travail, pas des exemples.** Une entrée listée qui n'apparaît pas dans le rapport final est un manquement de l'audit.

---

## SOMMAIRE

1. Mandat, autorisation, règle d'arrêt
2. Le critère unique, rendu mesurable — les 15 seuils
3. Doctrine — les douze règles de cet audit
4. Accès réel : identifiants, environnements, règles du navigateur
5. **Le périmètre nominatif — les deux consoles, entrée par entrée, onglet par onglet**
6. **Les collisions déjà visibles** — ce que l'inventaire révèle avant même d'ouvrir
7. **Les grilles obligatoires** (10 grilles)
8. **Les circuits** — les parcours de bout en bout, à travers les deux consoles
9. **La méthode d'optimisation** — ce qu'il faut produire, pas seulement constater
10. Les 50 agents
11. Déroulé — dix phases, trois vérifications
12. Livrables et modèles de fiche
13. Sécurité et non-régression
14. Pièges connus
15. Définition de fini

---

## 1. MANDAT, AUTORISATION, ET RÈGLE D'ARRÊT

Tu es le **chef de chantier** d'une organisation de **50 agents d'IA spécialisés** en expérience d'utilisation, architecture de l'information, rédaction d'interface et interface graphique. Tu conduis un **audit intégral joué dans un vrai navigateur** de l'organisation, de la navigation, des onglets, des pages et de la lisibilité des **deux consoles d'administration** — puis tu **refais** ce qui doit l'être, tu **vérifies**, tu **contre-vérifies de façon adversariale**, et tu **re-vérifies une troisième fois d'un œil neuf**.

**Autorisation explicite du dirigeant (Will), donnée le 2026-08-18 :**

- **Autopilote intégral.** Tu **ne poses aucune question**, tu ne t'arrêtes pas pour un arbitrage.
- **Tu décides toi-même**, selon tes propres recommandations, et tu consignes chaque décision avec sa raison.
- Tu peux **naviguer, mesurer, renommer, regrouper, réordonner, fusionner, scinder, masquer, rediriger, redessiner des pages et des onglets, réécrire les libellés et les messages, écrire des tests, ouvrir des branches et des PR, fusionner**.
- **Tu ne t'arrêtes pas tant que les 15 seuils du §2 ne sont pas atteints et prouvés.**

**Les six choses que tu ne fais jamais** — tu les inscris dans `06_RESTE-WILL.md` avec ta recommandation, et tu continues tout le reste :

1. Supprimer ou modifier des **données de production** (aucun clic destructeur sur une vraie fiche, un vrai devis, une vraie session, un vrai contact).
2. **Envoyer un e-mail, un SMS ou un WhatsApp réel** à qui que ce soit.
3. **Allumer un drapeau de production** qui change ce que voit un client ou un prospect.
4. **Supprimer une fonctionnalité.** Tu peux la **ranger, renommer, fusionner, masquer derrière un drapeau réversible, rediriger** — jamais la faire disparaître.
5. Engager une dépense.
6. Toucher aux secrets, ou écrire un mot de passe dans un fichier versionné.

---

## 2. LE CRITÈRE UNIQUE, RENDU MESURABLE — LES 15 SEUILS

« Bien organisé », « fluide », « intuitif » ne sont pas des verdicts : ce sont des mesures. Chacun de ces seuils se mesure **avant** et **après**, et les deux chiffres figurent au rapport final.

| # | Seuil | Protocole |
|---|---|---|
| 1 | **Test des 5 secondes** : ≥ 90 % des pages comprises | Un agent naïf (§10, bloc V) reçoit **une capture seule**, 5 s, puis répond : « à quoi sert cette page ? quelle est l'action principale ? ». Compté page par page |
| 2 | **10 intentions → 10/10, < 5 s chacune** | Dix intentions en langage courant, données à trois naïfs sans carte de navigation : ils désignent l'entrée de menu (CDC §29-24) |
| 3 | **5 réglages trouvés et modifiés en < 1 min chacun** | Trois naïfs, cinq réglages courants (CDC §29-20) |
| 4 | **≤ 3 clics** vers toute action courante | Comptage sur les ~50 intentions du §9.1 (CDC §23.2) |
| 5 | **≤ 2 niveaux** de profondeur, **≤ 7 entrées** par groupe | Lecture de l'arbre cible ; aujourd'hui deux groupes en portent **33 et 34** |
| 6 | **0 cul-de-sac** | Aucune page sans sortie évidente, aucun état vide muet, aucune erreur sans action possible |
| 7 | **0 entrée qui ment** | Aucun cadenas, aucune route 501, aucun lien mort, aucun libellé dont le contenu diffère de la promesse, aucune URL qui contredit son groupe |
| 8 | **0 synonyme, 0 homonyme** | Le glossaire du §9.4, appliqué aux **deux** consoles. Départ mesuré : **4 « Tableau de bord », 3 « Vue d'ensemble », 3 mots pour « réglages », 3 « journaux », 3 « clients », 3 « pipeline »** (§6) |
| 9 | **Chaque compteur appelle une action** | Liste des compteurs : conservés (actionnables) / supprimés (décoratifs) |
| 10 | **0 perte d'état** | Retour arrière, rechargement, filtre, tri, pagination, onglet interne : l'écran revient comme on l'a laissé — testé sur chaque liste et chaque écran à onglets |
| 11 | **Fluidité perçue** : première image utile < 1 s sur les écrans courants, aucun écran blanc, aucun saut de mise en page | Mesuré au navigateur, réseau bridé compris |
| 12 | **0 régression d'accès** | Toute ancienne URL redirige ; aucune fonctionnalité rendue introuvable ; les entrants (formulaires, réservations, alertes) continuent d'arriver |
| 13 | **0 page orpheline, 0 entrée fantôme** | Croisement route ↔ navigation : aucune page atteignable sans entrée, aucune entrée sans page |
| 14 | **Cohérence inter-écrans** : la même action au même endroit, sous le même nom, avec la même icône | Matrice de cohérence §7.8 |
| 15 | **Les circuits tiennent** : chaque parcours de bout en bout du §8 se joue sans ressaisie ni aller-retour inutile | Chronométré, clics comptés, GIF archivé |

---

## 3. DOCTRINE — LES DOUZE RÈGLES DE CET AUDIT

1. **On ouvre, on clique, on se perd.** Aucun constat d'interface n'est recevable s'il vient de la lecture du code. Le code sert à **expliquer** ce qu'on a vu, jamais à le remplacer.
2. **Capture avant, capture après.** Chaque page, chaque état, chaque section de menu, chaque onglet : archivé en image, nommé selon la convention du §12.
3. **On chronomètre et on compte les clics.** « Plus fluide » est interdit sans nombre.
4. **La naïveté se simule sérieusement.** Les agents naïfs (bloc V) n'ont **ni code, ni carte de navigation, ni rapports** : une capture et une intention. Leur verdict est une donnée. Il est déclaré comme simulation, jamais présenté comme un test utilisateur réel.
5. **Un défaut d'organisation est un défaut produit.** Une fonctionnalité qu'on ne trouve pas n'existe pas.
6. **On ne supprime pas, on range.** Tout retrait passe par un drapeau réversible et une redirection. Personne ne perd un signet.
7. **Le vocabulaire est une fonctionnalité.** Un mot par notion, le même dans les deux consoles, en français courant, sans jargon ni anglicisme.
8. **L'ordre suit la fréquence d'usage**, du quotidien au rare — pas l'ordre historique des sprints.
9. **Un masquage n'est pas un rangement.** Une entrée retirée de la barre reste atteignable par URL, palette de commandes, fil d'ariane, signet, lien d'e-mail. **Tous** les chemins d'entrée se vérifient.
10. **Toute garde ajoutée est vue rougir** : un test de navigation, de libellé ou de redirection jamais vu rouge ne garde rien.
11. **On n'invente pas ce qui est déjà décidé.** La cible du CRM est écrite (CDC §23.3, §19) ; la refonte de `content_gen` en 6 pôles par la tâche (2026-06-16) et le renommage `qualiopi` → « Formations & prestations » (2026-08-01) sont des **patrons à généraliser**, pas à refaire.
12. **Rien n'est fini sans mesure avant/après archivée.**

---

## 4. ACCÈS RÉEL : IDENTIFIANTS, ENVIRONNEMENTS, RÈGLES DU NAVIGATEUR

### 4.1 Identifiants

Les identifiants de la console CRM de production sont dans un fichier **hors dépôt** :

```
C:\Users\willi\.axion-audit-credentials.local
```

Il contient `CRM_PROD_URL`, `CRM_PROD_EMAIL`, `CRM_PROD_PASSWORD`. **Lis-le, ne le recopie jamais** — ni dans un fichier du dépôt, ni dans un rapport, ni dans une capture d'écran (masque le champ mot de passe et la barre d'adresse si elle porte un jeton). Si le fichier est absent, le premier constat est « accès non fourni » et l'audit se poursuit **en local**.

### 4.2 Les environnements, et l'ordre dans lequel on les utilise

| Console | Environnement | Comment |
|---|---|---|
| **CRM Pro** | **Local, en premier** | `docker-compose.local.yml` (API sur `58080`) + `pnpm dev` dans `frontend/`. ⚠️ Le défaut **D-11** (console non exécutable en local d'une seule origine) est corrigé sur `chore/etape-0-prealables` / PR #174 — travaille sur cette référence si `main` ne démarre pas, **et dis-le** |
| **CRM Pro** | **Production, en lecture seule** | `https://app.axion-crm-pro.com/login` — pour ce que le local ne peut pas montrer (volumes réels, données réelles, vitesse réelle) |
| **Console axionia** | **Local** | `pnpm dev` dans `axionia/`, puis `/fr/admin-dev-x7k2n9/…` (fallback de `adminSegment()`, `src/lib/admin-path.ts`) |
| **Console axionia** | **Production, en lecture seule** | via le préfixe réel |

**Si une console refuse de démarrer en local, c'est le premier constat, de sévérité S0** — et le contournement est la production **en lecture seule**, jamais l'abandon.

**En production : consultation, navigation, mesure. Aucune création, modification, suppression, aucun envoi.** Toute action d'écriture se joue en local, sur un jeu de démonstration.

### 4.3 Les comptes de rôle

Une console ne se juge pas depuis le seul compte tout-puissant. Créer **en local** un compte par rôle (administrateur, utilisateur complet, restreint à ses fiches, lecture seule, limité à un espace) et **rejouer les parcours principaux avec chacun**. Ce que voit un compte restreint — entrées grisées, messages secs, pages vides sans explication — est un axe de constat à part entière.

### 4.4 Règles du navigateur (Claude in Chrome)

- **Toujours** `tabs_context_mcp` en premier, puis `tabs_create_mcp` pour un onglet neuf. Jamais d'identifiant d'onglet hérité d'une autre session.
- Charger les outils en **un seul** appel : `tabs_context_mcp, tabs_create_mcp, navigate, computer, read_page, get_page_text, find, form_input, javascript_tool, read_console_messages, read_network_requests, resize_window, gif_creator`.
- **Ne jamais déclencher d'alerte, de confirmation ou de dialogue modal natif** : cela gèle l'extension et la session est perdue. Avant tout clic sur « Supprimer », vérifier ce qu'il déclenche.
- ⚠️ **`form_input` n'écrit pas l'état React** : pour tout champ contrôlé, saisir au clavier avec `computer`, sinon le formulaire paraît rempli et part vide.
- `read_console_messages` après chaque parcours : **une erreur console est un constat**, même si l'écran a l'air correct.
- `read_network_requests` sur les écrans lents : combien d'appels, lesquels sont redondants.
- `resize_window` aux **quatre points de rupture** : 375, 768, 1280, 1920.
- `gif_creator` pour chaque circuit du §8 : c'est la preuve la plus lisible d'un avant/après.

### 4.5 Comment on mesure (protocole, à appliquer à l'identique avant et après)

- **Clics** : comptés depuis l'écran de départ nommé, en incluant les ouvertures de menu et les défilements nécessaires pour atteindre la cible. Les frappes clavier ne comptent pas comme clics mais sont notées.
- **Temps** : de la fin du clic à la **première image utile** (contenu réel, pas squelette). Trois mesures, on garde la médiane.
- **Réseau bridé** : une passe en conditions dégradées (type 3G rapide, processeur ralenti ×4) sur les dix écrans les plus consultés.
- **Test des 5 secondes** : capture montrée 5 s, retirée, puis deux questions. Réponse notée mot pour mot.
- **Tri par carte inversé** : on montre le libellé seul, sans la page, et on demande ce qu'on croit y trouver.

---

## 5. LE PÉRIMÈTRE NOMINATIF — LES DEUX CONSOLES, ENTRÉE PAR ENTRÉE

**Règle d'exhaustivité.** Ces listes datent du 2026-08-18. Le gardien du contexte les **recompte dans le code** (`Sidebar.tsx` et `routeTree.tsx` côté CRM ; `buildAdminNav()` de `admin-nav.ts` côté axionia — y compris les entrées **construites dynamiquement**), publie l'écart, puis transforme chaque liste en **tableau de suivi** : une ligne par entrée et par page, colonnes `ouverte le · verdict · captures · frictions · décision · appliquée le · testée`. **Aucune ligne ne reste vide.**

### 5.1 Console CRM Pro — 10 sections

| Section actuelle | Entrées |
|---|---|
| **Console CRM** *(runtime, drapeau `EnsureCrmConsoleV2` / `ConsoleGate`)* | Contacts (`/console/contacts`) · À arbitrer · Vivier candidats *(conditionnel)* |
| **Pilotage** | Tableau de bord · Couverture France · **Campagnes** *(= collecte)* · **Runs de scraping** |
| **Data** *(nom technique)* | Entreprises · **Contacts** (`/contacts`) — **2ᵉ entrée « Contacts »** · Tags |
| **International** | Roumanie |
| **Médias & Presse** | Médias · Journalistes |
| **Communication** | Audiences · 🔒 Templates email · 🔒 Envois email |
| **IA** | LLM Router · Proxies · Rotations |
| **Conformité** | Requêtes RGPD · Registre AI Act · Journaux d'audit |
| **Admin** | Utilisateurs · Paramètres |
| **Phase 2** | 🔒 E-mails à froid · 🔒 Prospection LinkedIn · 🔒 Pipeline CRM · 🔒 Analytique |

### 5.2 Console CRM Pro — les 39 pages à ouvrir, une par une

`/login` · `/2fa` · `/magic-link` · `/password-reset` · `/` · `/coverage` · `/campaigns` · `/campaigns/new` *(assistant complet, étape par étape)* · `/campaigns/{id}` · `/scraper-runs` · `/companies` · `/companies/{id}` · `/contacts` · `/tags` · `/international/roumanie` · `/media` · `/media/{id}` · `/journalists` · `/audiences` · `/audiences/new` *(constructeur)* · `/audiences/{id}` · `/llm/router` · `/llm/proxy-providers` · `/llm/rotations` · `/rgpd/requests` · `/rgpd/ai-act` · `/audit-logs` · `/users` · `/settings` · `/admin/observability` · `/console/contacts` · `/console/vivier` · `/console/arbitrage` · **`/persons/{key}` — la fiche 360°, page la plus consultée** · `/cold-email` · `/linkedin` · `/crm` · `/analytics` *(4 pages factices)* · une URL inexistante *(la page 404)*.

### 5.3 Console CRM Pro — les 13 écrans à onglets internes

**Les onglets internes sont une navigation, et se jugent comme telle** (grille §7.2). Écrans concernés : `ContactsHubPage` · `CandidatesPage` · `CoveragePage` · `CampaignsListPage` · `CampaignWizardPage` · `CampaignDetailPage` · `AudienceDetailPage` · `RgpdRequestsPage` · `LlmRouterPage` · `DashboardPage` · `ScraperRunsPage` · `RoumaniePage` · **`SettingsPage`**.
Pour chacun : lister les onglets, leur ordre, leur libellé, ce qu'ils contiennent, **si l'onglet actif survit à un rechargement et à un retour arrière**, s'il est dans l'URL, et si le contenu justifie un onglet plutôt qu'une page ou une section.

### 5.4 La cible du CRM est déjà écrite

CDC §23.3 : **`AUJOURD'HUI` · `CONTACTS` · `ÉCHANGES` · `PILOTAGE` · `RÉGLAGES`**, plus `↗ Console axionia`, `Rechercher (⌘K)`, `Fiches récentes`. Sur téléphone, barre basse « Aujourd'hui · Contacts · Échanges · Rechercher · Plus ». L'espace de travail (business / vivier) **change la teinte de toute la barre**. Un groupe « Collecte » est toléré en plus tant que la collecte est un chantier vivant. La console de réglages suit le §19 : **8 sous-groupes** (Personnes et types · Entretiens · Rendez-vous et rappels · Messages et modèles · Équipe et sécurité · Données et conformité · Apparence · Intégrations).

### 5.5 Console axionia — les 125 entrées, par groupe

*(source : `buildAdminNav()`, `admin-nav.ts`, 1 534 lignes ; `Ⓜ` = masquée de la barre par le champ `parent`, mais toujours atteignable ; les entrées dynamiques sont à énumérer à l'exécution)*

**`main` — « Activité quotidienne » (7)** : Tableau de bord `/` · Hub de pilotage `/planning/hub` · Planning `/planning` · Timeline ressources `/planning/timeline` · Charge formateurs `/planning/charge` · Pipeline commercial `/planning/pipeline` · Prévisionnel `/planning/previsionnel`
 ⚠️ **+ 7 entrées mortes masquées** (ancien flux de réservation payante) : Calendrier · Réservations · Options 48 h · Devis · Factures · Paiements · Échéanciers.

**`contacts` — « Boîte de réception » (12)** : Tout `/contacts` · Appels réservés `/contacts/appels` · Messages `/contacts/messages` · Clients `/contacts/clients` · Presse `/contacts/presse` · Partenariats `/contacts/partenariats` · Investisseurs `/contacts/investisseurs` · Conférences `/contacts/conferences` · **Recrutement `/contacts/commercial`** · Podcast `/podcast` · Autres `/contacts/autres` · Candidatures `/contacts/candidatures`
 ⚠️ *C'est un CRM entier en doublon d'Axion CRM Pro. Le CDC (§A, §25.1) prescrit son retrait par paliers réversibles au profit d'une seule entrée « Contacts → ouvrir le CRM », chaque écran devenant une redirection. `Booking` / `planning` restent : c'est de l'exécution, pas de la relation.*

**`tunnels` (3)** : Vue d'ensemble `/tunnels` · Tunnel de prospects · Tunnel de vente.

**`content` — « Contenu » (8)** : Connaissances · Blog · Catégories · Cas concrets · Avis clients · Offres d'emploi · FAQ · Centre d'aide.

**`content_gen` — « Génération de contenu » (34, 6 pôles : Lancer · Suivre · Publier · Villes · Qualité & Coûts · Réglages)** : Nouvelle campagne · Campagnes pré-réglées · Générer une seule page · Actualités (news RSS) · Premiers pas Ⓜ · Tableau de bord · Campagnes · Générations en cours · Observatoire IA 2026 · À valider · Contenus publiés · Suivi des publications (kanban) · Photos hero Unsplash · Backfill citations (Sources) · Couverture des villes · File de génération (prochaine vague) · Matière première villes (39 pilotes) · Couverture — par palier de population Ⓜ · Couverture — par type de contenu Ⓜ · Couverture — production par région Ⓜ · Couverture — croisé ville × secteur Ⓜ · Qualité du contenu · Coûts · Détection de doublons Ⓜ · Dérive du ton éditorial · Suivi des vecteurs de similarité · Liens externes · Réglages génération · Sources RSS (actualités) · Instructions IA (prompts) · Suivi des positions Ⓜ · Variantes de landing · Profil de l'auteur (Manon).

**`qualiopi` — « Formations & prestations » (33)** : À traiter · Dossiers (pipeline) · Nouvelle vente · Formations · Formation Engine · Validations IA · Sessions · Formateurs · Accès & connexions formateurs `/coaching/formateurs` · Rémunération formateurs · Audits IA · Stagiaires · Offres · Entrées récentes · **Clients (CRM)** · **Devis** · Barèmes OPCO · Indicateurs / BPF · Pilotage · Appréciations · Réclamations · Conformité & mode auditeur · Veille · Partenariats · Sous-traitants · Moyens pédagogiques · Incidents · Revue de direction · Configuration · Demandes RGPD · Alertes · E-mails à valider.

**`finances` (6)** : **Cockpit financier `/qualiopi/cockpit-financier`** · **Facturation (Hub) `/qualiopi/facturation`** · Facture directe · Plans récurrents · Rapprochement bancaire · Alertes financement (sessions)
 ⚠️ *URL sous `/qualiopi/`, groupe « Finances » : l'URL et la barre racontent deux histoires différentes.*

**`documents-interventions` — « Documents » (8)** : Formations · 1-to-1 · Audit · Implémentations · Sites web · Autres · Annuaire équipe · Importer un kit.

**`coaching-1to1` (2)** : Tableau de bord `/coaching` · Séances 1-to-1.

**`image-bank` — « Banque d'images » (10)** : Vue d'ensemble · Bibliothèque · Téléverser · Import CSV en masse Ⓜ · Catégories Ⓜ · **Étiquettes** Ⓜ · File de qualité · Statistiques Ⓜ · Journaux d'utilisation (RGPD) · Réglages Ⓜ.

**`presse` — « Salle de presse » (4)** : Vue d'ensemble · Communiqués · Kit média · Couverture médias.

**`chatbot` (5)** : Tableau de bord · Escalades · Conversations · Prompt versionné · Réglages.

**`engagement` (1)** : Newsletter ⚠️ *un groupe entier pour une seule entrée.*

**`ops` — « Ops & monitoring » (12 + dynamiques)** : Statistiques & SEO `/analytics` · Web Vitals · E-mails envoyés · Toutes les URLs `/site-explorer` · Infra & outils · Sauvegardes & DR · Alertes ops · **Synchro CRM** · QR codes & liens **+ une entrée par catégorie de QR (dynamique)** · Imprimés **+ une entrée par imprimé (dynamique)**.

**`system` — « Système » (4)** : Utilisateurs · Journaux d'activité · Paramètres · 2FA — sécurité.

### 5.6 Croisement obligatoire : routes ↔ navigation

Le dossier `axionia/src/app/[locale]/(admin)/[adminPrefix]/` contient une cinquantaine de dossiers de routes — dont plusieurs qui **n'apparaissent dans aucune entrée de barre** (`submissions`, `documents`, `reservations`, `devis`, `factures`, `paiements`, `echeanciers`, `options`, `calendrier`, `catalogue-imprime`, `candidatures`, `_v2`…). **Produire les deux listes et leur différence :**
- **pages orphelines** : atteignables par URL, absentes de la navigation → décision (rattacher / rediriger / masquer proprement) ;
- **entrées fantômes** : présentes dans la barre, sans page réelle ou vers une redirection → décision.
Le même croisement est fait côté CRM entre `Sidebar.tsx` et `routeTree.tsx`.

---

## 6. LES COLLISIONS DÉJÀ VISIBLES

Relevées **dans le code**, avant même d'ouvrir le navigateur. Elles sont le point de départ du glossaire (§9.4), **à vérifier à l'écran, pas à recopier** :

| Collision | Occurrences |
|---|---|
| **« Tableau de bord » ×4** | `main` · `content_gen` · `coaching-1to1` · `chatbot` — plus « Tableau de bord » côté CRM Pro |
| **« Vue d'ensemble » ×3** | `tunnels` · `image-bank` · `presse` |
| **Trois mots pour la même notion** | « Réglages » (chatbot, image-bank) · « Réglages génération » (content_gen) · « Configuration » (qualiopi) · « Paramètres » (system, et CRM Pro) |
| **« Journaux » ×3** | « Journaux d'activité » (system) · « Journaux d'utilisation (RGPD) » (image-bank) · « Journaux d'audit » (CRM Pro) |
| **« Clients » ×3** | `/contacts/clients` (axionia) · « Clients (CRM) » `/qualiopi/clients` · le hub contacts du CRM Pro |
| **« Pipeline » ×3** | « Pipeline commercial » (main) · « Dossiers (pipeline) » (qualiopi) · 🔒 « Pipeline CRM » (CRM Pro) |
| **« Campagnes » ×3** | `content_gen` (génération de contenu) · CRM Pro (collecte d'entreprises) · « Nouvelle campagne » |
| **« Alertes » ×3** | « Alertes » (qualiopi) · « Alertes ops » (ops) · « Alertes financement » (finances) |
| **« Formations » ×2** | `qualiopi` · `documents-interventions` |
| **« Autres » ×2** | `contacts` · `documents-interventions` |
| **« Catégories » ×2** | `content` · `image-bank` |
| **« Statistiques » ×2** | « Statistiques » (image-bank) · « Statistiques & SEO » (ops) |
| **« Partenariats » ×2** | `contacts` · `qualiopi` |
| **Presse ×3** | `/contacts/presse` · groupe « Salle de presse » · « Médias / Journalistes » (CRM Pro) |
| **« Étiquettes » vs « Tags »** | image-bank (axionia) vs CRM Pro — **même notion, deux mots, deux consoles** |
| **Libellé ≠ URL** | « Recrutement » → `/contacts/commercial` |
| **Groupe ≠ URL** | « Cockpit financier », « Facturation (Hub) », « Facture directe », « Plans récurrents », « Rapprochement bancaire », « Alertes financement » : groupe **Finances**, URL `/qualiopi/…` |
| **Entrées mortes masquées** | 7 dans `main`, 9 Ⓜ ailleurs — toujours atteignables par URL, palette, fil d'ariane, signet |
| **Groupes hors gabarit** | 33 et 34 entrées là où la règle en autorise 7 ; un groupe pour une seule entrée (`engagement`) |
| **Jargon en barre** | « Data », « Runs de scraping », « LLM Router », « Proxies », « Rotations », « Backfill citations », « Ops & monitoring », « Hub » |

---

## 7. LES GRILLES OBLIGATOIRES

Chaque agent remplit la grille de son objet, **point par point**, dans un tableau où **aucune case n'est vide**. « Non vérifié » est honnête ; une case absente ne l'est pas.

### 7.1 Grille ENTRÉE DE MENU — les ~135 entrées des deux consoles

1. Libellé exact · 2. **Ce qu'un naïf croit y trouver** (tri par carte inversé, avant d'ouvrir) · 3. Ce qu'on y trouve réellement · 4. **L'écart** entre 2 et 3 · 5. Groupe actuel — est-ce le bon ? · 6. Profondeur · 7. Fréquence d'usage estimée (quotidien / hebdo / mensuel / rare / jamais) · 8. Compteur : présent ? actionnable ou décoratif ? · 9. Verrouillée, morte, masquée, redondante ? · 10. Cohérence libellé ↔ URL ↔ groupe · 11. Jargon, anglicisme, synonyme, homonyme ? · 12. Icône distinctive ou interchangeable ? · 13. **Décision** : conservée / renommée / déplacée / fusionnée / transformée en vue épinglée / redirigée / masquée derrière un drapeau · 14. Redirection à écrire · 15. Test qui garde la décision.

### 7.2 Grille ONGLET INTERNE — les 13 écrans du §5.3 et tous ceux d'axionia

1. Liste des onglets, dans l'ordre affiché · 2. Le premier onglet est-il le plus utilisé ? · 3. Un onglet cache-t-il une information nécessaire à la tâche principale ? (le CDC l'interdit sur la fiche) · 4. L'onglet actif est-il **dans l'URL** ? · 5. Survit-il au rechargement ? au retour arrière ? · 6. Les filtres et le défilement de chaque onglet sont-ils conservés en revenant ? · 7. Un onglet vide dit-il pourquoi ? · 8. Compteurs par onglet : justes ? actionnables ? · 9. Les onglets sont-ils atteignables au clavier (flèches, `Home`/`End`) et correctement étiquetés (`role="tablist"`) ? · 10. **Décision** : onglets conservés / réordonnés / renommés / fusionnés / convertis en pages ou en filtres.

### 7.3 Grille PAGE — chaque page des deux consoles

**Compréhension immédiate** — 1. Test des 5 secondes : réussi / échoué, avec la réponse exacte · 2. Le titre dit-il la tâche, en français courant ? · 3. **Une seule action principale**, visuellement dominante ? · 4. Hiérarchie visuelle : l'œil va-t-il au bon endroit ? · 5. Densité : combien d'éléments interactifs ? · 6. Aide au point de doute, utile ?
**Orientation** — 7. Fil d'ariane juste · 8. Entrée de menu correspondante mise en évidence · 9. Sait-on d'où l'on vient et comment revenir ? · 10. **Et après ?** La suite logique est-elle proposée ?
**États** — 11. Chargement (squelette, pas d'écran blanc, pas de saut) · 12. **Vide, dessiné, avec la marche à suivre** · 13. Erreur (langage courant, cause, action) · 14. Permission refusée (explicite, sans cul-de-sac) · 15. Partiel / hors ligne.
**Manipulation** — 16. Chaque bouton et lien essayé, un par un · 17. Boutons désactivés : sait-on pourquoi ? · 18. Retour après action : immédiat, explicite, annulable si destructeur · 19. Confirmations nécessaires ou bureaucratiques · 20. Actions de masse : décompte exact, aperçu, réversibilité.
**Listes** — 21. Tri, filtres, recherche, pagination : fonctionnent, persistent, sont dans l'URL · 22. Colonnes : les bonnes, dans le bon ordre · 23. Comportement à 0 / 1 / 100 / 10 000 lignes.
**Forme** — 24. Composants du système de design réutilisés ou balisage recopié · 25. Mode sombre complet · 26. **375 / 768 / 1280 / 1920** · 27. **Clavier seul** : tout atteignable, focus visible, pas de piège · 28. Contrastes, libellés de formulaire, ARIA.
**Verdict** — 29. Ce qu'un utilisateur non formé ne comprendrait pas, nommé · 30. **Décision** : conservée / retitrée / réorganisée / fusionnée / scindée / redirigée.

### 7.4 Grille FORMULAIRE

1. Champs obligatoires réellement nécessaires ? · 2. Valeurs par défaut intelligentes ? · 3. Validation : au bon moment (pas à chaque frappe, pas seulement à l'envoi) · 4. Messages d'erreur au bon endroit, en français courant · 5. **Aucun refus silencieux** (piège avéré : un `select` vide rejeté sans message) · 6. Sauvegarde continue ou avertissement avant de quitter · 7. Rechargement forcé et coupure réseau pendant la saisie : reprise à l'identique ? · 8. Assistants multi-étapes : sait-on où l'on en est, peut-on revenir, l'état survit-il ? · 9. Saisie clavier de bout en bout · 10. Ressaisie d'une information déjà connue du système (friction `F3`).

### 7.5 Grille RECHERCHE ET PALETTE DE COMMANDES

Jeu d'essai imposé, joué à l'identique dans les deux consoles : **20 requêtes** — un nom exact · un nom mal orthographié · un nom sans accents · un prénom seul · deux mots dans le désordre · une adresse e-mail complète · un fragment d'e-mail · un numéro de téléphone avec espaces · sans espaces · un nom de société · un numéro de devis · un montant · un mot du contenu d'une note · un intitulé de réglage (« rappel », « couleur », « OPCO ») · un nom d'écran (« sauvegardes ») · une abréviation · un terme au pluriel · un terme en anglais · une chaîne vide · une chaîne qui ne doit rien rendre.
Pour chacune : résultat attendu, résultat obtenu, temps, groupement des résultats, action possible depuis le résultat. **Et : la palette de commandes trouve-t-elle les écrans masqués ?**

### 7.6 Grille NOTIFICATIONS, COMPTEURS ET ALERTES

1. Chaque badge : d'où vient le nombre, est-il juste, appelle-t-il une action ? · 2. Se remet-il à zéro quand on a agi ? · 3. Notifications internes : où arrivent-elles, que peut-on en faire, disparaissent-elles trop vite ? · 4. Alertes Telegram : que reçoit-on, est-ce lisible sur un écran ? · 5. Y a-t-il un endroit où l'on voit **tout ce qu'il y a à faire aujourd'hui** — et un seul ?

### 7.7 Grille FLUIDITÉ PERÇUE

1. Temps jusqu'à la première image utile · 2. Écran blanc ? · 3. Saut de mise en page ? · 4. Squelette ou spinner ? · 5. Réactivité à la frappe · 6. Latence avant retour visuel d'une action · 7. Transitions discrètes, cohérentes, réduction de mouvement respectée · 8. Comportement en réseau bridé · 9. Erreurs console pendant le parcours · 10. Nombre et redondance des requêtes réseau.

### 7.8 Matrice de COHÉRENCE INTER-ÉCRANS

Un tableau `action × écran` pour vérifier que la même chose se fait **partout pareil** : Créer · Rechercher · Filtrer · Trier · Exporter · Actions de masse · Modifier en place · Supprimer · Annuler · Revenir · Rafraîchir · Voir l'historique.
Pour chaque case : **le libellé, la position à l'écran, l'icône, le raccourci clavier**. Toute variation non justifiée est un constat.

### 7.9 Grille FRICTION — la taxonomie à utiliser partout

`F1` attente non signalée · `F2` ambiguïté de libellé · `F3` ressaisie d'une information déjà connue · `F4` aller-retour entre deux écrans · `F5` confirmation inutile · `F6` perte d'état · `F7` cul-de-sac · `F8` retour arrière cassé · `F9` message technique · `F10` choix sans valeur par défaut · `F11` action introuvable · `F12` action désactivée sans explication · `F13` information dispersée · `F14` doublon · `F15` promesse non tenue (cadenas, 501, page vide) · `F16` densité écrasante · `F17` navigation qui change de logique d'un écran à l'autre · `F18` vocabulaire incohérent · `F19` ressaisie **entre les deux consoles** · `F20` on ne sait pas si l'action a marché.
Chaque friction : type, écran, geste déclencheur, coût (secondes ou clics), correctif, statut.

### 7.10 Grille COMPRÉHENSION — le protocole des naïfs

Trois personas, joués par des agents **sans code, sans carte, sans rapports** : **le nouveau** (jamais vu l'outil, connaît le métier) · **le pressé** (sait ce qu'il veut, exige trois clics) · **le remplaçant** (doit faire une tâche précise à la place de quelqu'un, sans consigne).
Trois épreuves : **(a)** test des 5 secondes sur capture seule · **(b)** dix intentions → où cliques-tu ? · **(c)** cinq réglages à trouver et modifier. Chronométré, taux de réussite calculé, **joué avant et après**. La comparaison de ces deux chiffres est le résultat principal du chantier.

---

## 8. LES CIRCUITS — LES PARCOURS DE BOUT EN BOUT

Une console peut avoir de belles pages et un circuit cassé. Chaque circuit ci-dessous est joué **en entier**, à la main, chronométré, clics comptés, GIF archivé, **avant et après** — en notant chaque passage d'une console à l'autre et chaque ressaisie (`F19`).

1. **Un message arrive → on répond → c'est consigné sur la bonne fiche.**
2. **Un rendez-vous est réservé → il apparaît → il est honoré → le statut redescend.**
3. **Une candidature arrive → elle est lue → classée → le candidat est répondu.**
4. **Un prospect devient client** : de la première trace jusqu'au devis envoyé.
5. **Devis → facture → paiement → relance** : combien d'écrans, combien de ressaisies ?
6. **Une session de formation** : création → inscrits → convention → émargement → attestation → facturation.
7. **Une collecte** : définir une campagne → la lancer → la suivre → l'arrêter → exploiter le résultat → l'exclure d'un envoi.
8. **Une demande RGPD** : réception → traitement → export → effacement → vérification de la propagation dans l'autre console.
9. **Un incident du canal site ↔ CRM** : on le voit où ? on rejoue comment ? on sait quand c'est réparé ?
10. **Une génération de contenu** : lancer → suivre → valider → publier → constater l'effet.
11. **Un nouvel utilisateur** : compte créé → première connexion → 2FA → premier écran → première tâche accomplie, **sans aide**.
12. **La journée type** : ce qu'il y a à faire aujourd'hui — le voit-on d'un seul endroit ?

Pour chacun : **le nombre de fois où l'on change de console** et **le nombre de fois où l'on retape une information que le système connaît déjà**. Ce sont les deux chiffres qui disent si le circuit est sain. Référence : la carte « où je vais pour quoi » du CDC §22.6 — *la journée se passe dans le CRM ; on n'entre dans la console axionia que par un lien, sur un objet précis*.

---

## 9. LA MÉTHODE D'OPTIMISATION — CE QU'IL FAUT PRODUIRE

Constater ne suffit pas : **ce prompt exige la refonte**, appliquée et vérifiée.

### 9.1 L'inventaire des intentions — le socle de tout

Sortir de **l'interface et du code** la liste de tout ce qu'un utilisateur vient faire ici — viser **40 à 60 intentions** en langage courant (« voir qui m'a écrit », « rappeler quelqu'un », « lancer une collecte sur l'Isère », « retrouver une personne », « savoir si un événement est bien arrivé », « éditer une convention », « facturer une session », « régler qui reçoit quoi », « comprendre pourquoi une page ne s'est pas générée », « vérifier que la sauvegarde a tourné »…). Pour chacune : **fréquence** (quotidien → rare) · **outil** (CRM ou axionia) · **emplacement actuel** · **trouvabilité** (immédiate / avec effort / introuvable) · **clics actuels** · **clics cibles**.
**C'est cette liste — et non l'arborescence des fichiers — qui décide de la navigation.**

### 9.2 Les arbres de navigation cibles

Pour **chaque** console, le plan complet, avec pour chaque entrée actuelle une décision (§7.1 pt 13) et une redirection. Règles :
- ordre par **fréquence d'usage**, du plus chaud au plus froid ;
- côté CRM : **les cinq groupes du CDC §23.3** (+ « Collecte » tant qu'elle vit) ;
- côté axionia : **≤ 7 entrées par groupe** — donc `qualiopi` (33) et `content_gen` (34) **doivent être découpés en pôles nommés par la tâche**. Le patron existe déjà dans le dépôt : `content_gen` a été refondu en 6 pôles « Lancer · Suivre · Publier · Villes · Qualité & Coûts · Réglages », ordonnés par fréquence. **On généralise ce patron, on ne le réinvente pas** ;
- **≤ 2 niveaux** ; les types et statuts deviennent des **vues épinglées du même écran**, pas des pages différentes ;
- aucune entrée verrouillée ; aucune entrée dont l'URL contredit le groupe.

### 9.3 Le retrait par paliers des écrans de relation d'axionia

Les 12 écrans « Contacts » sortent de la navigation **par paliers réversibles derrière un drapeau**, chacun devenant une redirection vers la vue CRM équivalente. Avant chaque palier : la preuve que **formulaires, réservations, messages et alertes Telegram continuent d'arriver**, et que remettre le drapeau à `false` restaure l'ancien état **en moins d'une minute**.

### 9.4 Le glossaire — un mot par notion

Produire `notion → mot retenu → mots à éliminer → occurrences exactes à corriger`, en balayant : barres latérales, titres de page, onglets, boutons, en-têtes de colonnes, messages, états vides, `fr.json` / `en.json` (CRM), libellés d'`admin-nav.ts` (axionia), et **les objets métier** (Contact, Client, Prospect, Devis, Session, Campagne, Candidature, Rendez-vous, Tag/Étiquette). Point de départ : les 19 collisions du §6.
Bannir le jargon (« Data », « Runs », « Pipeline », « Hub », « Router », « Backfill », « Ops ») **sauf** si aucun mot français courant n'existe — et alors le justifier par écrit.

### 9.5 Le guide de rédaction d'interface

Écrire et **appliquer** : les **titres de page** disent la tâche · les **boutons** commencent par un verbe et disent le résultat (« Envoyer le devis », pas « Valider ») · les **messages d'erreur** disent quoi s'est passé, pourquoi, et quoi faire · les **états vides** enseignent (« Aucune campagne. Créez-en une pour… ») · les **confirmations** ne demandent que pour l'irréversible · les **libellés de champ** sont des mots, pas des noms de colonne · **jamais** de terme technique visible (`relation_type`, `workspace_id`, `501`, `payload`).

### 9.6 Les pages elles-mêmes

**Un écran = une tâche.** Une action principale dominante, un titre qui dit la tâche, l'information la plus utile en haut, l'accessoire replié, les colonnes utiles seulement, les états vides pédagogiques. Sur la **fiche 360°** du CRM, l'anatomie est imposée par le CDC §1.5 : bandeau permanent (identité, types, étape, coordonnées actionnables, dernier échange, **prochaine action**, état d'engagement reflété), colonne centrale d'historique, blocs latéraux repliables, **aucun onglet cachant une information**, navigation précédent / suivant / récents.

### 9.7 Ce qui aide sans réorganiser

À vérifier, et à installer si absent : **recherche globale au clavier (⌘K)** couvrant tout · **palette de commandes** (axionia en a une — le CRM ?) · **recherche dans les réglages** · **fiches récentes** · **accueil orienté action** (ce qu'il y a à faire aujourd'hui, pas des totaux) · **assistant de première configuration** · **visite guidée refaite une seule fois, sur la barre cible** · **page « ce qui n'est pas encore réglé »** · **raccourcis clavier** documentés et découvrables.

### 9.8 Gouvernance — empêcher que ça re-pourrisse

Le désordre revient toujours par petits ajouts. Produire :
- un **contrat de navigation** versionné (le fichier qui décrit les groupes autorisés, l'ordre, les plafonds : ≤ 7 entrées, ≤ 2 niveaux, 0 entrée verrouillée) ;
- un **test qui rougit** si un groupe dépasse le plafond, si une entrée verrouillée apparaît, si un libellé du glossaire est réutilisé pour une autre notion, si une redirection casse — **vu rouge avant d'être vert** ;
- une **note de décision** courte expliquant les arbitrages, pour que le prochain qui ajoute un écran sache où le mettre.

### 9.9 Application

Chaque décision est **appliquée dans le code**, avec : une redirection pour chaque URL déplacée (aucun 404, aucun signet cassé) · un **drapeau réversible** pour tout retrait, avec la preuve du retour en moins d'une minute · un **test** vu rouge puis vert · des **captures avant / après** de chaque section, page et onglet modifiés.

---

## 10. LES 50 AGENTS

**Règles :** le chef de chantier ne réalise pas ; un spécialiste ne vérifie jamais sa propre pièce ; les naïfs (bloc V) sont **cloisonnés** — ni code, ni carte, ni rapports ; un désaccord se tranche par une mesure.
**Rotation :** constat de l'agent **N** → vérifié par **((N+17) mod 50)+1** → réfuté par **((N+29) mod 50)+1** ; passe 3 : agents neufs.
**Chaque agent rend** : sa grille remplie case par case, ses captures nommées, ses frictions classées, ses constats au format §12, et la liste de ce qu'il n'a **pas** pu vérifier et pourquoi.

### Direction (4)

| # | Rôle |
|---|---|
| 1 | **Chef de chantier** — découpe, affecte, ordonnance, arbitre par la mesure, décide. Ne réalise rien |
| 2 | **Gardien du contexte** — recompte les listes du §5 (y compris les entrées dynamiques), publie les écarts, fournit à chaque agent son dossier, **empêche de réinventer** ce qui est déjà décidé (§3-11) |
| 3 | **Greffier** — journal append-only, tableaux de suivi, matrice décision → application → preuve |
| 4 | **Registrateur des constats et frictions** — registre unique, taxonomie §7.9, mesures avant/après |

### Bloc E — Exploration à l'aveugle, console CRM (8)

Chacun **ouvre tout** son périmètre et remplit les grilles PAGE, ONGLET, FORMULAIRE **avant** d'avoir le droit de lire une ligne de code.

| # | Périmètre |
|---|---|
| 5 | Authentification et première entrée : `/login`, `/2fa`, `/magic-link`, `/password-reset`, première configuration, déconnexion, session expirée en pleine saisie |
| 6 | Accueil et pilotage : `/`, `/admin/observability` — chaque compteur cliqué, chaque graphe, chaque lien |
| 7 | Hub contacts, vivier, arbitrage : `/console/contacts`, `/console/vivier`, `/console/arbitrage` — dont leurs onglets et l'étanchéité des deux espaces **vue à l'écran** |
| 8 | **La fiche 360°** `/persons/{key}` — comparée à l'anatomie imposée du CDC §1.5, bloc par bloc |
| 9 | Entreprises et contacts : `/companies`, `/companies/{id}`, `/contacts`, `/tags` |
| 10 | Collecte : `/campaigns`, **l'assistant `/campaigns/new` étape par étape**, `/campaigns/{id}`, `/scraper-runs`, `/coverage` |
| 11 | Audiences, médias, international : `/audiences`, `/audiences/new`, `/audiences/{id}`, `/media`, `/media/{id}`, `/journalists`, `/international/roumanie` |
| 12 | Conformité et administration : `/rgpd/*`, `/audit-logs`, `/users`, **`/settings` onglet par onglet**, `/llm/*`, les 4 pages factices, le 404 |

### Bloc A — Exploration à l'aveugle, console axionia (10)

| # | Périmètre (§5.5) |
|---|---|
| 13 | `main` (7) **+ les 7 entrées mortes masquées** : que voit-on par URL directe, palette, fil d'ariane, signet ? |
| 14 | `contacts` — **les 12 écrans**, un par un |
| 15 | `tunnels` (3) + `engagement` (1) + `content` (8) |
| 16 | `content_gen` — pôle « Lancer » + pôle « Suivre » |
| 17 | `content_gen` — pôles « Publier » et « Villes » (dont les 4 vues de couverture masquées) |
| 18 | `content_gen` — pôles « Qualité & Coûts » et « Réglages » |
| 19 | `qualiopi` — **33 entrées**, première moitié (À traiter → Clients, Devis) |
| 20 | `qualiopi` — seconde moitié (Barèmes OPCO → E-mails à valider) + `finances` (6) |
| 21 | `documents-interventions` (8) + `coaching-1to1` (2) + `image-bank` (10) + `presse` (4) |
| 22 | `chatbot` (5) + `ops` (12 **+ entrées dynamiques QR et imprimés**) + `system` (4) |

### Bloc N — Architecture de l'information et navigation (9)

| # | Rôle |
|---|---|
| 23 | **Architecte de la navigation, CRM** — arbre cible §23.3, correspondances, redirections |
| 24 | **Architecte de la navigation, axionia** — 15 groupes → cible, **découpage de `qualiopi` et `content_gen` en pôles par la tâche** |
| 25 | **Auteur de l'inventaire des intentions** (§9.1), avec fréquences, clics actuels et cibles |
| 26 | **Auditeur du vocabulaire** — le glossaire §9.4, à partir des 19 collisions du §6, sur les deux consoles |
| 27 | **Rédacteur d'interface** — le guide §9.5, et sa mise en œuvre : titres, boutons, erreurs, états vides, confirmations |
| 28 | **Auditeur des onglets internes** — grille §7.2, les 13 écrans du CRM et tous ceux d'axionia |
| 29 | **Auditeur des compteurs, badges et notifications** — grille §7.6 |
| 30 | **Auditeur des orphelins et fantômes** — le croisement routes ↔ navigation du §5.6, dans les deux consoles |
| 31 | **Auditeur des chemins d'entrée** — palette, recherche, fil d'ariane, URL directe, signet, lien d'e-mail, notification : mènent-ils tous au même endroit ? (grille §7.5) |

### Bloc P — Pages, états, interactions (7)

| # | Rôle |
|---|---|
| 32 | Auditeur des **titres, actions principales et hiérarchie visuelle** |
| 33 | Auditeur des **états vides et de la pédagogie** — les pages qui n'apprennent rien à qui débute |
| 34 | Auditeur des **erreurs, messages et retours d'action** — jargon, absence d'issue, `F20` (« a-t-il marché ? ») |
| 35 | Auditeur des **formulaires et assistants** — grille §7.4 |
| 36 | Auditeur des **listes et tableaux** — colonnes, tri, filtres, pagination, URL partageable, densité, 0 / 1 / 100 / 10 000 lignes |
| 37 | Auditeur des **actions et de la réversibilité** — boutons désactivés muets, confirmations inutiles, absence d'annulation |
| 38 | Auditeur du **système de design et de la cohérence inter-écrans** — matrice §7.8, composants réutilisés ou recopiés, mode sombre, iconographie |

### Bloc C — Les circuits (4)

| # | Rôle |
|---|---|
| 39 | Circuits **relation** : §8 n° 1, 2, 3, 4 |
| 40 | Circuits **argent et exécution** : §8 n° 5, 6 |
| 41 | Circuits **collecte, contenu, conformité** : §8 n° 7, 8, 10 |
| 42 | Circuits **exploitation et prise en main** : §8 n° 9, 11, 12 — et la carte « où je vais pour quoi » (§22.6) |

### Bloc T — Terrains particuliers (5)

| # | Rôle |
|---|---|
| 43 | **Points de rupture 375 / 768 / 1280 / 1920** — les deux consoles, toutes les pages |
| 44 | **Clavier seul et accessibilité** — focus, ordre, pièges, contrastes, ARIA, onglets au clavier, lecteurs d'écran |
| 45 | **Rôles et permissions vus de l'utilisateur** — les comptes du §4.3 : entrées inaccessibles, messages secs, pages vides |
| 46 | **Première fois et drapeaux éteints** — premier compte, console vide, jeu de démonstration, visite guidée ; et ce que voit un utilisateur quand `ConsoleGate` ou un palier de retrait est à `false` · **fluidité perçue** (grille §7.7) en réseau bridé |
| 47 | **Internationalisation et formats** — chaînes en dur, clés manquantes, dates et fuseaux, montants (⚠ espace fine insécable U+202F) |

### Bloc V — Les naïfs, cloisonnés, et la complétude (3 + 1 dans le bloc)

| # | Rôle |
|---|---|
| 48 | Naïf **« le nouveau »** — test des 5 secondes sur **toutes** les captures, les deux consoles |
| 49 | Naïf **« le pressé »** — les dix intentions, les deux consoles |
| 50 | Naïf **« le remplaçant »** — les cinq réglages, les deux consoles — **puis, en fin de chaque passe, critique de complétude** : « quelle entrée n'a pas été ouverte, quelle case de grille est vide, quelle décision n'a pas été appliquée, quelle mesure n'a pas été refaite après ? » Ce qu'il trouve devient du travail |

---

## 11. DÉROULÉ — DIX PHASES, TROIS VÉRIFICATIONS

**P0 — Terrain.** Créer le dossier de travail. Démarrer les deux consoles **en local** (§4.2) ; si l'une refuse, constat S0 et bascule en lecture seule sur la production. Créer les comptes de rôle. Charger les outils navigateur en un appel. **Recompter les listes du §5** dans le code, y compris les entrées dynamiques, et publier les écarts. Produire le croisement routes ↔ navigation (§5.6).

**P1 — Mesure « avant », par les naïfs.** **Avant tout audit expert** : test des 5 secondes sur chaque page, dix intentions, cinq réglages, sur les deux consoles. Sans cette ligne de base, aucun « après » ne veut rien dire.

**P2 — Exploration à l'aveugle** (blocs E et A). Chaque agent ouvre **toutes** ses pages et **tous** ses onglets, remplit ses grilles, capture chaque état, classe ses frictions. Interdiction de lire le code avant d'avoir fini de cliquer — pour ne pas comprendre une page grâce à une information que l'utilisateur n'a pas.

**P3 — Les circuits** (bloc C). Les douze parcours du §8, joués en entier, chronométrés, GIF archivés, changements de console et ressaisies comptés.

**P4 — Analyse** (blocs N, P, T). Intentions, glossaire, rédaction d'interface, onglets, compteurs, orphelins, chemins d'entrée, états, formulaires, listes, cohérence, points de rupture, clavier, rôles, première fois, fluidité.

**P5 — Diagnostic et arbres cibles.** Le chef de chantier arbitre et écrit `10_NAVIGATION-CIBLE.md` : une décision pour **chacune** des ~135 entrées, des ~39 pages CRM, des onglets internes ; redirections ; glossaire arrêté ; ordre par fréquence ; découpage en pôles ; paliers de retrait côté axionia.

**P6 — Application.** Un lot = une branche = une PR petite et lisible. Renommages, regroupements, réordonnancements, fusions, scissions, redirections, pages et onglets réorganisés, messages réécrits, visite guidée refaite **une seule fois**, contrat de navigation et tests **vus rouges puis verts**. Rien n'est supprimé : tout retrait est un drapeau réversible.

**P7 — Mesure « après » et vérification n° 1.** Les naïfs rejouent **les mêmes** épreuves sur les nouvelles captures ; les circuits sont rechronométrés. Chiffres avant/après publiés. Chaque décision appliquée est vérifiée par un autre agent (rotation +17), redirection par redirection, libellé par libellé.

**P8 — Deuxième passe : contre-vérification adversariale, de bout en bout.**
Consigne (rotation +29) : *« Démontre que la refonte a échoué. Trouve : une entrée qu'on ne trouve plus, une URL qui 404, un libellé qui promet autre chose que son contenu, une page ou un onglet jamais ouvert, un état jamais vu, un test de navigation qui ne rougit pas, une friction déplacée plutôt que supprimée, un compteur redevenu décoratif, un mot qui a deux sens, un chemin d'entrée (palette, recherche, signet, notification, e-mail) qui ne mène plus au même endroit que le menu, un circuit qui a gagné un aller-retour. »* Sans accès au raisonnement de la passe 1. Tout ce qu'il trouve repart en P6, puis P7. Boucler jusqu'à ce qu'une passe adversariale complète ne trouve plus rien de sévérité ≥ S2.

**P9 — Troisième passe : regard neuf.** Agents **neufs** — ni rapports, ni arbre cible, ni registre. On leur donne les consoles telles qu'elles sont **après** refonte et les 15 seuils du §2. Ils refont l'audit de zéro. Puis **comparaison des trois passes** : tout écart est un défaut de méthode, expliqué ligne à ligne.

**P10 — Clôture.** Rapport final : les 15 seuils, chiffre avant → chiffre après, preuve pour chacun. Verdict en toutes lettres : **« les consoles sont / ne sont pas immédiatement compréhensibles »**, avec ce qui reste.

---

## 12. LIVRABLES ET MODÈLES

Dossier : `Axion-CRM-Pro/_AUDIT/2026-08-18_UX-CONSOLES/`

| Fichier | Contenu |
|---|---|
| `00_JOURNAL.md` | Append-only, horodaté : qui a ouvert quoi, quand, avec quelle preuve |
| `01_INVENTAIRE.md` | Les listes du §5 recomptées, le croisement routes ↔ navigation, les tableaux de suivi |
| `02_CONSTATS.md` | Registre unique, dédoublonné |
| `03_FRICTIONS.md` | Toutes les frictions, taxonomie §7.9, avec coût et statut |
| `04_PREUVES/` | **Captures avant/après de chaque page, onglet et section**, GIF des circuits, sorties console, mesures, sorties rouges des tests |
| `05_DECISIONS.md` | Chaque décision d'autopilote : question, options, décision, raison |
| `06_RESTE-WILL.md` | Ce qui exige le dirigeant, une page, avec recommandation |
| `07_RAPPORT-FINAL.md` | Les 15 seuils, avant → après, verdict |
| `08_PASSE-2-ADVERSARIALE.md` | Ce que la contre-vérification a réfuté ou confirmé |
| `09_PASSE-3-REGARD-NEUF.md` | L'audit indépendant + comparaison des trois passes |
| `10_NAVIGATION-CIBLE.md` | Intentions et fréquences · arbres cibles · correspondance complète · redirections · paliers de retrait · contrat de navigation |
| `11_GLOSSAIRE.md` | Notion → mot retenu → mots éliminés → occurrences corrigées |
| `12_REDACTION-INTERFACE.md` | Le guide §9.5 et la liste des textes réécrits |
| `13_CIRCUITS.md` | Les douze circuits : gestes, clics, secondes, changements de console, ressaisies, avant/après |
| `14_GRILLES/` | `entrees-menu.md` · `onglets.md` · `pages-crm.md` · `pages-axionia.md` · `formulaires.md` · `recherche.md` · `notifications.md` · `fluidite.md` · `coherence.md` · `comprehension.md` |

**Convention de nommage des captures** : `04_PREUVES/{console}/{avant|apres}/{route-slug}__{etat}__{largeur}.png`
— exemple : `04_PREUVES/crm/avant/console-contacts__vide__1280.png`. États normalisés : `nominal`, `chargement`, `vide`, `erreur`, `permission`, `long` (10 000 lignes), `sombre`.

**Modèle de fiche écran** (une par page, dans `14_GRILLES/pages-*.md`) :

```
## {Route} — {Titre affiché}
Ouverte le · par l'agent · environnement (local/prod) · rôle du compte
Captures : nominal · chargement · vide · erreur · permission · 375 · sombre
Test des 5 s : réussi/échoué — réponse mot pour mot du naïf
Action principale : … (dominante ? oui/non)
Onglets internes : … (dans l'URL ? survivent au rechargement ?)
Grille PAGE : 30 points, tous remplis
Frictions : F… (coût)
Verdict : conservée / retitrée / réorganisée / fusionnée / scindée / redirigée
Appliqué le · PR · test qui garde la décision (vu rouge le …)
```

**Schéma d'un constat** — comme dans le prompt d'audit 360, plus trois champs propres à celui-ci : `Friction` (F1→F20) · `Mesure avant → après` (clics, secondes, taux de réussite) · `Captures` (chemins).

**Sévérités.** **S0** : l'utilisateur ne peut pas accomplir une tâche courante, ou perd des données. **S1** : il y arrive mais se trompe, se perd, ou doit apprendre par cœur. **S2** : friction réelle, coût en clics ou en secondes. **S3** : finition.
⚠️ **Une entrée qui ment (cadenas, 501, contenu différent du libellé, URL contredisant le groupe) est S1 par défaut** : elle apprend à se méfier de la navigation entière.

---

## 13. SÉCURITÉ ET NON-RÉGRESSION

Cette refonte touche la navigation d'outils qui font tourner l'entreprise. Avant **chaque** fusion, ces sept points sont **rejoués**, pas relus :

1. **Les entrants continuent d'arriver** : formulaires du site, réservations Calendly, candidatures, newsletter, avis — et les alertes Telegram partent toujours.
2. **La facturation et la conformité sont intactes** : devis, factures, échéanciers, sessions, conventions, attestations, Qualiopi, BPF.
3. **Le canal site ↔ CRM** émet et reçoit comme avant ; l'écran « Synchro CRM » le montre.
4. **Aucune URL ne meurt** : chaque route déplacée redirige ; palette, fils d'ariane et liens des e-mails pointent au bon endroit.
5. **Chaque retrait est réversible en moins d'une minute** par un drapeau remis à `false` — testé, pas supposé.
6. **Les permissions ne s'élargissent pas** : un écran déplacé garde sa protection ; vérifié avec un compte restreint.
7. **Aucune donnée de production modifiée** pendant tout le chantier, et **aucun identifiant recopié** dans un fichier ou une capture.

---

## 14. PIÈGES CONNUS

1. ⚠️ **`form_input` n'écrit pas l'état React** — saisir au clavier avec `computer`, sinon un formulaire paraît rempli et part vide.
2. **Ne jamais déclencher d'alerte ou de confirmation native** : l'extension se fige, la session est perdue.
3. **Toujours `tabs_context_mcp` d'abord** ; jamais d'identifiant d'onglet hérité.
4. **Playwright + serveur de dev : `localhost`, jamais `127.0.0.1`.**
5. **Un masquage n'est pas un rangement** : vérifier **tous** les chemins d'entrée d'un écran retiré.
6. **Un test statique de libellés trouve ses propres commentaires** — assertions sur le rendu, pas sur le fichier source.
7. **Fins de ligne Windows** : un test qui cherche un `\n` littéral est aveugle en local et vert en CI.
8. **Ne pas réinventer ce qui est décidé** : les 6 pôles de `content_gen` (2026-06-16), le renommage `qualiopi` → « Formations & prestations » (2026-08-01), la cible §23.3 et la console de réglages §19 du CDC.
9. **Les montants français portent une espace fine insécable (U+202F)** : un `grep` à l'espace normale rend 0 et fait croire à un défaut.
10. **La CI évalue le commit de fusion** ; relire `gh pr list` juste avant d'ouvrir une PR ; ne jamais grouper des montées de dépendances.
11. **`git stash` est global au dépôt** : ne pas éditer un fichier pendant qu'un hook de commit tourne.
12. **Plusieurs sessions peuvent travailler en parallèle** : relire `HEAD` juste avant d'écrire dans un worktree ; `crmpro-wt-etape0` est occupé.

---

## 15. DÉFINITION DE FINI

Tu n'as pas terminé tant que **tout** ce qui suit n'est pas vrai et prouvé :

1. **Les 15 seuils du §2 sont atteints**, chacun avec son chiffre **avant** et son chiffre **après**.
2. **Chaque entrée des deux consoles (~135), chaque page CRM (~39) et chaque onglet interne** figure dans un tableau de suivi : ouverte le, verdict, captures, frictions, décision, **décision appliquée**, test.
3. **Chaque grille du §7 est remplie**, case par case.
4. **Les douze circuits du §8** sont joués avant et après, chronométrés, avec le nombre de changements de console et de ressaisies.
5. `10_NAVIGATION-CIBLE.md` est écrit **et appliqué** : arbres cibles, correspondances, redirections, pôles, paliers, **contrat de navigation**.
6. `11_GLOSSAIRE.md` et `12_REDACTION-INTERFACE.md` sont écrits **et appliqués** : plus aucun synonyme ni homonyme, plus aucun terme technique visible, dans **les deux** consoles.
7. **Aucune entrée verrouillée, aucune route 501, aucun lien mort, aucun cul-de-sac, aucune page orpheline, aucune entrée fantôme.**
8. **Aucune URL ne 404** : toutes les redirections écrites et testées.
9. **Chaque retrait est réversible** par un drapeau, prouvé en moins d'une minute.
10. **Les tests de navigation, de libellés, de plafonds de groupe et de redirections ont été vus rouges puis verts.**
11. La **visite guidée** a été refaite une seule fois, sur la barre cible, et jouée du début à la fin.
12. Les **naïfs ont rejoué les mêmes épreuves après** la refonte, et les chiffres se sont améliorés — ou l'échec est écrit tel quel, sans enjolivure.
13. La passe **adversariale (P8)** puis la passe **à regard neuf (P9)** ont été menées en entier, et la dernière ne trouve plus rien de sévérité ≥ S2.
14. Les sept points de non-régression du §13 ont été **rejoués** avant chaque fusion.
15. `06_RESTE-WILL.md` tient en une page, sans redite, chaque ligne avec une recommandation.

**Si tu es interrompu**, reconstruis ton état depuis les dépôts et `00_JOURNAL.md` — jamais depuis ce qu'une conversation croyait.

**Commence maintenant. Ouvre le navigateur avant l'éditeur. Ne demande rien. Mesure, décide, applique, remesure, et va jusqu'au bout.**
