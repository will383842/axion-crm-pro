<?php

use App\Http\Controllers\Api\ProspectionLinkedInController;
use Illuminate\Support\Facades\Route;

// PONT-LINKEDIN LI-C : API de la file « prêt à cliquer » (voie S). Requis depuis routes/api.php dans le groupe v1 authentifié.
// Propriétaire seul (middleware de route, comme l'opposition manuelle) ; drapeau éteint ⇒ 404 gardé par le contrôleur, avant
// tout accès à la base du module. AUCUNE route de suppression.
Route::prefix('prospection-linkedin')->middleware('permission:workspaces.manage')->group(function () {
    Route::get('/file', [ProspectionLinkedInController::class, 'file']);
    Route::post('/prospects', [ProspectionLinkedInController::class, 'ajouter']);
    Route::post('/prospects/{id}/gestes', [ProspectionLinkedInController::class, 'geste']);
    Route::post('/arret', [ProspectionLinkedInController::class, 'arret']);
    Route::post('/reprise', [ProspectionLinkedInController::class, 'reprise']);
    Route::post('/plafonds', [ProspectionLinkedInController::class, 'plafonds']);
});
