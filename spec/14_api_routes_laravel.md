# 14 — API routes Laravel (60-80 endpoints)

> **Stack :** Laravel 12 + Sanctum SPA cookie + Spatie Data DTOs + Spatie Query Builder filtres + rate limiting IP + user.
> **Convention :** `/api/v1/` préfixe, RESTful, JSON only, snake_case JSON.
> **Phase 2 :** routes définies retournant `501 Not Implemented` avec types Spatie Data corrects.

---

## §1 — Conventions

### Auth

- **SPA frontend** : Sanctum cookie session (`/sanctum/csrf-cookie` puis `/login`)
- **Programmatic** : token Bearer (peu utilisé Phase 1, mais possible pour API publique future)

### Format response

```json
{
  "data": {...},                  // payload typé
  "meta": {                       // optionnel pour pagination/total
    "current_page": 1,
    "total": 245,
    "per_page": 50,
    "last_page": 5
  }
}
```

### Codes HTTP

- `200` OK / `201` Created / `204` No Content
- `400` Bad Request (validation) / `401` Unauthenticated / `403` Forbidden / `404` Not Found
- `409` Conflict (dedup violations) / `422` Validation errors
- `429` Rate limit hit
- `500` Server Error / `501` Not Implemented (Phase 2 stubs)

### Pagination

Query params : `?page=1&per_page=50&sort=-updated_at&filter[size_category]=pme`. Convention Spatie Query Builder.

### Rate limiting

```php
// app/Providers/RouteServiceProvider.php
RateLimiter::for('api', fn(Request $r) => [
    Limit::perMinute(120)->by($r->user()?->id ?? $r->ip()),
    Limit::perDay(20_000)->by($r->user()?->id ?? $r->ip()),
]);
RateLimiter::for('scraping_run', fn(Request $r) => [
    Limit::perMinute(20)->by($r->user()?->id),       // 20 runs/min/user max
]);
RateLimiter::for('llm_test', fn(Request $r) => [
    Limit::perMinute(30)->by($r->user()?->id),
]);
```

### Workspace scoping middleware

```php
// app/Http/Middleware/SetCurrentWorkspace.php
public function handle($request, Closure $next)
{
    $wsId = $request->user()->current_workspace_id;
    DB::statement("SET LOCAL app.current_workspace_id = ?", [$wsId]);  // active RLS
    return $next($request);
}
```

---

## §2 — Routes Auth (8)

```php
// routes/web.php (sanctum SPA)
Route::middleware('guest')->group(function () {
    Route::post('/sanctum/csrf-cookie',     fn() => response()->noContent());
    Route::post('/login',                   [LoginController::class, 'store']);
    Route::post('/magic-link/request',      [MagicLinkController::class, 'request']);
    Route::get('/magic-link/{token}',       [MagicLinkController::class, 'consume']);
    Route::post('/password/forgot',         [PasswordResetController::class, 'sendResetLink']);
    Route::post('/password/reset',          [PasswordResetController::class, 'reset']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout',                  [LoginController::class, 'destroy']);
    Route::post('/two-factor/verify',       [TwoFactorController::class, 'verify']);
    Route::post('/two-factor/enable',       [TwoFactorController::class, 'enable']);
    Route::post('/two-factor/disable',      [TwoFactorController::class, 'disable']);
});
```

---

## §3 — Routes Users / Workspaces (12)

```php
Route::middleware(['auth:sanctum','set.workspace'])->prefix('api/v1')->group(function () {
    // User
    Route::get('/me',                            [MeController::class, 'show']);
    Route::patch('/me',                          [MeController::class, 'update']);
    Route::get('/me/workspaces',                 [MeController::class, 'workspaces']);
    Route::post('/me/switch-workspace/{ws}',     [MeController::class, 'switchWorkspace']);

    // Workspaces
    Route::get('/workspaces',                    [WorkspaceController::class, 'index']);
    Route::post('/workspaces',                   [WorkspaceController::class, 'store']);
    Route::patch('/workspaces/{ws}',             [WorkspaceController::class, 'update']);
    Route::delete('/workspaces/{ws}',            [WorkspaceController::class, 'destroy']);

    // Users (within workspace)
    Route::get('/users',                         [UserController::class, 'index']);
    Route::get('/users/{u}',                     [UserController::class, 'show']);
    Route::patch('/users/{u}',                   [UserController::class, 'update']);
    Route::delete('/users/{u}',                  [UserController::class, 'destroy']);

    // Invitations
    Route::get('/invitations',                   [InvitationController::class, 'index']);
    Route::post('/invitations',                  [InvitationController::class, 'store']);
    Route::delete('/invitations/{inv}',          [InvitationController::class, 'destroy']);
    Route::post('/invitations/{token}/accept',   [InvitationController::class, 'accept'])->withoutMiddleware('auth:sanctum');
});
```

### Exemple Controller + DTO

```php
// app/Data/UpdateMeData.php
class UpdateMeData extends Data
{
    public function __construct(
        public ?string $name,
        public ?string $locale,
        public ?string $timezone,
    ) {}
    public static function rules(): array { return [
        'name'     => ['sometimes','string','min:2','max:120'],
        'locale'   => ['sometimes','in:fr,en'],
        'timezone' => ['sometimes','timezone'],
    ];}
}

// app/Http/Controllers/MeController.php
public function update(UpdateMeData $data): UserResource
{
    auth()->user()->update($data->toArray());
    return new UserResource(auth()->user()->fresh());
}
```

---

## §4 — Routes Companies (10)

```php
Route::get('/companies',                         [CompanyController::class, 'index']);
Route::get('/companies/{c}',                     [CompanyController::class, 'show']);
Route::post('/companies',                        [CompanyController::class, 'store']);          // import manuel
Route::patch('/companies/{c}',                   [CompanyController::class, 'update']);
Route::delete('/companies/{c}',                  [CompanyController::class, 'destroy']);
Route::post('/companies/{c}/enrich',             [CompanyController::class, 'enrich'])->middleware('throttle:scraping_run');
Route::post('/companies/bulk-enrich',            [CompanyController::class, 'bulkEnrich']);
Route::post('/companies/bulk-tag',               [CompanyController::class, 'bulkTag']);
Route::get('/companies/{c}/enrichment-runs',     [CompanyController::class, 'enrichmentRuns']);
Route::post('/companies/import-csv',             [CompanyImportController::class, 'store']);
Route::get('/companies/export',                  [CompanyController::class, 'export']);          // CSV/XLSX
```

### `GET /companies` exemple (Spatie Query Builder)

```php
public function index(Request $r): CompanyCollection
{
    $companies = QueryBuilder::for(Company::class)
        ->where('workspace_id', auth()->user()->current_workspace_id)
        ->whereNull('deleted_at')
        ->allowedFilters([
            'siren', 'legal_name', 'quality_score',
            AllowedFilter::partial('legal_name'),
            AllowedFilter::exact('size_category'),
            AllowedFilter::exact('region_code'),
            AllowedFilter::exact('department_code'),
            AllowedFilter::exact('prospection_status'),
            AllowedFilter::exact('axion_offer_match_code'),
            AllowedFilter::scope('has_signal'),
            AllowedFilter::scope('has_quality_complete'),
            AllowedFilter::callback('discovery_source_in', fn($q, $vals) => $q->whereHas('contacts', fn($c) => $c->whereIn('discovery_source', $vals))),
            AllowedFilter::callback('tags_any', fn($q, $vals) => $q->whereJsonContains('tags', $vals)),
        ])
        ->allowedSorts(['legal_name','updated_at','quality_score','axion_offer_match_score','priority_label'])
        ->defaultSort('-updated_at')
        ->paginate($r->input('per_page', 50));

    return new CompanyCollection($companies);
}
```

---

## §5 — Routes Contacts (6)

```php
Route::get('/contacts',                          [ContactController::class, 'index']);
Route::get('/contacts/{c}',                      [ContactController::class, 'show']);
Route::post('/contacts',                         [ContactController::class, 'store']);
Route::patch('/contacts/{c}',                    [ContactController::class, 'update']);
Route::delete('/contacts/{c}',                   [ContactController::class, 'destroy']);
Route::post('/contacts/{c}/find-emails',         [ContactController::class, 'findEmails'])->middleware('throttle:scraping_run');
Route::post('/contacts/{c}/revalidate-email',    [ContactController::class, 'revalidateEmail']);
```

---

## §6 — Routes Coverage / Map (4)

```php
Route::get('/coverage',                          [CoverageController::class, 'index']);   // données chloropleth
Route::get('/coverage/zone/{level}/{code}',     [CoverageController::class, 'showZone']);
Route::post('/coverage/zone/scrape',             [CoverageController::class, 'scrapeZone'])->middleware('throttle:scraping_run');
Route::get('/cities/suggest',                    [CityController::class, 'suggest']);     // auto-suggest
Route::get('/coverage/next-zone',                [CoverageController::class, 'nextZone']);// algo prochaine zone
```

---

## §7 — Routes Scraping (12)

```php
// Sources config
Route::get('/scraping/sources',                  [ScrapingSourceController::class, 'index']);
Route::patch('/scraping/sources/{src}',          [ScrapingSourceController::class, 'update']);
Route::post('/scraping/sources/{src}/test',      [ScrapingSourceController::class, 'test'])->middleware('throttle:scraping_run');

// Runs
Route::get('/scraping/runs',                     [ScraperRunController::class, 'index']);
Route::get('/scraping/runs/{run}',               [ScraperRunController::class, 'show']);

// Targets queue
Route::get('/scraping/targets',                  [ScraperTargetController::class, 'index']);
Route::post('/scraping/targets/bulk',            [ScraperTargetController::class, 'bulkEnqueue']);

// Anomalies
Route::get('/scraping/anomalies',                [AnomalyController::class, 'index']);
Route::post('/scraping/anomalies/{a}/ack',       [AnomalyController::class, 'acknowledge']);

// Proxies
Route::get('/scraping/proxies',                  [ProxyController::class, 'index']);
Route::get('/scraping/proxies/providers',        [ProxyProviderController::class, 'index']);
Route::post('/scraping/proxies/providers',       [ProxyProviderController::class, 'store']);
Route::patch('/scraping/proxies/providers/{p}',  [ProxyProviderController::class, 'update']);
Route::delete('/scraping/proxies/providers/{p}', [ProxyProviderController::class, 'destroy']);
Route::post('/scraping/proxies/providers/{p}/test', [ProxyProviderController::class, 'test']);

// Search engines
Route::get('/scraping/search-engines',           [SearchEngineController::class, 'index']);
Route::patch('/scraping/search-engines/{e}',     [SearchEngineController::class, 'update']);

// Rotations dashboard data
Route::get('/scraping/rotations',                [RotationsController::class, 'index']);
```

---

## §8 — Routes LLM (8)

```php
Route::get('/llm/providers',                     [LLMProviderController::class, 'index']);
Route::patch('/llm/providers/{p}',               [LLMProviderController::class, 'update']);
Route::post('/llm/providers/{p}/test',           [LLMProviderController::class, 'test']);

Route::get('/llm/use-cases',                     [LLMUseCaseController::class, 'index']);
Route::patch('/llm/use-cases/{uc}',              [LLMUseCaseController::class, 'update']);

Route::get('/llm/prompts',                       [PromptTemplateController::class, 'index']);
Route::get('/llm/prompts/{tpl}',                 [PromptTemplateController::class, 'show']);  // + versions
Route::post('/llm/prompts/{tpl}/versions',       [PromptTemplateController::class, 'addVersion']);
Route::post('/llm/prompts/{tpl}/set-current',    [PromptTemplateController::class, 'setCurrent']);

Route::post('/llm/test-prompt',                  [LLMTestController::class, 'run'])->middleware('throttle:llm_test');

Route::get('/llm/usage',                         [LLMUsageController::class, 'index']);    // tabular
Route::get('/llm/usage/cost-per-enrichment',     [LLMUsageController::class, 'costPerEnrichment']);
```

---

## §9 — Routes RGPD (8)

```php
Route::get('/rgpd/requests',                     [GdprRequestController::class, 'index']);
Route::post('/rgpd/requests',                    [GdprRequestController::class, 'store']);   // saisie manuelle
Route::get('/rgpd/requests/{req}',               [GdprRequestController::class, 'show']);
Route::patch('/rgpd/requests/{req}',             [GdprRequestController::class, 'update']);
Route::post('/rgpd/requests/{req}/verify-identity',  [GdprRequestController::class, 'verifyIdentity']);
Route::get('/rgpd/requests/{req}/preview-impact',    [GdprRequestController::class, 'previewImpact']);
Route::post('/rgpd/requests/{req}/execute-erasure',  [GdprRequestController::class, 'executeErasure']);
Route::post('/rgpd/requests/{req}/export-portability', [GdprRequestController::class, 'exportPortability']);

Route::get('/rgpd/audit-log',                    [AuditLogController::class, 'index']);
Route::post('/rgpd/audit-log/verify-chain',      [AuditLogController::class, 'verifyChain']);

Route::get('/rgpd/data-processing-log',          [DataProcessingLogController::class, 'index']);
Route::get('/rgpd/ai-act-register',              [AiActRegisterController::class, 'index']);

Route::get('/rgpd/opt-out',                      [OptOutController::class, 'index']);
Route::post('/rgpd/opt-out',                     [OptOutController::class, 'store']);
Route::delete('/rgpd/opt-out/{id}',              [OptOutController::class, 'destroy']);
```

---

## §10 — Routes Référentiels (6)

```php
Route::get('/referentiels/countries',            [ReferentialController::class, 'countries']);
Route::get('/referentiels/regions',              [ReferentialController::class, 'regions']);
Route::get('/referentiels/departments',          [ReferentialController::class, 'departments']);
Route::get('/referentiels/cities/{insee}',       [ReferentialController::class, 'city']);
Route::get('/referentiels/naf',                  [ReferentialController::class, 'naf']);        // tree
Route::get('/referentiels/legal-forms',          [ReferentialController::class, 'legalForms']);
Route::get('/referentiels/effectif-ranges',      [ReferentialController::class, 'effectifRanges']);

// Axion-IA offers & keywords (CRUD)
Route::get('/referentiels/axion-offer-targets',     [AxionOfferTargetController::class, 'index']);
Route::post('/referentiels/axion-offer-targets',    [AxionOfferTargetController::class, 'store']);
Route::patch('/referentiels/axion-offer-targets/{id}', [AxionOfferTargetController::class, 'update']);
Route::delete('/referentiels/axion-offer-targets/{id}', [AxionOfferTargetController::class, 'destroy']);

Route::get('/referentiels/strategic-keywords',      [StrategicKeywordController::class, 'index']);
Route::post('/referentiels/strategic-keywords',     [StrategicKeywordController::class, 'store']);
// ...

Route::get('/referentiels/auto-tag-definitions',    [AutoTagDefinitionController::class, 'index']);
Route::post('/referentiels/auto-tag-definitions',   [AutoTagDefinitionController::class, 'store']);
// ...
```

---

## §11 — Routes Dashboard + Reports (4)

```php
Route::get('/dashboard/kpis',                    [DashboardController::class, 'kpis']);
Route::get('/dashboard/throughput',              [DashboardController::class, 'throughput']);
Route::get('/dashboard/cost-breakdown',          [DashboardController::class, 'costBreakdown']);
Route::get('/dashboard/scraper-activity',        [DashboardController::class, 'scraperActivity']);
```

---

## §12 — Routes Phase 2 stubs (10+)

Toutes retournent `501 Not Implemented` mais avec les **types Spatie Data corrects** pour permettre développement UI Phase 1.

```php
Route::middleware(['auth:sanctum','set.workspace'])->prefix('api/v1')->group(function () {

    // Campaigns
    Route::get('/campaigns',                     fn() => response()->json(['error' => 'phase2_not_implemented'], 501));
    Route::post('/campaigns',                    fn() => response()->json(['error' => 'phase2_not_implemented'], 501));
    Route::patch('/campaigns/{c}',               fn() => response()->json(['error' => 'phase2_not_implemented'], 501));
    Route::post('/campaigns/{c}/start',          fn() => response()->json(['error' => 'phase2_not_implemented'], 501));
    Route::post('/campaigns/{c}/pause',          fn() => response()->json(['error' => 'phase2_not_implemented'], 501));

    // Cold Email
    Route::get('/cold-email/sending-domains',    fn() => response()->json(['error' => 'phase2_not_implemented'], 501));
    Route::get('/cold-email/smtp-ips',           fn() => response()->json(['error' => 'phase2_not_implemented'], 501));
    Route::get('/cold-email/templates',          fn() => response()->json(['error' => 'phase2_not_implemented'], 501));
    Route::get('/cold-email/sends',              fn() => response()->json(['error' => 'phase2_not_implemented'], 501));

    // LinkedIn Outreach
    Route::get('/linkedin/accounts',             fn() => response()->json(['error' => 'phase2_not_implemented'], 501));
    Route::get('/linkedin/campaigns',            fn() => response()->json(['error' => 'phase2_not_implemented'], 501));
    Route::get('/linkedin/messages',             fn() => response()->json(['error' => 'phase2_not_implemented'], 501));

    // CRM
    Route::get('/crm/pipelines',                 fn() => response()->json(['error' => 'phase2_not_implemented'], 501));
    Route::get('/crm/deals',                     fn() => response()->json(['error' => 'phase2_not_implemented'], 501));
    Route::get('/crm/activities',                fn() => response()->json(['error' => 'phase2_not_implemented'], 501));

    // Analytics
    Route::get('/analytics/snapshots',           fn() => response()->json(['error' => 'phase2_not_implemented'], 501));
    Route::get('/analytics/funnels',             fn() => response()->json(['error' => 'phase2_not_implemented'], 501));
    Route::get('/analytics/cohorts',             fn() => response()->json(['error' => 'phase2_not_implemented'], 501));
});
```

---

## §13 — DTOs Spatie Data (extraits)

```php
// app/Data/CompanyData.php
class CompanyData extends Data
{
    public function __construct(
        public string $id,
        public ?string $siren,
        public string $legalName,
        public ?string $brandName,
        public ?string $nafSubclassCode,
        public ?string $effectifRangeCode,
        public ?string $sizeCategory,
        public ?float $revenueEur,
        public ?int $revenueYear,
        public ?string $cityInsee,
        public ?string $departmentCode,
        public ?string $regionCode,
        public string $countryCode,
        public ?float $latitude,
        public ?float $longitude,
        public ?string $websiteUrl,
        public ?string $mainPhone,
        public ?string $linkedinUrl,
        public ?int $iaMaturityScore,
        public ?string $iaMaturityLabel,
        public ?string $axionOfferMatchCode,
        public ?int $axionOfferMatchScore,
        public ?string $priorityLabel,
        public string $qualityScore,
        public string $prospectionStatus,
        public array $tags,
        public array $autoTags,
        public ?DateTime $firstSeenAt,
        public ?DateTime $lastEnrichedAt,
        public Lazy|Collection $contacts,
        public Lazy|Collection $businessSignals,
    ) {}
}

// app/Data/ContactData.php
class ContactData extends Data { /* similar */ }
```

---

## §14 — Récap inventaire endpoints

| Domaine | Endpoints |
|---------|-----------|
| Auth | 8 |
| Users / Workspaces / Invitations | 12 |
| Companies | 11 |
| Contacts | 7 |
| Coverage / Map / Cities | 5 |
| Scraping (sources/runs/targets/anomalies/proxies/engines/rotations) | 18 |
| LLM (providers/use cases/prompts/usage/test) | 11 |
| RGPD (requests/audit/dpl/ai_act/opt_out) | 13 |
| Référentiels | 12 |
| Dashboard | 4 |
| **Sous-total Phase 1** | **101 endpoints** |
| Phase 2 stubs (501) | ~20 |
| **Total** | **~121 endpoints** |

(Le prompt v6 mentionnait 60-80 ; le compte réel reflète la granularité métier. Cohérent.)

---

## §15 — Versioning API

Préfixe `/api/v1/`. Toute breaking change → nouvelle version `/api/v2/`. Maintient v1 deprecated 12 mois.

---

## §16 — Documentation OpenAPI

Génération auto via `darkaonline/l5-swagger` (annotations PHP) ou `dedoc/scramble` (introspection auto). Swagger UI servi à `/docs`.

---

## Lecture suivante

→ `15_auth_multitenant_rbac.md` (Sanctum SPA + 2FA + magic link + RLS + Spatie Permission + hash chain).
