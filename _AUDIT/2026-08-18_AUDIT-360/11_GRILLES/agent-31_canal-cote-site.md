# AGENT 31 — Auditeur du côté SITE du canal

> Périmètre : `src/server/crm-sync/**`, `src/server/actions/crm-sync/**`,
> `src/server/queue/workers/crm-sync-worker.ts`, `src/app/api/internal/crm-webhook/**`,
> `src/app/[locale]/(admin)/[adminPrefix]/synchro-crm/**`, `src/server/qualiopi/crm/**`,
> `CrmSyncOutbox` / `CrmSyncStatus`, les points de capture, la réconciliation, `CRM_SYNC_ALERT`.

## 0. Références mesurées, pas recopiées

| Dépôt | Chemin | HEAD relu le 2026-08-19 | Copie de travail |
| --- | --- | --- | --- |
| **SITE** (référence de ce rapport) | `C:\Users\willi\Documents\Projets\Axion-IA\axionia` | **`eb754332`** (`eb7543326386535c49d59f31117db2e3c81708ef`) | propre (0 ligne `git status --porcelain`) |
| CRM (contre-lecture du contrat) | `C:\Users\willi\Documents\Projets\Axion-CRM-Pro` | **`e8924b8`** (`e8924b81ad64c0b236acd99ac5cbac4cd68eada7`) | — |

Preuve : `04_PREUVES/agent-31/00_references.txt`.
Conformément à l'ordre de mission, **aucune mesure n'est passée par la pile locale du CRM en HTTP**
(A-009). Les deux seules commandes jouées dans un conteneur sont un `php -r` hors serveur web
(classification pure, aucune requête base) et un `psql` en **lecture** — jamais d'écriture, jamais
d'événement émis vers un CRM réel.

---

## 1. Grille — un objet par ligne

### 1.1 Les 16 points de capture (recensement du code, pas du prompt)

Mesure : `grep -rn "await sync[A-Za-z]*ToCrm({" src/features src/server src/app/api --include=*.ts`
→ **16 appels émetteurs**, preuve `04_PREUVES/agent-31/05_recensement-points-de-capture.txt`.

| # | Événement | Producteur (fichier:ligne) | Déclencheur | Ce qu'il envoie | Ce qu'il OMET | Visible si échec ? | Testé ? |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | `form_submission` × 12 finalités | `src/features/unified-contact/actions.ts:250` | soumission du formulaire unifié | `form_type`, personne, société (nom/ville/taille/secteur), `consent v1-2026-05-24`, `payload{subType,source,funnel}` | **`source_slug`**, `tags[]`, SIREN | oui si drapeau candidats ouvert (ligne `gave_up`) ; **non** pour `recrutement` drapeau fermé (aucune ligne écrite) — **E31-002** | partiel : la liste des 12 types est pinnée (`crm-sync.test.ts:180`), le croisement consentement × univers ne l'est pas |
| 2 | `form_submission` / `simulateur_roi` | `src/features/roi-report/actions.ts:168` | rapport de gains demandé | personne, société, `consent v1-2026-08-12`, `payload{savedEurPerYear,maturity,funnel}` | `source_slug`, `tags[]` | oui (outbox) | non (aucun test nomme `simulateur_roi` côté émission) |
| 3 | `form_submission` / `podcast` | `src/features/podcast-request/actions.ts:110` | formulaire podcast (QR flyers) | `source_slug=site-formulaire-podcast`, société, `consent podcast-v1-2026-07-21` | `occurredAt`, `consent.at`, `tags[]` | oui (outbox) — **mais jamais réconcilié, E31-005** | non |
| 4 | `form_submission` / `autre` | `src/server/chatbot/tools/capturer-lead.ts:156` | capture de lead par le chatbot | `source_slug=chatbot`, société, `payload{canal}`, **écrit DANS la transaction métier** (`tx`) | **tout le bloc `consent`**, `occurredAt`, `tags[]` | oui (outbox) | oui pour le `tx` (`crm-sync.test.ts:231`), non pour l'absence de consentement |
| 5 | `calendly_booked` | `src/app/api/calendly/client-event/route.ts:173` | `postMessage` de l'iframe sur `/appel` | `source_slug=calendly`, `payload{eventTypeSlug,pageUrl,utm*}` | **`consent`**, `occurredAt`, société, `tags[]` ; **rien du tout sans adresse d'invité** | non — sans email, silence total | oui (mock de la route) |
| 6 | `calendly_booked` | `src/server/calendly/discover.ts:244` | sondage API Calendly | idem + `payload.source=api_poll` | idem | non sans email | non |
| 7 | `calendly_canceled` / `calendly_no_show` | `src/server/calendly/enrich.ts:237` | enrichissement API détecte annulation/no-show | idem + `occurredAt` | **`consent`**, société | non sans email | oui (mock `enrich.test.ts:36`) |
| 8 | `calendly_booked` | `src/features/admin-calendly/actions.ts:181` | saisie manuelle d'un RDV en console | idem + `payload.source=manual_import` | idem | non sans email | non |
| 9 | `calendly_completed` / `no_show` / `canceled` | `src/features/admin-calendly/actions.ts:98` | changement de statut en console | idem + `payload.source=admin_status_change` | idem | non sans email | non |
| 10 | `newsletter_optin` | `src/features/newsletter/actions.ts:180` | **confirmation** du double opt-in | `consent newsletter-v1-2026-08-13`, `payload{source}` | `source_slug`, `occurredAt`, `tags[]` | oui (outbox) | oui côté réconciliation (`l5.test.ts:616`) |
| 11 | `newsletter_optout` | `src/features/newsletter/actions.ts:259` | clic du lien de désinscription | `payload{reason:"unsubscribe-link"}` | **tout le bloc `consent`**, `source_slug`, `occurredAt` | oui (outbox) — **mais invisible à la réconciliation, E31-006** | non |
| 12 | `review_posted` | `src/features/review-submission/actions.ts:217` | dépôt d'un avis client | société (nom), `consent reviews-v1-2026-07-06`, `payload{rating}` | `source_slug`, `occurredAt`, `consent.at` | oui (outbox) | oui côté réconciliation (`l5.test.ts:626`) |
| 13 | `application_submitted` | `src/features/job-application/actions.ts:315` | candidature à une offre publiée | `source_slug=site-candidature-offre`, famille, `offer_slug`, `cv_ref`, attributs, `consent careers-v2-2026-08-13` (+ `vivier_at` si case cochée) | `tags[]` | oui si drapeau candidats ouvert | non (aucun test du croisement consentement) |
| 14 | `application_submitted` | `src/features/commercial-application/actions.ts:273` | tunnel de candidature commerciale | `source_slug=site-candidature-commerciale`, `consent memo-v2-2026-08-13` (+ `vivier_at`) | `tags[]`, `cv_ref` | oui si drapeau candidats ouvert — **faux positif garanti de réconciliation sinon, E31-007** | non |
| 15 | `application_submitted` | `src/server/vivier/stock.ts:250` | batch J+30 d'intégration du stock (71 fiches) | `consent vivier-stock-2026-08-14` + `vivier_at` calculé, `cv_ref`, `attributes.vivierEntryMode` | `tags[]` | oui (garde d'état mixte : n'consomme l'échéance que si l'outbox a écrit) | oui (garde d'état mixte) |
| 16 | `opt_out` (opposition vivier) | `src/server/vivier/opposition.ts:66` | clic sur le lien d'opposition, sans login | `payload.scope="vivier"`, `consent.version` **lu en base** (`JobApplication.consentVersion`), univers **forcé** `vivier` | `source_slug` | **NON — E31-003 / E31-010** : drapeau candidats fermé ou déchiffrement raté ⇒ rien, aucune trace, réponse « ok » | non |

**Écart avec le chiffre 14 du prompt : +2.** Le code porte **16 émetteurs**, pour **10 types
d'événement** et **6 familles de `subject_ref`**. Le « 14 » du prompt correspond en réalité aux
**14 `FORM_TYPES`** du contrat (`SiteSyncEvent::FORM_TYPES` / `CRM_FORM_TYPES`), pas au nombre de
points de capture. Les deux surfaces que « 14 » n'inclut pas sont les deux qui ne sont pas
visiteur-facing : le **batch J+30 du stock vivier** (#15) et les **deux gestes console Calendly**
(#8 et #9, qui comptent pour un si on les groupe).

**Surfaces de capture qui n'émettent RIEN** (aucun des 16 émetteurs ne les couvre) :
`ChatEscalation` (escalade humaine du chatbot, porte une adresse en clair), `Client` et `Devis`
(module Qualiopi). Le vocabulaire `Taxonomy::ACTIVITY_KINDS` du CRM déclare `gdpr_export` et
`gdpr_erasure` ; **aucun émetteur du site ne produit ces deux types** — les demandes RGPD passent
par un autre endpoint (`…/site-sync/gdpr`, `crm-sync/gdpr.ts`), hors outbox et donc hors
observabilité de l'écran `synchro-crm`.

### 1.2 Modules du périmètre

| Objet | Rôle mesuré | Vérifié | Verdict |
| --- | --- | --- | --- |
| `crm-sync/types.ts` (132 l.) | contrat miroir ; 10 `CrmEventType`, 14 `CRM_FORM_TYPES`, champ `tags[]` | oui | miroir **exact** du CRM `e8924b8` (probe `01_…txt` : les 13 formes testées passent `SiteSyncEvent::fromArray`) ; `tags[]` = code mort (**E31-013**) |
| `crm-sync/config.ts` (60 l.) | drapeaux, URL, secret, backoff 1 min→6 h, 8 tentatives | oui | conforme ; inertie prouvée par test |
| `crm-sync/enqueue.ts` (120 l.) | outbox post-commit, `universeOf()`, verrou d'inertie | oui | `universeOf` **duplique** `SiteSyncClassifier::universe()` sans lien compilé (piège 15) ; retour `null` silencieux (**E31-003**) |
| `crm-sync/index.ts` (308 l.) | 7 fonctions publiques, construction de l'événement, `clean()` | oui | conforme ; `syncVivierOppositionToCrm` force l'univers vivier ⇒ le soumet au verrou candidats |
| `crm-sync/emit.ts` (178 l.) | HMAC `<ts>.<corps>`, 422 ⇒ `gave_up`, 503 ⇒ sans consommer de tentative | oui | conforme, testé (5 cas) |
| `crm-sync/inbound.ts` (302 l.) | contrat entrant, signature, idempotence, **`applyEffect()`** | oui | **`erasure` n'a aucune branche — E31-001 (S0)** ; contrat limité à 3 types ⇒ **E31-008** |
| `crm-sync/gdpr.ts` (135 l.) | propagation art. 15/17 vers `…/gdpr`, non bloquante | oui | conforme ; hors outbox, donc **absent de l'écran `synchro-crm`** |
| `crm-sync/reconcile.ts` (400 l.) | **l'instrument du critère 18** : 5 familles | oui | 4 défauts : **E31-004, E31-005, E31-006, E31-007** |
| `crm-sync/health.ts` (158 l.) | modèle de lecture de la console, lecture seule | oui | conforme ; recalcule la réconciliation à l'affichage (donc hérite de ses 4 défauts) |
| `crm-sync/alerts.ts` (88 l.) | dédup **horaire** par `kind`, seuil backlog 50 | oui | dédup horaire confirmée côté site (corrobore l'agent 14) ; **E31-011** |
| `queue/workers/crm-sync-worker.ts` (189 l.) | `emit` / `sweep` (10 min, lot 50) / `reconcile` | oui | conforme ; l'alerte du chemin `emit` ne porte pas de `subjectRef` (**E31-011**) |
| `actions/crm-sync/replay.ts` (86 l.) | rejeu manuel, garde TOCTOU, `jobId` horodaté | oui (lecture) | conforme ; **non exercé** (aucune ligne d'outbox nulle part, cf. §3) |
| `app/api/internal/crm-webhook/route.ts` (85 l.) | 200/401/422/500 | oui | conforme au contrat déclaré — mais le contrat est trop étroit (**E31-008**) et l'effet est faux (**E31-001**) |
| Écran `synchro-crm/page.tsx` (320 l.) | 5 compteurs + réconciliation + 20 lignes en erreur + rejeu | lecture seule (**geste réel impossible**, cf. §3) | affiche « Abandons définitifs » et la charge utile exacte — corrobore l'agent 14 ; hérite des défauts de la réconciliation |
| `prisma/schema.prisma` → `CrmSyncOutbox` (l. 10843) | 3 index (`status,nextAttemptAt` / `subjectRef` / `createdAt desc`) | oui | conforme au besoin de la réconciliation et du balayage |
| `enum CrmSyncStatus` (l. 10831) | `pending` / `sent` / `failed` / `gave_up` | oui | conforme ; **`gave_up` n'est pas distingué de `sent` par la réconciliation — E31-004** |
| `model CrmInboundEvent` (l. 10961) | journal du sens entrant, `outcome` | oui | conforme ; `outcome="applied"` **ment** pour un `erasure` (**E31-001**) |
| `src/server/qualiopi/crm/**` (11 fichiers) | **module sans aucun rapport avec le canal** | oui | `grep -rn "crm-sync" src/server/qualiopi/` → **0 résultat**. Il s'agit du référentiel clients/devis interne Qualiopi du site. **Collision de nom**, pas un objet du canal : rien à auditer ici au titre du canal, et `Client`/`Devis` n'émettent aucun événement |
| 2 suites de test du canal + suite du webhook | 59 tests | **rejoués** | **3 fichiers verts, 59/59, 445 s** (`06_vitest_suites-crm-sync.txt`) — **avec le défaut S0 en place** : piège 19 |

---

## 2. Verdicts demandés

### 2.1 Opposition vivier à version de consentement périmée → **RÉFUTÉ**

L'hypothèse transmise (« si le CRM valide la version pour tout événement d'univers vivier,
l'opposition part en 422 puis en `gave_up` ») **repose sur une prémisse fausse** : le CRM ne classe
PAS un `opt_out` en univers vivier.

`SiteSyncIngestService::ingest()` (CRM) n'appelle `assertCandidateConsentV2()` que
`if ($universe === 'vivier')`, et `SiteSyncClassifier::universe()` ne rend `vivier` que pour
`application_submitted` ou `form_submission`+`recrutement`. Mesure jouée :

```
OPPOSITION VIVIER (opposition.ts:66)   univers=business garde-v2=non appelee (univers business)
```

**Témoin positif du contrôle** — la garde v2, forcée par réflexion sur ce même événement, rougit
avec exactement la phrase du journal :

```
REJET : Fiche candidat refusée : consentement v2 requis
        (careers-v2-2026-08-13 | memo-v2-2026-08-13 | vivier-stock-2026-08-14),
        reçu : careers-v1-2026-06-09.
=> le contrôle EST capable de rougir ; il n'est simplement jamais atteint sur ce chemin.
```

La trace du journal du CRM existe bien, mais elle vient des **candidatures** (`application_submitted`
du stock, version `careers-v1-2026-06-09`), **pas** de l'opposition. Preuve :
`04_PREUVES/agent-31/01_classification-crm-par-point-de-capture.txt`.

**En revanche, la variante « `v1-2026-05-24` avec `form_type = recrutement` » est CONFIRMÉE**, et
elle est réelle : `recrutement` est l'un des 12 choix visibles du formulaire unifié
(`src/lib/schemas/unified-contact-schema.ts:29`). Voir **E31-002**.

Et l'opposition a un autre défaut, plus grave que celui recherché : elle **ne part pas du tout**
quand `CRM_SYNC_CANDIDATES_ENABLED` est fermé, sans la moindre trace (**E31-003**).

### 2.2 `erasure` → **N'EFFACE RIEN. CONFIRMÉ (B14-002), ligne à ligne.**

`src/server/crm-sync/inbound.ts:243-261`, la **seule** fonction qui applique un événement entrant :

```ts
async function applyEffect(payload: CrmInboundPayload): Promise<CrmInboundOutcome> {
  if (payload.event_type === "consent_optin") return "ignored";
  if (payload.scope !== "business") return "ignored";
  const subscriberId = await findSubscriberIdByHash(payload.email_hash);
  if (!subscriberId) return "no_match";
  await prisma.newsletterSubscriber.update({
    where: { id: subscriberId },
    data: { status: "unsubscribed", unsubscribedAt: new Date() },
  });
  return "applied";
}
```

Il n'existe **aucune** branche `erasure`. Le mot n'apparaît que **3 fois** dans tout
`src/server/crm-sync/` + `src/app/api/internal/crm-webhook/` : un commentaire (l. 17), une union de
types (l. 58), un `Set` de validation (l. 133). Jamais dans un `if`, jamais dans un test.
Preuve : `04_PREUVES/agent-31/04_erasure_verdict.txt`.

**C'est même pire que « rien n'est effacé »** : l'effacement local du site
(`/api/gdpr-erase` + `src/lib/rgpd-erase.ts`) **supprime** l'abonné
(`newsletterSubscriber.deleteMany`, `rgpd-erase.ts:87`) et touche 5 ensembles de données
(Submissions anonymisées, newsletter supprimée, favoris KB, conversations chatbot supprimées,
escalades anonymisées). L'`erasure` venu du CRM ne fait **que marquer l'abonné `unsubscribed`** —
strictement moins que l'opt-out local. Et pour `scope: "vivier"` (l'effacement d'un candidat), il
retombe sur `return "ignored"` : **effet nul**, réponse **200 `{ok:true, outcome:"ignored"}`**.

Le commentaire d'en-tête du module affirme le contraire (« `consent_optout` **et `erasure`**
produisent un effet local »). Le code fait foi (doctrine, règle 1).

### 2.3 Critère 18 du §29 → **NON MESURABLE sur les données réelles**, et l'instrument censé le mesurer est faux

**Pourquoi la mesure est impossible** (preuve `04_PREUVES/agent-31/02_critere-18_non-mesurable.txt`) :

1. `grep -c "^CRM_SYNC" .env.local .env.dev` → **0 et 0**. Sans `CRM_SYNC_ENABLED=true`,
   `enqueueCrmSyncEvent()` rend `null` avant toute écriture : **l'outbox est vide par construction**.
2. `docker ps -a` → **`axion-ia-postgres  Exited (127) 3 weeks ago`**. La base de développement du
   site n'existe plus. Le port 5433 est occupé par un autre service (`FATAL: password
   authentication failed for user "axion_ia"`).
3. Côté CRM local, en lecture : `select count(*) from activities where external_ref like
   'site:event:%'` → **0 ligne**, et `select count(*) from activities` → **0**. Le canal n'a jamais
   rien porté ici (`03_crm-local_evenements-site-recus.txt`).
4. La production du site n'est accessible qu'authentifiée, et l'ordre de mission interdit toute
   écriture : je n'ai ni accès en lecture à sa base, ni le droit d'émettre un événement témoin.

**Témoin négatif de la méthode** : la même commande `psql`, pointée sur une base qui existe, rend un
résultat (`select count(*) from migrations` → **58**). L'échec mesure donc bien l'absence de base,
pas l'incapacité de l'outil.

**Ce que j'ai pu mesurer à la place : l'instrument.** Le critère 18 n'est pas mesuré à la main par
un humain ; il est censé l'être par `runCrmSyncReconciliation()`. Or, même quand il tourne, cet
instrument **ne répond pas à la question du critère 18** :

| Écart | Effet sur le critère 18 |
| --- | --- |
| Il compare **« a une ligne d'outbox »**, pas « a été reçu par le CRM » : `crmSyncOutbox.findMany({where:{subjectRef:{in:refs}}})` **sans filtre de statut** (`reconcile.ts:311`) | Une ligne `gave_up` (refus 422 définitif) compte comme **émise**. Un lead que le CRM a refusé pour toujours est rapporté **conforme**. **E31-004** |
| Il connaît **5 familles**, le code en émet **6** (`site:podcast_request` absent) | Les demandes podcast **ne sont jamais comparées**. **E31-005** |
| Il compare des **enregistrements**, pas des **événements** : 6 des 10 types (`calendly_booked/completed/canceled/no_show`, `newsletter_optout`, `opt_out`) réutilisent le `subject_ref` de l'enregistrement de création | Une annulation de RDV, une désinscription ou une opposition **jamais émise** est invisible : la présence de l'événement de création suffit à déclarer la parité. **E31-006** |
| La famille `submission` est comparée **inconditionnellement**, alors que les candidatures du tunnel commercial écrivent une `Submission` soumise au verrou vivier | Drapeau candidats fermé ⇒ **100 % des candidatures commerciales sont comptées « manquantes »**, mélangées aux vrais manquants. **E31-007** |

Autrement dit : **le critère 18 ne peut ni être mesuré aujourd'hui, ni l'être demain par le
dispositif prévu**, sans les quatre correctifs ci-dessous.

---

## 3. Constats

### [E31-001] Le canal entrant traite `erasure` comme une désinscription : le site répond « 200 applied » et n'efface rien
- Sévérité      : **S0**
- Domaine       : conformité / canal
- Référence     : site `eb754332` (CRM `e8924b8`)
- Emplacement   : `src/server/crm-sync/inbound.ts:243-261` (`applyEffect`), route `src/app/api/internal/crm-webhook/route.ts:78`
- Constat       : `applyEffect()` ne comporte aucune branche pour `event_type: "erasure"` : l'événement suit la même ligne que `consent_optout` et son unique effet est de passer un abonné newsletter en `status: "unsubscribed"`, après quoi la route renvoie `200 {ok:true, outcome:"applied"}`.
- Preuve        : `04_PREUVES/agent-31/04_erasure_verdict.txt` — corps de `applyEffect` ; `grep -rn "erasure" src/server/crm-sync/ src/app/api/internal/crm-webhook/` → **3 occurrences, toutes déclaratives** (commentaire l. 17, union de types l. 58, `Set` l. 133).
- Témoin négatif: la même recherche, appliquée à `consent_optin` / `consent_optout`, **trouve** la branche de traitement (`inbound.ts:246`) **et** les tests correspondants (`crm-sync-l5.test.ts:164` et `:219`). La méthode voit donc bien un type d'événement traité et testé quand il l'est. Second témoin : les 3 suites du canal sont **vertes, 59/59** avec le défaut en place (`06_vitest_suites-crm-sync.txt`) — aucune garde ne mesure cet objet (piège 19).
- Impact        : une personne qui exerce son **droit à l'effacement depuis le CRM** obtient, sur le site : ses `Submission` non anonymisées, sa `JobApplication` et son CV intacts, ses conversations chatbot conservées, son avis client conservé, ses favoris KB conservés — et un abonné newsletter simplement marqué désinscrit, alors que l'effacement local du site le **supprime** (`src/lib/rgpd-erase.ts:87`). Pour `scope:"vivier"` (effacement d'un candidat), l'effet est **strictement nul** (`return "ignored"`) et la réponse reste 200. Le CRM, lui, journalise un succès : **la non-conformité est invisible des deux côtés**.
- Reproduction  : POST signé sur `/api/internal/crm-webhook` avec `{event_type:"erasure", scope:"business", email_hash:<sha256 d'un abonné confirmé>}` → réponse `200 {"ok":true,"outcome":"applied"}` ; la seule ligne modifiée est `newsletter_subscribers.status`.
- Correctif     : router `erasure` vers la chaîne d'effacement déjà écrite et éprouvée (`eraseChatDataForEmail`, `eraseSubmissionsForEmail`, `eraseNewsletterForEmail`, `eraseKbDataForEmail`) — elle prend un email en clair, or l'entrant ne porte qu'un sha256 non salé : soit le CRM joint l'adresse (il l'a), soit on résout le sha256 par le même balayage que `findSubscriberIdByHash` étendu aux tables porteuses d'email en clair. Tant que ce n'est pas fait, **renvoyer `outcome: "unsupported"` et non `"applied"`**, et alerter (`CRM_SYNC_ALERT`) : une non-conformité visible vaut mieux qu'un succès faux. Coût : 0,5 j pour le correctif d'honnêteté (outcome + alerte), 2-3 j pour l'effacement complet + tests.
- Statut        : ouvert

### [E31-002] Une demande « Recrutement » du formulaire unifié ne peut jamais arriver au CRM : refus 422 garanti, ou aucune ligne d'outbox du tout
- Sévérité      : **S1**
- Domaine       : canal
- Référence     : site `eb754332`, CRM `e8924b8`
- Emplacement   : `src/features/unified-contact/actions.ts:50` (`CONSENT_VERSION = "v1-2026-05-24"`) et `:250` ; `src/server/crm-sync/enqueue.ts:118` ; CRM `SiteSyncClassifier::universe()` + `SiteSyncIngestService::assertCandidateConsentV2()`
- Constat       : `recrutement` est l'un des 12 choix du formulaire unifié (`src/lib/schemas/unified-contact-schema.ts:29`, groupe « Autres demandes ») ; il est classé en univers **vivier** par les deux systèmes, mais il émet la version de consentement du formulaire commercial `v1-2026-05-24`, qui n'appartient pas à `Taxonomy::CANDIDATE_CONSENT_VERSIONS_V2`.
- Preuve        : `04_PREUVES/agent-31/01_classification-crm-par-point-de-capture.txt` — `unified-contact recrutement → univers=vivier, garde-v2=REJET 422 : Fiche candidat refusée : consentement v2 requis (…), reçu : v1-2026-05-24.`
- Témoin négatif: dans la même exécution, les trois émetteurs candidats légitimes (`careers-v2-2026-08-13`, `memo-v2-2026-08-13`, `vivier-stock-2026-08-14`) rendent `garde-v2=PASSE`. La sonde distingue donc bien un consentement accepté d'un consentement refusé.
- Impact        : **deux états, aucun ne délivre.** (a) `CRM_SYNC_CANDIDATES_ENABLED` fermé : `enqueueCrmSyncEvent` rend `null` avant toute écriture — le lead n'a **aucune ligne d'outbox**, l'écran `synchro-crm` ne montre rien, et la réconciliation le compte en « manquant » sans en donner la cause. (b) drapeau ouvert : 422 → `gave_up` → alerte (une par heure, cf. E31-011) → il faut un geste humain qui ne pourra jamais aboutir, puisque rejouer le même corps redonnera 422.
- Reproduction  : soumettre le formulaire unifié avec `type = "recrutement"` ; observer soit l'absence de ligne d'outbox, soit une ligne `gave_up` portant `candidate_consent_v2_required`.
- Correctif     : trancher côté produit. Soit `recrutement` cesse d'être un motif du formulaire unifié (redirection vers `/carrieres`, cohérent avec l'existence d'un tunnel dédié) — ~0,5 j ; soit ce motif sert son propre texte de consentement v2 et l'ajoute aux deux listes fermées **ensemble** — ~1 j + coordination CRM. **Dans les deux cas, ajouter le test croisé qui manque** : « tout `form_type` classé vivier émet une version de `CANDIDATE_CONSENT_VERSIONS_V2` », pinné des deux côtés.
- Statut        : ouvert

### [E31-003] L'opposition au vivier n'est pas transmise au CRM quand le flux candidats est fermé, et cet échec ne laisse aucune trace
- Sévérité      : **S1**
- Domaine       : conformité / canal
- Référence     : site `eb754332`
- Emplacement   : `src/server/vivier/opposition.ts:66` → `src/server/crm-sync/index.ts:169` (univers forcé `"vivier"`) → `src/server/crm-sync/enqueue.ts:86` (`if (universe === "vivier" && !isCrmSyncCandidatesEnabled()) return null;`)
- Constat       : `syncVivierOppositionToCrm` force l'univers `vivier`, ce qui soumet l'opposition au verrou `CRM_SYNC_CANDIDATES_ENABLED` ; quand ce verrou est fermé, l'appel rend `null` sans écrire de ligne d'outbox, sans journaliser et sans alerter, et `recordVivierOpposition` ignore la valeur de retour puis renvoie `{ ok: true }`.
- Preuve        : lecture de la chaîne complète (`opposition.ts:66` → `index.ts:169-175` → `enqueue.ts:84-87`) ; le retour de `dispatch()` est **jeté** dans `syncVivierOppositionToCrm` (`await dispatch(...)`, type de retour `Promise<void>`), là où `vivier/stock.ts:250` **le récupère** et refuse de consommer l'échéance si `outboxId === null`. La garde existe donc dans le dépôt — elle n'a simplement pas été posée sur ce chemin-là.
- Témoin négatif: `vivier/stock.ts:283` (« Aucune ligne d'outbox posée … on NE consomme PAS l'échéance ») prouve que le dépôt sait détecter et traiter ce cas précis. Le contrôle existe, il n'est pas appliqué ici.
- Impact        : le commentaire de `index.ts` justifie l'omission par « tant que ce drapeau est à OFF, aucune fiche candidat n'est jamais partie au CRM ». **Le raisonnement ne tient pas dans le sens inverse du temps** : une fois le drapeau ouvert et les 71 fiches du stock intégrées, toute fermeture ultérieure du drapeau (incident, rollback, bascule de maintenance) rend les oppositions **silencieusement inopérantes côté CRM**, alors que les fiches y sont. La personne a exercé son art. 21, le site le lui confirme, le CRM continue de la conserver et de la démarcher. Aucun compteur, aucune alerte, aucune ligne à rejouer.
- Reproduction  : `CRM_SYNC_ENABLED=true`, `CRM_SYNC_CANDIDATES_ENABLED=false`, cliquer le lien d'opposition → `vivierOpposedAt` posé, `consent_events` écrit, **`crm_sync_outbox` vide**, réponse « ok ».
- Correctif     : soit exempter `opt_out` du verrou candidats (une opposition doit pouvoir partir même canal candidats fermé — c'est le seul événement dont le refus est plus dangereux que l'émission), soit récupérer le retour `null` et alerter (`CRM_SYNC_ALERT` `kind: "gave_up"`) + consigner. La première option est la bonne : ~0,5 j, plus un test.
- Statut        : ouvert

### [E31-004] La réconciliation compte comme « émis » une événement définitivement abandonné : le critère 18 rend « conforme » sur des leads perdus
- Sévérité      : **S1**
- Domaine       : canal / tests
- Référence     : site `eb754332`
- Emplacement   : `src/server/crm-sync/reconcile.ts:308-312`
- Constat       : la requête de comparaison est `prisma.crmSyncOutbox.findMany({ where: { subjectRef: { in: refs } }, select: { subjectRef: true } })` — **sans aucun filtre sur `status`** ; une ligne `gave_up` (refus 422 définitif du CRM) est donc comptée dans `emitted`.
- Preuve        : `reconcile.ts:308-312` (code) ; `enum CrmSyncStatus` (`prisma/schema.prisma:10831`) distingue pourtant explicitement `sent` de `gave_up` (« Ne sera plus rejouée : à traiter à la main »). La colonne `crmResult` existe et porte l'accusé du CRM — elle n'est pas lue par la réconciliation.
- Témoin négatif: la suite `crm-sync-l5.test.ts` contient bien un test « compte les sources sans ligne d'outbox et alerte » (l. 477) qui **rougirait** si aucune ligne n'existait. Le contrôle sait détecter l'absence ; il est aveugle au statut.
- Impact        : le critère 18 du §29 demande l'égalité entre ce que voit la console et ce que **reçoit** le CRM. Tel qu'implémenté, il mesure ce que le site a **écrit chez lui**. Un canal dont 100 % des messages seraient refusés en 422 rendrait `totalMissing = 0` et un rapport vert. C'est exactement le mode de panne de E31-002.
- Reproduction  : poser une ligne d'outbox `gave_up` pour une `Submission` de la fenêtre ⇒ `missing = 0`.
- Correctif     : `where: { subjectRef: { in: refs }, status: "sent" }`, et rapporter séparément les trois populations (`sent`, `en cours`, `abandonné`) — le rapport gagne en information au lieu d'en perdre. ~0,5 j + adaptation de 2 tests.
- Statut        : ouvert

### [E31-005] La réconciliation ignore la 6ᵉ famille source : les demandes podcast ne sont jamais comparées
- Sévérité      : S2
- Domaine       : canal
- Référence     : site `eb754332`
- Emplacement   : `src/server/crm-sync/reconcile.ts:73` (`CrmSyncFamily`, 5 valeurs) vs `src/features/podcast-request/actions.ts:111` (`subjectRef: site:podcast_request:<id>`)
- Constat       : le code émet **6** préfixes de `subject_ref` (`site:submission`, `site:job_application`, `site:calendly_event`, `site:newsletter_subscriber`, `site:customer_review`, `site:podcast_request`) ; la réconciliation en compare **5**.
- Preuve        : `04_PREUVES/agent-31/05_recensement-points-de-capture.txt` — décompte des `subject_ref` émis (6 familles) face aux 6 déclarations `family: "…"` de `reconcile.ts` (qui ne portent que 5 valeurs distinctes, `job_application` apparaissant deux fois).
- Témoin négatif: le test `l5.test.ts:590` « le rapport porte les cinq familles, jamais moins » **passe** — il pinne le nombre 5, donc il **empêche** de découvrir la sixième au lieu de la révéler. Garde irréprochable qui mesure le mauvais objet (piège 19).
- Impact        : le formulaire podcast est relayé par les QR des flyers papier ; une perte d'événement sur ce canal est indétectable par le filet quotidien, et le critère 18 est déclaré satisfait sans l'avoir été sur cette famille.
- Reproduction  : créer une `PodcastRequest` sans ligne d'outbox ⇒ `totalMissing = 0`.
- Correctif     : ajouter la famille `podcast_request` (le modèle a une colonne d'horodatage propre) et transformer le test « cinq familles » en « une famille par préfixe de `subject_ref` émis » — un test qui rougit à l'ajout d'un émetteur. ~0,5 j.
- Statut        : ouvert

### [E31-006] La réconciliation est aveugle aux 6 événements de changement d'état, qui réutilisent le `subject_ref` de la création
- Sévérité      : S2
- Domaine       : canal
- Référence     : site `eb754332`
- Emplacement   : `src/server/crm-sync/reconcile.ts:293-320` (`compareFamily`) ; émetteurs `calendly/enrich.ts:239`, `admin-calendly/actions.ts:100`, `newsletter/actions.ts:260`, `vivier/opposition.ts:67`
- Constat       : `compareFamily` teste l'**existence** d'au moins une ligne d'outbox pour un `subject_ref` donné ; or `calendly_canceled`, `calendly_no_show`, `calendly_completed`, un second `calendly_booked`, `newsletter_optout` et `opt_out` portent **le même `subject_ref`** que l'événement de création de leur enregistrement.
- Preuve        : décompte des `subject_ref` (`05_…txt`) : 5 émetteurs Calendly pour 1 préfixe, 2 émetteurs newsletter pour 1 préfixe, 3 émetteurs `job_application` pour 1 préfixe. Croisé avec `reconcile.ts:315` (`const seen = new Set(emitted.map(r => r.subjectRef))`).
- Témoin négatif: le test `l5.test.ts:601` prouve qu'un RDV **sans aucune** ligne d'outbox est bien vu comme manquant. Le contrôle détecte donc l'absence totale — mais jamais l'absence d'un événement parmi plusieurs sur le même sujet.
- Impact        : 6 des 10 types d'événement du contrat sont hors du filet. Une annulation de RDV, une désinscription ou une opposition non émise n'apparaît nulle part. C'est précisément la moitié du canal que le critère 18 prétend couvrir (« réservations … et inscriptions »).
- Reproduction  : émettre `calendly_booked` puis perdre l'émission de `calendly_canceled` ⇒ `missing = 0`.
- Correctif     : comparer sur le couple (`subject_ref`, `event_type`) attendu, en dérivant l'attendu de l'état de l'enregistrement source (RDV `status = canceled` ⇒ un `calendly_canceled` est attendu ; `newsletterSubscriber.status = unsubscribed` ⇒ un `newsletter_optout` ; `jobApplication.vivierOpposedAt != null` ⇒ un `opt_out`). ~1,5 j.
- Statut        : ouvert

### [E31-007] La famille `submission` produit des faux positifs garantis : les candidatures du tunnel commercial sont comptées « manquantes » quand le flux candidats est fermé
- Sévérité      : S2
- Domaine       : canal
- Référence     : site `eb754332`
- Emplacement   : `src/server/crm-sync/reconcile.ts:160-179` (famille `submission`, comparée **sans condition**) vs `src/features/commercial-application/actions.ts:202` (`prisma.submission.create`) et `:273` (`syncCandidateToCrm` ⇒ univers vivier)
- Constat       : le tunnel de candidature commerciale écrit une ligne `Submission` mais émet un `application_submitted` d'univers **vivier**, soumis au verrou `CRM_SYNC_CANDIDATES_ENABLED` ; la réconciliation exclut explicitement la famille `job_application` quand ce verrou est fermé, mais **n'exclut rien** dans la famille `submission`.
- Preuve        : `reconcile.ts:181-186` (« Le vivier a son propre verrou … comparer produirait un écart de 100 % qui ne signale rien ») face à `reconcile.ts:163-179`, où aucune exclusion symétrique n'est faite ; `grep "prisma.submission.create"` → 3 émetteurs, dont `commercial-application/actions.ts:202`.
- Témoin négatif: le test `l5.test.ts:501` « ne compare PAS le vivier tant que le flux candidats est fermé » **passe** — il vérifie l'exclusion sur `job_application` uniquement, donc il atteste d'une protection qui ne couvre pas le cas réel.
- Impact        : dans l'état d'exploitation **prévu** (synchro ouverte, candidats fermé), chaque candidature commerciale génère une alerte `reconcile_gap` fausse. Le raisonnement du code lui-même s'applique : un écart qui ne signale rien apprend à ne plus lire les alertes — et noie les vrais manquants (E31-002 (a)) parmi eux.
- Reproduction  : `CRM_SYNC_ENABLED=true`, `CRM_SYNC_CANDIDATES_ENABLED=false`, soumettre le tunnel commercial, lancer la réconciliation ⇒ 1 manquant.
- Correctif     : exclure de la famille `submission` les lignes dont l'univers d'émission est vivier (le champ discriminant existe déjà dans `Submission.details`/`type`), ou — plus propre — dériver l'attendu de la même fonction `universeOf()` que l'émission, au lieu de le réécrire. ~0,5 j.
- Statut        : ouvert

### [E31-008] Le contrat entrant n'accepte que 3 types : le §22.6 (« le statut du RDV redescend vers la console tout seul ») est inapplicable par construction
- Sévérité      : S2
- Domaine       : canal / navigation
- Référence     : site `eb754332`
- Emplacement   : `src/server/crm-sync/inbound.ts:133` (`EVENT_TYPES = new Set(["consent_optout","consent_optin","erasure"])`) et `:158` (`parseInboundPayload` rend `null` sinon) → `route.ts:72` (`422 contract_violation`)
- Constat       : le site émet bien `calendly_completed`, `calendly_canceled` et `calendly_no_show` vers le CRM (3 émetteurs, cf. grille §1.1 lignes 7 et 9), mais son webhook entrant **refuserait en 422** tout événement de statut que le CRM lui renverrait : aucun type de retour métier n'est prévu.
- Preuve        : `inbound.ts:133` et `:158` (code) ; `04_PREUVES/agent-31/04_erasure_verdict.txt` pour l'énumération complète.
- Témoin négatif: le test `crm-webhook/route.test.ts:94` (« 422 sur un corps qui viole le contrat ») **prouve** que le refus fonctionne — ce n'est donc pas une hypothèse : un `calendly_completed` venu du CRM serait rejeté.
- Impact        : corrobore et complète la mesure de l'agent 14 (« le CRM ne renvoie rien ») — même s'il renvoyait quelque chose, le site le refuserait. Le §22.6 du CDC n'est pas « pas encore branché » : il est **contredit par les deux contrats à la fois**. Un opérateur qui change le statut d'un RDV dans le CRM ne le verra jamais dans la console du site, et réciproquement il n'existe aucun chemin de convergence.
- Reproduction  : POST signé `{event_type:"calendly_completed", …}` sur `/api/internal/crm-webhook` → `422 {"error":"contract_violation"}`.
- Correctif     : décision produit d'abord (le CRM doit-il être source de vérité du statut d'un RDV, ou la console du site ?). Techniquement : étendre `CrmInboundEventType`, `EVENT_TYPES`, et poser l'effet local sur `CalendlyEvent.status` avec le même garde-fou anti-boucle (`origin: "crm"` déjà présent). ~2 j côté site, symétrique côté CRM. **Tant que ce n'est pas tranché, retirer la promesse du §22.6** — un CDC qui décrit une fonction absente est un piège pour le prochain lecteur.
- Statut        : ouvert

### [E31-009] L'opposition d'un candidat au vivier fait naître, côté CRM, une activité `pending_match` dans le workspace BUSINESS portant son adresse en clair
- Sévérité      : S2
- Domaine       : conformité
- Référence     : site `eb754332`, CRM `e8924b8`
- Emplacement   : émission `src/server/vivier/opposition.ts:66-70` (bloc `person: { email }`) ; effet CRM `SiteSyncIngestService::apply()` → `upsertBusiness()` → `recordActivity()` bloc `$pendingMatch`
- Constat       : le CRM classe l'`opt_out` en univers **business** (mesuré, §2.1) ; sans SIREN, `upsertBusiness()` rend `PENDING_MATCH` et `recordActivity()` écrit dans le workspace business une activité dont la charge utile `pending_match` contient `email`, `first_name`, `last_name`, `phone` du candidat.
- Preuve        : `04_PREUVES/agent-31/01_classification-crm-par-point-de-capture.txt` (`OPPOSITION VIVIER … univers=business`) ; côté CRM, `SiteSyncIngestService.php`, bloc `$pendingMatch = array_filter([... 'email' => $event->email(), ...])`.
- Témoin négatif: le même probe montre que `oppositionScope()` **réussit** à faire retomber l'opposition dans le scope `vivier` (grâce à `payload.scope` + `subject_ref`) : la protection existe et fonctionne **pour la liste d'opposition**. Elle ne s'étend pas à l'univers d'écriture de l'activité.
- Impact        : exercer son droit d'opposition à la conservation en vivier **fait apparaître** ses données identifiantes dans l'univers commercial, où elles n'existaient pas, sous la forme d'un événement à arbitrer par un opérateur. C'est l'inverse de l'effet demandé. À croiser avec l'agent 14 (côté CRM), qui tient le correctif.
- Reproduction  : émettre l'`opt_out` de `opposition.ts` sans SIREN (cas nominal, une opposition n'en porte jamais) ⇒ activité `pending_match` en workspace business.
- Correctif     : côté CRM, court-circuiter l'upsert pour un événement d'opposition (`isOppositionEvent`) : inscrire la liste d'opposition et la timeline dans le workspace de **son scope**, sans jamais créer de matière d'arbitrage business. Côté site, cesser de joindre `first_name`/`last_name`/`phone` à une opposition (l'`email` seul suffit au hachage). ~0,5 j site + 1 j CRM.
- Statut        : ouvert

### [E31-010] Une opposition dont l'adresse ne se déchiffre pas est perdue en silence, et la route répond quand même « ok »
- Sévérité      : S2
- Domaine       : conformité
- Référence     : site `eb754332`
- Emplacement   : `src/server/vivier/opposition.ts:39` (`const email = safeDecrypt(application.email)`) et `:54` (`if (email) { … }`) ; retour `{ ok: true, alreadyOpposed: false, applications: n }` ligne 72
- Constat       : si le déchiffrement de l'adresse échoue (clé PII absente ou tournée), le bloc conditionnel est sauté : **ni** la ligne du registre de preuve (`recordConsentEvent`), **ni** l'émission CRM ne sont exécutées, et la fonction renvoie tout de même un succès.
- Preuve        : lecture de `opposition.ts:39-72`. `safeDecrypt` (l. 98-…) attrape et rend `null` sans journaliser au niveau de l'appelant.
- Témoin négatif: le même module journalise explicitement l'autre mode d'échec (`catch (error) { console.error("[vivier] opposition non enregistrée:", error) }`, l. 74) — la journalisation d'échec existe donc dans ce fichier ; elle ne couvre pas ce chemin-là.
- Impact        : `vivierOpposedAt` est bien posé (l'effet principal est sauf), mais l'exercice du droit **n'entre pas au registre de preuve** exigé par l'art. 7(1)/art. 30, et n'atteint jamais le CRM. Le compteur `applications` renvoyé à la personne affirme le contraire. Aucune alerte, aucune ligne d'outbox : rien à rejouer.
- Reproduction  : rendre `PII_ENCRYPTION_KEY` invalide, cliquer un lien d'opposition ⇒ `vivierOpposedAt` posé, `consent_events` sans ligne, `crm_sync_outbox` vide, réponse « ok ».
- Correctif     : journaliser + `alertCrmSync({kind:"gave_up", subjectRef})` dans la branche `else`, et renvoyer un état distinct (`{ ok: true, propagated: false }`) que la page d'opposition affiche. ~0,5 j.
- Statut        : ouvert

### [E31-011] Une rafale d'abandons produit un seul message qui, sur le chemin d'émission immédiate, ne nomme aucun lead
- Sévérité      : S2
- Domaine       : canal
- Référence     : site `eb754332`
- Emplacement   : `src/server/crm-sync/alerts.ts:52` (`crmSyncAlertDedupKey` = `kind` + seau **horaire UTC**) ; `src/server/queue/workers/crm-sync-worker.ts:170-174` (alerte du chemin `emit`, **sans `subjectRef`**) vs `:80-85` (alerte du balayage, **avec** `subjectRef`)
- Constat       : la clé de déduplication ne porte que le type d'anomalie et l'heure ; 40 abandons dans la même heure ne produisent **qu'un** message. Et l'alerte émise depuis le traitement d'un job `emit` — le chemin nominal, celui qui part quelques secondes après la capture — n'inclut pas le `subjectRef`, contrairement à celle du balayage.
- Preuve        : `alerts.ts:52-56` (code de la clé) ; `crm-sync-worker.ts:170-174` (`alertCrmSync({ kind: "gave_up", ...(result.error !== undefined ? { detail: result.error } : {}) })` — aucun `subjectRef`) ; format du message `src/server/notifications/format.ts:511-518` (le champ `Source` est simplement omis quand il est absent).
- Témoin négatif: le test `l5.test.ts:301` « trois alertes du même type dans l'heure ne produisent QU'UN envoi » **passe** : le comportement est donc voulu et mesuré. Et `l5.test.ts:296` prouve que deux `kind` différents ne se dédupliquent pas l'un l'autre — la méthode distingue bien. Confirme la mesure de l'agent 14 depuis le côté site.
- Impact        : le seul signal qui dit « ce lead n'arrivera jamais » est agrégé à un par heure **et**, dans le cas le plus fréquent, anonyme. La console reste la seule source complète (compteur « Abandons définitifs » + 20 lignes détaillées avec charge utile) — mais il faut savoir aller la regarder, et le message Telegram ne donne ni le nombre ni la référence pour y aller.
- Reproduction  : provoquer deux abandons 422 dans la même heure via le chemin `emit` ⇒ un message Telegram sans champ `Source`, aucun compteur.
- Correctif     : (a) passer `row.subjectRef` dans l'alerte du chemin `emit` (le job connaît l'`outboxId`, une lecture suffit) ; (b) faire porter au message le **nombre** d'abandons de l'heure plutôt qu'un seul exemple — la charge utile `count` existe déjà dans le contrat de la catégorie et est affichée par `format.ts`. ~0,5 j.
- Statut        : ouvert

### [E31-012] Onze des seize émetteurs n'envoient aucun `source_slug` : la provenance exacte n'est jamais dans la timeline du CRM
- Sévérité      : S3
- Domaine       : canal
- Référence     : site `eb754332`, CRM `e8924b8`
- Emplacement   : émetteurs sans `sourceSlug` : `unified-contact/actions.ts:250`, `roi-report/actions.ts:168`, `newsletter/actions.ts:180` et `:259`, `review-submission/actions.ts:217`, `vivier/opposition.ts:66`
- Constat       : **5 valeurs** de `source_slug` seulement sont émises (`calendly` ×5, `chatbot`, `site-candidature-offre` ×2, `site-candidature-commerciale`, `site-formulaire-podcast`), par 10 des 16 émetteurs.
- Preuve        : `04_PREUVES/agent-31/05_recensement-points-de-capture.txt` (décompte des `sourceSlug:`).
- Témoin négatif: la sonde de classification montre que le CRM **retombe** correctement sur un tag dérivé pour chacun de ces cas (`src:site-formulaire-simulateur-roi`, `src:newsletter`, `src:avis-client`, `src:site-formulaire-recrutement`) et que **tous** ces slugs existent au référentiel gouverné (`GovernedTagsSeeder`, l. 39-95). L'impact est donc borné — ce n'est pas une perte de tag.
- Impact        : `activities.payload.source_slug` est écrit à `null` (`SiteSyncIngestService::recordActivity()` le pose systématiquement), et l'opposition vivier hérite du tag fourre-tout `src:site-formulaire-autre` (mesuré). L'analyse de provenance depuis la timeline est donc plus pauvre que le contrat ne le permet.
- Reproduction  : cf. sortie de la sonde, colonne `tags=` pour les lignes `avis client`, `simulateur ROI`, `newsletter_*`, `OPPOSITION VIVIER`.
- Correctif     : ajouter `sourceSlug` aux 6 émetteurs (valeurs déjà gouvernées côté CRM : `site-formulaire-<type>`, `newsletter`, `avis-client`) et un slug propre pour l'opposition. ~0,5 j, plus l'ajout du tag `src:vivier-opposition` au référentiel CRM.
- Statut        : ouvert

### [E31-013] Le champ `tags[]` du contrat n'est renseigné par aucun émetteur : code mort des deux côtés
- Sévérité      : S3
- Domaine       : canal
- Référence     : site `eb754332`, CRM `e8924b8`
- Emplacement   : déclaration `src/server/crm-sync/types.ts:105` (`tags?: string[]`) et `src/server/crm-sync/index.ts:100` (`tags?: string[]` dans `BaseInput`), consommation CRM `SiteSyncClassifier::tags()` (`foreach ($event->tags as $tag)`)
- Constat       : aucun des 16 émetteurs ne passe `tags` à une fonction `sync*ToCrm`.
- Preuve        : `04_PREUVES/agent-31/05_recensement-points-de-capture.txt`, section « recherche CIBLEE dans les 16 fichiers émetteurs » — les occurrences trouvées (`commercial-application`, `roi-report`, `unified-contact`, `podcast-request`, `job-application`, `review-submission`) sont **toutes** des `tags:` de `Sentry.captureException`, vérifiées à la lecture ; aucune n'est un argument d'appel au canal.
- Témoin négatif: la même méthode trouve bien les champs **réellement** renseignés dans ces mêmes appels (`sourceSlug`, `consent`, `company`, `payload`), ce qui prouve qu'elle voit un champ passé quand il l'est.
- Impact        : nul aujourd'hui (le CRM dérive ses tags de provenance). Mais deux surfaces de code, dans deux dépôts qu'aucun compilateur ne relie, existent sans usage : la prochaine évolution s'appuiera dessus sans savoir qu'elle est le premier utilisateur, et sans test pour la couvrir.
- Reproduction  : —
- Correctif     : soit un premier usage utile (les émetteurs candidats pourraient poser `cand-zone:<dept>`, namespace déjà dérivable côté CRM), soit retrait du champ des deux contrats. Décider, ne pas laisser. ~0,5 j.
- Statut        : ouvert

---

## 4. Ce que je n'ai PAS pu vérifier, et pourquoi

1. **Le critère 18 sur des données réelles.** Impossible : la base de développement du site est
   éteinte depuis 3 semaines, les drapeaux de synchro sont absents de tous les `.env` locaux (donc
   l'outbox est vide par construction), le CRM local ne porte **0 activité**, et je n'ai ni accès en
   lecture à la base de production du site ni le droit d'y émettre un événement témoin. Preuve
   complète, témoin négatif compris, dans `02_critere-18_non-mesurable.txt`. **Ce que j'ai mesuré à
   la place, c'est l'instrument** (§2.3) : il est faux sur quatre points, donc le critère resterait
   non prouvé même si la mesure était possible.
2. **Le geste réel sur l'écran `synchro-crm`.** L'ouvrir demande une session admin sur un site dont
   la base est éteinte. La page a été lue intégralement (320 l.) et je décris ce qu'elle rend, mais
   **je ne l'ai pas vue à l'écran** — ce n'est pas la doctrine (règle 4), et je le dis plutôt que de
   laisser croire le contraire.
3. **Le bouton « Rejouer » (`replay.ts`).** Lu, non exercé : il refuse d'agir sans
   `CRM_SYNC_ENABLED`, et aucune ligne d'outbox n'existe nulle part sur ce poste. Sa garde TOCTOU et
   son `jobId` horodaté paraissent corrects, mais **aucune n'a été vue rougir**.
4. **Le comportement bout-en-bout de l'ingestion CRM** (`upsertBusiness`, `recordOpposition`,
   `attachTags`). Mes mesures côté CRM portent sur la **classification pure** (`SiteSyncEvent`,
   `SiteSyncClassifier`, `assertCandidateConsentV2`), sans base : c'est suffisant pour trancher les
   verdicts §2.1 et E31-002, insuffisant pour affirmer l'effet en base. E31-009 est donc **déduit du
   code du CRM, pas exécuté** — à confirmer par l'agent 14, dont c'est le périmètre.
5. **La production du site et du CRM.** Aucun appel, aucune lecture : ordre de mission.
6. **Le contenu réel des 71 candidatures du stock** (`careers-v1-2026-06-09`). Je reprends ce chiffre
   du commentaire de `job-application/actions.ts:311-314` et de `vivier/stock.ts:202`, **sans avoir
   pu le compter en base**. Le raisonnement de §2.1 n'en dépend pas (il porte sur la classification,
   pas sur le volume), mais le chiffre lui-même reste une hypothèse documentaire.
7. **`src/server/qualiopi/crm/**` au titre du canal.** Vérifié négativement (0 import de `crm-sync`),
   donc **hors sujet** : c'est le référentiel clients/devis interne Qualiopi, pas le canal. Je ne
   l'ai pas audité pour lui-même — ce n'est pas mon périmètre.
8. **Le sens entrant en conditions réelles.** Les 7 tests de la route et les 12 tests d'`inbound`
   sont verts et rejoués, mais aucun événement entrant n'a jamais été reçu (`crm_inbound_events`
   inaccessible, base éteinte). Le verdict E31-001 est établi **par lecture du code + absence totale
   de branche + absence totale de test**, pas par une requête HTTP jouée.
