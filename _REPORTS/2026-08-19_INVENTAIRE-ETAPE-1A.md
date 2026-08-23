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
| **`notifications`** (`type`, `title`, `body`, `action_url`, `read_at`) | base + cloche in-app | Table **sans écrivain** : lue par `NotificationsController` et l'export RGPD, purgée par la rétention et l'effacement, **jamais alimentée**. ⚠️ voir §3 bis — ce n'est pas une pièce à étendre, c'est un producteur à écrire. |
| **`Taxonomy`** — source de vérité unique, listes fermées, comparées aux `CHECK` réels par `Feature\Crm\SocleCrmTest` | `app/Crm/Taxonomy.php` | **Le patron à suivre** pour toute nouvelle taxonomie de 1a (activités, motifs) : ajouter une valeur sans migration fait ROUGIR la CI. |
| **`crm-console`** middleware + drapeau `crm.console_v2` | `routes/api.php` | Tout `/v1/crm/*` est déjà derrière un drapeau. `CRM_CONSOLE_V2_ENABLED=true` **vérifié sur la production le 19/08**. |
| **`ArbitragePage`** + `/crm/arbitrage/*` | frontend + API | Rapprochement / arbitrage des doublons — servira le §1.2 et l'étape 1c. |

---

## 2. Ce qui N'EXISTE PAS DU TOUT — à construire

> ⚠️ **Deux lignes de ce tableau ont été rectifiées le 2026-08-22** (A09-003) :
> elles déclaraient absent ce qui existait déjà le jour même. Le tableau porte
> l'état mesuré, l'encart qui le suit porte ce qui était écrit.

| Exigence | État |
|---|---|
| **Activités (§2.3)** — les cinq activités d'AXION IA | ✅ **construit** — table `crm_activites`, constante `ActivitesEtMotifs::ACTIVITES`. Voir la RECTIFICATION 2026-08-22 sous ce tableau |
| **Motifs d'échange (§2.3)** — les 11 motifs non commerciaux | ✅ **construit** — table `crm_motifs`, constante `ActivitesEtMotifs::MOTIFS`. Voir la RECTIFICATION 2026-08-22 sous ce tableau |
| **Dossiers (§3.1 bis)** — le regroupement des échanges | ❌ aucune table `dossiers`, aucun `dossier_id` |
| **Multi-types sur une fiche (§2.2)** | ❌ voir §4 — le modèle actuel dit le contraire |
| **Interaction consignée à la main (§3.2)** — appel, e-mail, réunion, note | ❌ `activities.kind` ne porte **aucun** de ces mots (voir §4) |
| **Écran d'entretien, trames (§6, §8.1)** | ❌ rien |
| **Vue « aujourd'hui »** | ❌ rien |
| **Règles d'attribution** | ❌ rien |
| **Compte rendu envoyé au contact (§6.4)** | ❌ rien |
| **Notifications Telegram** | ❌ rien côté CRM |

> ### RECTIFICATION 2026-08-22 — deux lignes de ce tableau étaient fausses le jour même
>
> **Pourquoi elle est ici et pourquoi les deux lignes d'origine restent lisibles.**
> Ce document sert d'entrée au §28.5 : on le lit pour décider quoi construire.
> Deux lignes y déclaraient absent ce qui existait déjà — les relire, c'est
> refaire une taxonomie qui a sa migration, son semis et sa garde. Un document
> qui ment est pire qu'un document absent, parce qu'on le suit. Les deux
> affirmations du 19/08 ne sont donc pas effacées : elles sont datées, et
> corrigées ici (constat **A09-003** de l'audit 360).
>
> **Ce qui était écrit ici le 2026-08-19**, mot pour mot, et qui était déjà faux
> ce jour-là :
>
> | Exigence | État écrit le 19/08 |
> |---|---|
> | **Activités (§2.3)** — les cinq activités d'AXION IA | ❌ aucune table, aucune colonne, aucune constante |
> | **Motifs d'échange (§2.3)** — les 11 motifs non commerciaux | ❌ idem |
>
> **Ce qui a été mesuré le 2026-08-22**, par lecture du dépôt — pas par lecture
> d'un rapport antérieur :
>
> | Ce que le 19/08 déclarait absent | Ce qui existe, et où |
> |---|---|
> | table des activités | `crm_activites` — `backend/database/migrations/2026_08_19_000002_crm_activites_et_motifs.php` (`CREATE TABLE IF NOT EXISTS crm_activites`) |
> | table des motifs | `crm_motifs` — même migration, contrainte `crm_motifs_espace_check` sur `ESPACES` |
> | constante des cinq activités | `App\Crm\ActivitesEtMotifs::ACTIVITES` (5 entrées, drapeau `qualiopi` sur `formation`) |
> | constante des onze motifs | `App\Crm\ActivitesEtMotifs::MOTIFS` (11 entrées, de `presse` à `autre`) |
>
> **Même jour que ce document** : la migration est arrivée par le commit
> `504737f` du 2026-08-19 (« feat(etape1a-p2) : les cinq activités et les onze
> motifs d'échange (§2.3) ») ; le dernier commit touchant cet inventaire est
> `a832d88`, du 2026-08-19 également. L'inventaire n'a jamais été relu après le
> travail qu'il devait annoncer — c'est le mécanisme du défaut, pas un oubli
> isolé : **un inventaire se réouvre à la fin du lot qu'il ouvre.**
>
> ⚠️ Le semis est fait **par la migration**, pas par un seeder : l'entrypoint de
> production ne joue que `migrate deploy`. C'est une raison de plus de ne pas
> reconstruire ces tables « puisqu'elles n'existent pas ».

---

## 3. 🟠 Ce qui existe en BASE mais que RIEN n'exécute — l'échafaudage mort

Six tables de la phase 2 (mai 2026) couvrent, sur le papier, des besoins de
l'étape 1a. **Cinq d'entre elles n'ont ni modèle Eloquent, ni contrôleur, ni
route, ni écran** — pour la sixième, voir la RECTIFICATION sous le tableau.

| Table | Ce qu'elle promet | Qui la cite dans le code applicatif |
|---|---|---|
| `crm_tasks` | tâches et rappels (§13) | — |
| `crm_notes` | notes de fiche (§3.1) | — |
| `crm_pipelines`, `pipeline_stages` | parcours (§2.5) | — |
| `deals`, `deal_history` | opportunités (§2.4) | — |
| `saved_views` | vues épinglées (§18) | ⚠️ **contrôleur + trois routes** — voir la RECTIFICATION 2026-08-22 sous ce tableau |

Pour les **cinq premières**, les seuls fichiers qui les nomment sont
`PentestSelfCheck.php` (inventaire de sécurité), `SemeurTablesScopees.php` (aide
de test d'isolation) et `PasDeStub501SousCrmEtAnalyticsTest.php` (garde de
routage). Autrement dit : elles sont **surveillées et cloisonnées par la RLS,
mais vides et inertes**.

> ### RECTIFICATION 2026-08-22 — `saved_views` n'est pas de l'échafaudage mort
>
> **Pourquoi cette rectification est ici, et pourquoi la phrase d'origine reste
> lisible.** Ce document sert d'entrée au §28.5 : on le lit pour décider quoi
> construire. Ranger `saved_views` dans l'échafaudage mort revient à faire
> réécrire un contrôleur qui existe et re-trancher un cloisonnement déjà tranché.
> Un document qui ment est pire qu'un document absent, parce qu'on le suit — même
> raison que la RECTIFICATION du §2. Constat **A09-004** de l'audit 360.
>
> **Ce qui était écrit ici le 2026-08-19**, mot pour mot : « **Aucune n'a de
> modèle Eloquent, de contrôleur, de route ni d'écran.** », et `saved_views`
> rangée dans le tableau avec « Qui la cite dans le code applicatif : — ».
>
> **Ce qui a été mesuré le 2026-08-22**, par lecture du dépôt — pas par lecture
> d'un rapport antérieur :
>
> | Ce que le 19/08 déclarait absent | Ce qui existe, et où |
> |---|---|
> | contrôleur | `backend/app/Http/Controllers/Api/SavedViewsController.php` — son en-tête décrit explicitement le remplacement du bouchon `return $this->ok(['data' => []])`, au titre des constats A-002 et B12-007 |
> | routes | `backend/routes/api.php` — `use App\Http\Controllers\Api\SavedViewsController;` puis **trois** `Route::apiResource('saved-views', …)` : `index`/`show` sous `permission:companies.view`, `store`/`update` sous `companies.update`, `destroy` sous `companies.delete` |
>
> **Ce qui reste vrai, et qu'il ne faut pas sur-corriger** : `store`, `update` et
> `destroy` répondent encore `501` — le contrôleur le dit lui-même. Seules `index`
> et `show` sont implémentées, et aucun écran ne consomme la ressource. La table
> reste donc sans écriture ; elle n'est pas pour autant inerte, elle est LUE, par
> du code qui a déjà tranché son cloisonnement (une vue appartient à une
> personne, pas à un espace).

⚠️ **Le piège à ne pas manquer.** Un inventaire superficiel lit « il y a déjà une
table `crm_tasks` » et conclut « les tâches existent, on étend ». C'est faux :
il n'y a qu'un nom de table. Et le schéma trahit son âge — `crm_tasks` n'a ni
`dossier_id`, ni règle de rappel, ni récurrence, ni notification ; `deals` n'a
pas de rattachement à une **activité**, que le §2.4 rend obligatoire.

**Recommandation** (à trancher au moment d'écrire la pièce, pas ici) : réutiliser
les **noms** et les colonnes qui conviennent, et les étendre par migration
additive ; ne pas créer `tasks` à côté de `crm_tasks`, ce qui donnerait deux
tables pour une notion — la faute exacte que le §28.5 cherche à éviter.

### 3 bis. 🟠 `notifications` — lue, exportée, purgée… et jamais alimentée

Ce document affirmait, jusqu'au 2026-08-22, que `notifications` était
**« Vivante. Sert les rappels et les notifications de 1a. »** C'est faux, et
c'est la sorte de phrase qui coûte cher : elle range la table au §1 (« à
étendre »), et l'auteur de la pièce découvre l'absence de producteur au milieu
du travail.

**Mesure du 2026-08-22** (constat I48-006), `grep` de `'notifications'` sur
`backend/app`, `backend/routes`, `backend/config`, `backend/database/seeders` :

| Rôle | Qui | Où |
|---|---|---|
| Lecture | cloche in-app | `NotificationsController::index` (`DB::table('notifications')`) |
| Lecture | export RGPD art. 15/20 | `GdprPortabilityService` |
| Suppression | rétention > 90 j | `RetentionPurge` |
| Suppression | droit à l'effacement | `GdprErasureService` |
| Surveillance | inventaire de sécurité | `PentestSelfCheck` |
| **Écriture** | **personne** | **aucun `insert`, aucun modèle Eloquent `Notification`, aucun `->notify()`** |

Le trait `Notifiable` est bien déclaré sur `App\Models\User`, et n'est appelé
nulle part. Et `POST /notifications/{n}/read` comme `POST /notifications/read-all`
répondent encore **501**.

Autrement dit : la cloche est câblée de bout en bout **sauf** au bout qui
compte. La table n'est pas à étendre — **le producteur est à écrire**, et les
deux `501` avec lui. La garde
`Feature\Infra\InventaireEtape1aNeMentPasSurNotificationsTest` tient les deux
bouts : le jour où un écrivain apparaît, elle rougit et renvoie ici pour
reclasser la ligne en §1.

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
