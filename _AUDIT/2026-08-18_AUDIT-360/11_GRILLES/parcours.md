# Grille des parcours — AGENT 24 « Auditeur des parcours » (mandat §5.6)

- **Référence** : `main = e8924b8` au lancement, **`6c90194`** à la remise.
  Vérifié : `git diff --stat e8924b8..6c90194 -- backend frontend infra` ne rend **qu'un fichier**,
  `infra/scripts/verifier-serveur-http.sh` (**nouveau**). **Aucune ligne de `backend/` ni de
  `frontend/` n'a bougé** : toutes les mesures de cette grille valent pour les deux références.
- **Base locale** : `axion_crm`, `select count(*) from migrations` = **58 avant** et **58 après**.
- **Preuves** : `04_PREUVES/agent-24/` — 5 sondes, 6 fichiers JSON, 8 captures, le journal des
  130 appels d'API doublés, et `COMMANDES-JOUEES.md`.

---

## 0. Avertissement de méthode — ce que cette grille mesure, et ce qu'elle ne mesure pas

### 0.1 Pourquoi l'état nominal reste inatteignable — et pourquoi ce n'est pas un échec

L'agent 22 l'a mesuré de bout en bout, je ne le rouvre pas, je l'utilise comme borne :

```
POST /auth/login       -> 200    GET /auth/me -> 200 {"roles":["owner"]}
GET  /dashboard/stats  -> 403    first_login_required -> next_step:/auth/2fa/setup
POST /auth/2fa/setup   -> 500    column "two_factor_secret" of relation "users" does not exist
```

et **aucun écran du produit n'expose l'enrôlement 2FA** (D22-001). La console **n'est pas
utilisable** : A07-001 (S0) mure le serveur, D22-001 (S0) mure l'interface. **Aucun parcours ne peut
donc être joué sur une session réelle.** Je ne l'ai pas contourné par un artifice sur le produit.

### 0.2 Comment j'ai quand même compté des clics — méthode de l'agent 23, reprise telle quelle

L'agent 23 avait déjà résolu ce problème sans toucher au produit : un serveur `vite` sur le code de
`main`, et les deux seules réponses qui gouvernent le montage de la coquille (`/auth/me` et
`/config/features`) **doublées** dans le navigateur. **On étend, on ne réinvente pas** (règle 8).
J'ai repris exactement sa méthode et **étendu le jeu de doublures aux 40 routes d'API** que les
écrans consomment, en relevant leur **contrat exact dans les types TypeScript du dépôt** (par
exemple `frontend/src/features/crm-console/types.ts` pour le hub, `campaigns/types.ts` pour les
collectes, `CoveragePage.tsx:7` pour la carte).

```
frontend/node_modules/.bin/vite --port 5224 --strictPort --host 127.0.0.1
node sondes/parcours.mjs <dossier>          # 21 parcours, chaque clic est un VRAI clic
```

**Ce que cela mesure, et bien** : le **chemin**, le **nombre de clics**, les **culs-de-sac**, les
**boutons désactivés**, la **perte d'état au retour arrière**, l'**absence d'un geste**. Ce sont
précisément les objets du §5.6.

**Ce que cela ne mesure PAS, et je ne le masque pas** : (a) le comportement du **vrai** serveur
(sauf là où j'ai reproduit sa charge à l'identique — c'est le cas de **D24-001**, où la doublure
copie littéralement les colonnes du modèle `AuditLog`) ; (b) les **refus de permission** à
l'écran ; (c) les **volumes réels**. Chaque case du tableau dit sur quoi elle porte.

### 0.3 Six écrans n'ont pas pu être rendus sous doublure — dit franchement

`/campaigns/$id`, `/audiences`, `/audiences/new`, `/audiences/$id`, `/admin/observability`,
`/rgpd/requests` : mes charges d'essai ne portaient pas exactement les champs attendus, et l'écran
a **emporté toute l'application** (§4.3). **C'est un défaut de ma doublure, pas une mesure du
produit** — je ne les compte donc ni comme culs-de-sac ni comme écrans sains. En revanche, le
**mécanisme** qu'ils exhibent, lui, est mesuré sur la charge **réelle** du serveur : c'est
**D24-001**, et il a un témoin.

---

## 1. 🔴 LA GRILLE DES 13 PARCOURS DE RÉFÉRENCE DU §23.4

> Le mandat le dit lui-même : *« beaucoup n'existent pas encore : c'est précisément le résultat
> attendu »*. Ce tableau n'est pas un rapport d'échec. **C'est la carte de ce qu'il reste à
> construire**, et la colonne « ce qui manque exactement » est la seule qui compte.

| # | Parcours (§23.4) | Budget CDC | Verdict | Ce qui manque **exactement** | Clics mesurés |
|---|---|---|---|---|---|
| 1 | **Répondre à un message entrant** | 2 clics, fiche visible à côté | 🔴 **absent** | **Les deux départs sont morts.** ① La cloche « Notifications » de l'en-tête **n'a aucun gestionnaire de clic** (`Header.tsx:62-70`) — mesuré : elle se clique, **rien ne s'ouvre**. ② Aucune boîte de réception : le groupe **ÉCHANGES** entier manque (A-006). Côté serveur, `GET /notifications` rend `['data'=>[]]` **en dur** et `markRead`/`markAllRead` rendent **501** ; **aucun fichier du frontend n'appelle cette route**. Les tables `email_threads`, `email_messages`, `email_inboxes` **existent en base** — **0 contrôleur, 0 route, 0 écran** | **1 clic, puis plus rien** (arrêt à l'étape 2) |
| 2 | **Créer un contact complet** | 1 clic + saisie, **aucun champ bloquant** | 🔴 **absent** | **Aucune route de création** : `POST /contacts` n'existe pas ; `PUT`/`DELETE` rendent **501** (I48-001). **Aucun bouton** de création de personne nulle part : cherché dans l'en-tête, la barre et les 26 écrans rendus → **0**. Et « aucun champ bloquant » est **déjà faux au niveau du schéma** : `contacts.company_id` **NOT NULL** (une personne sans organisation est impossible) et `contacts.last_name` **NOT NULL** (I48-003) | **0 clic** — le geste n'existe pas |
| 3 | **Consigner un appel qui vient d'avoir lieu** | 1 clic, écran d'entretien prêt | 🔴 **absent** | `Taxonomy::ACTIVITY_KINDS` (16 valeurs) **n'a aucun mot d'interaction humaine** — ni appel, ni e-mail, ni réunion, ni note — et la liste est **fermée par un `CHECK` SQL** (`activities_kind_check`) : **il n'existe pas de `kind` pour un appel**. Aucun « écran d'entretien ». Et le départ exigé, la fiche, **n'offre aucun bouton** (§3.3) | **0 clic** depuis la fiche |
| 4 | **Lancer la visio d'un rendez-vous prévu** | 1 clic depuis l'accueil | 🔴 **absent** | Table `appointments` : **absente des 58 migrations**. Le mot « visio » : **0 occurrence** dans `frontend/src`. L'accueil porte **4 boutons** (7j/30j/90j + « Démarrer sur /coverage ») — aucun ne concerne un rendez-vous. Entrée « Mes rendez-vous » : absente (A-006) | **0 clic** |
| 5 | **Retrouver ce qui a été dit à un contact** | recherche → **fiche** → réponse **< 10 s** | 🟠 **partiel** — l'escalier existe, la marche du haut manque | ⌘K s'ouvre, la palette trouve la personne, **et le clic mène à `/contacts`, la liste complète** (`GlobalSearch.tsx:117`, déjà D23-005). Trois murs indépendants derrière : `GET /search` rend une **charge codée en dur** `{companies:[],contacts:[],tags:[]}`, **zéro SQL** (I48-002) ; **0 contact sur 1 319 567** porte une `person_key` (A05-001) ; et la timeline n'est qu'un **INDEX**, elle ne transporte **aucun contenu** (E32-002) — « ce qui a été dit » n'est nulle part | **2 clics, 9,4 s** — mais on atterrit sur **la liste**, pas sur la fiche |
| 6 | **Passer un entretien terminé en compte rendu validé** | 1 écran : relire, corriger, valider | 🔴 **absent** | Tables `recordings`, `transcriptions`, `documents` : **absentes des 58 migrations**. Aucune entrée « Comptes rendus à valider » : cherchée dans la barre → **introuvable**. Le groupe **ÉCHANGES** entier manque | **0 clic** |
| 7 | **Envoyer le devis après un rendez-vous** | 1 clic ouvre la console pré-remplie, envoi en 3, statut de retour **< 1 min** | 🔴 **absent** | Tables `quotes` et `invoices` : **absentes**. **Aucun lien du CRM vers la console axionia** : `grep -ri "axionia"` sur tout `frontend/src` → **0 occurrence** ; le pied de la barre ne contient que « Réduire » (D23-011). Et la fiche, départ exigé, **n'offre aucun bouton**. Il n'existe donc ni l'objet, ni le geste, ni le chemin | **0 clic** |
| 8 | **Traiter un appel client pour un problème** | 1 clic → entretien, motif « support » **pré-déduit**, engagement affiché ; tâche + réclamation en 2 clics | 🔴 **absent** | Le motif existe — `crm_motifs` porte bien `support-reclamation` (semé par `ActivitesEtMotifsSeeder`) — mais la table est **orpheline** : aucune route, aucun contrôleur, aucun écran (I48-004), **et aucune clé étrangère** ne la relie à quoi que ce soit (**D24-007**). Pas d'écran d'entretien. Pas de table « réclamation » ni « dossier ». `crm_tasks` existe, **0 code** | **0 clic** |
| 9 | **Prendre un appel dont on ignore le motif** | 1 clic → entretien ; 1 clic pour choisir le motif ; **trame chargée sans autre geste** | 🔴 **absent** | Même cause que ⑧ : les 11 motifs et les 5 activités sont **en base et injoignables**. Aucune notion de « trame » n'existe : `grep -i "trame\|questionnaire"` sur `frontend/src` → **0**. Le mot « motif » n'apparaît à l'écran que pour le **motif d'écartement** d'un arbitrage — un autre objet | **0 clic** |
| 10 | **Déplacer un candidat d'étape** | glisser-déposer ou 1 clic | 🟠 **partiel** — les étapes s'affichent, elles ne se changent pas | `candidates.lifecycle_stage` existe avec ses 6 étapes, et `/console/vivier` **les affiche** (compteurs « À QUALIFIER 5 · PRÉSÉLECTION 2 · ENTRETIEN 1 · CONSERVÉS 3 » mesurés à l'écran). Mais **aucune route de mutation** : côté `crm/candidates`, seuls `index` et `counts` existent. Mesuré à l'écran : les 5 boutons sont des **filtres**, aucun ne déplace. Aucun kanban. Les tables `crm_pipelines`, `pipeline_stages`, `deals`, `deal_history` **existent** — **0 code applicatif, 0 frontend** | **2 clics pour voir**, **0 pour déplacer** |
| 11 | **Programmer un rappel** | 1 clic + une date | 🔴 **absent** (mais la table est là) | `crm_tasks` **existe en base** avec `title`, `due_at`, `assignee_id`, `contact_id` — exactement ce qu'il faut — et **0 modèle, 0 route, 0 contrôleur, 0 écran**. Départ exigé : la fiche → **0 bouton** | **0 clic** |
| 12 | **Modifier un questionnaire** | aperçu avant publication | 🔴 **absent** | Table `questionnaires` : **absente des 58 migrations**. `/settings` porte **4 onglets** mesurés à l'écran — *Workspace · Intégrations · Observabilité · Apparence* — et **aucun** des 8 sous-groupes de la console du §19. Aucune notion d'aperçu ni de publication | **2 clics pour atteindre `/settings`**, puis **rien** |
| 13 | **Voir depuis la console qui est ce client** | 1 clic → fiche CRM | 🔴 **absent** | **0 lien profond dans les deux sens** (E32-006 / D23-011). Mesuré : le texte « axionia » est **introuvable dans la barre latérale**. L'unique lien existant, côté console, s'appelle **« Prospection »** et pointe la **racine** de l'application — pas une fiche. Et même s'il pointait une fiche : la fiche 360° exige une `person_key` que **0 fiche sur 1 319 567** ne porte | **0 clic** |

### 1.1 Le compte

| verdict | nombre | part |
|---|---|---|
| **existe** (au budget du CDC) | **0** | 0 % |
| **partiel** | **2** (⑤ retrouver, ⑩ déplacer un candidat) | 15 % |
| **absent** | **11** | 85 % |

**Aucun des 13 parcours de référence du §23.4 ne tient son budget.** Deux existent à moitié : dans
les deux cas, **la partie « voir » est là et la partie « faire » manque**.

### 1.2 Ce que le tableau fait apparaître, et qui ne se voit pas parcours par parcours

1. **Six des treize parcours partent « de la fiche ». La fiche n'a aucun bouton.**
   Mesuré : `/console/personnes/pk-demo`, servie avec une personne complète et sa timeline, rend
   **0 lien sortant et 0 bouton** dans son `<main>`. Ce n'est pas « il manque le bouton devis » :
   c'est que **le point de départ commun de la moitié du §23.4 est une page en lecture seule**.
   → **D24-003**.
2. **Le mot « fiche » désigne trois écrans différents.** `/companies/$id` (l'organisation),
   `/console/personnes/$personKey` (la personne, inatteignable), et la ligne de `/contacts` (qui
   n'ouvre rien du tout). Un budget « 1 clic depuis la fiche » n'est pas mesurable tant que le
   produit n'a pas tranché **de quelle fiche il parle** — et le §1.1 du CDC, lui, a tranché : une
   personne = une fiche.
3. **Quatre parcours butent sur une table qui existe déjà.** `crm_tasks` (rappels), `deals` +
   `pipeline_stages` + `crm_pipelines` (étapes), `email_threads` + `email_messages` (messages
   entrants), `crm_activites` + `crm_motifs` (motifs). **Le schéma est en avance sur le produit
   d'une couche entière** : il manque, à chaque fois, le modèle, la route, le contrôleur et
   l'écran — jamais la colonne. C'est une **bonne** nouvelle pour le chiffrage, et une mauvaise
   pour qui lirait la liste des tables comme une liste de fonctions.
4. **Quatre parcours butent sur une table qui n'existe pas du tout** : `appointments`,
   `quotes`/`invoices`, `questionnaires`, `recordings`/`transcriptions`. Ceux-là sont du travail
   neuf, migration comprise.
5. **Un parcours ne bute sur rien de technique** : ⑬ « voir qui est ce client depuis la console »
   ne demande qu'**un lien**. C'est le seul des treize dont le premier pas coûte moins d'une heure.

---

## 2. LES PARCOURS RÉELS DE L'OUTIL EXISTANT — chiffrés

> Le second volet du mandat, et il est atteignable, lui. Neuf parcours de l'outil **tel qu'il est**,
> joués avec de vrais clics. Preuve : `04_PREUVES/agent-24/parcours-mesures.json`.

| # | Parcours réel | Clics mesurés | Durée | Verdict |
|---|---|---|---|---|
| A1 | lancer une collecte sur un département (carte) | **4** | 17,3 s | ✅ **aboutit** — Collecte → Couverture France → *Action* → zone |
| A2 | créer une collecte multi-sources (assistant 4 étapes) | **5** | 11,6 s | ✅ **aboutit** — l'assistant enchaîne, l'étape 2 s'ouvre |
| A3 | retrouver une entreprise et ouvrir sa fiche | **3** | 11,3 s | ✅ **aboutit** — **au budget des 3 clics du §7.3** |
| A4 | exporter les entreprises en CSV | **3** | 10,3 s | ✅ **aboutit** |
| A6 | ouvrir la fiche d'un média | **3** | 9,1 s | ✅ **aboutit** (l'écran blanc de D22-003 ne se produit **pas** quand l'API répond) |
| A5 | poser un tag sur toute une page de résultats | **3, puis blocage** | — | ⚠️ **s'arrête** : après la case cochée, « Poser le tag » **et** « Retirer » **apparaissent DÉSACTIVÉS** ; il faut d'abord choisir un tag ailleurs dans l'écran. Le parcours coûte donc **≥ 5 clics**, pas 4 |
| A7 | arbitrer un rapprochement (rattacher) | **2, puis blocage** | — | 🔴 **s'arrête** : « Rattacher » **et** « Écarter » sont **DÉSACTIVÉS**, et les deux seuls champs de l'écran sont des zones de texte libre — placeholder **« ex. 1842 »**. **Il faut connaître et taper à la main l'identifiant numérique interne de l'entreprise.** → **D24-004** |
| A9 | ouvrir la fiche d'un candidat depuis le vivier | **2, puis blocage** | — | 🔴 **s'arrête** : aucun lien vers une fiche. Le `<Link>` existe (`CandidatesPage.tsx:124`) mais il est **conditionné à `person_key !== null`** — et A05-001 mesure **0 sur 1 319 567** |
| A8 | relancer un run de collecte échoué | **non mesuré** | — | ⚪ **défaut de ma sonde** : le bouton « Échec 0 » est bien **présent et actif** (`arrets.json`) ; c'est mon sélecteur qui a échoué. **Je ne conclus rien** |

### 2.1 Le verdict chiffré

**5 parcours réels sur 8 mesurables aboutissent** (A1, A2, A3, A4, A6). **3 s'arrêtent** (A5, A7, A9).
Un neuvième n'est pas mesuré, et je le dis.

Et la ligne de partage est nette : **les 5 qui aboutissent sont tous des parcours de collecte ou de
lecture.** **Les 3 qui s'arrêtent sont les 3 seuls où l'on essayait d'agir sur une personne ou sur
un lot.**

### 2.2 Le verdict de l'agent 22 — vérifié, et chiffré autrement

> *« le produit sait collecter mais ne sait pas travailler »* — 7 intentions trouvables sur 9 côté
> collecte, **6 impasses sur 7** côté « ouvrir sa journée ».

**Confirmé, par une mesure indépendante et d'une autre nature** (lui comptait des *intentions*
depuis le code ; je compte des *clics* dans un navigateur) :

| côté | mesure de l'agent 22 | ma mesure, en clics |
|---|---|---|
| **Collecter** | 7 trouvables / 9, **aucune impasse** | **4 parcours de collecte sur 4 aboutissent** (A1, A2, A4 + A6) en **3 à 5 clics** |
| **Ouvrir sa journée** | **6 impasses / 7** | **0 geste du matin sur 4 aboutit** — cloche (B1), visio (B4), comptes rendus (B6), création de contact (B2) : **0 clic productif sur les 4** |
| **Travailler une fiche** | non chiffré séparément | **0 action sur 4** depuis la fiche : consigner un appel, envoyer un devis, programmer un rappel, déplacer une étape → **la fiche a 0 bouton** |

**Et j'ajoute un chiffre que l'agent 22 ne pouvait pas voir**, parce qu'il a mesuré API muette :
côté « ouvrir sa journée », ce n'est pas seulement que les gestes manquent — **l'écran d'accueil
lui-même s'effondre dès que le journal d'audit contient une ligne** (**D24-001**). En base locale,
`audit_logs` en contient **34**. Le premier écran du produit, sur cette machine, **n'existe déjà
plus**.

---

## 3. CULS-DE-SAC, LIENS MORTS, ÉTAT PERDU AU RETOUR

### 3.1 Culs-de-sac — **18 écrans sur 26** n'offrent aucun lien sortant

Mesuré écran par écran (`recon-ecrans.json`) : nombre de `<a href="/…">` **visibles dans le
`<main>`**, hors barre latérale et hors en-tête.

| écran | liens sortants | boutons | lecture |
|---|---|---|---|
| `/companies` | **20** | 16 | l'écran le plus ouvert du produit |
| `/international/roumanie` | **20** | 15 | (la même liste, filtrée) |
| `/` · `/media` · `/campaigns` · `/campaigns/new` · `/companies/$id` · `/media/$id` | 1 à 2 | 3 à 6 | passables |
| **`/contacts`** | **0** *(hors `mailto:`/`tel:`)* | **0** | 🔴 **320 personnes affichées, aucune n'est ouvrable, aucune action** |
| **`/console/personnes/$key`** | **0** | **0** | 🔴 **la fiche 360° : ni porte de sortie, ni geste** |
| `/console/contacts` · `/console/vivier` · `/console/arbitrage` | 0 | 12 · 5 · 2 | les boutons sont des **onglets et des filtres** |
| `/journalists` · `/coverage` · `/scraper-runs` · `/tags` · `/users` · `/settings` · `/audit-logs` · `/llm/*` | 0 | 0 à 7 | — |
| `/rgpd/ai-act` · `/llm/rotations` · `/cold-email` · `/linkedin` | **0** | **0** | 🔴 **ni lien ni bouton : rien à faire du tout** |

**18 culs-de-sac sur 26 écrans rendus (69 %)**, dont **4 écrans qui n'offrent strictement aucune
action** et **2 écrans-clés** — la liste des personnes et la fiche 360°.

Le seul chemin hors d'un cul-de-sac est la barre latérale. La barre étant un **accordéon à une
seule section ouverte** (§7.2 de l'agent 23), **sortir d'un cul-de-sac coûte 2 clics**, pas 1.

### 3.2 L'état du travail ne survit pas au retour arrière — mesuré

```
/companies  → filtre « Prospectables » → page 3      URL = /companies        (aucun paramètre !)
            → clic sur une entreprise                URL = /companies/1
            → RETOUR ARRIÈRE du navigateur           URL = /companies
                                     état AVANT : bouton actif « 3 », « Page 3 · 20 affichées »
                                     état APRÈS : bouton actif « 1 », « Page 1 · 20 affichées »
                                     état conservé : FAUX
```

**Ni le filtre ni la page ne sont dans l'adresse.** Conséquences mesurées, toutes les trois :

1. **Le retour arrière ramène à la page 1, sans filtre.** Un opérateur qui travaille la page 3 sur
   7 et ouvre une fiche **perd sa place à chaque aller-retour** — c'est-à-dire à chaque fiche.
2. **Une page de résultats n'est ni partageable ni marquable** : l'adresse ne décrit pas ce qu'on
   voit.
3. **Aucune vue enregistrée ne peut rattraper cela** : `GET /saved-views` rend **200 liste vide**
   au lieu de 501 (A-002 / B10-013).

→ **D24-005**.

### 3.3 Liens morts et boutons morts — l'inventaire

| objet | état mesuré |
|---|---|
| **Cloche « Notifications »** (en-tête, tous les écrans) | 🔴 **aucun gestionnaire de clic** — se clique, ne fait rien. `Header.tsx:62-70` |
| **« Rattacher » / « Écarter »** (`/console/arbitrage`) | 🔴 **désactivés** tant qu'un identifiant numérique n'est pas **tapé à la main** |
| **« Poser le tag » / « Retirer »** (`/companies`) | ⚠️ **désactivés** après sélection, tant qu'aucun tag n'est choisi |
| **Résultat « personne » de ⌘K** | 🔴 mène à `/contacts` (la liste), pas à la fiche (D23-005, confirmé : `urlApresClic = /contacts`) |
| **`<Link>` vers la fiche 360°** (hub et vivier) | 🔴 présents dans le code, **conditionnés à `person_key !== null`** → **jamais rendus** (A05-001) |
| **`ErrorBoundary`** (`components/ui/ErrorBoundary.tsx`) | 🔴 écrit, exporté, **monté nulle part** — `grep -rn "<ErrorBoundary" frontend/src` → **0** |
| **Lien « ↗ Console axionia »** | 🔴 **n'existe pas** — le pied de la barre ne porte que « Réduire » |

---

## 4. CONSTATS

### [D24-001] L'écran d'accueil s'efface entièrement — barre latérale comprise — dès que le journal d'audit contient une seule ligne
- Sévérité      : **S0** bloquant
- Domaine       : interface / navigation
- Référence     : main e8924b8 (identique à 6c90194 sur `frontend/` et `backend/`)
- Emplacement   : `frontend/src/features/dashboard/components/ActivityFeed.tsx:19` et `:96` (`humanizeAction(log.action)`) · `backend/app/Models/AuditLog.php:15` · `backend/database/migrations/2026_05_16_000002_create_auth_tenant_audit_schema.php:198` · `backend/app/Http/Controllers/Api/AuditLogsController.php:29` · `frontend/src/main.tsx` (`createRouter` **sans** `defaultErrorComponent`)
- Constat       : la table `audit_logs` porte la colonne **`event_type`** ; `AuditLogsController::index` rend les lignes du modèle **telles quelles** ; le composant `ActivityFeed` de l'écran d'accueil lit **`log.action`**, qui n'existe donc **jamais**, et appelle `.replace()` dessus.
- Preuve        : sonde `04_PREUVES/agent-24/sondes/accueil-plante.mjs`, trois cas joués sur le **même** écran, résultat dans `accueil-plante.json` et trois captures :

  | charge servie à `GET /audit-logs` | barre latérale | `<main>` | ce que voit l'utilisateur |
  |---|---|---|---|
  | **0 ligne** | présente | présent | l'accueil normal |
  | **1 ligne réelle** (colonnes du modèle `AuditLog`) | **ABSENTE** | **ABSENT** | « **Something went wrong!** Cannot read properties of undefined (reading 'replace') » |
  | **1 ligne + le champ `action`** *(témoin)* | présente | présent | l'accueil normal |

  Et en base, sur ce poste : `select count(*) from audit_logs` → **34**.
- Témoin négatif: le **troisième** cas est le témoin, et il est décisif — **la même ligne, le même écran, le même instant**, avec le seul champ `action` ajouté : l'écran se monte normalement. La sonde sait donc distinguer « l'écran ne s'affiche pas » de « la charge est incomplète », et le fautif est nommé sans ambiguïté. Second témoin, côté code : `frontend/src/features/rgpd/AuditLogsPage.tsx:24` consomme la **même** route et lit, lui, **`event_type`** — le contrat correct existe dans le dépôt, il n'a pas été suivi par le second consommateur.
- Impact        : **c'est le premier écran du produit, et c'est là que la connexion dépose l'utilisateur.** Le middleware `AuditHashChainLogger` (`bootstrap/app.php:28`, groupe `api`) journalise **toute** requête `POST/PUT/PATCH/DELETE` — **`POST /auth/login` compris**. Donc : la toute première connexion écrit la ligne qui casse l'accueil ; à l'arrivée sur `/`, l'application entière disparaît, **barre latérale et en-tête compris** — il ne reste ni navigation, ni lien, ni message intelligible. Aucun autre écran n'est atteignable autrement qu'en tapant son adresse. En production, `audit_logs` se remplit **aussi** par les `POST /internal/site-sync` du site : le journal n'y sera pas vide au premier jour d'usage. **Ce défaut est invisible aujourd'hui pour la seule raison que personne ne s'est jamais connecté (A-012) et que l'agent 22 a mesuré API muette (liste vide).** Il se révélera **le jour même** où A07-001 et A-012 seront corrigés — c'est-à-dire au premier geste du chantier cible.
- Reproduction  : `docker exec axion-crm-postgres psql -U axion -d axion_crm -c "select count(*) from audit_logs"` (≥ 1), puis ouvrir `/`.
- Correctif     : **une ligne** — lire `log.event_type` (avec repli sur `log.action`) dans `ActivityFeed.tsx`, comme le fait déjà `AuditLogsPage.tsx`. **≈ 15 min.** Et, séparément, poser le filet : `defaultErrorComponent` sur le routeur (voir **D24-008**), sans quoi le prochain champ manquant produira exactement le même effacement. **Garde à poser** : un test qui rend l'accueil avec la charge **réelle** du contrôleur (une ligne du modèle `AuditLog`) et exige la présence de la barre latérale — la garde actuelle, s'il en existe une, ne rougit sur rien puisque le cas testé est la liste vide.
- Statut        : ouvert

### [D24-002] La cloche « Notifications » de l'en-tête n'a aucun gestionnaire de clic — le premier départ du parcours §23.4 n°1 est un bouton mort
- Sévérité      : **S1** grave
- Domaine       : interface / UX
- Référence     : main e8924b8
- Emplacement   : `frontend/src/components/layout/Header.tsx:62-70` · `backend/app/Http/Controllers/Api/NotificationsController.php:15,23,30` · `backend/routes/api.php:208-210`
- Constat       : l'`IconButton label="Notifications"` est rendu **sans prop `onClick`** ; le clic ne déclenche rien. Côté serveur, `GET /notifications` rend `['data' => []]` **codé en dur**, et `markRead` / `markAllRead` rendent **501**. Et **aucun fichier du frontend n'appelle `/notifications`**.
- Preuve        : lecture de `Header.tsx:62-70` (aucun attribut entre `label` et `variant`) ; sonde `parcours.mjs`, parcours **B1** : le clic sur la cloche **réussit** (1 clic compté), puis l'attente d'un `[role="dialog"] , [role="menu"] , [role="listbox"] , [data-state="open"]` **expire à 4 s** — `parcours-mesures.json`. `grep -rn "notifications" frontend/src --include=*.tsx | grep -v echo.ts` → **2 lignes, toutes deux des commentaires**.
- Témoin négatif: dans la **même** sonde, sur le **même** en-tête, `Control+K` ouvre bien la palette de recherche et le sélecteur `[role="dialog"]` la trouve (parcours **B5**, 2 clics mesurés) : la sonde sait donc reconnaître un panneau qui s'ouvre. Et `grep` trouve bien les `onClick` des trois autres boutons de l'en-tête (hamburger, recherche mobile, thème).
- Impact        : le §23.4 fait partir le parcours n°1 — celui qu'on fait le plus souvent — de « **Notification ou boîte de réception** ». **Les deux départs sont morts** : la cloche ne s'ouvre pas, et il n'y a pas de boîte de réception (A-006). Pire qu'une absence : la cloche **promet** un canal d'entrants qui n'existe ni à l'écran, ni dans la route, ni dans le canal temps réel — `subscribeWorkspaceNotifications` (`lib/echo.ts:85`) écoute pourtant bien un événement `notification.created`. Trois moitiés de mécanisme, aucune reliée.
- Reproduction  : ouvrir n'importe quel écran, cliquer la cloche.
- Correctif     : à court terme, **retirer la cloche** (0,25 j) — un bouton qui ne fait rien coûte plus cher qu'un bouton absent. À terme, c'est le chantier « ÉCHANGES » du §23.3 : boîte de réception + `GET /notifications` réel + panneau. Chiffrage hors de ce constat.
- Statut        : ouvert

### [D24-003] La fiche 360°, point de départ commun à six des treize parcours du §23.4, n'offre aucun bouton et aucun lien
- Sévérité      : **S1** grave
- Domaine       : interface / navigation
- Référence     : main e8924b8
- Emplacement   : `frontend/src/features/crm-console/PersonTimelinePage.tsx`
- Constat       : servie avec une personne complète, ses deux univers et une timeline non vide, la fiche rend son identité et sa chronologie — et **zéro `<button>`, zéro `<a href>`** dans son `<main>`.
- Preuve        : sonde `fiche360.mjs` → `fiche360.json` : `{"liens": [], "boutons": []}` pour `/console/personnes/pk-demo`, alors que le texte rendu est bien complet (« Marie DUPONT · Fiche 360° — tous les touchpoints connus de cette personne · Identité · Univers Business : Fiche présente · Timeline : 2026-08-18 Formulaire contact »). Capture `fiche360-_console_personnes_pk_demo.png`. Confirmé par le balayage des 32 routes (`recon-ecrans.json`).
- Témoin négatif: la **même** sonde, au **même** instant, relève **6 boutons** sur `/companies/1` (« Enrichir maintenant », « Exporter », « Marquer obsolète »…) et **12** sur `/console/contacts` : elle sait donc compter des boutons quand il y en a.
- Impact        : le §23.4 fait partir de « la fiche » **six** de ses treize parcours — consigner un appel, envoyer le devis, programmer un rappel, traiter un appel support, déplacer un candidat, retrouver ce qui a été dit. **Le point de départ de la moitié du contrat est une page en lecture seule dont on ne peut même pas revenir autrement que par la barre latérale.** Ce n'est pas « il manque six boutons » : c'est que l'écran n'a **aucun emplacement** prévu pour eux — ni barre d'actions, ni menu, ni pied de fiche. Le chantier cible devra donc **concevoir** la fiche, pas l'enrichir.
- Reproduction  : ouvrir `/console/personnes/<une clé>` avec une timeline non vide.
- Correctif     : hors périmètre d'un correctif ponctuel — c'est la pièce maîtresse du chantier §23.4. La décision préalable, elle, est immédiate et gratuite : **arrêter quel écran est « la fiche »** (voir §1.2 n°2).
- Statut        : ouvert

### [D24-004] Rattacher un lead à une entreprise exige de taper à la main son identifiant numérique interne — et c'est l'écran où stationnent 100 % des leads
- Sévérité      : **S1** grave
- Domaine       : interface / UX
- Référence     : main e8924b8
- Emplacement   : `frontend/src/features/crm-console/ArbitragePage.tsx`
- Constat       : sur `/console/arbitrage`, les deux seules actions — « Rattacher » et « Écarter » — sont rendues **désactivées** ; les deux seuls champs de saisie sont des zones de **texte libre**, dont l'une porte le placeholder **« ex. 1842 »**. Il n'y a **ni recherche, ni autocomplétion, ni sélecteur** d'entreprise.
- Preuve        : sonde `arrets.mjs` → `arrets.json` : `"arbitrage": [{"libelle":"Rattacher","desactive":true},{"libelle":"Écarter","desactive":true}]` et `"arbitrage champs": [{"type":"text","placeholder":"ex. 1842"},{"type":"text","placeholder":"ex. entreprise inexistante au RNE"}]`. Capture `arret-arbitrage.png`. Sonde `parcours.mjs`, parcours **A7** : arrêt à l'étape 3 après 2 clics, écran « Rapprochements à arbitrer » bien monté et bien peuplé (1 événement, « ACME », « Jean ACME », « x@y.fr », « 38000 GRENOBLE »).
- Témoin négatif: la **même** sonde relève, sur `/companies`, des boutons **actifs** (`"Importer",{desactive:false}` · `"Exporter",{desactive:false}`) et, après une case cochée, des boutons **désactivés** (`"Poser le tag",{desactive:true}`) : elle distingue donc bien les deux états, et le « désactivé » de l'arbitrage n'est pas un artefact de mesure.
- Impact        : **B13-001 mesure que 100 % des leads du site restent en arbitrage manuel** — aucun émetteur ne transmet de SIREN. Cet écran est donc le **goulot unique** de tout le canal entrant. Or il demande à l'opérateur une information qu'aucun écran du produit ne lui donne : l'`id` **interne** de l'entreprise cible. Pour le trouver, il doit ouvrir `/companies` dans un autre onglet, chercher l'entreprise, ouvrir sa fiche, lire l'identifiant dans **l'adresse**, revenir, le retaper. Le parcours réel n'est pas « 2 clics » : c'est **une navigation croisée entre deux onglets et une recopie manuelle, par lead**. Et une recopie manuelle sur un identifiant numérique est une **erreur d'attribution** en puissance : rien ne rougit si l'on rattache la personne à la mauvaise entreprise.
- Reproduction  : ouvrir `/console/arbitrage` avec au moins un rapprochement en attente.
- Correctif     : remplacer le champ libre par un sélecteur d'entreprise avec recherche (la route `GET /companies?q=` existe déjà et sert la liste — **on étend, on ne réinvente pas**), et afficher le nom retenu avant confirmation. **≈ 0,5 à 1 j.** À faire **avant** toute campagne de collecte, sinon la file d'arbitrage grossit plus vite qu'elle ne se vide.
- Statut        : ouvert

### [D24-005] Les filtres et la page ne sont pas dans l'adresse : le retour arrière renvoie l'utilisateur à la page 1, à chaque fiche ouverte
- Sévérité      : **S2** défaut
- Domaine       : navigation / UX
- Référence     : main e8924b8
- Emplacement   : `frontend/src/features/companies/CompaniesListPage.tsx` (état local `useState`, aucun `search` de route) ; même patron sur `/contacts`, `/media`, `/journalists`, `/scraper-runs`, `/console/contacts`
- Constat       : après un filtre et un changement de page, l'adresse reste **`/companies`**, sans aucun paramètre. Le retour arrière du navigateur restitue donc l'écran **dans son état initial**.
- Preuve        : sonde `parcours.mjs`, bloc **C1** → `parcours-mesures.json` :
  `urlApresFiltreEtPage3: "/companies"` · `etatAvant: { boutonsActifs: ["3"], "…Page 3 · 20 affichées…" }` · `urlFiche: "/companies/1"` · `urlApresRetour: "/companies"` · `etatApres: { boutonsActifs: ["1"], "…Page 1 · 20 affichées…" }` · **`etatConserve: false`**.
- Témoin négatif: la **même** sonde, dans la **même** exécution, constate que la navigation vers `/companies/1` **change** bien l'adresse et que le retour arrière **revient** bien à `/companies` : l'historique du navigateur fonctionne, et la sonde sait lire un changement d'adresse. Ce n'est donc pas l'historique qui est en cause, c'est **ce que l'adresse ne contient pas**.
- Impact        : le geste le plus courant d'un opérateur de liste — ouvrir une fiche, revenir, ouvrir la suivante — **coûte, à chaque itération, de refaire le filtre et de repaginer**. Sur une liste de 137 entreprises en 7 pages, travailler la page 3 devient un aller-retour de 3 clics supplémentaires **par fiche**. Trois conséquences de plus, mesurées : une page de résultats n'est **ni partageable ni marquable** ; le fil d'Ariane ne peut pas y ramener ; et le filet théorique — les vues enregistrées — n'existe pas non plus, `GET /saved-views` rendant **200 liste vide** au lieu de 501 (A-002 / B10-013). **Une confusion de navigation qui fait perdre l'utilisateur est au minimum S2.**
- Reproduction  : `/companies` → filtre « Prospectables » → page 3 → ouvrir une fiche → retour arrière.
- Correctif     : porter filtres, tri et page dans le `search` de la route TanStack (`validateSearch` + `useSearch`) sur les 6 écrans de liste. **≈ 1 à 1,5 j**, et cela rend au passage les vues enregistrées implémentables sans autre travail.
- Statut        : ouvert

### [D24-006] Dix-huit écrans sur vingt-six n'offrent aucun lien sortant, dont la liste des personnes et la fiche 360°
- Sévérité      : **S2** défaut
- Domaine       : navigation
- Référence     : main e8924b8
- Emplacement   : `frontend/src/features/**` — relevé exhaustif dans `04_PREUVES/agent-24/recon-ecrans.json`
- Constat       : sur les 26 écrans rendus sous doublure, **18** ne contiennent, dans leur `<main>`, **aucun `<a href="/…">`**. Quatre d'entre eux (`/rgpd/ai-act`, `/llm/rotations`, `/cold-email`, `/linkedin`) n'offrent **ni lien ni bouton**.
- Preuve        : sonde `recon.mjs`, 32 routes ouvertes une à une, comptage des `<a href>` et `<button>` **visibles** (rectangle non nul) dans le `<main>`. Résultat : `/contacts` → **0 lien** (seuls des `mailto:` et un `tel:`) et **0 bouton** pour 320 personnes affichées ; `/console/personnes/$key` → **0 / 0** ; `/console/contacts` → 0 lien, 12 boutons (**tous des onglets ou des filtres**) ; `/journalists`, `/coverage`, `/scraper-runs`, `/tags`, `/users`, `/settings`, `/audit-logs`, `/llm/*`, `/console/vivier`, `/console/arbitrage` → 0 lien.
- Témoin négatif: le **même** comptage rend **20 liens sortants** sur `/companies` et **20** sur `/international/roumanie`, et **1** sur `/companies/$id` (le retour vers la liste) : la sonde sait donc trouver un lien quand il y en a, et distinguer un écran ouvert d'un écran fermé.
- Impact        : le §23.4 suppose partout des rebonds — « la fiche visible à côté », « 1 clic → fiche CRM », « recherche → fiche ». **Le produit navigue presque exclusivement par la barre latérale**, qui est un accordéon à une seule section ouverte : **sortir d'un cul-de-sac coûte 2 clics** (déplier + cliquer), pour 21 entrées sur 22 (§7.2 de l'agent 23). Les deux cas les plus coûteux sont ceux du métier : une liste de **320 personnes dont aucune n'est ouvrable**, et une **fiche 360° sans porte**.
- Reproduction  : ouvrir `/contacts`, chercher à ouvrir une personne.
- Correctif     : à trancher écran par écran par le chantier cible ; le geste unitaire le moins cher et le plus utile est de rendre la **ligne de `/contacts` cliquable** vers la fiche de la personne — ce qui suppose d'avoir d'abord résolu A05-001 (la `person_key`).
- Statut        : ouvert

### [D24-007] Les deux tables d'activités et de motifs ne sont reliées à rien : aucune colonne du produit ne peut porter un motif
- Sévérité      : **S1** grave
- Domaine       : backend / conformité au plan
- Référence     : main e8924b8
- Emplacement   : `backend/database/migrations/2026_08_19_000002_crm_activites_et_motifs.php` · `backend/app/Crm/ActivitesEtMotifs.php`
- Constat       : les tables `crm_activites` et `crm_motifs` sont créées et semées ; **aucune migration du dépôt n'ajoute de colonne `motif_id` ni `activite_id` à quoi que ce soit**, et la migration de la pièce 2 ne touche **aucune** autre table.
- Preuve        : `grep -rn "motif_id\|activite_id" backend/database/migrations` → **0 occurrence**. `grep -n "ALTER TABLE activities" backend/database/migrations/2026_08_19_000002_*` → **0 occurrence**. Les deux tables existent bien en base (`\dt` → `crm_activites`, `crm_motifs`), et leurs seuls consommateurs dans tout `backend/app` sont la constante elle-même, le seeder, la migration et un test (I48-004, confirmé : `grep -rn "ActivitesEtMotifs\|crm_activites\|crm_motifs" backend/app` → **2 lignes, toutes deux dans le fichier de la constante**).
- Témoin négatif: le **même** `grep` sur la migration voisine `2026_08_14_000004` trouve bien **huit** `ALTER TABLE activities ADD COLUMN` (`person_key`, `kind`, `occurred_at`, `subject_type`…) : il sait donc repérer un rattachement quand il existe. Et `App\Crm\Taxonomy`, la taxonomie voisine, **est** servie par trois contrôleurs (`BulkController`, `CandidatesController`, `ContactsHubController`).
- Impact        : I48-004 disait « deux tables sans route, sans contrôleur, sans écran ». **C'est un cran plus profond que cela** : même en écrivant demain la route et l'écran, **il n'existe aucun endroit où ranger le motif choisi**. Le critère de réussite de l'étape 1a — « un appel se consigne en 1 clic **avec le bon motif** » — suppose donc, avant tout écran, une **migration** qui rattache le motif à l'échange. Cela concerne directement les parcours ⑧ et ⑨ du §23.4, dont c'est le cœur.
- Reproduction  : `grep -rn "motif_id" backend/`.
- Correctif     : une migration additive (`activities.motif_id`, `activities.activite_id`, clés étrangères, index) **avant** toute route. **≈ 0,25 j**, et c'est le préalable de tout le reste — mais la décision de **où** poser ces colonnes (sur `activities`, ou sur la table d'entretien qui n'existe pas encore) appartient au chantier cible et doit être prise **avant** d'écrire la migration, pas après.
- Statut        : ouvert

### [D24-008] Le composant qui devait rattraper les erreurs d'affichage est écrit, exporté, et monté nulle part — huit écrans mesurés effacent l'application entière
- Sévérité      : **S2** défaut
- Domaine       : interface / navigation
- Référence     : main e8924b8
- Emplacement   : `frontend/src/components/ui/ErrorBoundary.tsx` · `frontend/src/components/ui/index.ts:33` · `frontend/src/main.tsx` (`createRouter`, **sans** `defaultErrorComponent`) · `frontend/src/app/routeTree.tsx` (aucune route ne déclare `errorComponent`)
- Constat       : `ErrorBoundary` est défini et ré-exporté depuis l'index des composants ; **il n'est instancié dans aucun fichier**. Aucune route ne porte de `errorComponent`. Une erreur de rendu dans **n'importe quel** écran remplace donc **tout le document** par le texte interne du routeur, « Something went wrong! », **sans coquille, sans barre latérale, sans lien de retour**.
- Preuve        : `grep -rn "<ErrorBoundary" frontend/src` → **0 occurrence** (le fichier de définition et l'export sont les 2 seules mentions). Comportement observé sur **huit** écrans distincts au cours de mes sondes (`/`, `/campaigns/$id`, `/audiences`, `/audiences/new`, `/audiences/$id`, `/admin/observability`, `/console/contacts`, `/rgpd/requests`), chaque fois avec le même corps de page : « **Something went wrong! Hide Error** » + le message brut de l'exception. Le routeur émet lui-même l'avertissement : « *The following error wasn't caught by any route! At the very least, consider setting an 'errorComponent' in your RootRoute!* ».
- Témoin négatif: sur **sept** de ces huit écrans, la cause était **ma doublure d'API** et non le produit — je le dis, et c'est justement ce qui rend la mesure utile : elle établit que le **mécanisme d'effacement** est réel, reproductible et indépendant de l'écran. Le **huitième**, l'accueil, l'établit sur la charge **réelle du serveur**, avec témoin (**D24-001**). Second témoin : le dépôt sait faire — `CompanyDetailPage.tsx` gère bien son propre cas « Entreprise introuvable », et `ConsoleGate.tsx` gère bien `isPending` ; ce qui manque est le **filet global**, pas la compétence.
- Impact        : **il n'existe aucun plancher.** Un champ renommé côté serveur, une valeur nulle inattendue, une réponse partielle — et l'utilisateur perd **toute** l'application, pas seulement l'écran fautif. Il n'a alors ni navigation, ni message compréhensible, ni bouton pour réessayer ; son seul recours est de retaper une adresse. C'est le même patron que **D22-007** (l'écran « Page introuvable » livré et jamais branché) et que **A-011** (une garde qui existe et ne protège rien) : **le composant est écrit, le branchement manque**.
- Reproduction  : servir à `/audit-logs` une ligne sans champ `action`, ouvrir `/`.
- Correctif     : `defaultErrorComponent: <un écran d'erreur dans la coquille>` sur `createRouter`, et brancher `ErrorBoundary` autour de l'`Outlet` de `RootLayout` pour que la barre latérale survive. **≈ 0,5 j.** **Garde à poser** : un test qui provoque une erreur de rendu dans un écran et exige que la barre latérale soit **toujours** présente.
- Statut        : ouvert

---

## 5. CE QUE JE N'AI PAS PU VÉRIFIER, ET POURQUOI

Ce n'est pas un aveu : c'est la partie du travail qu'un autre agent, ou Will, doit reprendre.

1. **Tous les parcours sur une session réelle.** Cause mesurée par l'agent 22 et confirmée au
   dossier : **A07-001** (le serveur exige une 2FA dont l'enrôlement écrit trois colonnes
   inexistantes) et **D22-001** (aucun écran n'enrôle la 2FA). **Toute la colonne « clics
   mesurés » de ce rapport porte donc sur le code de `main` servi par `vite`, avec les réponses
   d'API doublées** — jamais sur la pile Docker authentifiée. Ce qui en découle : je mesure les
   **chemins**, pas les **droits**, ni les **temps de réponse réels** (A-009 / A-010).
2. **Les six écrans non rendus** (`/campaigns/$id`, `/audiences`, `/audiences/new`,
   `/audiences/$id`, `/admin/observability`, `/rgpd/requests`) : mes charges d'essai n'ont pas
   trouvé leur forme exacte. **Je ne les compte ni comme sains ni comme fautifs.** Leur mesure
   demande soit une session réelle, soit la lecture ligne à ligne des trois contrôleurs concernés.
3. **Le parcours A8** (relancer un run échoué). Le bouton « Échec » est **présent et actif**
   (`arrets.json`) ; c'est mon sélecteur qui a échoué. **Je ne conclus rien**, ni dans un sens ni
   dans l'autre.
4. **Le glisser-déposer** exigé par le parcours ⑩. Je n'ai mesuré que l'absence de **bouton** de
   changement d'étape et l'absence de **route de mutation** ; je n'ai pas tenté un
   glisser-déposer, faute d'objet à saisir (aucun kanban n'est rendu).
5. **Le rendu et les budgets sur téléphone**, que le §23.4 exige à l'identique (« chaque parcours
   tient au même budget sur téléphone, aux gestes tactiles près »). **Non mesuré** : toutes mes
   sondes sont en 1440 × 900. La barre en tiroir et la barre basse mobile restent à mesurer.
6. **La production.** Aucune écriture, aucune session ouverte. En particulier, **je n'ai pas
   vérifié le nombre de lignes d'`audit_logs` en production** — ce chiffre décide si **D24-001**
   se manifestera dès la première connexion ou seulement après le premier `POST`. Le
   `AuditHashChainLogger` journalisant aussi les `POST /internal/site-sync` du site, je fais
   l'hypothèse — **non vérifiée** — qu'il n'est pas nul. **C'est le premier geste à faire.**
7. **Le lien inverse console axionia → CRM.** Mesuré côté CRM seulement (0 occurrence
   d'« axionia »). Le côté console est couvert par D23-011 et E32-006, que je n'ai pas rejoués.

---

## 6. MÉNAGE

Le serveur `vite` du port 5224 a été **arrêté**. Aucun conteneur n'a été créé. **Aucun fichier du
produit n'a été modifié** — `git status` ne rend, sous mon nom, que les preuves de
`04_PREUVES/agent-24/`. Le worktree `crmpro-wt-etape1a` n'a **jamais** été lu ni touché. **Aucune
écriture en production.**
