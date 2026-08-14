<?php

use App\Http\Middleware\AuditHashChainLogger;
use App\Http\Middleware\EnforceFirstLoginSetup;
use App\Http\Middleware\EnsureCrmConsoleV2;
use App\Http\Middleware\SetCurrentWorkspace;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        channels: __DIR__ . '/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // statefulApi() prepend EnsureFrontendRequestsAreStateful sur les routes api/* déjà.
        // Ne pas le réajouter manuellement sur web (double-bind = double-exec).
        $middleware->statefulApi();

        $middleware->api(append: [
            SetCurrentWorkspace::class,
            EnforceFirstLoginSetup::class,
            AuditHashChainLogger::class,
        ]);

        $middleware->alias([
            'workspace' => SetCurrentWorkspace::class,
            'first-login' => EnforceFirstLoginSetup::class,
            'audit' => AuditHashChainLogger::class,
            // Lot L6 : drapeau de la console CRM v2 (404 tant qu'il est fermé).
            'crm-console' => EnsureCrmConsoleV2::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
