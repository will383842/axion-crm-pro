<?php

use App\Http\Controllers\Api\AiActRegisterController;
use App\Http\Controllers\Api\AudiencesController;
use App\Http\Controllers\Api\AuditLogsController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\MagicLinkController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Auth\TwoFactorController;
use App\Http\Controllers\Api\CompaniesController;
use App\Http\Controllers\Api\ContactsController;
use App\Http\Controllers\Api\CoverageController;
use App\Http\Controllers\Api\Crm\ArbitrageController;
use App\Http\Controllers\Api\Crm\BulkController;
use App\Http\Controllers\Api\Crm\CandidatesController;
use App\Http\Controllers\Api\Crm\ContactsHubController;
use App\Http\Controllers\Api\Crm\PersonTimelineController;
use App\Http\Controllers\Api\FeaturesController;
use App\Http\Controllers\Api\GlobalSearchController;
use App\Http\Controllers\Api\JournalistsController;
use App\Http\Controllers\Api\LlmUsageController;
use App\Http\Controllers\Api\LlmUseCasesController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\NotificationsController;
use App\Http\Controllers\Api\ObservabilityController;
use App\Http\Controllers\Api\Phase2\AnalyticsController;
use App\Http\Controllers\Api\Phase2\ColdEmailController;
use App\Http\Controllers\Api\Phase2\CrmController;
use App\Http\Controllers\Api\Phase2\LinkedInController;
use App\Http\Controllers\Api\ProxyProvidersController;
use App\Http\Controllers\Api\RgpdRequestsController;
use App\Http\Controllers\Api\RotationsController;
use App\Http\Controllers\Api\SavedViewsController;
use App\Http\Controllers\Api\ScraperRunsController;
use App\Http\Controllers\Api\ScrapingCampaignsController;
use App\Http\Controllers\Api\TagsController;
use App\Http\Controllers\Api\UsersController;
use App\Http\Controllers\Api\WorkspaceController;
use App\Http\Controllers\Internal\ScraperResultController;
use App\Http\Controllers\Internal\SiteGdprController;
use App\Http\Controllers\Internal\SiteSyncController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 routes (Sanctum SPA cookie-based)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // --- Auth (non protégé, throttle anti brute-force) ----------------------
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/auth/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
    Route::post('/auth/onboarding/complete', [AuthController::class, 'completeOnboardingTour'])->middleware('auth:sanctum');
    Route::post('/auth/2fa/verify', [TwoFactorController::class, 'verify'])->middleware('throttle:login');
    Route::post('/auth/2fa/setup', [TwoFactorController::class, 'setup'])->middleware('auth:sanctum');
    Route::post('/auth/2fa/confirm', [TwoFactorController::class, 'confirm'])->middleware('auth:sanctum');
    Route::post('/auth/magic-link', [MagicLinkController::class, 'request'])->middleware('throttle:magic-link');
    Route::post('/auth/magic-link/verify', [MagicLinkController::class, 'verify'])->middleware('throttle:magic-link');
    Route::post('/auth/password/forgot', [PasswordResetController::class, 'forgot'])->middleware('throttle:magic-link');
    Route::post('/auth/password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:magic-link');

    // --- Routes protégées -------------------------------------------------
    Route::middleware(['auth:sanctum', 'workspace', 'first-login'])->group(function () {

        // Dashboard (stub MVP — endpoints détaillés Sprint 19)
        Route::get('/dashboard/stats', function () {
            return response()->json([
                'companies_total' => 0,
                'companies_enriched_24h' => 0,
                'contacts_qualified' => 0,
                'scraper_runs_24h' => 0,
                'llm_cost_eur_month' => 0,
                'quality_distribution' => ['complete' => 0, 'partielle' => 0, 'basique' => 0],
                'size_distribution' => [
                    'artisan' => 0, 'tpe' => 0, 'pme' => 0, 'eti' => 0, 'grande_entreprise' => 0,
                ],
            ]);
        });
        Route::get('/search', function (Request $request) {
            return response()->json([
                'companies' => [],
                'contacts' => [],
                'tags' => [],
            ]);
        });

        // Workspace + users
        Route::get('/workspace', [WorkspaceController::class, 'show']);
        Route::put('/workspace', [WorkspaceController::class, 'update']);
        Route::get('/users', [UsersController::class, 'index']);
        Route::post('/users', [UsersController::class, 'store']);
        Route::put('/users/{user}', [UsersController::class, 'update']);
        Route::delete('/users/{user}', [UsersController::class, 'destroy']);

        // Companies
        Route::get('/companies', [CompaniesController::class, 'index']);
        // /companies/export DOIT précéder /companies/{company} (sinon "export" pris pour un id).
        Route::get('/companies/export', [CompaniesController::class, 'export'])
            // §2.10 : un export emporte 4,29 M de fiches nominatives hors du
            // système. Le throttle limitait la CADENCE, pas le DROIT.
            ->middleware(['throttle:scraper-list', 'permission:data.export']);
        Route::post('/companies', [CompaniesController::class, 'store']);
        Route::get('/companies/{company}', [CompaniesController::class, 'show']);
        Route::put('/companies/{company}', [CompaniesController::class, 'update']);
        Route::delete('/companies/{company}', [CompaniesController::class, 'destroy']);
        Route::post('/companies/{company}/enrich', [CompaniesController::class, 'enrich']);
        Route::post('/companies/bulk-enrich', [CompaniesController::class, 'bulkEnrich']);
        Route::post('/companies/{company}/recompute-score', [CompaniesController::class, 'recomputeScore']);

        // Contacts
        Route::get('/contacts', [ContactsController::class, 'index']);
        Route::get('/contacts/{contact}', [ContactsController::class, 'show']);
        Route::put('/contacts/{contact}', [ContactsController::class, 'update']);
        Route::delete('/contacts/{contact}', [ContactsController::class, 'destroy']);

        // Médias & Journalistes (chantier base médias)
        // /media/export DOIT précéder /media/{media} (sinon "export" pris pour un id).
        Route::get('/media', [MediaController::class, 'index']);
        Route::get('/media/export', [MediaController::class, 'export'])
            ->middleware(['throttle:scraper-list', 'permission:data.export']);
        Route::get('/media/{media}', [MediaController::class, 'show']);

        Route::get('/journalists', [JournalistsController::class, 'index']);
        Route::get('/journalists/export', [JournalistsController::class, 'export'])
            ->middleware(['throttle:scraper-list', 'permission:data.export']);
        Route::get('/journalists/{journalist}', [JournalistsController::class, 'show']);
        Route::post('/journalists/{journalist}/opt-out', [JournalistsController::class, 'optOut']);
        Route::delete('/journalists/{journalist}', [JournalistsController::class, 'destroy']);

        // Coverage
        Route::get('/coverage', [CoverageController::class, 'index']);
        Route::get('/coverage/next-zone', [CoverageController::class, 'nextZone']);
        Route::post('/coverage/launch', [CoverageController::class, 'launch'])
            ->middleware('throttle:scraper-launch');
        Route::post('/coverage/enrich', [CoverageController::class, 'enrich'])
            ->middleware('throttle:scraper-launch');
        Route::get('/coverage/cells/{cell}', [CoverageController::class, 'showCell']);

        // Scraper runs (Sprint 19.6 : rate limiting per-user)
        Route::get('/scraper-runs', [ScraperRunsController::class, 'index'])
            ->middleware('throttle:scraper-list');
        Route::get('/scraper-runs/{run}', [ScraperRunsController::class, 'show'])
            ->middleware('throttle:scraper-list');
        Route::post('/scraper-runs/{run}/cancel', [ScraperRunsController::class, 'cancel'])
            ->middleware('throttle:scraper-launch');
        Route::post('/scraper-runs/{run}/retry', [ScraperRunsController::class, 'retry'])
            ->middleware('throttle:scraper-launch');

        // LLM
        Route::get('/llm/use-cases', [LlmUseCasesController::class, 'index']);
        Route::put('/llm/use-cases/{useCase}', [LlmUseCasesController::class, 'update']);
        Route::get('/llm/use-cases/{useCase}/prompts', [LlmUseCasesController::class, 'prompts']);
        Route::put('/llm/use-cases/{useCase}/prompts/{v}', [LlmUseCasesController::class, 'updatePrompt']);
        Route::get('/llm/usage', [LlmUsageController::class, 'index']);
        Route::get('/llm/usage/summary', [LlmUsageController::class, 'summary']);

        // Proxies + rotations
        Route::get('/proxy-providers', [ProxyProvidersController::class, 'index']);
        Route::put('/proxy-providers/{p}', [ProxyProvidersController::class, 'update']);
        Route::post('/proxy-providers/{p}/test', [ProxyProvidersController::class, 'test']);
        Route::get('/rotations', [RotationsController::class, 'index']);
        Route::put('/rotations/{rotation}', [RotationsController::class, 'update']);

        // Tags + saved views + global search + notifications
        Route::get('/tags', [TagsController::class, 'index']);
        Route::post('/tags', [TagsController::class, 'store']);
        Route::put('/tags/{tag}', [TagsController::class, 'update']);
        Route::delete('/tags/{tag}', [TagsController::class, 'destroy']);
        Route::apiResource('saved-views', SavedViewsController::class);

        // Audiences (Sprint Pipeline 360°)
        Route::get('/audiences', [AudiencesController::class, 'index']);
        Route::post('/audiences', [AudiencesController::class, 'store']);
        Route::post('/audiences/preview', [AudiencesController::class, 'preview']);
        Route::get('/audiences/{audience}', [AudiencesController::class, 'show']);
        Route::put('/audiences/{audience}', [AudiencesController::class, 'update']);
        Route::delete('/audiences/{audience}', [AudiencesController::class, 'destroy']);
        Route::post('/audiences/{audience}/refresh', [AudiencesController::class, 'refresh']);
        Route::get('/audiences/{audience}/members', [AudiencesController::class, 'members']);

        Route::get('/search', [GlobalSearchController::class, 'index']);
        Route::get('/notifications', [NotificationsController::class, 'index']);
        Route::post('/notifications/{n}/read', [NotificationsController::class, 'markRead']);
        Route::post('/notifications/read-all', [NotificationsController::class, 'markAllRead']);

        // RGPD + AI Act + audit
        Route::get('/rgpd/requests', [RgpdRequestsController::class, 'index']);
        Route::post('/rgpd/requests', [RgpdRequestsController::class, 'store']);
        Route::post('/rgpd/requests/{req}/process', [RgpdRequestsController::class, 'process']);
        Route::get('/rgpd/export/{token}', [RgpdRequestsController::class, 'export']);
        Route::get('/ai-act/register', [AiActRegisterController::class, 'index']);
        Route::post('/ai-act/register', [AiActRegisterController::class, 'store']);
        Route::get('/audit-logs', [AuditLogsController::class, 'index']);
        Route::get('/audit-logs/verify-chain', [AuditLogsController::class, 'verifyChain']);

        // Sprint H4 — Dashboard observabilité (KPI cards + recent events)
        Route::get('/observability/summary', [ObservabilityController::class, 'summary']);

        // --- Scraping Campaigns (Sprint 19.7) ------------------------------
        Route::get('/campaigns', [ScrapingCampaignsController::class, 'index'])
            ->middleware('throttle:scraper-list');
        Route::post('/campaigns', [ScrapingCampaignsController::class, 'store'])
            ->middleware('throttle:scraper-launch');
        Route::get('/campaigns/{campaign}', [ScrapingCampaignsController::class, 'show'])
            ->middleware('throttle:scraper-list');
        Route::put('/campaigns/{campaign}', [ScrapingCampaignsController::class, 'update'])
            ->middleware('throttle:scraper-launch');
        Route::delete('/campaigns/{campaign}', [ScrapingCampaignsController::class, 'destroy'])
            ->middleware('throttle:scraper-launch');
        Route::post('/campaigns/{campaign}/start', [ScrapingCampaignsController::class, 'start'])
            ->middleware('throttle:scraper-launch');
        Route::post('/campaigns/{campaign}/pause', [ScrapingCampaignsController::class, 'pause'])
            ->middleware('throttle:scraper-launch');
        Route::post('/campaigns/{campaign}/resume', [ScrapingCampaignsController::class, 'resume'])
            ->middleware('throttle:scraper-launch');
        Route::post('/campaigns/{campaign}/cancel', [ScrapingCampaignsController::class, 'cancel'])
            ->middleware('throttle:scraper-launch');
        Route::get('/campaigns/{campaign}/stats', [ScrapingCampaignsController::class, 'stats'])
            ->middleware('throttle:scraper-list');

        // --- Lot L6 — Console CRM v2 ---------------------------------------
        //
        // 🔴 ORDRE CRITIQUE : ces routes DOIVENT précéder le stub Phase 2
        // `Route::any('/crm{any?}')` déclaré plus bas, qui capture tout
        // `/v1/crm/*` et répond 501. Laravel résout par ordre de déclaration :
        // les déplacer après reviendrait à livrer une console qui répond
        // « non implémenté » sur chacun de ses appels, drapeau ouvert compris.
        //
        // Drapeau `crm.console_v2` (défaut false) → 404 sur tout ce groupe.
        // `GET /config/features` est délibérément HORS du groupe : c'est lui qui
        // annonce l'état du drapeau, le mettre derrière serait circulaire.
        Route::get('/config/features', [FeaturesController::class, 'index']);

        Route::middleware('crm-console')->prefix('crm')->group(function () {
            Route::get('/contacts-hub', [ContactsHubController::class, 'index']);
            Route::get('/contacts-hub/counts', [ContactsHubController::class, 'counts']);

            Route::get('/candidates', [CandidatesController::class, 'index']);
            Route::get('/candidates/counts', [CandidatesController::class, 'counts']);

            Route::get('/persons/{personKey}/timeline', [PersonTimelineController::class, 'show']);

            // `/arbitrage/{activityId}/…` : les segments fixes précèdent, aucun
            // conflit possible avec un identifiant numérique.
            Route::get('/arbitrage', [ArbitrageController::class, 'index']);
            Route::post('/arbitrage/{activityId}/attach', [ArbitrageController::class, 'attach'])
                ->whereNumber('activityId');
            Route::post('/arbitrage/{activityId}/dismiss', [ArbitrageController::class, 'dismiss'])
                ->whereNumber('activityId');

            Route::post('/bulk', BulkController::class);
        });

        // --- Phase 2 (stubs, retournent 501 Not Implemented) ---------------
        // Note : /campaigns retiré — implémenté en Sprint 19.7 ci-dessus.
        // Note : /crm est PARTIELLEMENT capté au-dessus par la console v2.
        Route::any('/cold-email{any?}', ColdEmailController::class)->where('any', '.*');
        Route::any('/linkedin{any?}', LinkedInController::class)->where('any', '.*');
        Route::any('/crm{any?}', CrmController::class)->where('any', '.*');
        Route::any('/analytics{any?}', AnalyticsController::class)->where('any', '.*');
    });
});

// --- Internal endpoints (HMAC signed, no Sanctum) ---------------------------
Route::prefix('internal')->group(function () {
    Route::post('/scraper-result', [ScraperResultController::class, 'store'])->name('internal.scraper-result');

    // Lot L2 — ingestion des événements du site axion-ia.com. Signée HMAC
    // (même patron que scraper-result) et gatée par CRM_INGEST_ENABLED : tant
    // que le drapeau est à OFF, l'endpoint répond 503 et n'écrit rien.
    Route::post('/site-sync', [SiteSyncController::class, 'store'])
        ->middleware('throttle:internal')
        ->name('internal.site-sync');

    // Lot L4 — volet RGPD du même canal : art. 15/17 en une action sur les
    // deux systèmes. Même HMAC, même drapeau (503 tant que fermé).
    Route::post('/site-sync/gdpr', [SiteGdprController::class, 'store'])
        ->middleware('throttle:internal')
        ->name('internal.site-sync.gdpr');
});
