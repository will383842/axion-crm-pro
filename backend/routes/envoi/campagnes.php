<?php

use App\Http\Controllers\Api\EnvoiCampagnesController;
use Illuminate\Support\Facades\Route;

// R09 : suivi et gestes des campagnes (contrat campagnes.yaml). Lecture `users.manage` (owner, admin) ; gestes `workspaces.manage` (owner seul).
// Aucune URI ne porte « temoin », « tout-arreter », « reprendre » ni « armer ». R11 y ajoutera `annuler-validation` (série R09 puis R11).
Route::middleware('permission:users.manage')->group(function () {
    Route::get('/envoi/campagnes', [EnvoiCampagnesController::class, 'index']);
    Route::get('/envoi/campagnes/calendrier', [EnvoiCampagnesController::class, 'calendrier']);
    Route::get('/envoi/campagnes/{id}', [EnvoiCampagnesController::class, 'show'])->whereUuid('id');
    Route::get('/envoi/campagnes/{id}/destinataires', [EnvoiCampagnesController::class, 'destinataires'])->whereUuid('id');
});
Route::middleware('permission:workspaces.manage')->group(function () {
    foreach (['pause', 'reprise', 'terminer', 'dupliquer', 'date'] as $geste) {
        Route::post("/envoi/campagnes/{id}/{$geste}", [EnvoiCampagnesController::class, $geste])->whereUuid('id');
    }
});
