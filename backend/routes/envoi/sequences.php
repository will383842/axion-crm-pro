<?php

use App\Http\Controllers\Api\EnvoiSequencesController;
use Illuminate\Support\Facades\Route;

// T-SEQ (D6) : séquences (contrat sequences.yaml). Lecture et pause : `users.manage` (owner, admin ; operator et viewer : 403).
// Reprise : `workspaces.manage` (owner seul). Aucune URI ni méthode ne porte « reprendre » ; rien n'envoie, rien ne résout ServeurEnvoi.
Route::middleware('permission:users.manage')->group(function () {
    Route::get('/envoi/sequences', [EnvoiSequencesController::class, 'index']);
    Route::get('/envoi/sequences/{id}', [EnvoiSequencesController::class, 'show'])->whereUuid('id');
    Route::post('/envoi/sequences/{id}/pause', [EnvoiSequencesController::class, 'pause'])->whereUuid('id');
});
Route::middleware('permission:workspaces.manage')->group(function () {
    Route::post('/envoi/sequences/{id}/reprise', [EnvoiSequencesController::class, 'reprise'])->whereUuid('id');
});
