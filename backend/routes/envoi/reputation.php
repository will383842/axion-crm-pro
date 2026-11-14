<?php

use App\Http\Controllers\Api\EnvoiReputationController;
use Illuminate\Support\Facades\Route;

// R17 : réputation (contrat reputation.yaml). Lecture `users.manage` ; saisie d'un constat `workspaces.manage` (owner seul).
// Un constat « mauvaise » peut freiner, aucun ne lève une pause. Aucune route n'envoie ni n'écrit `envoi_drapeaux`.
Route::get('/envoi/reputation', [EnvoiReputationController::class, 'index'])
    ->middleware('permission:users.manage');
Route::post('/envoi/reputation/constats', [EnvoiReputationController::class, 'store'])
    ->middleware('permission:workspaces.manage');
