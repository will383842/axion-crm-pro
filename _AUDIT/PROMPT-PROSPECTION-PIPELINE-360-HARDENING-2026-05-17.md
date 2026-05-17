# Sprint Prospection Pipeline 360° — HARDENING (Production-Ready)

> Prompt complémentaire au [PROMPT-PROSPECTION-PIPELINE-360-2026-05-17.md](./PROMPT-PROSPECTION-PIPELINE-360-2026-05-17.md).
> Mode autopilot total, bout en bout jusqu'à implémentation complète + push origin/main + smoke test prod.
> Patche 6 angles morts critiques identifiés post-audit du sprint initial.
>
> **Créé le 2026-05-17** — à exécuter **APRÈS** que le sprint pipeline 360° initial soit mergé + déployé prod.

---

## TL;DR

Tu es Claude, mode autopilot total. Tu vas durcir Axion CRM Pro pour le passage à l'échelle : **anti-bot réaliste** (Brave Search API + Pages Jaunes via Webshare quand actif), **SMTP via Hunter.io** (zéro probe direct depuis IP Hetzner), **filtre INSEE actif partout**, **observabilité complète** (Sentry sur tous nouveaux services + audit_logs systématiques + Playwright E2E sur 3 wizards critiques), **scaling testé** (audience refresh chunk parallèle + load test Artillery + estimation coûts honnête), **commande Artisan `companies:rescrape-archives` réellement codée** (le sprint initial l'avait scheduled mais pas implémentée). ~10-14h, 12-16 commits atomiques, sub-agents parallèles. Commits + push origin/main autorisés.

## Pourquoi ce sprint

Le sprint initial pipeline 360° livre un MVP fonctionnel **mais 6 risques le rendent fragile en prod** :

1. 🤖 `DomainFinderService` scrape DuckDuckGo HTML brut → blacklist en quelques heures
2. 📧 `EmailFinderService` probe SMTP depuis IP Hetzner → bannissement Spamhaus quasi immédiat
3. 🔍 Filtre `etatAdministratifEtablissement='A'` mentionné mais pas appliqué dans `FranceTravailDiscoveryClient` → entreprises radiées enrichies pour rien
4. 📊 Aucun Sentry sur les 5 nouveaux services → bugs silencieux invisibles
5. ⚖️ Cible projet 1M entreprises/mois mais audience refresh testé à 50 → effondrement perf garanti
6. 🔄 `companies:rescrape-archives` schedulé mais commande Artisan jamais codée → cron fail silencieux

## Contexte projet (à connaître impérativement)

- **Repo** : `C:\Users\willi\Documents\Projets\Axion-CRM-Pro` (branche `main`, public `will383842/axion-crm-pro`)
- **Stack** : Laravel 12 + PHP 8.3 + Postgres 16 + Redis + Horizon + React 19 + Vite 6 + Tailwind v4 + TanStack Router/Query + MapLibre + lucide-react
- **Prod** : Hetzner CPX42 Helsinki, `https://app.axion-crm-pro.com`
- **DB prod** : user=`axion`, db=`axion_crm`
- **Owner** : Williams Jullin (`williamsjullin@gmail.com`, workspace UUID `1db106f5-c8a4-47b0-bf86-930f1ccc9f4a`)
- **Sentry DSN** : déjà configuré côté backend (`SENTRY_LARAVEL_DSN` dans `.env`) + frontend (`VITE_SENTRY_DSN`)

## Prérequis (vérifier avant de lancer)

```bash
# Doit afficher TOUS ces fichiers (= sprint initial mergé)
ls backend/app/Services/Domain/DomainFinderService.php
ls backend/app/Services/Legal/MentionsLegalesScraperService.php
ls backend/app/Services/Classification/AutoClassifierService.php
ls backend/app/Services/Tags/AutoTaggerService.php
ls backend/app/Services/Audiences/AudienceBuilderService.php
ls backend/app/Services/FranceTravail/FranceTravailDiscoveryClient.php

# Doit retourner 1+ ligne
docker compose exec -T postgres psql -U axion -d axion_crm -c "
SELECT COUNT(*) FROM information_schema.tables WHERE table_name IN ('tags','company_tag','email_audiences','audience_members');"
```

Si l'un de ces checks échoue → **STOP, signale à Will, attend confirmation**.

## MISSION — 6 sprints hardening

### Sprint H1 — Anti-bot réaliste (~3h)
### Sprint H2 — SMTP via Hunter.io (~2h)
### Sprint H3 — Filtre INSEE actif partout (~1h)
### Sprint H4 — Observabilité Sentry + audit_logs + Playwright (~4h)
### Sprint H5 — Scaling 1M companies + load test (~2h)
### Sprint H6 — Commande Artisan `rescrape-archives` codée (~1h)

Sub-agents parallèles autorisés. Commits séquentiels obligatoires uniquement sur fichiers partagés (`WaterfallOrchestrator.php`, `.env.example`, `routes/console.php`).

---

## SPRINT H1 — Anti-bot réaliste

### Commit 1 — Refactor DomainFinderService (Brave Search API)

`backend/app/Services/Domain/DomainFinderService.php` :

**Retirer** : scrape DuckDuckGo HTML brut (Stratégie 2 du sprint initial) — supprimer la méthode `searchDuckDuckGo()`.

**Remplacer par** : Brave Search API (2000 req/mois gratuit, `https://api.search.brave.com/res/v1/web/search?q=...`).

```php
private function searchBrave(string $denomination, string $ville): ?string {
    $apiKey = config('services.brave.api_key');
    if (!$apiKey) {
        Log::warning('Brave Search API key missing, skipping web search');
        return null;
    }
    
    $query = sprintf('%s %s site web officiel', $denomination, $ville);
    
    try {
        $response = Http::timeout(8)
            ->withHeaders([
                'X-Subscription-Token' => $apiKey,
                'Accept' => 'application/json',
            ])
            ->get('https://api.search.brave.com/res/v1/web/search', [
                'q' => $query,
                'count' => 5,
                'country' => 'fr',
                'safesearch' => 'moderate',
            ]);
        
        if (!$response->successful()) return null;
        
        $results = $response->json('web.results', []);
        foreach ($results as $r) {
            $url = $r['url'] ?? null;
            if (!$url) continue;
            
            $host = parse_url($url, PHP_URL_HOST);
            if ($this->isBlacklistedHost($host)) continue;
            
            return $this->canonicalizeUrl($url);
        }
    } catch (\Throwable $e) {
        \Sentry\captureException($e);
        Log::warning('Brave search failed', ['error' => $e->getMessage()]);
    }
    
    return null;
}

private function isBlacklistedHost(string $host): bool {
    $blacklist = [
        'linkedin.com', 'facebook.com', 'twitter.com', 'x.com', 'youtube.com',
        'instagram.com', 'tiktok.com', 'pinterest.com',
        'societe.com', 'verif.com', 'pappers.fr', 'manageo.fr', 'infogreffe.fr',
        'annuaire-entreprises.data.gouv.fr', 'pagesjaunes.fr',
    ];
    foreach ($blacklist as $b) {
        if (str_contains($host, $b)) return true;
    }
    return false;
}
```

**Garder** : Stratégie 1 (signals.legal.siteweb) en priorité. Stratégie 3 (Pages Jaunes scrape) **uniquement si `MOCK_SCRAPERS=false`** (sinon return null silencieusement).

Ajouter dans `config/services.php` :
```php
'brave' => ['api_key' => env('BRAVE_SEARCH_API_KEY')],
```

Ajouter dans `.env.example` :
```
BRAVE_SEARCH_API_KEY=
```

### Commit 2 — User-Agent rotation + retry exponentiel MentionsLegalesScraperService

`backend/app/Services/Legal/MentionsLegalesScraperService.php` :

```php
private const USER_AGENTS = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
];

private function fetchPath(string $website, string $path): ?string {
    $url = rtrim($website, '/') . $path;
    $ua = self::USER_AGENTS[array_rand(self::USER_AGENTS)];
    
    try {
        $response = Http::timeout(10)
            ->withHeaders(['User-Agent' => $ua, 'Accept' => 'text/html'])
            ->retry(2, 1000, function (\Throwable $e) {
                return $e instanceof \Illuminate\Http\Client\ConnectionException;
            })
            ->get($url);
        
        if (!$response->successful() || strlen($response->body()) < 500) {
            return null;  // skip JS-rendered ou erreur
        }
        
        // Random delay 200-800ms pour ne pas marteler
        usleep(random_int(200_000, 800_000));
        
        return $response->body();
    } catch (\Throwable $e) {
        \Sentry\captureException($e);
        return null;
    }
}
```

### Commit 3 — Webshare proxy config Pages Jaunes (préparation Phase B)

`backend/config/services.php` :
```php
'webshare' => [
    'enabled' => env('WEBSHARE_ENABLED', false),
    'username' => env('WEBSHARE_USERNAME'),
    'password' => env('WEBSHARE_PASSWORD'),
    'endpoint' => env('WEBSHARE_ENDPOINT', 'p.webshare.io:80'),
],
```

`backend/app/Services/Http/ProxiedHttpClient.php` (nouveau) :
```php
namespace App\Services\Http;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class ProxiedHttpClient {
    public function request(): PendingRequest {
        $client = Http::timeout(15);
        
        if (config('services.webshare.enabled')) {
            $proxy = sprintf(
                'http://%s:%s@%s',
                config('services.webshare.username'),
                config('services.webshare.password'),
                config('services.webshare.endpoint'),
            );
            $client = $client->withOptions(['proxy' => $proxy]);
        }
        
        return $client;
    }
}
```

`DomainFinderService::searchPagesJaunes()` utilise désormais `app(ProxiedHttpClient::class)->request()` au lieu de `Http::`.

`.env.example` :
```
WEBSHARE_ENABLED=false
WEBSHARE_USERNAME=
WEBSHARE_PASSWORD=
WEBSHARE_ENDPOINT=p.webshare.io:80
```

Tests Pest : mock `Http::fake()` + assert que `withOptions(proxy)` est bien appliqué quand `WEBSHARE_ENABLED=true`.

---

## SPRINT H2 — SMTP via Hunter.io

### Commit 4 — HunterEmailVerifier service

`backend/app/Services/Email/HunterEmailVerifier.php` :

Hunter.io a un free tier 25 vérifs/mois et payant à $0.005/vérif. Pour 1000 companies × 3 contacts moyens = 3000 vérifs = ~$15/mois. Bien moins risqué que probe direct depuis Hetzner.

```php
namespace App\Services\Email;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class HunterEmailVerifier {
    public function verify(string $email): array {
        $apiKey = config('services.hunter.api_key');
        if (!$apiKey) {
            return ['status' => 'unknown', 'reason' => 'no_api_key'];
        }
        
        // Cache 30j — pas la peine de re-vérifier
        return Cache::remember(
            "hunter:verify:{$email}",
            now()->addDays(30),
            fn() => $this->doVerify($email, $apiKey),
        );
    }
    
    private function doVerify(string $email, string $apiKey): array {
        try {
            $response = Http::timeout(20)
                ->retry(2, 1000)
                ->get('https://api.hunter.io/v2/email-verifier', [
                    'email' => $email,
                    'api_key' => $apiKey,
                ]);
            
            if (!$response->successful()) {
                \Sentry\captureMessage("Hunter API HTTP {$response->status()} for {$email}");
                return ['status' => 'unknown', 'reason' => 'http_error'];
            }
            
            $data = $response->json('data', []);
            return [
                'status' => $data['status'] ?? 'unknown',  // deliverable|undeliverable|risky|unknown
                'score' => $data['score'] ?? 0,
                'mx_records' => $data['mx_records'] ?? false,
                'smtp_check' => $data['smtp_check'] ?? false,
                'webmail' => $data['webmail'] ?? false,
                'disposable' => $data['disposable'] ?? false,
            ];
        } catch (\Throwable $e) {
            \Sentry\captureException($e);
            return ['status' => 'unknown', 'reason' => 'exception'];
        }
    }
}
```

### Commit 5 — Refactor EmailFinderService → Hunter au lieu de probe direct

`backend/app/Services/Email/EmailFinderService.php` :

**Remplacer** la méthode `probeSmtp()` (heritage sprint initial) par appel `HunterEmailVerifier::verify()`.

```php
public function verifyEmail(string $email): string {
    // Skip catchall providers (toujours valides sans info utile)
    $domain = substr(strrchr($email, '@'), 1);
    $catchallProviders = ['gmail.com','outlook.fr','outlook.com','yahoo.fr','yahoo.com',
                          'free.fr','orange.fr','wanadoo.fr','hotmail.fr','hotmail.com','laposte.net'];
    if (in_array($domain, $catchallProviders, true)) {
        return 'catchall';  // ne pas envoyer, qualité incertaine
    }
    
    $result = $this->hunterVerifier->verify($email);
    
    return match($result['status']) {
        'deliverable' => 'valid',
        'undeliverable' => 'invalid',
        'risky' => 'risky',
        default => 'unknown',
    };
}
```

**Retirer entièrement** le code `smtp_probe_rate:{domain}` Redis (sprint initial commit 5) — Hunter gère son propre rate limit.

Garder le fallback : si `HUNTER_API_KEY` absent → `verifyEmail` retourne `'unknown'` et le contact reste en `partial_email`. **Pas de fallback vers probe direct**.

`.env.example` :
```
HUNTER_API_KEY=
```

`config/services.php` :
```php
'hunter' => ['api_key' => env('HUNTER_API_KEY')],
```

### Commit 6 — Migration `email_verification_logs`

`backend/database/migrations/2026_05_19_000001_create_email_verification_logs.php` :

Tracker chaque vérif Hunter pour ne pas exploser le quota + audit.

```sql
CREATE TABLE IF NOT EXISTS email_verification_logs (
  id            BIGSERIAL PRIMARY KEY,
  workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
  email         VARCHAR(255) NOT NULL,
  status        VARCHAR(20) NOT NULL,
  score         INTEGER,
  provider      VARCHAR(20) NOT NULL DEFAULT 'hunter',
  raw_response  JSONB,
  verified_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (workspace_id, email, provider)
);

CREATE INDEX IF NOT EXISTS idx_email_verif_workspace_status 
  ON email_verification_logs (workspace_id, status);

ALTER TABLE email_verification_logs ENABLE ROW LEVEL SECURITY;
CREATE POLICY email_verif_ws ON email_verification_logs FOR ALL 
  USING (workspace_id::TEXT = COALESCE(NULLIF(current_setting('app.current_workspace_id', true), ''), workspace_id::TEXT));
```

`HunterEmailVerifier::doVerify()` upsert dans cette table après chaque appel API.

UI dans `Settings > Quotas` : afficher count vérifs du mois en cours + warning si > 80% du quota Hunter configuré.

---

## SPRINT H3 — Filtre INSEE actif partout

### Commit 7 — Garde-fou `etatAdministratifEtablissement='A'` systématique

3 fichiers à patcher :

**1.** `backend/app/Services/Insee/HttpInseeClient.php` méthode `searchByCriteria()` :

Après la réponse API (filtrage côté PHP obligatoire selon piège INSEE v3.11) :
```php
$etablissements = collect($response->json('etablissements', []))
    ->filter(fn($e) => 
        ($e['etablissementSiege'] ?? false) === true
        && ($e['etatAdministratifEtablissement'] ?? 'F') === 'A'
    )
    ->values()
    ->toArray();
```

**2.** `backend/app/Services/FranceTravail/FranceTravailDiscoveryClient.php` méthode `searchEntreprisesByDept()` :

Après dédoublonnage SIRET, **appeler INSEE pour valider l'état admin** avant de retourner :
```php
$validSirens = [];
foreach ($uniqueSirets as $siret) {
    $siren = substr($siret, 0, 9);
    $inseeData = app(HttpInseeClient::class)->getBySiren($siren);
    if (!$inseeData) continue;  // entreprise inconnue INSEE
    if (($inseeData->etatAdministratif ?? 'F') !== 'A') continue;  // radiée
    $validSirens[$siren] = $inseeData;
}
```

Batch INSEE max 100 SIREN par appel (limite API).

**3.** `WaterfallOrchestrator::step1_insee()` : si la réponse INSEE retourne `etatAdministratifEtablissement !== 'A'`, **marquer la company `prospection_status='archived_no_email'`** (avec reason `entreprise_radiee`) et SKIP tous les steps suivants (économie waterfall).

### Commit 8 — Migration `companies.archive_reason`

```sql
ALTER TABLE companies
  ADD COLUMN IF NOT EXISTS archive_reason VARCHAR(64);
  -- valeurs: entreprise_radiee | no_email | low_quality_score | duplicate | manual
  
CREATE INDEX IF NOT EXISTS idx_companies_archive_reason 
  ON companies (workspace_id, archive_reason) 
  WHERE archive_reason IS NOT NULL;
```

Adapter `TriageAutoService` (step11) pour renseigner `archive_reason` au lieu de juste set le status.

---

## SPRINT H4 — Observabilité

### Commit 9 — Sentry sur tous les nouveaux services

Wrap systématique de toutes les méthodes publiques des services suivants avec `\Sentry\captureException` dans le catch :

- `DomainFinderService` ✅ (déjà fait commit 1)
- `MentionsLegalesScraperService` ✅ (déjà fait commit 2)
- `HunterEmailVerifier` ✅ (déjà fait commit 4)
- `AutoClassifierService` ⚠️ à ajouter
- `AutoTaggerService` ⚠️ à ajouter
- `TriageAutoService` ⚠️ à ajouter
- `AudienceBuilderService` ⚠️ à ajouter
- `FranceTravailDiscoveryClient` ⚠️ à ajouter

Pattern à appliquer dans chaque service :
```php
try {
    // logique
} catch (\Throwable $e) {
    \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($company) {
        $scope->setTag('service', static::class);
        $scope->setContext('company', [
            'id' => $company->id,
            'siren' => $company->siren,
            'workspace_id' => $company->workspace_id,
        ]);
    });
    \Sentry\captureException($e);
    throw $e;  // re-throw pour que le job échoue proprement
}
```

### Commit 10 — Audit logs systématiques nouveaux services

Helper `App\Support\AuditLogger::log(string $action, array $context)` à utiliser dans :

- `AudienceBuilderService::refresh()` → action `audience.refreshed`
- `AudienceBuilderService::create()` → action `audience.created`
- `AutoTaggerService::syncTags()` → action `company.tags_synced` (seulement si delta > 0)
- `TriageAutoService::triage()` → action `company.archived` (seulement si transition vers archived)
- `HunterEmailVerifier::verify()` → action `email.verified` (avec quota tracking)
- Création/refresh campagnes existantes (déjà loggés mais vérifier cohérence)

Migration si la table `audit_logs` n'a pas déjà les colonnes nécessaires (vérifier d'abord) :
```sql
ALTER TABLE audit_logs
  ADD COLUMN IF NOT EXISTS context JSONB,
  ADD COLUMN IF NOT EXISTS resource_type VARCHAR(64),
  ADD COLUMN IF NOT EXISTS resource_id VARCHAR(64);

CREATE INDEX IF NOT EXISTS idx_audit_logs_action 
  ON audit_logs (workspace_id, action, created_at DESC);
```

### Commit 11 — Tests Playwright E2E sur 3 wizards critiques

`frontend/tests/e2e/` (nouveau dossier si absent) :

**1.** `campaigns-wizard.spec.ts` — flow complet création campagne :
- Login Will
- Click "+ Nouvelle campagne"
- Étape 1 : nom "E2E test"
- Étape 2 : zone dept 75
- Étape 3 : check 2 sources (INSEE + France Travail), vérif Google Maps + Pages Jaunes sont disabled+lock
- Étape 4 : params limites, click "Lancer"
- Assert redirect `/campaigns/{id}` + status `running`

**2.** `audiences-builder.spec.ts` :
- Naviguer `/audiences/new`
- Builder visuel : check dept 75 + size pme + tag sector-it-saas
- Assert live preview affiche un count > 0 (debounce 500ms attendu)
- Click "Créer audience"
- Assert apparait dans `/audiences`

**3.** `tags-manager.spec.ts` :
- Naviguer `/tags`
- Click "+ Nouveau tag"
- Form : slug=`e2e-test`, label=`E2E Test`, category=`custom`, color=`emerald`
- Submit
- Assert tag apparait dans la liste catégorie custom

Setup Playwright :
```bash
cd frontend
pnpm add -D @playwright/test
npx playwright install chromium
```

`frontend/playwright.config.ts` minimal :
```ts
import { defineConfig } from '@playwright/test';
export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  reporter: 'html',
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:5173',
    storageState: 'tests/e2e/.auth/will.json',
  },
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});
```

Auth fixture `tests/e2e/auth.setup.ts` : login Will une fois, sauvegarde storageState.

CI : ajouter step Playwright dans `.github/workflows/ci.yml` (run uniquement sur PR vers main, pas chaque push).

### Commit 12 — Dashboard Observabilité

Nouvelle page `/admin/observability` :
- KPI cards : taux d'erreur Waterfall 24h / Quota Hunter mois / Companies archivées par reason / Audiences refresh failures 7j
- Table dernières 50 erreurs Sentry (lien direct vers issue Sentry)
- Graph member_count par audience (7 derniers refresh)
- Heatmap activity waterfall (steps × heure du jour)

Sidebar entry sous section "Admin", icon `Activity` lucide.

Data viennent de `audit_logs` + `email_verification_logs` + table existante `scraper_runs`. Pas d'agrégation lourde, queries SQL directes < 100ms.

---

## SPRINT H5 — Scaling 1M companies

### Commit 13 — Audience refresh chunk parallèle via Horizon

Refactor `AudienceBuilderService::refresh()` :

Avant (sprint initial) : `chunk(500)` séquentiel → ~3min pour 100k companies, ~30min pour 1M.

Après : split en jobs `RefreshAudienceChunkJob` paralléles, 10 workers max via tag Horizon dédié.

```php
public function refresh(EmailAudience $audience): void {
    $totalCount = Company::query()
        ->where('workspace_id', $audience->workspace_id)
        ->count();
    
    $chunkSize = 5000;
    $chunks = (int) ceil($totalCount / $chunkSize);
    
    DB::transaction(function () use ($audience) {
        AudienceMember::where('audience_id', $audience->id)->delete();
        $audience->update(['refreshed_at' => null, 'member_count' => 0]);
    });
    
    $batch = Bus::batch([])
        ->name("audience-refresh-{$audience->id}")
        ->onQueue('audiences-refresh')
        ->allowFailures()
        ->finally(function () use ($audience) {
            $audience->update([
                'refreshed_at' => now(),
                'member_count' => AudienceMember::where('audience_id', $audience->id)->count(),
            ]);
        })
        ->dispatch();
    
    for ($i = 0; $i < $chunks; $i++) {
        $batch->add(new RefreshAudienceChunkJob(
            $audience->id,
            $i * $chunkSize,
            $chunkSize,
        ));
    }
}
```

`RefreshAudienceChunkJob` traite son chunk avec `INSERT INTO audience_members ... ON CONFLICT DO NOTHING` direct SQL (pas Eloquent, gain x5).

`config/horizon.php` : nouveau supervisor `audiences-refresh` avec `maxProcesses: 10`, `tries: 2`, `timeout: 600`.

### Commit 14 — Load test Artillery

`load-tests/audience-refresh.yml` :
```yaml
config:
  target: 'https://app.axion-crm-pro.com'
  phases:
    - duration: 60
      arrivalRate: 5
      name: warmup
    - duration: 300
      arrivalRate: 20
      name: sustained
  defaults:
    headers:
      Authorization: 'Bearer {{ $processEnvironment.API_TOKEN }}'

scenarios:
  - name: List + filter companies (10k)
    flow:
      - get:
          url: '/api/v1/companies?limit=100&department_code=75&size_category=pme'
          expect:
            - statusCode: 200
            - contentType: json

  - name: Preview audience criteria
    flow:
      - post:
          url: '/api/v1/audiences/preview'
          json:
            criteria:
              all:
                - field: prospection_status
                  op: in
                  value: [ready_for_outreach]
                - field: region_code
                  op: in
                  value: ['11']
          expect:
            - statusCode: 200
            - contentType: json
```

Doc `_AUDIT/LOAD-TEST-RUNBOOK.md` : comment lancer + baselines attendues (p95 < 800ms list, < 300ms preview).

### Commit 15 — Estimation coûts honnête (doc)

Fichier `_AUDIT/COST-ESTIMATION-1M-COMPANIES.md` à 1M entreprises/mois :

| Service | Volume | Coût unitaire | Coût mensuel |
|---|---|---|---|
| Mistral LLM classify | 1M companies × 1 call | $0.10/1K | $100 |
| Hunter.io email verify | 1M × 2 contacts moyens | $0.005 | $10 000 |
| INSEE Sirene API | 1M calls (gratuit 30 req/s) | $0 | $0 |
| France Travail API | 1M calls (gratuit) | $0 | $0 |
| Brave Search API | 1M calls (2K gratuit, puis $5/1K) | $5 | $4 990 |
| Pages Jaunes via Webshare | 100K calls Phase B | $30/mo flat | $30 |
| BODACC | 1M (gratuit data.gouv) | $0 | $0 |
| Hetzner CPX42 | flat | €12.49 | €12 |
| Postgres storage 1M rows | ~5GB | inclus CPX42 | $0 |

**Total réaliste : ~$15 130/mois** à 1M companies/mois avec verify email systématique.

**Levier d'optim** : ne vérifier email que pour companies score qualité > 60 → divise par 5 = ~$3000/mo.

**Cible réaliste honnête** : 200k companies/mois enrichies, dont 50k qualifiés Hunter = ~$750/mo.

---

## SPRINT H6 — Commande Artisan `rescrape-archives`

### Commit 16 — `companies:rescrape-archives` réellement codée

`backend/app/Console/Commands/RescrapeArchivesCommand.php` :

```php
namespace App\Console\Commands;

use App\Jobs\EnrichCompanyJob;
use App\Models\Company;
use Illuminate\Console\Command;

class RescrapeArchivesCommand extends Command {
    protected $signature = 'companies:rescrape-archives 
        {--limit=200 : Max companies à re-scraper}
        {--workspace= : Workspace UUID (default: tous)}
        {--reason=no_email : archive_reason à cibler}';
    
    protected $description = 'Re-dispatch EnrichCompanyJob pour companies archivées sans email depuis 30+ jours';
    
    public function handle(): int {
        $limit = (int) $this->option('limit');
        $workspace = $this->option('workspace');
        $reason = $this->option('reason');
        
        $query = Company::query()
            ->where('prospection_status', 'archived_no_email')
            ->where('archive_reason', $reason)
            ->where('updated_at', '<', now()->subDays(30))
            ->orderBy('updated_at', 'asc')
            ->limit($limit);
        
        if ($workspace) {
            $query->where('workspace_id', $workspace);
        }
        
        $companies = $query->get();
        $this->info("Re-scraping {$companies->count()} companies (reason={$reason}, limit={$limit})");
        
        $offset = 0;
        foreach ($companies as $company) {
            EnrichCompanyJob::dispatch($company->id)
                ->delay(now()->addSeconds($offset))
                ->onQueue('default');
            $offset += 2;  // 2s entre chaque pour ne pas tuer INSEE
        }
        
        $this->info("Dispatched {$companies->count()} jobs with 2s spacing");
        
        return self::SUCCESS;
    }
}
```

Vérifier dans `routes/console.php` que le schedule existant pointe bien sur cette commande :
```php
Schedule::command('companies:rescrape-archives --limit=200')
    ->monthlyOn(1, '02:00')
    ->withoutOverlapping()
    ->onOneServer();
```

Test Pest `RescrapeArchivesCommandTest` :
- Create 5 companies archived 35j ago + 5 companies archived 10j ago
- Run command
- Assert seulement 5 jobs dispatched (les 35j+)

---

## Conventions du repo (rappel)

- **Commits Conventional** : `feat:`, `fix:`, `docs:`, `test:`, `chore:`, `perf:`, `refactor:`
- **Jamais** `--no-verify`, `--no-gpg-sign`, force-push main
- **Tests** : Pest backend + Vitest frontend. Ne pas casser les ~206 Pest + 56 Vitest existants
- **i18n** : tout texte UI nouveau en FR direct
- **Design system** : import exclusif `@/components/ui`
- **Lucide-react** : icons (pas d'emojis)
- **Backend** : FormRequest, Resource, Service, Job
- **Migrations idempotentes** : `IF NOT EXISTS`
- **RLS** : policy workspace_isolation systématique

## Anti-régression critique

- ⚠️ Sprint initial pipeline 360° doit être mergé + déployé avant
- ⚠️ Les 5 services du sprint initial restent fonctionnels (on les enrichit, on les remplace pas)
- ⚠️ `EmailFinderService::probeSmtp()` retiré → vérifier qu'aucun autre code ne l'appelle
- ⚠️ Fallback `HUNTER_API_KEY` absent → graceful degradation, pas de crash
- ⚠️ Fallback `BRAVE_SEARCH_API_KEY` absent → graceful degradation
- ⚠️ Tests Pest existants restent verts
- ⚠️ Playwright E2E ne tourne qu'en CI sur PR (pas chaque push)
- ⚠️ Audience refresh nouveau via Bus::batch — vérifier que les anciennes audiences refresh manuel marchent toujours
- ⚠️ `RefreshAudienceChunkJob` doit être idempotent (chunk peut être re-tenté sans corrompre)

## Commandes serveur de déploiement (autopilot)

```bash
ssh root@<ip>
cd /opt/axion-crm-pro

# Pull + rebuild
git fetch origin main && git reset --hard origin/main
docker compose build api horizon app
docker compose up -d api horizon app

# Migrations
docker compose exec -T api php artisan migrate --force

# Vérifier Horizon supervisor audiences-refresh actif
docker compose exec -T api php artisan horizon:status
docker compose exec -T api php artisan horizon:list

# Smoke test commande rescrape
docker compose exec -T api php artisan companies:rescrape-archives --limit=10 --workspace=1db106f5-c8a4-47b0-bf86-930f1ccc9f4a

# Smoke test Hunter (1 vérif réelle, attention quota)
docker compose exec -T api php artisan tinker --execute='
$verifier = app(\App\Services\Email\HunterEmailVerifier::class);
$result = $verifier->verify("contact@example.com");
print_r($result);
'

# Smoke test Brave Search
docker compose exec -T api php artisan tinker --execute='
$finder = app(\App\Services\Domain\DomainFinderService::class);
$url = $finder->find("Carrefour SA", "Paris");
echo "Found: " . ($url ?? "NULL") . "\n";
'

# Smoke test audience refresh batch
docker compose exec -T api php artisan tinker --execute='
$audience = \App\Models\EmailAudience::first();
app(\App\Services\Audiences\AudienceBuilderService::class)->refresh($audience);
echo "Batch dispatched for audience #" . $audience->id . "\n";
'

sleep 30
docker compose exec -T api php artisan horizon:list | grep audience

# Vérifier Sentry reçoit bien les erreurs (déclencher exception volontaire)
docker compose exec -T api php artisan tinker --execute='
try { throw new \RuntimeException("hardening sprint smoke test"); }
catch (\Throwable $e) { \Sentry\captureException($e); echo "Sentry event sent\n"; }
'

# Playwright CI (local)
cd /home/user/dev/axion-crm-pro/frontend
E2E_BASE_URL=https://app.axion-crm-pro.com npx playwright test

# Vérifications finales SQL
docker compose exec -T postgres psql -U axion -d axion_crm -c "
SELECT COUNT(*) AS verif_logs_total FROM email_verification_logs;
SELECT archive_reason, COUNT(*) FROM companies WHERE archive_reason IS NOT NULL GROUP BY archive_reason;
SELECT action, COUNT(*) FROM audit_logs WHERE created_at > NOW() - INTERVAL '1 hour' GROUP BY action;
"
```

## Env vars à ajouter en prod (autopilot via Coolify API)

```bash
# Brave Search (créer compte gratuit https://brave.com/search/api/, 2K req/mois free)
BRAVE_SEARCH_API_KEY=<récupéré https://api.search.brave.com/app/keys>

# Hunter.io (compte free 25 vérifs/mois, sinon $34/mo 1K vérifs)
HUNTER_API_KEY=<récupéré https://hunter.io/api-keys>

# Webshare proxies (Phase B uniquement, laisser disabled au début)
WEBSHARE_ENABLED=false
```

**Action humaine requise** : créer les comptes Brave + Hunter et fournir les API keys. Sans ces clés, les services dégradent gracefully (return null/unknown) mais le pipeline perd 80% de sa valeur.

## Risques connus + mitigations

1. **Quota Brave gratuit explosé** (2K/mois) → cache résultat 90j Redis par `(denomination + ville)` hash, skip si déjà en cache
2. **Quota Hunter explosé** → table `email_verification_logs` UNIQUE constraint évite double appel, alert UI si > 80%
3. **Bus::batch Horizon overhead** → seuls audiences > 5K companies utilisent batch, sinon refresh inline (fast path)
4. **Playwright flaky CI** → run avec retry 2, timeout 30s par test, fixtures auth pré-baked
5. **Sentry rate limit** → DSN configuré avec `sample_rate: 0.1` sur événements normaux, `1.0` sur erreurs
6. **Webshare coût mensuel** → désactivé par défaut, Will l'active quand il aura validé Phase A
7. **Filtre `etatAdministratif='A'` rétroactif** → ne pas re-traiter les 1000 entreprises Isère existantes (one-shot SQL `UPDATE companies SET prospection_status='archived_no_email', archive_reason='entreprise_radiee' WHERE etat_administratif != 'A'` après vérif manuelle)

## Critères de succès (Will valide à la fin)

1. ✅ `npx tsc --noEmit` frontend → 0 erreur
2. ✅ `php artisan migrate --force` → 2 nouvelles migrations vertes
3. ✅ Pest tests : nouveaux tests verts + 0 régression sur 206+ existants
4. ✅ Playwright E2E : 3/3 specs verts en CI
5. ✅ Smoke Brave Search : 1 vraie requête retourne un URL valide
6. ✅ Smoke Hunter : 1 vraie vérif retourne `status` non-null
7. ✅ Smoke rescrape command : dispatche bien N jobs avec spacing 2s
8. ✅ Smoke audience refresh batch : Bus::batch créé + workers Horizon actifs
9. ✅ Sentry test event apparait dans le projet Sentry
10. ✅ Nouvelle page `/admin/observability` accessible + KPIs chargés
11. ✅ `EmailFinderService` ne contient plus AUCUNE référence à probe SMTP direct
12. ✅ `FranceTravailDiscoveryClient` filtre bien `etatAdministratif='A'`
13. ✅ Doc `_AUDIT/COST-ESTIMATION-1M-COMPANIES.md` + `_AUDIT/LOAD-TEST-RUNBOOK.md` présents
14. ✅ 12-16 commits propres pushés origin/main avec messages Conventional
15. ✅ Aucune régression : pipeline 360° initial + 1000 entreprises Isère + campagnes existantes restent OK

## Workflow attendu (autopilot)

1. **Vérif prérequis** : checker les 6 fichiers sprint initial + 4 tables DB. Si KO → STOP & ASK.
2. **Lecture initiale** : `git log --oneline -15`, `EmailFinderService.php`, `DomainFinderService.php`, `FranceTravailDiscoveryClient.php`, `AudienceBuilderService.php`, `routes/console.php`, `config/horizon.php`, `.env.example`
3. **Sprint H1 + H2 en parallèle** via sub-agents (anti-bot + Hunter sont indépendants) :
   - Sub-agent A : commits 1, 2, 3 (anti-bot DDG/PJ/MentionsLegales)
   - Sub-agent B : commits 4, 5, 6 (Hunter complet)
4. **Sprint H3 séquentiel** (touche WaterfallOrchestrator, conflits potentiels) : commits 7, 8
5. **Sprint H4 en parallèle** via sub-agents :
   - Sub-agent C : commit 9 (Sentry sur 5 services)
   - Sub-agent D : commit 10 (audit logs)
   - Sub-agent E : commit 11 (Playwright complet)
   - Sub-agent F : commit 12 (dashboard observability UI)
6. **Sprint H5 séquentiel** (refactor AudienceBuilderService critique) : commits 13, 14, 15
7. **Sprint H6 isolé** : commit 16
8. **Tests** : `pnpm test` frontend + `vendor/bin/pest --parallel` backend → 0 fail
9. **Push** : `git push origin main` (1 push final pour les 16 commits)
10. **Deploy prod** : exécuter le bloc "Commandes serveur de déploiement" en SSH
11. **Smoke test final** : exécuter tous les smoke tests du bloc déploiement, vérifier critères de succès 1-15
12. **Rapport final** ≤ 600 mots : ce qui marche, ce qui dégrade gracefully sans API keys, action humaine restante (créer comptes Brave + Hunter)

## Si tu as un doute

- Préfère **graceful degradation** plutôt que crash : pas d'API key → return null/unknown, jamais throw
- **STOP and ASK** uniquement si :
  - Migration risque de corrompre data existante (le check `etatAdministratif='A'` ne doit PAS supprimer les 1000 entreprises Isère sans confirmation)
  - Sentry capture des secrets en clair (vérifier scrubbing)
  - Hunter API key fournie via env mais quota mensuel déjà épuisé (vérifier headers `X-RateLimit-Remaining`)
- Sinon : prends la décision la plus simple + documente dans commit message

## Pas d'over-engineering

- Pas de circuit breaker complexe (cache 30j Hunter suffit)
- Pas de retry exponentiel custom (utiliser `Http::retry()` natif Laravel)
- Pas de Prometheus / Grafana (Sentry + dashboard `/admin/observability` suffisent)
- Pas de tests E2E sur tous les flows (juste 3 wizards critiques)
- Pas de migration data rétroactive automatique (Will validera one-shot SQL manuellement)

## Hors scope (sprints futurs explicites)

❌ **NE PAS coder** :
- Module envoi email (templates, SMTP send, tracking opens/clicks, désinscription)
- Page d'opt-out RGPD publique (sprint juridique dédié plus tard)
- Migration vers DataDog / NewRelic (Sentry suffit pour V1)
- Cluster Postgres HA (CPX42 single node OK jusqu'à 500k companies)
- Bloctel check (sprint juridique)
- Article 14 RGPD info subjects (sprint juridique)

✅ **Préparer architecture** uniquement (services modulaires, env vars découplées) pour brancher plus tard sans refactor.

## Action humaine post-deploy

1. Créer compte gratuit Brave Search → récupérer API key → poser dans Coolify env vars
2. Créer compte free Hunter (25 vérifs test) ou Starter ($34/mo, 1K vérifs/mois) → API key → Coolify
3. Décider quand activer Webshare (Phase B, ~$30/mo) — pas urgent
4. Vérifier dans Sentry que les events arrivent + configurer alertes (> 10 erreurs/h sur tag `service=*` → email Will)
5. Lancer une vraie campagne 50 entreprises dept 75 avec pipeline complet activé → suivre via `/admin/observability`
6. Valider one-shot SQL re-archivage entreprises radiées Isère (script fourni dans rapport final)

**GO. Lance le hardening complet bout-en-bout, autopilot, push origin/main, deploy prod, smoke test. Rapport final dans la même conversation.**
