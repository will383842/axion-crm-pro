# AGENT 48 — APTITUDE AU CAHIER DES CHARGES

> **Verdict de sortie du §12-15 du mandat.** Périmètre : les **26 chapitres** du cahier des
> charges fonctionnel v2.7 (`axion-ia-crm-cahier-des-charges-fonctionnel-v2.md`, 984 l.),
> plus les annexes qui les commandent (§A.1, §0, §27, §29).
>
> **Référence** : `main = e8924b8`, re-mesurée le 2026-08-19 (`git log -1` joué, pas cru).
> Code **identique à `c0c453d`** — les trois PR de la session parallèle n'ont touché que deux
> documents et un script (A-008).
>
> **Méthode** : travail **documentaire et de lecture de code**. La pile locale n'a pas été
> sollicitée (A-009 : mono-processus, saturée). Aucune écriture, aucun fichier produit modifié,
> le worktree `crmpro-wt-etape1a` n'a pas été approché.
>
> **Ce que je ne re-mesure pas** : tout ce qui est déjà au registre (`02_CONSTATS.md`). Je le
> **reprends et je le relie**. Mon travail est de synthèse : je réponds à la question telle que
> le mandat l'a reformulée le 19/08 —
> *« sur quoi le chantier en cours est-il en train de bâtir, et qu'est-ce qui va lui casser
> dans les mains ? »*
>
> **Preuves** : `04_PREUVES/agent-48/` (5 fichiers, commandes rejouables).

---

## 0. Ce que ce rapport ajoute, et ce qu'il ne fait que relier

Ce rapport n'est pas un vingtième relevé de défauts. Il tient **une colonne que personne
d'autre ne tenait** : *ce qui devra être **DÉFAIT***.

C'est la colonne la plus chère, parce qu'elle n'apparaît dans aucun décompte d'avancement.
Un manque se comble : on ajoute. Une **contradiction** se paye deux fois — il faut retirer du
code écrit, testé, gardé par une CI bloquante, puis écrire autre chose à sa place, sans casser
les 1,3 M de fiches et les 4,29 M d'organisations qui vivent dessus.

**Quinze objets du code contredisent aujourd'hui la cible.** Ils sont recensés au §3 et
chiffrés au constat **I48-005**. Trois d'entre eux sont sous la main du chantier en cours.

---

## 1. LA GRILLE — les 26 chapitres, colonne par colonne

Légende : ✅ existe et sert · 🟡 amorce réelle · ❌ rien · 🔴 contredit la cible.

---

### §1 — Contacts et organisations

| | |
|---|---|
| **Ce qui existe** | `contacts` (1 319 567) et `companies` (4,29 M) ; `person_key` **en colonne** sur `contacts`, `candidates`, `activities`, `crm_outbound_events` ; `field_origins` (source et date par champ, §1.2) ; cascade d'enrichissement d'organisation réelle (`POST /companies/{id}/enrich`, `/bulk-enrich`) ; détection de doublons (`DeduplicationService`) **et écran d'arbitrage** (`ArbitragePage`, `/crm/arbitrage/*`) ; tags gouvernés avec `namespace` généré et `is_locked` ; export de vue sous `permission:data.export` ; masquage PII (`contacts.view_pii`) ; `CompanyDetailPage`, `ContactsListPage`, `PersonTimelinePage`. |
| **Ce qui manque** | **La création d'une fiche personne** (aucune route `POST /contacts` — I48-001) ; la modification et la suppression (**501**) ; coordonnées **multiples** ; civilité, photo, fonction ; champs personnalisés ; source d'acquisition ; langue et fuseau ; **identifiant lisible** et **numéro client** ; propriétaire de fiche ; statut de veille (actif / pause / archivé) ; liens entre organisations ; relations entre contacts ; fusion non destructive **annulable** ; import par fichier ; indicateur de complétude ; **modification sur place** ; édition concurrente ; historique des modifications ; correction qui redescend vers la console. |
| **🔴 À DÉFAIRE** | **(1)** `relation_type` et `lifecycle_stage` sont des colonnes de **`companies`** et de **`candidates`** — jamais de la personne. **(2)** `contacts.company_id` est **`NOT NULL`** : une personne sans organisation est physiquement impossible (I48-003). **(3)** `contacts.last_name` est **`NOT NULL`** : un champ obligatoire bloquant, contre le principe 5 et le §1.6. **(4)** Cinq tables de personnes séparées — `contacts`, `candidates`, `journalists`, `health_practitioners` — donc « une personne = une fiche » (principe 1) est **structurellement impossible**. **(5)** `ContactsHubController` prend l'**entreprise** pour unité de ligne, par choix documenté. **(6)** **0 contact sur 1 319 567 porte une `person_key`** (A05-001, S1) : la colonne existe, le backfill n'existe pas. |

---

### §2 — Types, activités, motifs, parcours

| | |
|---|---|
| **Ce qui existe** | `App\Crm\Taxonomy`, source de vérité unique, listes **fermées par des `CHECK` SQL** que `Feature\Crm\SocleCrmTest` compare aux contraintes **réellement en base** — c'est une **vraie** garde, qui rougit sur le bon objet. Les 4 familles de candidats du §2.1 sont conformes au mot près. `BUSINESS_LIFECYCLE_STAGES` (6 valeurs). **Et la pièce 2 de l'étape 1a, livrée le 19/08** : `crm_activites` et `crm_motifs`, les **5 activités** d'AXION IA avec le drapeau `qualiopi` sur la formation seule, les motifs non commerciaux, semées en `insertOrIgnore` — le **piège 22 est paré**, et un test vérifie qu'un libellé modifié depuis la console **survit** au déploiement suivant. |
| **Ce qui manque** | Multi-types sur une fiche ; type principal ; union des champs ; une étape par type ; **offres, campagnes, opportunités** (`deals` est morte et n'a **aucun** rattachement à une activité, que le §2.4 rend obligatoire) ; parcours configurables (`crm_pipelines`, `pipeline_stages` mortes) ; vue kanban ; motif obligatoire à la sortie négative ; **route, contrôleur et écran** pour les activités et motifs (I48-004) ; badges d'activité sur la fiche ; passage prospect → client automatique — **4 295 349 fiches sont à `nouveau`, le cycle de vie n'a jamais changé d'état** (A05-004). |
| **🔴 À DÉFAIRE** | **(7)** `Taxonomy::BUSINESS_RELATION_PRIORITY` — « une fiche porte **TOUJOURS** le type le plus engageant qu'elle a atteint ». C'est l'**exact contraire** du §2.2, et c'est **écrit, testé, gardé**. Le dirigeant a **remis le multi-types au périmètre le 19/08** (D-005) : cette constante devient donc du code à retirer, pas une préférence à discuter. **(8)** `conference` et `newsletter` sont des **types** dans le code ; le CDC en fait un **motif** (§2.3) et un **événement de capture** (§22.2). **(9)** Quatre types du §2.1 n'existent pas — *non qualifié*, *prospect froid*, *contact professionnel*, *autre* — alors que **la totalité des 4,29 M de fiches en production est, au sens du CDC, du *prospect froid***. |

---

### §3 — Historique unifié, interactions, **dossiers**

| | |
|---|---|
| **Ce qui existe** | `activities` étendue (`kind`, `occurred_at`, `subject_type`, `subject_id`, `title`, `payload`, `person_key`), cloisonnée et indexée ; `PersonTimelineController` **agrège les deux univers par `person_key`** et n'y copie aucun contenu — c'est la bonne conception ; `PersonTimelinePage`. |
| **Ce qui manque** | Aperçu dépliable, filtres cumulables, recherche plein texte dans la fiche, regroupement par mois, épinglage, **résumé de la relation**, note libre, mention `@collègue`, suivre une fiche, **export de l'historique** ; **l'objet interaction du §3.2 n'existe pas** (canal, durée, participants, réponses, notation, ressenti, actions) ; **les dossiers du §3.1 bis n'ont aucune table** — et le dirigeant les a **séquencés, pas reportés** (D-005). |
| **🔴 À DÉFAIRE** | **(10)** `Taxonomy::ACTIVITY_KINDS` est un vocabulaire d'**événements du site** : `form_submission`, `calendly_*`, `review_posted`, `scraped`… **Pas un seul mot d'interaction humaine** — ni appel, ni e-mail, ni réunion, ni note, ni entretien. Le critère de réussite de l'étape 1a est « *un appel se consigne en 1 clic avec le bon motif* » : **il n'y a pas de `kind` pour un appel.** Et la liste est fermée par un `CHECK` : l'étendre est une migration **plus** une garde à revoir. **(11)** `activities.type` (texte libre) coexiste avec `kind` — phase « expand » d'un *expand → migrate → contract* jamais contractée. |

---

### §4 — Questionnaires · §5 — Notes, notation, ressenti · §6 — **L'écran d'entretien**

| | |
|---|---|
| **Ce qui existe** | **Rien.** Zéro table, zéro route, zéro écran, zéro constante. Mesuré : `questionnaires`, `trames`, `grilles`, `interactions` — **0 migration, 0 fichier applicatif**. |
| **Ce qui manque** | Tout. Éditeur de trames, formats, sections, question liée à un champ de fiche, versionnement, logique conditionnelle, tronc commun, remplissage hors connexion ; les trois couches du §5 ; **et l'écran d'entretien du §6, dont le CDC dit qu'il est « l'écran qui justifie le produit »** — exigé en consignation manuelle **dès l'étape 1a**. |
| **🔴 À DÉFAIRE** | **Rien — et c'est une bonne nouvelle qu'il faut dire.** Ces trois chapitres sont du terrain vierge : aucune contradiction à payer, aucun code à retirer. Un seul point d'attention, à câbler dès la conception et non après : le §5 impose que **le ressenti figure dans l'export de la personne** — or l'export RGPD ne couvre aujourd'hui que **4 tables** (B10-004, S0). |

---

### §7 — Visioconférence · §8 — Appels

| | |
|---|---|
| **Ce qui existe** | Rien, dans les deux cas. |
| **Ce qui manque** | §7 : la brique auto-hébergée entière (étape 5). §8.1 : **la consignation manuelle d'un appel — qui est dans l'étape 1a** et dont dépend le critère de réussite de l'étape. §8.2 : le raccordement (étape 6). |
| **🔴 À DÉFAIRE** | Rien d'écrit. Mais **une exigence du §7 est déjà contredite par l'infrastructure** : « la brique tourne sur un serveur distinct ou avec des ressources réservées — *une visio ne doit jamais ralentir la fiche* ». Sur une production qui **sérialise toutes ses requêtes** (A-010, S0), cette exigence ne peut pas être tenue, quel que soit le serveur choisi. |

---

### §9 — Messagerie et e-mails

| | |
|---|---|
| **Ce qui existe** | `email_suppressions` alimentée par le webhook ZeptoMail (rebonds durs, plaintes) ; `opt_out` avec `email_hash` et `scope` (business / vivier) ; `EligibiliteCampagne` qui consulte les deux avant tout envoi ; les 7 clés SMTP ZeptoMail **complètes et valides** en production. |
| **Ce qui manque** | **La réception d'e-mail** — le préalable que le §9 nomme lui-même, et qui n'existe dans aucun des deux outils ; la boîte unifiée ; le rattachement ; les modèles et champs de fusion ; les relances individuelles ; le fil continu ; le lien de désabonnement. |
| **🔴 À DÉFAIRE** | **(12)** **Deux listes de suppression** (`opt_out` **et** `email_suppressions`) là où le §9.0 en exige **une seule**, consultée par les trois voies et alimentée par les trois. **(13)** `email_messages` et `email_sequences` : tables mortes du même échafaudage. **(14)** `MAIL_MAILER` **n'est écrit nulle part** et retombe sur `log` par défaut de framework (F40-002). La décision « rester à `log` » est **celle du dirigeant** et n'est pas rouverte (D-005) — mais une décision **implicite** n'est pas relisible, et c'est elle qui a coupé, sans que personne le voie, les courriels **d'authentification** (A-012, S0). |

---

### §10 — Formulaires publics · §11 — Collecte

| | |
|---|---|
| **Ce qui existe** | §10 : le canal entrant **reçoit déjà** les 14 finalités du formulaire du site, et c'est le point le plus solide du produit (agent 13). §11 : le module de collecte entier — 4,29 M d'organisations, `scraping_sources` (registre), campagnes, journaux, arrêt d'urgence, `opt_out` consulté **avant** import, backfill des tags `src:` sur **4 294 895 lignes** au chiffre près, distinction *actifs* / *froids* déjà mécanisée dans le hub. |
| **Ce qui manque** | §10 : le constructeur de formulaires, les **règles d'attribution** (étape 1a), l'accusé de réception, le dépôt de fichiers. §11 : le type *prospect froid* ; **l'information de l'article 14** (`first_info_at` : deux colonnes livrées, **aucun écrivain ni lecteur**, 0 ligne renseignée — A05-002) ; la lecture du `robots.txt` (C19-004, **zéro occurrence**) ; la limite par domaine cible (C19-005) ; la base juridique et la date de revue **par source** dans le registre. |
| **🔴 À DÉFAIRE** | **(15)** `ScrapingSourcesSeeder` fait un **`upsert` depuis deux migrations** et réécrit six colonnes du référentiel à chaque déploiement (B10-011) — c'est le piège 22 en flagrant délit, et il annule la promesse « réglable depuis la console ». À quoi s'ajoutent, non structurels mais bloquants : la base légale sans mise en balance écrite pour **1 319 567 personnes** (C19-007, **S0**) et le déguisement des collecteurs **sans ADR** (C19-006). |

---

### §12 — Rendez-vous · §13 — Tâches, rappels, notifications

| | |
|---|---|
| **Ce qui existe** | §12 : la **réception** des 4 événements Calendly, prouvée. §13 : deux tables, `notifications` et `crm_tasks` — **et rien d'autre**. |
| **Ce qui manque** | §12 : page de réservation, plages, types de rendez-vous, fuseaux, confirmations, rappels e-mail **et WhatsApp**, réservation d'équipe, absence, synchronisation de calendrier, vue calendrier. §13 : **tout** — tâches, rappels par règles, vue « aujourd'hui » (étape 1a), notifications applicatives, **notifications Telegram groupe / privé (étape 1a)**. |
| **🔴 À DÉFAIRE** | **(16)** `notifications` a **0 écrivain, 0 lecteur et 3 suppresseurs** ; son contrôleur rend `{"data": []}` **en dur** et ses deux autres verbes **501**. `crm_tasks` n'a ni `dossier_id`, ni récurrence, ni règle de rappel. **Et l'inventaire de l'étape 1a — le document qui fixe l'ordre des pièces — déclare `notifications` « vivante, 10 fichiers applicatifs »** (I48-006). **(17)** « Telegram » apparaît **deux fois** dans tout le backend, et **les deux fois dans un commentaire** : `// Sprint 11 : send TelegramAlert::dispatch(...)`. |

---

### §14 — Documents · §15 — Devis et opportunités · §16 — Suivi après-vente · §17 — Enregistrements et analyse

| | |
|---|---|
| **Ce qui existe** | §14, §16, §17 : **rien**. §15 : rien côté CRM — **et c'est conforme**, le §15 veut que le devis naisse et vive dans la console. §17, à réutiliser : `llm_use_cases`, `llm_usage`, le routeur LLM et son plafond sont **exactement le patron** du plafond de coût par minute du §17.2 — on étend, on ne réinvente pas. |
| **Ce qui manque** | §14 : documents, catégories, versions, modèles, **nomenclature `CRM_<canal>_<nom>_<date>`**. §15 : opportunités, reflets de devis et factures, numéro client, liens croisés, relances déclenchées par un événement. §16 : dossiers de suivi, jalons, réclamations. §17 : lecteur, transcription synchronisée, repères, analyse, sous-traitant contractualisé, plafond. |
| **🔴 À DÉFAIRE** | **(18)** `deals` et `deal_history` : mortes, et `deals` n'a **aucun rattachement à une activité** — le §2.4 le rend obligatoire. `deal_history` porte en outre des données personnelles **sans colonne d'espace** (B11-007). **(19)** Le registre AI Act du §21.4/§17 est **vide (0 ligne)** et son contrôleur rend `['data' => []]` **en dur, sans une requête SQL** (B16-007) — alors que le CDC affirme qu'il « existe déjà ». |

---

### §18 — Recherche, vues, pilotage

| | |
|---|---|
| **Ce qui existe** | Le hub de listes avec ses filtres, son tri à **liste fermée** (un tri libre = un scan de 4,29 M de lignes) et ses **compteurs** — **c'est la pièce 1 de l'étape 1a**, mesurée, indexée (`2026_08_19_000001`), servie par `Cache::flexible` ; `/companies/export`, `/media/export`, `/journalists/export` ; `ObservabilityPage`. |
| **Ce qui manque** | Les vues nommées ; la **vue « Échanges »** ; les tableaux de bord par type **et par activité** ; le commutateur explicite d'inclusion de la base froide ; la recherche par numéro client et identifiant de fiche. |
| **🔴 À DÉFAIRE** | **(20) Quatre façades qui répondent 200 sans jamais toucher leur table** : `GET /search` rend `{companies:[], contacts:[], tags:[]}` **codé en dur** — et la route est **déclarée deux fois** (I48-002) ; `GET /saved-views` rend une liste vide quand ses quatre sœurs rendent 501 (A-002) ; `GET /dashboard/stats` est une fermeture *inline* qui rend des zéros ; `GET /ai-act/register` idem (B10-013). **La barre ⌘K du SPA appelle bien `/search`** : la recherche du §18.1 est branchée sur du vide. |

---

### §19 — Console d'administration du CRM

| | |
|---|---|
| **Ce qui existe** | `/settings` — **quatre onglets** : Workspace, Intégrations, Observabilité, Apparence. `/users` (index seul). `/tags` (référentiel gouverné, réel). |
| **Ce qui manque** | **Les huit sous-groupes du §19 et presque tout leur contenu** : types de contacts, champs personnalisés, parcours et motifs de sortie, questionnaires, grilles, modèles, types de rendez-vous, règles de rappel, rôles et permissions, espaces de travail, durées de conservation, textes légaux, sources de collecte, plafond d'analyse, corbeille, **état du canal**. Plus : aperçu avant application, « annuler », **recherche dans les réglages**, palette de commandes, assistant de première configuration, page « ce qui n'est pas encore réglé », **paramétrage exportable et importable**. → **critère 2 et critère 20 du §29 : NON TENUS.** |
| **🔴 À DÉFAIRE** | **(21)** Le mot **« console »** désigne déjà autre chose dans le produit : `/console/contacts`, `/console/vivier`, `/console/arbitrage`, `/console/personnes/…` — le **hub de contacts**. Le glossaire du CDC prend pourtant la peine d'avertir : « Console du CRM — l'espace de **paramétrage** (§19), à ne pas confondre ». Le critère 24 exige qu'« aucun libellé n'ait de synonyme ailleurs dans le produit ». Le conflit est **déjà là**, avant le premier écran de réglage (I48-007). |

---

### §20 — Utilisateurs et sécurité

| | |
|---|---|
| **Ce qui existe** | Comptes, rôles et permissions (Spatie), double authentification, sessions, **journal d'audit haché en chaîne** avec `AUDIT_HASH_CHAIN_SECRET` de 64 caractères **en production** (B16-001 réfuté pour la prod), sauvegardes avec **exercice de restauration daté du 16/08**, RLS forcée sur **55 tables**. |
| **Ce qui manque** | L'invitation par e-mail ; la **création, la modification et la suppression** d'un utilisateur (**501** toutes les trois) ; la **connexion unique** avec la console axionia (§22.5) ; la délégation ; la permission « franchir la frontière entre espaces » ; la journalisation des **consultations et des exports** — **50 routes `GET` sur 111 sont muettes**, dont les quatre exports de données nominatives (B16-008). Le §20 exige sept gestes de cycle de vie des comptes : **aucun n'existe**. |
| **🔴 À DÉFAIRE** | **(22)** `audit_logs` et ses **14 partitions** n'ont **aucune RLS**, et `GET /audit-logs` rend le journal de **tous les espaces** à **tout compte authentifié** (B16-004, **S0**) ; `AuditLogPolicy::viewAny` **n'est jamais appelée**. Le journal est **tronquable par la queue** sans rompre la chaîne (B16-002, S0) et `created_at` **n'entre pas dans le hachage** (B16-003, S0). Trois S0 dans le mécanisme même que le §20 désigne comme la preuve. |

---

### §21 — Données personnelles et conformité

| | |
|---|---|
| **Ce qui existe** | `RgpdRequestsController` (export et effacement) ; **la portabilité par jeton haché**, 48 caractères, 7 jours, 404 sur jeton inconnu — sortie du groupe authentifié le 19/08, ce qui la rend enfin utilisable par son unique destinataire ; `opt_out` par **empreinte** qui survit à l'effacement ; **AIPD 2026-08-18 v2.0** en vigueur ; registre des violations écrit. |
| **Ce qui manque** | La **mise en balance écrite** de l'intérêt légitime et **l'information de l'article 14** pour **1 319 567 personnes** (C19-007, **S0** — l'AIPD le dit elle-même : « *il n'est écrit nulle part* ») ; la table `data_processing_log` du registre article 30, **créée par aucune migration** ; les durées de conservation **par catégorie**, réglables ; le registre AI Act réel ; l'information du candidat sur l'analyse automatique. |
| **🔴 À DÉFAIRE** | **(23)** L'**export** RGPD couvre **4 tables** et l'**effacement 8**, sur ~40 porteuses de données personnelles — **`candidates` n'est dans ni l'un ni l'autre** (B10-004, **S0**). **(24)** `erasure` traverse le canal, le site répond « 200 applied », et **rien n'est effacé** (B14-002, **S0**). **(25)** `rgpd:anonymize-ips` n'a **jamais fonctionné** : son SQL ne compile pas (A08-006). **(26)** Les deux seules purges RGPD correctement construites **ne s'exécutent jamais** (B17-009) : l'échéance CNIL n'est tenue par aucun automatisme. |

---

### §22 — Contrat d'échange CRM ↔ console axionia

| | |
|---|---|
| **Ce qui existe** | **Le sens site → CRM, et il est exemplaire.** HMAC SHA-256 sur `"<horodatage>.<corps brut>"`, `hash_equals`, vérifié **avant** le drapeau ; **1 témoin positif et 4 témoins négatifs joués** (signature falsifiée, en-tête absent, corps altéré, horodatage altéré → 401, **0 écriture**) ; fenêtre ±300 s mesurée à −400 et +400 s ; idempotence par `event_id` **adossée à un index UNIQUE réel** ; **cloisonnement irréprochable** — le workspace n'est *jamais* dans le contrat, absent → **503 et 0 écriture**. C'est le meilleur point du produit. |
| **Ce qui manque** | Le sens inverse, presque entier : **2 événements sur 19**, **5 familles sur 6 sans aucun émetteur** ; la file morte n'est visible sur **aucun écran** et n'est **jamais reprise** ; **aucune alerte, dans aucun des deux dépôts** ; le batch de réconciliation **promis en commentaire** n'existe pas ; le **tableau de bord du canal** du §22.7 n'existe dans aucune des deux consoles ; le contrat est versionné **à l'entrée, pas à la sortie**, et **aucun test ne croise les deux dépôts** ; le symétrique Calendly ne redescend pas, alors que le §22.6 promet « le statut redescend tout seul ». |
| **🔴 À DÉFAIRE** | **(27)** `crm_outbound_events` est **mono-destination** : table **sans `workspace_id`**, **RLS absente** (mesuré `f\|f`), `scope` **codé en dur `'business'`**, **une seule URL**. Le §22.5 exige que le CRM reste **détachable** et le §0.8 qu'il serve **plusieurs sociétés dès le premier jour** : ce canal est un couplage dur à un seul site. **(28)** `person_key` est prévu dans le contrat, **jamais renseigné**, et **jeté par le site** (B14-012). Et **aucun émetteur du site ne transmet de SIREN, aucun formulaire n'en collecte** (B13-001, S1) : le canal ne peut **structurellement pas** créer une fiche — **100 %** des leads restent en arbitrage manuel. |

---

### §23 — Design et expérience · §24 — Aptitude à grandir

| | |
|---|---|
| **Ce qui existe** | §23 : le système de design existant, mode sombre, composants — et **la barre latérale a déjà été refondue** (étape 0) : 6 sections dans l'ordre de la journée, **une seule** entrée Contacts, « Collectes » et « Journaux de collecte », **plus aucune entrée verrouillée**, accordéon qui suit la page, entrée « Vivier » masquée si l'utilisateur n'est pas membre de l'univers. **8 des 10 « défauts » que le mandat croyait ouverts sont corrigés** (A-006). §24 : espace de travail cloisonné dès l'origine, RLS sur 55 tables, **jeu au volume de référence versionné et rejouable** (300 000 et 2 800 000 fiches), index concurrents, pagination. |
| **Ce qui manque** | §23 : **le groupe ÉCHANGES en entier**, Boîte de réception, Mes rendez-vous, Mes tâches, les vues épinglées par type, Organisations, Prospection, **Canal avec la console**, **Coûts**, le lien **↗ Console axionia**, **Fiches récentes**, **tous les compteurs**, la barre basse sur téléphone, la teinte par espace, la palette de commandes, le fil d'ariane. §24 : **l'interface de programmation documentée et versionnée**, les jetons par intégration révocables et journalisés, les **webhooks sortants signés**, le multilingue. |
| **🔴 À DÉFAIRE** | §23 : `/console/*` (voir §19) ; `/cold-email` et `/linkedin`, joignables par URL, F8 **à moitié close** (A-005, D-008) ; le groupe « Pilotage » porte *Audiences* et *Observabilité* là où le §23.3 y met *Tableaux de bord · Canal · Coûts* ; quatre entrées d'outillage de collecte cohabitent avec les réglages. §24 : **A-010** — la production sert l'API par `php -S`, **un seul processus** ; le **principe 8** et le **critère 17** sont **inatteignables par construction**. Plus : 26 tâches planifiées sur 33 et 5 jobs sur 6 **sans contexte d'espace** (B11-001/002, **S0**), 15 tables `workspace_id` **sans clé étrangère**. |

---

### §25 — Migration, réversibilité, coûts · §26 — Hors périmètre

| | |
|---|---|
| **Ce qui existe** | §25 : sauvegardes **restaurables**, exercice daté du 16/08 ; exports par entité ; le drapeau `crm.console_v2` est un **vrai chemin de retour** ; `llm_usage` comme patron de ligne de consommation. §26 : le respect est **réel** — pas de facturation dans le CRM, aucun SDK Stripe, aucune séquence de masse. |
| **Ce qui manque** | §25 : le rapprochement et la fusion de la section « Contacts » de la console ; le rejeu de l'historique (Calendly, soumissions, e-mails) ; **les quatre paliers réversibles du §25.1** ; le débrayage du canal ; les lignes de consommation et leurs plafonds. |
| **🔴 À DÉFAIRE** | §25 : `dr-drill.sh`, `backup-postgres.sh` et `verifier-sauvegarde.sh` sont **encore en CRLF** (A-003) — les scripts du jour où ça va mal, inexécutables tels quels ; la sauvegarde **restaure les données mais pas les droits** (A08-008) ; `_AUDIT/DEPLOY-PIPELINE.md` décrit une commande qui n'est pas celle qui tourne (A09-001). §26 : **(29)** c'est le **seul endroit où le produit dépasse le périmètre au lieu d'y manquer** — `/cold-email`, `/linkedin` et le constructeur d'audiences (`/audiences`, `/audiences/new`, `AudienceBuilderPage`, `audience_members`, 8 routes) **sont le lot L7**, que le §26 exclut nommément et que le §9.0 réserve à un moteur d'envoi dédié sur un domaine dédié (I48-008). |

---

### ANNEXES QUI COMMANDENT LES 26 CHAPITRES

| Annexe | État |
|---|---|
| **§0 — les dix principes** (« ils priment sur toute fonctionnalité prise isolément ») | **Trois sont structurellement violés.** Principe **1** (« une personne = une fiche ») : cinq tables de personnes séparées, 0 `person_key` renseignée. Principe **7** (« le produit s'explique tout seul ») : **personne ne s'est jamais connecté au CRM en production** (A-012, S0) — le principe n'a jamais été éprouvé par un usage. Principe **8** (« dix utilisateurs dès le premier jour ») : `php -S` mono-processus (A-010, S0). Principe **4** (« paramétrage sans code ») : la console du §19 n'existe pas. Principe **5** (« aucun champ obligatoire bloquant ») : `contacts.last_name NOT NULL`. |
| **§A.1 — les 15 fragilités** | Aucune n'est ✅ pleinement close. F5 est la plus avancée (politique écrite, 0 PR). F1, F3, F4, F6, F8, F12, F14 sont ⚠️ partielles. **F9 est ❌ NON TENUE** et a régressé sur `audit_logs`. Détail : `03_MATRICE-EXIGENCES.md` §A. |
| **§27 — l'ordre de construction** | L'étape 0 n'est **pas close** ; l'étape 1a a **commencé** (2 pièces). Le §28.1 dit : « il ne commence pas une étape avant que la précédente ne satisfasse ses critères ». **Le mode de réalisation exigé est donc déjà en écart** — c'est un constat de fait, pas un reproche : c'est le dirigeant qui a lancé l'audit *pendant* pour cette raison. |
| **§29 — les 25 critères** | 9 hors périmètre (étapes non ouvertes) ; **6 mesurés NON TENUS** — n°2, 4, 17, 18, et j'ajoute **n°1** (la recherche rend du vide) et **n°6** (`PUT /contacts` = 501) que la matrice classait « ⏳ en cours ». Les 10 autres restent ⏳. |
| **§28.3 — « une garde ne vaut que si elle rougit sur l'objet qui casse »** | **Six cas indépendants** de gardes qui mesurent le mauvais objet (A-011, S1), plus trois qui ne mesurent rien. C'est le constat qui **explique** tous les autres. |

---

## 2. LE VERDICT PAR DOMAINE — sept phrases

Le mandat a **retiré** le feu vert global et demandé un verdict **par domaine**. Le voici.
Chacun est adossé à des constats nommés ; aucun n'est une impression.

> **① DONNÉES — le chantier bâtit sur un modèle qui dit le contraire de sa cible.**
> Le type vit sur l'**organisation** et non sur la personne, une fiche ne porte **qu'un seul**
> type « le plus engageant » (`BUSINESS_RELATION_PRIORITY`), une personne ne peut exister ni
> sans entreprise (`company_id NOT NULL`) ni sans nom (`last_name NOT NULL`), cinq tables de
> personnes coexistent, **0 contact sur 1 319 567 porte une `person_key`** (A05-001) —
> et le CRM **n'a même pas de route pour créer une fiche personne** (I48-001, I48-003).

> **② CANAL — entrant exemplaire, sortant absent, et l'entrant ne crée rien.**
> Le sens site → CRM est la meilleure pièce du produit (HMAC vérifié avant le drapeau,
> 1 témoin positif + 4 négatifs, idempotence sur index UNIQUE réel, 503 sans écriture) ; le
> sens inverse rend **2 événements sur 19**, **5 familles sur 6 sans émetteur**, `erasure`
> **n'efface rien** (B14-002, S0) ; et faute de SIREN chez **tous** les émetteurs,
> **100 % des leads finissent en arbitrage manuel** — 0 fiche créée en 5 jours (B13-001, A05-003).

> **③ INTERFACE ET NAVIGATION — la barre est refondue, la carte reste à moitié blanche, et le vocabulaire est déjà en collision.**
> Six sections propres, une seule entrée Contacts, **aucune entrée verrouillée** (A-006 : 8 des
> 10 défauts supposés sont **déjà corrigés**) ; mais il manque le groupe **ÉCHANGES** entier,
> Boîte de réception, Mes rendez-vous, Mes tâches, Organisations, Prospection, Canal, Coûts,
> Fiches récentes et **tous les compteurs** — et le mot « console », que le §19 réserve au
> paramétrage, désigne déjà le hub de contacts (I48-007).

> **④ CONFORMITÉ — le produit n'est pas en règle sur ses propres fondations, et ses mécanismes de preuve sont troués.**
> Intérêt légitime **sans mise en balance écrite ni information article 14** pour **1 319 567
> personnes** (C19-007, S0), registre article 30 dont la table **n'est créée par aucune
> migration**, export sur **4 tables** et effacement sur **8** avec `candidates` **dans ni l'un
> ni l'autre** (B10-004, S0), `erasure` qui n'efface rien (B14-002, S0), registre AI Act **vide
> et servi en dur** (B16-007), et les deux seules purges CNIL correctes **ne s'exécutent jamais**
> (B17-009).

> **⑤ SÉCURITÉ — le cloisonnement est réel là où on l'a durci, et absent exactement là où il prouve.**
> 55 tables en `FORCE ROW LEVEL SECURITY`, canal entrant en 503 sans écriture, aucun secret de
> proxy en base (témoin négatif solide) — mais `audit_logs` et ses **14 partitions** n'ont
> **aucune RLS** et se lisent **tous espaces confondus par tout compte authentifié** (B16-004,
> S0), le journal est **tronquable par la queue** (B16-002, S0), **26 tâches sur 33 et 5 jobs
> sur 6 tournent sans contexte d'espace** (B11-001/002, S0), et **local et production
> n'exécutent pas le même cloisonnement** (B11-010).

> **⑥ EXPLOITATION — la production ne peut pas porter le produit qu'on lui construit, et personne n'y est jamais entré.**
> L'API est servie par `php -S`, **un seul processus**, requêtes **sérialisées** — escalier
> parfait de 15 ms sur 12 requêtes, témoin séquentiel plat (A-010, **S0**) : le **principe 8**
> et le **critère 17** sont **inatteignables par construction** ; `MAIL_MAILER` absent coupe
> les deux voies de récupération et **personne ne s'est jamais connecté** (A-012, S0) ; le
> journal grossit de ~90 Mo/jour dont **100 % du même défaut** (A-007).

> **⑦ TESTS — le harnais backend est réel et bloquant ; ce qu'il garde est parfois le mauvais objet, et l'interface n'est presque pas gardée.**
> **780 tests, 6 503 assertions, 0 échec, CI requise**, PHPStan niveau 8 `[OK] No errors`,
> `SocleCrmTest` compare les `CHECK` **réellement en base** aux constantes, **zéro exclusion
> silencieuse** dans les configurations — mais **six gardes mesurent le mauvais objet**
> (A-011, S1), **14 des 16 spécifications Playwright ne tournent nulle part** (267 tests sur
> 285), **27 écrans sur 37 ne sont touchés par rien**, et deux personnes ne peuvent pas lancer
> les tests en même temps (H44-004).

---

## 3. CE QUI VA CASSER DANS LES MAINS DU CHANTIER EN COURS

Ordonné par **ce qui bloque le plus tôt**, pas par sévérité. Pour chacun : **quelle pièce de
l'étape 1a s'y appuie**, et **ce qui se passe si on ne le corrige pas d'abord**.

| # | Ce qui casse | La pièce de 1a qui s'y appuie | Ce qui se passe si on ne corrige pas d'abord |
|---|---|---|---|
| **1** | **Où vit le type, et le multi-types** (`relation_type` sur `companies`, `BUSINESS_RELATION_PRIORITY`, `company_id NOT NULL`) — objets 1, 2, 5, 7 | **La fiche par type et le multi-types**, première ligne de l'étape 1a — et le multi-types a été **remis au périmètre par le dirigeant le 19/08** (D-005) | On écrit les dossiers, les motifs d'échange et les trames **contre un modèle où la personne n'est pas le sujet**. Chaque pièce suivante s'y rattache : se tromper ici se paye sur **toutes** les autres. L'inventaire de l'étape 1a le dit lui-même : *« il faut commencer une marche plus bas »*. **C'est la marche.** |
| **2** | **Il n'y a aucune route pour créer une fiche personne**, et modifier ou supprimer rend **501** (I48-001) | Le **critère de réussite de l'étape 1a** : « une fiche se crée en 3 secondes » | Le critère de sortie de l'étape est **inatteignable par l'API du produit**, quel que soit l'écran qu'on dessine. Toute démonstration de 1a se fera sur des fiches créées par la collecte ou par le canal — c'est-à-dire sur des fiches que **personne n'a saisies**. |
| **3** | **`person_key` : 0 sur 1 319 567**, dont 410 481 ont pourtant un e-mail, et **aucun backfill n'existe** (A05-001, S1) | La **fiche 360°** (`PersonTimelinePage`, `/crm/persons/{personKey}/timeline`) — l'écran que l'inventaire désigne comme « la fiche en germe », donc la base de la pièce fiche | La fiche 360° **s'ouvre sur du vide pour tout le monde**. Le **critère 4 du §29** tombe avec elle. Et l'écran existe déjà : on va le construire, le tester, le livrer vert — **sur zéro donnée**. |
| **4** | **`ACTIVITY_KINDS` ne contient aucun mot d'interaction humaine** — ni appel, ni note, ni entretien — et la liste est **fermée par un `CHECK`** (objet 10) | **L'écran d'entretien en consignation manuelle** (§6 + §8.1), pièce centrale de 1a | « *Un appel se consigne en 1 clic avec le bon motif* » : **il n'y a pas de `kind` pour un appel**. La pièce entretien butera sur une migration **plus** une garde à revoir, découvertes au dernier moment. |
| **5** | **`crm_activites` et `crm_motifs` : deux tables sans route, sans contrôleur, sans écran** (I48-004) | **La pièce 2, déjà livrée** — et la pièce entretien, qui doit y choisir un motif | La pièce 2 justifie son schéma par le **critère 2 du §29** (« aucun paramétrage n'exige d'intervention technique ») — un critère qu'elle **ne rend pas atteignable** : les motifs sont en base et **rien ne les sert ni ne les édite**. Une pièce livrée qui ne peut être consommée par personne. |
| **6** | **`notifications` : 0 écrivain, 0 lecteur, 3 suppresseurs** — et l'inventaire qui **fixe l'ordre des pièces** la déclare « vivante, 10 fichiers applicatifs » (I48-006) | **Tâches, rappels et notifications Telegram groupe / privé**, au périmètre de 1a | L'équipe croira **étendre** et devra **partir de zéro** : un écrivain, un lecteur, un diffuseur, un canal Telegram (2 commentaires aujourd'hui). C'est la faute exacte que le §28.5 cherche à éviter — et elle est écrite dans le document censé l'éviter. |
| **7** | **`crm_tasks`, `crm_notes`, `crm_pipelines`, `pipeline_stages`, `deals`, `deal_history`** : six tables de mai 2026 dont le schéma **précède le CDC** (objet 18) — et **42 tables sur 102 ne sont nommées par aucune ligne de code** (B10-012) | **Tâches et rappels**, **dossiers** (§3.1 bis), **opportunités** | Réutiliser les **noms** sans mesurer les **colonnes** livre des tâches sans récurrence et des opportunités **sans rattachement à une activité** — que le §2.4 rend obligatoire. Créer `tasks` à côté de `crm_tasks` donne **deux tables pour une notion**. Il faut trancher **avant** d'écrire, pas pendant. |
| **8** | **Le canal ne crée aucune fiche** : aucun émetteur ne transmet de SIREN, aucun formulaire n'en collecte (B13-001, S1) ; vivier vide (A05-006) ; 0 fiche en 5 jours (A05-003) | **Les règles d'attribution** et la **vue « aujourd'hui »** de 1a, qui n'ont de sens que si des entrants arrivent | La vue « aujourd'hui » et les règles d'attribution seront livrées **sur un écran vide**, et testées sur des fixtures. Le **critère 18** reste NON TENU quoi qu'on construise au-dessus. Ce correctif est **dans l'autre dépôt** — il doit être commandé maintenant, il ne se rattrapera pas à la fin. |
| **9** | **La production sérialise toutes ses requêtes** (`php -S`, A-010, **S0**) | **Toute** mesure de performance de 1a — et la pièce 1, dont les compteurs ont été chronométrés à **17,5 s cache froid** | Une seule ouverture de console après purge de cache **gèle l'application pour tout le monde**. Toute mesure de charge faite avant ce correctif mesure **la file d'attente, pas le produit** (D-010). Correctif **peu coûteux** : php-fpm est déjà dans l'image ; `PHP_CLI_SERVER_WORKERS=8` en repli, 15 min. |
| **10** | **Personne ne s'est jamais connecté au CRM en production** (A-012, S0) | **Toutes** les pièces d'interface de 1a | Le principe 7 (« le produit s'explique tout seul ») n'a **jamais été éprouvé par un usage**. On livrera l'étape 1a sans que quiconque ait ouvert un seul de ses écrans en conditions réelles. L'accès est **rendu** par `definir-mot-de-passe-crm.sh` — **il reste à le jouer**. |
| **11** | **Six gardes mesurent le mauvais objet** (A-011, S1) | **Toutes** les gardes que l'étape 1a va écrire | Chaque nouvelle garde a de bonnes chances de rejouer le patron : verte, irréprochable, et **aveugle au défaut**. La règle 2 doit être **écrite dans `CONTRIBUTING.md`** avant la pièce suivante, pas après. |
| **12** | **La console du §19 n'existe pas, et son nom est déjà pris** (I48-007) | Rien dans 1a — mais **tout** dans l'étape 2 | Chaque réglage que 1a mettra en base **sans écran** creuse la dette du critère 2. Et le jour où l'on ouvrira la vraie console du CRM, `/console/*` désignera déjà autre chose : **une migration d'URL, sur un produit en service**. Le renommage coûte **quelques heures aujourd'hui**, un chantier plus tard. |

---

## 4. CE QUI PEUT CONTINUER SANS ATTENDRE

Un audit qui dit « tout est bloqué » n'aide personne. Voici ce qui est **sain, mesuré, et sur
quoi on peut poser une pièce dès aujourd'hui**.

**① Le canal entrant est solide — n'y touchez pas, étendez-le.**
HMAC SHA-256 sur `"<horodatage>.<corps brut>"`, `hash_equals`, vérifié **avant** le drapeau ;
**1 témoin positif et 4 témoins négatifs joués** (signature falsifiée, en-tête absent, corps
altéré après signature, horodatage altéré) → **401 à chaque fois, 0 écriture** ; fenêtre ±300 s
mesurée aux deux bornes ; idempotence par `event_id` **adossée à un index UNIQUE réel** (rejeu
octet pour octet → `noop_idempotent`, une seule activité) ; **cloisonnement le meilleur du
produit** : le workspace n'est *jamais* dans le contrat, workspace absent → **503, 0 écriture**.
Les 10 événements sont classés 10/10, un inconnu rend un **422 bruyant**. C'est le patron à
copier pour les 17 événements manquants du sens inverse.

**② La CI backend est réelle et bloquante.**
**780 tests, 6 503 assertions, 39,31 s, 0 échec, 0 ignoré**, `needs: [ci]`, `migrate --force`
sans `|| true` ; PHPStan **niveau 8 → `[OK] No errors`**, `reportUnmatchedIgnoredErrors: true`,
baseline **1 321 l.** (et non 2 045) ; Vitest **118/118** et **61/61** ; **zéro exclusion
silencieuse** dans les configurations — 0 `<exclude>`, 0 `->skip()`, 0 `.only`, 0 `testIgnore` ;
`phpunit.xml` et `phpunit-ci.xml` ne diffèrent que par `executionOrder` : **la quarantaine est
réellement levée**. Une pièce écrite avec ses tests **sera vraiment gardée** côté backend.

**③ `SocleCrmTest` est le patron de garde à réutiliser.**
Il compare les `CHECK` **réellement présents en base** aux constantes de `Taxonomy` : ajouter
une valeur sans écrire la migration **fait rougir la CI**. C'est une garde qui mesure le bon
objet — la seule chose que le produit ait faite systématiquement bien. La pièce 2 s'en est déjà
servie, et a paré le **piège 22** (`insertOrIgnore`, jamais `upsert`, avec un test qui vérifie
qu'un libellé modifié depuis la console **survit** au déploiement suivant).

**④ Le temps est juste en base.**
**203 colonnes temporelles sur 203 en `timestamptz`.** Le critère 16 du §29 ne butera pas sur
le schéma — seulement sur le décalage de +7 200 s à l'entrée du canal (B13-006), qui est un
défaut **de point d'entrée**, pas de modèle.

**⑤ La barre latérale a déjà été refondue — ne la rouvrez pas, complétez-la.**
Six sections dans l'ordre de la journée, **une seule** entrée Contacts, « Collectes » et
« Journaux de collecte », **plus aucune entrée verrouillée**, plus de section « Phase 2 »,
accordéon qui suit la page, entrée « Vivier » masquée hors de l'univers. **8 des 10 défauts que
le mandat croyait ouverts sont corrigés** (A-006). Le travail restant est **d'ajouter** les
groupes de la cible §23.3, pas de refaire.

**⑥ Le volume de référence existe, versionné et rejouable.**
`seed_reference_50k.sql` (300 000 fiches) et `seed_volume_production_4m.sql` (2,8 M) : le §29
exige de tout mesurer au volume de référence, et **le jeu est là**. Acquis réel de l'étape 0,
à ne pas redécouvrir. *(Réserve : toute mesure de charge attend le correctif A-010.)*

**⑦ La pièce 1 de l'étape 1a est bonne, et son intérêt dépasse ce qu'on lui prêtait.**
Index couvrant `(workspace_id, relation_type, lifecycle_stage)` + `Cache::flexible` avec
contexte d'espace préservé (le seul cas du patron « contexte perdu après la réponse », et il
est corrigé). Elle ne réglait pas qu'une lenteur : sur une production sérialisée, elle
**retirait un point de blocage global**.

**⑧ Cinq chapitres entiers sont du terrain vierge — aucune contradiction à payer.**
§4 (questionnaires), §5 (notation et ressenti), §6 (écran d'entretien), §14 (documents),
§16 (suivi après-vente) : **0 migration, 0 route, 0 écran**. On y écrit **directement la cible**,
sans rien retirer. C'est là que le chantier avance le plus vite et le plus proprement — à une
condition, nommée dès maintenant : le §5 impose que **le ressenti figure dans l'export de la
personne**, donc l'export RGPD (4 tables aujourd'hui) doit être conçu avec, pas après.

**⑨ Deux briques existantes servent des chapitres à venir — on étend, on ne réinvente pas.**
`llm_use_cases` / `llm_usage` / le routeur et son plafond sont **exactement** le patron du
plafond de coût par minute du §17.2 et de la ligne « Coûts » du §23.3. Et l'enrichissement
d'organisation en cascade du §1.2 est **déjà livré** : le §1.2 dit « on ne ressaisit pas ce que
le produit sait déjà trouver » — c'est fait.

---

## 5. MES CONSTATS

### [I48-001] Le CRM n'a aucune route pour créer une fiche personne, et la modifier ou la supprimer rend 501
- **Sévérité**      : **S0** — *blocage du chantier cible*
- **Domaine**       : backend / interface
- **Référence**     : `main e8924b8` (code identique à `c0c453d`)
- **Emplacement**   : `backend/routes/api.php:131-134` ; `backend/app/Http/Controllers/Api/ContactsController.php:138,151`
- **Constat**       : sur les quatre routes de `/contacts`, deux sont en lecture, et les deux verbes d'écriture rendent **501** ; **aucune route `POST /contacts` n'est déclarée**.
- **Preuve**        :
  ```
  131:  Route::get('/contacts',            [ContactsController::class, 'index']);
  132:  Route::get('/contacts/{contact}',  [ContactsController::class, 'show']);
  133:  Route::put('/contacts/{contact}',  [ContactsController::class, 'update']);   -> notImplemented('5')
  134:  Route::delete('/contacts/{contact}',[ContactsController::class,'destroy']);  -> notImplemented('5')

  $ grep -c "Route::post('/contacts'" backend/routes/api.php
  0
  ```
  `04_PREUVES/agent-48/01_contacts-sans-creation.txt`
- **Témoin négatif** : le **même** motif de recherche, dans le **même** fichier, trouve bien `Route::post('/companies')` (l.122), `Route::post('/tags')` (l.186) et `Route::post('/audiences')` (l.199). Le contrôle sait donc reconnaître une route de création quand il y en a une : c'est bien `/contacts` qui n'en a pas. Et le contrôleur **n'a pas de méthode `store`** — l'absence est cohérente des deux côtés, ce n'est pas une route oubliée dans le fichier.
- **Impact**        : le **critère de réussite de l'étape 1a** est « *une fiche se crée en 3 secondes* », et le parcours §23.4 « Créer un contact complet — 1 clic + saisie, aucun champ bloquant ». **L'API du produit ne sait pas créer une personne.** Les 1 319 567 fiches existantes viennent **toutes** de la collecte ou du canal ; aucune n'a été saisie par un humain, et aucune ne peut l'être. Les critères **3** (aucune saisie perdue) et **6** (toute information se modifie sur place) tombent avec : `PUT` rend 501. La matrice classait le critère 6 « ⏳ agent 26 » — il est mesurable par lecture de code, et il est **NON TENU**.
- **Reproduction**  : `grep -n "contacts" backend/routes/api.php | grep "Route::"` ; puis lire les deux méthodes du contrôleur.
- **Correctif**     : la pièce « fiche » de l'étape 1a doit livrer `POST /contacts`, `PUT /contacts/{id}` et `DELETE /contacts/{id}` réels. **Mais elle ne peut pas être écrite avant que I48-003 et l'arbitrage « où vit le type » ne soient tranchés** — écrire une création contre `company_id NOT NULL` reviendrait à exiger une entreprise pour enregistrer un candidat. Coût : indissociable de la pièce fiche ; **l'arbitrage préalable, lui, coûte une décision, pas du code**.
- **Statut**        : **ouvert**

---

### [I48-002] La recherche globale rend une charge codée en dur : le critère 1 du §29 n'est pas « non mesuré », il est non tenu
- **Sévérité**      : **S1**
- **Domaine**       : backend / UX
- **Référence**     : `main e8924b8`
- **Emplacement**   : `backend/app/Http/Controllers/Api/GlobalSearchController.php:17-20` ; `backend/routes/api.php:99` **et** `:207` ; `frontend/src/components/ui/GlobalSearch.tsx:35`
- **Constat**       : `GET /v1/search` rend `{"companies":[], "contacts":[], "tags":[]}` **sans exécuter la moindre requête**, et la route est **déclarée deux fois** — une fermeture *inline* l.99, puis le contrôleur l.207.
- **Preuve**        :
  ```php
  public function index(Request $r): JsonResponse
  {
      return $this->ok(['companies' => [], 'contacts' => [], 'tags' => []]);
  }
  ```
  ```
  $ grep -n "'/search'" backend/routes/api.php
   99:  Route::get('/search', function (Request $request) {   // rend [] , [] , []
  207:  Route::get('/search', [GlobalSearchController::class, 'index']);

  $ frontend/src/components/ui/GlobalSearch.tsx:35
        return (await api.get<SearchResults>(`/search?q=${…}`)).data;
  ```
  `04_PREUVES/agent-48/02_recherche-et-notifications.txt`
- **Témoin négatif** : le comptage `DB::table|::query()` rend **0** sur `GlobalSearchController` et **≥ 1** sur `ContactsHubController`, servi par le **même** fichier de routes. Le contrôle distingue donc un contrôleur qui interroge sa base d'un contrôleur qui n'en a jamais l'intention.
- **Impact**        : le §18.1 fait de la barre unique le premier moyen d'atteindre une personne, et le **critère 1 du §29** exige « 20 recherches, 20/20 sous 5 s, résultats à la frappe ». La barre ⌘K du SPA **est branchée**, et elle est branchée **sur du vide** : elle répond 200 en quelques millisecondes et n'a jamais rien trouvé. La matrice classe le critère 1 « ⏳ agent 41/42 » : il n'y a rien à chronométrer. C'est la **cinquième façade** de ce type recensée (avec `/saved-views`, `/dashboard/stats`, `/ai-act/register`) : le patron est systémique, et il fausse tout tableau d'avancement lu par un humain qui ouvre l'écran et voit « aucun résultat ».
- **Reproduction**  : lire le contrôleur ; `grep -n "'/search'" backend/routes/api.php`.
- **Correctif**     : réaliser la recherche (elle doit de toute façon être livrée en 1a : « recherche » figure au §27 étape 1a), **ou** rendre 501 en attendant — mais pas 200. Et **retirer la déclaration en double** : deux routes pour une URL, c'est une des deux qui ment sans qu'on sache laquelle. La garde doit rougir **sur une base semée**, sans quoi elle passerait verte sur une réponse vide (piège 19). Coût : ~30 min pour le 501 + le doublon ; la réalisation appartient à la pièce recherche.
- **Statut**        : **ouvert**

---

### [I48-003] Une personne ne peut exister ni sans organisation ni sans nom de famille : deux `NOT NULL` contre le principe 5
- **Sévérité**      : **S1**
- **Domaine**       : backend / conformité au CDC
- **Référence**     : `main e8924b8`
- **Emplacement**   : `backend/database/migrations/2026_05_16_000003_create_companies_contacts_scraping_schema.php:74,76`
- **Constat**       : `contacts.company_id BIGINT **NOT NULL** REFERENCES companies(id)` et `contacts.last_name TEXT **NOT NULL**`.
- **Preuve**        :
  ```sql
  CREATE TABLE contacts (
      id                BIGSERIAL PRIMARY KEY,
      workspace_id      UUID   NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
      company_id        BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,  -- <<<
      first_name        TEXT,
      last_name         TEXT   NOT NULL,                                             -- <<<
      normalized_hash   TEXT GENERATED ALWAYS AS (… || company_id::TEXT …) STORED,
      …
  ```
  `04_PREUVES/agent-48/03_modele-personne.txt`
- **Témoin négatif** : dans la **même** table, `first_name`, `email`, `phone` et `title` sont **nullables** — le schéma n'est donc pas « tout obligatoire » par principe. Les deux `NOT NULL` sont des décisions isolées, pas un style. Et `candidates`, créée plus tard, **n'a pas** de `company_id` : la contrainte n'est pas une nécessité du domaine.
- **Impact**        : trois conséquences distinctes. **(a)** Le principe **5** du §0 (« aucun champ obligatoire bloquant — à une exception près, motivée ») et le §1.6 (« la fiche existe dès qu'un élément d'identification est saisi ») sont contredits **par le schéma**, donc par la couche la plus coûteuse à changer. **(b)** Le §2.1 crée un type ***non qualifié*** « pour un premier message dont on ne sait rien encore » : une telle fiche ne peut pas être écrite en base. **(c)** C'est la cause mécanique de **B13-002** — un lead **avec** SIREN mais **sans nom de famille** est accepté 200 et **son adresse électronique est détruite** : le canal n'a nulle part où l'écrire. `normalized_hash` est en outre **calculé sur `company_id`**, donc la clé d'unicité elle-même suppose l'organisation.
- **Reproduction**  : `sed -n '71,97p'` sur la migration citée.
- **Correctif**     : rendre `company_id` et `last_name` nullables est une migration additive **simple**, mais elle **déclenche** deux chantiers : `normalized_hash` (colonne générée sur `company_id`) doit être revu, et la contrainte `UNIQUE(workspace_id, normalized_hash)` avec. **À trancher dans le même arbitrage que « où vit le type »**, pas séparément — c'est la même question posée deux fois. Coût : ~1 j avec la reprise de la clé d'unicité sur 1,3 M de lignes ; **quelques heures si l'on tranche d'abord et qu'on écrit une fois**.
- **Statut**        : **ouvert**

---

### [I48-004] La pièce 2 de l'étape 1a est en base sans route, sans contrôleur ni écran — et se justifie par un critère qu'elle ne rend pas atteignable
- **Sévérité**      : **S1**
- **Domaine**       : backend / interface
- **Référence**     : `main e8924b8`
- **Emplacement**   : `backend/database/migrations/2026_08_19_000002_crm_activites_et_motifs.php` ; `backend/app/Crm/ActivitesEtMotifs.php`
- **Constat**       : `crm_activites` et `crm_motifs` sont créées et semées ; **aucun fichier de `backend/app/Http`, `backend/routes` ou `frontend/src` ne les nomme**.
- **Preuve**        :
  ```
  $ grep -rn "crm_activites|crm_motifs|ActivitesEtMotifs" backend/app backend/routes frontend/src
    (aucune ligne, hors app/Crm/ActivitesEtMotifs.php lui-même)

  $ grep -rl "crm_activites|crm_motifs|ActivitesEtMotifs" backend/ --include=*.php
    backend/app/Crm/ActivitesEtMotifs.php                      <- la constante
    backend/database/migrations/2026_08_19_000002_…php          <- la migration
    backend/database/seeders/ActivitesEtMotifsSeeder.php        <- le semis
    backend/tests/Feature/Crm/ActivitesEtMotifsTest.php         <- le test
  ```
  `04_PREUVES/agent-48/04_piece2-et-chapitres.txt`
- **Témoin négatif** : le **même** motif appliqué à `Taxonomy` trouve bien trois contrôleurs qui la consomment (`ContactsHubController`, `CandidatesController`, `BulkController`). Le contrôle voit donc la différence entre une constante servie par une route et une constante qui ne l'est pas.
- **Impact**        : l'en-tête de la migration écrit noir sur blanc pourquoi elle a choisi **deux tables plutôt qu'un `CHECK`** : *« le §2.3 dit extensibles depuis la console du CRM, et le §29 critère 2 exige qu'aucun paramétrage courant n'exige d'intervention technique »*. **Le raisonnement est juste ; le critère reste non tenu** : sans route ni écran, ajouter un motif exige un accès `psql` — c'est-à-dire **plus** d'intervention technique qu'une migration relue en PR. La pièce est **correcte et invérifiable par son propre critère de sortie**. C'est la forme la plus discrète du piège 19 : la garde (`ActivitesEtMotifsTest`) est excellente, elle mesure le **semis**, et l'objet qui casse est **l'accès**. Conséquence directe pour la pièce suivante : l'écran d'entretien doit « choisir le bon motif » et **il n'y a pas d'endpoint pour lister les motifs**.
- **Reproduction**  : les deux `grep` ci-dessus.
- **Correctif**     : ajouter au groupe `crm-console` un `GET /crm/motifs` et `GET /crm/activites` (lecture, pour l'écran d'entretien) puis, en étape 2, l'édition depuis la console du §19. La lecture seule est **≈ 1 h** et débloque la pièce entretien ; l'édition appartient à l'étape 2 et doit être **inscrite comme telle**, pour que le critère 2 ne soit pas réputé tenu par la seule présence des tables.
- **Statut**        : **ouvert**

---

### [I48-005] Quinze objets du code contredisent la cible : ils devront être DÉFAITS, pas complétés
- **Sévérité**      : **S1** *(constat de synthèse — il ne remplace aucun constat, il chiffre une colonne que personne ne tenait)*
- **Domaine**       : backend / méthode
- **Référence**     : `main e8924b8`
- **Constat**       : sur les 26 chapitres, **15 objets** du code ne sont pas des manques mais des **contradictions** : du code écrit, testé, parfois gardé par une CI bloquante, qui dit l'inverse de la cible.
- **Preuve**        : le recensement complet est au **§1** de ce rapport, colonne « 🔴 À DÉFAIRE », numéroté 1 → 29 (les objets non structurels y sont rappelés pour mémoire). Les **quinze structurels** :

  | # | L'objet | Le chapitre qu'il contredit | Poids |
  |---|---|---|---|
  | 1 | `relation_type` / `lifecycle_stage` sur `companies`, jamais sur la personne | §1.1, §2.1 | **lourd** |
  | 2 | `BUSINESS_RELATION_PRIORITY` — un seul type, « le plus engageant » | §2.2 (**remis au périmètre 19/08**) | **lourd** |
  | 3 | `contacts.company_id NOT NULL` | §1.6, principe 5 | **lourd** |
  | 4 | `contacts.last_name NOT NULL` | §1.6, principe 5 | moyen |
  | 5 | Cinq tables de personnes séparées (`contacts`, `candidates`, `journalists`, `health_practitioners`) | principe 1 | **lourd** |
  | 6 | `conference` et `newsletter` comme **types** | §2.3, §22.2 | léger |
  | 7 | `ContactsHubController` : unité de ligne = l'**entreprise** | §18.2 | moyen |
  | 8 | `ACTIVITY_KINDS` sans aucun mot d'interaction humaine, fermé par `CHECK` | §3.2, étape 1a | **lourd** |
  | 9 | `activities.type` libre coexistant avec `kind` (*expand* jamais contracté) | §3.1 | léger |
  | 10 | Six tables d'échafaudage mort + **42 tables sur 102** sans aucun appelant (B10-012) | §2.4, §2.5, §13, §18 | **lourd** |
  | 11 | Deux listes de suppression (`opt_out` + `email_suppressions`) | §9.0 (« **une seule** ») | moyen |
  | 12 | `crm_outbound_events` : sans `workspace_id`, sans RLS, `scope` en dur, une seule URL | §22.5, §0.8 | **lourd** |
  | 13 | Quatre routes qui rendent 200 sur une charge en dur (`/search`, `/saved-views`, `/dashboard/stats`, `/ai-act/register`) | §18, §21.4 | moyen |
  | 14 | `/console/*` = le hub de contacts, alors que le §19 réserve le mot | §19, critère 24 | moyen |
  | 15 | `/cold-email`, `/linkedin`, `/audiences` (constructeur de segments) | §26 (**lot L7 exclu**) | moyen |

- **Témoin négatif** : le crible **discrimine**. **Cinq chapitres entiers** (§4, §5, §6, §14, §16) ne portent **aucun** objet à défaire — vérifié : 0 migration et 0 fichier applicatif pour `questionnaires`, `trames`, `grilles`, `interactions`, `documents`. La colonne « à défaire » n'est donc pas un réquisitoire appliqué partout : elle **désigne**.
- **Impact**        : c'est le coût que **personne ne compte**. Un manque s'ajoute ; une contradiction se paye **deux fois** — retirer, puis réécrire — sur 1,3 M de fiches et 4,29 M d'organisations en service, avec une CI qui **garde activement** deux de ces objets (`SocleCrmTest` compare `BUSINESS_RELATION_TYPES` aux `CHECK` réels : retirer `conference` **fera rougir la CI**, et c'est voulu). Trois de ces objets — **1, 2 et 3** — sont sous la main de la pièce que le chantier écrit **en ce moment**.
- **Reproduction**  : les cinq fichiers de `04_PREUVES/agent-48/`, chacun rejouable en une commande.
- **Correctif**     : **ne pas les corriger un par un.** Les objets 1, 2, 3, 4, 5 et 7 sont **une seule question** — *où vit la personne, et que porte-t-elle ?* — posée six fois. Un **ADR unique** doit la trancher avant la pièce fiche ; les six migrations en découlent et s'écrivent ensemble. Les objets 6, 8 et 9 forment un second lot (le vocabulaire), 13 et 14 un troisième (les façades et les noms), 15 un arbitrage à porter au dirigeant (§28.6 : « tout écart à ce document »). Coût de l'ADR : **une décision**. Coût de son absence : chaque pièce de 1a écrite au-dessus.
- **Statut**        : **ouvert** — **c'est le premier lot du chantier, avant toute nouvelle pièce**

---

### [I48-006] `notifications` : zéro écrivain, zéro lecteur, trois suppresseurs — et l'inventaire qui fixe l'ordre des pièces la déclare « vivante »
- **Sévérité**      : **S2**
- **Domaine**       : backend / méthode
- **Référence**     : `main e8924b8`
- **Emplacement**   : `backend/app/Http/Controllers/Api/NotificationsController.php:15,23,30` ; `backend/app/Console/Commands/RetentionPurge.php:31` ; `backend/app/Services/Rgpd/GdprErasureService.php:32` ; `_REPORTS/2026-08-19_INVENTAIRE-ETAPE-1A.md` §1
- **Constat**       : rien n'écrit dans `notifications`, rien ne la lit, et **trois mécanismes en suppriment** des lignes. Son contrôleur rend `{"data": []}` **en dur** ; ses deux autres verbes rendent **501**.
- **Preuve**        :
  ```
  $ grep -rn "table('notifications')|DELETE FROM notifications" backend/app
    RetentionPurge.php:31       "DELETE FROM notifications WHERE created_at < now() - INTERVAL '90 days'"
    GdprErasureService.php:32   DB::table('notifications')->whereRaw('body ILIKE ?', …)->delete()
    -> deux suppresseurs, zero insert, zero select

  $ grep -n "public function" .../NotificationsController.php
    15: index      { return $this->ok(['data' => []]); }     // en dur
    23: markRead   { return $this->notImplemented('10'); }
    30: markAllRead{ return $this->notImplemented('10'); }
  ```
  Et l'inventaire de l'étape 1a, §1 : « **`notifications`** … base, **10 fichiers applicatifs** — **Vivante.** Sert les rappels et les notifications de 1a. » Mesure : **6 fichiers** citent le mot, dont **2** touchent la table, et **aucun** ne l'alimente.
  `04_PREUVES/agent-48/02_recherche-et-notifications.txt`
- **Témoin négatif** : le **même** motif de recherche trouve bien des écrivains pour d'autres tables (`DB::table('opt_out')->insert(...)` dans trois services distincts). Le contrôle sait donc reconnaître un écrivain : il n'y en a pas ici. Et l'inventaire n'est pas faux partout — sur `person_key`, `activities` et `tags`, il est exact et utile.
- **Impact**        : **ce n'est pas un défaut de code, c'est un défaut du document qui pilote le chantier.** Le §28.5 exige, avant chaque lot, « la liste de ce qui existe déjà et sert le lot — on étend, on ne réinvente pas ». L'inventaire se trompe ici **dans le sens qui coûte le plus cher** : il fait croire qu'on **étend** là où il faudra **partir de zéro** (un écrivain, un lecteur, un diffuseur, un canal Telegram — lequel n'existe que sous forme de **deux commentaires** dans tout le backend). L'agent 9 avait relevé le sens inverse (A09-003 : l'inventaire déclare absentes des activités qui existent) ; **les deux erreurs sont dans le même document, et c'est lui qui fixe l'ordre des pièces**. Une notion classée « à étendre » qui est en réalité « à construire » décale l'estimation de la pièce tâches et rappels de l'étape 1a.
- **Reproduction**  : les deux `grep` ci-dessus.
- **Correctif**     : rectifier la ligne `notifications` de l'inventaire de l'étape 1a (« table présente, **inerte** : 0 écrivain, 0 lecteur, 3 suppresseurs ») ; et poser au §3 du même document, à côté de `crm_tasks`, la même mise en garde qu'il formule déjà si bien : *« un inventaire superficiel lit “il y a déjà une table” et conclut “ça existe, on étend” »*. Coût : ~20 min. Le vrai coût est celui qu'on évite.
- **Statut**        : **ouvert**

---

### [I48-007] Le mot « console » désigne déjà autre chose que le §19 : le critère 24 est perdu avant le premier écran de réglage
- **Sévérité**      : **S2** *(confusion de navigation — le §8 du dossier commun la classe au minimum S2)*
- **Domaine**       : navigation / UX
- **Référence**     : `main e8924b8`
- **Emplacement**   : `frontend/src/app/routeTree.tsx:97-100` ; `backend/routes/api.php:263` (`prefix('crm')`, middleware `crm-console`) ; `frontend/src/features/settings/SettingsPage.tsx:71-74`
- **Constat**       : `/console/contacts`, `/console/vivier`, `/console/arbitrage` et `/console/personnes/$personKey` désignent le **hub de contacts**. Le CDC réserve « **Console du CRM** » au **paramétrage** du §19, dont l'écran réel — `/settings` — porte **quatre onglets** (Workspace, Intégrations, Observabilité, Apparence) et **aucun** des huit sous-groupes exigés.
- **Preuve**        :
  ```
  routeTree.tsx:97   path: '/console/contacts'          -> ContactsHubPage
  routeTree.tsx:98   path: '/console/vivier'            -> CandidatesPage
  routeTree.tsx:99   path: '/console/arbitrage'         -> ArbitragePage
  routeTree.tsx:100  path: '/console/personnes/$personKey' -> PersonTimelinePage

  SettingsPage.tsx:71-74   'workspace' | 'integrations' | 'observability' | 'appearance'
  ```
  Glossaire du CDC : « **Console du CRM** — l'espace de paramétrage du CRM (§19) — **à ne pas confondre** avec la console axionia ». §23.3 : « **RÉGLAGES** (la console du CRM, §19 — 8 sous-groupes) ».
  `04_PREUVES/agent-48/05_a-defaire.txt`
- **Témoin négatif** : le dépôt **sait** éviter ce piège quand il le voit — le commentaire de `routeTree.tsx:39-46` explique en détail pourquoi `/console` avait été préféré à `/crm` (le stub `CrmStub` occupait alors `/crm`) et note que **`/crm` est désormais libre**. La collision **n'a donc pas été manquée par inattention** ; elle a été créée sciemment contre un obstacle qui n'existe plus.
- **Impact**        : le **critère 24 du §29** exige que « **aucun libellé n'ait de synonyme ailleurs dans le produit** », et le §23.2 que « le même mot désigne la même chose dans les deux consoles ». Avec la console axionia, le produit compte déjà **deux** sens du mot « console » ; le code en ajoute un **troisième**. L'URL est un libellé que l'utilisateur voit, partage et met en signet. Le coût croît avec le temps : renommer aujourd'hui, avant que la console du §19 n'existe et avant que quiconque n'ait de signets (**personne ne s'est jamais connecté** — A-012), coûte quelques heures ; le faire après l'étape 2 est une migration d'URL sur un produit en service.
- **Reproduction**  : `grep -n "path: '/console" frontend/src/app/routeTree.tsx` ; lire le glossaire et le §23.3 du CDC.
- **Correctif**     : reprendre `/crm/*` pour le hub de contacts — le commentaire du dépôt dit lui-même que le chantier CRM cible « pourra reprendre `/crm` sans collision, et c'est lui qui décidera ». **C'est ce chantier, et c'est maintenant.** Réserver « console du CRM » aux réglages (§19). Prévoir la redirection `/console/* → /crm/*` : le §6.3-9 du mandat exige que ce qui disparaît devienne une redirection, jamais un 404. Coût : ~3 h avec les redirections et la mise à jour de `console-locale.spec.ts`.
- **Statut**        : **ouvert** — arbitrage à inscrire dans l'ADR de I48-005

---

### [I48-008] Le seul endroit où le produit DÉPASSE le périmètre : `/cold-email`, `/linkedin` et le constructeur d'audiences sont le lot L7, explicitement exclu
- **Sévérité**      : **S2**
- **Domaine**       : navigation / conformité au CDC
- **Référence**     : `main e8924b8`
- **Emplacement**   : `frontend/src/app/routeTree.tsx:102-103` ; `backend/routes/api.php:322-323` ; `frontend/src/features/audiences/*` ; `backend/routes/api.php:197-205` (8 routes) ; `frontend/src/components/layout/Sidebar.tsx` (« Audiences (segments) », groupe Pilotage)
- **Constat**       : le CRM porte un **constructeur de segments** avec écran, assistant, 8 routes d'API et deux tables (`email_audiences`, `audience_members`), plus deux écrans factices nommés « e-mails à froid » et « prospection LinkedIn » — alors que le §26 exclut nommément « l'e-mailing de masse et les séquences adressées à une population (contacts collectés, segments, campagnes) — c'est le lot L7 ».
- **Preuve**        :
  ```
  api.php:197-205   /audiences, /audiences/preview, /audiences/{a}, /audiences/{a}/refresh,
                    /audiences/{a}/members  (8 declarations)
  routeTree.tsx     /audiences, /audiences/new (AudienceBuilderPage), /audiences/$audienceId
  Sidebar.tsx       { to: '/audiences', label: 'Audiences (segments)' }   <- groupe « Pilotage »
  routeTree.tsx:102 path: '/cold-email'  -> ColdEmailStub
  routeTree.tsx:103 path: '/linkedin'    -> LinkedInStub
  ```
  §9.0 du CDC : « **C — le produit écrit à une population** … moteur d'envoi dédié, domaine dédié — **L7, hors de ce document (§26)** ». §9.4 : « **Aucune “séquence” de ce document ne doit pouvoir être adressée à un segment.** » §11.1 : « **Aucune sollicitation automatisée des contacts collectés dans ce document.** »
- **Témoin négatif** : sur **tous** les autres chapitres, le produit est **en deçà** de la cible, jamais au-delà — et le respect du §26 est par ailleurs **réel et vérifié** : aucune facturation dans le CRM, **aucun SDK Stripe** (mesuré par l'agent 47), aucune séquence d'envoi active. Le contrôle ne crie donc pas au dépassement partout : il désigne le seul endroit où il y en a un.
- **Impact**        : trois effets. **(a)** Le §23.3 fige la barre latérale : « Audiences (segments) » n'y figure pas, et le §23.3 précise « jamais plus de sept entrées par groupe » avec des libellés qui « ne changent jamais » — l'entrée devra sortir. **(b)** Le §11.1 pose que « **le froid n'existe nulle part par défaut** » : un constructeur de segments au premier niveau de « Pilotage » propose exactement le geste que le chapitre interdit. **(c)** Le §28.6 réserve au dirigeant « tout écart à ce document » — le maintien de ces trois objets **est** un écart, et il n'a jamais été porté devant lui : F8 est à moitié close et l'arbitrage vit dans un **commentaire** de `routes/api.php` (D-008), pas dans un ADR.
- **Reproduction**  : les `grep` ci-dessus ; puis lire §9.0, §9.4, §11.1 et §26 du CDC.
- **Correctif**     : **une question au dirigeant, regroupée avec les autres** (§28.6 : « il regroupe ses questions, les pose une fois, avec sa recommandation »). Ma recommandation : **conserver** `audiences` — c'est un outil de segmentation **interne** utile à la collecte, et le §26 exclut *l'envoi*, pas le fait de constituer une liste — mais **le sortir de la barre latérale cible** et l'inscrire dans un ADR qui dit explicitement qu'aucun envoi ne s'y branchera avant L7 ; **retirer** `/cold-email` et `/linkedin`, qui ne rendent aucun service et portent le vocabulaire de L7 dans les URL. Coût : ~1 h + l'ADR.
- **Statut**        : **ouvert** — remonte au dirigeant

---

## 6. CE QUE JE N'AI PAS PU VÉRIFIER, ET POURQUOI

Cette liste est un livrable, pas un aveu.

| # | Non vérifié | Raison |
|---|---|---|
| 1 | **L'état réel des 26 chapitres en base de production** (combien de lignes dans `crm_activites`, `crm_motifs`, `saved_views`, `notifications`) | Interdit d'écrire en production, et la pile locale est écartée (A-009). Mes verdicts §1 portent sur **le code et le schéma**, jamais sur des volumes. Là où un volume compte, je cite le constat de l'agent qui l'a mesuré (A05-001, A05-004, B16-007), je n'en produis aucun. |
| 2 | **Les 37 écrans ouverts à la main** | Le §11 du mandat l'exige et **A-012** l'a rendu impossible pendant la majeure partie de l'audit : personne ne s'est jamais connecté au CRM en production, l'accès n'a été rendu qu'en cours de session. Mes verdicts d'interface reposent sur la **lecture du code des écrans**, ce qui est plus faible : je le déclare. |
| 3 | **Si les six tables d'échafaudage mort sont réutilisables** colonne par colonne | J'ai lu leurs noms et l'analyse de l'inventaire de l'étape 1a ; je n'ai pas relu les 6 schémas au complet. La conclusion « `deals` n'a pas de rattachement à une activité » vient de l'inventaire, **pas de ma mesure**. À trancher au moment d'écrire la pièce. |
| 4 | **La suite Pest rejouée par moi-même** | D-009 : la base de test est unique et partagée (B11-005, H44-004) ; trois agents s'y sont détruits mutuellement. Les chiffres 780 / 6 503 que je cite sont ceux de l'agent 44 sur un **run CI**, pas un run local. |
| 5 | **Le décompte exact « 42 tables sur 102 »** | Repris de B10-012 tel quel, comme le mandat le demande. Je n'ai pas re-joué l'inventaire de tables. |
| 6 | **Le sort réel de `person_key` en production** (0 sur 1 319 567) | Repris de A05-001. J'ai vérifié dans le code que **la colonne existe et qu'aucun backfill n'existe** ; le chiffre est celui de l'agent 5. |
| 7 | **La conformité des 13 parcours du §23.4 et des critères 3, 10, 14, 16, 23, 24, 25** | Hors de ce que la lecture de code peut trancher : ils exigent un geste réel dans un navigateur (agents 22, 23, 24, 26, 27, 32). Je ne les ai **pas** classés dans mon verdict. |
| 8 | **Ce que la baseline PHPStan de 1 321 lignes cache** | Périmètre de l'agent 46. Mon verdict ⑦ dit que la CI est réelle et bloquante — il ne dit pas que tout est analysé. |

---

*Agent 48 — auditeur d'aptitude au cahier des charges. `main = e8924b8`, 2026-08-19.*
*Aucun fichier du produit modifié. Le worktree `crmpro-wt-etape1a` n'a pas été approché.*
