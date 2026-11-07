<?php

use App\Envoi\Desinscription\DesinscriptionController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'env' => config('app.env'),
    'docs' => '/docs',
]));

// Envoi P0 L5 : désinscription publique. Sans session, sans cookie, sans jeton CSRF :
// le jeton signé de l'URL est l'autorisation (le POST « one-click » RFC 8058 vient d'un client de messagerie).
Route::middleware([])->withoutMiddleware([
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    ValidateCsrfToken::class,
])->group(function () {
    Route::get('/desinscription/{jeton}', [DesinscriptionController::class, 'page'])
        ->name('envoi.desinscription.page');
    Route::post('/desinscription/{jeton}', [DesinscriptionController::class, 'confirmer'])
        ->name('envoi.desinscription.confirmer');
});
