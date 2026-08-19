# Inventaire de l'existant — étape 1a (§28.5)

> **Pourquoi ce document.** Le §28.5 exige, avant d'écrire une ligne, la liste
> de ce qui **existe déjà et sert le lot** — « on étend, on ne réinvente pas ».
> Le §28.3 exige que rien n'y soit affirmé sans mesure : tout ce qui suit a été
> lu dans le code ou interrogé en base le **2026-08-19**, jamais déduit d'un
> rapport antérieur.
>
> Méthode : schéma réel de la base
> (`information_schema.columns`), `grep` sur `backend/app`, `backend/routes`,
> `backend/tests`, `frontend/src`, `workers`, lecture de `app/Crm/Taxonomy.php`
> et de `routes/api.php`, et vérification du drapeau sur le serveur de
> production.

---

## 1. Ce qui EXISTE et sert directement l'étape 1a — à étendre

| Objet | Où | Ce qu'il apporte à 1a |
|---|---|---|
| **`person_key`** sur `contacts`, `candidates`, `activities` | base | **La colonne vertébrale de la fiche.** C'est déjà l'identité de la personne à travers les deux univers (business / vivier). Le multi-types du §2.2 se construit dessus, pas à côté. |
| **`activities`** étendue (`kind`, `occurred_at`, `subject_type`, `subject_id`, `title`, `payload`) | base + `Taxonomy::ACTIVITY_KINDS` | Timeline unifiée polymorphe (§3.1), déjà cloisonnée par workspace, déjà pensée pour ça (migration `2026_08_14_000004`). |
| **`PersonTimelinePage.tsx`** (« FICHE 360° ») + `GET /crm/persons/{personKey}/timeline` | frontend + API | **La fiche existe déjà en germe.** Elle indexe les touchpoints sans copier leur contenu. La fiche de 1a est son extension, pas un écran neuf. |
| **`ContactsHubPage.tsx`** + `/crm/contacts-hub` + `/counts` | frontend + API | Le hub et ses compteurs — pièce 1 déjà livrée et optimisée. |
| **`tags`** gouvernés (`slug`, `category`, `kind`, `is_locked`, `namespace` généré) + `company_tag` / `candidate_tag` + `TagsManagerPage.tsx` | base + frontend | **Les tags libres du §27 existent déjà**, avec leur gouvernance (`Taxonomy::TAG_NAMESPACES`). Rien à créer. |
| **`saved_views`** (`entity`, `filters jsonb`, `is_default`) | base | La table des **vues épinglées** est là, à la colonne près. ⚠️ voir §3 : personne ne l'utilise. |
| **`notifications`** (`type`, `title`, `body`, `action_url`, `read_at`) | base, 10 fichiers applicatifs | Vivante. Sert les rappels et les notifications de 1a. |
| **`Taxonomy`** — source de vérité unique, listes fermées, comparées aux `CHECK` réels par `Feature\Crm\SocleCrmTest` | `app/Crm/Taxonomy.php` | **Le patron à suivre** pour toute nouvelle taxonomie de 1a (activités, motifs) : ajouter une valeur sans migration fait ROUGIR la CI. |
| **`crm-console`** middleware + drapeau `crm.console_v2` | `routes/api.php` | Tout `/v1/crm/*` est déjà derrière un drapeau. `CRM_CONSOLE_V2_ENABLED=true` **vérifié sur la production le 19/08**. |
| **`ArbitragePage`** + `/crm/arbitrage/*` | frontend + API | Rapprochement / arbitrage des doublons — servira le §1.2 et l'étape 1c. |

---

## 2. Ce qui N'EXISTE PAS DU TOUT — à construire

| Exigence | État |
|---|---|
| **Activités (§2.3)** — les cinq activités d'AXION IA | ❌ aucune table, aucune colonne, aucune constante |
| **Motifs d'échange (§2.3)** — les 11 motifs non commerciaux | ❌ idem |
| **Dossiers (§3.1 bis)** — le regroupement des échanges | ❌ aucune table `dossiers`, aucun `dossier_id` |
| **Multi-types sur une fiche (§2.2)** | ❌ voir §4 — le modèle actuel dit le contraire |
| **Interaction consignée à la main (§3.2)** — appel, e-mail, réunion, note | ❌ `activities.kind` ne porte **aucun** de ces mots (voir §4) |
| **Écran d'entretien, trames (§6, §8.1)** | ❌ rien |
| **Vue « aujourd'hui »** | ❌ rien |
| **Règles d'attribution** | ❌ rien |
| **Compte rendu envoyé au contact (§6.4)** | ❌ rien |
| **Notifications Telegram** | ❌ rien côté CRM |

---

## 3. 🟠 Ce qui existe en BASE mais que RIEN n'exécute — l'échafaudage mort

Six tables de la phase 2 (mai 2026) couvrent, sur le papier, des besoins de
l'étape 1a. **Aucune n'a de modèle Eloquent, de contrôleur, de route ni d'écran.**

| Table | Ce qu'elle promet | Qui la cite dans le code applicatif |
|---|---|---|
| `crm_tasks` | tâches et rappels (§13) | — |
| `crm_notes` | notes de fiche (§3.1) | — |
| `crm_pipelines`, `pipeline_stages` | parcours (§2.5) | — |
| `deals`, `deal_history` | opportunités (§2.4) | — |
| `saved_views` | vues épinglées (§18) | — |

Les **seuls** fichiers qui les nomment sont `PentestSelfCheck.php` (inventaire
de sécurité), `SemeurTablesScopees.php` (aide de test d'isolation) et
`PasDeStub501SousCrmEtAnalyticsTest.php` (garde de routage). Autrement dit :
elles sont **surveillées et cloisonnées par la RLS, mais vides et inertes**.

⚠️ **Le piège à ne pas manquer.** Un inventaire superficiel lit « il y a déjà une
table `crm_tasks` » et conclut « les tâches existent, on étend ». C'est faux :
il n'y a qu'un nom de table. Et le schéma trahit son âge — `crm_tasks` n'a ni
`dossier_id`, ni règle de rappel, ni récurrence, ni notification ; `deals` n'a
pas de rattachement à une **activité**, que le §2.4 rend obligatoire.

**Recommandation** (à trancher au moment d'écrire la pièce, pas ici) : réutiliser
les **noms** et les colonnes qui conviennent, et les étendre par migration
additive ; ne pas créer `tasks` à côté de `crm_tasks`, ce qui donnerait deux
tables pour une notion — la faute exacte que le §28.5 cherche à éviter.

---

## 4. 🔴 Ce que l'inventaire a RÉFUTÉ — trois écarts entre le cahier des charges et le code

Ce ne sont pas des manques, ce sont des **contradictions**. Les livrer sans les
poser, c'est bâtir sur du faux.

### 4.1 Le type est porté par l'ORGANISATION, pas par la personne

`relation_type` et `lifecycle_stage` sont des colonnes de **`companies`** et de
**`candidates`**. La table **`contacts` ne les porte pas** — vérifié colonne par
colonne. Le modèle actuel est celui d'une prospection B2B : l'entreprise est
l'objet principal, le contact est une personne rattachée à elle.

Or le §2.1 dit « chaque **type** détermine les champs affichés, l'indicateur de
complétude, les étapes du parcours » d'une **fiche de personne**, et le §2.2
parle d'« un **contact** [qui] peut porter plusieurs types ».

De plus, presse, candidat, journaliste et praticien vivent aujourd'hui dans des
tables **séparées** (`journalists`, `media`, `candidates`, `health_practitioners`).
« Une même personne candidate ET cliente » n'est donc pas seulement absente :
elle est structurellement impossible en l'état.

### 4.2 Le modèle actuel prend explicitement le parti INVERSE du multi-types

`Taxonomy::BUSINESS_RELATION_PRIORITY` documente un « **upgrade** automatique du
type : une fiche porte TOUJOURS le type le plus engageant qu'elle a atteint ».
C'est un choix délibéré, écrit, testé — et c'est le contraire du §2.2, qui veut
l'**union** des types avec un **type principal** désigné.

Ce n'est pas un bogue : c'était le bon choix pour la collecte. C'est un arbitrage
à reprendre pour 1a, pas une négligence à corriger en silence.

### 4.3 Deux listes de types divergent, et l'une contredit la notion de motif

| | Cahier des charges §2.1 | `Taxonomy::BUSINESS_RELATION_TYPES` |
|---|---|---|
| Présents des deux côtés | prospect, client, presse, partenaire, investisseur, fournisseur | ✅ |
| **Absents du code** | *non qualifié*, *prospect froid*, contact professionnel, autre | ❌ |
| **En trop dans le code** | — | `conference`, `newsletter` |

`conference` est un **type** dans le code ; le §2.3 en fait un **motif**
(« conférence / prise de parole »). `newsletter` n'est un type dans aucune
des deux listes du cahier des charges.

Les deux types en italique comptent : le §2.1 précise qu'ils existent « parce que
le produit les crée lui-même » — *non qualifié* pour un premier message dont on
ne sait rien (§9.1), *prospect froid* pour la collecte (§11). **Toute la
production actuelle est de la collecte** : ces 4,29 M de fiches sont, au sens du
cahier des charges, des *prospects froids* — un type qui n'existe pas encore.

---

## 5. Ce que l'inventaire change dans l'ordre des pièces

L'ordre naïf serait « fiche → activités → dossiers → tâches ». L'inventaire dit
qu'il faut commencer **une marche plus bas** :

1. **Trancher §4.1 / §4.2** — où vit le type, et le multi-types. Tout le reste en
   dépend : les dossiers se rattachent à une fiche, les motifs à un échange, les
   trames à un motif. Se tromper ici se paye sur toutes les pièces suivantes.
2. **Activités et motifs (§2.3)** — nouvelle taxonomie fermée, sur le patron de
   `Taxonomy`, avec sa garde vue rouge.
3. **Vocabulaire d'interaction humaine** — étendre `ACTIVITY_KINDS` (appel,
   e-mail, réunion, note, entretien) : c'est la condition du critère « un appel
   se consigne en 1 clic avec le bon motif ».
4. **Dossiers (§3.1 bis)**, puis rattachement des interactions.
5. **Tâches et rappels**, en réveillant `crm_tasks` plutôt qu'en la doublant.
6. **Vue « aujourd'hui »**, qui se nourrit de 4 et 5 — elle ne peut pas venir
   avant ce qu'elle affiche.

---

## 6. Pièges du dépôt à parer (§28.5, dernière puce)

| Piège | Parade retenue |
|---|---|
| **Aucun hook Git dans ce dépôt** — `core.hooksPath` vide, `.git/hooks` sans hook actif (vérifié) | Rien ne rattrapera un format ou un lint avant le push : la CI est le **seul** filet. Lancer Pint / PHPStan / ESLint à la main avant chaque commit. |
| **Fins de ligne** — Git avertit `LF will be replaced by CRLF` sur ce dépôt | Ne jamais écrire de test statique qui cherche un `\n` littéral. |
| **Taxonomie fermée gardée par `SocleCrmTest`** | Toute valeur ajoutée à `Taxonomy` sans migration du `CHECK` fait rougir la CI — c'est voulu, et c'est la garde à réutiliser pour les activités et motifs. |
| **`crm.console_v2`** | Toute route de 1a doit naître **dans** le groupe `crm-console`. Hors du groupe, elle serait publique ; le fourre-tout 501 qui masquait ce genre d'oubli a été retiré (F7). |
| **Échafaudage mort (§3)** | Ne pas conclure « ça existe » sur la seule présence d'une table. |
