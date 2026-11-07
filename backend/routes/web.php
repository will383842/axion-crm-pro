<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'env' => config('app.env'),
    'docs' => '/docs',
]));

// Envoi P0 L5 : désinscription publique. Sans session, sans cookie, sans jeton CSRF :
// le jeton signé de l'URL est l'autorisation (le POST « one-click » RFC 8058 vient d'un client de messagerie).
Route::middleware([])->withoutMiddleware([
    \Illuminate\Cookie\Middleware\EncryptCookies::class,
    \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
])->group(function () {
    Route::get('/desinscription/{jeton}', [\App\Envoi\Desinscription\DesinscriptionController::class, 'page'])
        ->name('envoi.desinscription.page');
    Route::post('/desinscription/{jeton}', [\App\Envoi\Desinscription\DesinscriptionController::class, 'confirmer'])
        ->name('envoi.desinscription.confirmer');
});
