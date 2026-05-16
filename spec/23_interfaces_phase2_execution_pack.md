# 23 — Interfaces Phase 2 + Execution Pack

> **Partie A** : Interfaces de liaison Phase 1 ↔ Phase 2 (events, traits, hooks).
> **Partie B** : Execution Pack — Code Generation Roadmap (12 étapes) + Tests AC + Seeders & Assets + 12 prompts Claude Code prêts à l'emploi.

---

# PARTIE A — Interfaces Phase 1 ↔ Phase 2

## A.1 — Events Laravel à émettre depuis Phase 1

```php
// app/Events/Phase2Ready/ContactReadyForColdEmail.php
class ContactReadyForColdEmail
{
    use Dispatchable, SerializesModels;
    public function __construct(public Contact $contact, public Company $company) {}
}

// app/Events/Phase2Ready/LeadScored.php
class LeadScored
{
    use Dispatchable, SerializesModels;
    public function __construct(public Company $company, public int $score, public string $reason) {}
}

// app/Events/Phase2Ready/DealCreatedFromContact.php
class DealCreatedFromContact
{
    use Dispatchable, SerializesModels;
    public function __construct(public Contact $contact, public string $source) {}
}

// app/Events/Phase2Ready/HighValueSignalDetected.php
class HighValueSignalDetected
{
    use Dispatchable, SerializesModels;
    public function __construct(public CompanyBusinessSignal $signal) {}
}

// app/Events/Phase2Ready/CompanyEnriched.php (déjà émis Phase 1, mais utilisé Phase 2)
class CompanyEnriched
{
    use Dispatchable, SerializesModels;
    public function __construct(public Company $company, public EnrichmentRun $run) {}
}
```

### Quand émis (Phase 1)

| Event | Émis depuis | Conditions |
|-------|-------------|------------|
| `CompanyEnriched` | `WaterfallOrchestrator::enrichCompany()` | Fin du waterfall, status `success` |
| `ContactReadyForColdEmail` | Trigger SQL ou listener `CompanyEnriched` | Quand `contacts.primary_email_score >= 70` |
| `LeadScored` | `ClassifierService::classify()` | Après LLM `axion_offer_match` |
| `HighValueSignalDetected` | `BodaccSignalsCommand` + `FranceTravailHiringCommand` | Signal score ≥ 70 |
| `DealCreatedFromContact` | (Phase 2 only) | Quand contact converti deal CRM |

## A.2 — Traits PHP de hook (Phase 1 expose, Phase 2 consume)

```php
// app/Traits/Phase2Ready/HasPhase2Hooks.php
trait HasPhase2Hooks
{
    /**
     * Override-able dans Phase 2.
     * Phase 1 : no-op.
     */
    public function onContactReadyForColdEmail(Contact $c): void {}

    public function onCompanyEnriched(Company $c, EnrichmentRun $run): void {}

    public function onHighValueSignal(CompanyBusinessSignal $signal): void {}
}
```

## A.3 — Tables consommables Phase 1 → Phase 2

| Phase 2 module | Tables Phase 1 lues |
|----------------|---------------------|
| Campaigns | `companies`, `contacts`, `email_verifications` (email valide ≥70), `opt_out` (check), `coverage_matrix_cells` (audience targeting) |
| Cold Email | `contacts.primary_email`, `email_verifications.score`, `companies.quality_score = 'complete'` |
| LinkedIn Outreach | `contacts.linkedin_url`, `linkedin_url_searches` |
| CRM | `companies`, `contacts`, `company_business_signals` (deals auto-créés sur signal fort) |
| Analytics | `enrichment_runs`, `scraper_runs`, `llm_usage`, `email_sends` (Phase 2) |

## A.4 — Routes API Phase 2 à appeler (depuis Phase 1)

Aucune. Phase 1 ne dépend pas de Phase 2. Phase 2 consomme Phase 1 via lecture DB + listeners events.

## A.5 — Queues à consommer

Phase 2 ajoutera de nouvelles queues (cf. configuration future `horizon.php`) :

- `campaign-dispatch` — orchestrateur campagnes
- `cold-email-send` — envoi SMTP cascade
- `cold-email-warmup` — warmup IPs
- `linkedin-action` — connect requests + messages
- `crm-lead-scoring` — recompute deal scores
- `analytics-rollup` — rollups quotidiens

---

# PARTIE B — Execution Pack

## B.1 — Code Generation Roadmap (12 étapes ordonnées)

### Étape 1 — Bootstrap projet

**Inputs** : compte Hetzner CRM-Pro, clé SSH, domaine acheté, accès Cloudflare nouveau compte.

**Outputs :**
- Repo GitHub `axion-crm-pro` initialisé (avec ce dossier `spec/` mergé)
- Skeleton Laravel 12 backend (`backend/`)
- Skeleton React 19 + Vite 6 frontend (`frontend/`)
- Skeleton Node 22 workers (`workers/`)
- 5 serveurs Hetzner provisionnés + vSwitch + firewall
- Coolify v4 installé sur `app`
- Postgres 16 + extensions + Redis 7 sur `data`
- Premier `docker-compose up` Laravel hello world OK

**Critères done :**
- ✅ `git push origin main` déclenche CI verte
- ✅ `curl https://staging.axion-pro.com/up` → 200
- ✅ Tests Pest installés et passent

**Dépendances :** aucune.

**Effort :** 5 jours dev + 8 jours Claude Code.

### Étape 2 — DB migrations + RLS + seed référentiels

**Inputs :** `03_db_schema_phase1.md` + `04_db_schema_phase2_scaffold.md`.

**Outputs :**
- ~98 migrations Laravel (63 Phase 1 + 35 Phase 2 scaffold + 1 RLS policies)
- Seeders : countries, regions (13), departments (101), cities (~2150), naf_* (5 niveaux), legal_forms, effectif_ranges, axion_offer_targets, strategic_keywords, search_engines, user_agents (pool 50+)
- Materialized view `coverage_matrix_cells` créée + pg_cron scheduled

**Critères done :**
- ✅ `php artisan migrate:fresh --seed` exécute sans erreur
- ✅ Tests `DatabaseSchemaTest::test_rls_enforced()` passent
- ✅ `SELECT COUNT(*) FROM cities` ≥ 2150
- ✅ Coverage matrix refresh manuelle OK

**Dépendances :** Étape 1.

**Effort :** 6 jours.

### Étape 3 — Auth + RBAC + multi-tenant

**Inputs :** `15_auth_multitenant_rbac.md` + `13_ui_admin_phase1.md` § 1.

**Outputs :**
- Sanctum SPA + 2FA TOTP + Magic link + Brute force protection
- Spatie Permission seedé (4 rôles)
- Middleware `SetCurrentWorkspace` + RLS DB
- Audit log hash chain
- Pages React `Login`, `TwoFactor`, `MagicLinkRequest`, `PasswordReset`
- Tests E2E "user A cannot access workspace B"

**Critères done :**
- ✅ Tests Pest auth `>=` 95 % coverage
- ✅ E2E Playwright login + 2FA passe
- ✅ Audit hash chain valide après 100 inserts

**Dépendances :** Étapes 1-2.

**Effort :** 7 jours.

### Étape 4 — LLM Router + Patterns techniques transversaux

**Inputs :** `07_llm_router.md` + `09_proxy_pluggable_system.md` + `10_rotations_universelles.md` + `12_coverage_matrix_deduplication.md` § 2.

**Outputs :**
- `LLMClient` PHP + 5 providers + fallback + cache + cost tracking
- `ProxyProvider` interface + Webshare + IPRoyal
- `ProxyRouter` intelligent + health checks
- `WeightedRoundRobin` algorithme
- `DeduplicationService` 6 niveaux + tests
- 11 use cases LLM seedés + prompt templates v1
- UI admin "LLM Router" + "Proxy Providers" + "Rotations" (basique)

**Critères done :**
- ✅ Tests unitaires : dedup 6 niveaux 100 % coverage
- ✅ Smoke : test prompt `sector_classification` retourne JSON valide
- ✅ Smoke : acquire proxy + health check OK

**Dépendances :** Étapes 1-3.

**Effort :** 8 jours.

### Étape 5 — Sources INSEE + annuaire-entreprises + BODACC + Coverage

**Inputs :** `05_scrapers_14_sources.md` § 1 + 2 + 5 + `08_waterfall_enrichissement_classification.md`.

**Outputs :**
- 3 scrapers (PHP, pas Playwright)
- `WaterfallOrchestrator` Spatie state machine (10 étapes, mais 1+2+9 actives initialement)
- `ZoneRotator` cooldown 24h
- UI page Companies (liste basique) + DetailPage skeleton
- Endpoint `/api/v1/companies/{c}/enrich`

**Critères done :**
- ✅ Smoke : enrichir 100 SIRENs IDF NAF 6201Z → 100 companies en DB avec quality `basic`
- ✅ Coverage matrix se peuple correctement

**Dépendances :** Étapes 1-4.

**Effort :** 7 jours.

### Étape 6 — Workers Playwright (Google Maps + Pages Jaunes + Sites web)

**Inputs :** `05_scrapers_14_sources.md` § 6+7+8 + `19_queues_workers_playwright.md`.

**Outputs :**
- Workers Node `worker-google-maps`, `worker-pages-jaunes`, `worker-sites-web`
- Bridge Redis Laravel ↔ Node + endpoint `/internal/scraper-result`
- Email extraction exhaustive + classification 4 catégories
- Détection pattern email entreprise (`email_patterns`)
- Use case LLM `extract_team_from_page` opérationnel
- UI page "Scraper Runs"
- Étapes 3 + 4 waterfall actives

**Critères done :**
- ✅ Smoke : 50 entreprises → tel + site (>= 70 %)
- ✅ Smoke : 30 sites web → pattern email détecté pour 80 %+
- ✅ E2E : captcha Google Maps détecté + bascule auto

**Dépendances :** Étapes 4-5.

**Effort :** 10 jours.

### Étape 7 — Google Search Wrapper + Direction Finder + sources résiduelles

**Inputs :** `05_scrapers_14_sources.md` § 9 + § Direction Finder + sources 10-14 + 3-4.

**Outputs :**
- Worker `worker-google-search` 3 moteurs + scoring matching LLM
- Worker `worker-direction-finder` (LLM-heavy) 4 sources combinées
- pdf-parse intégré pour rapports annuels
- Workers `worker-france-travail`, `worker-mesri`, `worker-crunchbase`, `worker-bodacc` (déjà fait étape 5 mais polish), `worker-infogreffe`, `worker-societe-com`, `worker-social-light`
- Géocodage BAN dans waterfall (étape 8)
- Étape 5 + 6 + 9 + 10 waterfall actives

**Critères done :**
- ✅ Smoke : 10 ETI testées → ≥ 3 C-level trouvés moyenne
- ✅ Smoke : 50 entreprises → ≥ 35 LinkedIn URLs trouvées (entreprise)
- ✅ Smoke : France Travail détecte hiring_surge sur 20 % top entreprises

**Dépendances :** Étapes 4-6.

**Effort :** 14 jours (le plus chargé — Direction Finder est complexe).

### Étape 8 — Email Finder + Validation SMTP cascade

**Inputs :** `06_email_finder_validation.md`.

**Outputs :**
- `EmailFinderService` complet (18 patterns + génération candidats)
- `SmtpValidator` cascade N1→N5
- `CatchAllDetector` cache 7j
- Disposable list mensuelle
- IPs dédiées validation (rDNS configurés)
- Job hourly check blacklists
- Étape 7 waterfall active

**Critères done :**
- ✅ Smoke : 100 contacts → 60 % ont email validé score ≥ 70
- ✅ Tests : 18 patterns matérialisation OK
- ✅ Catch-all detection fonctionne sur 5 domaines tests

**Dépendances :** Étapes 1-7.

**Effort :** 7 jours.

### Étape 9 — Carte France interactive + Coverage Matrix UI

**Inputs :** `11_carte_france_interactive.md` + `13_ui_admin_phase1.md` § 3.

**Outputs :**
- Import IGN AdminExpress COG 2026 (mapshaper + tippecanoe → MVT tiles)
- Composant `<FranceCoverageMap />` 3 modes
- Endpoint `/api/v1/coverage`
- `<SearchMode />` auto-suggest 2150+ villes
- `<ActionMode />` panneau scraping zone

**Critères done :**
- ✅ Smoke : carte se charge en < 2 s sur 4G
- ✅ Smoke : recherche "Paris" zoom auto OK
- ✅ Smoke : clic zone → panneau + bouton "Lancer scraping" → 1 run en queue

**Dépendances :** Étapes 1-5.

**Effort :** 6 jours.

### Étape 10 — Classification LLM + UI complète

**Inputs :** `08_waterfall_enrichissement_classification.md` + `13_ui_admin_phase1.md` (toutes pages).

**Outputs :**
- `ClassifierService` : ia_maturity_scoring + axion_offer_match + auto_tag_generation + extract_strategic_keywords
- Recompute `companies.quality_score` (via SQL function)
- UI complète 17 pages Phase 1
- A/B testing prompts opérationnel
- Dashboard "coût par enrichissement"
- Étape 10 waterfall active

**Critères done :**
- ✅ Smoke : 100 entreprises enrichies → toutes ont maturity + offer + tags + qualityScore non null
- ✅ UI 17 pages naviguent sans erreur (Cypress / Playwright)

**Dépendances :** Étapes 1-9.

**Effort :** 9 jours.

### Étape 11 — Scaffold Phase 2 + RGPD + Monitoring

**Inputs :** `04_db_schema_phase2_scaffold.md` + `17_rgpd_aiact_owasp.md` + `13_ui_admin_phase1.md` § 18-22 + `16_monitoring_observabilite.md`.

**Outputs :**
- 5 pages Phase 2 stubs avec wireframes "bientôt disponible"
- Routes API Phase 2 → 501 avec Spatie Data types
- Triggers SQL Phase 2 créés (firent jamais)
- UI RGPD requests complète + erasure SQL transaction multi-tables
- Monitoring stack déployé (Prometheus + Grafana + Loki + Alertmanager)
- 10 dashboards Grafana provisionnés (JSON Git)
- Alertmanager rules + Slack/Telegram routing

**Critères done :**
- ✅ Smoke : /campaigns retourne page placeholder
- ✅ Smoke : GET /api/v1/campaigns → 501 avec body typé
- ✅ Smoke : 1 demande RGPD erasure end-to-end (manuelle, identité validée) → contact anonymisé + opt-out global
- ✅ Grafana 10 dashboards accessibles + data populated

**Dépendances :** Étapes 1-10.

**Effort :** 8 jours.

### Étape 12 — Polish + E2E + Doc + Promotion prod

**Outputs :**
- 50+ tests E2E Playwright (auth + CRUD + scraping + RGPD + map)
- Tests load k6 (100 req/s API)
- Documentation OpenAPI auto-doc + runbooks
- Penetration test interne (Burp Suite basic)
- Promotion staging → prod (DNS Cloudflare orange + HSTS preload 12 mois)
- DNSSEC actif
- Status page Uptime Kuma publique

**Critères done :**
- ✅ Tous les SLOs Phase 1 atteints (cf. `16_monitoring_observabilite.md` § 14)
- ✅ DR drill effectué (restore RTO < 4h)
- ✅ Audit log hash chain valide en prod
- ✅ ≥ 50 000 fiches 🟢 dans workspace `axion-ia`

**Dépendances :** Étapes 1-11.

**Effort :** 8 jours.

---

## B.2 — Tests Acceptance Criteria

### Auth + RBAC

```php
test('user can register, login, enable 2FA, logout')
test('failed login increments counter, locks after 5 attempts')
test('magic link expires after 15 min, single use')
test('user A in workspace A cannot access workspace B data')
test('audit log records all sensitive actions with valid hash chain')
```

Dataset : 5 users seedés, 2 workspaces.

### INSEE Sirene

```php
test('searchByZoneAndSize returns expected SIRENs for Paris NAF 6201Z')
test('rate limit 429 triggers exponential backoff')
test('OAuth token refresh on 401')
test('idempotence : second run does not duplicate companies')
```

Dataset : 10 SIRENs connus (axion-ia.com + 9 PME visibles).

### annuaire-entreprises

```php
test('fetchBySiren returns dirigeants legal + revenue + bilans')
test('fallback Infogreffe activated when API returns empty')
test('handle French accents in legal_name correctly')
```

### Google Maps

```php
test('worker_google_maps extracts phone + website + hours for 10 test companies (>=80% success)')
test('captcha detected → engine state changes + cool-down 30 min')
test('proxies rotation : 100 requests use ≥ 5 different proxies')
```

### Sites web

```php
test('extract all emails from HTML including obfuscated formats')
test('classify emails into 4 categories correctly')
test('detect email pattern with confidence ≥ 70 when 3+ nominative emails present')
test('crawl 2 levels deep on /contact, /equipe, /mentions')
test('extract team via structured CSS + fallback LLM')
```

### Google Search Wrapper

```php
test('search company linkedin → URL found with confidence ≥ 70 for known company')
test('search person linkedin scoring rejects homonyme correctly')
test('captcha on Google → fallback Bing → fallback DuckDuckGo')
test('rate limit 1 engine → cool-down + bascule')
```

### Direction Finder

```php
test('activated only when effectif >= 100 OR size_category in [eti,ge]')
test('crawl 25 corporate paths + LLM fallback')
test('extract C-level from press releases')
test('parse PDF rapport annuel and extract leadership pages')
test('5 C-level types found for known ETI test (TOTAL, etc.)')
```

### Email Finder + SMTP

```php
test('generate 18 patterns for "Marie LE GALL @ axion-ia.com"')
test('detect pattern from 3 known emails → confidence ≥ 80')
test('SMTP cascade N1 → invalid syntax = score 0')
test('SMTP cascade N3 → 550 = invalid, score 0')
test('SMTP cascade catch-all → score 50-60')
test('cache 30j : second probe same email skipped')
test('opt-out global checked before any probe')
```

### Carte France

```typescript
test('map loads in < 2s on simulated 4G')
test('search "Paris" zooms to lon 2.35, lat 48.85')
test('click département → side panel opens with stats')
test('click "Lancer scraping" → 1 scraper_run created')
test('a11y : tab navigation through controls')
```

### Coverage Matrix + Dedup

```php
test('niveau 1 : SIREN unique within workspace')
test('niveau 3 : skip if TTL not expired')
test('niveau 6 : opt-out global blocks scraping')
test('fuzzy matching pg_trgm detects "SARL DUPONT" vs "Dupont SARL"')
test('coverage_matrix_cells refresh updates within 1 min')
```

### LLM Router

```php
test('use case routes to correct provider')
test('fallback chain triggered on 429')
test('cache hit on identical prompt')
test('cost cap workspace blocks excess calls')
test('A/B variant_a selected ~50% of times with split=0.5')
test('prompt template versioning rollback works')
```

### Proxies

```php
test('ProxyRouter picks healthy proxy for domain google.com')
test('failed proxy cooldown 2h after 3 health check fails')
test('budget cap blocks acquire')
```

### RGPD

```php
test('erasure transaction atomique : contacts anonymized + email_verifications deleted + opt_out added')
test('previewImpact returns correct counts')
test('audit log records gdpr.erasure.start + complete')
test('hash chain verification finds tampering')
```

### Monitoring

```php
test('Prometheus /metrics endpoint exposes 40+ metrics')
test('alert ScrapingSourceErrorSpike fires when error rate > 15%')
test('anomaly detector inserts row when LLM cost z-score > 3')
```

---

## B.3 — Data Seeders & Assets

### IGN AdminExpress COG 2026

- **Source officielle :** `https://geoservices.ign.fr/adminexpress` → ADMIN-EXPRESS-COG (mise à jour annuelle)
- **Licence :** Licence Ouverte Etalab 2.0
- **Format :** SHP Lambert-93 7z
- **Taille :** ~600 MB compressé, ~2 GB extrait
- **Fréquence MAJ :** Annuelle (mars typiquement)
- **Commande :** `php artisan import:ign-polygons --year=2026`
- **Tables cibles :** `regions`, `departments`, `cities` (geometry columns postgis)
- **Temps import :** ~15 min (mapshaper simplification + tippecanoe MVT + INSERT DB)
- **Idempotence :** `ON CONFLICT DO UPDATE` sur `code`

### NAF (5 niveaux)

- **Source :** `https://www.insee.fr/fr/information/2406147` (nomenclature 2008)
- **Licence :** Domaine public INSEE
- **Format :** CSV ou XLSX
- **Commande :** `php artisan import:naf-codes`
- **Tables cibles :** `naf_sections`, `naf_divisions`, `naf_groups`, `naf_classes`, `naf_subclasses` (732 codes au total)

### Formes juridiques

- **Source :** `https://www.insee.fr/fr/information/2028129`
- **Commande :** `php artisan import:legal-forms`
- **Table cible :** `legal_forms`

### Tranches d'effectifs

Seedé directement dans la migration (cf. `03_db_schema_phase1.md` § 3 `effectif_ranges`).

### User-Agents pool

- **Source :** `https://www.useragents.me/` (top UAs réels)
- **Format :** JSON
- **Commande :** `php artisan refresh:user-agents` (mensuel)
- **Table cible :** `user_agents` (50+ entries)

### Pages cibles crawl

Constante PHP `config/scrapers/target_paths.php`. Pas de DB.

### Patterns email

Constante PHP `config/email_patterns.php`. 18 patterns. Pas de DB.

### Mots-clés stratégiques

- **Source :** Custom Axion-IA business
- **Format :** YAML seeder
- **Commande :** `php artisan db:seed StrategicKeywordsSeeder`
- **Table cible :** `strategic_keywords`

Exemple seeder :

```php
class StrategicKeywordsSeeder extends Seeder
{
    public function run(): void
    {
        $kws = [
            // category=digital
            ['digital', 'transformation digitale', 'digitalisation'],
            // category=ia
            ['intelligence artificielle', 'IA', 'machine learning', 'ML', 'NLP'],
            // category=cloud
            ['cloud', 'AWS', 'Azure', 'GCP', 'migration cloud'],
            // ... etc.
        ];
        // INSERT each
    }
}
```

### Cibles Axion-IA (axion_offer_targets)

```php
class AxionOfferTargetsSeeder extends Seeder
{
    public function run(): void
    {
        $ws = Workspace::firstWhere('slug', 'axion-ia');
        AxionOfferTarget::create([
            'workspace_id' => $ws->id,
            'offer_code' => 'audit_flash',
            'label' => 'Audit Flash',
            'target_size_min' => 1, 'target_size_max' => 50,
            'naf_sections_in' => ['C','G','H','J','M','N'],
            'keywords_should' => ['digitalisation','transformation','data'],
            'score_weight' => 1.0,
            'is_active' => true,
        ]);
        AxionOfferTarget::create([
            'workspace_id' => $ws->id,
            'offer_code' => 'audit_essentielle',
            'label' => 'Audit Ciblé Essentielle',
            'target_size_min' => 10, 'target_size_max' => 250,
            'naf_sections_in' => ['C','G','J','K','M','N'],
            'keywords_should' => ['IA','automation','data','cloud'],
            'score_weight' => 1.2,
            'is_active' => true,
        ]);
        // mission_pme, mission_eti, grand_programme...
    }
}
```

### Blocked domains (disposable + spam)

- **Source :** `https://github.com/disposable/disposable/blob/master/blocklist.txt`
- **Commande :** `php artisan email:update-disposable-list` (mensuel)
- **Storage :** `storage/app/disposable_domains.json`

---

## B.4 — 12 prompts Claude Code prêts à l'emploi

Format de chaque prompt : copier-coller dans Claude Code → laisser tourner → review.

### Prompt 1 — Bootstrap projet

```
Tu es Architecte Logiciel Principal sur Axion CRM Pro.

CONTEXTE
Lis ces fichiers de spec avant tout :
- spec/00_INDEX.md
- spec/01_thinking_executive_naming.md
- spec/02_architecture_infra.md
- spec/18_deploiement_hetzner.md

MISSION
Initialise la structure complète du projet (Phase 1) :

1. Crée les répertoires backend/, frontend/, workers/, infra/ avec README minimaux par dossier.
2. Initialise Laravel 12 dans backend/ via `composer create-project --prefer-dist laravel/laravel:^12.0`. Configure .env avec valeurs sensibles via Doppler/Infisical pointeurs (placeholders).
3. Initialise React 19 + Vite 6 + TypeScript 5.6 + Tailwind 4 + shadcn/ui dans frontend/ via `pnpm create vite . --template react-ts` puis ajout deps.
4. Initialise Node 22 + Playwright 1.49 + BullMQ + Pino dans workers/.
5. Crée infra/docker-compose.app.yml + infra/docker-compose.data.yml + infra/docker-compose.worker.yml + infra/Dockerfile.laravel + infra/Dockerfile.worker selon spec 02 et 18.
6. Crée .github/workflows/ci.yml + deploy-staging.yml + deploy-prod.yml selon spec 18 § 5.
7. Première migration Laravel : `create_extensions` (CREATE EXTENSION pgcrypto, pg_trgm, postgis, pgvector, pg_partman, pg_cron, unaccent, btree_gin, citext).

CONTRAINTES
- Aucune valeur secret en clair dans le code/Git
- Toutes les versions exactes selon spec 02 § Stack
- Tests Pest + Vitest installés
- ESLint + Prettier + PHPStan configurés strict

CRITÈRES DONE
- `git push origin main` déclenche CI verte (au moins le job hello world)
- `docker compose -f infra/docker-compose.app.yml config` parse sans erreur
- `composer test` passe
- `pnpm test` passe

LIVRABLE
1 commit par étape ci-dessus, messages Conventional Commits (feat:, chore:, etc.).
À la fin, affiche un récap avec liste des commits + commande pour cloner le repo.
```

### Prompt 2 — DB migrations + RLS + seed référentiels

```
CONTEXTE
Lis :
- spec/03_db_schema_phase1.md (63 tables)
- spec/04_db_schema_phase2_scaffold.md (35 tables)
- spec/15_auth_multitenant_rbac.md § 4 (RLS)
- spec/23_interfaces_phase2_execution_pack.md § B.3 (Seeders)

MISSION
Implémente toutes les migrations Laravel + RLS policies + seeders référentiels.

1. Crée les ~98 migrations dans backend/database/migrations/ selon l'ordre indiqué dans spec 03 § 14 + spec 04 § 7.
2. Chaque migration utilise Schema::create() classique pour la majorité, mais DB::statement(raw SQL) pour :
   - Partitionnement pg_partman (audit_logs, scraper_runs, llm_usage, proxy_usage_log, email_sends)
   - Materialized view coverage_matrix_cells
   - RLS ALTER TABLE + CREATE POLICY
   - Triggers SQL (3 triggers Phase 2)
   - Functions SQL (normalize_name, recompute_company_quality_score)
3. Crée les Seeders pour : Countries (FR + EU member states), Regions (13 INSEE), Departments (101), NAF (732 codes), LegalForms, EffectifRanges, AxionOfferTargets, StrategicKeywords, AutoTagDefinitions, SearchEngines (5), UserAgents (50+).
4. Crée commands artisan : `import:ign-polygons --year=2026`, `import:naf-codes`, `import:legal-forms`, `refresh:user-agents`.

CONTRAINTES
- UUID v7 (via gen_random_uuid + futur uuidv7 quand pg_uuidv7 dispo)
- TIMESTAMPTZ partout
- snake_case identifiers
- Index composites obligatoires `(workspace_id, ...)`
- RLS sur toutes les tables avec workspace_id

CRITÈRES DONE
- `php artisan migrate:fresh --seed` exécute sans erreur (< 30 sec)
- `SELECT COUNT(*) FROM cities` ≥ 2150 (cities >5k habitants)
- `SELECT COUNT(*) FROM naf_subclasses` = 732
- Tests Pest : DatabaseSchemaTest passe avec assertions sur structure des 98 tables
- RLS effectif : test cross-workspace bloqué côté DB

LIVRABLE
- ~98 fichiers migration dans backend/database/migrations/
- ~12 fichiers seeder dans backend/database/seeders/
- 4 commands import dans backend/app/Console/Commands/Import/
- 1 commit "feat(db): migrations Phase 1 + Phase 2 scaffold + RLS + seeders référentiels"
```

### Prompt 3 — Auth + RBAC + Multi-tenant

```
CONTEXTE
Lis spec/15_auth_multitenant_rbac.md + spec/13_ui_admin_phase1.md § 1.

MISSION
1. Installe Laravel Sanctum 4 + Spatie Permission 6 + pragmarx/google2fa-laravel.
2. Implémente LoginController, MagicLinkController, TwoFactorController, PasswordResetController (cf. spec § 1-3).
3. Crée middleware SetCurrentWorkspace (RLS + workspace context).
4. Crée AuditLog model + hash chain (cf. § 6).
5. Crée Policies pour Company, Contact, EmailVerification, etc. (cf. § 5).
6. Implémente pages React : Login.tsx, TwoFactor.tsx, MagicLinkRequest.tsx, PasswordReset.tsx + AuthLayout.tsx.
7. Tests : 12+ tests Pest auth + 4 tests Playwright E2E.

CONTRAINTES
- Cookies HttpOnly + Secure + SameSite=lax
- Bcrypt rounds 12
- TOTP secret chiffré AES-256 (Crypt::encryptString)
- HIBP check sur password (api.pwnedpasswords.com/range/{prefix})
- Brute force protection 5 fails → lock 15 min
- Rate limit POST /login 5/min/IP

CRITÈRES DONE
- Tests Pest auth coverage ≥ 95%
- E2E Playwright login + 2FA passe
- Audit hash chain valide après 100 inserts (`php artisan audit:verify-chain`)
- Tests "user A workspace A cannot access workspace B data" passent

LIVRABLE
1 commit feat(auth): + 1 commit feat(rbac): + 1 commit test(auth): E2E
```

### Prompt 4 — LLM Router + Patterns transversaux

```
CONTEXTE
Lis : spec/07_llm_router.md + spec/09_proxy_pluggable_system.md + spec/10_rotations_universelles.md + spec/12_coverage_matrix_deduplication.md § 2.

MISSION
1. LLMClient interface + LLMRouterService + 5 providers (Anthropic, OpenAI, Mistral, OpenRouter, Ollama) selon spec 07.
2. PromptRenderer Twig + LLMCostCalculator + Redis cache + cost tracking.
3. ProxyProvider interface + WebshareProvider + IPRoyalProvider + ProxyRouter intelligent.
4. WeightedRoundRobin algorithme générique.
5. DeduplicationService 6 niveaux avec tests unitaires complets.
6. UserAgentSelector + ZoneRotator + SearchEngineRotator.
7. UI admin "LLM Router" (4 tabs : Providers, UseCases, Prompts, Usage) + "Proxy Providers" + "Rotations".

CONTRAINTES
- Aucun nom de modèle LLM hardcodé (toujours via llm_use_cases.primary_provider/primary_model)
- Aucun credentials API en clair (toujours via Doppler/Infisical pointeurs)
- Tests unitaires DeduplicationService : 100 % coverage
- Validation Spatie Data sur tous les DTOs

CRITÈRES DONE
- Test "use case sector_classification with Mistral Small returns JSON" passe
- Test "fallback chain on 429" passe
- Test "proxy router picks healthy proxy for google.com domain" passe
- Test "dedup niveau 6 (opt-out) blocks scraping" passe
- UI LLM Router test prompt button → JSON response affiché

LIVRABLE
4 commits : feat(llm), feat(proxies), feat(rotations), feat(dedup)
```

### Prompt 5 — Sources INSEE + annuaire-entreprises + BODACC + Coverage

```
CONTEXTE
Lis spec/05_scrapers_14_sources.md § 1+2+5 et spec/08_waterfall_enrichissement_classification.md.

MISSION
1. Implémente InseeSirenScraper + AnnuaireEntreprisesScraper + BodaccScraper (PHP, pas Playwright).
2. OAuth2 token manager pour INSEE (refresh auto).
3. WaterfallOrchestrator avec Spatie Model States 5 états (created/running/partial/success/failed).
4. Étapes 1+2+9 du waterfall actives. Autres étapes stub no-op.
5. ZoneRotator avec cooldown 24h + advisory lock parallel safety.
6. CoverageController API endpoint /api/v1/coverage.
7. UI page Companies (liste basique avec filtres 5 dimensions sur 10).

CRITÈRES DONE
- Smoke : `php artisan enrich:test --siren=12345678901` → company INSERT + dirigeant + signal BODACC si dispo
- Smoke : 100 SIRENs IDF NAF 6201Z enrichis → tous en `companies` avec quality `basic`
- Coverage matrix CONCURRENT refresh OK
- Tests Pest : 8+ tests scraping (mocks HTTP)

LIVRABLE
3 commits : feat(scrapers): INSEE + annuaire-entreprises + BODACC, feat(waterfall): state machine + étapes 1+2+9, feat(coverage): API + UI list basique
```

### Prompt 6 — Workers Playwright (Google Maps + PJ + Sites web)

```
CONTEXTE
Lis spec/05_scrapers_14_sources.md § 6+7+8 + spec/19_queues_workers_playwright.md.

MISSION
Phase 1 critique : workers Node Playwright avec proxies + stealth.

1. workers/ : implémente 3 workers (GoogleMapsPlugin, PagesJaunesPlugin, SiteWebPlugin) avec architecture plugin de spec 05.
2. Bridge Redis Laravel ↔ Node : DispatchPlaywrightScraperJob côté Laravel + main.ts côté Node + endpoint /internal/scraper-result côté Laravel.
3. playwright-extra-stealth + UA rotation depuis Laravel + proxy IPRoyal résidentiel.
4. Sites web : crawl 2-3 niveaux, extraction emails exhaustive (HTML + mailto + obfusqués), classification 4 catégories, détection pattern email entreprise, extraction équipe (structured CSS + fallback LLM use case extract_team_from_page).
5. UI page "Scraper Runs" avec filtres + drill-down + pattern detection.
6. Étapes 3+4 du waterfall actives.

CONTRAINTES
- Playwright headless avec viewport coherent avec UA (mobile vs desktop)
- Cookies banner cliqués automatiquement (regex multiple langues)
- Captcha detection + bascule auto + cool-down 30 min
- Pagination Google Maps + Pages Jaunes sans limite arbitraire 20 pages
- Tests E2E avec Playwright sur staging contre 10 sites de test prédéfinis

CRITÈRES DONE
- Smoke : 50 entreprises → tel + site web extraits ≥ 70%
- Smoke : 30 sites → pattern email détecté ≥ 80 %
- Smoke : 5 captchas Google Maps simulés → bascule détectée auto
- Tests : worker survit graceful shutdown (SIGTERM)

LIVRABLE
2 commits : feat(workers): Google Maps + Pages Jaunes + Bridge Redis, feat(workers): sites web + extraction emails + pattern detection
```

### Prompt 7 — Google Search Wrapper + Direction Finder

```
CONTEXTE
Lis spec/05_scrapers_14_sources.md § 9 + § Direction Finder + spec/07_llm_router.md § 9 (use cases linkedin_url_matching_scoring, extract_team_from_page, business_signal_detection).

MISSION (le plus chargé)
1. Worker `worker-google-search` : 3 moteurs Google/Bing/DuckDuckGo, rotation, captcha detection.
2. Scoring matching LLM via use case linkedin_url_matching_scoring (rejet homonymes).
3. Worker `worker-direction-finder` : 4 sources combinées (corporate pages 25 paths + presse + rapport annuel PDF + Google Search étendu C-level 5 types DRH/DAF/DSI/CMO/CCO).
4. pdf-parse intégré pour rapports annuels (download + extract leadership section + LLM parse).
5. Cache `corporate_pages_crawled` TTL 30j fonctionnel.
6. Étapes 5+6 du waterfall actives. Direction Finder conditionnel effectif ≥ 100.

CONTRAINTES
- Aucun scraping direct profil LinkedIn (uniquement URLs publiques retournées par moteurs)
- Pattern matching strict pour éviter faux positifs (nom dans URL + snippet + raison sociale)
- Rotation moteurs avec états (active/rate_limited/captcha_challenge/cooldown/disabled)
- LLM heavy : worker DF concurrence 2 (pas 6)

CRITÈRES DONE
- Smoke : 50 entreprises → ≥ 35 LinkedIn URLs entreprise trouvées
- Smoke : 10 ETI testées (TOTAL, Carrefour, Veolia, ...) → ≥ 3 C-level moyenne trouvés
- Test : captcha Google détecté → état changé en `captcha_challenge` + cooldown 30 min auto
- Test : 5 rapports annuels AMF parsés → 5 leadership extracts retournés

LIVRABLE
3 commits : feat(workers): Google Search Wrapper 3 moteurs, feat(workers): Direction Finder 4 sources, feat(waterfall): étapes 5+6 actives + conditional DF
```

### Prompt 8 — Email Finder + SMTP cascade

```
CONTEXTE
Lis spec/06_email_finder_validation.md (intégral).

MISSION
1. EmailFinderService complet : générateur 18 patterns + cache + SMTP cascade N1→N5 + scoring final 0-100.
2. CatchAllDetector (Redis cache 7j).
3. Disposable list embarquée + commande mensuelle update.
4. IPs dédiées validation : configuration rDNS + container `validator-smtp` sur worker-2.
5. Job hourly check_blacklists (Spamhaus, Barracuda, SORBS, Surriel).
6. Étape 7 waterfall active.
7. UI : section "Validation cascade" dans détail contact + bouton "Revalider".

CONTRAINTES
- Probe SMTP MAIL FROM = `validator@axion-pro.com` (boîte légitime, pas spammer)
- Timeouts stricts : 10s connect, 5s par command
- TTL 30j obligatoire (revalidation auto si > 30j)
- Cache MX 1h, catch-all 7j
- Stop early : si score ≥ 80 et status='valid', ne pas probe d'autres patterns

CRITÈRES DONE
- Test : "marie le gall" → 18 patterns générés (normalisation accents + particules OK)
- Test : "no-reply@example.com" → status=no_reply, score=0
- Test : domaine catch-all détecté → score 50-60
- Test : second probe identique → cache hit (pas de SMTP call)
- Test : IPs validator pas dans blacklists
- Smoke : 100 contacts → 60 % ont email validé score ≥ 70

LIVRABLE
2 commits : feat(email-finder): patterns + cascade SMTP + scoring, feat(infra): IPs dédiées validation + blacklist monitoring
```

### Prompt 9 — Carte France interactive

```
CONTEXTE
Lis spec/11_carte_france_interactive.md.

MISSION
1. Import IGN AdminExpress COG 2026 (commande `import:ign-polygons`).
2. Génération tuiles MVT via tippecanoe (regions + departments + cities >5k) → public/tiles/admin/.
3. Composant React <FranceCoverageMap /> avec 3 modes (Visualisation/Search/Action).
4. Endpoint /api/v1/coverage + cache Redis 60s.
5. Sub-composant <SearchMode /> auto-suggest 2150+ villes (Combobox shadcn).
6. Sub-composant <ActionMode /> panneau latéral avec stats + bouton "Lancer scraping zone".
7. Lazy-load MapLibre (dynamic import).

CONTRAINTES
- 100 % gratuit (MapLibre + OpenFreeMap + IGN + BAN)
- Performance : < 50 KB transfer initial, < 2s TTI sur simulated 4G
- A11y WCAG 2.1 AA : tab navigation + aria-label
- Type strict TypeScript

CRITÈRES DONE
- Carte se charge en < 2 s sur 4G
- Recherche "Paris" zoom auto lon=2.35, lat=48.85, zoom=10
- Clic zone → panneau ouvre + bouton "Lancer scraping" → 1 scraper_run created
- Test E2E Playwright passe

LIVRABLE
2 commits : feat(map): import IGN + tuiles MVT, feat(map): composant React 3 modes + API
```

### Prompt 10 — Classification LLM + UI complète

```
CONTEXTE
Lis spec/08_waterfall_enrichissement_classification.md + spec/13_ui_admin_phase1.md (intégral).

MISSION
1. ClassifierService : 4 use cases LLM (ia_maturity_scoring, axion_offer_match, auto_tag_generation, extract_strategic_keywords).
2. Recompute companies.quality_score via SQL function trigger sur changements pertinents.
3. AutoTagApplier (rules DSL JSONB).
4. UI : implémente toutes les pages Phase 1 manquantes (17 pages au total) selon wireframes spec 13.
5. Composants partagés : <QualityBadge />, <DiscoverySourceBadge />, <SizeCategoryBadge />, <PrioritySelect />, <NafSelector />, <DateRangePicker />.
6. Dashboard "coût par enrichissement" (p50/p95/p99).
7. Étape 10 waterfall active.

CONTRAINTES
- Dark mode + Light mode persistant localStorage
- a11y WCAG 2.1 AA
- TanStack Virtual pour listes > 1000 rows
- Filters URL-synced (Spatie Query Builder côté backend)

CRITÈRES DONE
- Smoke : 100 entreprises enrichies → toutes ont maturity + offer + tags + qualityScore non null
- Test E2E Playwright : navigation 17 pages sans erreur
- Tests visuels (Playwright screenshots) sur 3 résolutions (mobile, tablet, desktop)

LIVRABLE
3 commits : feat(classifier): 4 use cases LLM + recompute quality, feat(ui): 17 pages Phase 1 + composants partagés, feat(dashboard): coût par enrichissement
```

### Prompt 11 — Scaffold Phase 2 + RGPD + Monitoring

```
CONTEXTE
Lis spec/04_db_schema_phase2_scaffold.md + spec/17_rgpd_aiact_owasp.md + spec/13_ui_admin_phase1.md § 18-22 + spec/16_monitoring_observabilite.md.

MISSION
1. 5 pages Phase 2 stubs : Campaigns, Cold Email, LinkedIn, CRM, Analytics (wireframes "bientôt disponible").
2. Routes API Phase 2 → 501 avec types Spatie Data.
3. Triggers SQL Phase 2 créés (firent jamais Phase 1).
4. UI RGPD requests complète + GdprErasureService (transaction multi-tables atomique).
5. GdprPortabilityService (export JSON encrypted).
6. AI Act register UI.
7. Monitoring stack déployé via docker-compose.observability.yml.
8. 10 dashboards Grafana provisionnés (JSON Git dans grafana-provisioning/).
9. Alertmanager rules + Slack/Telegram routing configurés.
10. Anomaly detector job `app:anomalies:detect` every 15 min.

CRITÈRES DONE
- Page Phase 2 /campaigns retourne placeholder
- GET /api/v1/campaigns → 501 avec body typé
- Test : 1 demande RGPD erasure end-to-end (identité validée) → contacts anonymized + opt-out global added
- Grafana 10 dashboards accessibles + data populated
- Test alerte ScrapingSourceErrorSpike fire correctly

LIVRABLE
3 commits : feat(phase2-scaffold): 5 pages + routes 501 + triggers, feat(rgpd): erasure + portability + AI Act register, feat(monitoring): Prometheus + Grafana + 10 dashboards + Alertmanager
```

### Prompt 12 — Polish + E2E + Doc + Promotion prod

```
CONTEXTE
Tout le repo, focus :
- spec/16_monitoring_observabilite.md § 14 (SLOs)
- spec/18_deploiement_hetzner.md § 7-8 (DR + runbooks)
- spec/21_couts_roadmap.md § 3 (GO/NO-GO criteria)
- spec/22_risques_mitigations.md

MISSION
1. 50+ tests E2E Playwright (auth + CRUD entreprises + scraping + RGPD + map).
2. Tests load k6 : 100 req/s API tient sans dégradation.
3. Documentation auto-générée OpenAPI (`darkaonline/l5-swagger`) + Swagger UI à /docs.
4. Runbooks /runbooks/ : restart workers, disk plein, site down 5xx, restore DR, rotation secrets.
5. Penetration test interne (Burp Suite Pro basic scan).
6. DR drill simulé : restore depuis pgbackrest sur server temporaire, mesure RTO.
7. Promotion staging → prod : DNS Cloudflare orange + HSTS preload 12 mois + DNSSEC.
8. Status page Uptime Kuma publique (status.axion-pro.com).
9. Verification GO/NO-GO contre les 9 critères spec 21 § 3.

CRITÈRES DONE
- Tous les SLOs Phase 1 atteints
- DR drill RTO < 4h validé
- Audit log hash chain valide en prod
- ≥ 50 000 fiches 🟢 dans workspace axion-ia
- Penetration test : aucune vulnérabilité High/Critical
- 50+ tests E2E Playwright verts en CI

LIVRABLE
- 3 commits : test(e2e): 50+ scénarios Playwright, docs(runbooks): 5 runbooks ops, feat(deploy): promotion prod + DNSSEC + HSTS preload
- Issue GitHub "GO/NO-GO checklist" avec verdict 🟢/🟡/🔴
- Tag git `v1.0.0-phase1-go` après validation
```

---

## Mot de fin

Cette spec couvre **100 % de Phase 1** + **scaffold complet Phase 2** + **12 prompts Claude Code prêts** pour démarrer l'implémentation en autonomie.

À l'issue des 12 étapes, Axion CRM Pro Phase 1 sera **GO PROD** avec ≥ 50 000 fiches 🟢 prêtes à exploiter pour Axion-IA.

Phase 2 (cold email + LinkedIn outreach + CRM + analytics) sera spec'ée dans un V7 dédié quand Phase 1 validée par le terrain.

**Bonne implémentation. 🚀**
