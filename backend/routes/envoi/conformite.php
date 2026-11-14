<?php

use App\Http\Controllers\Api\EnvoiConformiteController;
use App\Http\Controllers\Api\EnvoiOppositionExportController;
use App\Http\Controllers\Api\EnvoiPuisJeEcrireController;
use Illuminate\Support\Facades\Route;

// R05 : conformité d'envoi en LECTURE (contrat conformite.yaml). Listes paginées, réservées à `users.manage`.
// Aucune écriture ni suppression ; la page publique de désinscription reste servie par routes/web.php.
Route::middleware('permission:users.manage')->group(function () {
    Route::get('/envoi/desinscriptions', [EnvoiConformiteController::class, 'desinscriptions']);
    Route::get('/envoi/opposition', [EnvoiConformiteController::class, 'opposition']);
});

// R05b : export du registre (propriétaire ET data.export, 2 par heure) et « Puis-je écrire à… ? » (users.manage, 30 par minute).
// Lecture seule. « Puis-je écrire » est un POST de LECTURE : l'adresse voyage dans le corps JSON, jamais dans l'URL (journaux du proxy, Sentry).
Route::get('/envoi/opposition/export', EnvoiOppositionExportController::class)
    ->middleware(['permission:workspaces.manage', 'permission:data.export', 'throttle:envoi-opposition-export']);
Route::post('/envoi/puis-je-ecrire', EnvoiPuisJeEcrireController::class)
    ->middleware(['permission:users.manage', 'throttle:envoi-puis-je-ecrire']);
