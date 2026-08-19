# AGENT 18 — Auditeur du pipeline de collecte

> Périmètre : le funnel d'ingestion, la déduplication, les campagnes et runs, le registre des
> sources, les 13 scrapers de `workers/src`, les mocks.
> Rapport écrit le 2026-08-19.

---

## 0. Référence et méthode

| | |
|---|---|
| Dépôt | `C:\Users\willi\Documents\Projets\Axion-CRM-Pro` |
| Référence donnée par le dossier | `main = c0c453d` |
| `HEAD` **relu** au début de ma session | `1145473` |
| `HEAD` **relu** à la rédaction | `e8924b8` |
| Mon périmètre a-t-il bougé ? | **Non.** `git diff --stat c0c453d..e8924b8 -- <mon périmètre>` rend **une sortie vide**. Tous mes constats valent donc indifféremment sur `c0c453d` et sur `e8924b8`. Preuve : `04_PREUVES/agent-18/00_reference.txt` |
| Base de travail | `axion_crm_a18`, **jetable**, créée et migrée pour cette mission (58 migrations). `datcollate=C`, `datctype=C` — **comme la production** |
| Production | **jamais touchée**. Aucune écriture, aucune lecture de secret |
| Collecte réelle | **aucune**. Aucun scraper lancé, aucune requête vers un site tiers |
| Worktree `crmpro-wt-etape1a` | **jamais approché** |
| Fichiers produit modifiés | **aucun**. Tous mes scripts vivent dans `/tmp` du conteneur et dans le scratchpad |

⚠️ **Condition de mesure à connaître pour me relire** : pendant toute la session la machine était
saturée par les autres agents (`/proc/loadavg` = **29,64**). `php artisan --version` a mis
**3 min 52 s**. J'ai donc groupé les mesures PHP en deux lots au lieu de les jouer une par une, et
j'ai basculé tout ce qui pouvait l'être sur `psql`, `redis-cli` et `node` directs. Cela n'altère
aucun résultat ; cela explique la forme des preuves.

---

## 1. Grille — une ligne par objet, une case par point

Légende : ✅ vérifié conforme · ⚠️ vérifié, défaut mesuré · ❌ vérifié, cassé · ⬜ non vérifié (raison donnée)

### 1.a — Le funnel d'ingestion

| Objet | Existe | Branché | Testé | Défaut mesuré | Constat |
|---|---|---|---|---|---|
| `ScrapedRecordIngestService` (745 l.) | ✅ | ⚠️ derrière `CRM_SCRAPE_FUNNEL_ENABLED=false` **par défaut** | ✅ `tests/Feature/Crm/ScrapedIngestTest.php`, 30 tests | Le drapeau à OFF fait répondre `{"ingested": true}` à un message qui n'est **rien** ingéré | **C18-001** |
| `ScrapedRecord` (17 456 o.) | ✅ | ✅ | ✅ schéma pivot strict, champ inconnu = 422 | rien | — |
| `ScrapeIngestOutcome` | ✅ | ✅ | ✅ | **aucun compteur pour `skipped`** : une personne écartée n'est comptée nulle part | **C18-002** |
| `ScrapeIngestRejection` | ✅ | ✅ | ✅ 422 / 503 mesurés par le test existant | rien | — |
| `DryRunRollback` | ✅ | ✅ | ✅ (le test existant ne compte que 4 tables) | **0 ligne** laissée sur **114 tables**. Mais **3 séquences consommées** | **C18-003** (S3) |
| `EmailMxValidator` | ✅ | ✅ | ✅ (désactivé en test par `validate_mx=false`) | Un `--dry-run` fait de **vrais appels DNS** (`checkdnsrr`) : la trace hors base existe | **C18-003** |
| `/internal/scraper-result` | ✅ | ✅ | ✅ | Secret HMAC vide par défaut des deux côtés — **déjà relevé par l'agent 12** (`routes-entete.md:174`), je ne le re-rapporte pas | — |

### 1.b — Déduplication

| Objet | Existe | Branché | Testé | Défaut mesuré | Constat |
|---|---|---|---|---|---|
| `DeduplicationService` — niveau 1 (SIREN) | ✅ | ✅ | ✅ | Contrainte SQL `UNIQUE(workspace_id, siren)` : sûr | — |
| — niveau 2 (`normalized_hash`) | ✅ | ⚠️ | ✅ `tests/Unit/Dedup/DeduplicationServiceTest.php` | `computeContactHash()` **diverge** de la colonne `GENERATED` sur les particules et les espaces de bordure | **C18-004** |
| — niveau 3 (TTL par source) | ✅ | ✅ registre lu à chaque appel | ✅ | Repli code si la table manque : correct | — |
| — niveau 4 (cooldown zone) | ✅ | ✅ | ✅ `tests/Feature/Coverage/CooldownZoneTest.php` | rien | — |
| — niveau 5 (cache validation email) | ✅ | ✅ | ⬜ non joué : hors chemin de collecte, l'agent chargé de l'e-mail le couvre | — | — |
| — niveau 6 (opt-out) | ✅ | ✅ | ✅ | `isOptedOut()` empile deux `orWhere` **sans clause de base** ; sûr uniquement grâce au retour anticipé l. 208 | **C18-005** (S3) |
| Dédup e-mail du funnel (`upsertContact`) | ✅ | ✅ | ⚠️ | **Piège 10 joué et confirmé** : `lower()` SQL ≠ `mb_strtolower` PHP sous `lc_ctype=C` | **C18-006** |
| Dédup e-mail **inter-entreprises** | ✅ | ✅ | ❌ aucun test | Une personne rattachée à l'entreprise B est **perdue** si son adresse existe déjà sur l'entreprise A | **C18-002** |

### 1.c — Campagnes, runs, arrêt d'urgence

| Objet | Existe | Branché | Testé | Défaut mesuré | Constat |
|---|---|---|---|---|---|
| `ScrapingCampaign` (modèle) | ✅ | ✅ | ✅ `tests/Feature/CampaignsTest.php` | `shouldAutoPause()` s'appuie sur `companies_created`, remis à 0 par le moniteur | **C18-007** |
| `Api/ScrapingCampaignsController` | ✅ | ✅ | ✅ | `cancel()` ne purge **pas** la file Redis | **C18-008** |
| `Api/ScraperRunsController` | ✅ | ✅ | ✅ `tests/Feature/ScraperRunsCancelRetryTest.php` | Le drapeau `cancelled:scraper-run:*` n'est **lu par personne** | **C18-008** |
| `LaunchCampaignJob` | ✅ | ✅ | ✅ | Sources Node nommées `google_maps`/`pages_jaunes` (souligné) là où tout le reste du système emploie le tiret | **C18-009** (S3) |
| `LaunchZoneScrapingJob` | ✅ | ✅ | ✅ | Marque le run `success` pour une source Node **sans avoir rien collecté** ; le `run_id` envoyé au worker n'a aucun rapport avec l'`id` du `ScraperRun` | **C18-010** |
| `MonitorCampaignProgressJob` | ✅ | ✅ | ✅ | Recalcule `companies_created` par `COUNT(DISTINCT company_id)` sur des runs dont le `company_id` est **NULL** → **400 → 0**, mesuré | **C18-007** |
| `DispatchScrapeJob` | ✅ | ✅ | ⬜ aucun test | Écrit une clef Redis **préfixée** que le worker ne lit jamais | **C18-011** |
| Reprise après panne | — | — | ⬜ partiellement | `base.ts` remet en file un job tiré pendant un arrêt ; côté Laravel un run `running` orphelin **reste `running` pour toujours** | **C18-012** |

### 1.d — Éligibilité, sentinelle, registre

| Objet | Existe | Branché | Testé | Défaut mesuré | Constat |
|---|---|---|---|---|---|
| `EligibiliteCampagne` | ✅ | ✅ | ✅ `tests/Feature/EligibiliteCampagneTest.php` | Définition calculée, jamais figée : correct. Mais **aucun motif d'exclusion n'est exposé** — l'écran ne peut pas dire *pourquoi* une fiche est écartée | **C18-013** (S2) |
| `WaterfallSentry` | ✅ | ✅ 11 sites de capture | ⬜ aucun test | La classe `Sentry\State\Hub` **existe** (le paquet est installé) donc la garde no-op ne joue pas — mais `SENTRY_LARAVEL_DSN` n'est déclaré **nulle part** | **C18-014** (voir aussi agent-16) |
| `scraping_sources` (table) | ✅ | ✅ 17 lignes | ✅ | Table globale, sans RLS : assumé et documenté | — |
| `ScrapingSourcesSeeder` | ✅ | appelé par **2 migrations** | ✅ | **Piège 22 joué** : `enabled` est protégé, `ttl_days`/`name`/`legal_note`/`quota_per_day` ne le sont **pas** | **C18-015** (S3) |

### 1.e — Les mocks

| Objet | Existe | Peut-il s'armer en production ? | Testé | Constat |
|---|---|---|---|---|
| `MockServicesProvider` (14 liaisons) | ✅ | **OUI — aucune garde d'environnement, et le défaut est le mock** | ⬜ aucun test ne vérifie le câblage réel | **C18-016** |
| 6 mocks Node (`workers/src/mocks/`) | ✅ | **Non** — l'expression échoue *vers* le mock, jamais vers le réel | ❌ aucun test | **C18-017** |
| `_stub.ts` | ✅ | — | ❌ | **Code mort** : `stubWorker()` n'est importé nulle part | **C18-017** |
| `MOCKS-STRATEGY.md` | ✅ | — | — | Le document promet « basculement en 1 ligne `MOCK_MODE=false` ». C'est vrai côté PHP, **faux côté Node** : il faut `MOCK_SCRAPERS=false` exactement, en minuscules | **C18-016** |

### 1.f — Les 13 scrapers de `workers/src`

Colonnes : **Fichiers** · **Branché** (présent dans le `REGISTRY` de `main.ts`) · **Déployé** (service `docker-compose`) · **A tourné** · **Testé** · **Mort ?**

| Scraper | Fichiers (lignes) | Branché | Déployé | A déjà tourné | Testé | Verdict |
|---|---|---|---|---|---|---|
| `google-maps` | `.worker.ts` (10) + `.playwright.ts` (56) | ✅ `REGISTRY` | ❌ retiré de `docker-compose.yml` le 2026-08-14 | ❌ file Redis vide, aucun `scraper_runs` local | ❌ aucun test n'importe l'implémentation | **MORT** (code vivant, exécution nulle) |
| `pages-jaunes` | `.worker.ts` (10) + `.playwright.ts` (45) | ✅ | ❌ | ❌ | ❌ | **MORT** |
| `website` | `.worker.ts` (10) + `.playwright.ts` (71) | ✅ | ❌ | ❌ | ❌ | **MORT** |
| `google-search` | `.worker.ts` (10) + `.playwright.ts` (78) | ✅ | ❌ | ❌ | ❌ | **MORT** |
| `direction-finder` | `.worker.ts` (10) + `.playwright.ts` (118) | ✅ | ❌ | ❌ | ❌ | **MORT** |
| `france-travail` | `.worker.ts` (10) → `http-source.ts` | ✅ | ❌ | ❌ | ❌ | **MORT** côté Node (la source vit côté PHP via `FranceTravailDiscoveryClient`) |
| `mesri` | `.worker.ts` (10) → `http-source.ts` | ✅ | ❌ | ❌ | ❌ | **MORT** |
| `crunchbase` | `.worker.ts` (10) → `http-source.ts` | ✅ | ❌ | ❌ | ❌ | **MORT** |
| `infogreffe` | `.worker.ts` (10) → `http-source.ts` | ✅ | ❌ | ❌ | ❌ | **MORT** |
| `societe-com` | `.worker.ts` (10) → `http-source.ts` | ✅ | ❌ | ❌ | ❌ | **MORT** |
| `social-light` | `.worker.ts` (10) → `http-source.ts` | ✅ | ❌ | ❌ | ❌ | **MORT**, et son URL réelle est `https://api.example-social.com/…` — **un domaine d'exemple** |
| `http-source` | `http-source.ts` (92) | ✅ (via 6 workers) | ❌ | ❌ | ❌ | inerte |
| `base` | `base.ts` (252) | ✅ (moteur commun) | ❌ | ❌ | ✅ **16 tests** | vivant en test, jamais en production |
| `_stub` | `_stub.ts` (10) | ❌ **aucun import** | ❌ | ❌ | ❌ | **CODE MORT ABSOLU** |

**Bilan : 11 workers démarrables, 0 déployé, 0 testé au niveau de l'implémentation, 1 fichier
totalement mort.** Les 61 tests de `workers/` (6 fichiers, tous verts, rejoués le 2026-08-19)
couvrent `base`, `extract`, `queues`, `result-sender`, `ssrf-guard` — **aucun scraper**.

---

## 2. Réponse aux 10 points de la mission

### ① Le parcours d'une fiche, de bout en bout — et où elle se perd en silence

```
[worker Node]  scrape() ──► base.ts:consumeLoop ──► sendResult()
                                                        │  POST /internal/scraper-result
                                                        │  X-Worker-Signature = HMAC(secret)
                                                        ▼
                                          ScraperResultController::store()
                                            ├─ ⑴ HMAC faux              → 401
                                            ├─ ⑵ CRM_SCRAPE_FUNNEL_ENABLED=false (DÉFAUT)
                                            │        → Log::info + 200 {"ingested": true}   ◄── PERTE #1
                                            └─ ⑶ drapeau ON → ScrapedRecord::fromArray()
                                                        │  champ inconnu → 422
                                                        ▼
                                          ScrapedRecordIngestService::ingest()
                                            ├─ source hors registre        → 422
                                            ├─ source coupée               → 503
                                            ├─ run déjà ingéré             → noop_idempotent
                                            ├─ status=failed               → scraper_runs seul
                                            ├─ ni SIREN ni foreign_id      → pending_match ◄── file d'arbitrage
                                            └─ upsertCompany() (backfill-only)
                                                 └─ pour chaque personne :
                                                      ├─ kind=service_mailbox → email générique entreprise
                                                      ├─ last_name manquant   → 'skipped'  ◄── PERTE #2 (muette)
                                                      ├─ opposition (hash)    → 'opted_out' (compté)
                                                      ├─ MX non résolvable    → 'bad_mx'    (compté)
                                                      ├─ e-mail déjà vu sur
                                                      │  UNE AUTRE entreprise → 'skipped'  ◄── PERTE #3 (muette)
                                                      └─ sinon INSERT contacts
```

**Trois pertes silencieuses**, mesurées :

- **PERTE #1** — le drapeau `CRM_SCRAPE_FUNNEL_ENABLED` vaut **`false` par défaut**
  (`backend/config/crm.php:104`), et dans ce cas l'endpoint répond `{"ingested": true}` alors que
  **rien n'est ingéré**. Le producteur ne peut pas distinguer « ingéré » de « jeté ». → **C18-001**
- **PERTE #2 et #3** — `upsertContact()` rend `'skipped'` dans deux cas, et `ScrapeIngestOutcome`
  **n'a aucun compteur pour `'skipped'`**. Le rapport d'ingestion affiche `contacts_created: 0`
  sans autre indication. → **C18-002**

### ② Déduplication — sur quelle clé, et le piège 10

| Niveau | Clé | Casse | Accents | Espaces |
|---|---|---|---|---|
| Entreprise FR | `(workspace_id, siren)`, contrainte SQL | n/a | n/a | n/a |
| Entreprise étrangère | `(workspace_id, country_code, foreign_id)`, index unique | sensible | sensible | sensible |
| Personne — clé 1 | e-mail, via `lower(email::text) = ?` | ✅ ASCII | ❌ **casse** | ✅ (`trim` PHP) |
| Personne — clé 2 | `normalized_hash` `GENERATED` = `sha256(normalize_name(prénom_nom)_company_id)` | ✅ | ✅ (`unaccent` **avant** `lower`) | ❌ **espace de tête** |

**Piège 10 joué** (`04_PREUVES/agent-18/02_locale_lower_normalize.txt`) :

```
 datcollate | datctype        -- la base locale est en C, comme la production
------------+----------
 C          | C

 lower('Elodie@Exemple.FR') = elodie@exemple.fr      ← ASCII : correct
 lower('Élodie@Exemple.FR') = Élodie@exemple.fr      ← le É SURVIT
 mb_strtolower (PHP)        = élodie@exemple.fr
 egal ?                     = f
```

Deux conséquences distinctes :

1. **`normalize_name()` est immunisée** : elle applique `unaccent()` **avant** `lower()`, donc elle
   ne travaille jamais que sur de l'ASCII. Mesuré : `casse_ok = t`, `accent_ok = t`. En revanche
   `espace_avant_ok = f` — elle ne fait aucun `trim`.
2. **La dédup e-mail du funnel ne l'est pas** : `ScrapedRecordIngestService.php:435` compare un
   `lower()` SQL à un `mb_strtolower` PHP. Mesuré en base : la requête exacte du funnel rend
   **0 ligne** sur un contact réellement présent. → **C18-006**

⚠️ **Correction au dossier commun.** Le piège 10 énonce « CI en `en_US.utf8`, prod en `C` ». C'est
**faux pour ce dépôt** : `.github/workflows/ci.yml:363` initialise Postgres avec
`POSTGRES_INITDB_ARGS="--encoding=UTF-8 --lc-collate=C --lc-ctype=C"`. **La CI est alignée sur la
production.** Le piège reste réel — mais sa cause n'est pas une divergence CI/prod, c'est que
`lower()` sous `C` ne connaît pas l'ASCII étendu, partout à l'identique. Le dépôt le sait déjà et
l'a figé par un test (`tests/Feature/Rgpd/EmpreinteSqlEtPhpTest.php`) — mais **ce test ne couvre
que `opt_out`**, pas la dédup e-mail du funnel.

### ③ `DryRunRollback` — zéro trace ?

**Mesuré sur les 114 tables** (`04_PREUVES/agent-18/01_dryrun_dedup_eligibilite.txt`) :

```
outcome={"status":"created","company_id":3,…,"tags":["src:scraping-mentions-legales"],"activity_id":9}
  => AUCUNE des 114 tables modifiee
    SEQ companies_id_seq      '2' -> '3'
    SEQ scraper_runs_id_seq   '8' -> '9'
    SEQ activities_id_seq     '8' -> '9'
  => 3 sequence(s) consommee(s)
```

**Témoin négatif** (le même appel sans `dryRun`) :

```
    activities    4 -> 5 (+1)
    companies     1 -> 2 (+1)
    company_tag   1 -> 2 (+1)
    scraper_runs  4 -> 5 (+1)
  => 4 table(s) modifiee(s)
```

Le contrôle sait donc voir des lignes quand il y en a. **Verdict : zéro ligne, sur toutes les
tables — pas seulement `companies`.** Le test existant du dépôt n'en vérifiait que 4 ; ma mesure
étend à 114 et confirme.

**Deux traces subsistent néanmoins, hors lignes** — c'est le point ③ complet :

- **3 séquences consommées** (`companies_id_seq`, `scraper_runs_id_seq`, `activities_id_seq`).
  Postgres ne rejoue jamais une séquence : un `--dry-run` creuse un trou dans les identifiants.
  Sans gravité fonctionnelle, mais un « zéro trace » strict est faux.
- **Des requêtes DNS réelles.** `EmailMxValidator::isDeliverable()` appelle `checkdnsrr()` dès que
  `crm.scrape_funnel.validate_mx` vaut `true` — c'est-à-dire **par défaut**. Un essai à blanc
  interroge donc le DNS pour chaque domaine d'e-mail du lot. → **C18-003**

### ④ Arrêt d'urgence — existe-t-il, et arrête-t-il quelque chose ?

**Il existe. Il n'arrête rien du côté collecteur.** Trois mesures :

**Mesuré** (`04_PREUVES/agent-18/10_lot2_mocks_perte_annulation.txt`, section [C]) : après
annulation, le run passe bien à `cancelled` en base, **et la file Redis contient toujours ses
4 jobs**.

1. **Le drapeau Redis n'est lu par personne.**
   `ScraperRunsController.php:99` et `ScrapingCampaignsController.php:315` écrivent
   `Redis::setex('cancelled:scraper-run:'.$id, 3600, '1')`, avec ce commentaire :
   *« Flag Redis lu par les workers Node (BullMQ) pour interrompre le job en cours. »*
   Or `grep -rniE 'cancel|annul' workers/src/` rend **aucune occurrence** — 7 fichiers,
   1 363 lignes. **Témoin négatif** : le même `grep` trouve bien `cancel` ailleurs (il a été
   validé sur `backend/`). Le drapeau est écrit dans le vide.
2. **Il n'atterrit même pas au bon endroit.** `Redis::setex` emploie la connexion **`default`**
   (`REDIS_DB=0`) **et applique le préfixe**, tandis que les jobs vivent sur la connexion `queue`
   (`REDIS_QUEUE_DB=1`) et que le worker lit `WORKER_REDIS_URL=redis://redis:6379/**1**` sans
   préfixe. Relevé sur le Redis de l'atelier après avoir rejoué la méthode :

   ```
   redis-cli -n 0 --scan --pattern '*cancelled:scraper-run*'
   → axion_crm_pro_database_cancelled:scraper-run:14
     axion_crm_pro_database_cancelled:scraper-run:13   … (7 clefs)
   ```
   Base 0, préfixées. Le worker ne peut voir **ni** le drapeau **ni** les jobs.
3. **La file n'est pas purgée.** `cancel()` passe les `scraper_runs` à `cancelled` en base et
   s'arrête là. Les messages déjà déposés dans `axion:scrape:<source>` y restent.

**Ce qui s'arrête réellement** : la ligne en base change de statut, l'écran se rafraîchit
(`ScraperRunCancelled` diffusé), et `LaunchCampaignJob` / `MonitorCampaignProgressJob` s'auto-
annulent au prochain tour car ils re-testent `status !== 'running'`. C'est un arrêt **de
l'orchestration Laravel**, pas un arrêt **de la collecte**. → **C18-008**

⬜ **Je n'ai pas pu jouer les deux `POST` par HTTP** : les quatre appels ont expiré
(`HTTP 000` après 30 s, `04_PREUVES/agent-18/07_endpoints_annulation.txt`) — l'API était saturée.
J'ai donc rejoué **le corps exact des deux méthodes** en PHP (lot 2) plutôt que de conclure sans
mesure. La preuve porte donc sur la logique, pas sur le transport.

### ⑤ Reprise après panne

| Situation | Comportement mesuré | Verdict |
|---|---|---|
| Worker Node tué par `SIGTERM` | `base.ts:installerSignaux` pose le drapeau d'arrêt, remet en file un job tiré mais non traité (`lpush`), attend 25 s les jobs en cours. **Couvert par 16 tests** | ✅ correct |
| Worker Node tué par `SIGKILL` | Le job en vol est **perdu** — sorti de Redis, jamais renvoyé. Aucun ack, aucune file de reprise | ⚠️ inhérent au protocole `BRPOP` nu |
| Job en échec | `base.ts:180` réempile jusqu'à `max_attempts` (défaut 3), puis émet `status: failed` | ✅ |
| `LaunchZoneScrapingJob` interrompu | `tries = 2` : Laravel le rejoue. Mais un **nouveau** `ScraperRun` est créé à chaque tentative (l. 55), et `Company::updateOrCreate` dédoublonne les fiches | ⚠️ runs en double, fiches non dupliquées |
| Conteneur `api` redémarré avec un run `running` | **Rien ne le reprend et rien ne l'expire.** Le run reste `running` pour toujours ; il compte dans `runs_total` mais jamais dans `runs_completed`, donc la campagne ne passe **jamais** `completed` | ❌ **C18-012** |
| Ingestion rejouée | ✅ **idempotente** — `scraper_runs.dedup_key = pivot:<source>:<run_id>`, testée | ✅ |

**Duplique-t-il ?** Non pour les fiches (SIREN / `foreign_id` / `dedup_key`). Oui pour les
**lignes de run** : chaque relance en crée une nouvelle.

### ⑥ `EligibiliteCampagne` — qui est éligible, qui est écarté, est-ce visible ?

**Éligible** = `email_generic IS NOT NULL` **ET** `deleted_at IS NULL` **ET** absent de `opt_out`
**ET** absent de `email_suppressions` (comparaison sur l'empreinte SHA-256 seule, portée
`business`). Le **palier de confiance** (A/B/C) n'entre volontairement pas dans l'éligibilité :
c'est un paramètre de ciblage, explicite.

**Écarté** = tout le reste, et `appliquerNonEligibles()` ne rend **que** le cas « pas d'adresse ».

**Est-ce visible ? Partiellement, et c'est le défaut.** Mesuré : la classe expose
`appliquer`, `appliquerContacts`, `exclureOpposes`, `peutRecevoir`, `appliquerNonEligibles`.
Aucune ne rend un **motif**. Une fiche pourvue d'une adresse mais opposée sort de `appliquer()`
**et** de `appliquerNonEligibles()` : elle n'apparaît dans aucune des deux listes. L'écran ne peut
donc pas répondre à « pourquoi cette fiche n'est-elle pas contactable ? » — la seule question que
pose un opérateur devant un compteur qui a baissé. → **C18-013**

`peutRecevoir()` fonctionne : mesuré `true` avant opposition, `false` après.

### ⑦ `WaterfallSentry` — que surveille-t-il, a-t-il déclenché ?

**Ce qu'il surveille** : 11 points de capture, tous dans un `catch (\Throwable)` qui **avale**
l'exception (log `warning` + `recordRun(…, 'failed')`, jamais de `throw`) —
`domain-finder`, `mentions-legales`, `google-places`, `email-finder`, `llm-classify`,
`auto-classify`, `auto-tag`, `triage-auto`, `auto-segment` (`WaterfallOrchestrator`) et
`audience-refresh-chunk` (`RefreshAudienceChunkJob`).

**Sa garde ne joue pas** : `class_exists(\Sentry\State\Hub::class)` est **vrai** —
`sentry/sentry-laravel ^4.10` est dans `composer.json` et `backend/vendor/sentry` existe. Le
no-op annoncé « si Sentry n'est pas installé » ne se déclenche donc jamais.

**Mais rien ne part** : `backend/config/sentry.php:12` lit `SENTRY_LARAVEL_DSN`, et cette clé
n'est présente **ni** dans `.env`, **ni** dans `backend/.env`, **ni** dans `.env.example`, **ni**
dans `infra/scripts/configure-prod-env.sh`. Sans DSN, `Sentry\captureException()` est inerte.

**A-t-il déjà déclenché ?** ⬜ **Je ne peux pas le savoir** : il faudrait accéder au projet
GlitchTip/Sentry, ce qui est hors de mon périmètre et interdit sur la production. Ce que je peux
affirmer, mesuré : **sur cette installation, il ne peut rien émettre.**

⚠️ L'agent 16 a déjà relevé le DSN absent (`agent-16_audit-ai-act.md:216`). Je n'ouvre pas de
doublon : **C18-014** n'ajoute que la portée *waterfall* — 11 sites, tous dans un `catch` qui
avale, donc **11 pannes silencieuses possibles** dont il ne reste que des `Log::warning`.

### ⑧ 🔴 Les mocks — un mock peut-il fuir en production ?

**RÉPONSE : OUI côté PHP. NON côté Node.**

#### Côté PHP — `MockServicesProvider`

**La condition qui l'arme, intégralement :**

```php
// backend/app/Providers/MockServicesProvider.php:57
$master = (bool) env('MOCK_MODE', true);
```

**Trois faits mesurés** (`04_PREUVES/agent-18/08_mocks_production.txt`) :

1. **Aucune garde d'environnement.** Le fichier ne contient ni `environment(`, ni `isProduction`,
   ni `App::isLocal`. Mesuré par lecture programmatique du source. `APP_ENV=production`
   ne change **rien**.
2. **Le défaut est le mock.** Mesuré : `env('A18_CLEF_ABSENTE_XYZ', true)` rend `true`, et
   `(bool) true` = `true` → **branche mock**. Une variable **absente** suffit donc à mettre toute
   la production sur les mocks.
3. **Une seule variable d'environnement suffit**, et sa sémantique n'est pas celle qu'on croit.
   Mesuré valeur par valeur, avec de **vraies** variables d'environnement du processus :

   ```
   ABSENT   -> env()=true    (bool)=true   -> MOCK     ← le défaut
   'false'  -> env()=false   (bool)=false  -> REEL
   'FALSE'  -> env()=false   (bool)=false  -> REEL     ← Laravel est insensible à la casse
   '0'      -> env()='0'     (bool)=false  -> REEL
   ''       -> env()=''      (bool)=false  -> REEL
   'off'    -> env()='off'   (bool)=true   -> MOCK     ← piège
   'no'     -> env()='no'    (bool)=true   -> MOCK     ← piège
   ```

   Côté PHP la bascule est donc **plus tolérante** que je ne le supposais : `FALSE`, `0` et la
   chaîne vide arment bien le réel. Restent deux entrées dangereuses : **l'absence** et les
   valeurs `off` / `no`, qu'un exploitant écrit naturellement pour « désactiver les mocks ».

4. 🔴 **Et les deux moitiés du même interrupteur ne sont pas d'accord.** Confrontation des deux
   mesures (`08_mocks_production.txt`) :

   | valeur | PHP `(bool) env()` | Node `(…) !== 'false'` | |
   |---|---|---|---|
   | ABSENT | MOCK | MOCK | cohérent |
   | `'false'` | RÉEL | RÉEL | cohérent |
   | `'FALSE'` | **RÉEL** | **MOCK** | ⚠️ divergent |
   | `'0'` | **RÉEL** | **MOCK** | ⚠️ divergent |
   | `''` | **RÉEL** | **MOCK** | ⚠️ divergent |
   | `'off'` / `'no'` | MOCK | MOCK | cohérent |

   Un `MOCK_SCRAPERS=FALSE` posé de bonne foi met donc le **backend en réel** et les **workers en
   mock** simultanément. C'est le pire des états : le CRM croit collecter et reçoit des charges
   vides marquées `status: 'success'`.

**Le chemin de fuite, concrètement.** Si `MOCK_MODE` disparaît du `.env` serveur — un
`configure-prod-env.sh` rejoué partiellement, un `.env` reconstruit depuis `.env.example`
(qui porte `MOCK_MODE=true` en **ligne 7**), une clef écrasée — alors :

- `MockLLMClient` remplace `LLMRouterService`. Or `WaterfallOrchestrator::step10_llm_classify`
  **écrit** le résultat du LLM dans `companies.signals['llm_classification']` puis
  `$company->save()`. **Des classifications fabriquées atterrissent en base de production.**
  C'est une fuite de mock **avec écriture**, pas une simple inertie.
- `MockSmtpProber`, `MockInseeClient`, `MockBodaccClient`… alimentent les mêmes chemins.

**Ce qui, en revanche, ne joue PAS** — je le dis parce que c'était mon hypothèse de départ et que
la mesure l'a réfutée : le piège classique « `config:cache` empêche le chargement du `.env`, donc
`env()` rend `null` » **ne s'applique pas ici**. `Dockerfile.laravel:86` interdit explicitement
`config:cache`, et `app()->configurationIsCached()` rend **`NON`** sur le conteneur mesuré. Le
`.env` est donc bien lu. Le risque subsiste entièrement, mais par ce chemin-là uniquement.

→ **C18-016**

#### Côté Node — les 6 mocks et `_stub`

**Mesuré** (`04_PREUVES/agent-18/05_drapeaux_mock_node.txt`), sur l'expression exacte des
11 workers :

```
MOCK_SCRAPERS=ABSENT    -> MOCK      MOCK_SCRAPERS=0        -> MOCK
MOCK_SCRAPERS=false     -> REEL      MOCK_SCRAPERS=(vide)   -> MOCK
MOCK_SCRAPERS=FALSE     -> MOCK      MOCK_SCRAPERS=off      -> MOCK
MOCK_SCRAPERS=False     -> MOCK      MOCK_SCRAPERS=no       -> MOCK
```

**Seule la chaîne minuscule exacte `'false'` arme le scraper réel.** Le mock ne peut donc **pas**
fuir : la menace est l'inverse — une production qui croit collecter et ne collecte rien, en
répondant `status: 'success'` avec des charges vides. Et `MOCKS-STRATEGY.md` promet un
« basculement en 1 ligne `MOCK_MODE=false` » : **c'est faux côté Node**, il faut `MOCK_SCRAPERS`,
en minuscules. → **C18-017**

`_stub.ts` ne peut fuir nulle part : **personne ne l'importe**. C'est du code mort.

#### Y a-t-il une garde qui rougirait ?

**Non.** Aucun test, PHP ou Node, ne vérifie qu'un environnement de production résout vers des
implémentations réelles. Témoin négatif : `grep -rl "MockServicesProvider" backend/tests/` ne rend
**aucun fichier**.

### ⑨ Registre des sources — piège 22

**Le piège est joué, et le verdict est nuancé.**

`ScrapingSourcesSeeder::run()` fait bien un `upsert`, et il est appelé par **deux** migrations, pas
une : `2026_08_14_000006_crm_scraping_sources.php:57` et
`2026_08_15_100001_seed_implantations_fr_etranger_source.php:22`.

**Ce qui est protégé** : `enabled` est **délibérément exclu** de la liste des colonnes mises à
jour (l. 168-169, avec le commentaire qui l'explique). Le kill-switch survit à un re-seed —
et un test le vérifie (`ScrapedIngestTest.php:182`, *« le re-seed du registre ne RÉACTIVE jamais
une source coupée à la main »*). **C'est correct.**

**Ce qui ne l'est pas** : `name`, `kind`, `ttl_days`, `legal_note`, `dedup_key_pattern`,
`quota_per_day` **sont** écrasés. Et `ttl_days` est précisément ce que la migration promet de
rendre modifiable sans redéploiement (`2026_08_14_000006:18` : *« `ttl_days` : remplace
SOURCE_TTL_DAYS en dur (le code le lit ici) »*), avec un `DeduplicationService::ttlDays()` qui
interroge la table **sans cache**, justement pour qu'un changement morde au prochain job.

**Donc : justifié aujourd'hui** — `grep -rn "scraping_sources" backend/app/` ne trouve **aucun**
contrôleur, **aucune** route, et `frontend/src` n'en contient **aucune** occurrence : rien ne
permet d'éditer le registre depuis une console. **Mais l'exception invoquée est plus étroite que
la protection posée** : ce n'est pas « personne n'édite le nom d'une source » qui rend le seeder
sûr, c'est « personne n'édite **rien** ». Le jour où un TTL se règle depuis la console — ce que la
migration annonce comme son intérêt principal — la troisième migration qui rappellera ce seeder
effacera ce réglage. C'est déjà arrivé **deux fois** en quatre jours. → **C18-015**

### ⑩ Les 13 scrapers

Voir le tableau §1.f. En une phrase : **les 13 existent, 11 sont branchés dans le `REGISTRY`,
0 est déployé, 0 est testé au niveau de son implémentation, et 1 (`_stub`) n'est référencé nulle
part.** Le retrait des services `worker-*` de `docker-compose.yml` le 2026-08-14 est **assumé et
documenté** (l. 182-187) ; ce qui ne l'est pas, c'est que le chemin qui les réveillerait est
**déjà cassé** (→ **C18-011**).

---

## 3. Constats

### [C18-001] Le funnel d'ingestion est fermé par défaut, et l'endpoint répond « ingested: true » sans rien ingérer

- Sévérité      : S1 grave
- Domaine       : backend
- Référence     : main `c0c453d` … `e8924b8` (périmètre inchangé, `git diff --stat` vide)
- Emplacement   : `backend/config/crm.php:104` ; `backend/app/Http/Controllers/Internal/ScraperResultController.php:49-58`
- Constat       : `CRM_SCRAPE_FUNNEL_ENABLED` vaut `false` par défaut ; dans ce cas le contrôleur journalise le message et répond `200 {"ingested": true}` sans qu'aucune ligne ne soit écrite.
- Preuve        : `backend/config/crm.php:104` → `env('CRM_SCRAPE_FUNNEL_ENABLED', false)` ; `.env.example:246` → `CRM_SCRAPE_FUNNEL_ENABLED=false` ; le test du dépôt lui-même l'affirme (`ScrapedIngestTest.php:110-132` : companies=0, scraper_runs=0, activities=0 après un 200). Archivé : `04_PREUVES/agent-18/01_dryrun_dedup_eligibilite.txt`
- Témoin négatif: le **même** endpoint, drapeau à ON, écrit réellement (`ScrapedIngestTest.php:134-154`, et ma propre mesure §[2] : 4 tables modifiées). Le contrôle sait donc distinguer les deux états.
- Impact        : un producteur — worker Node, import JSONL, futur connecteur — ne peut pas savoir que sa collecte est jetée. C'est exactement le défaut que le commentaire du contrôleur dit avoir corrigé (« un job vert qui ne relaie rien est le pire des états, leçon IndexNow ») : il a été corrigé **derrière un drapeau fermé**, donc pas corrigé pour l'exploitant.
- Reproduction  : `POST /internal/scraper-result` avec une signature valide, sans poser `CRM_SCRAPE_FUNNEL_ENABLED=true` → 200 `{"ingested": true}`, base inchangée.
- Correctif     : répondre `202 {"ingested": false, "reason": "funnel_disabled"}` quand le drapeau est fermé. **20 min**, sans toucher au drapeau ni au comportement d'ingestion.
- Statut        : ouvert

### [C18-002] Une personne collectée disparaît sans compteur : `'skipped'` n'existe pas dans le rapport d'ingestion

- Sévérité      : S2 défaut
- Domaine       : backend
- Référence     : main `c0c453d` … `e8924b8`
- Emplacement   : `backend/app/Crm/Scraping/ScrapedRecordIngestService.php:399-507` ; `backend/app/Crm/Scraping/ScrapeIngestOutcome.php:34-44`
- Constat       : `upsertContact()` rend `'skipped'` dans deux cas — nom de famille absent (l. 408) et rien à écrire sur une fiche déjà trouvée (l. 497) — et `ScrapeIngestOutcome` ne porte aucun champ pour ce cas ; le `match` de la l. 145 le range dans `default => null`.
- Constat second: la recherche par e-mail (l. 433-438) ne filtre **pas** sur `company_id` : elle prend n'importe quel contact du workspace portant cette adresse, l'`orderByRaw` ne faisant que *préférer* la bonne entreprise. Une personne collectée pour l'entreprise B, dont l'adresse existe déjà sur un contact de l'entreprise A, n'obtient **aucune fiche chez B** — et l'ingestion rend `status: created, contacts_created: 0`.
- Preuve        : `04_PREUVES/agent-18/10_lot2_mocks_perte_annulation.txt`, section [B] — deux ingestions successives, deux entreprises, la même personne :

  ```
  ingestion 1 (ENTREPRISE A) : {"status":"created","company_id":5,"contacts_created":1,…}
  ingestion 2 (ENTREPRISE B, MEME personne/email) : {"status":"created","company_id":6,"contacts_created":0,…}
  companies=2  contacts=1
    contact id=4 Paul MARTIN paul.martin@a18.example.invalid -> company=ENTREPRISE A
  => La personne de l'ENTREPRISE B a-t-elle une fiche ? *** NON — perdue ***
  => aucun compteur : contacts_created=0 contacts_updated=0 opt_out=0 mx=0
  ```
- Témoin négatif: la **première** ingestion, strictement identique, rend `contacts_created: 1` et crée bien le contact — la mesure sait donc produire le cas nominal, et la différence tient uniquement à la présence préalable de l'adresse.
- Impact        : sous-comptage invisible du rendement de collecte, et perte réelle de rattachement pour toute personne qui change d'entreprise ou dont l'adresse est partagée (cabinets, groupes, holdings). Aucune alerte, aucun journal.
- Reproduction  : ingérer deux `ScrapedRecord` de SIREN différents portant la même personne / le même e-mail.
- Correctif     : ⑴ ajouter `personsSkipped` à `ScrapeIngestOutcome` et l'alimenter (**30 min**) ; ⑵ borner la recherche par e-mail au `company_id` du record, en laissant la recherche large en simple *proposition* d'arbitrage (**2 h**, décision produit à prendre avec Will).
- Statut        : ouvert

### [C18-003] Un essai à blanc ne laisse aucune ligne — mais consomme 3 séquences et interroge le DNS

- Sévérité      : S3 finition
- Domaine       : backend
- Référence     : main `c0c453d` … `e8924b8`
- Emplacement   : `backend/app/Crm/Scraping/DryRunRollback.php` ; `ScrapedRecordIngestService.php:75-89` ; `EmailMxValidator.php:29-38`
- Constat       : le rollback est intègre sur les **114 tables** de la base, mais `companies_id_seq`, `scraper_runs_id_seq` et `activities_id_seq` avancent, et la validation MX émet de vraies requêtes DNS puisque `crm.scrape_funnel.validate_mx` vaut `true` par défaut.
- Preuve        : `04_PREUVES/agent-18/01_dryrun_dedup_eligibilite.txt`, section [1] — instantané des 114 tables et des séquences avant/après.
- Témoin négatif: section [2] du même fichier — le même appel sans `dryRun` modifie 4 tables. Le comparateur sait donc voir des écritures.
- Impact        : faible. Trous dans les identifiants ; et, sur un gros lot d'essai, un volume de requêtes DNS non anticipé depuis l'IP de production.
- Reproduction  : `php artisan scraping:ingest-file --dry-run`, comparer `pg_sequences` avant/après.
- Correctif     : documenter les deux effets dans le bloc de commentaire de `DryRunRollback` (**10 min**) ; forcer `validate_mx=false` en mode `--dry-run` si l'on veut un essai réellement hermétique (**20 min**).
- Statut        : ouvert

### [C18-004] `DeduplicationService::computeContactHash()` diverge de la colonne générée sur les particules et les espaces

- Sévérité      : S2 défaut
- Domaine       : backend
- Référence     : main `c0c453d` … `e8924b8`
- Emplacement   : `backend/app/Services/Dedup/DeduplicationService.php:64-72` et `306-314`
- Constat       : la méthode normalise **chaque partie séparément** en PHP, là où la colonne `GENERATED` normalise la **chaîne concaténée** en SQL ; les deux hachages diffèrent dès qu'un nom porte une particule ou un espace de bordure.
- Preuve        : `04_PREUVES/agent-18/01_dryrun_dedup_eligibilite.txt`, section [3] :

  ```
  'Paul' / 'ZZ A18'          PHP=801fa0a07e80  SQL=801fa0a07e80  IDENTIQUE
  'Elodie' / 'Dupont Martin' PHP=bb16247cef9b  SQL=bb16247cef9b  IDENTIQUE
  'Jean' / 'de la Fontaine'  PHP=ed131b778de1  SQL=1b2a298f732e  *** DIVERGENT ***
  'Paul' / ' Dupont'         PHP=515ee5b1033c  SQL=e85f971ef5df  *** DIVERGENT ***
  ```
- Témoin négatif: les quatre premiers cas rendent des hachages **identiques** — la comparaison n'est pas biaisée, elle sait dire « identique ».
- Impact        : `findContactByNormalizedHash()` interroge la colonne `normalized_hash` avec le hachage **PHP**. Sur un nom à particule, elle rend `null` alors que le contact existe : le niveau 2 de la dédup est aveugle exactement sur la population française où il servirait le plus. Le commentaire de la l. 67 avoue l'approximation (« la source de vérité reste la DB ») sans en tirer la conséquence : la méthode qui *interroge* la DB ne peut pas se contenter d'une approximation.
- Reproduction  : la section [3] ci-dessus, rejouable sur toute base migrée.
- Correctif     : faire calculer le hachage par Postgres, comme le fait déjà `ScrapedRecordIngestService::normalizedHash()` (l. 658-666) — le code correct existe à dix mètres. **30 min**, plus un test comparant les deux sur un jeu de noms à particule.
- Statut        : ouvert

### [C18-005] `isOptedOut()` construit une requête sans clause de base, sûre par accident

- Sévérité      : S3 finition
- Domaine       : backend / conformité
- Référence     : main `c0c453d` … `e8924b8`
- Emplacement   : `backend/app/Services/Dedup/DeduplicationService.php:206-221`
- Constat       : la requête empile deux `orWhere` sur `DB::table('opt_out')` sans aucun `where` initial ; elle n'est correcte que parce que le retour anticipé de la l. 208 garantit qu'au moins un des deux est posé.
- Preuve        : lecture du code. `DB::table('opt_out')->exists()` sans clause rendrait `true` dès qu'une seule opposition existe dans le système — c'est-à-dire « tout le monde est opposé ».
- Témoin négatif: la garde l. 208-210 (`if (! $email && ! $phone) return false;`) est le seul rempart ; je l'ai lue et elle tient **aujourd'hui**.
- Impact        : nul en l'état. Le jour où quelqu'un ajoute un troisième critère optionnel sans toucher au retour anticipé, la méthode rendra `true` pour tout le monde — c'est-à-dire qu'elle **coupera toute la prospection** sans un message d'erreur. Une garde qui sur-garde ne fait pas plus de bruit qu'une garde qui sous-garde.
- Reproduction  : appeler `isOptedOut(null, null)` après avoir retiré le retour anticipé.
- Correctif     : partir de `->where(fn($q) => $q->orWhere(...)->orWhere(...))`, ou poser `->whereRaw('false')` comme base neutre. **10 min**.
- Statut        : ouvert

### [C18-006] Piège 10 — la dédup e-mail du funnel compare un `lower()` SQL à un `mb_strtolower` PHP, et rate le contact

- Sévérité      : S2 défaut
- Domaine       : backend
- Référence     : main `c0c453d` … `e8924b8`
- Emplacement   : `backend/app/Crm/Scraping/ScrapedRecordIngestService.php:401` et `:435`
- Constat       : le service normalise l'adresse en PHP par `mb_strtolower()` puis la compare en SQL par `whereRaw('lower(email::text) = ?')` ; sous `lc_ctype=C` — la collation de la production **et** de la CI — `lower()` ne minuscule pas les majuscules non-ASCII, les deux valeurs diffèrent et la recherche ne trouve rien.
- Preuve        : `04_PREUVES/agent-18/02_locale_lower_normalize.txt` et `01_dryrun_dedup_eligibilite.txt` section [4] :

  ```
  datcollate=C datctype=C
  PHP mb_strtolower = élodie@a18.example.invalid
  SQL lower()       = Élodie@a18.example.invalid
  identiques ? *** NON ***
  requete EXACTE du funnel (upsertContact l.435) => 0 ligne(s)  *** DOUBLON GARANTI ***
  ```
- Témoin négatif: la même mesure sur `'Elodie@Exemple.FR'` (ASCII) rend `elodie@exemple.fr` des deux côtés — le comparateur sait dire « identique ». Et la ligne était bien en base : la comparaison `citext` directe la retrouve dès qu'on lui donne la forme non minusculée.
- Impact        : un doublon de fiche personne à chaque re-collecte d'une adresse à majuscule accentuée ; et, plus grave, la clause `lower(email::text)` **casse l'index** `idx_contacts_email` (posé sur `email` en `citext`, pas sur `lower(email::text)`) : chaque ingestion de personne provoque un parcours de table. Sur les 410 481 contacts annoncés, c'est un parcours complet par personne ingérée.
- Reproduction  : insérer un contact d'adresse `Élodie@…`, rejouer la requête de la l. 435 avec `mb_strtolower` de la même adresse.
- Correctif     : supprimer le `lower(...)` et comparer directement sur la colonne `citext`, qui est **déjà** insensible à la casse et **indexée** : `->where('email', $email)`. **15 min**, gain de performance en prime. Étendre `tests/Feature/Rgpd/EmpreinteSqlEtPhpTest.php` — qui fige déjà cet écart pour `opt_out` — à la dédup du funnel : **30 min**.
- Statut        : ouvert

### [C18-007] Le quota `max_companies` d'une campagne ne freine rien : le moniteur remet le compteur à zéro toutes les 60 s

- Sévérité      : S1 grave
- Domaine       : backend / conformité
- Référence     : main `c0c453d` … `e8924b8`
- Emplacement   : `backend/app/Jobs/MonitorCampaignProgressJob.php:76` et `:94` ; `backend/app/Jobs/LaunchZoneScrapingJob.php:55` et `:121-128` ; `backend/app/Models/ScrapingCampaign.php:183`
- Constat       : `LaunchZoneScrapingJob` incrémente `scraping_campaigns.companies_created`, puis `MonitorCampaignProgressJob` le **recalcule** par `COUNT(DISTINCT company_id) FILTER (WHERE company_id IS NOT NULL)` sur `scraper_runs` — or les runs de découverte de zone sont créés **sans `company_id`**, et les runs porteurs d'un `company_id` (ceux du funnel d'ingestion) sont créés **sans `campaign_id`**. Le recalcul rend donc toujours 0, et `$campaign->update($aggregates)` écrase la vraie valeur.
- Preuve        : `04_PREUVES/agent-18/09_quota_campagne.txt` — campagne `max_companies = 1000`, un run de zone tel que le code le crée, puis la requête **exacte** du moniteur rejouée à l'identique :

  ```
  ecrit_par_launchzonescrapingjob | max_companies
                              400 |          1000
  runs_completed | companies_created_recalcule
               1 |                           0
  ```
- Témoin négatif: la même requête compte correctement quand un `company_id` est présent (`runs_avec_company_id` non nul) — elle n'est pas cassée en soi, elle mesure le mauvais objet. **C'est le piège 19 du dossier commun, appliqué à un compteur.**
- Impact        : `shouldAutoPause()` teste `companies_created >= max_companies` : la condition est donc **toujours fausse**. Une campagne ne s'auto-pause **jamais** sur son plafond de fiches. Elle finit par s'arrêter sur `max_duration_minutes` — mais le plafond affiché dans l'écran, saisi par l'opérateur, et invoqué au titre de la minimisation RGPD, ne limite **rien**. Le compteur montré à l'écran est faux dans le même mouvement.
- Reproduction  : les commandes du fichier de preuve, rejouables sur toute base migrée.
- Correctif     : poser `campaign_id` sur les runs d'ingestion **ou** cesser de recalculer `companies_created` et laisser l'incrément faire foi (avec un recalcul de rattrapage à la fin). **2 h**, plus un test qui vérifie que le compteur ne décroît jamais.
- Statut        : ouvert

### [C18-008] L'arrêt d'urgence n'arrête pas la collecte : le drapeau d'annulation n'est lu par personne et la file n'est pas purgée

- Sévérité      : S1 grave
- Domaine       : backend / sécurité
- Référence     : main `c0c453d` … `e8924b8`
- Emplacement   : `backend/app/Http/Controllers/Api/ScraperRunsController.php:96-105` ; `backend/app/Http/Controllers/Api/ScrapingCampaignsController.php:298-328` ; `workers/src/scrapers/base.ts` (entier)
- Constat       : les deux contrôleurs écrivent `cancelled:scraper-run:<id>` dans Redis en annonçant en commentaire que « les workers Node le lisent pour interrompre le job en cours » ; aucun fichier de `workers/src` ne contient la chaîne `cancel`. En outre le drapeau part sur la connexion Redis `default` (`REDIS_DB=0`) alors que les jobs vivent sur la connexion `queue` (`REDIS_QUEUE_DB=1`), et la file `axion:scrape:<source>` n'est jamais vidée.
- Preuve        : `04_PREUVES/agent-18/03_pont_redis.txt` :

  ```
  grep -rniE 'cancel|annul' workers/src/
  → AUCUNE OCCURRENCE (7 fichiers, 1363 lignes)
  ```
  et `04_PREUVES/agent-18/10_lot2_mocks_perte_annulation.txt`, section [C] — le corps exact des deux méthodes rejoué :

  ```
  run cree id=14 status=running
  jobs en file Redis AVANT annulation : 4
  jobs en file Redis APRES annulation  : 4   *** LA FILE N'EST PAS PURGEE ***
  statut du run en base : cancelled
  connexion 'default' -> base 0, prefixe 'axion_crm_pro_database_'   ← où va le drapeau
  connexion 'queue'   -> base 1                                      ← où sont les jobs
  WORKER_REDIS_URL    -> redis://redis:6379/1                        ← où lit le worker
  ```
- Témoin négatif: le même `grep`, lancé sur `backend/app/Http/Controllers/Api/`, remonte bien les occurrences de `cancel` — il sait trouver le motif quand il existe.
- Impact        : une collecte partie de travers ne peut être stoppée qu'en tuant les conteneurs. Aujourd'hui l'impact est **latent** puisque aucun worker n'est déployé (→ C18-011) ; il devient immédiat le jour où on les redéclare. Et l'écran, lui, affiche « annulé » : la fonctionnalité **ment**.
- Reproduction  : les commandes du fichier de preuve.
- Correctif     : ⑴ purger la file au moment de l'annulation (`LREM` ciblé ou `DEL` par source) — **1 h** ; ⑵ faire lire le drapeau par `base.ts` en tête de traitement, **sur la même connexion Redis** — **2 h** ; ⑶ à défaut, **retirer le commentaire qui promet un comportement inexistant** — **5 min**, et c'est le minimum honnête.
- Statut        : ouvert

### [C18-009] Les sources Node sont nommées avec un souligné dans `LaunchCampaignJob`, avec un tiret partout ailleurs

- Sévérité      : S3 finition
- Domaine       : backend
- Référence     : main `c0c453d` … `e8924b8`
- Emplacement   : `backend/app/Jobs/LaunchCampaignJob.php:38-41` ; `backend/app/Jobs/LaunchZoneScrapingJob.php:202`
- Constat       : `DISCOVERY_SOURCES_NODE = ['google_maps', 'pages_jaunes']` et `DISCOVERY_SOURCES_BACKEND = ['insee', 'france_travail']` emploient le souligné, alors que le registre `scraping_sources`, `SOURCE_TTL_DAYS`, `QUEUES` et le `REGISTRY` Node emploient tous le tiret ; la conversion est faite au dernier moment par un `str_replace('_', '-', …)` (l. 202).
- Preuve        : lecture croisée des quatre listes ; `scraper_runs.source` reçoit la forme **soulignée** (l. 58 de `LaunchZoneScrapingJob`), tandis que le funnel d'ingestion écrit la forme **tiretée**.
- Témoin négatif: `workers/tests/registre-sources.test.ts` compare bien quatre listes de slugs — mais il lit `REGISTRY`, `QUEUES`, `SOURCE_TTL_DAYS` et le seeder, **pas** les constantes de `LaunchCampaignJob`. Il ne pouvait donc pas voir cet écart.
- Impact        : `scraper_runs.source` contient deux orthographes pour la même source. Toute agrégation par source (l'écran de statistiques d'une campagne, `stats()` l. 349-358, groupe par `source`) affiche deux lignes là où il devrait en afficher une. `DeduplicationService::ttlDays('google_maps')` retombe silencieusement sur le défaut de 30 jours au lieu des 60 du registre.
- Reproduction  : lancer une campagne avec `sources: ["google_maps"]` et lire `SELECT DISTINCT source FROM scraper_runs`.
- Correctif     : employer le tiret partout et convertir à l'entrée du contrôleur si l'API publique doit rester compatible. **1 h**. Étendre `registre-sources.test.ts` aux constantes de `LaunchCampaignJob` : **30 min**.
- Statut        : ouvert

### [C18-010] Un run Node est déclaré « success » sans avoir rien collecté, et son identifiant n'a aucun rapport avec le run envoyé au worker

- Sévérité      : S2 défaut
- Domaine       : backend
- Référence     : main `c0c453d` … `e8924b8`
- Emplacement   : `backend/app/Jobs/LaunchZoneScrapingJob.php:109-119` et `:189-212` ; `backend/app/Jobs/DispatchScrapeJob.php:39`
- Constat       : pour `google_maps` / `pages_jaunes`, `dispatchNodeWorker()` rend un tableau vide et le run est aussitôt marqué `status = 'success'` avec `companies_found = 0` ; le message déposé pour le worker porte un `run_id` fabriqué par `bin2hex(random_bytes(8))`, sans rapport avec l'`id` du `ScraperRun` créé l. 55.
- Preuve        : lecture du code ; `DispatchScrapeJob.php:39` → `'run_id' => bin2hex(random_bytes(8))`. Le funnel d'ingestion construit ensuite sa clef d'idempotence sur ce `run_id` (`ScrapedRecordIngestService.php:94`) et crée une **nouvelle** ligne `scraper_runs`.
- Témoin négatif: pour `insee` / `france_travail`, le même job rend un vrai décompte (`companies_found = count($results)`) — le chemin sait produire un run honnête quand il en a un.
- Impact        : trois conséquences. ⑴ L'écran affiche un run vert et vide, indiscernable d'une zone réellement sans résultat. ⑵ Le run d'origine reste **orphelin** : rien ne le relie au résultat qui reviendra plus tard. ⑶ `POST /scraper-runs/{run}/cancel` ne peut donc **rien** annuler pour une source Node, même si le drapeau était lu (→ C18-008) : le worker ne connaît pas cet identifiant.
- Reproduction  : lancer une campagne avec `sources: ["google_maps"]` et `MOCK_SCRAPERS=false`, comparer `scraper_runs.id` et le `run_id` du message Redis.
- Correctif     : passer l'`id` du `ScraperRun` comme `run_id` au worker et laisser le run en `running` jusqu'au retour du résultat. **3 h**.
- Statut        : ouvert

### [C18-011] Le pont Laravel → Node est rompu par le préfixe Redis : un job de collecte n'atteint jamais un worker

- Sévérité      : S1 grave
- Domaine       : backend / infrastructure
- Référence     : main `c0c453d` … `e8924b8`
- Emplacement   : `backend/app/Jobs/DispatchScrapeJob.php:49-52` ; `backend/config/database.php:136` ; `workers/src/scrapers/base.ts:159` ; `workers/src/bridge/queues.ts`
- Constat       : Laravel pousse par `Redis::connection('queue')->lpush("axion:scrape:{$source}", …)`, et le client Redis de Laravel applique le préfixe global `axion_crm_pro_database_` ; la clef réellement écrite est donc `axion_crm_pro_database_axion:scrape:google-maps`, tandis que le worker exécute `redis.brpop('axion:scrape:google-maps', 30)` **sans préfixe**.
- Preuve        : `04_PREUVES/agent-18/03_pont_redis.txt` — mesure complète, jouée :

  ```
  prefix = axion_crm_pro_database_     (config('database.redis.options.prefix'))
  KEYS *scrape* (base 1) → axion_crm_pro_database_axion:scrape:google-maps
  LRANGE → {"run_id":"2aa0d71665a7a8ba","source":"google-maps",…}

  BRPOP 'axion:scrape:google-maps' 3   → (vide)      ← le worker ne voit rien
  ```
- Témoin négatif: **le même `BRPOP`, après un `LPUSH` sur la clef nue, remonte immédiatement le message** :

  ```
  LPUSH 'axion:scrape:google-maps' '{"temoin":1}'  → 1
  BRPOP 'axion:scrape:google-maps' 3               → axion:scrape:google-maps / {"temoin":1}
  ```
  La commande de contrôle sait donc trouver un job quand il est là où le worker le cherche.
- Impact        : aucun résultat de collecte Node ne peut jamais parvenir au CRM. Le défaut est aujourd'hui **masqué** parce que les services `worker-*` ont été retirés de `docker-compose.yml` le 2026-08-14 : personne ne peut le constater. Il se déclenchera **le jour exact** où l'on redéclarera les services, c'est-à-dire au moment où l'on croira réactiver la collecte. Le commentaire de `DispatchScrapeJob` — « schéma simple JSON + listes Redis = robuste, inspectable via `redis-cli` » — décrit un pont qui n'a, mesure faite, jamais pu fonctionner.
- Reproduction  : `(new DispatchScrapeJob(1,'google-maps',[],null))->handle();` puis `redis-cli -n 1 KEYS '*scrape*'`.
- Correctif     : deux options, **30 min** l'une ou l'autre. ⑴ Déclarer une connexion Redis dédiée sans préfixe pour le pont (`'prefix' => ''` dans une entrée `scrape_bridge` de `config/database.php`). ⑵ Faire porter le préfixe au worker via une variable d'environnement. **Puis** ajouter au test `registre-sources.test.ts` une assertion sur la clef **réellement produite par Laravel**, et non sur la constante Node — c'est le seul ajout qui empêche la récidive.
- Statut        : ouvert

### [C18-012] Un run interrompu reste « running » pour toujours et bloque la fin de sa campagne

- Sévérité      : S2 défaut
- Domaine       : backend
- Référence     : main `c0c453d` … `e8924b8`
- Emplacement   : `backend/app/Jobs/LaunchZoneScrapingJob.php:55-69` ; `backend/app/Jobs/MonitorCampaignProgressJob.php:57` et `:114`
- Constat       : `LaunchZoneScrapingJob` crée le run en `running` puis ne le referme que dans son `try`/`catch` ; si le processus meurt entre les deux (redémarrage de conteneur, `SIGKILL`, `timeout` de 1 800 s dépassé), aucun mécanisme ne repasse le run à `failed`. Aucune tâche planifiée ne balaie les runs `running` périmés — vérifié : `grep -rn "running" backend/app/Console/` ne rend aucun balayage.
- Preuve        : lecture du code ; `MonitorCampaignProgressJob` ne compte comme terminés que les statuts `success|completed|failed|cancelled` (l. 57), et ne conclut `completed` que si `runs_completed >= runs_total` (l. 114).
- Témoin négatif: le cas nominal est couvert — `tries = 2` fait rejouer le job sur une exception attrapée, et le run est alors bien marqué `failed` (l. 145-149). Le trou est le cas **non attrapable**.
- Impact        : la campagne reste `running` indéfiniment, `MonitorCampaignProgressJob` se re-dispatche toutes les 60 s pour l'éternité, et l'écran affiche une campagne en cours qui ne progresse plus. Un seul run orphelin suffit.
- Reproduction  : créer un run `running` avec un `campaign_id`, ne jamais le fermer, observer que la campagne ne passe jamais `completed`.
- Correctif     : une tâche planifiée qui passe à `failed` tout run `running` dont le `started_at` dépasse `timeout` + marge, et l'exposer dans l'écran. **2 h**.
- Statut        : ouvert

### [C18-013] `EligibiliteCampagne` ne dit jamais *pourquoi* une fiche est écartée, et une fiche opposée n'apparaît dans aucune des deux listes

- Sévérité      : S2 défaut
- Domaine       : interface / conformité
- Référence     : main `c0c453d` … `e8924b8`
- Emplacement   : `backend/app/Support/EligibiliteCampagne.php:73-83` et `:248-251`
- Constat       : `appliquer()` retire les fiches sans adresse **et** les fiches opposées ou supprimées ; `appliquerNonEligibles()` ne rend que les fiches **sans adresse**. Une fiche pourvue d'une adresse mais inscrite en `opt_out` ou `email_suppressions` ne figure donc dans **aucune** des deux listes, et aucune méthode publique ne rend de motif.
- Preuve        : `04_PREUVES/agent-18/01_dryrun_dedup_eligibilite.txt`, section [5] — méthodes publiques mesurées : `appliquer, appliquerContacts, exclureOpposes, peutRecevoir, appliquerNonEligibles`. Aucune ne rend un motif.
- Témoin négatif: `peutRecevoir()` fonctionne et bascule bien : mesuré `true` avant l'insertion de l'opposition, `false` après. La logique d'exclusion est donc juste — c'est sa **restitution** qui manque.
- Impact        : devant un compteur d'éligibles qui a baissé, l'opérateur ne peut pas savoir si c'est une opposition, un rebond dur ou une suppression de fiche. Le commentaire d'en-tête de la classe justifie longuement le refus d'un « bac campagnes » figé au nom de la visibilité — mais la visibilité s'arrête au nombre. Une confusion de navigation qui fait perdre l'utilisateur est au minimum S2.
- Reproduction  : compter `appliquer()` + `appliquerNonEligibles()` sur une base contenant une opposition ; la somme est inférieure au total.
- Correctif     : ajouter `motifExclusion(string $email): ?string` rendant `'opposition' | 'suppression' | 'sans_adresse' | null`, et un décompte par motif pour l'écran. **3 h**.
- Statut        : ouvert

### [C18-014] `WaterfallSentry` couvre 11 pannes silencieuses et ne peut rien émettre

- Sévérité      : S2 défaut
- Domaine       : backend / tests
- Référence     : main `c0c453d` … `e8924b8`
- Emplacement   : `backend/app/Support/WaterfallSentry.php:23` ; `backend/config/sentry.php:12`
- Constat       : la garde `if (! class_exists(\Sentry\State\Hub::class)) return;` ne se déclenche jamais — le paquet `sentry/sentry-laravel ^4.10` est installé et `backend/vendor/sentry` existe. Mais `config/sentry.php` lit `SENTRY_LARAVEL_DSN`, absent de `.env`, de `backend/.env`, de `.env.example` et de `infra/scripts/configure-prod-env.sh`. Sans DSN, la capture est inerte.
- Preuve        : `04_PREUVES/agent-18/11_sentry_dsn.txt` — vérification **fichier par fichier**, chacun ouvert nommément :

  ```
  .env                                  SENTRY_LARAVEL_DSN x0
  backend/.env                          SENTRY_LARAVEL_DSN x0
  .env.example                          SENTRY_LARAVEL_DSN x0
  infra/scripts/configure-prod-env.sh   SENTRY_LARAVEL_DSN x0
  infra/scripts/setup-hetzner-cpx22.sh  SENTRY_LARAVEL_DSN x0

  backend/config/sentry.php:12:    'dsn' => env('SENTRY_LARAVEL_DSN'),
  ```
  ⚠️ **Pourquoi fichier par fichier et non un `grep -r`** : les `.env` sont *gitignorés*, donc un ripgrep de dépôt les **saute silencieusement**. Ma première mesure était de cette forme — elle aurait rendu le même « rien trouvé » que les fichiers contiennent la clef ou non. Refaite.
- Témoin négatif: le **même** contrôle, sur la même liste de fichiers, trouve `VITE_SENTRY_DSN` **x1** dans `.env`, `backend/.env` et `.env.example`. Il sait donc lire ces fichiers et y trouver une clef d'environnement quand elle est écrite — le « x0 » n'est pas un angle mort.
- Impact        : les 11 sites de capture sont tous placés dans un `catch (\Throwable)` qui **avale** l'exception (log `warning` + `recordRun(…, 'failed')`, jamais de `throw`). L'unique voie de remontée est donc Sentry, et elle est coupée. Une panne d'enrichissement se réduit à une ligne de log et à un run marqué `failed` que personne ne regarde. « A-t-il déjà déclenché ? » : sur cette installation, il ne le peut pas.
- Rattachement  : l'agent 16 a déjà relevé l'absence de DSN (`agent-16_audit-ai-act.md:216`). Je n'ouvre pas de doublon ; ce constat n'ajoute que la **portée waterfall** — l'inventaire des 11 sites et le fait qu'ils avalent tous l'exception.
- Correctif     : déclarer `SENTRY_LARAVEL_DSN` dans `.env.example` et dans `configure-prod-env.sh` (**30 min**) ; ajouter un test qui rougit si un site de capture existe sans voie de remontée configurée (**1 h**).
- Statut        : ouvert

### [C18-015] Piège 22 — le seeder du registre protège `enabled` mais écrase `ttl_days`, la seule valeur qu'il promet de rendre modifiable

- Sévérité      : S3 finition
- Domaine       : backend
- Référence     : main `c0c453d` … `e8924b8`
- Emplacement   : `backend/database/seeders/ScrapingSourcesSeeder.php:154-170` ; `backend/database/migrations/2026_08_14_000006_crm_scraping_sources.php:57` ; `backend/database/migrations/2026_08_15_100001_seed_implantations_fr_etranger_source.php:22`
- Constat       : le seeder fait un `upsert` par `slug` et il est appelé par **deux** migrations. `enabled` est délibérément exclu des colonnes mises à jour ; `name`, `kind`, `ttl_days`, `legal_note`, `dedup_key_pattern` et `quota_per_day` ne le sont pas.
- Preuve        : l. 167-169 du seeder — la liste des colonnes mises à jour, lue mot à mot. Et `grep -rn "ScrapingSourcesSeeder" backend/database/migrations/` remonte **deux** migrations, à quatre jours d'intervalle.
- Témoin négatif: le dépôt sait déjà tester ce comportement — `ScrapedIngestTest.php:182-188` vérifie que `enabled` survit à un re-seed, et il **passe**. La protection existe et fonctionne ; elle est simplement plus étroite que ce qu'il faudrait.
- Impact        : nul aujourd'hui — `grep -rn "scraping_sources" backend/app/` ne trouve aucun contrôleur ni route, et `frontend/src` n'en contient aucune occurrence : le registre n'est éditable que par migration. **Mais** la migration qui crée la table écrit noir sur blanc que `ttl_days` existe pour « remplacer `SOURCE_TTL_DAYS` en dur, modifiable sans redéploiement », et `DeduplicationService::ttlDays()` l'interroge **sans cache** pour qu'un changement morde au prochain job. L'exception invoquée pour justifier l'`upsert` (« personne n'édite le nom d'une source depuis une console ») est donc plus étroite que le risque : ce n'est pas le nom qui est en jeu, c'est le TTL.
- Reproduction  : `UPDATE scraping_sources SET ttl_days = 5 WHERE slug = 'google-maps';` puis rejouer le seeder — la valeur revient à 60.
- Correctif     : retirer `ttl_days` (et `quota_per_day`) de la liste des colonnes mises à jour, au même titre que `enabled`, avec le même commentaire. **15 min**. Ne pas attendre l'apparition de la console.
- Statut        : ouvert

### [C18-016] 🔴 `MockServicesProvider` n'a aucune garde d'environnement et son défaut est le mock : une variable absente suffit à mettre la production sur des données fabriquées

- Sévérité      : S1 grave (S0 le jour où `MOCK_MODE` disparaît du `.env` serveur — voir Impact)
- Domaine       : backend / sécurité / conformité
- Référence     : main `c0c453d` … `e8924b8`
- Emplacement   : `backend/app/Providers/MockServicesProvider.php:55-88`, en particulier la l. 57
- Constat       : la seule condition qui arme les 14 liaisons de mocks est `$master = (bool) env('MOCK_MODE', true)`. Le fichier ne contient ni `environment(`, ni `isProduction`, ni aucun contrôle d'environnement ; le **défaut** de la variable est `true`, c'est-à-dire le mock.
- Preuve        : `04_PREUVES/agent-18/08_mocks_production.txt` :

  ```
  env('A18_CLEF_ABSENTE_XYZ', true) = true   (bool) = true  ->  BRANCHE MOCK
  A18_V_OFF  = 'off'  env()='off'  (bool)=true  -> MOCK
  A18_V_NO   = 'no'   env()='no'   (bool)=true  -> MOCK
  Garde d'environnement dans le provider ? AUCUNE
  config:cache actif ? NON
  ```
  Mesures jouées dans le conteneur avec de **vraies** variables d'environnement du processus.
  ⚠️ Les tentatives par `Env::getRepository()->set()` sont **invalides** — le dépôt d'environnement de Laravel est immuable ; l'avertissement est consigné en tête de `01_dryrun_dedup_eligibilite.txt` pour que personne ne s'appuie sur la première version de cette mesure. **Je l'ai découvert en me relisant, pas avant.**
- Témoin négatif: la même recherche de garde, appliquée à un provider qui en possède une, la trouve — la détection n'est pas aveugle. Et le fichier de preuve montre bien qu'une valeur `'false'` bascule vers les implémentations réelles : le levier existe, c'est son **défaut** et son **absence de filet** qui sont en cause.
- Impact        : le sens de la panne n'est pas neutre. `MockLLMClient` remplace `LLMRouterService`, et `WaterfallOrchestrator::step10_llm_classify` **écrit** le résultat du LLM dans `companies.signals['llm_classification']` puis appelle `$company->save()` : des classifications **fabriquées** seraient écrites dans la base de production, sans marqueur, indiscernables de vraies. Ce n'est donc pas une simple inertie, c'est une corruption de données silencieuse. Le chemin d'entrée est ordinaire : un `.env` serveur reconstruit depuis `.env.example` — qui porte `MOCK_MODE=true` en ligne 7 — ou un `infra/scripts/configure-prod-env.sh` rejoué partiellement.
- Ce qui NE joue PAS : j'avais supposé le piège classique « `config:cache` empêche le chargement du `.env`, donc `env()` rend `null` ». **Mesuré et réfuté** : `Dockerfile.laravel:86` interdit explicitement `config:cache`, et `app()->configurationIsCached()` rend `NON` sur le conteneur. Ce chemin-là est fermé.
- Reproduction  : retirer `MOCK_MODE` du `.env`, résoudre `App\Contracts\LLMClient` → `MockLLMClient`.
- Correctif     : ⑴ **refuser de démarrer** si un mock est armé alors que `app()->environment('production')` — trois lignes, **20 min**, et c'est le correctif qui compte ; ⑵ inverser le défaut à `false` et rendre l'activation des mocks explicite — **30 min**, mais casse les suites qui s'appuient sur le défaut ; ⑶ ajouter un test qui monte l'application en `production` sans `MOCK_MODE` et vérifie qu'aucun contrat ne résout vers une classe `Mock*` — **1 h**. Aujourd'hui `grep -rl "MockServicesProvider" backend/tests/` ne rend **aucun fichier** : rien ne surveille ce câblage.
- Statut        : ouvert

### [C18-017] Côté Node, le mock ne peut pas fuir — mais la bascule vers le réel est plus fragile que ce que promet `MOCKS-STRATEGY.md`, et `_stub.ts` est du code mort

- Sévérité      : S2 défaut
- Domaine       : backend / tests
- Référence     : main `c0c453d` … `e8924b8`
- Emplacement   : les 11 fichiers `workers/src/scrapers/*.worker.ts`, l. 7 de chacun ; `workers/src/scrapers/_stub.ts` ; `MOCKS-STRATEGY.md:3` et `:35-36`
- Constat       : chaque worker décide par `(process.env['MOCK_SCRAPERS'] ?? process.env['MOCK_MODE'] ?? 'true') !== 'false'`. **Seule** la chaîne minuscule exacte `'false'` arme le scraper réel ; `FALSE`, `False`, `0`, la chaîne vide, `off`, `no` et l'absence retombent tous sur le mock. Par ailleurs `stubWorker()` de `_stub.ts` n'est importé nulle part.
- Preuve        : `04_PREUVES/agent-18/05_drapeaux_mock_node.txt` — les dix valeurs jouées sur l'expression exacte. Et `grep -rn "stubWorker\|_stub" workers/src/ workers/tests/` ne rend **aucun import**.
- Témoin négatif: la valeur `'false'` bascule bien vers le réel dans la même mesure — l'expression n'est pas figée, elle discrimine.
- Impact        : ⑴ le mock ne peut pas fuir, c'est la bonne nouvelle ; ⑵ mais un `MOCK_SCRAPERS=FALSE` posé de bonne foi laisse les workers sur les mocks, qui répondent `status: 'success'` avec des charges vides — une collecte qui se déclare réussie et ne ramène rien ; ⑶ 🔴 **et les deux moitiés du même interrupteur divergent** : la même valeur `FALSE` (ou `0`, ou la chaîne vide) met le **backend PHP en réel** et les **workers Node en mock**, mesuré des deux côtés (§2.⑧). Le CRM se croit alors en production réelle et ingère du vide ; ⑷ `MOCKS-STRATEGY.md` promet « basculement en 1 ligne `MOCK_MODE=false` », ce qui est vrai côté PHP et **faux** côté Node, où `MOCK_SCRAPERS` prend le pas ; ⑸ `_stub.ts` fait croire à un mécanisme de repli qui n'existe pas.
- Reproduction  : la commande `node -e` du fichier de preuve.
- Correctif     : normaliser la lecture (`['false','0','no','off'].includes(String(v).toLowerCase())`) dans un helper partagé — **30 min** ; supprimer `_stub.ts` — **5 min** ; corriger la phrase de `MOCKS-STRATEGY.md` — **10 min**.
- Statut        : ouvert

### [C18-018] Aucun des 13 scrapers n'est couvert par un test, et aucun n'est déployé

- Sévérité      : S2 défaut
- Domaine       : tests
- Référence     : main `c0c453d` … `e8924b8`
- Emplacement   : `workers/src/scrapers/` (13 implémentations) ; `workers/tests/` (6 fichiers) ; `docker-compose.yml:182-187`
- Constat       : la suite `workers` compte 61 tests répartis sur 6 fichiers, tous verts. Aucun n'importe une implémentation de scraper : les imports portent sur `base`, `extract`, `queues`, `result-sender` et `ssrf-guard`. Par ailleurs aucun service `worker-*` n'est déclaré dans `docker-compose.yml` depuis le 2026-08-14.
- Preuve        : `04_PREUVES/agent-18/04_inventaire_scrapers.txt` — la liste des imports des 6 fichiers de test, et le décompte par scraper (0 fichier de test pour 12 des 13). Suite rejouée le 2026-08-19 : `Test Files 6 passed (6) / Tests 61 passed (61)`.
- Témoin négatif: le même décompte remonte bien **2 fichiers** pour `base` et **2** pour `google-maps` (mentions dans `registre-sources.test.ts` et `base-worker.test.ts`, jamais l'implémentation) — la recherche sait compter quand il y a quelque chose à compter.
- Impact        : la CI est verte sur un domaine où **rien** n'est vérifié. Le job `workers` de `ci.yml` est par ailleurs exemplaire (lint bloquant, typecheck, garde de version Playwright, `passWithNoTests: false`) — ce qui rend le vert d'autant plus trompeur : il atteste d'une rigueur d'outillage, pas d'une couverture. Le jour où l'on redéploie les workers, aucun filet n'existe sur le seul code qui parle à l'extérieur.
- Reproduction  : `cd workers && npx vitest run` puis `grep -rn "from '../src" tests/*.ts`.
- Correctif     : un test par scraper qui injecte une page HTML de fixture et vérifie l'extraction, sans réseau — **1 j** pour les 5 Playwright, **2 h** pour les 6 `http-source`. À défaut, écrire dans `MOCKS-STRATEGY.md` que ce code n'est pas testé, pour que le vert cesse de mentir — **15 min**.
- Statut        : ouvert

---

## 4. Ce que je n'ai PAS pu vérifier, et pourquoi

Cette liste est un livrable.

1. **Les deux `POST` d'annulation par HTTP.** Les quatre appels (`/scraper-runs/{id}/cancel`,
   `/campaigns/{id}/cancel`, et les deux listes) ont tous expiré : `HTTP 000` après 30 s, sur une
   API dont `artisan --version` mettait 3 min 52 s. Preuve archivée
   (`07_endpoints_annulation.txt`). J'ai contourné en rejouant **le corps exact des deux méthodes**
   en PHP — ce qui mesure la logique, **pas** le transport, ni l'authentification, ni les
   autorisations. **Un agent qui reprendra devra refaire ces deux appels authentifiés.**
   Note : le constat A-001 du dossier (500 au lieu de 401 sans authentification) rend de toute
   façon ces routes difficiles à sonder sans jeton.
2. **`WaterfallSentry` a-t-il déjà déclenché ?** Il faudrait ouvrir le projet GlitchTip/Sentry.
   Hors périmètre et interdit sur la production. J'ai mesuré ce que je pouvais mesurer : sur cette
   installation, il ne **peut** rien émettre.
3. **Le comportement réel d'un scraper Playwright.** Interdiction explicite de lancer une collecte
   vers un site tiers. Je n'ai donc jugé les 5 implémentations Playwright que par lecture : elles
   existent, elles construisent leur URL depuis un hôte en dur (google-maps, google-search,
   pages-jaunes) ou depuis une cible gardée par `ensureSsrf` (website, direction-finder). Je n'ai
   **pas** pu vérifier qu'elles extraient quoi que ce soit d'utile.
4. **Le niveau 5 de la déduplication** (cache de validation d'e-mail, TTL 30 j). Il est hors du
   chemin de collecte et relève de l'agent chargé du canal e-mail. Non joué, délibérément.
5. **La valeur réelle de `MOCK_MODE` et de `SENTRY_LARAVEL_DSN` sur le serveur de production.**
   Lecture des secrets interdite. Je me suis appuyé sur `infra/scripts/configure-prod-env.sh:35`
   (`set_env "MOCK_MODE" "false"`), qui montre l'**intention**, pas l'**état**. L'agent 40 a fait
   une comparaison `.env` serveur ↔ `.env.example` (`agent-40/16_env-serveur-vs-exemple.txt`) : sa
   mesure prime sur ma déduction.
6. **Le plan d'exécution réel de la requête de dédup e-mail à l'échelle.** Les bases locales
   contiennent **0 contact** (`axion_crm` et `axion_crm_perf4m` mesurés). Mon affirmation sur la
   perte de l'index `idx_contacts_email` (C18-006) repose sur la lecture de `pg_indexes` — l'index
   porte sur `email`, pas sur `lower(email::text)` — et non sur un `EXPLAIN ANALYZE` sur 410 481
   lignes. **Le fait est certain, son coût ne l'est pas.**
7. **Les 6 mocks Node à l'exécution.** Ils sont lus et leur condition d'armement est mesurée ;
   aucun n'a été **exécuté** dans une boucle de worker complète, faute de worker déployé et pour
   ne pas lancer de collecte.
8. **`healthcheck.ts` (17 l.) et `healthcheck-server.ts` (46 l.).** Lus, jamais exécutés : ils
   n'écoutent que dans un conteneur worker, et il n'y en a aucun. Je note simplement que
   `/healthz` répond `200` **inconditionnellement** — un worker à zéro consommateur y répondrait
   `ok`. Le repli de concurrence de `base.ts:136` évoque explicitement ce défaut et le corrige
   pour la concurrence ; `/healthz` reste, lui, aveugle.

---

## 5. Ce que je n'ai pas re-rapporté, et où c'est déjà écrit

- **Secret HMAC vide par défaut des deux côtés du pont interne** — déjà mesuré par l'agent 12
  (`11_GRILLES/routes-entete.md:174-179`) et l'agent 40. Ma lecture le confirme
  (`ScraperResultController.php:37` → `env(..., '')` ; `result-sender.ts:21` → `?? ''`).
- **`SENTRY_LARAVEL_DSN` absent** — déjà relevé par l'agent 16
  (`11_GRILLES/agent-16_audit-ai-act.md:216`). C18-014 n'ajoute que la portée waterfall.
- **A-001, A-002, A-003** du dossier commun — non repris.
- **Piège 10 tel qu'énoncé** (« CI en `en_US.utf8` ») — **corrigé** au §2.② : la CI de ce dépôt est
  en `C`, comme la production. Le piège reste réel, sa cause est autre.

---

## 6. Deux erreurs de méthode que j'ai commises, et corrigées

Je les consigne parce qu'un lecteur pressé pourrait les reproduire, et parce que les deux
produisaient un « rien trouvé » d'apparence honnête.

1. **`Env::getRepository()->set()` ne mesure rien.** Ma première matrice des mocks (§[6] de
   `01_dryrun_dedup_eligibilite.txt`) manipulait le dépôt d'environnement de Laravel pour simuler
   des valeurs de `MOCK_MODE`. Ce dépôt est **immuable** : les huit cas ont tous mesuré la même
   valeur (`true`, venue du `.env`) et rendu « 5 mocks sur 5 » de façon parfaitement cohérente —
   donc parfaitement crédible, et fausse. Refaite avec de vraies variables d'environnement du
   processus (`docker exec -e`), la mesure donne un résultat **différent** : `FALSE`, `0` et la
   chaîne vide arment le réel. L'avertissement est consigné en tête du fichier de preuve d'origine.
2. **Un `grep -r` de dépôt saute les fichiers *gitignorés*.** Ma première vérification de
   `SENTRY_LARAVEL_DSN` était de cette forme : elle n'a jamais ouvert `.env` ni `backend/.env`.
   Elle aurait rendu le même « absent » que la clef y soit ou non. Refaite fichier par fichier,
   avec un témoin positif (`VITE_SENTRY_DSN`, trouvée x1 dans les trois fichiers) qui prouve que
   le contrôle sait lire ces fichiers. **La conclusion est inchangée** — mais elle ne reposait sur
   rien avant cette reprise.

Dans les deux cas, la première mesure n'était pas *approximative* : elle était **muette et
plausible**. C'est exactement la forme d'erreur que la règle du témoin négatif existe pour
attraper, et elle l'a attrapée les deux fois.
