<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\TwoFactorController;
use App\Http\Controllers\Api\Auth\MagicLinkController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\CompaniesController;
use App\Http\Controllers\Api\ContactsController;
use App\Http\Controllers\Api\CoverageController;
use App\Http\Controllers\Api\ScraperRunsController;
use App\Http\Controllers\Api\LlmUseCasesController;
use App\Http\Controllers\Api\LlmUsageController;
use App\Http\Controllers\Api\ProxyProvidersController;
use App\Http\Controllers\Api\RotationsController;
use App\Http\Controllers\Api\AuditLogsController;
use App\Http\Controllers\Api\RgpdRequestsController;
use App\Http\Controllers\Api\AiActRegisterController;
use App\Http\Controllers\Api\TagsController;
use App\Http\Controllers\Api\WorkspaceController;
use App\Http\Controllers\Api\UsersController;
use App\Http\Controllers\Api\NotificationsController;
use App\Http\Controllers\Api\SavedViewsController;
use App\Http\Controllers\Api\GlobalSearchController;
use App\Http\Controllers\Api\Phase2\CampaignsController;
use App\Http\Controllers\Api\Phase2\ColdEmailController;
use App\Http\Controllers\Api\Phase2\LinkedInController;
use App\Http\Controllers\Api\Phase2\CrmController;
use App\Http\Controllers\Api\Phase2\AnalyticsController;
use App\Http\Controllers\Internal\ScraperResultController;

/*
|--------------------------------------------------------------------------
| API v1 routes (Sanctum SPA cookie-based)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // --- Auth (non protégé, throttle anti brute-force) ----------------------
    Route::post('/auth/login',             [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/logout',            [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get( '/auth/me',                [AuthController::class, 'me'])->middleware('auth:sanctum');
    Route::post('/auth/2fa/verify',        [TwoFactorController::class, 'verify'])->middleware('throttle:login');
    Route::post('/auth/2fa/setup',         [TwoFactorController::class, 'setup'])->middleware('auth:sanctum');
    Route::post('/auth/2fa/confirm',       [TwoFactorController::class, 'confirm'])->middleware('auth:sanctum');
    Route::post('/auth/magic-link',        [MagicLinkController::class, 'request'])->middleware('throttle:magic-link');
    Route::post('/auth/magic-link/verify', [MagicLinkController::class, 'verify'])->middleware('throttle:magic-link');
    Route::post('/auth/password/forgot',   [PasswordResetController::class, 'forgot'])->middleware('throttle:magic-link');
    Route::post('/auth/password/reset',    [PasswordResetController::class, 'reset'])->middleware('throttle:magic-link');

    // --- Routes protégées -------------------------------------------------
    Route::middleware(['auth:sanctum', 'workspace', 'first-login'])->group(function () {

        // Workspace + users
        Route::get( '/workspace',          [WorkspaceController::class, 'show']);
        Route::put( '/workspace',          [WorkspaceController::class, 'update']);
        Route::get( '/users',              [UsersController::class, 'index']);
        Route::post('/users',              [UsersController::class, 'store']);
        Route::put( '/users/{user}',       [UsersController::class, 'update']);
        Route::delete('/users/{user}',     [UsersController::class, 'destroy']);

        // Companies
        Route::get(   '/companies',                       [CompaniesController::class, 'index']);
        Route::post(  '/companies',                       [CompaniesController::class, 'store']);
        Route::get(   '/companies/{company}',             [CompaniesController::class, 'show']);
        Route::put(   '/companies/{company}',             [CompaniesController::class, 'update']);
        Route::delete('/companies/{company}',             [CompaniesController::class, 'destroy']);
        Route::post(  '/companies/{company}/enrich',      [CompaniesController::class, 'enrich']);
        Route::post(  '/companies/bulk-enrich',           [CompaniesController::class, 'bulkEnrich']);
        Route::post(  '/companies/{company}/recompute-score', [CompaniesController::class, 'recomputeScore']);

        // Contacts
        Route::get(   '/contacts',           [ContactsController::class, 'index']);
        Route::get(   '/contacts/{contact}', [ContactsController::class, 'show']);
        Route::put(   '/contacts/{contact}', [ContactsController::class, 'update']);
        Route::delete('/contacts/{contact}', [ContactsController::class, 'destroy']);

        // Coverage
        Route::get( '/coverage',                   [CoverageController::class, 'index']);
        Route::get( '/coverage/next-zone',         [CoverageController::class, 'nextZone']);
        Route::post('/coverage/launch',            [CoverageController::class, 'launch']);
        Route::get( '/coverage/cells/{cell}',      [CoverageController::class, 'showCell']);

        // Scraper runs
        Route::get( '/scraper-runs',                [ScraperRunsController::class, 'index']);
        Route::get( '/scraper-runs/{run}',          [ScraperRunsController::class, 'show']);
        Route::post('/scraper-runs/{run}/cancel',   [ScraperRunsController::class, 'cancel']);
        Route::post('/scraper-runs/{run}/retry',    [ScraperRunsController::class, 'retry']);

        // LLM
        Route::get( '/llm/use-cases',                       [LlmUseCasesController::class, 'index']);
        Route::put( '/llm/use-cases/{useCase}',             [LlmUseCasesController::class, 'update']);
        Route::get( '/llm/use-cases/{useCase}/prompts',     [LlmUseCasesController::class, 'prompts']);
        Route::put( '/llm/use-cases/{useCase}/prompts/{v}', [LlmUseCasesController::class, 'updatePrompt']);
        Route::get( '/llm/usage',                           [LlmUsageController::class, 'index']);
        Route::get( '/llm/usage/summary',                   [LlmUsageController::class, 'summary']);

        // Proxies + rotations
        Route::get( '/proxy-providers',         [ProxyProvidersController::class, 'index']);
        Route::put( '/proxy-providers/{p}',     [ProxyProvidersController::class, 'update']);
        Route::post('/proxy-providers/{p}/test',[ProxyProvidersController::class, 'test']);
        Route::get( '/rotations',               [RotationsController::class, 'index']);
        Route::put( '/rotations/{rotation}',    [RotationsController::class, 'update']);

        // Tags + saved views + global search + notifications
        Route::get( '/tags',                [TagsController::class, 'index']);
        Route::post('/tags',                [TagsController::class, 'store']);
        Route::put( '/tags/{tag}',          [TagsController::class, 'update']);
        Route::delete('/tags/{tag}',        [TagsController::class, 'destroy']);
        Route::apiResource('saved-views',   SavedViewsController::class);
        Route::get( '/search',              [GlobalSearchController::class, 'index']);
        Route::get( '/notifications',       [NotificationsController::class, 'index']);
        Route::post('/notifications/{n}/read', [NotificationsController::class, 'markRead']);
        Route::post('/notifications/read-all', [NotificationsController::class, 'markAllRead']);

        // RGPD + AI Act + audit
        Route::get( '/rgpd/requests',                 [RgpdRequestsController::class, 'index']);
        Route::post('/rgpd/requests',                 [RgpdRequestsController::class, 'store']);
        Route::post('/rgpd/requests/{req}/process',   [RgpdRequestsController::class, 'process']);
        Route::get( '/rgpd/export/{token}',           [RgpdRequestsController::class, 'export']);
        Route::get( '/ai-act/register',               [AiActRegisterController::class, 'index']);
        Route::post('/ai-act/register',               [AiActRegisterController::class, 'store']);
        Route::get( '/audit-logs',                    [AuditLogsController::class, 'index']);
        Route::get( '/audit-logs/verify-chain',       [AuditLogsController::class, 'verifyChain']);

        // --- Phase 2 (stubs, retournent 501 Not Implemented) ---------------
        Route::any('/campaigns{any?}',   CampaignsController::class)->where('any', '.*');
        Route::any('/cold-email{any?}',  ColdEmailController::class)->where('any', '.*');
        Route::any('/linkedin{any?}',    LinkedInController::class)->where('any', '.*');
        Route::any('/crm{any?}',         CrmController::class)->where('any', '.*');
        Route::any('/analytics{any?}',   AnalyticsController::class)->where('any', '.*');
    });
});

// --- Internal endpoints (HMAC signed, no Sanctum) ---------------------------
Route::prefix('internal')->group(function () {
    Route::post('/scraper-result', [ScraperResultController::class, 'store'])->name('internal.scraper-result');
});
