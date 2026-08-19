# AGENT 5 — Auditeur du plan du 13 août

> Périmètre : les plans du 13-16 août dans `C:\Users\willi\Documents\Projets\Axion-IA\_PLANS\`
> (`PLAN-CRM-contacts-candidats`, `ORDRE-MISSION-AUTOPILOT-CRM`, `CONCEPTION-console-crm-ux`,
> `AUDIT-emails-site-vs-crm`, `RUNBOOK-ACTIVATION-CRM`, `SCENARIOS-E2E-CRM`,
> `PLAN-MIGRATION-DECALAGE-2H-CRM`), plus le journal d'exécution
> `_SESSIONS/2026-08-13_AUTOPILOT-CRM-journal.md` et le rapport de clôture
> `_REPORTS/2026-08-17_CLOTURE-PLAN-CRM-E2E2.md` — qui portent les déclarations « livré ».

## 0. Références de mesure — relues, jamais recopiées

| Objet | Valeur relue le 2026-08-19 | Commande |
|---|---|---|
| `main` du dépôt CRM | **`b53338c`** — `c0c453d` est **derrière de 2 commits** | `git log --oneline -1` |
| Delta `c0c453d..b53338c` | **1 fichier, `.md` uniquement** (`_AUDIT/…CNIL-ART33.md`, +20/-1) | `git diff --stat c0c453d HEAD` |
| Conséquence | **Le code applicatif de `b53338c` est identique à `c0c453d`.** Toutes les mesures de code ci-dessous valent pour `c0c453d`. | — |
| Dépôt site (hors référence du dossier) | `axionia` = `eb754332` | `git log --oneline -1` |
| Production CRM | 9 drapeaux `CRM_*` à `true`, `DB_TIMEZONE=Europe/Paris`, `APP_ENV=production` | `ssh root@46.62.248.239 docker inspect axion-crm-api` (lecture seule) |

⚠️ **Le dossier commun nomme `c0c453d` comme « `main` mesuré ». Ce n'est plus vrai au moment où
j'écris** (deux commits documentaires poussés depuis). Le constat est sans effet sur le code, mais
il illustre la règle 13 du dossier : ne jamais faire confiance à un SHA écrit dans un document.

**Preuves brutes** : `_AUDIT/2026-08-18_AUDIT-360/04_PREUVES/agent-05/`.

---

## 1. GRILLE — une ligne par lot / livrable déclaré

Verdicts : **LIVRÉ** (existe, câblé, prouvé) · **PARTIEL** (existe, mais l'objectif du plan n'est
pas atteint) · **NON LIVRÉ** (absent, mort, ou jamais exécuté) · **DÉCLARÉ À TORT** (un document
affirme livré ce qui ne l'est pas).

**Décompte** — 12 blocs de lots audités (Gate 0 · L0→L6 du plan · L7 · 2 lots ajoutés hors plan ·
migration du décalage de 2 h · règles et critères de fin de l'ordre de mission), **65 livrables** :

| Verdict | Nombre |
|---|---|
| **LIVRÉ** | **46** |
| **PARTIEL** | **7** |
| **NON LIVRÉ** | **9** |
| **DÉCLARÉ À TORT** | **2** |
| **NON VÉRIFIÉ** (raison au §3) | **1** |

### 1.1 Gate 0 — réparer la CI (ordre de mission, prérequis absolu)

| Lot | Ce que le plan promet | Ce qui existe réellement | Commande jouée | Verdict | Preuve |
|---|---|---|---|---|---|
| Gate 0.1 | Retirer tous les `\|\| true` / `continue-on-error` des étapes de qualité | `ci.yml` : `composer install`, PHPStan, Pint, Pest, `pnpm lint/test/build`, workers — tous BLOQUANTS, aucun neutralisant | `grep -n "continue-on-error\|\|\| true" .github/workflows/ci.yml` → 0 sur les étapes de qualité | **LIVRÉ** | en-tête daté de `ci.yml` l. 1-28 |
| Gate 0.2 | Baseline PHPStan figée, niveau non baissé | `composer analyse` niveau 8 → `[OK] No errors` dans le run CI réel | `gh run view 32233358457 --log \| grep "No errors"` | **LIVRÉ** | `04_PREUVES/agent-05/ci-run-pest.txt` |
| Gate 0.3 | `deploy-direct-ssh.yml` : `needs: [ci]`, `migrate --force` bloquant, vérif post-deploy | `needs: [ci]` l. 74 ; `migrate --force` sans `\|\| true` l. 213 ; `migrate:status` post-deploy l. 257 | `grep -n "needs:\|migrate --force" .github/workflows/deploy-direct-ssh.yml` | **LIVRÉ** | — |
| Gate 0.4 | Preuve par la rougeur (test cassé ⇒ CI rouge) | Non rejouée par moi (elle exigerait d'ouvrir une PR cassée sur `main` — hors mandat d'audit). Substitut mesuré : la CI exécute réellement **780 tests / 6 503 assertions** en 26,02 s sur un run du 2026-08-19 | `gh run view 32233358457 --log \| grep "Tests:"` | **LIVRÉ** (rougeur non rejouée, cf. §3) | `ci-run-pest.txt` |
| Gate 0.5 | Quarantaine des tests : 23 fichiers exclus de la CI, à lever | `phpunit-ci.xml` n'exclut plus **aucun** fichier ; seule différence avec `phpunit.xml` : `executionOrder="default"` | `cat backend/phpunit-ci.xml` | **LIVRÉ** | en-tête daté 2026-08-16 |

### 1.2 Plan L0 — prérequis CÔTÉ SITE (§2.0)

> ⚠️ L'ordre de mission impose « **L0 = SITE, prérequis** » comme **premier** lot. Le journal
> d'exécution le déclare hors périmètre (« Le L0 du plan maître N'EST PAS dans le périmètre de
> cette session », l. 341-345) et **renumérote** tous les lots. Le contenu a néanmoins été livré
> plus tard, sous d'autres numéros. Cf. constat **A05-007**.

| Lot | Ce que le plan promet | Ce qui existe réellement | Commande jouée | Verdict | Preuve |
|---|---|---|---|---|---|
| L0-1 | `contactEmailHash` déterministe (SHA-256 + sel) sur `submissions`, `job_applications`, `podcast_requests` | `src/lib/security/email-hash.ts` existe ; `contactEmailHash` lu/écrit par `rgpd-erase.ts`, `rgpd-export-chat.ts`, la synchro CRM | `grep -rn "contactEmailHash" src/lib` (dépôt `axionia`) | **LIVRÉ** | — |
| L0-2 | Normaliser l'email à l'écriture dans tous les points de capture | `hashEmailForLookup` normalise avant hachage ; la normalisation traverse les 14 points via `@/server/crm-sync` | `grep -rl "server/crm-sync" src` → 24 fichiers | **LIVRÉ** | — |
| L0-3a | Correctif PII : le chatbot écrivait `submissions` sans `encryptPii` | `capturer-lead.ts` l. 127-130 : `contactName/Email/Phone` passent par `encryptPii` | `grep -n "encryptPii" src/server/chatbot/tools/capturer-lead.ts` | **LIVRÉ** | — |
| L0-3b | Correctif PII : export art. 15 filtrant une colonne chiffrée en clair | `rgpd-export-chat.ts` l. 53 filtre sur `contactEmailHash` **OR** `contactEmail` | même grep | **LIVRÉ** | — |
| L0-4a | Registre versionné des consentements (`consent_events`, §2.8.1) | Modèle Prisma `ConsentEvent` → table `consent_events` ; module `src/lib/consents/index.ts` (append-only, `personKey`, jamais d'adresse en clair) | `grep -n "consent_events" prisma/schema.prisma` | **LIVRÉ** | — |
| L0-4b | « Un **module unique** portant les **textes** versionnés » — 5 constantes éparses à supprimer | Le registre existe, mais les versions restent **8 constantes en dur** dispersées dans `src/features/*/actions.ts`, `src/lib/commercial-application/model.ts`, `src/server/vivier/config.ts` — soit **plus** qu'avant le plan | `grep -rn "CONSENT_VERSION\s*=" src` → 8 occurrences dans 8 fichiers | **PARTIEL** | — |

### 1.3 Plan L1 (= « L0 » de la session) — étanchéité P0 (§2.1)

| Lot | Ce que le plan promet | Ce qui existe réellement | Commande jouée | Verdict | Preuve |
|---|---|---|---|---|---|
| P0-a | `FORCE ROW LEVEL SECURITY` sur les tables scopées | Migration `2026_08_14_000001_harden_workspace_isolation.php` ; en base locale **`relforcerowsecurity = t`** sur toutes les tables scopées (`activities`, `candidates`, `companies`, `contacts`…) | mesure d'agent-07, `04_PREUVES/agent-07/F09_rls_reel.txt` | **LIVRÉ** | — |
| P0-b | Rôle DB applicatif non-propriétaire `axion_app` | Rôle existe, `super=false bypassrls=false` ; `config/database.php` expose `pgsql_owner` / `pgsql_app` ; drapeau `CRM_DB_APP_ROLE_ENABLED=true` **en production** | `ssh … docker inspect axion-crm-api`, `agent-07/F09_roles.txt` | **LIVRÉ** | `prod-volumes-lots.txt` (env) |
| P0-c | Policies **sans** fallback permissif | Policy stricte `workspace_id::TEXT = NULLIF(current_setting(…),'')` ; contrôle croisé d'agent-07 : requête sans contexte → **0 ligne** sur `companies`, `email_verification_logs`, `health_practitioners` | `agent-07/F09_fuite_sans_contexte.txt` | **LIVRÉ** | — |
| P0-d | Contexte workspace fiable + global scope Eloquent + échec bruyant hors HTTP | `app/Support/WorkspaceContext.php` (179 l.), `WorkspaceScope`, `BelongsToWorkspace`, `RunsInWorkspace`, `MissingWorkspaceContextException` ; `CRM_STRICT_WORKSPACE_SCOPE=true` en production | `ls app/Support app/Models/Scopes …` ; `ssh … docker inspect` | **LIVRÉ** | — |
| P0-test | 15 tests d'étanchéité, sortis de quarantaine, prouvés rouges d'abord | `tests/Feature/RlsTest.php` (386 l.) présent et exécuté par la CI (aucune exclusion) | `cat backend/phpunit-ci.xml` ; run CI 780 tests | **LIVRÉ** | — |

*(La profondeur de l'étanchéité relève d'agent-07 — F09. Je ne mesure ici que la livraison.)*

### 1.4 Plan L2 (= « L1 » de la session) — socle de données (§2.2)

| Lot | Ce que le plan promet | Ce qui existe réellement | Commande jouée | Verdict | Preuve |
|---|---|---|---|---|---|
| L2-1 | Workspace `vivier-candidats` + table `candidates` + pivot `candidate_tag` (RLS forcée, CHECK, trigger) | Migration `…000003_crm_socle_vivier_candidats.php`, tables `candidates` et `candidate_tag` présentes, modèle `App\Models\Candidate`, contrôleur `Crm\CandidatesController`, routes `GET /v1/crm/candidates{,/counts}` | `\dt public.*` ; `grep -n candidat routes/api.php` | **LIVRÉ** (structure) | `tables-lots.txt` |
| L2-1bis | Le vivier accueille les candidats | **`candidates` = 0** et **`candidate_tag` = 0** en production, 5 jours après ouverture du flux (`CRM_INGEST_CANDIDATES_ENABLED=true`) | `ssh … psql -c "SELECT count(*) FROM candidates"` | **NON LIVRÉ** (structure vide) — **A05-006** | `prod-volumes-lots.txt` |
| L2-2 | `relation_type` (8 valeurs business, CHECK) sur `companies` | Colonne + CHECK posés ; `app/Crm/Taxonomy.php` (200 l.) source de vérité unique, test comparant les CHECK réels aux constantes | migration `…000002` ; `tests/Feature/Crm/SocleCrmTest.php` | **LIVRÉ** | — |
| L2-2bis | La taxonomie classe les fiches | **0** fiche sur 4 295 349 porte un `relation_type` autre que le défaut `prospect` | `ssh … psql -c "SELECT count(*) FROM companies WHERE relation_type <> 'prospect'"` | **PARTIEL** (colonne vivante, usage nul) | `prod-volumes-lots.txt` |
| L2-3 | `lifecycle_stage` (6 étapes) + **règles de passage**, dont « client → dormant, 12 mois sans interaction, règle batch mensuelle » (§2.2b) | Colonne + CHECK + index posés. **Aucune commande, aucun job, aucune tâche planifiée n'implémente la règle batch** ; les seuls écrivains sont l'ingestion (qui écrit `nouveau`) et l'action de masse console. En production : **4 295 349 fiches, toutes à `nouveau`** | `grep -rn "dormant" app/ routes/console.php` → 3 hits, tous descriptifs ; `SELECT lifecycle_stage, count(*) FROM companies GROUP BY 1` | **PARTIEL** — colonne LIVRÉE, **règle NON LIVRÉE** — **A05-004** | `prod-volumes-lots.txt` |
| L2-4 | `person_key` sur `contacts` (clé de rapprochement des personnes, §2.4) — déclarée « **livrée le 2026-08-14** » et invoquée pour **justifier le report de l'étape 5 bis** | Colonne posée, écrite **uniquement** par `Crm/Ingest/ContactUpserter.php` (ingestion site). **Aucun backfill** (ni migration, ni commande). En production : **1 319 567 contacts, dont 410 481 avec email, et 0 avec `person_key`** | `ssh … psql -c "SELECT count(*) contacts_total, count(person_key), count(email) FROM contacts"` ; `grep -ln person_key database/migrations/*.php` | **NON LIVRÉ** (colonne morte sur tout le stock) — **A05-001** | `prod-volumes-lots.txt` |
| L2-5 | `legal_basis` / `consent_version` / `consent_at` / `consent_text_ref` | Colonnes posées, écrites par `ContactUpserter`, `SiteSyncIngestService`, `ArbitrageController`, `SiteGdprService` | `grep -rl consent_text_ref app/` | **LIVRÉ** | — |
| L2-6 | `first_info_at` — art. 14 RGPD, horodatage de la première communication vers une fiche collectée | Colonnes posées sur `companies` **et** `contacts`, avec `COMMENT ON COLUMN` explicite. **Zéro écrivain, zéro lecteur dans tout le dépôt** (backend, frontend, workers, tests). Production : **0 ligne renseignée** | `Grep "first_info_at\|firstInfoAt"` (tout le dépôt) → **6 hits, tous dans la migration** ; `SELECT count(first_info_at) FROM companies` → 0 | **NON LIVRÉ** (code mort) — **A05-002** | `first_info_at_*.txt`, `prod-volumes-lots.txt` |
| L2-7 | `field_origins` (`declared` > `scraped`) | Colonne `NOT NULL` posée, écrite par `ContactUpserter`, `SiteSyncIngestService`, `ScrapedRecordIngestService` | `grep -rl field_origins app/` | **LIVRÉ** | — |
| L2-8 | Tags gouvernés : `tags.is_locked`, `namespace` généré, `category` accepte `candidate`, seeder versionné (30 business + 12 vivier) | Migration `…000004` + `database/seeders/GovernedTagsSeeder.php` (183 l.). Production : **217 tags, dont 60 verrouillés, 38 en namespace `src`, 14 en catégorie `candidate`** | `ssh … psql -c "SELECT count(*), count(*) FILTER (WHERE is_locked) … FROM tags"` | **LIVRÉ** | `prod-volumes-lots.txt` |
| L2-9 | `opt_out.scope` (`business`\|`vivier`) + `opt_out.email_hash` | Colonnes posées ; `Crm/Rgpd/SiteGdprService`, `Crm/Outbound/ConsentOutboundRecorder`, `ScrapedRecordIngestService` les écrivent. Production : **`opt_out` = 0 ligne** — le mécanisme n'a jamais été exercé | `\d opt_out` ; `SELECT count(*) FROM opt_out` | **LIVRÉ** (jamais exercé) | `prod-volumes-lots.txt` |
| L2-10 | `activities` étendue (timeline) : `person_key`, `external_ref` UNIQUE, `kind` fermé, `occurred_at`, `subject_type/id`, `payload` | Colonnes posées, `PersonTimelineController` et `ArbitrageController` les lisent. Production : **3 activités issues du site**, toutes `form_submission` | `SELECT kind, count(*) FROM activities WHERE external_ref LIKE 'site:event:%' GROUP BY kind` | **LIVRÉ** (structure) | `prod-volumes-lots.txt` |
| L2-11 | Index `CONCURRENTLY` hors transaction sur `companies` (4,29 M) et `contacts` (1,32 M) | Migration `…000005_crm_socle_index_concurrents.php` présente, avec nettoyage préalable des index INVALIDES | `ls backend/database/migrations` | **LIVRÉ** | — |
| L2-12 | `contacts.company_id` **NULLABLE** + colonne générée à double branche (exigence actée : « la synchro couvre TOUT, newsletter comprise ») | **`company_id` est toujours `NOT NULL`.** Report assumé (étape 5 bis), motivé par le verrou mesuré 2,5-5 min sur CPX22 | `SELECT is_nullable FROM information_schema.columns WHERE table_name='contacts' AND column_name='company_id'` → `NO` | **NON LIVRÉ** (report assumé et documenté) | `contacts_colonnes.txt` |

### 1.5 Plan L3 (= « L2 » de la session) — ingestion CRM (§2.5)

| Lot | Ce que le plan promet | Ce qui existe réellement | Commande jouée | Verdict | Preuve |
|---|---|---|---|---|---|
| L3-1 | `POST /internal/site-sync` signé HMAC, patron `scraper-result`, rate limiter `internal`, upsert idempotent | Route déclarée l. 313 de `routes/api.php`, `throttle:internal`, gatée par `CRM_INGEST_ENABLED` (503 sinon) ; `Crm/Ingest/SiteSyncIngestService.php` ; tests `tests/Feature/Crm/SiteSyncIngestTest.php` exécutés par la CI | `grep -n internal routes/api.php` ; run CI 780 tests | **LIVRÉ** | — |
| L3-2 | Volet `gdpr` du même canal | Route `POST /internal/site-sync/gdpr` l. 319, `Crm/Rgpd/SiteGdprService.php`, `tests/Feature/Crm/SiteGdprTest.php` | idem | **LIVRÉ** | — |
| L3-3 | Garde CNIL : rejeter toute fiche candidat sans `consent_version` v2 — testée ROUGE d'abord | Versions v2 dans `Taxonomy.php` ; test dédié dans `SiteSyncIngestTest.php`. Preuve par la rougeur : consignée au journal (l. 876-932), **non rejouée par moi** | — | **LIVRÉ** (rougeur non rejouée, cf. §3) | — |
| L3-4 | **La synchro automatique crée des fiches** | En 5 jours d'activation, **3 événements reçus, 3 tombés en arbitrage manuel, 0 fiche créée**. `upsertBusiness()` l. 152-155 : « Sans SIREN, on ne crée RIEN ». Or le plan lui-même écrit que le SIREN est « rarement rempli » (§1.2) et qu'aucun abonné newsletter n'en a | `SELECT count(*) FILTER (WHERE payload->'pending_match' IS NOT NULL), count(*) FROM activities WHERE external_ref LIKE 'site:event:%'` → `3 \| 3` ; `SELECT count(*) FROM contacts WHERE external_ref IS NOT NULL OR person_key IS NOT NULL` → `0` | **NON LIVRÉ** (canal vivant, effet nul) — **A05-003** | `prod-volumes-lots.txt` |

### 1.6 Plan L4 (= côté SITE) — outbox et observabilité (§2.9)

| Lot | Ce que le plan promet | Ce qui existe réellement | Commande jouée | Verdict | Preuve |
|---|---|---|---|---|---|
| L4-1 | Outbox transactionnelle + job BullMQ `crm-sync` + 14 points de capture | `src/server/crm-sync/index.ts`, worker `src/server/queue/workers/crm-sync-worker.ts`, **24 fichiers** importent `@/server/crm-sync` (dont les 8 `features/*/actions.ts`, Calendly, chatbot, RGPD, vivier) | `Grep "server/crm-sync"` (dépôt `axionia`) | **LIVRÉ** | — |
| L4-2 | Webhook entrant site (consentements bidirectionnels) | `src/app/api/internal/crm-webhook/route.ts` + son test | idem | **LIVRÉ** | — |
| L4-3 | Observabilité : statuts, carte console, catégorie Telegram `CRM_SYNC_ALERT` | 5 genres d'anomalie typés (`gave_up`, `backlog`, `reconcile_gap`, `reconcile_failed`, `scan_capped`), routage Telegram `crm-sync`, écran admin `synchro-crm/page.tsx`. Côté CRM : `ObservabilityController` (lecture seule, sans alerte — c'est le site qui alerte) | `Grep CRM_SYNC_ALERT` ; `grep -n "public function" ObservabilityController.php` | **LIVRÉ** | — |

### 1.7 Plan L5 — consentements v2, reprise du stock, RGPD outillé (§2.3, §2.8)

| Lot | Ce que le plan promet | Ce qui existe réellement | Commande jouée | Verdict | Preuve |
|---|---|---|---|---|---|
| L5-1 | Textes v2 servis, versions FERMES `careers-v2-2026-08-13` / `memo-v2-2026-08-13` | `src/features/job-application/actions.ts` l. 43 et `src/lib/commercial-application/model.ts` l. 26 portent exactement ces deux valeurs | `grep -rn "CONSENT_VERSION\s*=" src` | **LIVRÉ** | — |
| L5-2 | Décision (b) actée : email d'information aux 71 candidatures + opposition 30 j + intégration à J+30 | Mécanique écrite (`src/server/vivier/stock.ts`, `opposition.ts`, `VIVIER_STOCK_CONSENT_VERSION = "vivier-stock-2026-08-14"`, PR #63). **Drapeau `VIVIER_STOCK_ENABLED` laissé à OFF** (décision de Will : envoi d'e-mails réels). Aucun des 71 candidats n'est dans le vivier : `candidates` = 0 | `SELECT count(*) FROM candidates` ; rapport de clôture §8.3 | **PARTIEL** (mécanique livrée, effet nul par décision) | `prod-volumes-lots.txt` |
| L5-3 | RGPD art. 15/17 en **une action bi-système** | `Crm/Rgpd/SiteGdprService.php` + endpoint dédié + `tests/Feature/Crm/SiteGdprTest.php` (export, effacement, opt-out) exécutés par la CI. Production : `rgpd_requests` = 0 (jamais exercé en réel) | run CI ; rapport de clôture §7 | **LIVRÉ** (jamais exercé) | — |
| L5-4 | Purges automatiques par univers : vivier 2 ans + refusés J+90, business 3 ans | `RgpdPurgeVivier` / `RgpdPurgeBusinessProspects`, planifiées `monthlyOn(2, …)` avec double verrou (`skip()` + refus de la commande) ; `WorkspaceContext::run()` posé explicitement ; **4 tests** dans `SiteGdprTest.php` (dont « refusent tant que `CRM_PURGE_ENABLED` est à OFF »). `CRM_PURGE_ENABLED=true` en production | `sed -n '144,170p' routes/console.php` ; `grep -rn "purge-vivier" tests/` | **LIVRÉ** | `purges-sans-test.txt` |
| L5-5 | Piège actée : « un test d'intégration vérifie qu'une purge SANS contexte échoue BRUYAMMENT plutôt que de no-op — le test doit rougir d'abord » (§2.1) | Le mécanisme est testé **génériquement** dans `RlsTest.php` (« en mode strict, une requête sans contexte workspace échoue bruyamment »), pas sur l'objet « purge ». Aucun test ne prive une purge de son contexte | `grep -rn "sans contexte\|MissingWorkspaceContext" tests/Feature/Commands tests/Feature/Rgpd` → **aucun** | **PARTIEL** | `purges-sans-test.txt` |

### 1.8 Plan L6 — console CRM complète (§2.11) et conception UX v2

| Lot | Ce que le plan promet | Ce qui existe réellement | Commande jouée | Verdict | Preuve |
|---|---|---|---|---|---|
| L6-1 | Vues par type, hub de contacts | `/console/contacts` → `ContactsHubPage`, `ContactsHubController`, `Crm/Console/CompteursHub.php` | `grep -n "path:" frontend/src/app/routeTree.tsx` | **LIVRÉ** | — |
| L6-2 | Vivier étanche en console | `/console/vivier` → `CandidatesPage`, entrée de nav conditionnée à l'appartenance à l'univers (403 jamais offert au clic) | `sed -n '140,160p' Sidebar.tsx` | **LIVRÉ** | — |
| L6-3 | Arbitrage des rapprochements | `/console/arbitrage` → `ArbitragePage`, `ArbitrageController`. Production : **15 fiches en attente d'arbitrage**, dont les 3 seuls événements du site | `SELECT count(*) FROM activities WHERE payload->'pending_match' IS NOT NULL` → 15 | **LIVRÉ** | `prod-volumes-lots.txt` |
| L6-4 | **Fiche 360° + timeline unifiée** (§2.6) | `/console/personnes/$personKey` → `PersonTimelinePage` + `PersonTimelineController` (218 l., soigné). **Mais la route est indexée par `person_key`, et 0 des 1 319 567 contacts de production en porte une** : le hub n'affiche le lien que `si contact.person_key !== null` (`ContactsHubPage.tsx` l. 209). L'écran est donc **inatteignable pour 100 % du stock** | `SELECT count(person_key) FROM contacts` → 0 ; `sed -n '205,225p' ContactsHubPage.tsx` | **NON LIVRÉ** (écran réel, jamais offert) — **A05-001** | `prod-volumes-lots.txt` |
| L6-5 | Filtres combinables (dates, région, départements, tags) | PR #86 fusionnée ; `tests/Feature/FiltresDatesEtGeoTest.php` | `gh pr view 86` | **LIVRÉ** | — |
| L6-6 | Actions de masse | PR #87 fusionnée ; `BulkController`, `tests/Feature/ActionsDeMasseTagsTest.php` | `gh pr view 87` | **LIVRÉ** | — |
| L6-7 | Rôles par univers + masquage des coordonnées | PR #85 fusionnée ; `tests/Feature/MasquageCoordonneesTest.php`, `EtancheiteUniversTest.php`, `ExportPermissionTest.php` | `ls tests/Feature/` | **LIVRÉ** | — |
| L6-8 | Constructeur de segments (extension `email_audiences`) | `/audiences`, `AudienceBuilderPage`, `AudienceBuilderService` + tests (PR #142) | `grep -n "path:" routeTree.tsx` | **LIVRÉ** | — |
| L6-9 | Réutiliser `saved_views` (§2.12, ligne L6) | La seule route `saved_views` répond 200 liste vide au lieu de 501 — **déjà ouvert en A-002**, non re-rapporté ici | (agent 1) | **NON LIVRÉ** (cf. A-002) | — |
| L6-10 | **Conception UX v2** : trois espaces de premier niveau 🔍 Collecte · 👥 Contacts · 📤 Campagnes (grisé « bientôt »), URLs `/b/prospection`, `/b/collecte/sources`, `/b/campagnes`, univers coloré bleu/violet, base froide séparée | La barre livrée a **six** groupes (`Aujourd'hui · Contacts · Collecte · Pilotage · Conformité · Réglages`), **aucune** URL `/b/…`, **aucun** onglet Campagnes réservé/grisé (le mot « Campagnes » a été renommé « Collectes » et pointe le scraping), **aucun** sélecteur d'univers coloré. Refonte assumée et documentée dans `Sidebar.tsx` (« Étape 0, ligne 3 bis (F17) »), mais **le document de conception reste cité comme référentiel n°4 de l'ordre de mission** | `grep -n "path:" routeTree.tsx` ; `sed -n '55,160p' Sidebar.tsx` ; `grep -n "Collecte\|Campagnes" src/components src/app` | **DÉCLARÉ À TORT** (le référentiel décrit une console qui n'existe pas) — **A05-005** | — |

### 1.9 L7 — interdit par l'ordre de mission

| Lot | Ce que le plan promet | Ce qui existe réellement | Commande jouée | Verdict | Preuve |
|---|---|---|---|---|---|
| L7 | « EXCLU — zéro travail dessus, ne jamais le reproposer » | Aucun moteur d'envoi. `/cold-email` et `/linkedin` restent des stubs 501 assumés ; `/campaigns` désigne les **campagnes de collecte** (scraping, Sprint 19.7, antérieur au plan), pas l'e-mailing ; le renommage « Campagnes → Collectes » a été fait pour éviter la collision | `sed -n '280,305p' routes/api.php` ; `Sidebar.tsx` l. 70 | **RESPECTÉ** | — |

### 1.10 Lots hors plan, ajoutés en cours d'exécution

| Lot | Ce que le plan promet | Ce qui existe réellement | Commande jouée | Verdict | Preuve |
|---|---|---|---|---|---|
| « L3 » session — funnel de collecte + registre `scraping_sources` | *N'existe dans aucun des sept plans de mon périmètre* — issu de `AUDIT-scraping-harmonisation` | Migration `…000006_crm_scraping_sources.php`, table peuplée : **17 sources** en production ; `Crm/Scraping/ScrapedRecordIngestService.php` + `tests/Feature/Crm/ScrapedIngestTest.php` | `SELECT count(*) FROM scraping_sources` → 17 | **LIVRÉ** (hors périmètre du plan) | `prod-volumes-lots.txt` |
| « L5 » session — mini-outbox CRM → site | §2.5 « synchro bidirectionnelle des consentements » | Migration `…000007_crm_outbound_events.php`, `CrmFlushOutbound` planifiée `everyFiveMinutes()` avec double verrou, `CRM_OUTBOUND_ENABLED=true` en production, `tests/Feature/Crm/CrmOutboundTest.php`. Production : **`crm_outbound_events` = 0** (jamais exercé) | `SELECT count(*) FROM crm_outbound_events` → 0 | **LIVRÉ** (jamais exercé) | `prod-volumes-lots.txt` |
| Étape 14 — backfill des tags `src:` sur 4,29 M | Runbook, dernier acte technique | **4 294 895** lignes `company_tag` portant `assigned_by='backfill-src'` — le nombre déclaré au journal, au chiffre près | `SELECT count(*) FILTER (WHERE assigned_by='backfill-src') FROM company_tag` | **LIVRÉ** | `prod-volumes-lots.txt` |

### 1.11 Plan de migration du décalage de 2 h (2026-08-16)

| Lot | Ce que le plan promet | Ce qui existe réellement | Commande jouée | Verdict | Preuve |
|---|---|---|---|---|---|
| 2h-1 | « Le code est livré en PR #90, **INERTE** ; statut : RIEN N'EST APPLIQUÉ EN PRODUCTION » | PR #90 fusionnée. `config/database.php` l. 102 : `'timezone' => env('DB_TIMEZONE')`. **En production, `DB_TIMEZONE=Europe/Paris` est posée** — le correctif est donc ACTIF, contrairement à ce que le plan affirme encore aujourd'hui | `ssh … docker inspect axion-crm-api \| grep DB_TIMEZONE` | **LIVRÉ** — mais le **plan est périmé** | env prod |
| 2h-2 | Reprise des données via `horodatages:corriger` (simulation par défaut) | `app/Console/Commands/CorrigerHorodatages.php` existe, avec garde « si `DB_TIMEZONE` est déjà actif, ne pas corriger ». **Je n'ai pas pu établir si la reprise a été jouée** (cf. §3) | — | **NON VÉRIFIÉ** | — |
| 2h-3 | Parité atelier local / production | En local, `DB_TIMEZONE` est **NULL** : session PG en `Etc/UTC` face à une application en `Europe/Paris`. Le défaut de +2 h reste reproductible en local, et le correctif y est inerte ; seules les deux configurations PHPUnit posent la variable | `docker exec axion-crm-postgres psql -tAc "SHOW TimeZone"` → `Etc/UTC` ; `env DB_TIMEZONE` du conteneur api → NULL ; `grep -rn DB_TIMEZONE docker-compose*.yml` → aucun | **PARTIEL** — **A05-008** | `horodatage-sonde.txt` |

### 1.12 Ordre de mission — règles d'exécution et critères de fin

| Lot | Ce que l'ordre de mission promet | Ce qui existe réellement | Commande jouée | Verdict | Preuve |
|---|---|---|---|---|---|
| OM-1 | « Ordre des lots STRICT : **L0 = SITE** → L1 = CRM durcissement → L2 → … → L6 » | Le journal renumérote dès la première ligne du premier lot : « L0 ici = durcissement RLS (le L1 du plan maître) ; L1 ici = socle (le L2 du plan) ». Les PR #56 → #62 portent les titres `feat(L0)` … `feat(L6)` avec la numérotation **de session**. Deux systèmes de numérotation coexistent dans les documents de référence, sans table de correspondance ailleurs qu'au fil du journal | `gh pr list --state merged` (titres) ; journal l. 341-345 | **DÉCLARÉ À TORT** — **A05-007** | — |
| OM-2 | Une PR par lot, fusionnée dès verte ET INERTE, chaque fusion = un déploiement vérifié | **PR #53 → #63 toutes MERGED**, une par lot, SHA de fusion relus un par un | `gh pr view 53..63 --json state,mergeCommit` | **LIVRÉ** | — |
| OM-3 | Tout code livré neutralisé par drapeau, défaut OFF, testé (drapeaux OFF ⇒ comportement identique) | `config/crm.php` : `db_app_role`, `strict_workspace_scope`, `ingest.enabled`, `backfill_enabled`, `outbound_enabled`, `console_v2` — tous `env(…, false)` ; 5 tests d'inertie dans `RlsTest.php` | `grep -n "CRM_.*_ENABLED" config/crm.php` | **LIVRÉ** | — |
| OM-4 | Séquence finale : **E2E n°1 vert → E2E n°2 (regard neuf) vert → bascule des drapeaux** | Les 9 drapeaux ont été basculés les 14-15/08 ; l'E2E n°2 a été joué le **17/08**, trois jours **après** la mise en service, et il a trouvé 4 défauts réels dont 2 bloquants. Le rapport de clôture le constate lui-même et conclut « **NON CLOS** » | `_REPORTS/2026-08-17_CLOTURE-PLAN-CRM-E2E2.md` §VERDICT, §8.1 | **NON LIVRÉ** (contrôle joué après la mise en service) | rapport de clôture |
| OM-5 | « Tests UI depuis la console : à CHAQUE écran livré, parcours réel au Playwright (desktop 1440 + mobile <1024), captures REGARDÉES » | 16 spécifications Playwright existent dans `frontend/tests/e2e/`. **Deux seulement** (`a11y.spec.ts`, `navigation.spec.ts`) sont exécutées par un pipeline (`a11y.yml`). Les **14 autres** — dont `console-locale.spec.ts`, seule couverture automatisée des 4 écrans de la console v2, et `rgpd.spec.ts`, `audiences-builder.spec.ts`, `global-search.spec.ts` — ne sont lancées par **aucun** workflow | `ls tests/e2e/*.spec.ts` (16) vs `grep -rho "tests/e2e/[a-z0-9-]*\.spec\.ts" .github/workflows/` (2) | **PARTIEL** — **A05-009** | `e2e-specs-non-executees.txt` |
| OM-6 | Critère de fin : les deux E2E 100 % verts → rapport « PRODUCTION READY » | Le rapport de clôture écrit **« Verdict : NON CLOS »** et laisse 4 points ⛔ ouverts au §8.2 (rejouer §E, mesurer §F.6 à 100 000+ lignes, trancher 2 arbitrages, D-11). D-11 a été corrigé depuis (PR #166) ; les trois autres restent ouverts à ma connaissance | `grep -n "NON CLOS" _REPORTS/…` ; `gh pr view 166` | **NON LIVRÉ** | rapport de clôture |

---

## 2. CONSTATS

### [A05-001] La fiche 360° et le rapprochement par `person_key` sont inatteignables : 0 contact sur 1 319 567 porte une clé
- Sévérité      : S1 grave
- Domaine       : backend / interface
- Référence     : `main c0c453d` (code identique à `b53338c`), production `api.axion-crm-pro.com`
- Emplacement   : `backend/database/migrations/2026_08_14_000002_crm_socle_taxonomie_business.php:103` · `backend/app/Crm/Ingest/ContactUpserter.php:101` · `frontend/src/features/crm-console/ContactsHubPage.tsx:209`
- Constat       : la colonne `contacts.person_key` n'est écrite que par l'ingestion du site ; aucune migration ni commande ne l'a calculée pour le stock existant, et en production elle vaut `NULL` sur les 1 319 567 contacts, dont les 410 481 qui ont pourtant une adresse e-mail.
- Preuve        : `ssh root@46.62.248.239 "docker exec axion-crm-postgres psql -U axion -d axion_crm -c \"SELECT count(*) contacts_total, count(person_key) avec_person_key, count(email) avec_email FROM contacts;\""` → `1319567 | 0 | 410481` · `grep -ln person_key backend/database/migrations/*.php` → 5 migrations, aucune ne contient de `UPDATE`/backfill · `ls backend/app/Console/Commands/ | grep -i person` → rien. Sortie : `04_PREUVES/agent-05/prod-volumes-lots.txt`.
- Témoin négatif: la même requête compte 410 481 valeurs non nulles pour `email` sur la même table — le comptage sait donc trouver une colonne renseignée quand elle l'est. Et `grep` retrouve bien les migrations qui écrivent d'autres colonnes (`consent_text_ref` est écrite par `ContactUpserter`).
- Impact        : (a) la route `/console/personnes/$personKey` — la « fiche 360° », livrable phare du lot L6 et écran de référence de la conception §3b — n'est offerte pour aucune fiche du stock : le hub ne rend le lien que si `person_key !== null` ; (b) la déduplication inter-univers du plan §2.4, qui repose entièrement sur cette clé, ne peut rapprocher aucun contact existant d'un lead entrant ; (c) le **report de l'étape 5 bis** (`contacts.company_id` nullable) a été motivé par écrit dans le plan §2.2a — « le besoin réel du lot socle est déjà couvert par la colonne `contacts.person_key` livrée le 2026-08-14 » — justification qui ne tient pas puisque la colonne est vide.
- Reproduction  : ouvrir `https://app.axion-crm-pro.com/console/contacts`, déplier n'importe quelle entreprise : les noms de contacts s'affichent en texte brut, jamais en lien. Aucun chemin de navigation ne mène à la fiche 360° d'un contact du stock.
- Correctif     : une commande de backfill `contacts:backfill-person-key` calculant `sha256(lower(trim(email)) + sel)` sur les 410 481 contacts avec e-mail, par tranches, avec contexte workspace posé (patron `ScrapingBackfillSrcTags`, déjà éprouvé sur 4,29 M). ⚠️ Le sel est calculé **côté site** (`hashEmailForLookup`) : le backfill doit utiliser exactement le même sel, sinon il fabriquera des clés qui ne rapprocheront rien — un test croisé site/CRM sur une même adresse est indispensable avant lancement. Coût : ~1 j dont la vérification croisée.
- Statut        : ouvert

### [A05-002] `first_info_at` (art. 14 RGPD) : deux colonnes livrées, aucun écrivain dans tout le dépôt, 0 ligne renseignée
- Sévérité      : S2 défaut
- Domaine       : conformité
- Référence     : `main c0c453d`, production
- Emplacement   : `backend/database/migrations/2026_08_14_000002_crm_socle_taxonomie_business.php:88` et `:109`
- Constat       : les colonnes `companies.first_info_at` et `contacts.first_info_at`, posées avec un `COMMENT ON COLUMN` qui les désigne comme l'horodatage de la première communication vers une fiche collectée (obligation d'information de l'article 14 RGPD), ne sont lues ni écrites par aucune ligne de code du dépôt.
- Preuve        : recherche `first_info_at|firstInfoAt` sur l'ensemble du dépôt (backend, frontend, workers, tests, seeders, migrations) → **6 occurrences, toutes dans le fichier de migration qui crée les colonnes**. En production : `SELECT count(*) FROM companies WHERE first_info_at IS NOT NULL` → `0`. Sorties : `04_PREUVES/agent-05/first_info_at_companies.txt`, `first_info_at_contacts.txt`, `prod-volumes-lots.txt`.
- Témoin négatif: la même recherche appliquée à `consent_text_ref` — colonne créée par la **même migration**, aux mêmes lignes 66/74 — retourne 5 fichiers applicatifs (`ContactUpserter`, `SiteSyncIngestService`, `ArbitrageController`, `Candidate`, `Contact`). Le contrôle sait donc distinguer une colonne vivante d'une colonne morte.
- Impact        : l'obligation d'information de l'article 14 sur les 4,29 M de fiches collectées sans contact direct n'est ni horodatée ni traçable. Le jour où une campagne partira vers ces fiches, rien ne permettra de prouver quand — ni si — l'information a été délivrée. Le schéma donne l'apparence d'une conformité outillée qui n'existe pas.
- Reproduction  : `SELECT count(first_info_at) FROM companies;` → 0, quelle que soit la date.
- Correctif     : deux voies. (a) Câbler la colonne au premier envoi réel — mais l'envoi (L7) est exclu du périmètre, donc rien ne l'écrira avant longtemps. (b) Assumer l'attente : conserver les colonnes et **documenter dans la migration** qu'elles sont réservées à L7, pour qu'aucun audit ni aucune revue ne les lise comme un dispositif actif. Coût : (b) ~15 min. Recommandé : (b) maintenant, (a) au lot L7.
- Statut        : ouvert

### [A05-003] Cinq jours après activation, la synchro site → CRM n'a créé aucune fiche : 3 événements sur 3 sont tombés en arbitrage manuel
- Sévérité      : S1 grave
- Domaine       : backend / canal
- Référence     : production `api.axion-crm-pro.com`, code `main c0c453d`
- Emplacement   : `backend/app/Crm/Ingest/SiteSyncIngestService.php:147-155`
- Constat       : `upsertBusiness()` retourne `PENDING_MATCH` sans rien créer dès que l'événement n'apporte pas de SIREN, et les trois seuls événements reçus du site depuis l'activation du 14/08 sont tous dans cet état.
- Preuve        : `ssh … psql -c "SELECT count(*) FILTER (WHERE payload -> 'pending_match' IS NOT NULL) AS site_en_arbitrage, count(*) AS site_total FROM activities WHERE external_ref LIKE 'site:event:%';"` → `3 | 3` · `SELECT count(*) FROM contacts WHERE external_ref IS NOT NULL OR person_key IS NOT NULL;` → `0` · `SELECT count(*) FROM companies WHERE relation_type <> 'prospect';` → `0`. Sortie : `04_PREUVES/agent-05/prod-volumes-lots.txt`.
- Témoin négatif: la même requête compte 15 lignes `pending_match` au total sur `activities` (12 d'origine scraping) — le prédicat sait donc trouver des lignes ; et `SELECT kind, count(*)` retourne bien `form_submission | 3`, prouvant que les 3 événements du site sont arrivés, ont été signés, acceptés et journalisés. Le canal fonctionne ; c'est son effet qui est nul.
- Impact        : l'exigence actée par Will et écrite au plan §2.2a — « la synchro automatique couvre TOUT, newsletter comprise » — n'est pas tenue. Le plan écrit lui-même que `registrationNumber` (SIREN) est « rarement rempli » (§1.2) et qu'aucun abonné newsletter n'en a. Toute personne sans entreprise identifiée (abonné newsletter, investisseur individuel, particulier) exige donc un geste humain d'arbitrage pour exister dans le CRM. Le rapport de clôture présente la boucle comme « vivante, signée, idempotente » : c'est exact au niveau du transport, et trompeur au niveau du résultat.
- Reproduction  : soumettre le formulaire de contact du site sans renseigner de SIREN → l'événement arrive, une `activity` est créée avec `payload->pending_match`, et aucune ligne n'apparaît dans `companies` ni `contacts`.
- Correctif     : deux voies, non exclusives. (a) Lever l'étape 5 bis (`contacts.company_id` NULLABLE + colonne générée à double branche), ce que le plan prévoyait précisément pour ce cas — coût mesuré : 2,5 à 5 min de verrou `ACCESS EXCLUSIVE` sur CPX22, en fenêtre creuse, procédure et rollback déjà écrits. (b) À défaut, poser une alerte sur la profondeur et l'ancienneté de la file d'arbitrage : aujourd'hui 3 fiches y dorment depuis le 16/08 sans que rien ne le signale. Coût : (a) ~1 j + fenêtre ; (b) ~2 h.
- Statut        : ouvert

### [A05-004] Le cycle de vie business n'a jamais changé d'état : 4 295 349 fiches à `nouveau`, et la règle batch du plan n'existe pas
- Sévérité      : S2 défaut
- Domaine       : backend
- Référence     : `main c0c453d`, production
- Emplacement   : `_PLANS/2026-08-13_PLAN-CRM-contacts-candidats.md` §2.2b (table des règles de passage) · `backend/routes/console.php`
- Constat       : la seule règle de passage automatique du cycle de vie business — « client → dormant : 12 mois sans interaction (règle batch mensuelle) » — n'est implémentée par aucune commande, aucun job ni aucune tâche planifiée, et la totalité des fiches de production est restée à l'étape initiale.
- Preuve        : `grep -rn "dormant" backend/app backend/routes/console.php` → 3 occurrences, toutes descriptives (`Taxonomy.php:86` = la constante, `SiteSyncClassifier.php:203,218` = commentaire et branche de réveil), aucune n'écrit `lifecycle_stage = 'dormant'`. Les seuls écrivains de `lifecycle_stage` sont l'ingestion (qui pose `'nouveau'`) et `BulkController` (action manuelle de masse). En production : `SELECT lifecycle_stage, count(*) FROM companies GROUP BY 1;` → une seule ligne, `nouveau | 4295349`. Sortie : `prod-volumes-lots.txt`.
- Témoin négatif: le même `grep -rn` appliqué à `lifecycle_stage` retourne 13 fichiers applicatifs, dont deux qui l'écrivent réellement (`SiteSyncIngestService.php:199`, `BulkController.php:206`) — le contrôle sait donc trouver un écrivain quand il existe. Et `routes/console.php` contient bien 35 tâches planifiées réelles, dont les deux purges RGPD : le fichier n'est pas vide.
- Impact        : les vues par étape de cycle de vie de la console (`by_lifecycle_stage` de `CompteursHub`) affichent un histogramme à une seule barre. Le pilotage commercial promis par §2.2b n'existe pas ; un opérateur qui filtre par étape obtient toujours la base entière ou rien.
- Reproduction  : ouvrir `/console/contacts`, filtrer par `lifecycle_stage` : `nouveau` renvoie 4 295 349 fiches, toutes les autres valeurs renvoient 0.
- Correctif     : soit écrire la commande `crm:lifecycle-dormant` planifiée mensuellement (patron des deux purges RGPD : `WorkspaceContext::run()`, `skip()` derrière drapeau, `withoutOverlapping`, test d'intégration) — ~0,5 j ; soit retirer la ligne « règle batch mensuelle » du plan §2.2b, pour que le document cesse de décrire un automatisme absent. Le premier choix est le seul qui donne du sens au champ.
- Statut        : ouvert

### [A05-005] La conception console UX v2, référentiel n°4 de l'ordre de mission, décrit une navigation qui n'a jamais existé
- Sévérité      : S2 défaut (confusion de navigation)
- Domaine       : navigation / UX
- Référence     : `main c0c453d`
- Emplacement   : `_PLANS/2026-08-13_CONCEPTION-console-crm-ux.md` §2.2, §2.3, §2.4 et la table des URLs (l. 277-281) · `frontend/src/app/routeTree.tsx` · `frontend/src/components/layout/Sidebar.tsx:85-160`
- Constat       : la conception prescrit trois espaces de premier niveau (🔍 Collecte · 👥 Contacts · 📤 Campagnes grisé « bientôt »), un préfixe d'URL `/b/…`, un sélecteur d'univers coloré bleu/violet et des vues « base froide » séparées ; la console livrée a six groupes de navigation, aucune URL `/b/`, aucun onglet Campagnes réservé, et aucun sélecteur d'univers.
- Preuve        : `grep -n "path:" frontend/src/app/routeTree.tsx` → 40 routes, aucune commençant par `/b/`, aucune `/campagnes` réservée ; `grep -rn "Collecte\|Campagnes" frontend/src/components frontend/src/app` → « Collecte » n'est qu'un titre de groupe de la barre latérale et « Campagnes » a été **renommé « Collectes »** et pointe le scraping (`Sidebar.tsx:70,91`) ; `sed -n '85,130p' Sidebar.tsx` montre six sections nommées `Aujourd'hui · Contacts · Collecte · Pilotage · Conformité · Réglages`, refonte assumée sous l'étiquette « Étape 0, ligne 3 bis (F17) ».
- Témoin négatif: le même `grep -n "path:"` retrouve bien les quatre routes `/console/*` du lot L6 (`/console/contacts`, `/console/vivier`, `/console/arbitrage`, `/console/personnes/$personKey`) — le contrôle sait donc lire les routes réellement déclarées.
- Impact        : l'ordre de mission cite ce document comme référentiel à lire intégralement avant la première ligne de code, et le plan maître lui donne la préséance n°2 sur lui-même pour tout ce qui touche la structure de navigation. Un agent ou un humain qui reprend le chantier CRM cible planifiera contre une console qui n'existe pas : espaces, URLs et sélecteur d'univers sont tous introuvables. Aucun des documents en vigueur ne dit que cette conception a été remplacée.
- Reproduction  : ouvrir `https://app.localhost` puis `/b/prospection` → 404 ; comparer la barre latérale réelle au schéma de la conception §2.3 l. 99.
- Correctif     : poser en tête de `2026-08-13_CONCEPTION-console-crm-ux.md` un encart daté « SUPERSÉDÉ le 2026-08-18 par l'étape 0 F17 — la navigation livrée est celle de `Sidebar.tsx` », et corriger la table des URLs. Coût : ~1 h. La refonte elle-même n'est pas en cause : c'est l'absence de trace de la décision qui l'est.
- Statut        : ouvert

### [A05-006] Le vivier candidats — objet central du plan — est vide cinq jours après l'ouverture du flux, et rien ne signale ce silence
- Sévérité      : S2 défaut
- Domaine       : backend / canal
- Référence     : production, code `main c0c453d`
- Emplacement   : `backend/database/migrations/2026_08_14_000003_crm_socle_vivier_candidats.php` · production `axion_crm`
- Constat       : le drapeau `CRM_INGEST_CANDIDATES_ENABLED` est à `true` en production depuis le 14/08 et les tables `candidates` et `candidate_tag` sont restées à zéro ligne.
- Preuve        : `ssh … docker inspect axion-crm-api` → `CRM_INGEST_CANDIDATES_ENABLED=true` ; `ssh … psql -c "SELECT (SELECT count(*) FROM candidates) candidates, (SELECT count(*) FROM candidate_tag) candidate_tag;"` → `0 | 0`. Sortie : `prod-volumes-lots.txt`.
- Témoin négatif: la même requête composite retourne `sources = 17` pour `scraping_sources` et `217` pour `tags` — le comptage sait donc trouver des lignes dans les tables du même lot. Les tables `candidates`/`candidate_tag` existent bien (`\dt` les liste, sortie `tables-lots.txt`) : ce n'est pas une erreur de nom.
- Impact        : deux causes possibles, que ces chiffres seuls ne départagent pas — aucune candidature n'a été déposée sur le site depuis 5 jours, ou le point de capture des candidatures n'émet pas. La première serait normale, la seconde serait un lead perdu par jour. Aucune alerte CRM ne se déclenche sur une absence de flux : `ObservabilityController` est un résumé en lecture seule sans seuil, et les cinq genres d'alerte du site (`gave_up`, `backlog`, `reconcile_gap`, `reconcile_failed`, `scan_capped`) portent sur les échecs et les écarts de réconciliation, pas sur un univers resté vide. Le plan §2.3 fait par ailleurs reposer toute la doctrine CNIL « CVthèque » (2 ans, purge, information) sur ce vivier : la mécanique de purge tourne mensuellement sur une table vide.
- Reproduction  : `SELECT count(*) FROM candidates;` → 0, à toute date depuis le 14/08.
- Correctif     : la mesure qui tranche est celle que le rapport de clôture §7 identifiait déjà sans la faire — comparer, sur la même fenêtre, le nombre de `job_applications` créées côté site au nombre de lignes `crm_sync_outbox` de type candidature. Coût : ~1 h (deux requêtes, accès à la base du site). Selon le résultat : rien à corriger, ou réparer un point de capture muet.
- Statut        : ouvert

### [A05-007] Deux numérotations de lots coexistent dans les documents de référence, sans table de correspondance
- Sévérité      : S3 finition
- Domaine       : conformité (traçabilité)
- Référence     : `main c0c453d`
- Emplacement   : `_PLANS/2026-08-13_ORDRE-MISSION-AUTOPILOT-CRM.md` §RÈGLES D'EXÉCUTION, règle 1 · `_SESSIONS/2026-08-13_AUTOPILOT-CRM-journal.md:341-345` · titres des PR #56 → #62
- Constat       : l'ordre de mission fixe une numérotation « CORRIGÉE » (L0 = site, L1 = durcissement RLS, L2 = socle…), et l'exécution en a employé une autre décalée d'un cran (L0 = durcissement RLS, L1 = socle…), qui est celle inscrite dans les titres des PR fusionnées et dans le rapport de clôture.
- Preuve        : `gh pr list --state merged --json number,title` → `#56 feat(L0) : isolation par workspace`, `#57 feat(L1) : socle de données` ; à comparer au plan §2.12 qui définit `L0 — Site, prérequis` et `L1 — CRM, étanchéité P0`. Le journal l. 341-345 documente le décalage une seule fois, au fil de l'eau.
- Témoin négatif: le rapport de clôture §1.1 reproduit la numérotation **de session** sans signaler l'écart, ce qui montre que la confusion s'est propagée au document de synthèse et n'est pas une lecture erronée de ma part.
- Impact        : « le lot L1 est-il livré ? » n'a pas de réponse unique — c'est le durcissement RLS pour l'exécutant et le socle de données pour le plan. Toute reprise, toute revue et tout suivi lot-par-lot doit d'abord deviner quelle convention est employée. Le lot L0 **du plan** (prérequis site) n'a jamais porté ce nom nulle part, alors qu'il a bien été livré (cf. grille §1.2).
- Reproduction  : lire côte à côte le plan §2.12 et le titre de la PR #56.
- Correctif     : ajouter une table de correspondance de six lignes en tête du journal et du rapport de clôture. Coût : ~20 min.
- Statut        : ouvert

### [A05-008] Le correctif du décalage de 2 h est actif en production et inerte dans l'atelier local — le plan affirme toujours l'inverse
- Sévérité      : S3 finition
- Domaine       : backend / tests
- Référence     : `main c0c453d`, production, atelier local
- Emplacement   : `_PLANS/2026-08-16_PLAN-MIGRATION-DECALAGE-2H-CRM.md:20` · `backend/config/database.php:102` · `docker-compose*.yml`
- Constat       : `DB_TIMEZONE=Europe/Paris` est posée dans l'environnement du conteneur `axion-crm-api` en production, alors que le plan porte toujours en tête « **Statut : PLAN, RIEN N'EST APPLIQUÉ EN PRODUCTION** » ; à l'inverse, aucun fichier `docker-compose` ne la pose, si bien que l'atelier local tourne avec une session Postgres en `Etc/UTC` face à une application en `Europe/Paris`.
- Preuve        : `ssh root@46.62.248.239 "docker inspect axion-crm-api --format '{{range .Config.Env}}{{println .}}{{end}}' | grep DB_TIMEZONE"` → `DB_TIMEZONE=Europe/Paris` · `docker exec axion-crm-postgres psql -U axion -d axion_crm -tAc "SHOW TimeZone"` → `Etc/UTC` · `env DB_TIMEZONE` du conteneur api local → `NULL` · recherche `DB_TIMEZONE` sur `*.yml` → aucune occurrence. Sortie : `04_PREUVES/agent-05/horodatage-sonde.txt`.
- Témoin négatif: la même recherche trouve la variable dans `backend/phpunit.xml:52` et `backend/phpunit-ci.xml:60` — le contrôle sait donc la repérer là où elle est posée. C'est précisément ce qui masque l'écart : les tests la posent, l'application locale non.
- Impact        : (a) le plan est périmé et induira en erreur quiconque le relit pour savoir s'il reste quelque chose à basculer ; (b) l'atelier local ne reproduit pas la configuration de production sur un point qui a déjà produit deux défauts réels (PR #90, puis PR #170 « la date reçue du site reculait de 2 heures ») : un développeur qui vérifie un horodatage en local mesure un comportement que la production n'a plus.
- Reproduction  : `docker compose up -d` puis `docker exec axion-crm-postgres psql -U axion -d axion_crm -tAc "SHOW TimeZone"` → `Etc/UTC`, alors que la même commande en production donnerait le comportement corrigé côté session applicative.
- Correctif     : poser `DB_TIMEZONE: ${DB_TIMEZONE:-Europe/Paris}` dans le service `api` du `docker-compose.yml` (et les surcouches horizon/scheduler), et remplacer l'en-tête du plan par « APPLIQUÉ EN PRODUCTION le <date> ; reprise des données : <état> ». Coût : ~30 min.
- Statut        : ouvert

### [A05-009] Quatorze des seize spécifications Playwright ne sont exécutées par aucun pipeline, dont la seule qui couvre les quatre écrans de la console v2
- Sévérité      : S2 défaut
- Domaine       : tests
- Référence     : `main c0c453d`
- Emplacement   : `frontend/tests/e2e/` (16 fichiers) · `.github/workflows/a11y.yml:48,58`
- Constat       : seuls `a11y.spec.ts` et `navigation.spec.ts` sont lancés par un workflow ; les quatorze autres spécifications, dont `console-locale.spec.ts` qui visite `/console/contacts`, `/console/vivier`, `/console/arbitrage` et `/console/personnes/$personKey` et en prend des captures, ne sont exécutées nulle part.
- Preuve        : `ls frontend/tests/e2e/*.spec.ts` → 16 fichiers ; `grep -rho "tests/e2e/[a-z0-9-]*\.spec\.ts" .github/workflows/` → **2** (`a11y.spec.ts`, `navigation.spec.ts`) ; `grep -n '"test"\|"e2e"' frontend/package.json` → `"test": "vitest run"` (Vitest, pas Playwright) et `"e2e": "playwright test"`, script que `ci.yml` n'appelle jamais. Sortie : `04_PREUVES/agent-05/e2e-specs-non-executees.txt`.
- Témoin négatif: la même extraction trouve bien deux spécifications câblées — et l'en-tête de `a11y.yml` documente qu'en août `navigation.spec.ts` « n'était exécuté NULLE PART : rouge en silence depuis l'accordéon de la PR #84 », défaut réparé en le branchant. Le contrôle sait donc distinguer une spécification câblée d'une spécification orpheline, et ce cas précis prouve qu'une spécification non exécutée peut rester rouge sans que personne le sache.
- Impact        : l'ordre de mission exige « à CHAQUE écran livré, parcours réel au Playwright, desktop 1440 + mobile <1024, captures REGARDÉES ». Les captures ont été prises à la main pendant l'autopilot, mais rien ne rejoue ces parcours : les quatre écrans du lot L6 n'ont aucune couverture automatisée, pas plus que la RGPD, le constructeur d'audiences ou la recherche globale. Ces quatorze fichiers peuvent être rouges depuis des semaines sans qu'aucun signal n'apparaisse — exactement le scénario vécu avec `navigation.spec.ts`.
- Reproduction  : `cd frontend && pnpm e2e` — les 16 spécifications se lancent ; aucun workflow du dépôt n'exécute cette commande.
- Correctif     : ajouter au job `axe-playwright` de `a11y.yml` (qui construit et sert déjà l'application via `vite preview`) un lancement bloquant de l'ensemble `tests/e2e` sur le projet chromium, en excluant nommément ce qui échouerait pour raison connue plutôt qu'en laissant l'omission faire office de filtre. Prévoir une première passe de réparation : ces spécifications n'ont pas tourné depuis longtemps. Coût : ~0,5 j de câblage + le temps de réparation constaté.
- Statut        : ouvert

---

## 3. CE QUE JE N'AI PAS PU VÉRIFIER, ET POURQUOI

1. **La « preuve par la rougeur » du Gate 0 et de la garde CNIL.** Les deux sont consignées au journal avec leurs sorties, mais les rejouer exigerait de casser volontairement un test et d'ouvrir une PR sur `main` — donc d'écrire dans le dépôt, ce que mon mandat d'audit exclut. Je me suis rabattu sur une preuve indirecte mais réelle : la CI exécute 780 tests / 6 503 assertions et PHPStan rend `[OK] No errors` sur un run du 2026-08-19 (`ci-run-pest.txt`).
2. **La suite Pest complète rejouée par moi.** Lancée (`docker exec axion-crm-api php artisan test --configuration=phpunit-ci.xml`), elle n'a rien rendu : `ps aux` dans le conteneur montre **plus de vingt processus concurrents** d'autres agents de cet audit, dont un `php artisan migrate:fresh --force` et un `php /tmp/seed.php` qui recréent la base sous mes pieds. Une mesure prise dans ces conditions serait non déterministe : je ne l'ai pas prise. Le run CI ci-dessus la remplace, sur une base neuve et isolée.
3. **La reprise de données du décalage de 2 h** (`horodatages:corriger`, plan §5bis). Savoir si elle a été jouée exigerait d'inspecter les horodatages historiques en production et de les comparer à un état antérieur dont je ne dispose pas. La commande existe et porte sa garde ; son exécution est indéterminée.
4. **Le silence du vivier (A05-006) : trafic réel ou point de capture muet.** Trancher exige de compter les `job_applications` créées côté site sur la même fenêtre, donc un accès à la base de production du site `axion-ia` — hors de mon périmètre et non ouvert à cette session. C'est exactement la mesure que le rapport de clôture §7 déclarait « à faire » et qui ne l'est toujours pas.
5. **L'état d'avancement des 4 points ⛔ laissés ouverts au §8.2 du rapport de clôture.** J'ai pu établir que D-11 a été corrigé (PR #166, fusionnée le 17/08). Pour les trois autres — rejouer le §E (B.8→B.12, 4 pannes simulées, 6 écrans), mesurer le §F.6 (liste 100 000+, p95 < 500 ms), trancher les deux arbitrages `neq`/`not_in` et adresse en clair dans `opt_out` — aucun document postérieur ne les solde et je n'ai trouvé aucune preuve d'exécution. Je les tiens pour ouverts sans pouvoir l'affirmer.
6. **La conformité de la conception UX v2 sur écran réel.** J'ai comparé le document au code des routes et de la barre latérale, pas à un rendu ouvert. La règle 4 du dossier (« le geste réel avant l'instrumentation ») n'est donc pas honorée pour A05-005 ; le constat repose sur l'absence de routes `/b/…` et de tout onglet Campagnes réservé, ce qui est décisif mais reste une mesure statique.
7. **La profondeur de l'étanchéité RLS** (le lot P0 est vérifié comme *livré*, pas comme *suffisant*) : c'est le périmètre d'agent-07 (F09), dont j'ai réutilisé les sorties plutôt que de les refaire, conformément à la règle 8 (« on étend, on ne réinvente pas »).
8. **Le registre de preuve des consentements du site** (`src/lib/consents/index.ts`) se déclare lui-même « **BEST-EFFORT ABSOLU**, pas une garantie transactionnelle » : il n'est pas écrit dans la transaction métier et ne fait jamais échouer une capture. Le compromis est explicite et argumenté (« un formulaire cassé est un client perdu »), mais il signifie qu'un consentement peut être recueilli sans que sa preuve soit enregistrée. **Je n'ai mesuré aucune perte** — je n'ai pas accès à la base du site pour comparer `consent_events` aux soumissions. Je le signale ici plutôt qu'en constat, faute de mesure.
