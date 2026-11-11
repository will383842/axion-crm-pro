<?php

use App\Http\Controllers\Api\EnvoiOppositionExportController;
use App\Http\Controllers\Api\EnvoiPuisJeEcrireController;
use Illuminate\Support\Facades\Route;

// R05b : export du registre (propriétaire ET data.export, 2 par heure) et « Puis-je écrire à… ? » (users.manage, 30 par minute).
// Lecture seule. « Puis-je écrire » est un POST de LECTURE : l'adresse voyage dans le corps JSON, jamais dans l'URL (journaux du proxy, Sentry). La page publique de désinscription reste servie par routes/web.php.
Route::get('/envoi/opposition/export', EnvoiOppositionExportController::class)
    ->middleware(['permission:workspaces.manage', 'permission:data.export', 'throttle:envoi-opposition-export']);
Route::post('/envoi/puis-je-ecrire', EnvoiPuisJeEcrireController::class)
    ->middleware(['permission:users.manage', 'throttle:envoi-puis-je-ecrire']);
