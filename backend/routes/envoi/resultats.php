<?php

use App\Http\Controllers\Api\EnvoiResultatsController;
use Illuminate\Support\Facades\Route;

// R04 : résultats, courbe et recherche d'un envoi. LECTURE SEULE, réservée à `users.manage` (owner, admin).
Route::middleware('permission:users.manage')->group(function () {
    Route::get('/envoi/resultats', [EnvoiResultatsController::class, 'index']);
    Route::get('/envoi/resultats/courbe', [EnvoiResultatsController::class, 'courbe']);
    // 10 recherches par minute et par compte (une adresse de plus = une donnée personnelle de plus exposée).
    Route::get('/envoi/envois/chercher', [EnvoiResultatsController::class, 'chercher'])->middleware('throttle:envoi-chercher');
});
