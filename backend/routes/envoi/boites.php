<?php

use App\Http\Controllers\Api\EnvoiBoitesController;
use Illuminate\Support\Facades\Route;

// T-BOITES (D3) : contrat openapi/envoi/infrastructure.yaml. Identifiants de boîte = UUID (tout autre format : 404, sans requête).
Route::middleware('permission:users.manage')->group(function () {
    Route::get('/envoi/boites', [EnvoiBoitesController::class, 'lister']);
    Route::get('/envoi/boites/{id}', [EnvoiBoitesController::class, 'lire'])->whereUuid('id');
    Route::post('/envoi/boites/{id}/suspendre', [EnvoiBoitesController::class, 'suspendre'])->whereUuid('id');
});
Route::post('/envoi/boites/{id}/reactiver', [EnvoiBoitesController::class, 'reactiver'])->whereUuid('id')->middleware('permission:workspaces.manage');

// F3-B1 : ajout par l'écran, propriétaire seul (`workspaces.manage`, 403 sinon), en deux temps (code de confirmation puis création).
Route::middleware('permission:workspaces.manage')->group(function () {
    Route::post('/envoi/boites/ajout/code', [EnvoiBoitesController::class, 'demanderCodeAjout']);
    Route::post('/envoi/boites', [EnvoiBoitesController::class, 'ajouter']);
});

// 501 inconditionnels : aucune validation, aucune liaison, aucune lecture de envoi_boites (D3 bis). Sous `workspaces.manage` comme les écritures R15
// (la garde F36-001 exige une permission sur toute écriture) ; un compte sans ce droit reçoit 403 avant le 501.
Route::middleware('permission:workspaces.manage')->group(function () {
    Route::post('/envoi/boites/test-connexion', [EnvoiBoitesController::class, 'serveurReelRequis']);
    Route::put('/envoi/boites/{id}/reglages', [EnvoiBoitesController::class, 'serveurReelRequis']);
});
