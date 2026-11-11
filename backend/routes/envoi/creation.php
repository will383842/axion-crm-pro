<?php

use App\Http\Controllers\Api\EnvoiMessagesController;
use Illuminate\Support\Facades\Route;

// R10 : messages types et outils de contenu (contrat creation.yaml). Lecture et outils : `users.manage` ; écriture : `workspaces.manage`.
// Aucune route ne fait partir un message : `test` répond 409, `suggestion` 501. Pas de DELETE : un message n'est jamais supprimé.
// Les routes fixes (controler, apercu, test, suggestion) passent AVANT `/envoi/messages/{id}`.
Route::middleware('permission:users.manage')->group(function () {
    Route::get('/envoi/messages', [EnvoiMessagesController::class, 'index']);
    Route::get('/envoi/creation/contexte', [EnvoiMessagesController::class, 'contexte']);
    Route::post('/envoi/creation/champs', [EnvoiMessagesController::class, 'champs']);
    Route::post('/envoi/messages/controler', [EnvoiMessagesController::class, 'controler']);
    Route::post('/envoi/messages/apercu', [EnvoiMessagesController::class, 'apercu']);
    Route::post('/envoi/messages/test', [EnvoiMessagesController::class, 'test']);
    Route::post('/envoi/messages/suggestion', [EnvoiMessagesController::class, 'suggestion']);
    Route::get('/envoi/messages/{id}', [EnvoiMessagesController::class, 'show']);
});
Route::middleware('permission:workspaces.manage')->group(function () {
    Route::post('/envoi/messages', [EnvoiMessagesController::class, 'store']);
    Route::put('/envoi/messages/{id}', [EnvoiMessagesController::class, 'update']);
    Route::post('/envoi/messages/{id}/versions/{numero}/restaurer', [EnvoiMessagesController::class, 'restaurer'])->whereNumber('numero');
    Route::post('/envoi/messages/{id}/versions/{numero}/valider', [EnvoiMessagesController::class, 'valider'])->whereNumber('numero');
    Route::post('/envoi/messages/{id}/archivage', [EnvoiMessagesController::class, 'archivage']);
});
