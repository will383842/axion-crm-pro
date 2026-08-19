# AGENT 33 — Auditeur des entrées de contacts

**Référence mesurée** : dépôt du SITE `C:\Users\willi\Documents\Projets\Axion-IA\axionia`,
`main = eb754332` (arbre de travail propre, `git status --porcelain` vide).
Le dépôt CRM n'a pas été modifié ; aucune écriture, aucun événement émis, aucun appel au CRM
de production. Le worktree `crmpro-wt-etape1a` n'a été ni lu ni touché.

**Preuves brutes** : `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/agent-33/` (9 fichiers).

---

## 0. Les cinq réponses, avant le détail

| Question du mandat | Réponse mesurée |
|---|---|
| Nombre réel de finalités de formulaire | **14 au contrat**, dont **12** dans le formulaire unifié + `podcast` + `simulateur_roi`. Le CDC est **exact**. L'écart est ailleurs : **4 chemins de code** portent ces 14 finalités, et le chatbot en écrase une (`autre`). |
| Statuts Calendly | `booked` **automatique** · `canceled` **automatique** · `no_show` **automatique** · `completed` **MANUEL, par conception assumée et documentée**. |
| Chiffrement du chatbot | **Moitié faite.** `capturer_lead` chiffre (2026-08-18). `escalader_question` écrit l'adresse **EN CLAIR** dans `chat_escalations`, sans hachage de recherche. |
| Plafond réel de l'export | **`PLAFOND_EXPORT = 50 000`**, il **existe**, côté **SITE**, et il est **bruyant**. Le « 5 000 » était vrai jusqu'au 2026-08-18. |
| Critère 18 | **Non mesurable en l'état**, et **insatisfaisable par construction**. Raison détaillée en §3. |

---

## 1. Tableau de grille — les 16 points de capture

Légende : « émet » = appelle une fonction de `src/server/crm-sync`. Fichier:ligne = le site d'appel.

| # | Point de capture | fichier:ligne | Ce qu'il collecte | Ce qu'il émet vers le CRM | Ce qu'il OMET | Consentement joint ? | source_slug ? | SIREN ? | Visible en cas d'échec ? | Testé ? |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | Formulaire unifié (12 finalités) | `src/features/unified-contact/actions.ts:250` | nom, e-mail, tél, société, ville, taille, secteur, UTM | `form_submission` + `form_type` (12 valeurs) + person + company + consent | **`source_slug`**, **SIREN**, code postal, site web, corps du message | ✅ `v1-2026-05-24`, `textRef: unified-contact-form` | ❌ **aucun** | ❌ non collecté | Partiel — ligne d'outbox `failed`/`gave_up` visible en console `/synchro-crm` uniquement ; l'échec d'écriture d'outbox lui-même n'est qu'un `console.error` (`enqueue.ts:109`) | ✅ `crm-sync.test.ts` (17 tests) |
| 2 | Simulateur de gains (ROI) | `src/features/roi-report/actions.ts:168` | nom, e-mail, société, effectif, secteur, maturité, gain estimé | `form_submission` / `simulateur_roi` + company + consent + payload gain | **`source_slug`**, **SIREN**, tél, ville | ✅ `textRef: roi-report-form` | ❌ **aucun** | ❌ non collecté | Idem #1 | ⚠️ non couvert nominativement |
| 3 | Formulaire podcast | `src/features/podcast-request/actions.ts:110` | dirigeant, e-mail, tél, société, CP, ville, activité | `form_submission` / `podcast` + company (CP + ville !) + consent | **SIREN** | ✅ `textRef: podcast-form` (sans `at`) | ✅ `site-formulaire-podcast` | ❌ non collecté | Idem #1, **aggravé** : famille non réconciliée (§3, E33-004) | ⚠️ non couvert |
| 4 | Chatbot — `capturer_lead` | `src/server/chatbot/tools/capturer-lead.ts:156` | nom, e-mail, tél, structure, besoin (2 000 car.), consentement RGPD **obligatoire** | `form_submission` / **`autre`** + person + company.name | **le consentement recueilli** (voir E33-002), **SIREN**, le besoin | ❌ **aucun bloc `consent`** alors que `consentement_rgpd: z.literal(true)` est **exigé** en entrée | ✅ `chatbot` | ❌ non collecté | Émis **dans** la transaction métier — le seul point à garantir « lead ⇒ événement » | ✅ `capturer-lead.test.ts` |
| 5 | Chatbot — `escalader_question` | `src/server/chatbot/tools/escalader-question.ts:40` | question (2 000 car.), contexte (4 000 car.), e-mail | **RIEN** | **la totalité** | ❌ | ❌ | ❌ | Non — invisible du CRM par construction | ✅ testé, mais sur l'objet local seulement |
| 6 | Chatbot — `qualifier_prospect` | `src/server/chatbot/tools/qualifier-prospect.ts:14-22` | type de structure, secteur, besoin, maturité IA, **urgence** | **RIEN** | **la totalité** — c'est-à-dire exactement les 5 champs de scoring commercial | ❌ | ❌ | ❌ (mais `type_structure` renseigne la taille) | Non | ✅ local |
| 7 | Chatbot — `proposer_rdv` | `src/server/chatbot/tools/proposer-rdv.ts:8-12` | `type_rdv` seul — **aucune donnée personnelle** | rien à émettre | — | n/a | n/a | n/a | n/a — **outil de navigation, pas un point de capture** | ✅ local |
| 8 | Calendly — embed `/appel` | `src/app/api/calendly/client-event/route.ts:175` | e-mail, nom, type de RDV, UTM, page | `calendly_booked` + person + payload UTM | **bloc `consent`**, tél, **SIREN** | ❌ **aucun** | ✅ `calendly` | ❌ | Ligne d'outbox + alerte Telegram | ✅ `route.test.ts` |
| 9 | Calendly — sondage `discover` (1×/min) | `src/server/calendly/discover.ts:244` | e-mail, nom, tél, horaire | `calendly_booked` | **`consent`**, **SIREN** | ❌ | ✅ `calendly` | ❌ | Alerte Telegram `CALENDLY_INVITEE_CREATED` | ✅ |
| 10 | Calendly — sondage `refresh` (1×/10 min) | `src/server/calendly/enrich.ts:237` | statut Calendly, `no_show` de l'hôte | `calendly_canceled` **ou** `calendly_no_show`, **sur transition** | **`consent`**, **SIREN** | ❌ | ✅ `calendly` | ❌ | Alerte Telegram `CALENDLY_INVITEE_CANCELED` | ✅ `enrich.test.ts` |
| 11 | Calendly — statut posé en console | `src/features/admin-calendly/actions.ts:98` | statut choisi par l'admin | `calendly_completed` / `_no_show` / `_canceled`, sur **vrai** changement | **`consent`**, **SIREN** | ❌ | ✅ `calendly` | ❌ | Retour d'action `db_failed` + Sentry | ⚠️ non couvert |
| 12 | Calendly — saisie manuelle | `src/features/admin-calendly/actions.ts:181` | e-mail, nom, tél, horaire | `calendly_booked` | **`consent`**, **SIREN** | ❌ | ✅ `calendly` | ❌ | Sentry | ⚠️ non couvert |
| 13 | Newsletter — opt-in confirmé | `src/features/newsletter/actions.ts:180` | e-mail seul | `newsletter_optin` + consent | **`source_slug`**, nom, **SIREN** | ✅ `textRef: newsletter-double-optin` | ❌ **aucun** | ❌ | Idem #1 | ✅ |
| 14 | Newsletter — désinscription | `src/features/newsletter/actions.ts:259` | e-mail | `newsletter_optout` + payload motif | **`consent`**, **`source_slug`** | ❌ **aucun** | ❌ **aucun** | ❌ | Idem #1 | ✅ |
| 15 | Avis client | `src/features/review-submission/actions.ts:217` | prénom, nom, e-mail, société, note | `review_posted` + company.name + consent | **`source_slug`**, **SIREN**, le texte de l'avis (volontaire) | ✅ `textRef: review-form` (sans `at`) | ❌ **aucun** | ❌ | Idem #1 | ✅ |
| 16 | Candidature à une offre | `src/features/job-application/actions.ts:315` | prénom, nom, e-mail, tél, CV, consentement vivier | `application_submitted` + candidate + consent + `vivier_at` | **SIREN** (sans objet) | ✅ + `vivierAt` conditionnel | ✅ `site-candidature-offre` | ❌ | Idem #1 | ✅ |
| 17 | Candidature commerciale | `src/features/commercial-application/actions.ts:273` | prénom, nom, e-mail, tél, attributs | `application_submitted` (univers **vivier**) mais `subject_ref` = **`site:submission:`** | **SIREN** | ✅ | ✅ `site-candidature-commerciale` | ❌ | **Faux « manquant » permanent** (E33-005) | ⚠️ non couvert |
| 18 | Vivier — mise en stock | `src/server/vivier/stock.ts:250` | reprise de candidatures existantes | `application_submitted` + consent `vivier-information-email` | — | ✅ version fermée dédiée | ✅ `site-candidature-offre` | ❌ | `outboxId` remonté à l'appelant | ✅ `vivier.test.ts` |
| 19 | Vivier — opposition | `src/server/vivier/opposition.ts:66` | e-mail | `opt_out` univers vivier + consent | **`source_slug`** | ✅ (version de la candidature) | ❌ **aucun** | ❌ | `console.error` seul | ✅ |
| 20 | Tunnel booking / devis / cadrage | `src/features/booking/` (20 fichiers) | devis, cadrage, reprogrammation, remboursement, paiement | **RIEN** | **la totalité** | ❌ | ❌ | ❌ | Non — invisible du CRM par construction | n/a |

### Ce que le tableau donne, en trois nombres

- **19 points de capture** réels (le #7 `proposer_rdv` ne collecte aucune donnée personnelle :
  c'est un outil de navigation, je l'ai retiré du compte), dont **14 émettent** et
  **5 n'émettent rien** : #5 escalade, #6 qualification, #20 tunnel `booking`, plus les deux
  familles Calendly qui n'émettent que partiellement.
- **5 valeurs de `source_slug`** sur 14 émetteurs : `calendly`, `chatbot`, `site-formulaire-podcast`, `site-candidature-offre`, `site-candidature-commerciale`. Les **12 finalités du formulaire unifié**, le simulateur, la newsletter (×2), les avis et l'opposition vivier n'en portent **aucun** — soit **17 finalités sur 22** invisibles à l'attribution.
- **0 SIREN**, sur 20 points de capture.

---

## 2. Le SIREN, point de capture par point de capture

**B13-001 est confirmé, avec témoin négatif.** Le contrôle est prouvé capable de trouver :
le contrat déclare `company.siren` (`types.ts:89`), le site possède un normalisateur
`normalizeSiren()` qui accepte « 123 456 789 » et extrait le SIREN d'un SIRET
(`index.ts:247-254`), et ce normalisateur est **effectivement appliqué** à chaque construction
d'événement (`index.ts:228`). Il est même couvert par un test. Malgré cela :

```
$ grep -rn "siren" src/features/ src/server/vivier/ src/server/chatbot/ \
        src/server/calendly/ src/app/api/calendly/ | grep -v test
>>> AUCUN <<<
$ grep -rln "siren" src/lib/schemas/
>>> AUCUN <<<
```

**Ce qu'il faudrait collecter, point par point, pour que l'arbitrage manuel cesse :**

| Point | Geste minimal | Coût | Pourquoi celui-là |
|---|---|---|---|
| #1 formulaire unifié | 1 champ SIREN **facultatif** dans le groupe « Projet IA » (5 finalités commerciales sur 12) | ~2 h | Volume le plus fort ; `companyName` seul ne dédoublonne pas |
| #2 simulateur ROI | même champ | ~1 h | Le lead porte déjà effectif + secteur : le SIREN complète une fiche presque entière |
| #3 podcast | même champ | ~1 h | **Déjà le plus riche** : CP + ville + activité. Un SIREN le rend rapprochable sans arbitrage |
| #15 avis client | pas de SIREN — passer `company.postcode` | ~1 h | L'auteur certifie être client : le rapprochement doit passer par la fiche existante |
| #8–#12 Calendly | **impossible côté site** : Calendly Free ne transmet pas de champ personnalisé exploitable | — | À traiter par une question personnalisée dans l'event-type Calendly, puis lecture dans `enrich.ts` |
| #4 chatbot | demander la structure **et** le SIREN dans `CapturerLeadInputSchema` | ~2 h | Le champ `structure` existe déjà et part en `company.name` |
| #13/#14 newsletter | sans objet (e-mail seul) | — | — |
| #16–#19 vivier | sans objet (personnes physiques) | — | — |

Effort total pour couvrir les 4 points qui portent la valeur commerciale : **~6 h**, sans
aucune modification du contrat ni du CRM — le canal sait déjà transporter le champ.

---

## 3. Critère 18 — mesure impossible, et raison

> *« sur une même semaine, le nombre de réservations, soumissions, candidatures et inscriptions
> vues par la console est égal au nombre d'événements correspondants reçus par le CRM ; tout
> écart est expliqué ligne à ligne »*

**Je ne peux pas le mesurer sur les données réelles.** Quatre obstacles, tous vérifiés :

1. **Le canal est probablement fermé, et je ne peux pas lire son interrupteur.**
   `enqueueCrmSyncEvent` sort avant toute écriture si `CRM_SYNC_ENABLED !== "true"`
   (`enqueue.ts:80`). La seule déclaration de cette variable dans tout le dépôt est
   `.env.example:413 → CRM_SYNC_ENABLED=false`. La valeur réelle vit dans les variables
   d'environnement Coolify, hors dépôt. `.env.local` ne la porte pas.
   **Si le drapeau est à OFF, le critère 18 vaut trivialement 0 = 0 et ne mesure rien.**
2. **La base de production du site n'est pas lisible d'ici** : aucun identifiant en dépôt, et
   la console `/[adminPrefix]/synchro-crm` exige une session admin — m'y connecter serait une
   **écriture** (création de session), interdite par mon mandat.
3. **L'instrument existant mesure le mauvais objet** — E32-003 **contre-vérifié par moi**, il
   tient (voir E33-003). Son chiffre ne répondrait donc pas à la question posée.
4. **L'instrument est en outre incomplet** : il compare 5 familles, le site en émet 6
   (E33-004), et il produit un faux « manquant » permanent sur une sixième (E33-005).

**Mais le critère est insatisfaisable par construction, et voici l'écart ligne à ligne**
— ce que le critère 18 réclame précisément :

| Ligne d'écart | Sens | Cause mesurée |
|---|---|---|
| Tunnel `booking` : devis, cadrage, reprogrammation, remboursement | console **>** CRM | Aucun appel à `crm-sync` dans les 20 fichiers de `src/features/booking/` (E33-001) |
| Escalades chatbot | console **>** CRM | `escalader-question.ts` n'importe pas `crm-sync` (E33-001) |
| Qualifications de prospect du chatbot (5 champs de scoring) | console **>** CRM | idem |
| Demandes podcast | **écart non mesuré** | Famille `site:podcast_request:` absente de la réconciliation (E33-004) |
| Candidatures commerciales | CRM **<** console, **signalé à tort** | `subject_ref = site:submission:` comparé dans la famille `business`, alors que l'émission est bloquée par `CRM_SYNC_CANDIDATES_ENABLED` (E33-005) |
| Toute ligne `gave_up` (refus 422 définitif, ou 8 tentatives) | CRM **<** console, **non signalé** | `reconcile.ts:311` ne filtre pas `status` (E33-003) |
| Rendez-vous honorés | CRM **<** console, tant que personne ne clique | `completed` manuel par conception (A07-011, confirmé) |
| Toute personne sans e-mail exploitable | CRM **<** console | `dispatch()` rend `null` sans `person_key` (`index.ts:192`) |

---

## 4. Constats

### [E33-001] Quatre points de capture du site n'émettent RIEN vers le CRM, dont tout le tunnel de réservation
- Sévérité      : S1 grave
- Domaine       : canal
- Référence     : site `main eb754332`
- Emplacement   : `src/features/booking/` (20 fichiers) · `src/server/chatbot/tools/escalader-question.ts:40` · `qualifier-prospect.ts:14-22`
- Constat       : `src/features/booking/` — devis, cadrage, reprogrammation, remboursement, paiement — ne contient aucune référence à `@/server/crm-sync`, et 2 des 6 outils du chatbot qui collectent de la donnée exploitable non plus. (`proposer_rdv` est exclu : il ne collecte rien.)
- Preuve        : `04_PREUVES/agent-33/07_points-sans-emission.txt` — `grep -rn "crm-sync" src/features/booking/` ne rend **rien** ; `grep -rn "crm-sync" src/server/chatbot/` ne rend que `capturer-lead.ts:11`.
- Témoin négatif: le même `grep -rln "crm-sync" src/features/` **trouve** 7 autres dossiers (`unified-contact`, `newsletter`, `review-submission`, `roi-report`, `podcast-request`, `job-application`, `commercial-application`, `admin-calendly`) : le contrôle est prouvé capable de trouver une émission là où elle existe.
- Impact        : un prospect qui demande un devis, reprogramme ou se fait rembourser n'apparaît jamais dans le CRM. Une escalade chatbot — une question que le bot n'a pas su traiter, donc un signal commercial fort — n'y apparaît pas non plus. Et `qualifier_prospect` collecte précisément les **5 champs de scoring** (type de structure, secteur, besoin, maturité IA, urgence) que le CRM devrait recevoir pour classer un lead sans arbitrage : ils sont recueillis, persistés côté site, et jamais transmis. Le critère 18 ne peut pas être atteint tant que ces flux existent hors canal.
- Reproduction  : `cd axionia && grep -rn "crm-sync" src/features/booking/`
- Correctif     : brancher `syncFormSubmissionToCrm` sur les 3 actions terminales de `booking` (devis accepté, cadrage confirmé, reprogrammation) et `escaladerQuestion` sur un `form_type: "support_client"`. **~1 j.** Décision préalable requise : une escalade est-elle un lead ? (le mandat ne tranche pas)
- Statut        : ouvert

### [E33-002] Le chatbot exige un consentement RGPD explicite et ne le transmet pas au CRM
- Sévérité      : S1 grave
- Domaine       : conformité
- Référence     : site `main eb754332`
- Emplacement   : `src/server/chatbot/tools/capturer-lead.ts:51` et `:156-164`
- Constat       : `CapturerLeadInputSchema` impose `consentement_rgpd: z.literal(true)` et l'écrit en base (`details.consentementRgpd: true`, ligne 131), mais l'appel à `syncFormSubmissionToCrm` ne passe **aucun bloc `consent`**.
- Preuve        : `04_PREUVES/agent-33/05_chiffrement-chatbot.txt` ; l'appel des lignes 156-164 ne comporte ni `consent:` ni `version`.
- Témoin négatif: 8 autres émetteurs du site passent bien un bloc `consent` (`unified-contact:265`, `roi-report:178`, `podcast-request:116`, `newsletter:182`, `review-submission:220`, `job-application:326`, `commercial-application:284`, `vivier/stock:262`) — la forme est connue et appliquée partout ailleurs.
- Impact        : le CRM reçoit un lead dont il ne peut prouver la base légale, alors que la preuve **existe** côté site. Toute exploitation commerciale de ce lead est un traitement sans base légale démontrable. Même défaut sur `newsletter_optout` et les 4 événements Calendly, mais là le consentement n'est pas recueilli — ici il l'est, et il est perdu en chemin.
- Reproduction  : lire `capturer-lead.ts:43-53` puis `:156-164`.
- Correctif     : ajouter `consent: { version: <version du texte chatbot>, at: new Date(), textRef: "chatbot-capture-lead" }`. **~1 h**, plus la déclaration de la version côté CRM.
- Statut        : ouvert

### [E33-003] L'instrument de parité compte comme « émis » un événement définitivement abandonné
- Sévérité      : S1 grave
- Domaine       : canal
- Référence     : site `main eb754332`
- Emplacement   : `src/server/crm-sync/reconcile.ts:311-314`
- Constat       : la requête est `findMany({ where: { subjectRef: { in: refs } }, select: { subjectRef: true } })` — **sans aucun filtre sur `status`** — alors que l'enum `CrmSyncStatus` comporte `pending`, `sent`, `failed` et `gave_up`, et que la table porte une colonne `sentAt`.
- Preuve        : `04_PREUVES/agent-33/03_reconcile-e32-003.txt` (extrait du code + enum `prisma/schema.prisma:10831-10841`).
- Témoin négatif: la colonne `status` et la colonne `sentAt` **existent** et sont écrites par le worker (`crm-sync.test.ts:309-310` vérifie `res.status === "gave_up"`) : un filtre était disponible et n'a pas été posé. Le contrôle aurait donc pu distinguer les deux cas.
- Impact        : un lead refusé définitivement par le CRM (422 : type de formulaire inconnu, consentement manquant, taxonomie) est compté comme livré. Le rapport de parité affiche `missing: 0` alors que le lead n'est jamais arrivé — c'est exactement le mode de panne que le commentaire de `types.ts:39-45` décrit comme déjà survenu avec `simulateur_roi`. **Aucun test ne couvre ce cas** : `grep -n "gave_up" crm-sync-l5.test.ts` ne rend que 2 lignes, toutes deux sur les clés d'alerte, aucune sur la réconciliation.
- Reproduction  : `sed -n '308,318p' src/server/crm-sync/reconcile.ts`
- Correctif     : `where: { subjectRef: { in: refs }, status: "sent" }`, et compter séparément `pending` (en vol, légitime) et `gave_up` (perdu, à signaler). **~2 h**, test compris. Confirme et étend E32-003 de l'agent 32.
- Statut        : ouvert

### [E33-004] La réconciliation compare 5 familles alors que le site en émet 6 : les demandes podcast ne sont jamais vérifiées
- Sévérité      : S2 défaut
- Domaine       : canal
- Référence     : site `main eb754332`
- Emplacement   : `src/server/crm-sync/reconcile.ts:74-75` et `:161-260` · `src/features/podcast-request/actions.ts:111`
- Constat       : `CrmSyncFamily` déclare 5 familles ; les émetteurs produisent 6 préfixes de `subject_ref` distincts. `site:podcast_request:` n'appartient à aucune famille comparée.
- Preuve        : `04_PREUVES/agent-33/08_source-slug-et-familles.txt` — 6 préfixes émis (`calendly_event`, `customer_review`, `job_application`, `newsletter_subscriber`, `podcast_request`, `submission`) contre 5 `family:` dans `reconcile.ts`.
- Témoin négatif: la sortie réelle du batch, capturée pendant les tests, énumère nommément ses familles et n'en contient aucune pour le podcast : `{"familles":[{"famille":"submission"},{"famille":"job_application"},{"famille":"calendly_event"},{"famille":"newsletter_subscriber"},{"famille":"customer_review"}]}` (`09_vitest-crm-sync-52-tests.log`).
- Impact        : les demandes podcast sont le point de capture **le plus riche du site** (dirigeant, e-mail, tél, société, code postal, ville, activité) et le seul relayé par les QR des flyers papier. Une perte y est totalement invisible : ni comptée, ni alertée, ni signalée. Le commentaire de `reconcile.ts:64-71` affirme couvrir « toutes familles » — c'est faux d'une famille sur six.
- Reproduction  : comparer la sortie de `grep -rh "subjectRef: \`site:"` à celle de `grep -n "  family: \""` dans `reconcile.ts`.
- Correctif     : ajouter la famille `podcast_request` (source `prisma.podcastRequest`, horodatage `createdAt`). **~2 h**, test compris.
- Statut        : ouvert

### [E33-005] La réconciliation produit un faux « manquant » permanent sur chaque candidature commerciale
- Sévérité      : S2 défaut
- Domaine       : canal
- Référence     : site `main eb754332`
- Emplacement   : `src/server/crm-sync/reconcile.ts:161-179` · `src/features/commercial-application/actions.ts:202` et `:273-277`
- Constat       : `commercial-application` écrit une ligne dans `submissions` (ligne 202) puis appelle `syncCandidateToCrm`, dont l'univers est `vivier` — donc bloqué par `CRM_SYNC_CANDIDATES_ENABLED` (`enqueue.ts:81`). La famille `submission` de la réconciliation charge **toutes** les `submissions` de la fenêtre, sans filtre de type, et n'est protégée par aucune garde équivalente à celle qui protège `job_application`.
- Preuve        : `reconcile.ts:171-177` (`where: { submittedAt: { gte, lt } }`, aucun filtre de type) à comparer avec `reconcile.ts:184` (`if (isCrmSyncCandidatesEnabled())`) qui protège la seule famille `job_application`.
- Témoin négatif: la garde **existe** et fonctionne pour `job_application` — la sortie réelle du batch le montre : `{"famille":"job_application","ignoree":"flux candidats fermé (CRM_SYNC_CANDIDATES_ENABLED)"}` (`09_vitest-crm-sync-52-tests.log`). Le mécanisme d'exclusion était donc disponible et n'a pas été appliqué à ce cas.
- Impact        : chaque candidature commerciale est comptée comme un lead business manquant, indéfiniment. Le module jure en tête de fichier refuser ce faux positif précis (« un enregistrement qui, par construction, n'émet pas n'est pas un manquant », `reconcile.ts:71`) — la promesse est tenue pour une famille et rompue pour celle-ci. Une alerte qui crie à tort apprend à ne plus lire : c'est le risque nommé à la ligne 21 du même fichier.
- Reproduction  : `sed -n '161,200p' src/server/crm-sync/reconcile.ts`
- Correctif     : soit exclure du `where` les `submissions` dont `details.unifiedType` désigne une candidature, soit donner à `commercial-application` un `subject_ref` propre (`site:commercial_application:`) et sa famille. **~3 h.** La seconde voie est préférable : un `subject_ref` qui ment sur la nature de l'objet est la cause racine.
- Statut        : ouvert

### [E33-006] Le chatbot écrit encore des données personnelles en clair : F14 n'a couvert qu'un outil sur deux
- Sévérité      : S1 grave
- Domaine       : conformité
- Référence     : site `main eb754332`
- Emplacement   : `src/server/chatbot/tools/escalader-question.ts:40-49` · `prisma/schema.prisma:5302`
- Constat       : `escaladerQuestion` écrit `contactEmail: input.contact_email` sans passer par `encryptPii`, dans une colonne `@db.Citext` ; le fichier n'importe ni `@/lib/pii-crypto` ni `@/lib/security/email-hash`. La question (2 000 car.) et le contexte (4 000 car.) de texte libre saisi par le visiteur y sont également en clair.
- Preuve        : `04_PREUVES/agent-33/05_chiffrement-chatbot.txt` — la liste complète des `import` de `escalader-question.ts` (5 lignes, aucun helper de chiffrement) et le modèle `ChatEscalation`.
- Témoin négatif: le fichier voisin `capturer-lead.ts` **importe et applique** `encryptPii` sur trois champs depuis le 2026-08-18 (lignes 17, 127-130) et calcule un `contactEmailHash` — la mesure est donc prouvée capable de distinguer un point de capture chiffré d'un point de capture en clair, et elle les distingue bien.
- Impact        : F14 exige « chiffrement appliqué, **même chatbot éteint** ». Il ne l'est qu'à moitié. Deux conséquences distinctes : (a) une compromission de la base expose les adresses et le texte libre des escalades ; (b) plus grave, `chat_escalations` ne porte **aucun hachage de recherche** — contrairement à `submissions.contactEmailHash` — donc une personne qui a laissé son adresse dans une escalade est **introuvable** pour une demande d'accès (art. 15) ou d'effacement (art. 17). C'est le même mode de panne que B10-004 (`candidates` absente de l'export comme de l'effacement).
- Reproduction  : `grep -n "^import" src/server/chatbot/tools/escalader-question.ts` puis `sed -n '40,49p'` sur le même fichier.
- Correctif     : appliquer `encryptPii` sur `contactEmail`, ajouter une colonne `contact_email_hash` alimentée par `hashEmailForLookup`, et rattacher `chat_escalations` aux routines RGPD d'export et d'effacement. **~4 h**, migration comprise. ⚠️ La colonne étant `@db.Citext`, la migration doit la passer en `Text` — un ciphertext n'a pas de sémantique de casse.
- Statut        : ouvert

### [E33-007] Le lead chatbot part en clair vers Telegram, y compris ce que le chiffrement vient de protéger
- Sévérité      : S2 défaut
- Domaine       : conformité
- Référence     : site `main eb754332`
- Emplacement   : `src/server/chatbot/tools/capturer-lead.ts:24-41` (appelé ligne 182)
- Constat       : `notifyNewLead` compose un message contenant nom, adresse, téléphone, structure et besoin en clair, et l'envoie à Telegram — juste après que les trois premiers champs ont été chiffrés en base.
- Preuve        : `capturer-lead.ts:26-33`, le corps du message ; `:182`, l'appel.
- Témoin négatif: le même fichier chiffre ces champs 50 lignes plus haut (`:127-130`) : la donnée est bien reconnue comme sensible par le code lui-même.
- Impact        : le chiffrement au repos protège le scénario « base compromise » et laisse intact le scénario « historique Telegram ». Telegram est un sous-traitant hors registre pour ce flux. Le même patron existe sur `escalader-question.ts:57` et sur les notifications Calendly (`discover.ts`, `enrich.ts`), qui transportent l'adresse de l'invité.
- Reproduction  : `sed -n '24,41p' src/server/chatbot/tools/capturer-lead.ts`
- Correctif     : réduire le message au strict nécessaire (prénom + identifiant de soumission + lien console), l'identité restant derrière l'authentification. **~2 h** sur les 4 émetteurs. Arbitrage à porter au dirigeant : c'est le canal d'alerte réellement utilisé, l'appauvrir a un coût opérationnel.
- Statut        : ouvert

### [E33-008] Le formulaire unifié, qui porte 12 des 14 finalités, n'émet aucun `source_slug`
- Sévérité      : S2 défaut
- Domaine       : canal
- Référence     : site `main eb754332`
- Emplacement   : `src/features/unified-contact/actions.ts:250-270` · `src/features/roi-report/actions.ts:168` · `src/features/newsletter/actions.ts:180` et `:259` · `src/features/review-submission/actions.ts:217` · `src/server/vivier/opposition.ts:66`
- Constat       : 5 valeurs de `source_slug` sont émises par le site, sur 14 sites d'appel. Les 6 émetteurs ci-dessus n'en passent aucun — soit 17 des 22 finalités du site.
- Preuve        : `04_PREUVES/agent-33/08_source-slug-et-familles.txt` — les 10 lignes `sourceSlug:` trouvées, et les 5 valeurs distinctes.
- Témoin négatif: 8 sites d'appel **en passent un** et le champ traverse bien le contrat (`types.ts:77`, `index.ts:209`) : le contrôle est prouvé capable d'en trouver.
- Impact        : le CRM ne peut pas poser le tag gouverné `src:<slug>` sur la très grande majorité des leads. Combiné à E33-003 (aucun UTM hors `roi-report` et Calendly) et à B13-001 (aucun SIREN), il ne reste au CRM ni la provenance, ni l'attribution, ni l'identité d'entreprise — les trois clés d'un classement automatique. C'est la seconde cause, après B13-001, du 100 % d'arbitrage manuel.
- Reproduction  : `grep -rn "sourceSlug:" src/features/ src/server/ | grep -v test`
- Correctif     : une constante par point de capture, passée à l'appel. **~2 h** pour les 6, plus la déclaration des slugs au référentiel gouverné du CRM.
- Statut        : ouvert

### [E33-009] Le mandat d'audit se trompe sur deux points, réfutés par la mesure
- Sévérité      : S3 finition
- Domaine       : conformité
- Référence     : site `main eb754332`
- Emplacement   : `src/server/calendly/enrich.ts:237` · `src/features/admin-submissions/actions.ts:456`
- Constat       : (a) « `calendly_canceled` n'est pas émis » est **faux** — `enrich.ts:237` l'émet sur transition, depuis le sondage automatique. L'agent 14 a raison. (b) « le plafond réel de 5 000 est introuvable » est **faux dans le dépôt du site** — il a existé (`take: 5000`) et a été remplacé le 2026-08-18 par `PLAFOND_EXPORT = 50 000`. L'agent 12 l'a cherché dans les contrôleurs d'export du **CRM** ; F14 porte sur l'export des soumissions du **SITE**.
- Preuve        : `04_PREUVES/agent-33/06_calendly-verdict.txt` et `04_plafond-export.txt` (dont `git log -S "take: 5000"` → `181e21e4`, et `git log -S "PLAFOND_EXPORT"` → `1bcde6a4`, 2026-08-18).
- Témoin négatif: `git log -S` sur les deux chaînes rend un résultat non vide dans les deux cas — la recherche est prouvée capable de trouver un plafond dans l'historique de ce fichier.
- Impact        : deux affirmations du mandat orientent l'effort de correction vers des objets déjà corrigés. Le vrai reste à faire est ailleurs : `completed` (manuel), et E33-003.
- Reproduction  : `git log --oneline -S "take: 5000" -- src/features/admin-submissions/actions.ts`
- Correctif     : corriger le mandat. Coût nul.
- Statut        : ouvert

---

## 5. Ce que je n'ai PAS pu vérifier, et pourquoi

1. **La valeur réelle de `CRM_SYNC_ENABLED` et `CRM_SYNC_CANDIDATES_ENABLED` en production.**
   Hors dépôt (variables Coolify). La seule valeur versionnée est `.env.example:413 = false`.
   **Tout mon rapport décrit donc le canal tel qu'il fonctionnerait s'il était ouvert.** Si le
   drapeau est à OFF, aucun des flux décrits n'a jamais transporté quoi que ce soit, et la
   priorité de correction change complètement. **À trancher par Will en une commande Coolify.**
2. **Le critère 18 sur données réelles** — §3, quatre obstacles.
3. **La valeur de `PII_ENCRYPTION_KEY` en production.** `encryptPii` est un **no-op silencieux**
   sans elle (`pii-crypto.ts:74-83`). `env.ts` prétend échouer au démarrage si elle manque en
   production — je ne l'ai **pas vérifié**, et le mandat m'interdit de redémarrer quoi que ce
   soit. Tant que ce n'est pas confirmé, « le chatbot chiffre » reste **conditionnel**.
4. **Le comportement réel du CRM en réception** — hors de mon périmètre, et le CRM de
   production est en lecture seule sans identifiant.
5. **Les tests des 4 points de capture non couverts** (#2 ROI, #3 podcast, #11/#12 Calendly
   admin, #17 candidature commerciale) : j'ai constaté l'absence de fichier de test dédié,
   je n'ai pas mesuré la couverture de ligne.
6. **`features/booking` en profondeur** : j'ai mesuré l'**absence totale** d'émission (E33-001),
   je n'ai pas inventorié lesquelles de ses 20 actions constituent un point de capture de
   contact au sens du mandat. C'est un périmètre d'agent à part entière.

---

*Agent 33 — mesuré le 2026-08-19 sur le site `main eb754332`. 52 tests `crm-sync` rejoués
verts (`09_vitest-crm-sync-52-tests.log`, 327 s). Aucune écriture, aucun événement émis.*
