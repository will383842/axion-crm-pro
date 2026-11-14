<?php

use App\Http\Controllers\Api\EnvoiReponsesController;
use Illuminate\Support\Facades\Route;

// R12 : réponses (contrat reponses.yaml). Lecture `users.manage` ; gestes `workspaces.manage` (owner seul).
// « raccourcis » avant « {id} ». `repondre` répond toujours 409 `envoi_ferme_palier_0` au palier 0.
Route::middleware('permission:users.manage')->group(function () {
    Route::get('/envoi/reponses', [EnvoiReponsesController::class, 'index']);
    Route::get('/envoi/reponses/raccourcis', [EnvoiReponsesController::class, 'raccourcis']);
    Route::get('/envoi/reponses/{id}', [EnvoiReponsesController::class, 'show']);
});
Route::middleware('permission:workspaces.manage')->group(function () {
    Route::post('/envoi/reponses/{id}/classer', [EnvoiReponsesController::class, 'classer']);
    Route::post('/envoi/reponses/{id}/rattacher', [EnvoiReponsesController::class, 'rattacher']);
    Route::post('/envoi/reponses/{id}/creer-fiche', [EnvoiReponsesController::class, 'creerFiche']);
    Route::post('/envoi/reponses/{id}/repondre', [EnvoiReponsesController::class, 'repondre']);
});
