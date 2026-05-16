# 23 — INTERFACES PHASE 2 + EXECUTION PACK

## Vue d'ensemble

Ce fichier est le **plus important pour la suite** : il fait la jonction entre la spec V1 (terminée) et l'implémentation effective du code. Il se compose de :

- **Partie A — Interfaces Phase 2** : comment Phase 1 prépare techniquement Phase 2 (tables, events, queues, hooks)
- **Partie B — Execution Pack** : 4 sous-parties opérationnelles
  - B.1 Code Generation Roadmap (12 étapes ordonnées)
  - B.2 Tests Acceptance Criteria
  - B.3 Data Seeders & Assets
  - B.4 12 Prompts Claude Code prêts à l'emploi

---

# PARTIE A — Interfaces Phase 2

## A.1 Module Cold Email — Interfaces Phase 1 ↔ Phase 2

### Tables Phase 1 consommables
- `companies` (filtre par `axion_offer`, `priority_score`, `contact_priority`)
- `contacts` (filtre par `position_function`, `is_executive`)
- `company_emails` (filtre par `email_type = nominative` + `is_validated = true` + `validation_score >= 70`)
- `email_patterns` (réutilisation pattern entreprise pour génération adresse cible)
- `opt_out` (consulté avant envoi)
- `email_verifications` (TTL 30j — re-validate si périmé avant envoi)

### Events Laravel à émettre (Phase 1 → Phase 2)
- `App\Events\ContactReadyForColdEmail` — déclenché quand un contact obtient un email validé score ≥ 70
- `App\Events\LeadScored` — déclenché après chaque recalcul `priority_score`
- `App\Events\BusinessSignalCriticalDetected` — déclenché sur signal critical (utile pour priorisation cold email)

En V1 ces events sont **définis** dans `app/Events/` avec leur shape, mais **aucun listener actif**. En Phase 2 on ajoute un listener `EnrollContactInColdEmailCampaignListener` qui consomme `ContactReadyForColdEmail`.

### Routes API à exposer en Phase 2
Toutes définies dans fichier 14 §16.1 (`/api/cold-email/*`). Renvoient 501 en V1.

### Queues BullMQ/Horizon à produire
- `cold-email-send` (PHP Horizon) — déjà nommée, worker vide en V1
- `cold-email-tracking-pixel` (PHP Horizon) — réception événements deliverability
- `cold-email-bounce-handler` (PHP Horizon) — handle bounces SMTP

### Hooks d'intégration côté Phase 1
Trait PHP `App\Modules\Phase2\Traits\IsColdEmailReady` à appliquer sur `Contact` model :
```php
trait IsColdEmailReady
{
    public function isColdEmailReady(): bool
    {
        $email = $this->emails()->where('is_validated', true)->where('validation_score', '>=', 70)
                        ->where('email_type', 'nominative')->where('is_excluded', false)->first();
        if (!$email) return false;
        if (OptOut::where('email', $email->email)->exists()) return false;
        return true;
    }
}
```
Préparation en V1, utilisation en Phase 2.

---

## A.2 Module LinkedIn Outreach — Interfaces

### Tables consommables
- `companies` + `contacts` (filtres)
- `linkedin_accounts` (rotation déjà gérée V1)
- `opt_out` (filtre par `phone_e164` au lieu d'email côté LinkedIn)

### Events
- `App\Events\ContactReadyForLinkedInOutreach` — émis quand contact a un `linkedin_url` + n'est pas dans opt_out
- `App\Events\LinkedInAccountSuspicious` — réutilise event Phase 1

### Routes API
Toutes dans fichier 14 §16.2.

### Queues
- `linkedin-outreach-connect` — BullMQ Node
- `linkedin-outreach-message` — BullMQ Node
- `linkedin-outreach-reply-detect` — PHP Horizon (LLM use case `reply_intent_detection`)

### Hooks
Service `App\Modules\Phase2\LinkedIn\LinkedInOutreachService` (squelette en V1, implémenté Phase 2).

---

## A.3 Module CRM — Interfaces

### Tables consommables
- `companies`, `contacts` (création de deals depuis fiche entreprise)
- `crm_deals`, `crm_stages`, `crm_pipelines` (déjà scaffoldées V1)
- `crm_activities` (log automatique depuis emails + LinkedIn réponses)

### Events
- `App\Events\DealCreatedFromContact` — émis quand l'opérateur clique "Créer deal" depuis une fiche contact
- `App\Events\DealStageChanged` — émis sur changement de stage
- `App\Events\ReplyReceivedAndScored` — émis quand une réponse Cold Email/LinkedIn est détectée + intent scoré

### Routes API
Toutes dans fichier 14 §16.3.

### Queues
- `crm-deal-scoring` — recalcul score deal après chaque activité
- `crm-task-reminders` — envoi notifications Slack/Telegram pour tâches dues

### Hooks
- Action `App\Modules\Phase2\Crm\Actions\CreateDealFromContact` (squelette V1)
- Listener `App\Modules\Phase2\Crm\Listeners\AutoLogReplyAsActivity`

---

## A.4 Module Analytics avancées — Interfaces

### Tables consommables
- Toutes les tables Phase 1 + Phase 2 selon période
- `analytics_snapshots` (snapshot quotidien KPIs scoped)
- `analytics_funnels`, `analytics_cohorts`

### Events
- `App\Events\DailyAnalyticsSnapshotComputed` — émis nightly

### Routes API
Toutes dans fichier 14 §16.5.

### Queues
- `analytics-snapshot-daily` (PHP Horizon, scheduler nightly)
- `analytics-funnel-compute` (PHP Horizon, on-demand)

---

# PARTIE B — Execution Pack

## B.1 Code Generation Roadmap (12 étapes)

> Chaque étape = 1 prompt Claude Code (cf B.4) + revue Will. Effort estimé "Claude Code Opus 4.7 + Will senior" en jours.

### Étape 1 — Bootstrap Laravel 12 + DB migrations Phase 1

| Champ | Valeur |
|---|---|
| **Inputs requis** | spec/00, spec/02, spec/03 |
| **Outputs attendus** | `backend/` Laravel 12 skeleton + ~52 migrations + seeders bootstrap (extensions, helper SQL, RLS) |
| **Critères done** | `php artisan migrate` OK, RLS appliquée, test fuzzing cross-tenant 0 leak |
| **Dépendances** | Aucune |
| **Effort estimé** | 2j Claude Code / 5j Will senior seul |

### Étape 2 — DB migrations Phase 2 scaffold

| Champ | Valeur |
|---|---|
| **Inputs requis** | spec/04 |
| **Outputs attendus** | 31 migrations Phase 2 (toutes vides logiquement mais structurées) |
| **Critères done** | `php artisan migrate` OK + tests RLS Phase 2 |
| **Dépendances** | Étape 1 |
| **Effort estimé** | 1j Claude Code |

### Étape 3 — Auth + Multi-tenant + RBAC

| Champ | Valeur |
|---|---|
| **Inputs requis** | spec/15 |
| **Outputs attendus** | Sanctum config + TOTP 2FA + magic link + middleware `InjectWorkspace` + RolesAndPermissionsSeeder + audit log hash chain service |
| **Critères done** | Login + 2FA + workspace switch fonctionnent + tests Pest verts |
| **Dépendances** | Étape 1 |
| **Effort estimé** | 2j Claude Code / 5j Will seul |

### Étape 4 — LLM Router service complet

| Champ | Valeur |
|---|---|
| **Inputs requis** | spec/07 |
| **Outputs attendus** | `LLMClient` interface + 5 providers + orchestrateur fallback + recorder + AB tester + seeder use cases & templates |
| **Critères done** | `generate('ia_maturity_scoring', ...)` retourne réponse Claude Haiku valide + cost recorded |
| **Dépendances** | Étape 1 |
| **Effort estimé** | 2j Claude Code |

### Étape 5 — Système Proxies pluggable + 4 providers

| Champ | Valeur |
|---|---|
| **Inputs requis** | spec/09, spec/10 |
| **Outputs attendus** | Interface `ProxyProvider` + Webshare + IPRoyal + Smartproxy + BrightData + `ProxyRouter` intelligent + health checks |
| **Critères done** | `ProxyRouter::leaseFor('google.com')` retourne proxy résidentiel valide |
| **Dépendances** | Étape 1 |
| **Effort estimé** | 2j Claude Code |

### Étape 6 — Plugins scraping sources gratuites (INSEE + annu-ent + BODACC + France Travail + BAN + MESRI)

| Champ | Valeur |
|---|---|
| **Inputs requis** | spec/05, spec/19 |
| **Outputs attendus** | 6 plugins PHP + 6 queues Horizon + tests intégration sources réelles |
| **Critères done** | Import 1 000 entreprises Paris INSEE + enrichissement annu-ent OK |
| **Dépendances** | Étapes 1, 5 |
| **Effort estimé** | 3j Claude Code |

### Étape 7 — Workers Node.js Playwright (sources scraping headless)

| Champ | Valeur |
|---|---|
| **Inputs requis** | spec/05, spec/19 (workers Node) |
| **Outputs attendus** | `workers/` Node.js + 5 workers Playwright stealth (gmaps, pj, website, crunchbase, social-light) + BullMQ + bridge `scrape-results` |
| **Critères done** | Scraping Google Maps "Boulangerie Paris 75" sans captcha sur 50 runs |
| **Dépendances** | Étapes 5, 6 |
| **Effort estimé** | 3j Claude Code |

### Étape 8 — Email Finder + Validation SMTP cascade

| Champ | Valeur |
|---|---|
| **Inputs requis** | spec/06 |
| **Outputs attendus** | `EmailPatternGenerator` + cascade SMTP 5 niveaux + cache TTL 30j + workers dédiés `email-validate` |
| **Critères done** | Valid 10k emails en < 30 min + faux positifs < 3 % sur sample 100 |
| **Dépendances** | Étape 1 |
| **Effort estimé** | 2j Claude Code |

### Étape 9 — Waterfall enrichissement + Classification LLM

| Champ | Valeur |
|---|---|
| **Inputs requis** | spec/08 |
| **Outputs attendus** | State machine Spatie + 9 step jobs + orchestrateur Bus::chain([Bus::batch(...)]) + 4 use cases classification LLM activés |
| **Critères done** | Waterfall complet < 30s p95 sur 200 entreprises test |
| **Dépendances** | Étapes 4, 6, 7, 8 |
| **Effort estimé** | 3j Claude Code |

### Étape 10 — Frontend React (skeleton + 5 pages clés)

| Champ | Valeur |
|---|---|
| **Inputs requis** | spec/13, spec/14 |
| **Outputs attendus** | Vite + React 19 + Tailwind 4 + Router 7 + TanStack Query + Login + Dashboard + Companies (virtualized) + Coverage map + LLM config |
| **Critères done** | Login + parcours Coverage map + parcours Companies fluide |
| **Dépendances** | Étapes 3, 4, 9 |
| **Effort estimé** | 4j Claude Code |

### Étape 11 — Reste UI Phase 1 + 5 placeholders Phase 2 + Detection nightly jobs

| Champ | Valeur |
|---|---|
| **Inputs requis** | spec/13 (pages restantes), spec/20 (jobs) |
| **Outputs attendus** | 12 pages Phase 1 restantes + 5 placeholders Phase 2 + 16 jobs Scheduler |
| **Critères done** | 17 pages Phase 1 navigables + jobs nightly tournent sans overlap |
| **Dépendances** | Étape 10 |
| **Effort estimé** | 3j Claude Code |

### Étape 12 — Monitoring + Conformité + Déploiement + Tests E2E

| Champ | Valeur |
|---|---|
| **Inputs requis** | spec/16, spec/17, spec/18 |
| **Outputs attendus** | 40+ métriques Prometheus + 10 dashboards Grafana + alerting + 7 traitements RGPD documentés + ai_act_register seedé + docker-compose prod + GitHub Actions deploy + tests Playwright 5 parcours clés |
| **Critères done** | Go-live `https://crm.axion-ia.com` avec auth + scraping + observabilité OK |
| **Dépendances** | Toutes les étapes précédentes |
| **Effort estimé** | 4j Claude Code |

### Récapitulatif effort

| Méthode | Total |
|---|---|
| Avec Claude Code Opus 4.7 1M context | **~31 jours** (~6 semaines à 5j/semaine) |
| Will senior solo sans assistance | **~75 jours** (~15 semaines) |

---

## B.2 Tests Acceptance Criteria

### Backend (Pest)

- **Coverage minimum :** 80 % global, 90 % pour services métier critiques
- **Tests d'intégration DB** : RLS multi-tenant, fuzzing cross-workspace, dedup 6 niveaux
- **Tests unitaires** : EmailPatternGenerator (18 patterns), LLMRouter (fallback chain), ProxyRouter (scoring), PriorityCalculator
- **Tests feature** : tous les endpoints API Phase 1 (Pest HTTP tests)

### Frontend (Vitest)

- **Coverage minimum :** 70 % composants critiques
- **Tests unitaires** : formulaires, validators, hooks (useCompany, useCoverageMatrix)
- **Tests d'intégration** : flux complets (login → dashboard → companies → détail)

### E2E (Playwright)

5 parcours critiques :

1. **Login + 2FA setup + navigation Dashboard**
2. **Lancer scraping zone depuis carte interactive**
3. **Override score Axion-IA d'une entreprise (audit log vérifié)**
4. **Créer une requête RGPD erasure → traitement → vérification suppressions**
5. **Modifier un use case LLM (provider primary) → tester un prompt → vérifier coût**

### Datasets de test

- Dataset DB seed : 100 workspaces fictifs × 1 000 companies × 5 contacts = 500k rows test
- Fixtures HTML : 50 pages d'entreprises "réelles" pour tester crawler emails
- Fixtures INSEE : 200 SIREN connus
- Fixtures BODACC : 50 annonces variées

---

## B.3 Data Seeders & Assets

### Dataset 1 — IGN AdminExpress COG 2026

| Champ | Valeur |
|---|---|
| **Source officielle** | https://geoservices.ign.fr/adminexpress |
| **Licence** | Open License Etalab 2.0 |
| **Format** | Shapefile (7z) |
| **Taille** | ~600 Mo raw, ~18 Mo après simplification mapshaper |
| **Fréquence MAJ** | Annuelle (COG ~ avril) |
| **Commande import** | `php artisan axion:import-geo --regions=... --departments=... --cities=...` |
| **Tables cibles** | `regions`, `departments`, `cities` |
| **Temps import** | ~10 min |
| **Idempotence** | OUI (UPSERT par `insee_code`) |

### Dataset 2 — Base villes Axion-IA (format JSON)

| Source | INSEE Recensement + IGN COG 2026 |
| Format | JSON (~10 Mo) |
| Tables cibles | `cities` (population, lat/lng) |
| Mise à jour | Annuelle |

### Dataset 3 — Codes NAF INSEE (732 sous-classes + 4 niveaux supérieurs)

| Source | https://www.insee.fr/fr/information/2406147 |
| Licence | Open INSEE |
| Format | CSV |
| Commande | `php artisan axion:import-naf` |
| Tables cibles | `naf_sections`, `naf_divisions`, `naf_groups`, `naf_classes`, `naf_subclasses` |
| Note | Marquer `is_axion_priority` pour les NAF cibles (~40 sous-classes) |

### Dataset 4 — Référentiel administratif INSEE (codes COG)

| Source | https://www.insee.fr/fr/information/6800675 |
| Format | CSV |
| Tables cibles | référentiels jonction |

### Dataset 5 — Formes juridiques INSEE

| Source | https://www.insee.fr/fr/information/2028129 |
| Format | CSV |
| Tables cibles | `legal_forms` |

### Dataset 6 — Tranches d'effectifs INSEE

| Source | https://www.insee.fr/fr/information/2031033 |
| Format | CSV statique |
| Tables cibles | `effectif_ranges` (codes 00..53 + tiers TPE/PME/ETI/GE/UNKNOWN) |

### Dataset 7 — Pool User-Agents (50+)

| Source | https://www.useragents.me/ + https://github.com/microlinkhq/top-user-agents |
| Format | JSON |
| Tables cibles | `user_agents` |
| Fréquence MAJ | Mensuelle automatique via job `RefreshUserAgentsJob` |

### Dataset 8 — Pages cibles crawl par défaut (FR + EN + ES + DE)

| Source | Inline dans `App\Modules\Sources\Plugins\WebsitePriorityPaths` |
| Format | array PHP statique |
| Pages | `/contact`, `/equipe`, `/team`, `/about`, ... (15+ chemins) |

### Dataset 9 — Patterns email à tester (15+)

| Source | Inline dans `App\Modules\EmailFinder\Constants\Patterns` |
| Format | array PHP statique |
| Note | 18 patterns définis dans spec/06 |

### Dataset 10 — Mots-clés stratégiques (~50)

| Source | Inline dans seeder `StrategicKeywordsSeeder` |
| Mots-clés | digital, IA, cloud, transformation, modernisation, data, cybersecurité, devops, ML, automation, ... |
| Tables cibles | `strategic_keywords` |

### Dataset 11 — Cibles Axion-IA prioritaires (taxonomie)

| Source | spec/01 + spec/08 — table `axion_offer_targets` |
| Offres | audit_flash / audit_cible / mission_pme / mission_eti / grand_programme |
| Tables cibles | `axion_offer_targets` |

### Dataset 12 — Blocked domains

| Source | Liste noire emails disposables + domaines non-cible |
| Source externe | https://github.com/disposable-email-domains/disposable-email-domains |
| Format | TXT (1 domaine/ligne) |
| Tables cibles | (constant PHP — usage par EmailFinder) |
| Fréquence MAJ | Mensuelle |

---

## B.4 12 Prompts Claude Code prêts à l'emploi

> Chaque prompt est conçu pour être copier-collé directement dans Claude Code. Self-contained avec contexte + tâche + critères de done.

### Prompt 1 — Bootstrap Laravel 12 + migrations Phase 1

```
Tu es Architecte Logiciel Principal sur Axion CRM Pro. Lis les fichiers ./spec/00_INDEX.md, ./spec/02_architecture_infra.md et ./spec/03_db_schema_phase1.md.

Tâche : crée le dossier `backend/` avec Laravel 12 + PHP 8.3 fresh install (composer create-project laravel/laravel ^12.0 backend). Puis génère TOUTES les migrations Phase 1 (~52 tables) en respectant strictement le SQL du fichier 03 (types exacts, FK, indexes, RLS policies, partitionnement pg_partman). Pour chaque table tenant-scoped, active RLS via raw SQL dans la migration. Crée le helper SQL `app_workspace_id()` dans `bootstrap_migration`. Seed les rôles `owner/admin/operator/viewer` Spatie. Lance `php artisan migrate` pour valider.

Contraintes :
- Laravel 12 strict (pas Laravel 11 ni 10)
- composer require: laravel/sanctum, laravel/horizon, spatie/laravel-permission, spatie/laravel-data, spatie/laravel-model-states, pragmarx/google2fa-laravel
- Configuration RLS via raw DB::statement
- Tests Pest : 1 test par table critique vérifie l'existence + le RLS + l'index principal

Critères done :
- php artisan migrate OK
- Test cross-tenant fuzzing : 0 leak entre 2 workspaces fictifs
- composer test verts

Commence par lire les 3 fichiers ci-dessus puis exécute la tâche. À la fin, fais un commit `feat: bootstrap laravel 12 + db schema phase 1`.
```

### Prompt 2 — DB migrations Phase 2 scaffold

```
Tu es Architecte Logiciel Principal sur Axion CRM Pro. Lis ./spec/04_db_schema_phase2_scaffold.md.

Tâche : génère TOUTES les 31 migrations Phase 2 scaffold dans `backend/database/migrations/`. Chaque table doit avoir :
- workspace_id + FK + RLS policy
- COMMENT ON TABLE 'Phase 2 scaffold — créée pour structure future, pas de logique métier active'
- Indexes mentionnés dans spec
- Aucun seeder métier (Phase 2 = vide)

Inputs : ./spec/04_db_schema_phase2_scaffold.md

Critères done :
- 31 nouvelles migrations existent et passent migrate
- Test Pest : pour chaque table, vérifie l'existence + RLS active + commentaire correct
- Aucune insert seed métier

Commit final : `feat: db schema phase 2 scaffold (31 tables empty)`.
```

### Prompt 3 — Auth + Multi-tenant + RBAC

```
Tu es Architecte Logiciel Principal sur Axion CRM Pro. Lis ./spec/15_auth_multitenant_rbac.md.

Tâche : implémente le module Auth complet :
1. Configuration Sanctum cookie SPA (config/sanctum.php)
2. Routes /api/auth/* (login, logout, 2fa setup/verify, magic link request/consume, refresh, me)
3. Service `TwoFactorService` (TOTP + backup codes hashés bcrypt)
4. Middleware `InjectWorkspace` qui exécute SET LOCAL app.workspace_id
5. RBAC Spatie : 4 rôles + ~50 permissions seedées
6. Service `AuditLogger` avec hash chain SHA-256
7. Triggers PG pour bloquer UPDATE/DELETE sur audit_logs
8. Tests Pest : login + 2FA + magic link + cross-tenant fuzzing + hash chain integrity

Critères done :
- Test E2E : POST /api/auth/login + verify TOTP + GET /api/auth/me retourne user + workspace + permissions[]
- Test cross-tenant : user A ne peut PAS lire les companies de workspace B
- Test hash chain : modification manuelle d'un audit_log casse la chaîne (vérifier via /api/audit-logs/verify-integrity)

Commit final : `feat: auth sanctum + 2fa + rbac spatie + audit log hash chain`.
```

### Prompt 4 — LLM Router complet

```
Tu es Architecte Logiciel Principal sur Axion CRM Pro. Lis ./spec/07_llm_router.md.

Tâche : implémente le LLM Router complet :
1. Interface `App\Modules\LlmRouter\Contracts\LLMClient`
2. 5 implémentations providers (AnthropicProvider, OpenAIProvider, MistralProvider, OpenRouterProvider, OllamaProvider) avec calcul cost_eur_micro selon pricing du fichier 07
3. Orchestrateur `LlmRouterOrchestrator` avec fallback chain, A/B testing (resolve via hash deterministe), cost tracking, JSON parse safe
4. Tables seedées : 10 use cases Phase 1 + 5 use cases Phase 2 (disabled) + 10 prompt_templates avec system+user prompts en YAML
5. Tests Pest : 1 test par provider (mocké HTTP), 1 test fallback chain, 1 test A/B (90/10 split)

Inputs : ./spec/07_llm_router.md

Critères done :
- `app(LLMClient::class)->generate('ia_maturity_scoring', [...])` retourne une réponse mockée + cost recorded en `llm_usage`
- Fallback chain s'active si primary provider mock retourne 500
- A/B 90/10 produit 9 % à 11 % de variant B sur 10 000 calls

Commit final : `feat: llm router 5 providers + fallback chain + ab testing + cost tracking`.
```

### Prompt 5 — Système Proxies + 4 providers

```
Tu es Architecte Logiciel Principal sur Axion CRM Pro. Lis ./spec/09_proxy_pluggable_system.md et ./spec/10_rotations_universelles.md.

Tâche : implémente le système pluggable de proxies :
1. Interface `App\Modules\Proxies\Contracts\ProxyProvider`
2. 4 implémentations : WebshareProvider, IPRoyalProvider, SmartproxyProvider, BrightDataProvider
3. Routeur intelligent `ProxyRouter` avec scoring (success_rate × 0.5 + latency × 0.2 + usage × 0.2 + budget × 0.1)
4. `DomainProfileService` (domain → forbidsTypes, requiresType)
5. Health check job `BatchProxyHealthCheckJob` (toutes les 15 min)
6. Cool-down auto sur ban (24h) + auto-restore via `RestoreCooledProxiesJob` nightly
7. Tests Pest : routing par domaine (societe.com → résidentiel obligatoire), cooldown, scoring

Critères done :
- `app(ProxyRouter::class)->leaseFor('google.com')` retourne un proxy résidentiel valide
- Cooldown 24h s'applique automatiquement sur captcha détecté
- Ajout d'un 5e provider via factory + ligne en DB fonctionne sans modification du router

Commit final : `feat: proxy pluggable system + 4 providers + intelligent router`.
```

### Prompt 6 — Plugins scraping API gratuites (INSEE + annu-ent + BODACC + France Travail + BAN + MESRI)

```
Tu es Architecte Logiciel Principal sur Axion CRM Pro. Lis ./spec/05_scrapers_14_sources.md (sources 1, 2, 5, 10, 11, 13 = sources via API) et ./spec/19_queues_workers_playwright.md.

Tâche : implémente 6 plugins PHP scraping (sources qui ne nécessitent pas Playwright) :
1. InseeSirenePlugin (OAuth2 + pagination sans limite + 30 req/min)
2. AnnuaireEntreprisesPlugin (API + fallback HTML cheerio)
3. BodaccPlugin (signaux classifiés via heuristique + LLM `business_signal_detection` pour cas ambigus)
4. FranceTravailPlugin (OAuth2 + filtres mots-clés C-level)
5. BanGeocodingPlugin (api-adresse.data.gouv.fr + LLM `geocoding_disambiguation` si multi-résultats)
6. MesriOnisepPlugin (open data CSV + API enseignementsup-recherche.gouv.fr)

+ 6 Jobs Horizon : `EnrichWithInseeJob`, `EnrichWithAnnuaireEntreprisesJob`, etc. avec tries=5, backoff exp, timeout 60-180s
+ 6 queues nommées correctement

Critères done :
- Import 1 000 SIREN Paris depuis INSEE en < 5 min
- Enrichissement annu-ent OK sur 100 SIREN connus → dirigeants + CA insérés en DB
- Signaux BODACC classifiés correctement sur 50 annonces tests
- Géocodage BAN 99 %+ succès sur sample 200

Commit final : `feat: 6 scrapers API gratuites (insee + annu-ent + bodacc + ft + ban + mesri)`.
```

### Prompt 7 — Workers Node Playwright (gmaps + pj + website + crunchbase + social-light)

```
Tu es Architecte Logiciel Principal sur Axion CRM Pro. Lis ./spec/05_scrapers_14_sources.md (sources 6, 7, 8, 12, 14) et ./spec/19_queues_workers_playwright.md.

Tâche : crée le dossier `workers/` Node.js TypeScript :
1. package.json + tsconfig.json + Dockerfile multi-stage (base playwright:noble)
2. Bootstrap BullMQ + Pino logger JSON → Loki
3. 5 workers Playwright stealth :
   - gmaps-scraper-worker (résidentiel obligatoire, pagination sans limite via scroll, captcha detect)
   - pj-scraper-worker (datacenter OK)
   - website-crawler-worker (cheerio + Playwright fallback, extraction emails exhaustive classifiée nominative/role_based/generic/no_reply, 2-3 niveaux profondeur)
   - crunchbase-scraper-worker (résidentiel premium, rate 1/min)
   - social-light-scraper-worker (handles X/Insta/TikTok/YT/FB/GitHub)
4. Lib `proxy-client.ts` qui appelle `/api/internal/proxies/next` Laravel
5. Bridge `scrape-results` queue Node → PHP via BullMQ shared Redis
6. Graceful shutdown SIGTERM

Critères done :
- Worker gmaps scrape "Boulangerie Paris 75" → 100+ business sans captcha sur 50 runs successifs
- Worker website crawl 50 sites entreprises → moyenne 5 emails classifiés/site
- Communication scrape-results : 0 perte sur 10k jobs simulés

Commit final : `feat: workers node playwright (gmaps + pj + website + crunchbase + social-light)`.
```

### Prompt 8 — Email Finder + Validation SMTP cascade

```
Tu es Architecte Logiciel Principal sur Axion CRM Pro. Lis ./spec/06_email_finder_validation.md.

Tâche : implémente Email Finder + Validation :
1. `EmailPatternGenerator` (18 patterns, normalisation accents FR, particules)
2. `CompanyPatternDetector` (heuristique + LLM `detect_email_pattern` fallback)
3. Cascade SMTP 5 niveaux (`SyntaxValidator`, `MxValidator`, `SmtpHandshake`, `CatchallDetector`, `FinalScorer`)
4. Workers `email-validate` (2 instances, IPs séparées, HELO `validator.axion-ia.com`)
5. Cache TTL 30j sur `email_verifications` + job `RevalidateExpiredEmailsJob`
6. Job `RefreshDisposableListJob` mensuel
7. `OptOutGuard` consulté avant chaque tentative

Critères done :
- Valider 10 000 emails mix (nominative + role_based + generic) en < 30 min
- Faux positifs < 3 % sur sample 100 (validation manuelle)
- Pattern entreprise détecté sur ≥ 80 % des entreprises avec ≥ 3 emails nominatifs
- Cache 30j : re-validate = 0 SMTP call

Commit final : `feat: email finder 18 patterns + smtp cascade 5 levels + ttl 30j`.
```

### Prompt 9 — Waterfall enrichissement + Classification LLM

```
Tu es Architecte Logiciel Principal sur Axion CRM Pro. Lis ./spec/08_waterfall_enrichissement_classification.md.

Tâche : implémente le waterfall 9 étapes :
1. State machine Spatie `EnrichmentState` avec 12 états + transitions (Pending → Identifying → Enriching → Crawling → Investigating → Validating → Classifying → Completed)
2. 9 jobs Step (StepIdentifyJob, StepEnrichLegalJob, StepContactInfoJob, StepWebsiteCrawlJob, StepClevelJob, StepGeocodeJob, StepBusinessSignalsJob, StepEmailFinderJob, StepClassifyJob, StepFinalizeJob)
3. Orchestrateur `WaterfallOrchestrator` avec Bus::chain([..., Bus::batch([...]), ...])
4. 4 use cases LLM Classification activés (ia_maturity_scoring, axion_offer_match, auto_tag_generation, extract_strategic_keywords)
5. `PriorityCalculator` (calcule priority_score + contact_priority depuis formules fichier 08)
6. Override manuel préservé (priority_override)

Critères done :
- Waterfall complet < 30s p95 sur 200 entreprises test
- Taux fiches "Completed" ≥ 75 %
- Coût LLM moyen par entreprise classifiée ≤ 0,0015 €
- Test : override priority_score persiste après re-enrichissement

Commit final : `feat: waterfall 9 steps + state machine spatie + classification llm`.
```

### Prompt 10 — Frontend React skeleton + 5 pages clés

```
Tu es Architecte Logiciel Principal sur Axion CRM Pro. Lis ./spec/13_ui_admin_phase1.md et ./spec/11_carte_france_interactive.md.

Tâche : crée le dossier `frontend/` :
1. Vite 6 + React 19 + TypeScript 5.6 strict + Tailwind 4 + shadcn/ui-react
2. axios client + interceptors Sanctum + TanStack Query 5
3. AppShell + Sidebar + Topbar + dark theme (zinc-950 base + terracotta accent)
4. 5 pages clés :
   - LoginPage (+ TwoFASetupModal + MagicLinkRequest)
   - DashboardPage (KPIs cards)
   - CompaniesPage (TanStack Virtual table + filtres + bulk actions)
   - CoveragePage (FranceCoverageMap 3 modes via MapLibre + OpenFreeMap + IGN)
   - LlmConfigPage (use cases + Monaco editor templates + costs dashboard)
5. Tests Vitest : composants critiques, hooks useCompany / useCoverageMatrix

Critères done :
- Login + 2FA + parcours Coverage map fonctionnent
- Liste 100k companies sans lag (virtualization OK)
- Carte de France interactive 3 modes : visualization + search + action
- Modifier use case LLM depuis admin sans redéploiement (test PUT /api/llm/use-cases/{key})

Commit final : `feat: frontend react skeleton + 5 pages key (login, dashboard, companies, coverage, llm)`.
```

### Prompt 11 — Reste UI Phase 1 + 5 placeholders Phase 2 + 16 jobs nightly

```
Tu es Architecte Logiciel Principal sur Axion CRM Pro. Lis ./spec/13_ui_admin_phase1.md (pages 6-17 + 18-22), ./spec/20_detection_nouveaux_prospects_signaux.md.

Tâche :
1. Implémente les 12 pages Phase 1 restantes (Détail entreprise, Liste contacts + détail, Sources, Rotations, Proxies, Scraper Runs, Audit log, RGPD, Workspaces+users, Settings, Anomalies)
2. Implémente les 5 placeholders Phase 2 (Campaigns, Cold Email, LinkedIn, CRM, Analytics) avec message "Module en développement"
3. Implémente les 16 jobs nightly Scheduler (PollInseeNewCompaniesJob, PollBodaccSignalsJob, PollFranceTravailClevelJob, etc.)
4. Service `BusinessSignalCreator` avec anti-doublon + recalc priority_score
5. Notifications Slack + Telegram pour signaux critical

Critères done :
- 22 pages navigables (17 Phase 1 + 5 Phase 2 placeholders)
- 16 jobs tournent sans overlap (test runs simulés)
- Notif Slack + Telegram reçues sur signal critical simulé

Commit final : `feat: ui admin complete + phase 2 placeholders + 16 nightly jobs`.
```

### Prompt 12 — Monitoring + Conformité + Déploiement + Tests E2E

```
Tu es Architecte Logiciel Principal sur Axion CRM Pro. Lis ./spec/16_monitoring_observabilite.md, ./spec/17_rgpd_aiact_owasp.md, ./spec/18_deploiement_hetzner.md.

Tâche :
1. Expose 40+ métriques Prometheus via `/api/monitoring/metrics/prometheus` (Basic auth)
2. Provisionne 10 dashboards Grafana JSON dans `infra/grafana/dashboards/`
3. Configure Alertmanager + 3 receivers (Slack + Telegram + email)
4. Anomaly detector job nightly (z-score sur 7j glissants)
5. Loki ingestion logs PHP (Monolog JSON) + Node (Pino)
6. Page `/legal/mentions` + `/legal/privacy` + `/legal/ai-act`
7. Seed `data_processing_log` (7 traitements) + `ai_act_register` (10 entrées)
8. Headers sécurité (CSP nonce, HSTS 12mo preload, X-Frame DENY, Permissions-Policy)
9. `docker-compose.prod.yml` + Dockerfiles multi-stage (PHP Octane, Node Playwright, Frontend Vite)
10. Caddyfile prod
11. GitHub Actions : ci.yml (Pest + Vitest + Playwright + security audit) + deploy.yml (build → GHCR → Coolify API)
12. Script `backup-postgres.sh` (pg_dump GPG AES-256 → Backblaze B2) + crontab
13. Tests Playwright E2E 5 parcours clés

Critères done :
- 5 parcours E2E verts
- Penetration test léger (burp + zap) : 0 HIGH/CRITICAL findings
- Backup restore staging OK
- Deploy auto push main → live sous 5 min
- Dashboard "Vue exécutive" Grafana lisible

Commit final : `feat: monitoring + rgpd + deploy hetzner + e2e tests`.
```

---

## C. Récapitulatif final spec

| Stat | Valeur |
|---|---|
| **Total fichiers spec** | 24 |
| **Total lignes Markdown** | ~10 000+ |
| **Tables SQL Phase 1** | ~52 + 1 materialized view |
| **Tables SQL Phase 2 scaffold** | 31 |
| **Sources scraping** | 14 |
| **Routes API REST** | 70+ |
| **Pages UI admin** | 22 (17 Phase 1 + 5 Phase 2) |
| **Queues Horizon + BullMQ** | 16 |
| **Métriques Prometheus** | 40+ |
| **Dashboards Grafana** | 10 |
| **Use cases LLM** | 10 actifs + 5 scaffold |
| **Providers proxies** | 4 |
| **Risques identifiés** | 15 |
| **Coût mensuel cible V1** | 600-900 €/mois |
| **Volume cible mois 1** | 200 000 entreprises enrichies |
| **Effort dev avec Claude Code** | ~31 jours (~6 semaines) |
| **Étapes Code Generation Roadmap** | 12 |
| **Prompts Claude Code prêts** | 12 |
| **Datasets seeders** | 12 |

---

## D. Démarrage de l'implémentation

Pour lancer l'implémentation V1, l'utilisateur (Will) ouvre Claude Code dans le dossier projet `axion-crm-pro/`, lit ce fichier (`spec/23_interfaces_phase2_execution_pack.md`) et copie-colle séquentiellement les **12 prompts** ci-dessus, en validant le résultat à chaque étape.

Estimation totale : **6-7 semaines** de Will avec Claude Code Opus 4.7 1M context, pour passer de la spec à la V1 en production sur `https://crm.axion-ia.com`.

---

## FIN DE LA SPEC AXION CRM PRO V1

Cette spec est désormais **VERROUILLÉE** pour démarrage implémentation. Toute modification ultérieure nécessite un commit `spec(XX): description`. Cf doctrine SSOT pragmatique : le code prime sur la spec en cas de divergence après go-live, sauf décision Will explicite pour durcir le code.

🚀 **Prêt pour la phase d'implémentation.**
