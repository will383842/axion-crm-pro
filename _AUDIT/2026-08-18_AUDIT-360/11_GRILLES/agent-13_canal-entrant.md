# AGENT 13 — Auditeur du canal entrant (site Axion-IA → CRM)

> Référence mesurée le 2026-08-19 : `git log --oneline -1` → **`1145473`**.
> Le dossier commun nomme `main = c0c453d` ; le dépôt a avancé de 4 commits **documentaires** depuis.
> `git diff --stat c0c453d HEAD -- backend/app/Crm/ backend/app/Support/HmacSignature.php backend/app/Http/Controllers/Internal/ backend/routes/api.php` → **sortie vide**.
> **Tout mon périmètre est donc octet pour octet identique à `c0c453d`.** Les constats sont valables sur les deux références.
>
> Atelier : `axion-crm-{api,postgres,redis}` locaux. Base jetable **`axion_crm_audit13`**, créée pour cet audit
> (`CREATE DATABASE` + `artisan migrate`), détruite en fin de course. **Aucune écriture en production, aucun
> fichier du produit modifié, aucun accès au worktree `crmpro-wt-etape1a`.**
> Le harnais et les scénarios joués sont archivés dans `04_PREUVES/agent-13/`.
>
> ⚠️ **Charge de l'atelier pendant la mesure** : `/proc/loadavg` est monté à **29 → 40** (autres agents de
> l'audit : `pest`, `phpstan`, `migrate:fresh`, `tinker`). Cela n'invalide aucun résultat fonctionnel
> — mais **aucune mesure de temps de réponse n'a été tentée**, elle aurait été mensongère.

---

## 1. Grille — un objet par ligne, un point de grille par colonne

Légende : **✅** conforme, mesuré · **⚠️** défaut mesuré · **❌** manque mesuré · **—** hors objet ·
**NV** non vérifié (motif en §4).

| Objet | 1 Signature | 2 Rejeu | 3 Idempotence | 4 Classement | 5 UTC | 6 Rejets | 7 Journal | 8 Cloisonnement | 9 Consentement | 10 Ce qui ne traverse pas |
|---|---|---|---|---|---|---|---|---|---|---|
| `Support/HmacSignature.php` | ✅ HMAC-SHA256 sur `ts.corps`, `hash_equals` | ✅ fenêtre ±300 s, symétrique | — | — | — | — | — | — | — | — |
| `Internal/SiteSyncController.php` | ✅ 401 avant tout autre contrôle | ✅ 401 `stale_signature` | — | ✅ 422 explicites | — | ⚠️ `Log::warning` seul | ⚠️ `audit_logs` sans motif | ✅ 503 `workspace_missing` | ✅ 422 relayé | — |
| `Internal/SiteGdprController.php` | ✅ même garde | ⚠️ **rejouable 300 s, aucune clé d'idempotence** | ❌ aucune | ✅ 422 stricts | — | ⚠️ idem | ⚠️ idem | ✅ scope `both\|business\|vivier` | — | ⚠️ **pas de `schema_version`** |
| `Internal/ZeptoMailWebhookController.php` *(absent du prompt)* | ⚠️ **jeton en clair dans l'URL**, `hash_equals` | ✅ `inscrire()` incrémente, ne duplique pas | ✅ par adresse | ✅ tolérant, `ignored` compté | NV | ⚠️ inconnus comptés `ignored`, jamais journalisés | ✅ compteurs rendus | ✅ scope `business` figé | — | — |
| `Crm/Ingest/SiteSyncEvent.php` | — | — | ✅ `event_id` 8–128 c. | ✅ schéma **strict**, clé inconnue = 422 | ⚠️ **`setTimezone(app.timezone)`** | — | — | ✅ workspace **jamais** dans le contrat | ✅ `consent.version` transporté | ✅ 10 `EVENT_TYPES`, 14 `FORM_TYPES` |
| `Crm/Ingest/SiteSyncClassifier.php` | — | — | — | ✅ 10/10 classés, `default` garde-fou | — | — | — | ✅ univers = décision du CRM | ✅ base légale déduite | ⚠️ **`fournisseur` inatteignable**, `client` seulement par avis |
| `Crm/Ingest/SiteSyncIngestService.php` | — | ✅ `activities.external_ref` + index UNIQUE | ✅ `noop_idempotent` mesuré | ✅ | ⚠️ écrit `occurred_at`/`consent_at` | ❌ **aucune file morte** | ⚠️ trace sans contenu | ✅ `WorkspaceContext::run` + RLS forcé | ✅ garde v2 rougit | ⚠️ **`pending_match` systématique** |
| `Crm/Ingest/ContactUpserter.php` | — | — | ⚠️ **`lower()` locale-dépendant** | — | — | ❌ **personne sans nom : perdue** | — | ✅ scopé workspace | ✅ | — |
| `Crm/Ingest/IngestOutcome.php` | — | ✅ `noop_idempotent` | ✅ 5 statuts, tous soldants | — | — | ⚠️ aucun statut « refusé » | ✅ `tags` réellement posés rendus | — | — | — |
| `Crm/Ingest/SiteSyncRejection.php` | — | — | — | ✅ 422 définitif / 503 rejouable | — | ⚠️ **exception, jamais persistée** | — | — | ✅ code + détails | — |
| `Crm/Taxonomy.php` | — | — | — | ✅ = CHECK SQL en base | — | — | ✅ 16 `ACTIVITY_KINDS` | ✅ `VIVIER_WORKSPACE_SLUG` | ✅ 3 versions v2 | ⚠️ **rien pour une inscription à une session** |
| `POST /api/internal/site-sync` | ✅ **témoins + et −** | ✅ mesuré | ✅ mesuré | ✅ mesuré | ⚠️ **+7 200 s mesuré** | ⚠️ mesuré | ⚠️ mesuré | ✅ mesuré | ✅ mesuré | ⚠️ mesuré |
| `POST /api/internal/site-sync/gdpr` | ✅ code identique | ⚠️ | ❌ | ✅ | NV | ⚠️ | ⚠️ | ✅ | — | ⚠️ |

**Verdict par point de grille** (le détail des mesures est en §2) :

| # | Point | Verdict |
|---|---|---|
| 1 | Signature | ✅ **Solide.** HMAC-SHA256 sur `"<timestamp>.<corps brut>"`, `hash_equals`, contrôlée **avant** le drapeau. Témoin positif ET négatif joués. |
| 2 | Rejeu | ✅ **Couvert, sans nonce.** Fenêtre ±300 s + idempotence par `event_id` adossée à un index UNIQUE. Rejeu octet pour octet → `noop_idempotent`, 1 seule activité. |
| 3 | Idempotence / dédup | ⚠️ **Correcte, sauf un angle mort de locale** : la voie e-mail échoue en locale `C` (production) et réussit en `en_US.utf8` (CI). Doublon mesuré, témoin ASCII fusionné. |
| 4 | Classement | ✅ **Refus explicite et bruyant** d'un événement inconnu (422 `unknown_event_type`). Mais ⚠️ un **tag** inconnu est ignoré en **silence**, avec un 200. |
| 5 | Horodatage UTC | ⚠️ **La date traverse, l'instant non** : +7 200 s mesurés sur `occurred_at` **et sur `consent_at`** dans tout environnement sans `DB_TIMEZONE`. |
| 6 | Rejets | ❌ **Aucune file morte, nulle part.** 26 refus joués → 0 ligne persistée portant l'événement refusé. |
| 7 | Journal | ⚠️ **Trace oui, exploitable non.** Chaque appel laisse une ligne chaînée dans `audit_logs`, mais sans `event_id`, sans code d'erreur, sans contenu. |
| 8 | Cloisonnement | ✅ **Le meilleur point du canal.** Workspace décidé par le CRM, RLS forcé, 503 + zéro écriture si le workspace manque. |
| 9 | Consentement | ✅ **Le régime ne rejette AUCUN événement légitime courant** — et les deux messages cités dans mon ordre de mission viennent de journaux de **test**, dont l'un d'un **autre canal**. Voir §2.9. |
| 10 | Ce qui ne traverse pas | ⚠️ **Un gisement entier.** Liste mesurée en §3. |

---

## 2. Les dix points, mesurés

### 2.1 Signature — ✅

`HmacSignature::verify()` compare, en temps constant, `hash_hmac('sha256', "<timestamp>.<corps brut>", $secret)`
à l'en-tête `X-Site-Signature`. Le **corps signé est le flux brut** (`$request->getContent()`), pas un
ré-encodage — donc insensible à l'ordre des clés. Le contrôle est le **premier** du contrôleur, avant le
drapeau maître : un appelant non authentifié n'apprend rien de l'état du système.

Quatre témoins joués (`04_PREUVES/agent-13/01_…txt`) :

| Geste | Attendu | Mesuré |
|---|---|---|
| **Témoin positif** — signature correcte | acceptation | **HTTP 200** `{"status":"created","subject_id":1,"activity_id":1,"tags":["src:site-formulaire-audit","svc:audit"]}` + 1 `companies` + 1 `contacts` + 1 `activities` |
| **Témoin négatif** — signature falsifiée (`000…0`) | refus | **HTTP 401** `{"error":"bad_signature"}` |
| **Témoin négatif** — aucun en-tête de signature | refus | **HTTP 401** `bad_signature` |
| **Le corps est-il couvert ?** — signer un corps, en envoyer un autre | refus | **HTTP 401** `bad_signature` |
| **L'horodatage est-il couvert ?** — signature valide, `X-Site-Timestamp` décalé de 1 s | refus | **HTTP 401** `bad_signature` |

Après ces cinq tentatives : `select count(*) from activities where external_ref like 'site:event:p1-%'` → **0**.
Rien n'a été écrit. Le secret vide (`SITE_SYNC_HMAC_SECRET` absent, cas de l'atelier local par défaut) fait
échouer `verify()` d'entrée : **aucune requête ne passe**, ce qui est le bon défaut.

### 2.2 Rejeu — ✅ (fenêtre + `event_id`, pas de nonce)

**Il n'y a pas de nonce**, et il n'en faut pas : deux mécanismes indépendants se relaient.

- **Fenêtre d'horodatage** — `crm.ingest.max_clock_skew_seconds`, défaut **300 s**, `abs(time() - $ts)`, donc
  symétrique. Mesuré : `-400 s` → **401 `stale_signature`** ; `+400 s` → **401 `stale_signature`** ;
  `-290 s` → **200** (dans la fenêtre).
- **Idempotence métier** — `activities.external_ref = 'site:event:<event_id>'`, adossée à un index
  **UNIQUE** réellement présent en base :
  `activities_workspace_external_ref_key UNIQUE (workspace_id, external_ref) WHERE external_ref IS NOT NULL`.

**Geste joué** : la même requête renvoyée **octet pour octet** (mêmes en-têtes, même horodatage, même
signature) →

```
1re fois : HTTP 200 {"status":"updated",         "activity_id":2}
2e  fois : HTTP 200 {"status":"noop_idempotent", "activity_id":2}
select count(*) from activities where external_ref='site:event:p2-rejeu'  →  1
select count(*) from companies                                            →  1
```

**Une requête identique renvoyée deux fois ne crée donc pas deux fiches.** L'index UNIQUE couvre en outre la
course entre deux tentatives concurrentes de l'outbox : la seconde lèverait un 23505, capté en 500
`ingest_failed`, que l'outbox rejoue — et la reprise trouve la ligne et rend `noop_idempotent`.

Recherche d'une table anti-rejeu en base (`%nonce%`, `%replay%`, `%outbox%`, `%dead%`) : **aucune**.

> ⚠️ **`/site-sync/gdpr` n'a pas ce second filet** : son contrat (`action`, `person_key`, `email`, `scope`)
> ne porte **aucun identifiant d'événement**. Une requête `export` interceptée est rejouable pendant 300 s
> et rend à nouveau l'intégralité des données de la personne. → **B13-007**.

### 2.3 Idempotence de `ContactUpserter` — ⚠️

L'ordre de recherche est, du plus sûr au plus faible :
`external_ref` → `person_key` → **e-mail** → `normalized_hash` (prénom+nom+entreprise).

| Geste | Mesuré |
|---|---|
| Même personne, autre événement, autre source (`calendly_booked`) | **1 seule fiche** (`updated`) ✅ |
| Même e-mail, `person_key` **différent** | **1 seule fiche** — rapprochée par e-mail, et le `person_key` existant est **écrasé** par le nouveau ✅/⚠️ |
| `person_key` **absent** | **422 `invalid_person_key`** ✅ |
| `person_key` en **majuscules** hexadécimales | **422 `invalid_person_key`** (regex `^[0-9a-f]{64}$`) — strict, sans conséquence pratique : Node rend toujours du minuscule ✅ |

**Le piège 10, mesuré.** `ContactUpserter.php:69` (et `SiteSyncIngestService.php:314` pour les candidats)
interroge `whereRaw('lower(email::text) = ?', [$email])`, où `$email` a été normalisé **en PHP** par
`mb_strtolower()`. Or les deux `lower()` ne sont pas le même :

```
datcollate | datctype  →  C | C            (production ET atelier local)
lower('JOSÉ.MARTIN@EX.COM' COLLATE "C")         → josÉ.martin@ex.com     ← accent NON abaissé
lower('JOSÉ.MARTIN@EX.COM' COLLATE "en_US.utf8")→ josé.martin@ex.com     ← accent abaissé (la CI)
lower(...) = 'josé.martin@ex.com'  (ce que produit mb_strtolower)  →  f
```

**Geste joué, avec témoin négatif** — deux fiches préexistantes de la même entreprise, l'une avec un e-mail
à majuscule **accentuée**, l'autre à majuscule **ASCII** ; on ingère la même personne sous un **nom
différent**, pour que seule la voie e-mail puisse rapprocher :

| Fiche en base | Événement ingéré | Mesuré |
|---|---|---|
| `ANA.TEST@example.com` (témoin ASCII) | `Ana.Test@example.com` | **`updated`** → fusionnée ✅ |
| `ZOÉ.TEST@example.com` | `Zoé.Test@example.com` | **`created`** → **DOUBLON** ⚠️ |

Le contrôle sait donc rapprocher (témoin ASCII), et il échoue précisément sur l'objet accentué.
**Et il n'échoue qu'en production** : sous la locale `en_US.utf8` de la CI, les deux auraient fusionné —
la suite de tests ne peut pas faire rougir ce défaut. → **B13-003**.

### 2.4 Classement — ✅ pour les événements, ⚠️ pour les tags

`SiteSyncClassifier` sait classer les **10** `event_type` du contrat, qui sont exactement un sous-ensemble
des **16** `Taxonomy::ACTIVITY_KINDS` :
`array_diff(EVENT_TYPES, ACTIVITY_KINDS)` → **`[]`** (vérifié à l'exécution). Le `default` d'`activityKind()`
est un garde-fou réel, jamais atteint aujourd'hui.

**Événement inconnu, joué** :

| Geste | Mesuré |
|---|---|
| `event_type = "enrollment_created"` (inscription à une session) | **422** `unknown_event_type` — refus **explicite**, journalisé `Log::warning` |
| `form_type = "webinaire"` | **422** `unknown_form_type` |
| champ racine inconnu `session_id` | **422** `unknown_field` + `details.unknown` |
| `schema_version` absent | **422** `unsupported_schema_version : NULL (attendu : 1)` |
| `schema_version = 2` | **422** `unsupported_schema_version : 2` |

Ce n'est donc **ni un silence ni une alerte** : c'est un **rejet bruyant côté CRM**, que le site traduit en
`gave_up` **immédiat** (`emit.ts:110-123`) — la ligne d'outbox est soldée et **l'événement n'arrive jamais**.

⚠️ **En revanche, un TAG inconnu est perdu en silence, avec un 200** :

| Geste | Mesuré |
|---|---|
| `source_slug = "qualiopi-portail"` (hors référentiel) | **HTTP 200 `updated`** · `tags` renvoyés : `["svc:audit"]` · le tag `src:qualiopi-portail` **n'existe pas** en base · l'entreprise ne porte **aucun tag de provenance** |
| `tags = ["taille:micro", "taille:pme"]` | **HTTP 200** · `taille:pme` posé, **`taille:micro` disparu** sans un mot |

Le `src:` est la **seule** trace de la provenance d'un lead. → **B13-005**.

### 2.5 Horodatage UTC — ⚠️ la date traverse, l'instant recule

`SiteSyncEvent::parseDate()` (le **seul** point d'entrée des dates extérieures du canal : `occurred_at`,
`consent.at`, `consent.vivier_at`) ramène toute date dans `config('app.timezone')` = `Europe/Paris`.
C'est juste **à condition** que la session Postgres soit elle aussi à Paris — c'est le rôle de `DB_TIMEZONE`.

**Mesuré dans l'atelier** (`app.timezone = Europe/Paris`, `DB_TIMEZONE` **absente**) :

```
émis  occurred_at = 2026-08-17T10:00:00.000Z
stocké             = 2026-08-17 12:00:00+00     écart = +7200,000000 s
émis  consent.at   = 2026-08-17T09:59:00.000Z
stocké             = 2026-08-17 11:59:00+00     écart = +7200,000000 s

cause, mesurée à la connexion :  SHOW TimeZone  →  Etc/UTC
                                 app.timezone   →  Europe/Paris
témoin : la même sonde sur deux instants égaux →  0,000000 s
```

**`consent_at` est la date qui PROUVE le consentement.** Un horodatage faux est un horodatage sans valeur
probante — c'est exactement ce que dit le commentaire de `SiteSyncEvent.php:390-420`, et le défaut est
toujours armé partout où `DB_TIMEZONE` n'est pas posée.

> **Ceci approfondit [A05-008]**, qui a mesuré la cause (variable posée en production, absente de l'atelier
> et de **tout** fichier de composition) ; je n'en refais pas un constat neuf. Ce que j'ajoute est
> l'**effet sur le canal** : le seul point d'entrée des dates extérieures, et la date probante du
> consentement. J'ajoute aussi la fragilité de la garde : `NeDoitPasRegresserTest.php:400` vérifie que
> **`phpunit.xml` pose encore `DB_TIMEZONE`** — la garde mesure sa propre fixture, jamais l'exécution
> réelle (piège 19). → **B13-006**.

### 2.6 Rejets — ❌ aucune file morte

Recherche exhaustive : **aucune table** du schéma ne stocke un événement refusé
(116 tables listées ; motifs `%rejet%`, `%reject%`, `%dead%`, `%nonce%`, `%outbox%` → aucune).
`SiteSyncRejection` est une **exception**, jamais persistée. Après 26 refus joués :

```
select count(*) from activities where external_ref ilike '%p4-%'   →  0
```

Un refus laisse donc : (a) une ligne `Log::warning` dans la pile `single,stderr` — fichier
`storage/logs/laravel.log` **non rotatif** (pilote `single`), sans écran ni alerte ; (b) une ligne
`audit_logs` sans le contenu (§2.7). Côté site, la ligne d'outbox passe `gave_up`. **Personne n'est prévenu.**

⚠️ **Un rejet plus grave encore, parce qu'il rend 200** — mesuré :

```
SIREN présent + e-mail présent + nom de famille ABSENT
→ HTTP 200 {"status":"created","subject_type":"company","subject_id":6}
contacts de l'entreprise                                        → (AUCUN CONTACT)
payload de l'activité  → {"message":"bonjour","source_slug":null,"subject_ref":"site:submission:r61"}
"perdu@example.com" encore présent en base ? contacts=0, activities=0
```

L'adresse, le téléphone et le prénom **n'existent plus nulle part**. `pending_match` — qui aurait conservé
tout cela — n'est rempli que si `subject_id === null` (`SiteSyncIngestService.php:553`) ; ici l'entreprise a
été créée, donc il ne l'est pas. Le CRM répond **200 `created`** : le site solde sa ligne et ne rejouera
jamais. → **B13-002**.

### 2.7 Journal — ⚠️ une trace, pas une piste

`AuditHashChainLogger` est appliqué au groupe `api`, donc **aussi** à `/api/internal/site-sync`, et il
s'exécute **après** la réponse : les refus sont journalisés comme les succès. Mesuré :

```
event_type |          path          | status_code | n
POST       | api/internal/site-sync |         200 | 15
POST       | api/internal/site-sync |         401 |  7
POST       | api/internal/site-sync |         422 | 18
POST       | api/internal/site-sync |         503 |  1
→ 26 refus tracés
```

Mais la ligne ne porte que : `event_type` ('POST'), `path`, `status_code`, `ip`, `user_agent`,
**`payload_hash`** (un sha256), `prev_hash`, `current_hash`. **Ni `event_id`, ni code d'erreur, ni contenu.**
On peut donc **compter** les refus ; on ne peut ni savoir **lequel** a été refusé, ni **pourquoi**, ni le
rejouer. Le « pourquoi » n'existe que dans un fichier de log non rotatif que rien n'expose. → **B13-004**.

Côté succès, la trace est en revanche bonne : `activities` porte `kind`, `occurred_at`, `person_key`,
`external_ref`, `title`, `subject_type/id` et le `payload` complet, et `IngestOutcome` renvoie au site la
liste des tags **réellement** posés.

### 2.8 Cloisonnement — ✅

C'est le point le mieux tenu du canal.

- Le workspace **n'est jamais dans le contrat** (`TOP_LEVEL_KEYS` vérifié à l'exécution : 13 clés, aucune
  ne le nomme). Il est **déduit** : `vivier` pour `application_submitted` ou un formulaire `recrutement`,
  `business` sinon. Un émetteur compromis ne choisit donc pas son univers d'atterrissage.
- Toute l'écriture passe par `WorkspaceContext::run($workspaceId, …)` autour d'une transaction unique, et
  les policies RLS d'`activities` sont **`FORCE ROW LEVEL SECURITY`** : sans contexte, rien ne s'écrit.
- **Workspace absent, joué** (slug `axion-ia` temporairement renommé) :

```
HTTP 503 {"error":"workspace_missing","message":"Workspace de destination introuvable : « axion-ia »."}
companies créées = 0 ; activities créées = 0
```

**503 et non 422** : le site garde la ligne en attente et la rejouera. C'est le bon choix — un workspace
absent est un incident temporaire, pas un message invalide.

### 2.9 Consentement — ✅ et **la prémisse de la question est à corriger**

**Combien d'événements légitimes ce régime rejette-t-il ? Zéro, parmi les émetteurs actuels.**
Les trois émetteurs de candidature du site couvrent exactement les trois versions de la liste fermée :

| Émetteur (site) | `consent.version` émis | `Taxonomy::CANDIDATE_CONSENT_VERSIONS_V2` |
|---|---|---|
| `src/features/job-application/actions.ts:43` | `careers-v2-2026-08-13` | ✅ accepté |
| `src/lib/commercial-application/model.ts:26` | `memo-v2-2026-08-13` | ✅ accepté |
| `src/server/vivier/config.ts:57` | `vivier-stock-2026-08-14` | ✅ accepté |

Les quatre cas joués confirment que la garde **rougit sur le bon objet** :

| `consent.version` | Mesuré |
|---|---|
| `careers-v1-2026-01-01` | **422 `candidate_consent_v2_required`** |
| *(aucun)* | **422 `candidate_consent_v2_required`** |
| `careers-v2-2026-08-13` | **200 `created`**, `legal_basis = consent` |
| `vivier-stock-2026-08-14` | **200 `created`**, `legal_basis = consent` |

Le seul rejet réel est **voulu** : les candidatures du **stock** portent `careers-v1-2026-06-09`, dont le
texte ne couvre que l'étude de la candidature en cours. Le site le dit lui-même
(`job-application/actions.ts:305-313` : « **Le refus est donc attendu, et sain** »), et le rattrapage J+30
les réémet sous `vivier-stock-2026-08-14`.

**Les deux messages cités dans mon ordre de mission ne viennent pas de trafic réel** :
- `consentement v2 requis (…)` n'apparaît que dans `_REPORTS/e2e2-preuves/pest-*.txt` et dans les journaux
  de CI — ce sont les **témoins négatifs de la suite de tests**, pas des leads perdus ;
- `Version de schéma pivot non supportée : NULL (attendu : 1)` **n'appartient pas à ce canal** : la chaîne
  vit dans `backend/app/Crm/Scraping/ScrapedRecord.php:123`, et le message qui l'accompagne est
  `scraper-result refusé` — c'est le canal de **collecte**, pas la synchro site.
  Le message équivalent de mon périmètre est `Version de schéma non supportée` (sans « pivot »).

Reste une fragilité réelle : les deux listes sont tenues **à la main dans deux dépôts**, et rien ne
compare la liste du CRM à celle du site (piège 15). Le jour où le site sert un texte v3, la garde rejettera
100 % des candidatures — avec un 422, donc `gave_up` immédiat et perte sèche. → couvert par **B13-004**
(personne ne verrait la vague).

### 2.10 Ce qui ne traverse PAS et devrait

Voir §3 — c'est la partie la plus lourde de cet audit.

---

## 3. Les événements qui ne traversent pas, et devraient

### 3.1 D'abord, la cause du plus gros trou : **aucun émetteur ne transmet de SIREN**

`upsertBusiness()` ne crée **rien** sans SIREN. [A05-003] a mesuré l'effet en production (3 événements sur
3 en arbitrage) et l'a attribué à un SIREN « rarement rempli ». **La mesure du code du site dit autre chose,
et c'est plus grave que « rarement » :**

- `company.siren` est déclaré dans le contrat (`crm-sync/types.ts:89`) et normalisé dans `dispatch()`
  (`crm-sync/index.ts:228`) ;
- **aucun des 16 points d'appel du site ne le renseigne.** `grep -rn "siren" src/features/ src/server/crm-sync/ src/app/api/`
  ne rend **que** les fichiers internes de `crm-sync` — les six émetteurs qui passent un bloc `company`
  (`unified-contact:259`, `roi-report:173`, `podcast-request:115`, `review-submission:220`,
  `capturer-lead:162`) passent `name`, `city`, `postcode`, `sizeCategory`, `sector` — **jamais `siren`** ;
- **aucun formulaire du site ne collecte de SIREN** : rien dans `src/lib/schemas/`. La colonne
  `Submission.registrationNumber` existe au schéma Prisma (`prisma/schema.prisma:739`) mais
  **aucun code de `src/` ne l'écrit** (les seules occurrences sont le SIREN d'Axion-IA lui-même, en JSON-LD).

**Joué avec le contrat réel du site** (payload calqué sur `unified-contact/actions.ts:250-274`) :

```
S1  1 lead  « VRAIE ENTREPRISE SAS », audit  → HTTP 200 {"status":"pending_match","subject_id":null}
S2  5 leads (audit, devis, formation, partenariat, support_client) → 5 × pending_match

sociétés créées = 0 · personnes créées = 0 · en attente d'arbitrage HUMAIN = 6
```

**Ce n'est donc pas « rarement » : c'est structurellement 100 %.** Le canal est authentifié, versionné,
idempotent, cloisonné, testé — et il ne peut créer **aucune** fiche entreprise ni personne automatiquement.
Ceci change le correctif d'[A05-003] : sa voie (a) n'est pas une option parmi deux, c'est la **seule** —
« faire envoyer le SIREN par le site » supposerait de le **collecter**, ce qu'aucun formulaire ne fait.
→ **B13-001**.

*Une bonne nouvelle, mesurée* : dans ce cas-là le `pending_match` conserve bien tout
(`email`, `phone`, `first_name`, `last_name`, `denomination`, `city`, `consent_*`) — la matière de
l'arbitrage est intacte. La perte du §2.6 ne survient que dans le cas **inverse** (SIREN présent, nom absent).

### 3.2 Le vocabulaire fermé, confronté à ce que le site sait produire

`Taxonomy::ACTIVITY_KINDS` (16) ⊇ `EVENT_TYPES` (10) : **aucun événement émis n'est refusé** sur ce critère.
Les trous sont ailleurs — ce sont des données que le site **détient** et pour lesquelles **il n'existe ni
émetteur, ni valeur de contrat**. Un émetteur ajouté sans étendre `EVENT_TYPES` serait refusé 422 → `gave_up`.

| # | Ce qui ne traverse pas | Où c'est, côté site | Pourquoi ça devrait |
|---|---|---|---|
| 1 | **Inscription à une session / stagiaire** — `Trainee`, `Enrollment` | `prisma/schema.prisma:6604-6708` ; création `server/actions/qualiopi/{trainees:64,enrollments:75}` | **Déjà relevé par le journal du 19/08 §10.3, je le confirme et le complète** : `Trainee` porte `email @unique`, `telephone`, `entreprise`, **et le consentement déjà horodaté et versionné** (`consentementVersion:6620`, `consentementAt:6621`). La donnée la plus qualifiée du site. Aucun `event_type`, aucun `form_type` (`"formation"` désigne la *demande d'information*, pas l'inscription). Joué : `enrollment_created` → **422** |
| 2 | **Promotion en client payant** — `Client` Qualiopi | `schema.prisma:5449` (porte `siren:5458` !) ; `server/actions/qualiopi/entrees.ts` convertit une `Submission`/`CalendlyEvent` en client | `SiteSyncClassifier::lifecycleStage()` ne rend `client` **que** pour `review_posted`. **Un client qui paie mais n'écrit pas d'avis reste `opportunite` pour toujours.** Et `Client.siren` est le seul gisement de SIREN du site — celui qui débloquerait §3.1 |
| 3 | **Paiements et factures** — webhook Stripe | `app/api/stripe/webhook/route.ts` ; `Invoice.payerEmail:2035`, `payerSiret:2030` | Le signal commercial le plus fiable qui existe. Aucun émetteur. `payer_siret` est, là encore, un SIREN disponible |
| 4 | **Signature de contrat** — webhook DocuSeal | `app/api/docuseal/webhook/route.ts` ; `ContractDocument.signerEmail:2174` | Un contrat signé est la preuve d'un client. Aucun émetteur |
| 5 | **Lead chatbot escaladé** — `ChatEscalation.contactEmail` | `server/chatbot/tools/escalader-question.ts:40-49` (`schema.prisma:5302`) | **Asymétrie nette** : la branche `capturerLead` **émet** (`capturer-lead.ts:156`), la branche « je ne sais pas, laissez votre e-mail » **n'émet pas**. Même outil, même formulaire, un lead sur deux |
| 6 | **Bénéficiaires de coaching** — `CoachingSession.beneficiaireEmail`, `tuteurEntrepriseEmail` | `schema.prisma:9057,9109` ; `server/actions/formateur/coaching.actions.ts:65` | Deux adresses professionnelles par session, saisies par les formateurs |
| 7 | **Sous-traitants** — `SousTraitant.contactEmail` | `schema.prisma:7789` | `Taxonomy::BUSINESS_RELATION_TYPES` contient **`fournisseur`**, et `SiteSyncClassifier::relationType()` ne le rend **jamais**. Le CRM a la case, le site a la donnée, il n'y a pas de tuyau → **B13-008** |
| 8 | **Liste de diffusion espace-ressources** — `DocumentRecipient.email` (citext) | `schema.prisma:8129` ; `recipients.actions.ts:30,106` | Une liste d'envoi réelle, invisible du CRM — donc de l'opposition et de la liste de suppression |
| 9 | **Réclamations** — `Reclamation.reclamantEmail` | `schema.prisma:7720` | Un réclamant est un client mécontent : le signal le plus utile d'une fiche |
| 10 | **Abonnés newsletter `pending`** | `features/newsletter/actions.ts:75` | Volontaire (double opt-in) et sain — je le liste pour la complétude, **pas** comme un défaut |
| 11 | **`PodcastRequest`** — émet, mais **hors réconciliation** | `crm-sync/reconcile.ts:72-73` (5 familles ; podcast absent) | La seule famille émettrice non surveillée : un podcast perdu dans la fenêtre post-commit n'est jamais rattrapé |

Deux valeurs du vocabulaire fermé sont donc **inatteignables par le canal** : `fournisseur`
(`BUSINESS_RELATION_TYPES`) et — en pratique — `client` (`BUSINESS_LIFECYCLE_STAGES`), qui n'a qu'une seule
porte, l'avis client, laquelle tombe elle aussi en `pending_match` faute de SIREN.

### 3.3 Ce que j'ai cherché et qui n'est **pas** un trou

Rigueur : deux hypothèses vérifiées puis **abandonnées**.

- « Les familles de candidats ne sont jamais dérivées, tout tombe en `candidat_autre` » : **faux**.
  `src/lib/careers/candidate-family.ts:14-33` mappe correctement offre et catégorie vers
  `candidat_video` / `candidat_commercial` / `candidat_tech` / `candidat_autre`.
- « `simulateur_roi` manque au contrat du CRM » : **faux**, il est présent (`SiteSyncEvent::FORM_TYPES`),
  ajouté après l'incident décrit en commentaire. Les 14 `FORM_TYPES` du CRM et les 14 `CRM_FORM_TYPES` du
  site coïncident exactement.

---

## 4. Ce que je n'ai PAS pu vérifier, et pourquoi

1. **L'état réel de la production.** Consigne de lecture seule, et je n'ai pas ouvert de session distante.
   Tous mes nombres viennent de l'atelier local et de la lecture du code. Les volumes de production sont
   ceux d'[A05-003] et [A05-006], que je n'ai pas rejoués.
2. **`DB_TIMEZONE` en production.** Mon +7 200 s est mesuré dans l'atelier, où la variable est absente.
   [A05-008] atteste qu'elle **est** posée en production ; l'effet que je mesure **ne mord donc pas la
   production aujourd'hui**, mais il mord tout environnement recréé sans elle — et aucun `docker-compose*.yml`
   ne la pose.
3. **Le webhook ZeptoMail n'a été audité que statiquement.** Je n'ai joué aucune requête : `MAIL_WEBHOOK_TOKEN`
   est absent de l'atelier, la route rend 503 sans rien écrire, et fabriquer un jeton aurait exigé de toucher
   la configuration du produit. Le point 5 (UTC) le concernant reste **NV**. Ce que j'affirme sur lui
   (jeton en clair dans l'URL, parsing tolérant, `ignored` non journalisé) est lu, pas mesuré.
4. **Aucune mesure de temps de réponse ni de débit.** L'atelier tournait à `loadavg` 29–40 sous les autres
   agents de l'audit : tout chiffre de performance aurait été faux. Le rate limiter `internal`
   (600/min par IP, `RouteServiceProvider.php:28`) est lu, pas éprouvé.
5. **La profondeur réelle de la file d'arbitrage en production** et l'ancienneté des lignes qui y dorment :
   [A05-003] en donne 3 au 16/08 ; je ne l'ai pas rejouée.
6. **La visibilité côté site d'un `gave_up`** (écran d'admin, alerte, compteur) : question posée à
   l'inventaire du dépôt site, réponse non parvenue à l'heure de rendre. Ce que j'affirme sur le
   `gave_up` vient de `crm-sync/emit.ts:110-123`, lu.
7. **Divergence observée avec le constat A-001 du dossier**, non tranchée : `GET /api/v1/crm/arbitrage` sans
   authentification m'a rendu un **401 propre** (`{"message":"Unauthenticated."}`), pas le 500 annoncé.
   Mon appel passe par le noyau HTTP **en processus**, sans Caddy ni pile de session complète : ce n'est
   **pas** une réfutation, c'est un écart de protocole de mesure qu'il faudrait rejouer en HTTP réel.

---

## 5. Constats

### [B13-001] Aucun émetteur du site ne transmet de SIREN : la totalité des leads entrants reste en arbitrage manuel
- Sévérité      : S1 grave
- Domaine       : canal
- Référence     : `main c0c453d` (= `1145473` sur ce périmètre) · site `Axion-IA/axionia`
- Emplacement   : `backend/app/Crm/Ingest/SiteSyncIngestService.php:146-166` · `axionia/src/server/crm-sync/index.ts:223-235` · `axionia/src/features/unified-contact/actions.ts:259-263`
- Constat       : `company.siren` est déclaré au contrat et normalisé par `dispatch()`, mais aucun des points d'appel du site ne le renseigne et aucun formulaire du site ne le collecte, si bien que `upsertBusiness()` rend `pending_match` pour 100 % des événements business.
- Preuve        : 6 événements calqués sur le contrat réel du site (`04_PREUVES/agent-13/scenario_siren.php`) → 6 × `{"status":"pending_match","subject_id":null}`, `companies` créées = **0**, `contacts` créés = **0**, en attente d'arbitrage = **6**. Côté site : `grep -rn "siren" src/features/ src/server/crm-sync/ src/app/api/` ne rend que `crm-sync/{index.ts:64,228,297}` et `types.ts:89` — aucun émetteur ; `grep -rn "siret\|siren" src/lib/schemas/` → **aucun résultat** ; `grep -rn "registrationNumber" src/` → 8 occurrences, toutes le SIREN d'Axion-IA en JSON-LD, **aucun écrivain** de `Submission.registrationNumber`.
- Témoin négatif: le **même** payload additionné d'un `company.siren` valide à 9 chiffres rend `{"status":"created","subject_type":"company","subject_id":1}` avec une entreprise, une personne, une activité et deux tags (§2.1). Le chemin de création fonctionne donc parfaitement ; c'est son entrée qui est vide.
- Impact        : le canal ne peut créer aucune fiche automatiquement. Chaque lead — formulaire, RDV, avis, newsletter, simulateur — exige un geste humain d'arbitrage. Ceci **nomme la cause** de [A05-003], qui l'attribuait à un SIREN « rarement rempli » ; la conséquence pratique est que la voie (a) de son correctif (rendre `contacts.company_id` nullable) est la **seule** praticable, la voie « que le site envoie le SIREN » supposant d'abord de le **collecter**.
- Reproduction  : `docker cp` le harnais dans `axion-crm-api`, `php /tmp/harness.php siren` sur `axion_crm_audit13`.
- Correctif     : (a) lever l'étape 5 bis du plan (`contacts.company_id` NULLABLE) — chiffré ~1 j + fenêtre de verrou par [A05-003] ; (b) **en complément et à coût faible**, faire remonter les SIREN que le site détient déjà : `Client.siren` (`schema.prisma:5458`) et `Invoice.payerSiret` (`:2030`) — ~0,5 j côté site ; (c) ajouter un champ SIREN facultatif au formulaire unifié — ~2 h.
- Statut        : ouvert

### [B13-002] Un lead avec SIREN mais sans nom de famille est accepté 200, et son adresse électronique est détruite
- Sévérité      : S1 grave
- Domaine       : canal / conformité
- Référence     : `main c0c453d`
- Emplacement   : `backend/app/Crm/Ingest/ContactUpserter.php:82-90` · `backend/app/Crm/Ingest/SiteSyncIngestService.php:553-585`
- Constat       : quand l'entreprise est créée mais que `person.last_name` est absent, `ContactUpserter` renonce à créer la fiche personne et le bloc `pending_match` — qui aurait conservé l'adresse — n'est pas écrit, car il est conditionné à `subjectId === null`.
- Preuve        : `04_PREUVES/agent-13/02_….txt` §R6.1 — `HTTP 200 {"status":"created","subject_type":"company","subject_id":6}` ; contacts de l'entreprise → `(AUCUN CONTACT)` ; payload de l'activité → `{"message":"bonjour","source_slug":null,"subject_ref":"site:submission:r61"}` ; `select count(*) from contacts where email ilike '%perdu@example.com%'` → **0** et idem sur `activities` → **0**.
- Témoin négatif: le **même** événement sans SIREN conserve tout — `pending_match` contient `email`, `phone`, `first_name`, `last_name`, `denomination`, `city`, `consent_at`, `consent_version` (§3.1, sortie S1.1). La conservation existe donc et fonctionne ; elle est seulement débranchée dans ce cas.
- Impact        : le CRM répond **200 `created`**, donc l'outbox du site solde la ligne et ne rejouera jamais (`emit.ts`). Le lead est définitivement injoignable, sans qu'aucun écran ni journal ne porte l'adresse perdue. La perte est silencieuse et irréversible.
- Reproduction  : émettre un `form_submission` avec `company.siren` valide, `person.email` renseigné et `person.last_name` absent.
- Correctif     : écrire `payload.pending_match` **dès que `contactId === null`**, et non seulement quand `subjectId === null` — 3 lignes dans `recordActivity()` + un test. Coût ~1 h. À défaut, rendre l'issue explicite par un statut `IngestOutcome::PERSON_DROPPED` que le site puisse compter.
- Statut        : ouvert

### [B13-003] La déduplication par adresse électronique échoue en production (locale `C`) et réussit en CI (`en_US.utf8`)
- Sévérité      : S2 défaut
- Domaine       : backend / canal
- Référence     : `main c0c453d`
- Emplacement   : `backend/app/Crm/Ingest/ContactUpserter.php:69` · `backend/app/Crm/Ingest/SiteSyncIngestService.php:314`
- Constat       : la requête de rapprochement compare `lower(email::text)` calculé par Postgres à une valeur normalisée par `mb_strtolower()` en PHP, or `lower()` en locale `C` n'abaisse pas les majuscules accentuées alors que `mb_strtolower()` le fait.
- Preuve        : `04_PREUVES/agent-13/03_locale-journal-idempotence.txt` — `datcollate | datctype` → `C | C` ; `lower('JOSÉ.MARTIN@EX.COM' COLLATE "C")` → `josÉ.martin@ex.com` ; `COLLATE "en_US.utf8"` → `josé.martin@ex.com` ; égalité avec la sortie de `mb_strtolower` → **`f`**. Geste joué (`02_….txt` §R3.3) : fiche `ZOÉ.TEST@example.com` en base + ingestion de `Zoé.Test@example.com` sous un nom différent → **`created`**, deux fiches pour une personne.
- Témoin négatif: le contrôle jumeau sur une adresse **ASCII** (`ANA.TEST@example.com` + `Ana.Test@example.com`, §R3.4) rend **`updated`** et fusionne. La voie e-mail sait donc rapprocher ; elle échoue précisément sur l'objet accentué.
- Impact        : doublons de personnes sur les fiches dont l'adresse a été saisie ailleurs qu'au canal (collecte, import, saisie console) et porte une majuscule accentuée. Surtout : **la CI ne peut pas faire rougir ce défaut**, sa locale est `en_US.utf8` — le test serait vert sur un comportement que la production n'a pas (piège 10 + piège 19).
- Reproduction  : voir la preuve ; deux fiches de la même entreprise, l'une accentuée, l'autre non.
- Correctif     : remplacer `whereRaw('lower(email::text) = ?', …)` par une comparaison qui ne dépend pas de la locale — `where('email', $email)` suffit, `contacts.email` étant de type **`citext`** (mesuré) ; ou `lower(email::text COLLATE "und-x-icu")`. Deux appels à changer. Ajouter un test portant une adresse accentuée. Coût ~1 h.
- Statut        : ouvert

### [B13-004] Un événement refusé ne laisse aucune trace exploitable : ni file morte, ni motif persisté, ni alerte
- Sévérité      : S2 défaut
- Domaine       : canal / conformité
- Référence     : `main c0c453d`
- Emplacement   : `backend/app/Http/Controllers/Internal/SiteSyncController.php:49-91` · `backend/app/Crm/Ingest/SiteSyncRejection.php` · `backend/app/Http/Middleware/AuditHashChainLogger.php:36-48`
- Constat       : un refus n'existe que comme exception et comme ligne `Log::warning` ; aucune table du schéma ne conserve l'événement refusé, et la ligne d'audit ne porte ni son identifiant, ni son motif, ni son contenu.
- Preuve        : inventaire des 116 tables de `axion_crm_audit13` — aucune ne correspond à `%rejet%`, `%reject%`, `%dead%`, `%nonce%`, `%outbox%`. Après 26 refus joués : `select count(*) from activities where external_ref ilike '%p4-%'` → **0**. Colonnes réelles d'`audit_logs` : `id, workspace_id, user_id, event_type, path, status_code, ip, user_agent, payload_hash, prev_hash, current_hash, created_at` — le contenu n'est présent que comme sha256. Répartition mesurée : `200|15, 401|7, 422|18, 503|1`.
- Témoin négatif: la même chaîne d'audit **sait** enregistrer, et elle enregistre bien les refus (26 lignes ≥ 400) — le journal n'est donc pas muet, il est **sans objet exploitable**. Et pour les événements **acceptés**, la trace est complète (`activities` porte `kind`, `occurred_at`, `person_key`, `external_ref`, `payload`) : le dépôt sait faire.
- Impact        : un 422 est définitif côté site (`gave_up`, `emit.ts:110-123`). Une vague de refus — un texte de consentement v3 servi par le site, un `form_type` ajouté d'un seul côté, un champ nouveau — perdrait 100 % des leads concernés **sans que rien ne le signale et sans possibilité de rejeu**, puisque le contenu n'est nulle part. C'est le scénario `simulateur_roi` décrit en commentaire du code, mais sans le témoin qui a permis de le voir.
- Reproduction  : jouer un événement à `form_type` inconnu ; chercher ensuite l'événement dans le schéma.
- Correctif     : une table `site_sync_rejections` (`event_id`, `error_code`, `status`, `payload` chiffré, `received_at`, `resolved_at`) alimentée dans le `catch` du contrôleur, plus un compteur dans la console à côté de la file d'arbitrage qui existe déjà. Coût ~0,5 j. À défaut et à coût quasi nul : ajouter `error_code` et `event_id` aux métadonnées d'`audit_logs`.
- Statut        : ouvert

### [B13-005] Un tag de provenance hors référentiel est perdu en silence, avec une réponse 200
- Sévérité      : S2 défaut
- Domaine       : canal
- Référence     : `main c0c453d`
- Emplacement   : `backend/app/Crm/Ingest/SiteSyncIngestService.php:405-427` (`resolveTagId()` rend `null`) et `:373-378` (`continue`)
- Constat       : un tag dont le namespace est gouverné mais dont la valeur n'appartient ni au référentiel versionné ni à un namespace dérivable est ignoré sans journal ni avertissement, et l'événement est accepté.
- Preuve        : `04_PREUVES/agent-13/02_….txt` §R4.3 — `source_slug = "qualiopi-portail"` → **HTTP 200 `updated`**, `select count(*) from tags where slug='src:qualiopi-portail'` → **0**, tags posés sur l'entreprise → `svc:audit` seul, **aucun tag de provenance**. §R4.4 — `tags = ["taille:micro","taille:pme"]` → **HTTP 200**, tags posés → `src:site-formulaire-audit, svc:audit, taille:pme` : `taille:micro` a disparu.
- Témoin négatif: joué (`04_PREUVES/agent-13/04_temoins-negatifs.txt`) — dans la même requête, `taille:pme` (présent au référentiel `GovernedTagsSeeder:78`) **est** posé ; et un tag de namespace **non** gouverné, `toto:inconnu`, est refusé bruyamment : **HTTP 422** `{"error":"ungoverned_tag_namespace","message":"Namespace de tag hors référentiel gouverné : « toto »."}`. Le mécanisme sait donc à la fois poser et refuser ; c'est exactement la bande intermédiaire — namespace gouverné, valeur inconnue — qui est silencieuse : `taille:micro` seul rend **HTTP 200 `created`** avec `tags: ["src:site-formulaire-audit","svc:audit"]`, le tag demandé ayant disparu sans un mot.
- Impact        : le tag `src:` est la seule trace de la provenance d'un lead — la donnée sur laquelle repose le pilotage par canal d'acquisition. Un slug ajouté côté site sans l'être au `GovernedTagsSeeder` produit des fiches sans origine, et rien ne le dit. Le refus n'étant pas remonté, la comparaison entre les tags demandés et les tags rendus dans `IngestOutcome.tags` est la seule détection possible, et personne ne la fait.
- Reproduction  : émettre un événement avec `source_slug` absent du référentiel.
- Correctif     : journaliser en `Log::notice` chaque tag écarté avec son slug, et renvoyer un champ `tags_ignores` dans `IngestOutcome` pour que le site puisse le compter. Coût ~1 h. Le refus dur serait une erreur : il perdrait la fiche pour un tag.
- Statut        : ouvert

### [B13-006] L'unique point d'entrée des dates extérieures du canal décale de 2 h la date qui prouve le consentement, et la garde censée le voir ne mesure que sa propre fixture
- Sévérité      : S2 défaut *(approfondissement de [A05-008], qui porte la cause — ne pas compter deux fois)*
- Domaine       : canal / conformité
- Référence     : `main c0c453d`
- Emplacement   : `backend/app/Crm/Ingest/SiteSyncEvent.php:422-431` · `backend/config/database.php:102` · `backend/tests/Feature/NeDoitPasRegresserTest.php:398-406`
- Constat       : `parseDate()` ramène `occurred_at`, `consent.at` et `consent.vivier_at` dans `app.timezone`, ce qui n'est correct que si la session Postgres porte le même fuseau ; sans `DB_TIMEZONE`, l'instant stocké avance de 7 200 s, et la seule garde de non-régression vérifie la présence de la variable **dans `phpunit.xml`**, pas dans l'exécution réelle.
- Preuve        : `04_PREUVES/agent-13/01_….txt` §P5 — émis `occurred_at = 2026-08-17T10:00:00.000Z`, stocké `2026-08-17 12:00:00+00`, `extract(epoch from …)` → **`7200.000000`** ; émis `consent.at = 2026-08-17T09:59:00.000Z`, stocké `11:59:00+00`, écart **`7200.000000`**. Cause mesurée à la connexion (`04_….txt` §T2.3) : `SHOW TimeZone` sur la session de l'application → **`Etc/UTC`**, face à `config('app.timezone') = Europe/Paris`. `env | grep DB_TIMEZONE` dans `axion-crm-api` → vide ; `grep -rn DB_TIMEZONE *.yml` → aucune occurrence ; `grep -n DB_TIMEZONE backend/phpunit.xml` → `l.52 value="Europe/Paris"`.
- Témoin négatif: la sonde d'écart sait rendre zéro — jouée sur une paire volontairement égale (`04_….txt` §T2.2), `extract(epoch from (timestamptz '2026-08-17T10:00:00Z' - timestamptz '2026-08-17T10:00:00Z'))` → **`0.000000`**. Les `7200.000000` ne sont donc pas un artefact d'affichage de `psql` ni du fuseau de lecture. Et `HorodatagesFuseauTest` échouait bien sur « 7200.0 » avant le correctif du 16/08, d'après son propre en-tête.
- Impact        : `consent_at` est l'élément probant du consentement (art. 7-1 RGPD). Il est faux de 2 h dans tout environnement où `DB_TIMEZONE` n'est pas posée — c'est-à-dire l'atelier, et tout conteneur recréé à partir des seuls fichiers du dépôt, puisque **aucun `docker-compose*.yml` ne la pose**. [A05-008] atteste qu'elle est posée en production : l'effet ne mord donc pas la production aujourd'hui, et ce constat porte sur la **fragilité du dispositif**, pas sur un dommage actuel.
- Reproduction  : ingérer un événement daté en `Z` dans un environnement sans `DB_TIMEZONE`, relire la colonne.
- Correctif     : poser `DB_TIMEZONE: ${DB_TIMEZONE:-Europe/Paris}` dans les services `api`, `horizon` et `scheduler` du `docker-compose.yml` (correctif déjà proposé par [A05-008]) **et**, ce que j'ajoute, remplacer la garde de `NeDoitPasRegresserTest:398-406` par une assertion d'exécution : `SHOW TimeZone` sur la connexion doit valoir `config('app.timezone')`, sinon échec. Coût ~1 h.
- Statut        : ouvert

### [B13-007] `POST /api/internal/site-sync/gdpr` n'a ni version de schéma ni clé d'idempotence : une demande d'export est rejouable pendant 300 s
- Sévérité      : S3 finition
- Domaine       : canal / sécurité
- Référence     : `main c0c453d`
- Emplacement   : `backend/app/Http/Controllers/Internal/SiteGdprController.php:32` (`ALLOWED_KEYS`) et `:64-90`
- Constat       : le contrat de la route RGPD accepte exactement `action`, `person_key`, `email`, `scope` — sans `schema_version` ni identifiant de demande — alors que la route jumelle `/site-sync` impose les deux, si bien que la seule protection contre le rejeu est la fenêtre d'horodatage de 300 s.
- Preuve        : lecture de `ALLOWED_KEYS` (`:32`) et du site, `axionia/src/server/crm-sync/gdpr.ts:50-94`, qui construit `{ action, person_key, email, scope }` — **sans `schema_version`**, là où `crm-sync/types.ts:70` en impose un sur l'autre route. Mesuré sur `/site-sync` : une requête rejouée à l'identique dans la fenêtre est acceptée (**HTTP 200**, §R2.1) — c'est l'idempotence par `event_id` qui neutralise la duplication, mécanisme que cette route-ci n'a pas.
- Témoin négatif: sur `/site-sync`, le même rejeu rend `noop_idempotent` et ne produit qu'une activité (§2.2) : le dépôt sait faire de l'idempotence sur ce canal, avec le même secret et la même signature.
- Impact        : `erase` est naturellement idempotent, mais `export` **rend les données de la personne**. Une requête signée interceptée peut être rejouée pendant 5 minutes pour les obtenir à nouveau. L'exposition est faible (il faut casser TLS), le coût du correctif aussi. Accessoirement, l'absence de `schema_version` prive cette route de la seule manœuvre d'évolution non destructrice dont dispose l'autre.
- Reproduction  : capturer une requête `/site-sync/gdpr` signée et la rejouer sous 300 s.
- Correctif     : ajouter `schema_version` (obligatoire, `1`) et `request_id` à `ALLOWED_KEYS`, journaliser le `request_id` traité et rendre `noop_idempotent` s'il l'a déjà été. Les deux listes bougent ensemble avec `gdpr.ts`. Coût ~2 h.
- Statut        : ouvert

### [B13-008] `relation_type = 'fournisseur'` est inatteignable par le canal, alors que le site détient les fiches correspondantes
- Sévérité      : S3 finition
- Domaine       : canal
- Référence     : `main c0c453d`
- Emplacement   : `backend/app/Crm/Taxonomy.php:36-45` · `backend/app/Crm/Ingest/SiteSyncClassifier.php:52-69` · `axionia/prisma/schema.prisma:7781-7789`
- Constat       : `fournisseur` figure dans la liste fermée `BUSINESS_RELATION_TYPES` et dans le CHECK SQL, mais aucune branche de `relationType()` ne le rend, et le modèle `SousTraitant` du site — qui porte `contactEmail` — n'a aucun émetteur.
- Preuve        : les sept valeurs que `relationType()` peut rendre sont `newsletter`, `client`, `partenaire`, `presse_media`, `investisseur`, `conference`, `prospect` — lecture exhaustive du `match` (`:54-68`) ; `fournisseur` n'y est pas. En base : `activities_kind_check` et le CHECK de `companies.relation_type` portent bien les 8 valeurs. Côté site, `SousTraitant.contactEmail` (`schema.prisma:7789`) est écrit par `server/qualiopi/registres/sous-traitants-service.ts:54` et aucun `import … from "@/server/crm-sync"` n'existe dans ce répertoire.
- Témoin négatif: la même lecture montre que les sept **autres** valeurs sont toutes atteignables, chacune par une branche nommée — la liste n'est donc pas globalement morte, c'est cette valeur-là qui l'est. Et `presse_media`, atteignable par `form_type: presse`, prouve que le patron « un formulaire → un type de relation » fonctionne.
- Impact        : la console offre un filtre et un CHECK pour une catégorie qui ne peut jamais se peupler par le canal — un opérateur qui filtre sur `fournisseur` obtient toujours zéro et ne peut pas savoir si c'est un vide réel ou une tuyauterie absente. C'est une valeur de vocabulaire qui ment.
- Reproduction  : `select count(*) from companies where relation_type='fournisseur'` → 0, quel que soit le trafic entrant.
- Correctif     : soit ajouter un émetteur `SousTraitant` côté site avec un `form_type`/`source_slug` dédié et la branche correspondante (~0,5 j des deux côtés), soit documenter explicitement que `fournisseur` est réservé à la saisie manuelle en console. Le second choix coûte 10 min et supprime l'ambiguïté.
- Statut        : ouvert

---

*Sorties brutes, harnais et scénarios : `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/agent-13/`.*
*Base jetable `axion_crm_audit13` : détruite après archivage des preuves.*
