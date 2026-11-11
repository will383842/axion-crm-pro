<?php

use App\Http\Controllers\Api\EnvoiOppositionExportController;
use App\Http\Controllers\Api\EnvoiPuisJeEcrireController;
use Illuminate\Support\Facades\Route;

// R05b : export du registre (propriétaire ET data.export, 2 par heure) et « Puis-je écrire à… ? » (users.manage, 30 par minute).
// Lecture seule. La page publique de désinscription reste servie par routes/web.php.
Route::get('/envoi/opposition/export', EnvoiOppositionExportController::class)
    ->middleware(['permission:workspaces.manage', 'permission:data.export', 'throttle:envoi-opposition-export']);
Route::get('/envoi/puis-je-ecrire', EnvoiPuisJeEcrireController::class)
    ->middleware(['permission:users.manage', 'throttle:envoi-puis-je-ecrire']);
