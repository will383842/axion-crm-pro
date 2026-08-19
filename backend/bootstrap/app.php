<?php

use App\Http\Middleware\AuditHashChainLogger;
use App\Http\Middleware\EnforceFirstLoginSetup;
use App\Http\Middleware\EnsureCrmConsoleV2;
use App\Http\Middleware\EnsureTwoFactorPassed;
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
        // (A) CETTE API N'A PAS D'ECRAN DE CONNEXION COTE SERVEUR.
        //
        // Laravel 12 pose LUI-MEME, dans ApplicationBuilder::withMiddleware(),
        // un `redirectGuestsTo(fn () => route('login'))` inconditionnel, AVANT
        // d'executer ce rappel. Aucune route nommee `login` n'existe ici
        // (routes/web.php ne declare que `/`), si bien que toute requete non
        // authentifiee qui n'annonce pas attendre du JSON partait en
        // `RouteNotFoundException: Route [login] not defined` -> HTTP 500, en
        // production comprise, avec 8 475 octets de trace par requete.
        // Mesure : 500 pour navigateur/curl/sans Accept, 401 pour le SPA
        // (audit 360, A-001 / F35-001). Rendre `null` supprime la redirection ;
        // la redirection vers /login est portee par le SPA, pas par l'API.
        $middleware->redirectGuestsTo(fn () => null);

        // statefulApi() prepend EnsureFrontendRequestsAreStateful sur les routes api/* déjà.
        // Ne pas le réajouter manuellement sur web (double-bind = double-exec).
        $middleware->statefulApi();

        $middleware->api(append: [
            SetCurrentWorkspace::class,
            EnforceFirstLoginSetup::class,
            EnsureTwoFactorPassed::class,
            AuditHashChainLogger::class,
        ]);

        $middleware->alias([
            'workspace' => SetCurrentWorkspace::class,
            'first-login' => EnforceFirstLoginSetup::class,
            // §F35-003 — la double authentification doit etre EXIGEE, pas seulement
            // proposee par l'ecran. Cf. EnsureTwoFactorPassed.
            'two-factor' => EnsureTwoFactorPassed::class,
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
        // (B) TOUT CE QUI VIT SOUS /api REPOND EN JSON, quel que soit l'en-tete
        // `Accept` du client.
        //
        // Sans cela, le gestionnaire d'exceptions de Laravel repond a une requete
        // non-JSON par `redirect()->guest($e->redirectTo() ?? route('login'))` --
        // et rappelle donc `route('login')`, qui n'existe pas. Corriger (A) sans
        // (B) ne suffit PAS : mesure a quatre etats, seule la combinaison des deux
        // fait passer la reponse de 500 a 401 (audit 360, F35-001).
        //
        // Un 401 est un contrat. Un 500 dit « le serveur est casse » a un client
        // qui a simplement oublie de se connecter.
        $exceptions->shouldRenderJsonWhen(
            fn ($request, $e) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
