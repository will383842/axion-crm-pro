# AGENT 34 — Non-régression de la console axionia

> **Question posée** : les travaux CRM des dernières semaines ont-ils cassé quelque chose dans la
> console du site ?
> **Réponse courte** : **oui, une fois, et la casse a duré trois jours** (E34-001). Elle est
> réparée. Rien d'autre, dans le périmètre confié, n'est cassé *par le canal CRM* : les six autres
> points de contact sont strictement additifs et tous testés. En revanche l'établissement de la
> ligne de base a fait sortir **six défauts qui n'ont rien à voir avec le CRM** mais qui portent sur
> les mêmes fonctions — dont **deux graves** : la production affirme une certification que le dépôt
> dit non délivrée (**E34-003**), et le contrôle de cloisonnement Qualiopi est **rouge sur 24
> fichiers, dont tout le module planning**, sans que personne puisse le voir (**E34-007**).
>
> **Huit constats : E34-001 (S1) · E34-002 (S2) · E34-003 (S1) · E34-004 (S2) · E34-005 (S2) ·
> E34-006 (S2) · E34-007 (S1) · E34-008 (S2).**

## 0. Références de mesure — nommées, relues, pas recopiées

| Objet | Valeur mesurée | Commande |
|---|---|---|
| Dépôt du site, copie de travail | `main = eb754332` (arbre propre) | `git log --oneline -1` + `git status --porcelain` (vide) |
| Dépôt du site, `origin/main` | `82425496` — **une longueur d'avance** sur ma copie | `git fetch origin main && git log --oneline -3 origin/main` |
| Dépôt CRM | non touché par cet agent | — |
| Image de production | `ghcr.io/will383842/axion-ia:latest`, config créée **2026-08-19T08:26:02Z** | API registre GHCR, blob de config |
| Date des mesures | 2026-08-19, 13 h 20 → 15 h 00 (Paris) | — |

⛔ Aucune écriture. Deux lectures seulement ont touché l'extérieur : le registre GHCR public et
`GET https://axion-ia.com/…` (anonyme, sans effet de bord). Le worktree `crmpro-wt-etape1a` n'a
jamais été ouvert.

⚠️ **Avertissement de méthode, à lire avant les chiffres.** Ce poste est **≈ 40 fois plus lent
par fichier de test** que le runner CI (36 s/fichier contre 0,84 s). La suite complète du site y
demanderait **≈ 8 h 30**. J'ai donc mesuré en trois temps : (1) la suite complète **via la CI**,
(2) une suite **de périmètre** jouée localement, (3) un **réexamen individuel** de chaque rouge,
avec un témoin de lenteur. C'est écrit explicitement partout où ça change le verdict.

---

## 1. LA GRILLE — une ligne par objet du périmètre

Colonnes : **CRM ?** = touché par un commit dont l'objet est le CRM · **Tests** = fichiers de test /
tests · **Aujourd'hui** = résultat mesuré ce jour · **Garde CI** = ce qui rougirait en intégration
continue · **Régression CRM ?** = verdict.

| # | Objet du périmètre | Route réelle dans la console | CRM ? | Tests | Aujourd'hui | Garde CI | Régression CRM ? |
|---|---|---|---|---|---|---|---|
| 1 | **Devis** | `/qualiopi/devis` (l'écran `/devis` est un **308** vers lui) | non | inclus dans `src/server/actions/qualiopi` (40 fichiers) | vert | Gate A · Vitest + `admin-nav:routes-check` | **non** |
| 2 | **Factures** | `/qualiopi/facturation` (l'écran `/factures` est un **308**) | non | idem | vert | Gate A · Vitest | **non** — mais **E34-004** (page gatée par une variable d'environnement) |
| 3 | **Échéanciers** | `/qualiopi/facturation/plans` (`/echeanciers` est un **308**) | non | idem | vert | Gate A · Vitest | **non** — même gate, **E34-004** |
| 4 | **Paiements** | `/qualiopi/facturation` (`/paiements` est un **308**) | non | idem | vert | Gate A · Vitest | **non** — même gate, **E34-004** |
| 5 | **Sessions de formation** | `/qualiopi/sessions`, `/qualiopi/dossiers` | non | `src/server/qualiopi` : **251** fichiers de test / 518 fichiers | vert | Gate A · Vitest | **non** |
| 6 | **Qualiopi** (portail stagiaire, conformité, pièces) | `/qualiopi/*`, `/portail/*` | non | 251 + 40 + 6 fichiers | **vert, sauf 1** (`confirmations.spec.ts`, **E34-006**) | Gate A · Vitest ; **`qualiopi:isolation-check` est ROUGE et ne tourne nulle part** (**E34-007**) | **non** — mais **E34-003** (affirmation de certification) |
| 7 | **Banque d'images** | `/image-bank/*` (10 entrées de nav) | non | **7 fichiers / 46 tests** pour ~30 fichiers de code | vert | Gate A · Vitest ; **`image-bank:isolation-check` ne tourne nulle part** (**E34-007**) | **non** |
| 8 | **Contenus du site** | `/blog`, `/connaissances`, `/faq`, `/case-studies`, `/catalogue-imprime` | non | `src/content` : 40 fichiers | vert **en CI** — mais **12 710 tests sautés** (**E34-005**) | Gate A · Vitest, avec un `describe.skipIf(CI)` | **non** |
| 9 | **Planning** (intervenants) | `/planning`, `/planning/{hub,timeline,charge,pipeline,previsionnel,ics}` | non | `src/features/admin-planning` : **13 fichiers** | vert | Gate A · Vitest — mais **6 de ses fichiers violent le cloisonnement Qualiopi**, contrôle rouge et non câblé (**E34-007**) | **non** |
| 10 | **Booking / réservations** | ⚠️ `/reservations` est **mort, 308** vers `/qualiopi/dossiers` | non | `src/features/booking` : 5 fichiers (code serveur **toujours vivant**) | vert | Gate A · Vitest | **non** — mais **E34-008** (piège de nommage) |
| 11 | **Canal CRM lui-même** (`crm-sync`, hors périmètre strict, mesuré pour la parité) | `/synchro-crm` | **oui** | 2 fichiers + `tests/e2e-crm-sync/contract.spec.ts` — **67 tests** | vert | Gate A · Vitest | — |
| 12 | **Réservation d'appel Calendly** (point de capture partagé) | `/qualiopi/entrees` | **oui** | `src/server/calendly` : 6 fichiers | vert (après réexamen avec témoin de lenteur) | Gate A · Vitest | **OUI — E34-001**, réparée le 2026-08-17 |
| 13 | **Alertes Telegram** | — | **oui** (catégorie ajoutée) | `telegram-routing.test.ts`, `routing.test.ts`, `format.test.ts` | vert | Gate A · Vitest | **non** — ajout strictement additif |
| 14 | **Barre de navigation de la console** | `src/lib/admin-nav.ts` | **oui** (1 entrée ajoutée) | `admin-nav.test.ts` (26 tests) + `admin-nav:routes-check` | vert — **153 entrées, toutes résolues** | Gate A | **non** |

---

## 2. LES FICHIERS HORS `crm-sync` TOUCHÉS PAR LES COMMITS CRM — et leur état de test

### 2.1 Les commits CRM depuis le 2026-08-13

`git log --since=2026-08-13` rend **150 commits** sur le dépôt du site
(`04_PREUVES/agent-34/01_gitlog-depuis-0813.txt`). **Sept** ont le CRM pour objet :

| SHA | Date | Objet |
|---|---|---|
| `90d429bc` | 08-14 | feat(L2) : outbox de synchronisation site → CRM, 14 points de capture, tout inerte (#598) |
| `a8e3d8aa` | 08-14 | test(crm-sync) : harnais E2E local site↔CRM + tests de contrat inter-dépôts (#601) |
| `99ba93a0` | 08-14 | feat(L4) : consentements v2, registre de preuve, vivier 30 j, RGPD bi-système (#602) |
| `f51d544b` | 08-14 | feat(L5) : observabilité synchro CRM — carte console, alertes, réconciliation, webhook (#604) |
| `d9795e8b` | 08-18 | feat(crm-sync) : la réconciliation compare les CINQ familles de capture |
| `5142bc93` | 08-18 | style(crm-sync) : Prettier sur `reconcile.ts` et son test |
| `1dadc242` | 08-18 | fix(crm-prealables) : les 3 défauts du site + réconciliation 5 familles (#735) |

`c5189347` (« Merge branch 'main' into fix/crm-prealables-ligne-13 ») est **exclu** : c'est
l'absorption de `main` dans une branche, pas un travail CRM. L'y compter aurait attribué au canal
40 fichiers qu'il n'a jamais touchés.

### 2.2 Les 72 fichiers hors `crm-sync`

Union des sept commits, moins `src/server/crm-sync/**`, `scripts/e2e-crm-sync/**`,
`tests/e2e-crm-sync/**` : **72 fichiers**
(`04_PREUVES/agent-34/03_fichiers-hors-crmsync.txt`).

Dont 17 ne sont pas du code exécutable (5 tests, 3 migrations SQL, `schema.prisma`, 1 ADR,
`.env.example`, `vitest.config.ts`, …) → **55 fichiers de code** à qualifier.

**État de test des 55** (`04_PREUVES/agent-34/04_…` et `05_…`) :

| État | Nombre | Fichiers |
|---|---|---|
| **Testé** — un spec le nomme, l'importe par alias, ou l'importe en relatif (`await import("../route")` compris) | **29** | `admin-nav.ts`, `admin-nav-icons.ts`, `env.ts`, `i18n/routing.ts`, `notifications/{routing,format,types}.ts`, `queue/{queues,worker,types,lib/sentry-worker}.ts`, `calendly/{api,discover,enrich,refresh}.ts`, `chatbot/tools/capturer-lead.ts`, `vivier/{config,opposition,stock,token}.ts`, `consents/index.ts`, `email/templates/index.tsx`, `commercial-application/model.ts`, `features/{roi-report,unified-contact,newsletter,admin-submissions}/actions.ts`, `api/calendly/client-event/route.ts`, `api/internal/crm-webhook/route.ts` |
| **Non testé** — aucun spec ne le nomme ni ne l'importe, sous aucune forme | **26** | les 5 pages/composants admin (`SubmissionsV2.tsx`, `synchro-crm/page.tsx`, `RejouerBouton.tsx`, `carrieres/[slug]/postuler/page.tsx`, `vivier-opposition/page.tsx`), les 5 routes API (`admin/submissions/export`, `calendly/webhook`, `gdpr-erase`, `gdpr-export`, `vivier-opposition`), les 6 actions serveur (`admin-calendly`, `admin-submissions/query`, `commercial-application`, `job-application`, `podcast-request`, `review-submission`), `lib/csv.ts`, `lib/careers/candidate-family.ts`, `email/templates/vivier-information.tsx`, `server/actions/crm-sync/replay.ts`, les 2 workers (`crm-sync-worker`, `vivier-crons-worker`), les 3 composants de formulaire, `scripts/calendly-webhook-subscribe.ts` |

**Les fichiers réellement PARTAGÉS avec mon périmètre** — ceux où le canal a mordu dans du code qui
sert les fonctions à conserver — sont **sept**, et je les ai qualifiés un par un :

| Fichier partagé | Ce qu'il sert dans mon périmètre | Nature de la modification CRM | Testé ? | Test joué aujourd'hui |
|---|---|---|---|---|
| `src/lib/admin-nav.ts` | **toute** la navigation : devis, factures, échéanciers, paiements, planning, qualiopi, banque d'images | **additive** : +1 entrée `/synchro-crm`, groupe `ops` | oui | ✅ 26 tests verts + `admin-nav:routes-check` **153/153 résolues** |
| `src/lib/admin-nav-icons.ts` | idem | additive : +2 icônes | oui | ✅ 4 tests verts |
| `src/server/notifications/routing.ts` | **les alertes Telegram** de tous les modules | additive : +1 catégorie `CRM_SYNC_ALERT`, +1 groupe `crm-sync` avec repli sur Système | oui | ✅ `routing.test.ts`, `telegram-routing.test.ts`, `whatsapp.test.ts` verts |
| `src/server/notifications/format.ts` / `types.ts` | idem | additive | oui | ✅ verts |
| `src/server/queue/queues.ts` | la file de **tous** les crons Qualiopi, image-bank, contenus | additive : +1 file `crm-sync` | oui (14 specs l'importent) | ✅ verts |
| `prisma/schema.prisma` | **tout** | additive : 3 migrations, aucune colonne retirée | via `prisma migrate deploy` (Gate D) | ✅ Gate D vert en CI |
| `src/app/api/calendly/client-event/route.ts` | la **réservation d'appel** — le point de capture de `/qualiopi/entrees` | **intrusive** : appel `syncCalendlyEventToCrm` ajouté au corps de la route | oui | 🔴 **c'est là que ça a cassé — E34-001** |

**Verdict de cette section** : sur les sept points de contact, **six sont strictement additifs et
tous testés**. Le septième est le seul qui a modifié le *corps* d'une fonction existante — et c'est
exactement celui qui a cassé.

---

## 3. LE RÉSULTAT CHIFFRÉ DES SUITES DU SITE

### 3.1 Quelles suites existent

| Suite | Fichiers | Où elle tourne | Peut-elle rougir ? |
|---|---|---|---|
| **Vitest** (`pnpm test`) | **814** fichiers dans le périmètre `include` | Gate A (per-commit) + hook `pre-push` | **OUI** — c'est le seul filet bloquant |
| **Vitest intégration** (`test:integration`, config séparée) | `tests/integration/**` | **nulle part** | non |
| **Playwright** (`pnpm test:e2e`) | `tests/e2e/**` | Gate B, **`continue-on-error: true`** | **NON** |
| **Gate C · Docker smoke** | — | CI, **`continue-on-error: true`** | **NON** |
| **Lighthouse CI** | 5 URLs | Gate B (`continue-on-error`) + post-deploy | **NON** en PR |
| `admin-nav:routes-check` | 153 entrées | Gate A | **OUI** |
| `content-gen:isolation-check` | — | Gate A | **OUI** |
| **`qualiopi:isolation-check`** | 18 zones autorisées | **NULLE PART** — et il est **ROUGE** (E34-007) | **NON** |
| **`image-bank:isolation-check`** | — | **NULLE PART** (E34-007) — verdict non obtenu | **NON** |

### 3.2 La suite complète — chiffres CI, sur `82425496`

Run `32229490581`, job **Gate A · per-commit**, `success`, 2026-08-19 07:51 → 08:03 :

```
Test Files  816 passed | 1 skipped (817)
     Tests  10725 passed | 12710 skipped (23435)
  Duration  685.34s
```

**Lire ce vert avec précaution : 54 % des tests déclarés ne s'exécutent pas.** Voir **E34-005**.

### 3.3 La suite de périmètre — chiffres locaux, sur `eb754332`

Filtres `qualiopi notifications crm-sync calendly consents vivier admin-nav image-bank
admin-planning` (`04_PREUVES/agent-34/12_vitest-perimetre.log`) :

```
Test Files  3 failed | 353 passed (356)
     Tests  4 failed | 6017 passed (6021)
  Duration  1290.06s
```

Rejeu des trois fichiers rouges **dans la configuration par défaut** (sérialisée), pour écarter mon
propre réglage `--fileParallelism=true` : **5 rouges**, dans les **mêmes** trois fichiers
(`17_…log`). Le réglage n'était donc pas en cause.

Rejeu des deux fichiers dont les rouges portaient un **temps de 5 000 ms exactement** (la valeur du
`testTimeout`), avec `--testTimeout=120000` (`20_…log`) :

```
Test Files  2 passed (2)
     Tests  24 passed (24)
```

→ **4 des 5 rouges étaient de la lenteur de poste, pas des défauts.** C'est le témoin qui le prouve,
et c'est A-009 transposé au dépôt du site.

**Résultat net du périmètre : 356 fichiers, 6 021 tests, 6 020 verts, 1 rouge.** Le rouge résiduel
est `confirmations.spec.ts` (27 ms, assertion déterministe) : **E34-006**.

---

## 4. LE VERDICT SUR LE CORRECTIF QUALIOPI (`eb754332`)

### 4.1 Le correctif tient

`src/server/qualiopi/portail/portail-service.ts:438-445` — le `where` distingue désormais quatre
types **collectifs** (servis sans contrainte) de deux types **nominatifs** (`convocation`,
`autorisation_captation`) soumis à `traineeId` en égalité stricte :

```ts
documents: {
  where: {
    OR: [
      { type: { in: [...TYPES_PIECES_COLLECTIVES_ESPACE_STAGIAIRE] } },
      { type: { in: [...TYPES_PIECES_NOMINATIVES_ESPACE_STAGIAIRE] }, traineeId },
    ],
  },
```

**Trois vérifications**, toutes passées :

1. **Le filtre est bien dans la REQUÊTE, pas dans un tri postérieur.** Un filtrage en aval aurait
   laissé la pièce du tiers traverser la déduplication `retenirPiecesParSessionEtType`, qui ne garde
   qu'une pièce par (session, type).
2. **La garde mesure le bon objet** (contre-crible A-011). `portail-service.spec.ts` **rejoue le
   `where`** que le service passe à Prisma contre quatre lignes de fixture — dont une convocation
   d'un tiers et une convocation héritée sans `traineeId`. Un fixture pré-filtré à la main aurait
   rendu vert un service qui ne filtre pas ; ce n'est pas ce qui est écrit.
3. **Le point d'entrée est unique.** Les quatre écrans de l'espace stagiaire
   (`mon-espace`, `/documents`, `/formations`, `/mon-compte`) passent tous par
   `chargerEspaceStagiaire()` (`_chargement.ts`), qui est le seul appelant de `getEspaceStagiaire`.
   Il n'existe donc pas de second chemin non corrigé.

**Verdict : le correctif tient.**

### 4.2 Les autres surfaces du même type — j'en ai cherché, je n'en ai pas trouvé d'ouverte

J'ai passé au crible **toutes** les surfaces qui servent des données à un porteur de jeton
stagiaire ou formateur :

| Surface | Ce qu'elle lit | Cloisonnement | Verdict |
|---|---|---|---|
| `/portail/mon-espace/*` | `getEspaceStagiaire` | `traineeId` sur les types nominatifs | ✅ corrigé |
| `/portail/emarger/[token]` | `lireFeuilleStagiaire` | part de l'**inscription** du porteur ; politique de champs stricte écrite en tête de fichier | ✅ |
| `/portail/signer/[token]` | `verifierTokenDocument` | jeton HMAC lié à **une** pièce ; identité du signataire **figée** dans la ligne de jeton | ✅ |
| `/portail/enquete/[token]` | `questionnaire.findUnique({ token })` | une seule ligne ; type contrôlé (« un jeton stagiaire ne doit jamais ouvrir le formulaire entreprise ») | ✅ |
| Export RGPD stagiaire (`rgpd-service.ts`) | `trainee.findUnique` + `include` | part du stagiaire, jamais de la session | ✅ |
| `POST` satisfaction portail | `questionnaire.findUnique({ token })` | contrôle IDOR explicite (`enrollment.traineeId` == porteur) | ✅ |
| `GET /api/espace-formateur/kit/[sessionId]` | `trainingSession.findFirst` | `whereSessionsDuFormateur(trainerId)` — « une seule règle d'appartenance dans tout le code » ; 404 indifférencié | ✅ |
| `GET /api/qualiopi/documents/[id]` | `documentGenere.findUnique` | **admin/super_admin uniquement** | ✅ |

**Aucune autre fuite de pièce nominative n'est ouverte à ce jour.**

⚠️ **Ce qui manque quand même** : le cloisonnement repose sur **un test par fichier**. Il n'existe
**aucune garde transverse** qui interdirait, demain, une nouvelle lecture partant de la session et
remontant une pièce nominative. Le dépôt sait pourtant faire ce genre de registre — il vient d'en
écrire un dans le même commit (`assertion-flag-surfaces.spec.ts`, qui balaie les sources et exige
que tout porteur d'une formulation assertive figure au registre ou dans une liste d'exemptions
motivée). C'est le correctif que je recommande, et il est chiffré en E34-002.

### 4.3 Ce que `eb754332` a laissé ouvert, et que la production porte aujourd'hui

Le même commit dit, noir sur blanc : *« Le geste prévu — éteindre `QUALIOPI_CERTIFICATION_OBTENUE` »*.
**Ce geste n'a pas eu lieu.** Voir **E34-003** : mesure sur l'image de production et sur la page
d'accueil live.

---

## 5. LA LIGNE DE BASE POUR LE CRITÈRE 25 (§29)

> « à chaque palier du §25.1 : formulaires, réservations et messages **continuent d'arriver**
> (parité maintenue), les **alertes Telegram continuent de partir**, et le drapeau remis à `false`
> rend les anciens écrans **en moins d'une minute** »

L'agent 32 a mesuré que le drapeau n'existe pas (E32-004) : **aucun palier n'a été franchi**. Ma
mission est donc préventive. Voici le zéro contre lequel les paliers se mesureront. **Chaque ligne
est un nombre, relevé aujourd'hui, sur `eb754332`.**

### 5.1 Parité de capture — « les formulaires, réservations et messages continuent d'arriver »

**15 appels d'émission** répartis dans **14 fichiers** de production, plus 2 fichiers de sortie RGPD
(`04_PREUVES/agent-34/06_points-de-capture-crm.txt` — sortie de `rg "sync[A-Za-z]*ToCrm\("`) :

| Fichier | Fonction appelée |
|---|---|
| `src/app/api/calendly/client-event/route.ts` | `syncCalendlyEventToCrm` |
| `src/features/admin-calendly/actions.ts` | `syncCalendlyEventToCrm` |
| `src/server/calendly/discover.ts` | `syncCalendlyEventToCrm` |
| `src/server/calendly/enrich.ts` | `syncCalendlyEventToCrm` |
| `src/features/commercial-application/actions.ts` | `syncCandidateToCrm` |
| `src/features/job-application/actions.ts` | `syncCandidateToCrm` |
| `src/server/vivier/stock.ts` | `syncCandidateToCrm` |
| `src/features/newsletter/actions.ts` | `syncNewsletterOptInToCrm` **et** `syncNewsletterOptOutToCrm` |
| `src/features/podcast-request/actions.ts` | `syncFormSubmissionToCrm` |
| `src/features/roi-report/actions.ts` | `syncFormSubmissionToCrm` |
| `src/features/unified-contact/actions.ts` | `syncFormSubmissionToCrm` |
| `src/server/chatbot/tools/capturer-lead.ts` | `syncFormSubmissionToCrm` |
| `src/features/review-submission/actions.ts` | `syncReviewToCrm` |
| `src/server/vivier/opposition.ts` | `syncVivierOppositionToCrm` |
| + `src/app/api/gdpr-{erase,export}/route.ts` | `crm-sync/gdpr` |

**Le compte à retenir : 15 émissions, 14 fichiers.** Le commit `90d429bc` annonce « 14 points de
capture » — ce qui est le compte des **fichiers**, pas celui des **appels** : `newsletter/actions.ts`
en porte deux (opt-in et opt-out). **Une mesure de parité à un palier doit vérifier 15 émissions, pas
14 fichiers** : c'est l'opt-out de newsletter qui disparaîtrait sans que le compte bouge.
Et **21 fichiers** au total importent `@/server/crm-sync`
(`07_importateurs-crm-sync.txt`) : les 14 émetteurs, les 2 sorties RGPD, le worker, le rejeu, la
carte de console, le webhook entrant et `careers/candidate-family.ts`.

**Ce qui rend la parité observable aujourd'hui** :
- `src/server/crm-sync/reconcile.ts` compare **cinq familles de capture** (deux avant `d9795e8b`)
  aux `subject_ref` émis, sur 7 jours, et **consigne** les manquants sans les ré-émettre.
- La borne basse est un marqueur persistant `site_settings.crm_sync_activated_at`, pas la première
  ligne d'outbox — sinon le filet serait aveugle à l'instant de l'allumage.
- **Cinq familles ≠ quinze émissions.** Un palier qui retirerait un écran devra vérifier que la
  famille correspondante est bien l'une des cinq, sinon la réconciliation ne verra rien.

### 5.2 Les alertes Telegram — « elles continuent de partir »

Il y a **deux** chemins Telegram, et une vérification de palier qui n'en regarde qu'un serait fausse :

1. **Le chemin routé**, `notify()` → `routing.ts` : **40 catégories**, `Record` exhaustif
   typé-vérifié, dont **`CRM_SYNC_ALERT`**, ajoutée par le canal (`04_PREUVES/agent-34/14_…txt`).
   Repli documenté : sans `TELEGRAM_CHAT_ID_CRM_SYNC`, on retombe sur 🔔 Système.
2. **Le chemin direct**, `sendTelegram()` importé sans passer par le routage :
   **31 fichiers** (`04_PREUVES/agent-34/15_…txt`), dont, **dans mon périmètre** :
   `server/actions/qualiopi/devis.ts`, `piece-signature.ts`, `portail.ts`,
   `queue/workers/qualiopi-formation-crons-worker.ts`, `queue/workers/option-expiration-worker.ts`,
   `queue/workers/booking-crons-worker.ts`, `features/booking/*` (6 fichiers),
   `features/admin-options/actions.ts`, `api/stripe/webhook/route.ts`, `api/docuseal/webhook/route.ts`.

**Le compte à retenir : 40 catégories routées + 31 fichiers en appel direct.**
Les 7 alertes qui pointent dans `contacts/*` et leurs 8 redirections de compatibilité (E32) sont à
**conserver** : rien dans ce que j'ai mesuré ne les rend superflues.

### 5.3 Le drapeau et la minute — **le point dur, et il est mesurable**

**Il n'existe aucun drapeau de bascule console↔CRM côté site.** Je confirme E32-004 par une mesure
indépendante : `CRM_SYNC_ENABLED` et `CRM_SYNC_CANDIDATES_ENABLED` gouvernent **l'émission**, pas
l'affichage ; aucune page de la console ne les lit.

Les drapeaux qui gouvernent réellement l'affichage dans mon périmètre sont **trois**, et **tous
trois sont des `process.env` bruts** :

| Drapeau | Ce qu'il éteint | Lu où | Coût du retour arrière |
|---|---|---|---|
| `FACTURATION_HUB_ENABLED` | **factures, échéanciers, rapprochement, comptabilité, facture directe** — 5 écrans | `qualiopi/config/flag.ts:71`, appelé en tête de chaque page | variable Coolify + **redémarrage de conteneur** |
| `OF_PUBLIC_DISCLOSURE_ENABLED` | les pages publiques de l'organisme | idem | **redémarrage + rebuild** — voir ci-dessous |
| `QUALIOPI_CERTIFICATION_OBTENUE` | l'affirmation de la certification | idem | **redémarrage + rebuild** |

🔴 **Et pour deux d'entre eux, la minute est hors d'atteinte par construction.** Le `Dockerfile`
l'écrit lui-même, lignes 123-134 :

> « Ces deux drapeaux sont lus par des pages en SSG (`revalidate = 3600`), donc **ÉVALUÉS AU
> BUILD**. […] le jour où le certificat arrive, le passer à "true" dans Coolify **ne suffira PAS**.
> Il faut un nouveau build, c'est-à-dire un `git push` ».

Un rebuild de ce dépôt prend **≈ 25 min** (job `build` de `deploy-coolify.yml`). Le critère 25
demande **60 s**. **Écart mesuré : ×25.**

✅ **Mais le dépôt possède déjà le mécanisme qui tiendrait la minute, et personne ne l'a proposé.**
`src/server/qualiopi/config/site-settings.ts` lit un drapeau **en base** (`SiteSetting`, préfixe
`qualiopi.`) à **chaque appel**, sans cache, avec repli sur le défaut du registre en cas d'erreur —
et les pages de la console sont toutes `export const dynamic = "force-dynamic"`. Un drapeau posé là,
et non dans l'environnement, **prend effet à la navigation suivante**, sans redémarrage, sans build.
C'est le seul chemin mesuré qui satisfasse le critère 25. **Recommandation de ligne de base : tout
drapeau de bascule console↔CRM doit être un `SiteSetting`, jamais une variable Coolify.**

### 5.4 Ce que la ligne de base fige, pour qu'un futur retrait ne vise pas à côté

- **153 entrées** de navigation, **toutes résolues** vers un `page.tsx` réel
  (`pnpm admin-nav:routes-check`, sortie archivée : `21_gates-statiques.txt`). ⚠️ Cette garde vérifie
  **l'existence du fichier**, **pas que la page rende** — voir E34-004. ⚠️ Ce 153 n'est pas le
  « 154 destinations » du dossier commun : je compte les **entrées émises par `buildAdminNav()`**,
  pas les destinations atteignables. Les deux mesures sont justes et ne comptent pas la même chose.
- **29 fichiers** de la console appellent `permanentRedirect` (308). **Quatorze** appartiennent à la
  famille « ancien module Booking », et **sept d'entre eux portent les noms exacts de mon
  périmètre** : `/devis`, `/devis/new`, `/devis/[id]`, `/factures`, `/factures/[id]`,
  `/echeanciers`, `/paiements` — auxquels s'ajoutent `/options`, `/options/[id]`, `/reservations`,
  `/reservations/[id]` et les trois `/calendrier/*`. Les **quinze** autres sont hors famille
  (onze `content-gen/*`, deux `qualiopi/*`, deux `submissions/*`).
  **Aucun de ces quatorze n'est l'écran que le CDC demande de conserver** — voir E34-008.
- **0** entrée de navigation ne pointe vers le CRM (conforme à E32).
- **Suite Vitest de référence** : 817 fichiers en CI (816 verts, 1 sauté), **10 725 tests exécutés**.
  C'est ce nombre-là, et non 23 435, qui doit rester stable d'un palier à l'autre.

---

## 6. LES CONSTATS

### [E34-001] Le canal CRM a rendu la suite du site rouge pendant trois jours, et le hook de pré-envoi infranchissable pour tout le monde
- Sévérité      : S1 grave
- Domaine       : tests / canal
- Référence     : dépôt du site, `main eb754332` (défaut introduit en `90d429bc`, réparé en `8b5fbf81` / `0baf0783`)
- Emplacement   : `src/app/api/calendly/client-event/route.ts` + `src/app/api/calendly/client-event/__tests__/route.test.ts:18-30`
- Constat       : la PR #598 (lot L2 du chantier CRM) a ajouté l'appel `syncCalendlyEventToCrm` dans le corps de la route sans ajouter le `vi.mock("@/server/crm-sync")` correspondant dans le test qui l'exerçait déjà ; la synchro partait donc pour de vrai pendant les tests.
- Preuve        : `git log -S "syncCalendlyMock" -- …/route.test.ts` → **`0baf0783`, 2026-08-17** ; et `git log --all -S "lot L2" -- '*.test.ts'` → `8b5fbf81` « test(calendly): mock manquant depuis la PR #598, le pre-push etait rouge ». Le commentaire posé en tête du test énonce les trois symptômes mesurés à l'époque : « les deux cas ci-dessous expiraient a 5 s », « "rate limit depasse" rendait 200 au lieu de 429 », « le hook de PRE-PUSH de ce depot refusait TOUT push, pour tout le monde ». Sortie : `04_PREUVES/agent-34/01_gitlog-depuis-0813.txt`.
- Témoin négatif: le contrôle est capable de trouver — la même recherche `git log -S` sur les six autres fichiers partagés ne rend **aucun** commit correctif, et les 353 autres fichiers de test du périmètre sont verts aujourd'hui. Le rouge trouvé n'est donc pas un artefact de la méthode.
- Impact        : **fenêtre 2026-08-14 → 2026-08-17, trois jours**. Le hook `pre-push` de ce dépôt lance `pnpm test` complet : plus personne ne pouvait pousser, sur aucune branche. Et un rouge qui dure trois jours pour une raison qu'on croit fausse entraîne à contourner par `--no-verify`, donc à désarmer la garde le jour où elle sert.
- Reproduction  : `git show 90d429bc -- src/app/api/calendly/client-event/route.ts` (l'appel entre) ; `git show 0baf0783 -- src/app/api/calendly/client-event/__tests__/route.test.ts` (le mock entre, trois jours plus tard).
- Correctif     : réparé. **Ce qui reste à faire est la garde** : voir E34-002.
- Statut        : défaut fermé ; **garde absente — voir E34-002**

### [E34-002] Dix-huit des vingt et un fichiers qui appellent le canal CRM n'ont aucun test qui connaisse le canal, et rien ne relie les deux
- Sévérité      : S2 défaut
- Domaine       : tests / canal
- Référence     : dépôt du site, `main eb754332`
- Emplacement   : `src/server/crm-sync/index.ts` (les appelants) ; garde absente
- Constat       : **21 fichiers de production** importent `@/server/crm-sync` ; **3 seulement** ont un fichier de test qui mentionne `crm-sync` (`api/calendly/client-event`, `api/internal/crm-webhook`, `server/calendly/enrich`).
- Preuve        : `rg -l "from \"@/server/crm-sync\"" -g '!*.spec.*' -g '!*.test.*' src` → 21 fichiers. `rg -l "crm-sync" -g '*.spec.*' -g '*.test.*' .` → 7 fichiers, dont 4 sont les tests du canal lui-même. Sorties dans `04_PREUVES/agent-34/`.
- Témoin négatif: le contrôle n'est pas aveugle — il **a** trouvé les trois qui mockent, et il **a** trouvé que `capturer-lead.test.ts`, `discover.test.ts` et `vivier.test.ts` exercent du code qui appelle le canal **sans** le mocker (ils survivent aujourd'hui parce que `isCrmSyncEnabled()` est faux en test et court-circuite l'appel — c'est-à-dire par accident, pas par construction).
- Impact        : E34-001 se reproduira à l'identique dès qu'un point de capture ajoutera un appel réseau non court-circuité par le drapeau, ou dès que le drapeau passera à `true` dans un environnement de test. Et à ce moment-là, c'est **le seul filet bloquant du dépôt** qui tombe.
- Reproduction  : ajouter un `await fetch()` inconditionnel dans `src/server/crm-sync/emit.ts`, jouer `pnpm test` : les trois fichiers ci-dessus expirent.
- Correctif     : un spec-registre sur le modèle de `assertion-flag-surfaces.spec.ts` (déjà écrit dans ce dépôt le 2026-08-19) : balayer les sources, lister les fichiers qui importent `@/server/crm-sync`, exiger que chacun figure au registre **et** que son spec, s'il en a un, porte le `vi.mock`. Avec témoin de non-vacuité. **Coût : 2-3 h.**
- Statut        : ouvert

### [E34-003] La production affirme la certification Qualiopi le jour même où un commit du dépôt écrit qu'elle n'est pas délivrée
- Sévérité      : S1 grave (conformité)
- Domaine       : conformité / interface
- Référence     : dépôt du site, `main eb754332` ; image `ghcr.io/will383842/axion-ia:latest` (config créée **2026-08-19T08:26:02Z**) ; `https://axion-ia.com/fr` interrogé le 2026-08-19
- Emplacement   : `Dockerfile:254-255` ; variable de dépôt GitHub `QUALIOPI_CERTIFICATION_OBTENUE` ; `src/server/qualiopi/config/flag.ts:62`
- Constat       : le commit `eb754332` du 2026-08-19 dit « le geste prévu — **éteindre** `QUALIOPI_CERTIFICATION_OBTENUE` » et qualifie l'affichage anticipé d'illégal en citant l'en-tête de `flag.ts` ; le drapeau vaut pourtant `true` au build **et** au runtime, et la page d'accueil rend l'affirmation.
- Preuve        : trois mesures indépendantes, toutes archivées.
  ① `gh variable list` → `QUALIOPI_CERTIFICATION_OBTENUE  true  2026-08-10T18:32:02Z` (donc le build-arg de `deploy-coolify.yml:280` vaut `true`, et non le `'false'` de repli).
  ② Blob de configuration de l'image de production, lu au registre GHCR :
  `OF_PUBLIC_DISCLOSURE_ENABLED=true` / `QUALIOPI_CERTIFICATION_OBTENUE=true`
  (`04_PREUVES/agent-34/13_ghcr-image-env.txt`).
  ③ `curl https://axion-ia.com/fr` → 200, et la page porte « **Qualiopi — certification qualité** »,
  le logo officiel `qualiopi/axion-ia-qualiopi.png`, et la phrase commerciale « Qualiopi. Audits,
  coaching 1-to-1, automatisation ».
- Témoin négatif: le contrôle sait distinguer — la même lecture du blob montre que **`FACTURATION_HUB_ENABLED` est absent** de l'image. Un `grep` qui rendrait tout vrai aurait aussi rendu celui-là.
- Impact        : le site affirme publiquement une certification, dans la formulation réservée aux organismes certifiés, avec le logo et un nœud JSON-LD lu par les moteurs. Le dépôt qualifie lui-même cela d'illégal. Le correctif `eb754332` a fermé **quatre surfaces inconditionnelles** ; il n'a pas touché **le drapeau qui gouverne toutes les autres**, et le commit dit explicitement que l'extinction était le geste attendu.
- Reproduction  : `gh variable list` ; puis lecture du blob de config de `:latest` au registre ; puis `curl -s https://axion-ia.com/fr | grep -io "qualiopi[^<\"]\{0,40\}"`.
- Correctif     : ① poser `QUALIOPI_CERTIFICATION_OBTENUE=false` côté Coolify (effet en ~30 s d'après le `Dockerfile:243`, sur les seules surfaces dynamiques) **et** ② retirer la variable de dépôt GitHub + repousser, pour que les pages SSG cessent de la porter. **Coût : 5 min + un déploiement (~25 min).** ⚠️ **Décision du dirigeant requise** : si le certificat *est* détenu depuis le 2026-08-10, c'est le commit `eb754332` qui se trompe, et il faut corriger son message plutôt que la production. Cette question ne se tranche pas dans le code — je l'ai laissée ouverte.
- Statut        : ouvert — **arbitrage dirigeant**

### [E34-004] Cinq entrées de navigation du périmètre finances mènent à un 404 quand une variable d'environnement est absente, et la garde de navigation ne peut pas le voir
- Sévérité      : S2 défaut (confusion de navigation — plancher imposé par le dossier commun)
- Domaine       : navigation / interface
- Référence     : dépôt du site, `main eb754332`
- Emplacement   : `src/lib/admin-nav.ts:1033-1060` (les 5 entrées) ; `src/server/qualiopi/config/flag.ts:71` ; `scripts/check-admin-nav-routes.ts:1-14`
- Constat       : les entrées « Facturation (Hub) », « Facture directe », « Plans récurrents », « Rapprochement bancaire » et l'écran comptabilité sont émises **sans condition** dans la barre de navigation, tandis que leurs pages appellent `if (!isFacturationHubEnabled()) notFound();` **avant même** `auth()`.
- Preuve        : `grep -n "isFacturationHubEnabled" -A3` sur les pages → `facturation/page.tsx:108` et `facturation/plans/page.tsx:34`, tous deux `notFound()` en première instruction. `src/lib/admin-nav.ts:1033` porte le commentaire « page gatée par FACTURATION_HUB_ENABLED » et n'en tire aucune conséquence sur l'entrée. Blob de config de l'image de production : **`FACTURATION_HUB_ENABLED` absent** (`13_ghcr-image-env.txt`).
- Témoin négatif: **le contrôle a échoué, et je le dis.** J'ai voulu trancher depuis l'extérieur en comparant `/qualiopi/facturation` (gaté) à `/qualiopi/devis` (non gaté) sur la production : **les deux rendent 404** (`16_prod-gate-facturation.txt`), parce que `[adminPrefix]/layout.tsx:182` appelle `notFound()` sur tout préfixe inconnu, avant que la page ne s'exécute. L'oracle ne discrimine pas. **Je ne conclus donc pas sur l'état du drapeau côté Coolify — il est NON VÉRIFIÉ.** Ce qui est mesuré, c'est que l'image ne le porte pas, et que la garde ne peut pas le voir.
- Impact        : si le drapeau n'est pas posé côté Coolify, un administrateur qui clique sur « Factures » ou « Plans récurrents » — c'est-à-dire **les échéanciers**, en plein périmètre du CDC — tombe sur un 404 sans explication. Et `admin-nav:routes-check`, qui rend « ✅ 153 entrées, toutes résolues », **ne mesure que l'existence du fichier `page.tsx` sur le disque** : c'est A-011 appliqué à ma grille — une garde irréprochable qui mesure le mauvais objet.
- Reproduction  : `pnpm admin-nav:routes-check` → vert. Puis lire `facturation/page.tsx:108`. Puis lire le blob de config de l'image.
- Correctif     : ① faire porter le drapeau à **l'entrée de nav** autant qu'à la page (une entrée qu'on ne peut pas ouvrir ne doit pas s'afficher) ; ② étendre `check-admin-nav-routes.ts` pour détecter un `notFound()` inconditionnel-sur-drapeau en tête de page et exiger que l'entrée correspondante soit gatée par le même drapeau. **Coût : 3-4 h.** ③ Et vérifier la valeur côté Coolify — geste du dirigeant, 2 min.
- Statut        : ouvert

### [E34-005] La suite du site est verte, mais 54 % de ses tests ne s'exécutent pas en intégration continue
- Sévérité      : S2 défaut
- Domaine       : tests
- Référence     : CI run `32229490581`, Gate A, `success`, sur `82425496`
- Emplacement   : `src/content/villes/copy/__tests__/quality.test.ts:26`
- Constat       : `Tests 10725 passed | 12710 skipped (23435)`. Un unique `describe.skipIf(process.env.CI === "true")` neutralise le contrôle qualité anti-doorway des **2 157+ pages ville**, soit ~12 700 tests.
- Preuve        : `gh run view 32229490581 --log` → `04_PREUVES/agent-34/11_ci-gateA-8242549-vitest.txt`. Le motif est écrit dans le fichier : « Sprint T4 (2026-05-27 → en cours) : 422/1702 villes sous les seuils. Skip en CI pour ne pas bloquer le deploy ». Le sprint T4 est **toujours en cours** deux mois et demi plus tard.
- Témoin négatif: le contrôle sait compter du non-sauté — `rg "(it|test|describe)\.skip"` ne rend que **27** occurrences statiques dans tout le dépôt : les 12 710 ne peuvent venir que d'un `describe.each` gaté, et c'est bien celui-là. Le compte est donc attribué, pas supposé.
- Impact        : ce n'est pas un défaut du CRM et ce n'est pas un mensonge — le skip est motivé et daté. Mais **c'est le piège 7 du dossier commun**, et il concerne directement « contenus du site », dans mon périmètre : le vert de Gate A ne dit rien de la qualité des 2 157 pages ville. Toute mesure de non-régression à un palier futur qui citerait « 23 435 tests verts » serait fausse d'un facteur deux.
- Reproduction  : `gh run view 32229490581 --log | grep "Tests "`. Localement (sans `CI=true`), les 12 710 s'exécutent — c'est une des raisons pour lesquelles la suite complète demande ≈ 8 h 30 sur ce poste.
- Correctif     : aucun code à changer. **Ce qui manque est un chiffre affiché** : faire écrire à Gate A, en résumé de job, le nombre de tests **exécutés** et le nombre **sautés**, pour que le vert cesse de se lire comme « 23 435 ». **Coût : 1 h.**
- Statut        : ouvert

### [E34-006] Le verrou LF du 2026-08-18 n'a pas été appliqué à la copie de travail : 4 877 fichiers portent encore des CR, et le test que ce verrou devait sauver rougit toujours
- Sévérité      : S2 défaut
- Domaine       : tests
- Référence     : dépôt du site, `main eb754332`, copie de travail de ce poste, 2026-08-19
- Emplacement   : `src/components/admin/ui/useConfirmation.tsx` ; `src/features/admin-qualiopi/confirmations.spec.ts:115` ; `.gitattributes` (activé par `8ff99f2f`, #728)
- Constat       : `confirmations.spec.ts` cherche la chaîne littérale `"setDemande(null);\n          await geste"` dans le source lu **brut sur le disque** ; le fichier visé porte **114 octets CR**, `indexOf` rend `-1`, le test rougit.
- Preuve        : `pnpm vitest run src/features/admin-qualiopi/confirmations.spec.ts` (configuration par défaut, sérialisée) → `AssertionError: expected -1 to be greater than -1`, **27 ms**, déterministe (`04_PREUVES/agent-34/17_….log`). Comptage d'octets par la méthode A-003 : `od -An -tx1 f | tr ' ' '\n' | grep -c '^0d'` → **114 CR / 114 LF**.
- Témoin négatif: **le contrôle discrimine, et c'est ce qui donne le diagnostic.** Trois fichiers réécrits depuis l'activation du verrou — `portail-service.ts` (0 CR / 609 LF), `portail-service.spec.ts` (0 / 616), `admin-nav.ts` (0 / 1544) — sont **purs LF**. Un compteur qui rendrait « CRLF » partout aurait rendu ceux-là aussi. `04_PREUVES/agent-34/18_crlf-temoins.txt`.
- Impact        : `.gitattributes` ne renormalise **qu'à la sortie de dépôt**. Les fichiers non retouchés depuis le 2026-08-18 gardent leurs CR : **4 877 fichiers suivis sur 6 165** sous `src/ prisma/ tests/ scripts/`, dont **605 fichiers de test** (`19_fichiers-CRLF.txt`). Conséquence concrète et mesurée : le hook `pre-push`, qui lance `pnpm test` complet, **reste infranchissable depuis ce poste Windows** — exactement le défaut que #728 annonçait avoir clos, et que son propre en-tête décrit mot pour mot. ⚠️ **Précision d'honnêteté** : sur ce poste précis, le `pre-push` échouerait de toute façon pour une **seconde** raison indépendante — les quatre expirations à 5 000 ms du §3.3, qui tiennent à la lenteur de la machine et non au code. Les deux causes se cumulent ; seule la première est un défaut du dépôt, et seule la première suivrait le dépôt sur une autre machine Windows.
- Reproduction  : `pnpm vitest run src/features/admin-qualiopi/confirmations.spec.ts` depuis cette copie de travail.
- Correctif     : `git add --renormalize .` puis un commit de normalisation — c'est le geste que `.gitattributes` seul ne fait pas. Vérifier ensuite par le comptage d'octets, pas par `git status` (qui est vert **malgré** les CR, la normalisation à la lecture masquant l'écart). **Coût : 15 min + une relecture de diff volumineux.** Correctif complémentaire, plus solide : rendre le test insensible à la fin de ligne (normaliser le source lu avant `indexOf`) — **20 min**, et il protège les 604 autres fichiers de test du même piège.
- Statut        : ouvert

### [E34-007] Les deux contrôles d'isolation de mon périmètre sont ROUGES — 47 + 24 violations — et aucun des deux ne tourne nulle part, l'un affirmant dans son en-tête être câblé au pré-envoi
- Sévérité      : S1 grave
- Domaine       : tests / conformité
- Référence     : dépôt du site, `main eb754332`
- Emplacement   : `scripts/qualiopi/isolation-check.ts:24` ; `scripts/image-bank/isolation-check.ts` ; `.github/workflows/ci.yml:122` ; `.husky/pre-push`
- Constat       : `pnpm qualiopi:isolation-check` sort en **1** avec **47 violations** ; `pnpm image-bank:isolation-check` sort en **1** avec **24 violations**. Aucun des deux n'est lancé **par un workflow ni par un hook** : **personne ne les a jamais vus rouges.**
- Preuve        : ① `pnpm qualiopi:isolation-check` → `❌ [qualiopi:isolation-check] 47 violation(s)` puis `ELIFECYCLE Command failed with exit code 1` (liste intégrale : `04_PREUVES/agent-34/24_qualiopi-isolation-complet.txt`). ② `pnpm image-bank:isolation-check` → 24 violations, exit 1 (`22_isolation-checks.txt`). ③ `grep -n "isolation-check" .github/workflows/{ci,nightly,staging}.yml` → **une seule ligne**, `ci.yml:122`, `content-gen`. ④ `cat .husky/pre-push` → `typecheck`, `i18n:check`, `zod:check`, `test`, `pnpm audit` — et rien d'autre. `git config core.hooksPath` → `.husky/_` (les hooks existent bien ici, contrairement au dépôt CRM).
  **Les 47 violations Qualiopi qui touchent mon périmètre** — et le module **planning en porte onze à lui seul** :
  · **planning, les écrans** : `(admin)/[adminPrefix]/planning/{page,timeline/page,previsionnel/page,[type]/[id]/page}.tsx` + `planning/ics/route.ts` (5)
  · **planning, les requêtes** : `features/admin-planning/{queries,hub-queries,hub-queries.spec,charge-queries,detail,pipeline}.ts` (6)
  · **factures** : `features/invoice/admin-actions.ts` · **devis** : `features/contract/admin-actions.ts`
  · **sessions** : `features/admin-qualiopi/session-hub/ChecklistSession.tsx`
  · **console** : `server/admin/{dossiers-pipeline,pilotage-dashboard,qualiopi-nav-counts}.ts`, `server/actions/admin-recherche.ts`
  · **contenus / surfaces publiques** : `app/[locale]/{page,a-propos,avis,certification-qualiopi,financement-opco-france-travail,memo-isere,secteurs/…}/page.tsx`, `app/sitemap.ts`, `app/sitemap-images-services.xml/route.ts`, `components/nav/Footer.tsx`, `server/content-gen/generators/blog-article.ts`
  · **portail** : `components/portail/{DemanderAccesForm,EnqueteEntrepriseForm}.tsx` · **formateur** : `server/formateur/{echeances,etapes}-formateur.ts`, `app/[locale]/espace-formateur/page.tsx`
- Témoin négatif: **deux témoins, et ils ne disent pas la même chose — c'est important.** (a) Le contrôle de câblage n'est pas aveugle : il a bien rendu `content-gen:isolation-check` et `admin-nav:routes-check` en Gate A. (b) Le contrôle Qualiopi lui-même **discrimine** : il épargne les 18 zones autorisées, dont les **251 fichiers** de `src/server/qualiopi/**` et les **40** de `src/server/actions/qualiopi/**` — un contrôle qui rendrait tout faux les aurait signalés aussi. Il résout des **symboles**. (c) ⚠️ **Le contrôle image-bank, lui, ne résout rien** : son message est « Contient **marqueur** image-bank hors zones dédiées », et il dénonce `src/app/admin.css` et `src/lib/admin-nav.test.ts`, qui ne font que **contenir la chaîne**. Ses 24 violations sont donc à prendre comme un **signal poreux**, pas comme un compte de fautes — c'est A-011 dans le contrôle lui-même, et probablement la raison pour laquelle personne ne l'a câblé. **Je rapporte les 47 comme un fait, les 24 comme une alerte à qualifier.**
- Impact        : trois défauts qui se composent. (a) Le cloisonnement Qualiopi **est effectivement rompu en 47 endroits**, dont les onze fichiers du module planning — celui-là même que le CDC demande de conserver, et que tout retrait futur devra donc déplacer avec ses dépendances Qualiopi. (b) Personne ne peut le savoir : les contrôles ne sont déclarés que dans `verify:all`, qu'il faut taper à la main. (c) L'en-tête du script Qualiopi **affirme le contraire** — « câblé dans verify:all + pre-push » — si bien qu'un lecteur qui fait confiance au commentaire croit protégé un cloisonnement rompu depuis un temps indéterminé. C'est A-011 sous sa forme la plus complète : une garde correcte, qui a raison, que personne ne lance, et dont la documentation ment sur son propre câblage.
- Reproduction  : `pnpm qualiopi:isolation-check` puis `pnpm image-bank:isolation-check` (compter ~20 min chacun sur ce poste) ; `grep -rn "isolation-check" .github/ .husky/`.
- Correctif     : ① **d'abord trancher les 47** — soit les fichiers rejoignent les zones, soit les zones s'élargissent avec une raison écrite. Le module planning a probablement vocation à devenir une zone autorisée à part entière ; les surfaces publiques (`app/[locale]/*/page.tsx`, `sitemap.ts`, `Footer.tsx`) relèvent d'un autre arbitrage, puisqu'elles sont précisément celles que `QUALIOPI_CERTIFICATION_OBTENUE` gouverne (**E34-003**). ② **Réparer le contrôle image-bank avant de croire ses 24** : passer du marqueur textuel à la résolution de symbole, comme son homologue Qualiopi (**4-6 h**). ③ Puis câbler les deux à Gate A (**15 min**) et corriger l'en-tête mensonger (**2 min**). ⚠️ **Ne pas câbler avant d'avoir tranché** : brancher une garde rouge rend Gate A rouge pour tout le monde — c'est exactement le mécanisme qui a produit E34-001. ⚠️ Mesurer aussi la durée : **~20 min sur ce poste**. Si l'ordre de grandeur tient sur un runner, borner avant de rendre bloquant, sinon on remplace un silence par un blocage — ce qui est arrivé à Gate C (`f40b408a`, « Gate C exigeait 32 min sans pouvoir rougir »).
- Statut        : ouvert

### [E34-008] Le module « Booking » de la console n'est pas le « booking » du CDC : viser le premier détruirait les inscriptions aux sessions
- Sévérité      : S2 défaut (piège de périmètre — il fait perdre l'utilisateur, et pire, il ferait perdre celui qui exécutera un retrait)
- Domaine       : navigation / UX
- Référence     : dépôt du site, `main eb754332`
- Emplacement   : `src/app/[locale]/(admin)/[adminPrefix]/{reservations,devis,factures,echeanciers,paiements,options,calendrier}/page.tsx`
- Constat       : **quatorze pages** de la console, dont sept portent les noms exacts du périmètre du CDC, sont **mortes** et redirigent en **308** vers leurs équivalents réels sous `qualiopi/` ou `planning/`. Le CDC §A dit conserver « `Booking` / `planning` = inscriptions aux sessions de formation et planning des intervenants » ; le module qui porte ce nom dans la console ne contient rien de tel.
- Preuve        : `grep -rln "permanentRedirect" "src/app/[locale]/(admin)/"` → **29 fichiers**. Lecture des quatorze de la famille : toutes portent le même en-tête, « Module Booking (mort) — redirection vers le module réel équivalent. Audit UX console du 2026-08-01 (phase 2) ». Destinations mesurées : `/reservations` → `/qualiopi/dossiers` · `/devis` → `/qualiopi/devis` · `/factures` et `/paiements` → `/qualiopi/facturation` · `/echeanciers` → `/qualiopi/facturation/plans` · `/options` → `/qualiopi/dossiers` · `/calendrier` → `/planning`.
- Témoin négatif: la recherche n'est pas complaisante — elle rend aussi **quinze redirections hors famille** (onze `content-gen/*`, `submissions/{page,[id]}`, `qualiopi/conformite`, `qualiopi/page.tsx`), que je n'ai pas comptées dans les quatorze. Et le module `planning`, lui, est **bien vivant** : `planning/page.tsx` rend une vraie grille mensuelle RSC avec 6 sous-écrans (`hub`, `timeline`, `charge`, `pipeline`, `previsionnel`, `ics`).
- Impact        : **le risque n'est pas dans la console, il est dans le retrait futur.** Un exécutant qui lit « conserver Booking et planning » et ouvre `/reservations` trouve une coquille vide ; s'il lit « retirer les écrans que le CRM remplace » et vise `qualiopi/dossiers` parce que c'est là que `/reservations` l'a mené, il supprime les **inscriptions aux sessions de formation** — la fonction que le CDC demande explicitement de garder. Le code serveur de l'ancien module (`src/features/booking/*`, 21 fichiers, 6 émetteurs Telegram) est d'ailleurs **toujours actif** : la suppression dure a été renvoyée à un lot séparé.
- Reproduction  : ouvrir `src/app/[locale]/(admin)/[adminPrefix]/reservations/page.tsx`.
- Correctif     : aucun code. **Une ligne dans la ligne de base** : le périmètre à conserver s'appelle `/qualiopi/dossiers`, `/qualiopi/sessions`, `/qualiopi/devis`, `/qualiopi/facturation{,/plans}` et `/planning/*` — jamais `/reservations`, `/devis`, `/factures`, `/echeanciers`, `/paiements`, `/options`, `/calendrier`. **Coût : 0 €, 10 min de rédaction.** C'est le constat le moins cher et, si un palier est franchi un jour, probablement le plus utile.

- Statut        : ouvert

---

## 7. CE QUE JE N'AI PAS PU VÉRIFIER — et pourquoi

1. **La suite complète du site, jouée localement.** Lancée à 13 h 24, arrêtée à 13 h 49 après
   **41 fichiers sur 814** : 36 s par fichier, soit ≈ 8 h 30 de projection. Journal partiel archivé
   (`10_vitest-full-PARTIEL-41fichiers.log`), **41/41 verts**. Je me suis rabattu sur les chiffres CI
   (§3.2) et sur une suite de périmètre (§3.3). Ce n'est pas la même mesure et je ne la présente pas
   comme telle.
2. **L'état réel de `FACTURATION_HUB_ENABLED` côté Coolify.** Mon oracle externe s'est révélé
   incapable de discriminer (le témoin non gaté rend le même 404), et je n'ai pas accès à la console
   Coolify. Voir E34-004, où c'est écrit noir sur blanc.
3. **Si le certificat Qualiopi est détenu ou non.** C'est un fait d'entreprise, pas un fait de code.
   J'ai mesuré la contradiction entre le message de `eb754332` (2026-08-19) et l'état de production ;
   je ne la tranche pas. Voir E34-003.
4. **`image-bank:isolation-check`.** Lancé à la suite du contrôle Qualiopi, il n'avait pas rendu la
   main au moment d'écrire (le contrôle Qualiopi, lui, a demandé ~20 min). Son **absence de câblage**
   est mesurée et certaine (E34-007) ; c'est son **verdict** qui manque. Celui du contrôle Qualiopi,
   en revanche, a été obtenu : **rouge, 24 violations** (E34-007). La liste que j'ai archivée est
   **tronquée par mon propre `tail -25`** ; la sortie complète est dans
   `24_qualiopi-isolation-complet.txt` — relance lancée, **encore en cours à l'heure d'écrire** (le
   contrôle demande ~20 min sur ce poste). Le compte exact peut donc être **supérieur à 24** : je
   l'écris comme un **plancher**, jamais comme un total. Ce qui est certain et suffisant pour le
   constat : **le contrôle sort en 1**, et il sort en 1 sur des fichiers du périmètre.
5. **La suite Playwright.** `tests/e2e/**` est exclu de Vitest et ne tourne qu'en Gate B, en
   `continue-on-error`. Je ne l'ai pas jouée : elle exige un `next build` (≈ 25 min sur ce poste,
   17 629 routes) que le budget de cette mission ne portait pas.
6. **Les écrans ouverts pour de vrai** (règle 4 du dossier commun). La console de production exige
   une session administrateur et le préfixe d'URL secret ; aucun des deux ne m'est accessible, et
   D23-001 interdit de conclure depuis un atelier local dont je n'ai pas reconstruit le bundle. Tous
   mes verdicts d'interface sont donc **des lectures de code et de configuration**, pas des gestes.
7. **`src/features/booking/*`** (21 fichiers, 5 fichiers de test, 6 émetteurs Telegram). Le module est
   mort côté écran mais vivant côté serveur. Je l'ai inventorié, pas audité : il sort du périmètre
   que le CDC demande de conserver, et l'auditer aurait empiété sur l'agent chargé du retrait.
