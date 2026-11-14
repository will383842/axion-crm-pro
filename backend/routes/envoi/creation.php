<?php

use App\Http\Controllers\Api\EnvoiMessagesController;
use App\Http\Controllers\Envoi\CreationController;
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
    Route::put('/envoi/messages/{id}', [EnvoiMessagesController::class, 'nouvelleVersion']);
    Route::post('/envoi/messages/{id}/versions/{numero}/restaurer', [EnvoiMessagesController::class, 'restaurer'])->whereNumber('numero');
    Route::post('/envoi/messages/{id}/versions/{numero}/valider', [EnvoiMessagesController::class, 'valider'])->whereNumber('numero');
    Route::post('/envoi/messages/{id}/archivage', [EnvoiMessagesController::class, 'archivage']);
});

// Routes Envoi : domaine « creation » (lot R11). Lectures et calculs sans effet : users.manage ; écritures : workspaces.manage (owner).
// Aucune route ici n'appelle ServeurEnvoi, Sequenceur ni une boîte d'envoi ; le test d'un brouillon répond 409 (palier 0).
Route::get('/envoi/creation/cibles', [CreationController::class, 'cibles'])->middleware('permission:users.manage');
Route::get('/envoi/creation/boites', [CreationController::class, 'boites'])->middleware('permission:users.manage');
Route::get('/envoi/creation/destinataires-exemples', [CreationController::class, 'destinatairesExemples'])->middleware('permission:users.manage');
Route::get('/envoi/brouillons/{id}', [CreationController::class, 'lire'])->whereUuid('id')->middleware('permission:users.manage');
Route::get('/envoi/brouillons/{id}/controles', [CreationController::class, 'controles'])->whereUuid('id')->middleware('permission:users.manage');
Route::post('/envoi/brouillons/{id}/cible/estimation', [CreationController::class, 'estimation'])->whereUuid('id')->middleware('permission:users.manage');
Route::post('/envoi/brouillons/{id}/apercu', [CreationController::class, 'apercu'])->whereUuid('id')->middleware('permission:users.manage');
Route::post('/envoi/brouillons', [CreationController::class, 'creer'])->middleware('permission:workspaces.manage');
Route::put('/envoi/brouillons/{id}/etapes/{etape}', [CreationController::class, 'etape'])->whereUuid('id')->whereNumber('etape')->middleware('permission:workspaces.manage');
Route::post('/envoi/brouillons/{id}/test', [CreationController::class, 'test'])->whereUuid('id')->middleware('permission:workspaces.manage');
Route::post('/envoi/brouillons/{id}/validation/code', [CreationController::class, 'code'])->whereUuid('id')->middleware('permission:workspaces.manage');
Route::post('/envoi/brouillons/{id}/valider', [CreationController::class, 'valider'])->whereUuid('id')->middleware('permission:workspaces.manage');
