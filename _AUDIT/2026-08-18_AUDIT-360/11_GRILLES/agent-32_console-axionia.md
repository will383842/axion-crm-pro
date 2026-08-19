# AGENT 32 — Les écrans « Contacts » de la console axionia

> Auditeur : agent 32. Périmètre : la section « Contacts » de la console d'administration du
> **site** (`Axion-IA/axionia`), le module `calendrier` mort, la frontière `booking` / `planning`,
> et l'écart au **critère 23** et au **critère 25** du §29 du CDC.

## Références mesurées (relues moi-même, règle 1 et 14 du dossier)

| Objet | Référence |
|---|---|
| Dépôt **site** (objet du mandat) | `main` **`eb754332`** — `fix(qualiopi): l'espace stagiaire livrait la pièce d'un tiers…` (#739) |
| Dépôt **CRM** (pour les équivalents) | `main` `c0c453d` |
| CDC | `C:\Users\willi\Downloads\axion-ia-crm-cahier-des-charges-fonctionnel-v2.md`, 983 lignes |

**Atelier local CRM : non utilisé** (consigne du mandat, constat A-009). Toutes les mesures de ce
rapport sont **statiques sur le code**, sauf une requête HTTP de lecture seule en production
(§ « ce que je n'ai pas pu vérifier »).

Preuves brutes : `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/agent-32/`
`01_nav-count.txt` · `02_flags-et-liens.txt` · `03_routes-et-sources.txt` · `04_prod-features.txt`

---

## 1. RECOMPTE — ce que le mandat annonce, ce que le code dit

Méthode : `buildAdminNav("admin-xyz")` **exécutée** (tsx), pas lue. Les deux listes dynamiques
(`QR_CATEGORIES`, `IMPRIMES`) sont donc dépliées, ce qu'un `grep` ne fait pas.
Sortie : `04_PREUVES/agent-32/01_nav-count.txt`.

| Ce que le mandat / le §A annonce | Ce que la mesure rend | Écart |
|---|---|---|
| « ~150 entrées de navigation » | **153** items dans le SSOT `src/lib/admin-nav.ts`, **+1** entrée « Prospection ↗ » codée en dur hors SSOT = **154 destinations** | ≈ juste. 141 visibles en barre latérale (12 masquées par `parent`), 20 masquées en mode Simple (`tier: "advanced"`) |
| « 12 écrans Contacts » | **12** entrées dans le groupe `contacts` — **mais ce ne sont pas les 12 de la liste** | ✅ sur le nombre, ❌ sur 2 items sur 12 |
| Liste du mandat : `Appels réservés · Messages · Clients · Presse · Partenariats · Investisseurs · Conférences · Recrutement · Podcast · Autres · Candidatures · Rendez-vous + calendrier` | La liste réelle **n'a pas** « Rendez-vous + calendrier » (retiré de la nav le 2026-07-29) et **a** une entrée « **Tout** » (`/contacts`, boîte de réception unifiée) que le mandat ignore | 2 items faux sur 12 |
| — | « Rendez-vous » et « Calendrier RDV » **existent encore comme URL**, mais ce sont **4 redirections** (`contacts/rendez-vous`, `contacts/rendez-vous/calendrier`, `contacts/calendly`, `contacts/calendly/[id]`) | à ne pas compter comme écrans |

**Le compte exact du périmètre relation** (mesure `03_routes-et-sources.txt`) :

- **12** entrées de barre latérale, groupe `contacts` (dont 8 en niveau 2) ;
- **17 écrans vivants** = les 12 listes + 5 fiches de détail hors nav
  (`contacts/appels/[id]`, `contacts/messages/[id]`, `contacts/commercial/[id]`,
  `contacts/candidatures/[id]`, `podcast/[id]`) ;
- **8 routes de redirection** de compatibilité dans le périmètre relation
  (`contacts/rendez-vous`, `…/calendrier`, `contacts/calendly`, `contacts/calendly/[id]`,
  `submissions`, `submissions/[id]`, `candidatures`, `candidatures/[id]`) — **toutes retombent
  dans la console**, aucune ne va au CRM ;
- **2 routes de fichier** (`contacts/candidatures/[id]/cv`, `…/photo`) qui servent le CV et la
  photo du candidat ;
- **5 redirections du module Booking mort** (`calendrier`, `calendrier/heatmap`,
  `calendrier/reschedule`, `reservations`, `reservations/[id]`) → `planning` / `qualiopi/dossiers` ;
- **27** pages purement redirectrices dans toute la console (`Promise<never>`), dont **0** vers le CRM.

**Et 12 écrans ne veulent pas dire 12 objets : ils lisent 4 tables.**
**8 des 12 entrées lisent la même table `Submission`** — une non filtrée (« Messages ») et sept avec
un `forcedTypes` figé (Clients, Presse, Partenariats, Investisseurs, Conférences, Recrutement,
Autres). Le §A a raison sur le fond : c'est un CRM en doublon. Il se trompe seulement sur l'étendue —
le doublon est **plus concentré** qu'annoncé (4 tables), et **plus large** que la seule section
Contacts (le podcast vit hors `/contacts`, à `/podcast`).

### Frontière `booking` / `planning` — vérifiée, elle tient

Le module Booking est **mort et redirigé** : `/calendrier` → `/planning` (308),
`/reservations` → `/qualiopi/dossiers` (308), avec garde `auth()` conservé. Les Server Actions et
modèles Prisma restent en place (suppression dure = lot séparé, assumé en commentaire).
`planning/*` (7 entrées, groupe `main`) reste bien de **l'exécution** (charge formateurs, timeline
ressources, prévisionnel, pipeline) : il **reste** dans la console au titre du principe 2 du §0.
**Rien à retirer de ce côté.**

---

## 2. LIVRABLE 1 — le doublon, écran par écran

Colonne la plus importante : la dernière. C'est elle qui dit **ce qu'on perdrait**.
« CRM » ci-dessous = la console CRM v2 (`/console/*`), seule surface du CRM qui parle de relation.

| # | Écran du site | Ce qu'il montre | Données lues | Équivalent CRM ? | Lequel | ⚠️ Ce que le site montre et que le CRM ne montre PAS |
|---|---|---|---|---|---|---|
| 1 | `/contacts` — **Tout** | Chronologie unifiée des 4 canaux d'entrée, pastille « demande une action », canal en couleur, canaux en panne signalés | `listInbox()` : `Submission` + `calendly_events` + `JobApplication` + `PodcastRequest`, fenêtre 100/canal, tri et pagination en mémoire | **NON** | — | **Tout l'écran.** Le CRM n'a aucune boîte de réception. Sa seule vue « ce qui est arrivé » est la timeline d'**une** personne, qu'il faut donc déjà connaître |
| 2 | `/contacts/appels` — **Appels réservés** (+ vue `?vue=calendrier`) | Liste **et grille mensuelle** des rendez-vous Calendly, filtres par statut, bouton « créer un événement à la main », enrichissement API | `listRendezVous()` → `calendly_events` | **NON** | — | Le créneau (`startTime`/`endTime`/`timezone`), le **lieu ou le lien Meet** (`location`), les URL d'annulation et de report, la grille calendaire, le rattachement manuel à une soumission, la création manuelle, l'enrichissement API |
| 3 | `/contacts/appels/[id]` | Fiche d'un appel : édition des champs, notes de débrief, payload brut | `prisma.calendlyEvent` | **NON** | — | `notes` (débrief), `rawPayload`, `linkedSubmissionId`, `enrichedAt`, édition sur place |
| 4 | `/contacts/messages` | **Toutes** les soumissions : badge de réponse (Sans réponse / Répondu (N) / Échec envoi / Archivé), onglets Actifs / Archivés / **Corbeille**, **export CSV** | `listSubmissionsAction()` → `Submission` | **partiel** | `/console/contacts`, onglet « Tous » | Le badge de réponse, l'archive, la corbeille, l'export CSV, le tri par statut de traitement. Le CRM liste des **entreprises**, pas des messages |
| 5 | `/contacts/messages/[id]` | **Le corps du message**, **répondre par e-mail** depuis la console, **historique des réponses + statut de remise**, notes internes, statut, réservations liées | `SubmissionDetailContent` → `getSubmissionDetailAction` | **NON** | — | **Le texte du message.** Les réponses envoyées et leur remise. Les notes internes. Le statut. Les `Booking` liés. L'empreinte anti-spam (`turnstileScore`, `ipHash`, `userAgent`, `referer`) |
| 6 | `/contacts/clients` | Idem 4, filtré sur 6 types (`audit`, `implementation`, `formation`, `un_a_un`, `devis`, `support_client`) | `Submission` + `forcedTypes` | **oui, en onglet** | `/console/contacts` onglet **Clients** | idem 4 — et le CRM tranche par `relation_type` de l'**entreprise**, pas par type de **demande** : un client qui écrit pour un audit reste « client », la nature de sa demande disparaît |
| 7 | `/contacts/presse` | Idem, `unifiedType = presse` | idem | **oui, en onglet** | `/console/contacts` onglet **Presse & médias** (+ `/journalists`, `/media`) | idem |
| 8 | `/contacts/partenariats` | Idem, `partenariat` | idem | **oui, en onglet** | onglet **Partenaires** | idem |
| 9 | `/contacts/investisseurs` | Idem, `investisseur` | idem | **oui, en onglet** | onglet **Investisseurs** | idem |
| 10 | `/contacts/conferences` | Idem, `speaker` | idem | **oui, en onglet** | onglet **Conférences** | idem |
| 11 | `/contacts/commercial` — **« Recrutement »** | Idem, `recrutement` (candidatures du réseau commercial) | idem | **partiel** | `/console/vivier`, famille `candidat_commercial` | idem — et le vivier est **vide** (A05-001/A05-003) |
| 12 | `/podcast` + `/podcast/[id]` | Demandes de tournage : entreprise, dirigeant (déchiffré), **lieu du tournage**, workflow de statuts | `prisma.podcastRequest` | **NON** | — | **La totalité des champs métier.** Le canal transporte un `form_submission` de `form_type: "podcast"` — aucun champ du tournage |
| 13 | `/contacts/autres` | Idem 4, `autre` | idem | **oui, en onglet** | `/console/contacts` (aucun onglet dédié) | idem |
| 14 | `/contacts/candidatures` | Candidatures **fusionnées** : offres d'emploi (`JobApplication`) + candidatures commerciales (`Submission` `subType=candidature-commerciale`), sous-onglets Toutes / Monteur vidéo / Mémo Isère, filtre « demande attention » | `listCandidaturesUnifieesAction` + `listApplicationsAction` | **partiel** | `/console/vivier` | La fusion des deux sources, le filtre « attention », le rattachement à l'offre |
| 15 | `/contacts/candidatures/[id]` | Fiche candidat : **CV téléchargeable**, **photo**, réponses au questionnaire, instantané de l'offre, **formulaire de changement de statut** | `getApplicationDetailAction` | **NON** | — | **Le fichier CV et la photo** (le canal n'envoie qu'un `cv_ref`), les réponses au questionnaire, l'instantané de l'offre, le workflow de statut (6 états) |
| — | `/contacts/rendez-vous`, `…/calendrier`, `/contacts/calendly`, `/contacts/calendly/[id]` | **Redirections** (2026-07-29) vers `/contacts/appels` en préservant `year`/`month`/`date` | — | — | — | Rien : elles ne montrent rien. Elles existent pour les favoris et **les liens déjà envoyés par Telegram** |

### Ce qui existe côté CRM, et qui n'a pas d'équivalent site

`/console/arbitrage` — **l'écran d'arbitrage exigé par le §25.1** existe déjà et sait
`attach` / `dismiss` un événement dont la personne n'est pas identifiée. C'est le seul écran
**mutant** de la console CRM v2. `/console/personnes/$personKey` (fiche 360°) et
`/console/contacts` et `/console/vivier` sont, eux, **en lecture seule** — aucune écriture,
mesuré : aucun `api.post/patch/put/delete` hors `ArbitragePage`.

### 🔴 La raison de fond, et elle est **écrite dans le CRM lui-même**

`frontend/src/features/crm-console/PersonTimelinePage.tsx:4` :

> « La timeline est un **INDEX** des touchpoints, jamais une copie de leur contenu (plan §2.6) :
> chaque ligne référence sa source, le détail reste dans le système qui l'a produit. »

Et `PersonTimelineController` ne projette que `id, universe, kind, title, occurred_at,
external_ref, subject_type, subject_id`. **C'est un parti pris de conception, pas un manque.**

Conséquence directe et mesurable sur le principe 10 (« tout ce qui concerne une personne se voit et
se **fait** dans le CRM ») : tant que la timeline est un index et que le canal ne transporte pas de
contenu, **« le système qui a produit le détail » est précisément la console qu'on veut retirer.**
Retirer les 17 écrans avant d'avoir résolu cette contradiction ne déplace pas l'information : elle
la rend inatteignable.

### Ce que le canal transporte — et ce qu'il ne transporte pas (mesuré)

`src/server/crm-sync/types.ts` : `CrmSyncEvent` = `person` (clé, e-mail, nom, téléphone) +
`company` + `consent` + `candidate` (famille, offre, attributs, `cv_ref`) + `tags` + `payload`.
`src/features/unified-contact/actions.ts:250-275` : le `payload` d'un formulaire ne contient que
`subType`, `source`, `funnel`. `src/app/api/calendly/client-event/route.ts:173-185` : le payload
d'un appel ne contient que `eventTypeSlug`, `pageUrl`, `utm*`.

**13 informations que le site détient et que le CRM ne reçoit jamais :**

1. le **corps du message** (`Submission.details`) ; 2. les **réponses envoyées** (`SubmissionReply`)
et leur remise (`failed`/`bounced`) — aucun `CrmEventType` ne les couvre ; 3. les **notes internes**
(`Submission.internalNotes`, `CalendlyEvent.notes`) ; 4. `assignedTo` ; 5. le **statut de
traitement** (4 états Submission, 6 états JobApplication, statuts PodcastRequest) ;
6. l'**archivage / la corbeille** (`archivedAt`, `deletedAt`) ; 7. les **marqueurs de lecture**
(`markInboxRead`) ; 8. **l'heure du rendez-vous** (`startTime`/`endTime`/`timezone`) ; 9. le **lieu
ou le lien Meet** (`location`) et les URL d'annulation/report ; 10. le **fichier CV** et la
**photo** ; 11. les **réponses au questionnaire** de candidature ; 12. tous les **champs du
tournage podcast** ; 13. l'**empreinte anti-spam et de provenance** (`turnstileScore`, `ipHash`,
`userAgent`, `referer`, `source=chatbot`).

---

## 3. LIVRABLE 2 — la trajectoire de retrait (§25.1), paliers et conditions d'ouverture

### Le drapeau : **il n'existe pas côté site.** (règle 8 : cherché avant de proposer)

Mesuré (`02_flags-et-liens.txt`, avec **témoin négatif** : le même `git grep` trouve bien
`CRM_SYNC_ENABLED`, `CRM_SYNC_CANDIDATES_ENABLED`, `EN_LOCALE_ENABLED`) :

- `git grep -iE "CRM_REDIRECT|CONTACTS_REDIRECT|RELATION_SCREENS|CONSOLE_CONTACTS_ENABLED|REDIRECT_TO_CRM" -- src/` → **aucun résultat.**
- Les seuls drapeaux liés au CRM dans le site sont **`CRM_SYNC_ENABLED`** et
  **`CRM_SYNC_CANDIDATES_ENABLED`** : ils gouvernent le **canal d'émission**, jamais l'affichage.
- Côté CRM il existe **`CRM_CONSOLE_V2_ENABLED`** (`backend/config/crm.php:168`, défaut `false`),
  qui ferme l'API **et** l'interface par la même poignée (`ConsoleGate` + middleware
  `EnsureCrmConsoleV2`). C'est le drapeau de la **destination**, pas du retrait.

**Ce qu'il faut donc étendre, pas réinventer :**

- ❌ Ne **pas** faire du drapeau de retrait une variable d'environnement. Le critère 25 exige un
  retour « en **moins d'une minute** » ; une variable Coolify impose un **redémarrage de
  conteneur**, dont la durée n'est pas mesurée et n'est pas garantie sous 60 s. Un drapeau posé
  ainsi ne serait **pas démontrable**.
- ✅ Le poser dans **`SiteSetting`**, table déjà utilisée par le canal
  (`crm_sync_activated_at`, `src/server/crm-sync/reconcile.ts:143`), lue par requête, éditable
  depuis la console. Retour en **un clic**, sans redéploiement — et donc **mesurable**.
- ✅ Étendre **`collectReconciliation()`** plutôt qu'écrire un nouveau comparateur (voir E32-003).

### Palier 0 — préalable, **non tenu aujourd'hui**

| | |
|---|---|
| Ce qui bascule | **Rien.** Aucun écran ne bouge. |
| Ce qui doit continuer d'arriver | tout (état actuel) |
| Condition d'ouverture, **mesurable** | ① `CRM_SYNC_ENABLED = "true"` **constaté en production** (aujourd'hui non mesurable de l'extérieur) ; ② **au moins une** fiche CRM porte une `person_key` — aujourd'hui **0 sur 1 319 567** (A05-001) ; ③ `/console/personnes/{person_key}` rend une timeline non vide pour une soumission réelle du site ; ④ `CRM_CONSOLE_V2_ENABLED = true` en production. |
| Retour arrière | sans objet |

> Tant que ce palier n'est pas franchi, **la fiche 360° est inatteignable** et le vivier vide :
> aucun bandeau, aucune redirection, aucun retrait n'a de sens.

### Palier 1 — la parité prouvée (§25.1 étape 1, critère 18)

| | |
|---|---|
| Ce qui bascule | **Rien à l'écran.** On instrumente. |
| Ce qui doit continuer d'arriver | tout |
| Condition d'ouverture | sur **7 jours glissants**, pour les **5 familles** (`submission`, `job_application`, `calendly_event`, `newsletter_subscriber`, `customer_review`) : nombre vu par la console = nombre **accepté par le CRM**, **écart zéro**, tout écart expliqué ligne à ligne. **Comptage côté CRM**, pas côté outbox (E32-003). |
| Ce qu'il faut construire | étendre `collectReconciliation()` : ① filtrer sur `status = "sent"` **et** `crmResult ∈ {created, updated, noop_idempotent}` ; ② ajouter un compteur lu **chez le CRM** (`/crm/...`) et le confronter. Coût : ~1 j. |
| Retour arrière | sans objet (aucune bascule) |

### Palier 2 — le bandeau « Cette vue existe dans le CRM → ouvrir » (§25.1 étape 2)

| | |
|---|---|
| Ce qui bascule | chaque écran de relation affiche un bandeau et un **lien profond** vers la fiche 360° de la personne : `https://app.axion-crm-pro.com/console/personnes/{person_key}`. La clé est **déjà calculée par le site** — `hashEmailForLookup(email)` = `Submission.contactEmailHash` = la `person_key` du CRM (`src/server/crm-sync/index.ts`, en-tête). **Rien à inventer.** |
| Ce qui doit continuer d'arriver | tout — aucun écran n'est retiré, aucune donnée ne bouge |
| Condition d'ouverture | palier 1 vert **et** un lien profond ouvert au hasard sur 10 fiches rend 10 fois une timeline non vide |
| Retour arrière | `SiteSetting["crm_banner_enabled"] = false` → **un rechargement**, le bandeau disparaît |
| Risque | **nul pour l'information** : c'est un ajout. C'est le seul palier ouvrable **avant** que le CRM sache montrer la relation, et il corrige déjà E32-006 |

### Palier 3 — la redirection derrière drapeau (§25.1 étape 3)

**Par famille, pas en bloc** — les 5 familles n'ont pas la même maturité côté CRM.

| Sous-palier | Ce qui bascule | Condition d'ouverture, mesurable |
|---|---|---|
| 3a — les **7 vues filtrées** de `Submission` (Clients, Presse, Partenariats, Investisseurs, Conférences, Recrutement, Autres) | `→ /console/contacts?type=…` | le CRM montre les **7 onglets** peuplés, et une soumission de chaque type y est retrouvée en < 5 s (critère 1) |
| 3b — **Candidatures** | `→ /console/vivier` | `CRM_SYNC_CANDIDATES_ENABLED = true` **et** le vivier n'est plus vide **et** le CRM sait servir **le CV et la photo** (aujourd'hui il n'a qu'un `cv_ref`) |
| 3c — **Appels réservés** | `→` fiche 360° / vue rendez-vous CRM | le CRM affiche **l'heure et le lieu** du rendez-vous — donc le canal doit d'abord les transporter (`startTime`, `location`, `cancelUrl`) : **contrat d'événement à étendre**, refus 422 sinon |
| 3d — **Messages** + **Tout** + **Podcast** | `→` CRM | **le plus lointain.** Suppose que le CRM sache montrer **le corps du message**, **répondre**, **archiver**, et porte un **statut de traitement**. Rien de tout cela n'existe ni dans le contrat d'événement ni dans la console v2 |

| | |
|---|---|
| Ce qui doit continuer d'arriver, **à chaque sous-palier** | les formulaires publics, les réservations Calendly, les messages : **aucun de ces chemins ne passe par les écrans**. Les 7 alertes Telegram qui pointent dans `contacts/*` (`src/server/notifications/format.ts:250,270,304,329,383,400,416`) doivent continuer d'atterrir : **les 8 routes de redirection de compatibilité existantes couvrent déjà ce cas** — ne pas les supprimer |
| Retour arrière, **< 1 minute** | `SiteSetting["console_relation_screens"]` remis à `"legacy"` → la requête suivante rend l'ancien écran. **À jouer avant chaque palier**, comme l'exige le critère 25 |

### Palier 4 — le retrait du code (§25.1 étape 4)

| | |
|---|---|
| Condition d'ouverture | **aucun ancien écran rouvert depuis un mois**. Rien ne le mesure aujourd'hui : il faut journaliser chaque bascule du drapeau et chaque passage en mode `legacy` dans `activity-logs` (le module existe) |
| Ce qui reste | les **8 routes de redirection** de compatibilité (liens Telegram déjà émis) et les **2 routes de fichier** CV/photo, tant que le CRM ne les sert pas |
| Retour arrière | aucun : c'est pourquoi ce palier vient en dernier et sous condition d'un mois de silence |

---

## 4. LIVRABLE 3 — critère 23 du §29 : l'écart, mesuré

> « aucun écran de relation (contacts, rendez-vous, messages) n'est atteignable dans la console
> axionia autrement que par redirection vers le CRM »

**Écart : 17 sur 17.** Aucun écran de relation ne redirige vers le CRM. **0 des 27 redirections**
de la console pointe vers le CRM (toutes retombent dans la console). Quatre chemins d'accès :

| Chemin | Combien | Détail mesuré |
|---|---|---|
| **Barre latérale** | **12** | groupe « Boîte de réception », dont 8 en niveau 2. Aucun n'est masqué (`parent` nul, `tier` nul sur les 12) |
| **Palette ⌘K** | **17** (et 153 au total) | `AdminCommandPalette` consomme `buildAdminNav()` **sans filtre** : elle expose **tous** les items, y compris les 12 masqués de la barre et les 20 « avancés ». Les 5 fiches de détail s'atteignent par la recherche de données (`rechercheGlobaleAction`) |
| **URL directe** | **17 + 8** | les 17 écrans, plus 8 redirections de compatibilité qui **ramènent dans la console** (`/submissions`, `/candidatures`, `/contacts/rendez-vous`…) |
| **Lien depuis ailleurs** | **7 points d'entrée** | les alertes **Telegram** pointent dans `contacts/candidatures/[id]`, `contacts/commercial/[id]`, `contacts/appels/[eventUri]` (×3), `podcast/[id]`, `avis/[id]` — `src/server/notifications/format.ts` |

**Et dans l'autre sens** — le §22.6 attend qu'on soit « envoyé par un lien depuis la fiche, sur
l'objet exact » : la console n'offre **qu'un seul** lien vers le CRM, codé en dur hors du SSOT de
navigation (`AdminSidebarNav.tsx:773`), libellé « **Prospection** », ouvrant la **racine** de
`app.axion-crm-pro.com` dans un nouvel onglet. **Zéro lien profond vers une personne, dans les deux
sens.**

---

## 5. Grille complète — une ligne par objet du périmètre

| Objet | Existe | Type | Table lue | Équiv. CRM | Redirige vers le CRM | Dans ⌘K | Lien Telegram | Verdict |
|---|---|---|---|---|---|---|---|---|
| `/contacts` (Tout) | ✅ | écran | 4 tables | ❌ | ❌ | ✅ | — | perte totale si retiré |
| `/contacts/appels` | ✅ | écran (2 vues) | `calendly_events` | ❌ | ❌ | ✅ | ✅ ×3 | perte forte |
| `/contacts/appels/[id]` | ✅ | écran | `calendly_events` | ❌ | ❌ | via recherche | ✅ | perte forte |
| `/contacts/messages` | ✅ | écran | `Submission` | partiel | ❌ | ✅ | — | perte forte |
| `/contacts/messages/[id]` | ✅ | écran | `Submission` + `SubmissionReply` | ❌ | ❌ | via recherche | — | **perte critique** |
| `/contacts/clients` | ✅ | écran filtré | `Submission` | onglet | ❌ | ✅ | — | perte faible |
| `/contacts/presse` | ✅ | écran filtré | `Submission` | onglet | ❌ | ✅ | — | perte faible |
| `/contacts/partenariats` | ✅ | écran filtré | `Submission` | onglet | ❌ | ✅ | — | perte faible |
| `/contacts/investisseurs` | ✅ | écran filtré | `Submission` | onglet | ❌ | ✅ | — | perte faible |
| `/contacts/conferences` | ✅ | écran filtré | `Submission` | onglet | ❌ | ✅ | — | perte faible |
| `/contacts/commercial` (« Recrutement ») | ✅ | écran filtré | `Submission` | vivier (vide) | ❌ | ✅ | ✅ | perte moyenne |
| `/contacts/commercial/[id]` | ✅ | écran | `Submission` | ❌ | ❌ | via recherche | ✅ | perte critique |
| `/contacts/autres` | ✅ | écran filtré | `Submission` | ❌ (aucun onglet) | ❌ | ✅ | — | perte moyenne |
| `/contacts/candidatures` | ✅ | écran | `JobApplication` + `Submission` | vivier (vide) | ❌ | ✅ | ✅ | perte forte |
| `/contacts/candidatures/[id]` | ✅ | écran | `JobApplication` | ❌ | ❌ | via recherche | ✅ | **perte critique** (CV, photo) |
| `/contacts/candidatures/[id]/cv` | ✅ | route fichier | fichier | ❌ | ❌ | ❌ | — | perte critique |
| `/contacts/candidatures/[id]/photo` | ✅ | route fichier | fichier | ❌ | ❌ | ❌ | — | perte critique |
| `/podcast` | ✅ | écran | `PodcastRequest` | ❌ | ❌ | ✅ | — | perte totale |
| `/podcast/[id]` | ✅ | écran | `PodcastRequest` | ❌ | ❌ | via recherche | ✅ | perte totale |
| `/contacts/rendez-vous` | ✅ | **redirection** | — | — | ❌ (→ console) | ❌ | (cible de liens émis) | à conserver |
| `/contacts/rendez-vous/calendrier` | ✅ | **redirection** | — | — | ❌ | ❌ | idem | à conserver |
| `/contacts/calendly` | ✅ | **redirection** | — | — | ❌ | ❌ | idem | à conserver |
| `/contacts/calendly/[id]` | ✅ | **redirection** | — | — | ❌ | ❌ | idem | à conserver |
| `/submissions`, `/submissions/[id]` | ✅ | **redirection** 308 | — | — | ❌ | ❌ | — | à conserver |
| `/candidatures`, `/candidatures/[id]` | ✅ | **redirection** | — | — | ❌ | ❌ | ✅ | à conserver |
| `/calendrier`, `/calendrier/heatmap`, `/calendrier/reschedule` | ✅ | **redirection** 308 → `/planning` | — | — | ❌ | ❌ | — | module Booking mort, correctement neutralisé |
| `/reservations`, `/reservations/[id]` | ✅ | **redirection** 308 → `/qualiopi/dossiers` | — | — | ❌ | ❌ | — | idem |
| `planning/*` (7 entrées) | ✅ | écrans | Qualiopi | — | — | ✅ | — | **exécution : reste dans la console** (§0 principe 2) |
| Lien « Prospection ↗ » | ✅ | `<a>` hors SSOT | — | — | racine seulement | ❌ | — | **E32-006** |
| Drapeau de retrait | ❌ | — | — | — | — | — | — | **E32-004** |
| `collectReconciliation()` | ✅ | instrument | `crmSyncOutbox` | — | — | — | — | **E32-003** — mesure le mauvais objet |
| `/console/arbitrage` (CRM) | ✅ | écran mutant | `activities` | — | — | — | — | l'écran d'arbitrage du §25.1 **existe déjà** |

---

## 6. Constats

### [E32-001] La liste des « 12 écrans Contacts » se trompe sur 2 items sur 12 ; la console compte 153 entrées de navigation, pas ~150
- Sévérité      : S3 finition
- Domaine       : navigation
- Référence     : site `main eb754332`
- Emplacement   : `src/lib/admin-nav.ts:431-524`
- Constat       : le groupe `contacts` compte bien 12 entrées, mais « Rendez-vous + calendrier » n'y figure plus depuis le 2026-07-29 (devenu 4 redirections) et l'entrée « Tout » (`/contacts`, boîte de réception unifiée) n'est dans aucune liste du mandat ; le SSOT porte 153 items, plus 1 lien codé en dur.
- Preuve        : `buildAdminNav("admin-xyz")` exécutée — `04_PREUVES/agent-32/01_nav-count.txt` (`TOTAL_ITEMS 153`, `contacts = 12`, `SIDEBAR_VISIBLE 141`, `HIDDEN 12`, `TIER_ADVANCED 20`)
- Témoin négatif: le même script déplie `QR_CATEGORIES` (4) et `IMPRIMES` (3), que le comptage par `grep` manque — le `grep` rendait 150, l'exécution rend 153 : la méthode a été prouvée capable de voir la différence.
- Impact        : toute décision de retrait fondée sur la liste du mandat retirerait un écran qui n'existe plus et oublierait celui qui agrège les 4 canaux.
- Reproduction  : `node_modules/.bin/tsx` sur un script important `buildAdminNav` depuis la racine du dépôt site.
- Correctif     : corriger la liste du §A du CDC. Coût : 10 min.
- Statut        : ouvert

### [E32-002] Le canal ne transporte aucun contenu : retirer les écrans de la console perdrait 13 catégories d'information
- Sévérité      : S1 grave
- Domaine       : canal
- Référence     : site `main eb754332` / CRM `main c0c453d`
- Emplacement   : `src/server/crm-sync/types.ts:69-113` ; `src/features/unified-contact/actions.ts:250-275` ; `src/app/api/calendly/client-event/route.ts:173-185` ; `Axion-CRM-Pro/backend/app/Http/Controllers/Api/Crm/PersonTimelineController.php:96-120`
- Constat       : le message `CrmSyncEvent` porte l'identité, l'entreprise, le consentement et des tags — jamais le corps du message, ni l'heure du rendez-vous, ni le lieu, ni le CV, ni le statut de traitement ; et la fiche 360° du CRM projette délibérément un **index** (`kind`, `title`, `occurred_at`, `external_ref`) sans contenu.
- Preuve        : `04_PREUVES/agent-32/03_routes-et-sources.txt` + les extraits de payload cités ci-dessus (§2, liste des 13).
- Témoin négatif: la même lecture trouve bien ce qui **est** transporté (`person`, `company`, `consent`, `candidate.cv_ref`, `tags`, `payload.subType/source/funnel`) : le contrôle n'est pas aveugle, il est concluant.
- Impact        : le principe 10 du §0 (« tout se voit et se fait dans le CRM ») est **inatteignable par construction du canal**. Un retrait des écrans sans extension du contrat rendrait inaccessibles le texte des demandes, les réponses envoyées, les CV et l'heure des rendez-vous.
- Reproduction  : lire `CrmSyncEvent` puis les trois sites d'appel ; comparer aux colonnes de `model Submission` / `model CalendlyEvent` dans `prisma/schema.prisma`.
- Correctif     : décider explicitement ce que le CRM doit **montrer** avant tout retrait, puis étendre le contrat d'événement des deux côtés (le CRM refuse 422 tout champ non déclaré). Coût : non chiffrable avant arbitrage ; l'extension `startTime`/`location` seule ≈ 2 j.
- Statut        : ouvert

### [E32-003] L'instrument de parité mesure le mauvais objet : il compte les lignes d'outbox, pas ce que le CRM a reçu
- Sévérité      : S1 grave
- Domaine       : canal
- Référence     : site `main eb754332`
- Emplacement   : `src/server/crm-sync/reconcile.ts:311-317`
- Constat       : `compareFamily()` fait `prisma.crmSyncOutbox.findMany({ where: { subjectRef: { in: refs } } })` **sans filtrer sur `status`** ; une ligne en `failed` ou en `gave_up` compte comme émise, et l'écart affiché est zéro.
- Preuve        : lecture du code, lignes 311-317 ; l'en-tête du module (l. 11-16) confirme le parti pris : « il CONSIGNE, il ne ré-enfile pas ».
- Témoin négatif: le module **sait** distinguer les statuts — `emit.ts` renvoie `sent | failed | gave_up | skipped` et `health.ts` les expose : l'information existe, la réconciliation ne l'utilise pas. Le contrôle aurait donc pu voir le problème.
- Impact        : le **palier 1 du §25.1** (« la parité est prouvée », critère 18) serait déclaré vert sur un instrument qui ne mesure pas ce que le critère demande — « ce que le CRM **reçoit** ». Un CRM qui refuse tout en 422 (le cas déjà vécu avec `simulateur_roi`, cité l. 43) laisserait la réconciliation au vert.
- Reproduction  : `sed -n '305,320p' src/server/crm-sync/reconcile.ts`.
- Correctif     : filtrer sur `status = "sent"` et `crmResult ∈ {created, updated, noop_idempotent}`, et confronter à un compteur lu **chez le CRM**. Coût : ~1 j.
- Statut        : ouvert

### [E32-004] Le drapeau de retrait exigé par le critère 25 n'existe pas, et la forme évidente (variable d'environnement) ne peut pas tenir la minute
- Sévérité      : S2 défaut
- Domaine       : navigation
- Référence     : site `main eb754332`
- Emplacement   : `src/env.ts:73-78` (les seuls drapeaux CRM du site) ; `src/server/crm-sync/config.ts:16-28`
- Constat       : aucun drapeau ne gouverne l'affichage des écrans de relation ; les deux drapeaux CRM du site gouvernent l'émission. Le CRM a `CRM_CONSOLE_V2_ENABLED` mais c'est le drapeau de la destination.
- Preuve        : `04_PREUVES/agent-32/02_flags-et-liens.txt`, section T2 (aucun résultat).
- Témoin négatif: section T1 du même fichier — le même `git grep` trouve bien `CRM_SYNC_ENABLED`, `CRM_SYNC_CANDIDATES_ENABLED` et `EN_LOCALE_ENABLED`. Le contrôle est prouvé capable de trouver un drapeau.
- Impact        : le critère 25 (« le drapeau remis à `false` rend les anciens écrans en moins d'une minute ») n'a aucune implémentation. Et posé en variable d'environnement Coolify, il imposerait un redémarrage de conteneur dont la durée n'est ni garantie ni mesurée : le critère serait **indémontrable**.
- Reproduction  : rejouer T1 puis T2 de `02_flags-et-liens.txt`.
- Correctif     : poser le drapeau dans `SiteSetting` (table déjà utilisée par le canal, `reconcile.ts:143`), lu par requête, éditable depuis la console — retour en un clic, sans redéploiement. Coût : ~0,5 j pour le mécanisme, hors câblage écran par écran.
- Statut        : ouvert

### [E32-005] Critère 23 : 17 écrans de relation sur 17 restent atteignables sans redirection vers le CRM, par 4 chemins
- Sévérité      : S2 défaut
- Domaine       : navigation
- Référence     : site `main eb754332`
- Emplacement   : `src/lib/admin-nav.ts:431-524` ; `src/app/[locale]/(admin)/[adminPrefix]/contacts/**` ; `AdminCommandPalette.tsx:100-110` ; `src/server/notifications/format.ts:250,270,304,329,383,400,416`
- Constat       : 12 par la barre latérale, tous les 153 items par ⌘K (la palette ne filtre ni `parent` ni `tier`), 17 + 8 par URL directe, 7 points d'entrée par lien Telegram ; **0 des 27 redirections de la console ne pointe vers le CRM**.
- Preuve        : `01_nav-count.txt` (12 items `contacts`, palette sans filtre), `03_routes-et-sources.txt` (17 écrans / 8 redirections), `02_flags-et-liens.txt` T3.
- Témoin négatif: le même recensement identifie correctement les 8 routes du périmètre qui **sont** des redirections (`Promise<never>`), et les 27 de toute la console : la méthode distingue bien écran et redirection.
- Impact        : le critère 23 est à 0 %. Tant que le palier 3 n'est pas ouvrable (E32-002), il le restera — ce n'est pas une négligence, c'est la conséquence de E32-002.
- Reproduction  : `find … -name page.tsx | xargs grep -l "Promise<never>"` ; exécuter `buildAdminNav`.
- Correctif     : palier 2 puis 3 de la trajectoire ci-dessus. Coût : voir les paliers.
- Statut        : ouvert

### [E32-006] L'unique lien de la console vers le CRM le présente comme un outil de « Prospection », pointe la racine, et vit hors du SSOT de navigation
- Sévérité      : S2 défaut
- Domaine       : navigation
- Référence     : site `main eb754332`
- Emplacement   : `src/components/admin/ui/AdminSidebarNav.tsx:771-793`
- Constat       : un `<a href="https://app.axion-crm-pro.com" target="_blank">` codé en dur au-dessus des groupes, libellé « Prospection », `title="Ouvrir Axion CRM Pro (outil de prospection)"`, sans aucun paramètre de personne.
- Preuve        : `02_flags-et-liens.txt` T3 — c'est la **seule** occurrence de `axion-crm-pro` dans tout `src/`.
- Témoin négatif: le même `git grep` sur `CRM_APP_URL` et `app.axion-crm` ne rend rien d'autre : il n'existe aucun autre lien, profond ou non.
- Impact        : ① le §22.6 attend un lien « sur l'objet exact » — il n'existe dans aucun sens ; ② le libellé enseigne que le CRM sert à prospecter, ce qui contredit frontalement le principe 10 et le critère 24 (« aucun libellé n'a de synonyme ailleurs ») ; ③ hors SSOT, il échappe au test structurel des icônes et à ⌘K, et divergera comme les catégories QR ont divergé (commentaire `admin-nav.ts:1456-1462`).
- Reproduction  : `git grep -n "app.axion-crm-pro.com" -- src/`.
- Correctif     : rapatrier l'entrée dans `admin-nav.ts`, la renommer (« ↗ CRM — la relation »), et lui donner un lien profond par personne au palier 2. Coût : ~0,5 j.
- Statut        : ouvert

### [E32-007] Une candidature commerciale s'affiche dans 4 écrans de la console, avec 2 fiches de détail et 2 vocabulaires de statut
- Sévérité      : S2 défaut
- Domaine       : UX
- Référence     : site `main eb754332`
- Emplacement   : `src/features/admin-job-applications/actions.ts:184-188` ; `src/app/[locale]/(admin)/[adminPrefix]/contacts/commercial/page.tsx:14` ; `.../contacts/candidatures/_v2/ApplicationsV2.tsx:132-133,220-221` ; `src/features/commercial-application/actions.ts:211-214`
- Constat       : le tunnel `/devenir-commercial-ia/candidature` écrit une `Submission` portant `details.unifiedType = "recrutement"` **et** `details.subType = "candidature-commerciale"` ; elle apparaît donc dans « Tout », dans « Messages », dans « Recrutement » (filtre `unifiedType`) et dans « Candidatures » onglet « Mémo Isère » (filtre `subType`), avec deux routes de détail distinctes et deux tables de libellés de statut (`STATUS_LABELS` vs `COMMERCIALE_STATUS_LABELS`).
- Preuve        : lecture croisée des 4 emplacements ci-dessus ; `whereCommerciale` est un sous-ensemble strict de `forcedTypes: ["recrutement"]`.
- Témoin négatif: le même recoupement montre qu'une candidature **d'offre d'emploi** (`JobApplication`) n'apparaît, elle, que dans 2 écrans (Tout, Candidatures) : la méthode distingue les deux cas, elle ne crie pas au doublon partout.
- Impact        : « une confusion de navigation qui fait perdre l'utilisateur » (dossier §8) ; deux statuts pour la même personne, sans que rien ne dise lequel fait foi. C'est le doublon **interne** à la console, indépendant du CRM.
- Reproduction  : ouvrir `/contacts/commercial` et `/contacts/candidatures?view=memo` — mêmes lignes, statuts nommés autrement.
- Correctif     : une seule vue, un seul vocabulaire ; le retrait au palier 3a doit trancher lequel disparaît. Coût : ~1 j.
- Statut        : ouvert

### [E32-008] Le libellé « Recrutement » désigne une route `/contacts/commercial` dont la page s'intitule « Commercial »
- Sévérité      : S3 finition
- Domaine       : navigation
- Référence     : site `main eb754332`
- Emplacement   : `src/lib/admin-nav.ts:492-497` (label « Recrutement ») ; `.../contacts/commercial/page.tsx:1` (« onglet Commercial »)
- Constat       : trois noms pour un objet — « Recrutement » en barre latérale, `commercial` dans l'URL, « Commercial » dans le code ; côté CRM la famille s'appelle `candidat_commercial` (« Commercial »).
- Preuve        : `01_nav-count.txt`, ligne `lvl2 | Recrutement | /fr/admin-xyz/contacts/commercial`.
- Témoin négatif: les 6 autres catégories de Messages ont un libellé, une URL et un en-tête concordants (`presse`, `partenariats`, `investisseurs`, `conferences`, `clients`, `autres`) : le contrôle voit bien la différence.
- Impact        : critère 24 du §29 — « aucun libellé n'a de synonyme ailleurs dans le produit ». Le renvoi vers le CRM au palier 3b héritera de la confusion.
- Reproduction  : comparer la ligne de nav et l'en-tête de la page.
- Correctif     : un seul mot des deux côtés. Coût : 1 h (plus la redirection de l'ancienne URL).
- Statut        : ouvert

### [E32-009] Huit entrées de navigation pour une seule table, là où le CRM en prévoit une avec des onglets
- Sévérité      : S2 défaut
- Domaine       : UX
- Référence     : site `main eb754332` / CRM `main c0c453d`
- Emplacement   : `src/lib/admin-nav.ts:447-515` ; `Axion-CRM-Pro/frontend/src/features/crm-console/ContactsHubPage.tsx:1-11`
- Constat       : Messages, Clients, Presse, Partenariats, Investisseurs, Conférences, Recrutement et Autres sont **huit routes** qui rendent le même composant `SubmissionsV2` sur la même table `Submission`, avec un `forcedTypes` différent ; la console CRM a explicitement tranché l'inverse (« les types ne sont pas huit pages, ce sont des VUES PRÉRÉGLÉES du même écran… huit pages auraient signifié sept occasions de diverger »).
- Preuve        : `03_routes-et-sources.txt` (8 lignes `forcedTypes,SubmissionsV2`) + l'en-tête de `ContactsHubPage.tsx`.
- Témoin négatif: le même recensement isole les 4 écrans qui, eux, lisent une table propre (`calendly_events`, `JobApplication`, `PodcastRequest`, l'union) : il ne confond pas tout avec `Submission`.
- Impact        : la divergence annoncée s'est déjà produite (les libellés de catégorie diffèrent entre le filtre et la sidebar ; `conferences` n'existait que comme filtre jusqu'au 2026-08-14). Le palier 3a devra fusionner 8 → 1.
- Reproduction  : `grep -l forcedTypes src/app/**/contacts/*/page.tsx`.
- Correctif     : porter les 7 catégories en onglets d'un seul écran **avant** de rediriger — sinon on redirige sept fois vers le même endroit. Coût : ~1,5 j, ou zéro si on attend le palier 3a.
- Statut        : ouvert

### [E32-010] L'état du drapeau `CRM_CONSOLE_V2_ENABLED` en production n'est pas mesurable de l'extérieur : la route qui l'annonce répond 500
- Sévérité      : S2 défaut
- Domaine       : conformité
- Référence     : CRM `main c0c453d`, production `api.axion-crm-pro.com`, 2026-08-19 11:13 UTC
- Emplacement   : `backend/routes/api.php:257` (dans le groupe `auth:sanctum` ouvert l. 83) ; `backend/config/crm.php:168` (défaut `false`) ; `backend/app/Http/Controllers/Api/FeaturesController.php:21` (« la mettre derrière le [drapeau] serait circulaire »)
- Constat       : `GET /api/v1/config/features` répond **HTTP 500** en production ; la route est déclarée hors du groupe `crm-console` mais **dans** le groupe `auth:sanctum`, ce qui la fait tomber sur le défaut A-001 (`Route [login] not defined`). Le seul environnement du dépôt qui pose `CRM_CONSOLE_V2_ENABLED: "true"` est `docker-compose.local.yml:59` ; `.env.example:291` le pose à `false`, et aucun compose de production ou de préproduction ne le mentionne.
- Preuve        : `04_PREUVES/agent-32/04_prod-features.txt` (`HTTP=500`, page d'erreur Laravel) ; `grep -n CRM_CONSOLE_V2_ENABLED docker-compose*.yml .env.example backend/config/crm.php`.
- Témoin négatif: le même `grep` **trouve** l'occurrence `docker-compose.local.yml:59` : il n'est pas aveugle aux affectations du drapeau, il constate simplement qu'aucune n'existe hors du local.
- Impact        : la **destination** de tous les paliers pourrait être fermée en production sans qu'aucun contrôle extérieur ne le dise. Le contrôleur a été écrit précisément pour être interrogeable sans authentification (« serait circulaire ») ; il est en fait derrière l'authentification, et l'authentification rend 500.
- Reproduction  : `curl -s -o /dev/null -w "%{http_code}" https://api.axion-crm-pro.com/api/v1/config/features` → `500`.
- Correctif     : sortir `/config/features` du groupe `auth:sanctum` — c'est ce que son propre commentaire annonce — et poser le drapeau explicitement dans les composes de préproduction et de production. Coût : 1 h. Prérequis à toute vérification de palier.
- Statut        : ouvert

---

## 7. Ce que je n'ai PAS pu vérifier, et pourquoi

1. **L'état réel de `CRM_SYNC_ENABLED` en production côté site.** Aucune surface publique ne
   l'expose ; la console `/synchro-crm` est derrière authentification. Toutes mes conclusions sur
   le canal portent donc sur **le code**, pas sur son état d'exécution.
2. **L'état réel de `CRM_CONSOLE_V2_ENABLED` en production.** La route prévue pour l'annoncer rend
   500 (E32-010). **Verdict non concluant, déclaré comme tel** — je ne présente pas la lecture du
   `docker-compose.local.yml` comme la vérité de la production (piège 19 appliqué à l'atelier).
3. **Aucun écran n'a été ouvert pour de vrai** (dossier §2 règle 4). L'atelier CRM local est
   interdit par le mandat (A-009) et la console axionia de production demande une authentification
   que je n'ai pas. Tout ce rapport est une lecture de code exécutée, pas une observation d'écran.
   Les colonnes « ce qu'il montre » sont donc dérivées des composants rendus, pas d'une capture.
4. **La durée réelle du retour arrière** (critère 25, « moins d'une minute »). Je n'ai pas pu
   chronométrer un redémarrage de conteneur Coolify ; c'est pourquoi je **recommande** la variante
   `SiteSetting` plutôt que d'affirmer que la variante variable d'environnement échoue.
5. **La parité elle-même** (critère 18). Je n'ai pas pu jouer `collectReconciliation()` : elle
   requiert la base de production. J'ai audité **l'instrument**, pas la mesure.
6. **Les compteurs réels** de `Submission`, `calendly_events`, `JobApplication`, `PodcastRequest`.
   Sans eux, je ne peux pas dire **combien** de lignes seraient concernées par chaque palier — je
   peux seulement dire quelles catégories d'information le seraient.
