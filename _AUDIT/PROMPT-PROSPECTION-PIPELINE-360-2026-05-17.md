# Sprint Prospection Pipeline 360° — Axion CRM Pro

> Prompt complet pour une nouvelle conversation Claude Code.
> Mode autopilot total. Multi-sources de découverte + enrichissement complet + tags + audiences.
> Préparation campagnes email (architecture, sans envoi — envoi sera codé dans sprint ultérieur).
>
> **Créé le 2026-05-17** — fait suite à la session 10 bugs résolus + feature Campagnes opérationnelle.

---

## TL;DR

Tu es Claude, mode autopilot total. Tu vas transformer Axion CRM Pro en plateforme de prospection B2B **100% automatique zéro abonnement** : pipeline complet de A à Z avec **multi-sources de découverte** (INSEE + France Travail actives, Google Maps + Pages Jaunes préparés pour Phase B), enrichissement complet (Annuaire + BODACC + mentions légales + SMTP probe + BAN + LLM), classification multi-axes auto (région/dept/ville/secteur/taille/intent), tags structurés, audiences/segments réutilisables, triage auto, archive intelligente. **L'envoi d'email sera codé dans un sprint ultérieur — ne pas coder l'envoi ici**, juste préparer l'architecture (audience_members ready). Commits + push origin/main autorisés. ~14-18h de travail estimé, 15-20 commits atomiques via sub-agents parallèles.

## Vision produit complète

```
┌──────────────────────────────────────────────────────────────────────────┐
│  ENTRÉE — Will crée une campagne dans le wizard /campaigns/new           │
│  Choisit 1+ source de découverte parmi :                                  │
│    🟢 INSEE (gratuit, actif)                                              │
│    🟢 France Travail (gratuit, actif)                                     │
│    🔒 Google Maps (Phase B, requires Webshare + 2captcha)                │
│    🔒 Pages Jaunes (Phase B, requires Webshare + 2captcha)               │
└────────────────────────────┬─────────────────────────────────────────────┘
                             ↓
┌──────────────────────────────────────────────────────────────────────────┐
│  DÉCOUVERTE multi-source (par zone × source)                              │
│  Pour chaque (dept × source) → search → liste SIREN/companies             │
│  ├─ INSEE: search par codeCommuneEtablissement → entreprises légales      │
│  ├─ France Travail: search offres d'emploi par dept → entreprises         │
│  │  qui recrutent (signal intent fort)                                    │
│  ├─ Google Maps: search "secteur Ville" → fiches établissement (Phase B) │
│  └─ Pages Jaunes: search annuaire pro (Phase B)                          │
│  → upsert Company avec discovery_source (insee/france_travail/gm/pj)     │
│  → dispatch EnrichCompanyJob pour chaque                                  │
└────────────────────────────┬─────────────────────────────────────────────┘
                             ↓
┌──────────────────────────────────────────────────────────────────────────┐
│  WATERFALL ENRICHISSEMENT (toujours actif, auto, par company)            │
│  ├─ step1 INSEE refresh (denom, naf, effectif)                           │
│  ├─ step2 Annuaire → dirigeants + CA + bilans                            │
│  ├─ step3 BODACC → annonces légales                                      │
│  ├─ step3b NOUVEAU DomainFinder → website                                │
│  ├─ step3c NOUVEAU MentionsLegalesScraper → email_generic + tel          │
│  ├─ step4 dispatch Node scrapes Phase B (skip si MOCK_SCRAPERS)          │
│  ├─ step7 SMTP probe emails dirigeants                                   │
│  ├─ step8 BAN géocodage                                                  │
│  ├─ step9 France Travail signaux RH (offres actives)                     │
│  ├─ step10 LLM Mistral classify                                          │
│  ├─ step10b NOUVEAU AutoClassifier → denormalize geo + size + sector     │
│  ├─ step10c NOUVEAU AutoTagger → tags structurés (auto-rule + llm)       │
│  ├─ step11 NOUVEAU TriageAuto → prospection_status                        │
│  └─ step12 NOUVEAU AutoSegment → ajout aux audiences matchantes          │
└────────────────────────────┬─────────────────────────────────────────────┘
                             ↓
┌──────────────────────────────────────────────────────────────────────────┐
│  AUDIENCES ready pour campagnes email (Sprint futur)                      │
│  Will crée audiences via /audiences/new (builder visuel)                  │
│  Chaque audience = criteria JSONB → members synchronisés auto             │
│  Cron daily refresh + auto-add à chaque nouvelle company enrichie         │
└──────────────────────────────────────────────────────────────────────────┘
```

**Logique business** :
- **Découverte multi-source** = on cross-référence INSEE (toutes entreprises légales) avec France Travail (qui recrute → intent fort) pour trouver les meilleures cibles
- **Enrichissement** = toujours toutes les sources actives, à chaque company on accumule de la valeur
- **Triage** = pas d'email → archive (mais on garde pour re-scrape mensuel)
- **Tags** = classification multi-axes structurée pour filtrage rapide
- **Audiences** = pré-segmentation pour campagnes email futures, refresh auto, plug-and-play

## Contexte projet (à connaître impérativement)

- **Repo** : `C:\Users\willi\Documents\Projets\Axion-CRM-Pro` (branche `main`, public `will383842/axion-crm-pro`)
- **Stack** : Laravel 12 + PHP 8.3 + Postgres 16 + Redis + Horizon + React 19 + Vite 6 + Tailwind v4 + TanStack Router/Query + MapLibre + lucide-react
- **Prod** : Hetzner CPX42 Helsinki, `https://app.axion-crm-pro.com`, Docker Compose (api, horizon, app, postgres, redis)
- **DB prod** : user=`axion`, db=`axion_crm`
- **Owner** : Williams Jullin (`williamsjullin@gmail.com`, workspace UUID `1db106f5-c8a4-47b0-bf86-930f1ccc9f4a`)

## État actuel (session précédente 2026-05-17 — 10 bugs résolus)

✅ Feature Campagnes scraping prod (`/campaigns`, wizard 4 étapes anti-blacklist)
✅ INSEE Sirene `/siret` fonctionne (1000+ entreprises Isère en DB)
✅ Waterfall 10 étapes actives : INSEE, Annuaire-entreprises, BODACC, BAN, France Travail (stub), LLM Mistral
✅ `HttpInseeClient` + `HttpAnnuaireEntreprisesClient` + `BodaccClient` + `BanGeocoder` + `LLMClient` opérationnels
✅ `HttpFranceTravailClient` existe (clés OAuth dans `.env`) mais utilisé seulement comme stub dans step9
✅ Design system 2026 (`@/components/ui` barrel : Button, Card, Tabs, StatusPill, KpiCard, DropdownMenu, Modal, Drawer, SegmentedControl, Tooltip, Avatar, Stat, etc.)
✅ Sidebar groupée (Pilotage / Data / IA / Conformité / Admin / Phase 2) — lucide-react icons
✅ i18n FR partout
✅ Build prod Vite via Caddy 2 alpine
✅ `LaunchZoneScrapingJob` pose campaign_id + update compteurs

⏸ `LaunchCampaignJob` dispatch seulement INSEE × département pour le moment. Pour autres sources → crée ScraperRun pending non consommé.
⏸ `HttpFranceTravailClient` n'a pas de méthode `searchByDept` (pas exploité comme source de découverte)
⏸ MOCK_SMTP=true, MOCK_SCRAPERS=true, MOCK_PROXIES=true, MOCK_CAPTCHA=true dans `.env`
⏸ Pas de DomainFinder ni MentionsLegales scrape
⏸ Pas de tags structurés ni audiences
⏸ `company.website` reste NULL

**Pièges à mémoriser** (chain 10 bugs précédents) :
- Container API utilise sa copie build-time → `docker compose build api horizon` après tout commit backend
- JAMAIS `(int)` sur UUID (PHP cast tronque)
- INSEE Sirene v3.11 : `etablissementSiege` et `etatAdministratifEtablissement` non filtrables dans `q` (filtrer côté PHP)
- Table `scraper_runs` : colonne s'appelle `error` (pas `error_message`), `updated_at` présent depuis migration `bec23b7`
- `audit_logs.user_id` et `workspace_id` typés UUID → validation `asUuidOrNull()` obligatoire pour any insert
- DB user serveur = `axion`

## MISSION — Pipeline complet en 4 sprints techniques

### Sprint A — Pipeline data (find domain + mentions légales + SMTP) ~5h
### Sprint B — Multi-source découverte (France Travail actif + Google Maps/PJ préparé) ~3h
### Sprint A.5 — Tags + Classification structurée ~3h
### Sprint A.7 — Audiences + Segments (préparation campagnes email) ~4h

Tu peux faire les 4 sprints **en parallèle via sub-agents** quand les commits sont indépendants. Tu coordonnes les commits séquentiels obligatoires.

---

## SPRINT A — Détail technique (Pipeline data + emails)

### Commit 1 — Migration Companies (prospection fields)
`backend/database/migrations/2026_05_18_000006_add_prospection_fields_to_companies.php` :

```sql
ALTER TABLE companies
  ADD COLUMN IF NOT EXISTS website         VARCHAR(500),
  ADD COLUMN IF NOT EXISTS email_generic   VARCHAR(255),
  ADD COLUMN IF NOT EXISTS phone           VARCHAR(50),
  ADD COLUMN IF NOT EXISTS prospection_status TEXT NOT NULL DEFAULT 'pending'
    CHECK (prospection_status IN ('pending','ready_for_outreach','partial_email','archived_no_email')),
  ADD COLUMN IF NOT EXISTS region_code     VARCHAR(3),
  ADD COLUMN IF NOT EXISTS department_code VARCHAR(3),
  ADD COLUMN IF NOT EXISTS commune_code    VARCHAR(5),
  ADD COLUMN IF NOT EXISTS city_name       VARCHAR(120),
  ADD COLUMN IF NOT EXISTS postcode        VARCHAR(10),
  ADD COLUMN IF NOT EXISTS sector_main     VARCHAR(64);     -- ex: 'it_saas','btp','sante'

CREATE INDEX IF NOT EXISTS idx_companies_prospection_status ON companies (workspace_id, prospection_status);
CREATE INDEX IF NOT EXISTS idx_companies_dept ON companies (workspace_id, department_code);
CREATE INDEX IF NOT EXISTS idx_companies_region ON companies (workspace_id, region_code);
CREATE INDEX IF NOT EXISTS idx_companies_sector ON companies (workspace_id, sector_main);
```

**Avant migration** : Read `Models/Company.php` + grep migrations existantes pour vérifier conflits (notamment `website`). Use `IF NOT EXISTS` partout.

### Commit 2 — DomainFinderService
`backend/app/Services/Domain/DomainFinderService.php` — 3 stratégies cascade :
1. Lire `company.signals.legal.siteweb` si déjà rempli par Annuaire
2. DuckDuckGo HTML : `https://html.duckduckgo.com/html?q={denomination}+{ville}` → parse premiers `<a class="result__url">` filtrant les réseaux sociaux (linkedin/facebook/twitter/youtube) et annuaires (societe.com/verif.com)
3. Pages Jaunes HTML brut : `https://www.pagesjaunes.fr/recherche/{ville}/{denomination}` → parse `<a class="company-website" href="...">`

Timeout 10s par source. Fail silently et passe à la suivante. Return URL canonique (`https://domain.fr/`) ou null.

Tests Pest avec 3 fixtures HTML mockées.

### Commit 3 — MentionsLegalesScraperService
`backend/app/Services/Legal/MentionsLegalesScraperService.php`. Paths candidats :
```php
['/mentions-legales', '/mentions-legales.html', '/legal', '/imprint',
 '/a-propos/mentions-legales', '/cgv', '/cgu', '/conditions-generales']
```
Regex :
- email : `/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/`
- tel : `/\b0[1-9](?:[\s.\-]?\d{2}){4}\b/`
- siret : `/\b\d{14}\b/`

Filtre blacklist emails techniques : `no-reply@`, `postmaster@`, `abuse@`, `webmaster@`, `noreply@`. Si page contient section "Équipe"/"Direction" + noms matchant les dirigeants Annuaire → emails ajoutés à contacts via insertOrIgnore.

Tests : 5 fixtures HTML de sites français typiques.

### Commit 4 — Intégration Waterfall (steps 3b + 3c + 11)
Modifier `WaterfallOrchestrator.php` :
- Inject `DomainFinderService` + `MentionsLegalesScraperService` + `AutoClassifierService` (commit 11) + `AutoTaggerService` (commit 12) + `AudienceBuilderService` (commit 16)
- Order final :
  ```
  step1_insee → step2_annuaire → step3_bodacc 
  → step3b_find_domain → step3c_mentions_legales 
  → step4_dispatch_node_scrapes (mock-aware)
  → step7_email_finder → step8_geocode → step9_france_travail 
  → step10_classify (LLM)
  → step10b_auto_classify (denormalize geo+size+sector)
  → step10c_auto_tag (tags structurés)
  → step11_triage_auto (prospection_status)
  → step12_auto_segment (audiences match)
  ```

### Commit 5 — Activer SMTP probe propre
`EmailFinderService` :
- Ajouter rate limit Redis (50 probes/h/domain), key `smtp_probe_rate:{domain}` avec TTL 3600s
- Catch `\Throwable` autour de chaque probe → mark `invalid` plutôt que crash
- Skip blacklist gros providers (gmail.com, outlook.fr, outlook.com, yahoo.fr, yahoo.com, free.fr, orange.fr, wanadoo.fr, hotmail.fr, hotmail.com — toujours catchall sur ces domaines)
- Helper bash dans `_AUDIT/PROD-ACTIVATION-RUNBOOK.md` pour `MOCK_SMTP=false`

---

## SPRINT B — Multi-source découverte (NOUVEAU)

### Commit 6 — Refactor LaunchZoneScrapingJob pour multi-source
`backend/app/Jobs/LaunchZoneScrapingJob.php` :

```php
public function __construct(
    public readonly string $workspaceId,
    public readonly string $department,
    public readonly ?string $naf,
    public readonly ?string $sizeCategory,
    public readonly int $limit,
    public readonly ?int $campaignId = null,
    public readonly string $source = 'insee',  // NEW
) {}

public function handle(): void {
    // Crée ScraperRun avec source dynamique
    $run = ScraperRun::create([..., 'source' => $this->source, ...]);
    
    try {
        $results = match($this->source) {
            'insee' => app(InseeClient::class)->searchByCriteria([
                'department' => $this->department, 'limit' => $this->limit,
            ]),
            'france_travail' => app(FranceTravailDiscoveryClient::class)->searchEntreprisesByDept(
                $this->department, $this->limit,
            ),
            'google_maps', 'pages_jaunes' => 
                // Dispatch vers Node BullMQ Phase B
                // Si MOCK_SCRAPERS=true → return [] (skip silent)
                env('MOCK_SCRAPERS', true) ? [] : $this->dispatchNodeWorker($this->source),
            default => throw new \RuntimeException("Unknown source: {$this->source}"),
        };
        
        // Upsert + dispatch EnrichCompanyJob comme avant
        ...
    } catch (\Throwable $e) {
        $run->update(['status' => 'failed', 'error' => $e->getMessage()]);
        throw $e;
    }
}
```

### Commit 7 — Service FranceTravailDiscoveryClient
`backend/app/Services/FranceTravail/FranceTravailDiscoveryClient.php` :

API France Travail : `GET https://api.francetravail.io/partenaire/offresdemploi/v2/offres/search?departement=38&range=0-149`

Auth OAuth2 client_credentials (Will a déjà `FRANCE_TRAVAIL_CLIENT_ID` + `FRANCE_TRAVAIL_CLIENT_SECRET` dans `.env`).

Méthode `searchEntreprisesByDept(string $dept, int $limit): array` :
- Récupère N offres d'emploi actives dans le dept
- Dédoublonne par `entreprise.siret` (peut-être présent dans la réponse)
- Pour chaque entreprise unique → retourne `InseeCompanyData` (réutilise le DTO existant) avec :
  - `siren` = depuis siret (10 premiers chars)
  - `denomination` = entreprise.nom
  - `naf` = entreprise.activitePrincipale
  - `discovery_source` = 'france_travail'
- Cache OAuth token 1h dans Redis

Tests Pest avec fixture JSON France Travail.

### Commit 8 — LaunchCampaignJob dispatch multi-source
Modifier `LaunchCampaignJob.php` :

```php
foreach ($zones as $zone) {
    if ($zone['type'] !== 'department') continue;
    $dept = $zone['code'];

    foreach ($sources as $source) {
        // Sources de découverte = INSEE, France Travail, Google Maps, Pages Jaunes
        if (in_array($source, ['insee', 'france_travail'], true)) {
            // Sources backend Laravel — dispatch direct
            LaunchZoneScrapingJob::dispatch(
                $campaign->workspace_id, $dept, null, null, 
                $perCampaignLimit, $campaign->id, $source
            )->delay(now()->addSeconds($offsetSeconds));
            $runsTotal++;
        } elseif (in_array($source, ['google_maps', 'pages_jaunes'], true)) {
            // Sources Node BullMQ — dispatch via DispatchScrapeJob
            if (env('MOCK_SCRAPERS', true)) {
                // Mode mock : crée juste un run skipped
                ScraperRun::create([
                    'workspace_id' => $campaign->workspace_id,
                    'campaign_id' => $campaign->id,
                    'source' => $source,
                    'status' => 'skipped',
                    'started_at' => now(),
                    'finished_at' => now(),
                    'error' => 'MOCK_SCRAPERS=true: Phase B non activée',
                ]);
            } else {
                DispatchScrapeJob::dispatch(/* ... */)->delay(now()->addSeconds($offsetSeconds));
            }
            $runsTotal++;
        } else {
            // Sources d'enrichissement (annuaire, bodacc, ban) — pas de découverte
            // Ces sources ne sont pas censées être dans le wizard "Sources"
            // Skip silently si quelqu'un les coche par erreur
            Log::info("Source {$source} is enrichment-only, skipped in campaign dispatch");
        }
        $offsetSeconds += $delayPerRequestSec;
    }
}
```

### Commit 9 — UI Wizard sources adapté
Frontend `CampaignWizardPage.tsx` étape 3 (Sources) :
- Refactor `ALL_SOURCES` constant pour ne contenir QUE des sources de découverte :
  ```ts
  export const ALL_SOURCES = [
    { id: 'insee', label: 'INSEE Sirene', icon: 'Database',
      description: 'Base officielle entreprises FR — toutes les entreprises légales',
      status: 'api_key', activable: true },
    { id: 'france_travail', label: 'France Travail', icon: 'Briefcase',
      description: 'Entreprises qui recrutent dans la zone (signal intent fort)',
      status: 'api_key', activable: true },
    { id: 'google_maps', label: 'Google Maps', icon: 'MapPin',
      description: 'Fiches établissement géo-localisées (Phase B)',
      status: 'proxies_required', activable: false }, // disabled si MOCK_SCRAPERS=true
    { id: 'pages_jaunes', label: 'Pages Jaunes', icon: 'BookOpen',
      description: 'Annuaire pro FR (Phase B)',
      status: 'proxies_required', activable: false },
  ];
  ```
- Retirer `annuaire-entreprises`, `bodacc`, `ban` du wizard Sources (ce sont des sources d'enrichissement, toujours actives)
- Ajouter en bas de l'étape 3 une note d'info :
  > "💡 L'enrichissement (Annuaire Entreprises, BODACC, BAN, mentions légales, LLM Mistral) s'applique automatiquement à chaque entreprise découverte. Pas besoin de les activer ici."
- Sources non activables (Google Maps / PJ) : afficher card grisée avec `Lock` icon + tooltip "Phase B — Configuration Webshare requise (Settings)"

---

## SPRINT A.5 — Détail technique (Tags + Classification)

### Commit 10 — Migration Tags
`2026_05_18_000007_create_tags_system.php` :

```sql
CREATE TABLE IF NOT EXISTS tags (
  id           BIGSERIAL PRIMARY KEY,
  workspace_id UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  slug         VARCHAR(64) NOT NULL,
  label        VARCHAR(120) NOT NULL,
  color        VARCHAR(20) DEFAULT 'slate',
  category     VARCHAR(32) NOT NULL CHECK (category IN ('geo','sector','size','intent','custom')),
  kind         VARCHAR(16) NOT NULL DEFAULT 'auto' CHECK (kind IN ('auto','manual','llm')),
  rule         JSONB,
  description  TEXT,
  created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (workspace_id, slug)
);

CREATE TABLE IF NOT EXISTS company_tag (
  company_id   BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
  tag_id       BIGINT NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
  workspace_id UUID NOT NULL,
  assigned_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  assigned_by  VARCHAR(32) NOT NULL CHECK (assigned_by IN ('auto-rule','llm','user')),
  PRIMARY KEY (company_id, tag_id)
);

CREATE INDEX IF NOT EXISTS idx_company_tag_workspace ON company_tag (workspace_id);
CREATE INDEX IF NOT EXISTS idx_tags_workspace_category ON tags (workspace_id, category);

ALTER TABLE tags ENABLE ROW LEVEL SECURITY;
ALTER TABLE company_tag ENABLE ROW LEVEL SECURITY;
CREATE POLICY tags_ws_iso ON tags FOR ALL USING (workspace_id::TEXT = COALESCE(NULLIF(current_setting('app.current_workspace_id', true), ''), workspace_id::TEXT));
CREATE POLICY company_tag_ws_iso ON company_tag FOR ALL USING (workspace_id::TEXT = COALESCE(NULLIF(current_setting('app.current_workspace_id', true), ''), workspace_id::TEXT));
```

### Commit 11 — AutoClassifierService + step10b
`backend/app/Services/Classification/AutoClassifierService.php` :

Pour chaque company enrichie, denormalize en colonnes :
- **Géo** depuis BAN/INSEE → `region_code` + `department_code` + `commune_code` + `city_name` + `postcode`
- **Taille** depuis `effectif_range` INSEE → `size_category` enum :
  - "00", "01", "02" → micro (0-9 salariés)
  - "03", "11", "12" → tpe (10-49)
  - "21", "22", "31", "32" → pme (50-249)
  - "41", "42", "51" → eti (250-4999)
  - "52", "53" → grande (5000+)
- **Secteur** depuis NAF code → `sector_main` enum :
  - "62.*", "63.1*" → it_saas
  - "41.*", "42.*", "43.*" → btp
  - "86.*", "87.*", "88.*" → sante
  - "10.*", "11.*", "12.*" → agro_alimentaire
  - "47.*", "46.*" → commerce
  - "55.*", "56.*" → hotellerie_restauration
  - "64.*", "65.*", "66.*" → finance_assurance
  - etc. (mapping en dur ~20 secteurs grands)

Tests : 10 cas couvrant les principales tranches NAF + effectif.

### Commit 12 — AutoTaggerService + step10c
`backend/app/Services/Tags/AutoTaggerService.php` :

Pour chaque company, applique les tags auto :
- Tag dept (`dept-75`, `dept-92`, etc.) — créé si absent (kind=auto, category=geo)
- Tag region (`region-idf`, `region-paca`, etc.) — créé si absent (kind=auto, category=geo)
- Tag size (`size-micro`, `size-tpe`, `size-pme`, etc.) — créé si absent (kind=auto, category=size)
- Tag sector (`sector-it-saas`, `sector-btp`, etc.) — créé si absent (kind=auto, category=sector)
- Tags depuis `signals.llm_classification.tags` (kind=llm, category=intent)
- Sync : retire tags auto-rule qui ne matchent plus, ajoute les nouveaux. Ne touche pas aux tags `kind=manual`.

### Commit 13 — UI Tags Manager
- Route `/tags` → `TagsManagerPage` (sidebar entry, icon `Hash` lucide, section Data)
- Liste groupée par catégorie (geo/sector/size/intent/custom)
- Chaque tag : pill couleur + label + count companies + bouton "Voir companies" → `/companies?filter[tag]=slug`
- Bouton "+ Nouveau tag" → Modal (slug + label + color picker)
- Sur `Company Detail` : card "Tags" avec sections (auto / manuel) + bouton "+ ajouter"

### Commit 14 — Filtres Companies List (tags + geo + size)
Modifier `CompaniesListPage.tsx` :
- Toolbar : ajouter Filters DropdownMenu avec multi-select dept/région/tags/taille/secteur + range quality_score
- Tabs prospection_status (Tous / Prospectables / Partiels / Archivés)
- URL params sync → bookmarkable
- Sur la table : afficher StatusPill prospection_status + tags chips (top 3, truncate)
- Compteur filtres actifs

---

## SPRINT A.7 — Détail technique (Audiences)

### Commit 15 — Migration Audiences
`2026_05_18_000008_create_email_audiences.php` :

```sql
CREATE TABLE IF NOT EXISTS email_audiences (
  id            BIGSERIAL PRIMARY KEY,
  workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  name          VARCHAR(160) NOT NULL,
  description   TEXT,
  criteria      JSONB NOT NULL,
  is_active     BOOLEAN NOT NULL DEFAULT true,
  auto_refresh  BOOLEAN NOT NULL DEFAULT true,
  member_count  INTEGER NOT NULL DEFAULT 0,
  refreshed_at  TIMESTAMPTZ,
  created_by    UUID REFERENCES users(id),
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  deleted_at    TIMESTAMPTZ
);

CREATE TABLE IF NOT EXISTS audience_members (
  id            BIGSERIAL PRIMARY KEY,
  audience_id   BIGINT NOT NULL REFERENCES email_audiences(id) ON DELETE CASCADE,
  company_id    BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
  contact_id    BIGINT REFERENCES contacts(id) ON DELETE CASCADE,
  workspace_id  UUID NOT NULL,
  added_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (audience_id, contact_id)
);

CREATE INDEX IF NOT EXISTS idx_audience_members_ws ON audience_members (workspace_id, audience_id);
CREATE INDEX IF NOT EXISTS idx_audience_members_company ON audience_members (company_id);

ALTER TABLE email_audiences ENABLE ROW LEVEL SECURITY;
ALTER TABLE audience_members ENABLE ROW LEVEL SECURITY;
CREATE POLICY audiences_ws ON email_audiences FOR ALL USING (workspace_id::TEXT = COALESCE(NULLIF(current_setting('app.current_workspace_id', true), ''), workspace_id::TEXT));
CREATE POLICY audience_members_ws ON audience_members FOR ALL USING (workspace_id::TEXT = COALESCE(NULLIF(current_setting('app.current_workspace_id', true), ''), workspace_id::TEXT));
```

**Format criteria** :
```json
{
  "all": [
    { "field": "prospection_status", "op": "in", "value": ["ready_for_outreach"] },
    { "field": "department_code", "op": "in", "value": ["75","92","93","94","95","91","78","77"] },
    { "field": "size_category", "op": "in", "value": ["pme","eti"] },
    { "field": "tags", "op": "contains_any", "value": ["sector-it-saas"] }
  ],
  "any": [],
  "not": []
}
```

### Commit 16 — AudienceBuilderService
`backend/app/Services/Audiences/AudienceBuilderService.php` :
- `preview(criteria)` : retourne count sans persister
- `refresh(audience)` : recalcule tous les members, idempotent
- `evaluateForCompany(company)` : pour 1 company, retourne audiences matchées
- Operators : `eq`, `neq`, `in`, `not_in`, `gt`, `lt`, `gte`, `lte`, `contains_any` (tags), `is_null`, `is_not_null`
- Chunk 500 pour bulk refresh

### Commit 17 — step12_auto_segment + Controllers
- Méthode `step12_auto_segment` dans WaterfallOrchestrator
- `Api/AudiencesController` : CRUD + `preview` + `refresh` + `members`
- Routes `/api/v1/audiences` workspace-scoped throttle standard
- FormRequest validation criteria avec whitelist fields + ops

### Commit 18 — UI Audiences (pages + builder)
- Sidebar : nouvelle entrée "Audiences" sous section "Communication" (créer la section), icon `Users2` lucide
- Aussi disabled entries : "Templates email" + "Envois email" avec `Lock` icon + tooltip "Bientôt"
- Route `/audiences` → AudiencesListPage : 4 audiences seedées, cards avec member_count + status pulsé + actions DropdownMenu
- Route `/audiences/new` → AudienceBuilderPage avec builder visuel :
  - Section "Géographie" : multi-select dept + région + ville
  - Section "Taille / Secteur" : multi-select size_category + sector + tags
  - Section "Qualité" : status (default ready_for_outreach actif) + quality_score range + toggle "A des contacts email"
  - Section "Tags personnalisés" : multi-select tags custom
  - **Live preview** côté droit (sticky) : "X entreprises, Y contacts éligibles" via `/api/v1/audiences/preview` debounced 500ms
- Route `/audiences/{id}` → AudienceDetailPage :
  - KPI cards : Members / Last refresh / Qualité moyenne / Couverture geo
  - Tab "Membres" : table contacts (email, company, dept, tags) + export CSV
  - Tab "Critères" : readonly + bouton "Modifier"
  - Tab "Préparation campagne" : placeholder "Bientôt — connectez à une campagne email" + lien Sprint roadmap
  - Bouton Refresh manual / Pause auto-refresh / Edit / Delete

### Commit 19 — Seeder 4 audiences exemple
`backend/database/seeders/DemoAudiencesSeeder.php` :
1. **PME IT Île-de-France** : `prospection_status=ready_for_outreach AND size_category IN (pme,eti) AND region_code='11' AND tags CONTAINS sector-it-saas`
2. **TPE Sud-Ouest tous secteurs** : `prospection_status IN (ready_for_outreach,partial_email) AND size_category=tpe AND department_code IN (33,40,47,64,24)`
3. **Grandes entreprises France entière** : `size_category IN (eti,grande) AND prospection_status=ready_for_outreach`
4. **À tester (qualité moyenne)** : `quality_score BETWEEN 40 AND 70 AND prospection_status != archived_no_email`

Run automatique du seeder dans la migration ou via `php artisan db:seed --class=DemoAudiencesSeeder`.

### Commit 20 — Cron + Doc finale
- Schedule daily `audiences:full-refresh` à 04:00 UTC dans `routes/console.php`
- Schedule monthly `companies:rescrape-archives --limit=200` le 1er à 02:00 UTC
- Doc complète `_AUDIT/PROSPECTION-PIPELINE.md` :
  - Flow complet avec diagramme ASCII
  - Comment Will crée sa 1ère campagne multi-source
  - Comment Will crée une audience custom
  - KPIs à surveiller
  - Procédures de re-scrape

---

## Conventions du repo (à respecter)

- **Commits Conventional** : `feat:`, `fix:`, `docs:`, `test:`, `chore:`
- **Jamais** `--no-verify`, `--no-gpg-sign`, force-push main
- **Tests** : Pest backend + Vitest frontend. Ne pas casser les ~206 Pest existants + 56 Vitest. Écrire de nouveaux tests pour services critiques.
- **i18n** : tout texte UI nouveau en FR direct
- **Design system** : import exclusivement depuis `@/components/ui`
- **Lucide-react** : icons partout (pas d'emojis)
- **Backend** : FormRequest, Resource, Service, Job. Pas de logique dans Controllers
- **Migrations idempotentes** : `IF NOT EXISTS`, `IF EXISTS`, `ON CONFLICT DO NOTHING`
- **RLS** : policy workspace_isolation pour toute nouvelle table avec `workspace_id`

## Anti-régression critique

- ⚠️ Routes inchangées : `/companies`, `/campaigns`, `/contacts`, etc.
- ⚠️ queryKey React Query inchangés
- ⚠️ Toutes les sources existantes restent actives (INSEE + Annuaire + BODACC + BAN + France Travail + LLM Mistral)
- ⚠️ Les 1000 entreprises Isère déjà en DB ne doivent PAS être supprimées
- ⚠️ Les step1-step10 existants du Waterfall continuent de tourner — on AJOUTE
- ⚠️ Préserver data-tour Joyride
- ⚠️ `Models/Company.php` : ajouter dans `$fillable` les nouveaux champs (website, email_generic, phone, prospection_status, region_code, department_code, commune_code, city_name, postcode, sector_main)
- ⚠️ `LaunchZoneScrapingJob` constructor backward-compat : `source='insee'` default (anciens dispatch `/coverage/launch` continuent de marcher)
- ⚠️ Tests Pest existants `CampaignsTest`, `ScraperRunsCancelRetryTest`, `ScraperRateLimitTest` doivent rester verts
- ⚠️ Step10 LLM continue d'écrire `signals.llm_classification` — step10b/c LIT ce JSON pour matérialiser tags

## Commandes serveur de déploiement

```bash
ssh root@<ip>
cd /opt/axion-crm-pro && \
  git fetch origin main && git reset --hard origin/main && \
  docker compose build api horizon && \
  docker compose up -d api horizon && \
  sleep 10

# Migrations (toutes en une fois)
docker compose exec -T api php artisan migrate --force

# Seeder audiences démo
docker compose exec -T api php artisan db:seed --class=DemoAudiencesSeeder --force

# Activer SMTP probe
sed -i 's|^MOCK_SMTP=true|MOCK_SMTP=false|' /opt/axion-crm-pro/.env
docker compose restart api horizon

# Rebuild frontend (nouvelles pages /tags, /audiences, /audiences/new, /audiences/:id)
docker compose build app && docker compose up -d app

# Vérifier cron
docker compose exec -T api php artisan schedule:list | grep -E "audiences|companies"

# SMOKE TEST FINAL : créer campagne multi-source INSEE + France Travail dept 75
docker compose exec -T api php artisan tinker --execute='
$campaign = \App\Models\ScrapingCampaign::create([
    "workspace_id" => "1db106f5-c8a4-47b0-bf86-930f1ccc9f4a",
    "created_by"   => "4c77fc58-0fa5-4b64-8d11-1ffbff557f13",
    "name"         => "SMOKE Paris multi-source",
    "status"       => "running",
    "sources"      => ["insee", "france_travail"],
    "zones"        => [["type" => "department", "code" => "75"]],
    "max_companies" => 50,
    "max_duration_minutes" => 30,
    "max_requests_per_minute" => 20,
    "started_at"   => now(),
]);
\App\Jobs\LaunchCampaignJob::dispatch($campaign->id);
echo "Smoke campaign id=" . $campaign->id . " dispatched\n";
'

sleep 120

docker compose exec -T postgres psql -U axion -d axion_crm -c "
SELECT id, name, status, runs_total, runs_completed, companies_created FROM scraping_campaigns ORDER BY id DESC LIMIT 1;
SELECT source, status, COUNT(*) FROM scraper_runs WHERE campaign_id = (SELECT MAX(id) FROM scraping_campaigns) GROUP BY source, status;
SELECT prospection_status, COUNT(*) FROM companies GROUP BY prospection_status;
SELECT category, COUNT(*) FROM tags WHERE workspace_id='1db106f5-c8a4-47b0-bf86-930f1ccc9f4a' GROUP BY category;
SELECT id, name, member_count FROM email_audiences WHERE workspace_id='1db106f5-c8a4-47b0-bf86-930f1ccc9f4a';
"
```

## Risques connus + solutions

1. **France Travail API change** → cache OAuth token 1h, retry sur 401 (token expiré)
2. **DuckDuckGo markup change** → parser permissif (find any `<a href="http*">` filtré par regex)
3. **Mentions légales JS dynamiques** → skip si <500 octets text parsé
4. **SMTP blacklist IP Hetzner** → rate limit 50/h/domain + skip blacklist providers
5. **Charge waterfall 1000 companies × 12 étapes** → INSEE 30 req/min limite naturellement
6. **Tests Pest sans vendor/** → écrire quand même, CI valide
7. **Migration colonnes potentiellement existantes** : Read `Models/Company.php` AVANT, use `IF NOT EXISTS`
8. **LLM Mistral coût** : ~$0.10 pour 1000 companies, acceptable. Ne PAS rebooter step10 dans step10b
9. **Audience refresh perf 100k+ companies** : chunk(500) + UNIQUE constraint + `ON CONFLICT DO NOTHING`
10. **Wizard sources backward-compat** : si des campagnes existantes ont `sources` includant `annuaire-entreprises`/`bodacc`/`ban`, le `LaunchCampaignJob` les skip silencieusement avec log (pas de crash)

## Critères de succès (Will valide à la fin)

1. ✅ `npx tsc --noEmit` frontend → 0 erreur
2. ✅ `php artisan migrate --force` → toutes migrations vertes
3. ✅ Smoke test campagne multi-source INSEE + France Travail → 2 runs success, companies créées des 2 sources
4. ✅ UI `/companies` : 4 tabs + filters dept/région/tags/taille/secteur + URL bookmarkable
5. ✅ UI `/tags` : liste organisée par catégorie + count
6. ✅ UI `/audiences` : 4 audiences seedées avec member_count
7. ✅ Wizard `/audiences/new` : live preview count fonctionnel
8. ✅ Wizard `/campaigns/new` étape 3 : 4 sources visibles (INSEE/FT activables, GM/PJ disabled+lock)
9. ✅ Sidebar : "Tags" + "Audiences" + entries disabled "Templates email" / "Envois email"
10. ✅ Cron jobs configurés (audiences:full-refresh daily + companies:rescrape-archives monthly)
11. ✅ Aucune régression : 1000 entreprises Isère + campagnes existantes restent OK
12. ✅ 15-20 commits propres pushés origin/main avec messages Conventional

## Workflow attendu (autopilot)

1. **Lecture initiale** : `git log --oneline -25`, `\d companies`, `WaterfallOrchestrator.php`, `EmailFinderService.php`, `HttpFranceTravailClient.php`, `LaunchCampaignJob.php`, `LaunchZoneScrapingJob.php`, `routeTree.tsx`, `CampaignWizardPage.tsx`
2. **Migrations DB en premier** (commits 1, 10, 15) — vérifier idempotence
3. **Services backend en parallèle** via sub-agents :
   - Sub-agent A : DomainFinderService + tests
   - Sub-agent B : MentionsLegalesScraperService + tests
   - Sub-agent C : FranceTravailDiscoveryClient + tests
   - Sub-agent D : AutoClassifierService + AutoTaggerService + tests
   - Sub-agent E : AudienceBuilderService + tests
4. **Refactor LaunchZoneScrapingJob + LaunchCampaignJob** séquentiel (commits 6, 8)
5. **Intégration Waterfall** séquentielle (commit 4)
6. **Activation SMTP** (commit 5)
7. **Controllers + Routes** (commit 17)
8. **Frontend en parallèle** via sub-agents :
   - Sub-agent F : Tags Manager + Companies filters (commits 13, 14)
   - Sub-agent G : Audiences UI complete (commit 18)
   - Sub-agent H : Wizard sources adapté (commit 9)
9. **Seeder + Cron + Doc** (commits 19, 20)
10. **Smoke test final** + **rapport** ≤ 600 mots

## Si tu as un doute

- Préfère **un commit propre fonctionnel** plutôt qu'un mega-commit half-features
- **STOP and ASK** uniquement si :
  - Migration risque de corrompre data existante
  - Un test critique régresse de manière inexpliquée
  - Décision UX/business ambiguë (ex: heuristique sur la priorité dans `evaluateForCompany`)
- Sinon : prends la décision la plus simple + documente dans commit message

## Pas d'over-engineering

- Pas de retry compliqué : fail + log + skip
- Pas de cache au-delà de Redis (SMTP rate limit + OAuth FT token)
- Pas de queue séparée : reuse `default` Horizon
- Pas de WebSocket : UI refresh via React Query `refetchInterval`
- Pas d'optim N+1 prématurée
- Code lisible : junior dev doit comprendre en 10 min par fichier

## RAPPEL CRITIQUE — Out of scope

❌ **NE PAS coder** l'envoi d'email (templates email réels, SMTP send, tracking opens/clicks, désinscription RGPD, Mailcow, Mailjet/Postmark intégration)
✅ Juste préparer l'**architecture** (audience_members ready) pour plug-and-play futur
✅ Ajouter sidebar entries "Templates email" + "Envois email" mais **disabled** + `Lock` icon + tooltip "Bientôt disponible"

L'objectif final de ce sprint : Will lance une campagne multi-source (INSEE + France Travail) → 24h plus tard, 100+ entreprises Paris sont enrichies, classifiées géo/secteur/taille/intent, taguées, archivées si pas d'email, sinon **déjà dans leurs audiences cible** prêtes pour l'envoi quand on codera le module email.

**GO. Lance le pipeline complet 360°. Rapport final dans la même conversation.**
