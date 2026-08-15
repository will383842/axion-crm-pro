<?php

use App\Http\Middleware\AuditHashChainLogger;
use App\Http\Middleware\EnforceFirstLoginSetup;
use App\Http\Middleware\EnsureCrmConsoleV2;
use App\Http\Middleware\SetCurrentWorkspace;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;

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
            // §2.10 du plan — les exports de données sont réservés aux
            // détenteurs de la permission `data.export` (owner, admin,
            // opérateur ; PAS viewer). Sans cette garde, n'importe quel compte
            // authentifié pouvait exporter les 4,29 M de fiches en CSV.
            'permission' => PermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
