<?php

use App\Http\Controllers\Api\EnvoiAlertesController;
use App\Http\Controllers\Api\EnvoiReglagesController;
use App\Http\Controllers\Api\NotificationsController;
use Illuminate\Support\Facades\Route;

// R14a : alertes d'envoi et règles d'alerte. Lecture `users.manage` ; écriture sensible `workspaces.manage` (owner seul).
// Aucune route ici n'écrit envoi_pauses ni envoi_drapeaux, ni ne résout ServeurEnvoi.
Route::middleware('permission:users.manage')->group(function () {
    Route::get('/envoi/alertes', [EnvoiAlertesController::class, 'index']);
    Route::get('/envoi/reglages/alertes', [EnvoiAlertesController::class, 'regles']);
});
Route::middleware('permission:workspaces.manage')->group(function () {
    Route::post('/envoi/alertes/{id}/regler', [EnvoiAlertesController::class, 'regler']);
    Route::put('/envoi/reglages/alertes', [EnvoiAlertesController::class, 'enregistrer']);
    // Réutilise la cloche + l'e-mail du Socle (déjà limité à 6 par minute) : ne fait partir aucun message de prospection.
    Route::post('/envoi/reglages/alertes/test', [NotificationsController::class, 'testerAlerte'])->middleware('throttle:6,1');
});

// R14b : réglages (on ne peut que durcir), identité, absence, connexion CRM interne, carnet (501).
Route::middleware('permission:users.manage')->group(function () {
    Route::get('/envoi/reglages', [EnvoiReglagesController::class, 'reglages']);
    Route::get('/envoi/reglages/regles', [EnvoiReglagesController::class, 'regles']);
    Route::get('/envoi/reglages/apercu', [EnvoiReglagesController::class, 'apercu']);
    Route::get('/envoi/reglages/identite', [EnvoiReglagesController::class, 'identite']);
    Route::get('/envoi/reglages/connexion-crm', [EnvoiReglagesController::class, 'connexionCrm']);
    Route::get('/envoi/carnet', [EnvoiReglagesController::class, 'carnet']);
});
Route::middleware('permission:workspaces.manage')->group(function () {
    Route::put('/envoi/reglages', [EnvoiReglagesController::class, 'enregistrerReglages']);
    Route::put('/envoi/reglages/regles', [EnvoiReglagesController::class, 'enregistrerRegles']);
    Route::put('/envoi/reglages/absence', [EnvoiReglagesController::class, 'absence']);
    Route::put('/envoi/reglages/identite', [EnvoiReglagesController::class, 'enregistrerIdentite']);
    Route::post('/envoi/reglages/connexion-crm/tester', [EnvoiReglagesController::class, 'connexionCrm']);
    Route::post('/envoi/carnet', [EnvoiReglagesController::class, 'carnet']);
});
