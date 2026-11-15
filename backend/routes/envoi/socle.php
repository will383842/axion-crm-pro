<?php

use App\Http\Controllers\Api\EnvoiSocleController;
use Illuminate\Support\Facades\Route;

// T-SOCLE (D1) : état global et compteurs de l'en-tête (lecture, tout rôle de l'espace), arrêt général (`users.manage`).
// Il n'existe aucune route pour lever l'arrêt : la reprise est une commande locale journalisée.
Route::get('/envoi/etat', [EnvoiSocleController::class, 'etat']);
Route::get('/envoi/compteurs', [EnvoiSocleController::class, 'compteurs']);
Route::post('/envoi/arret-general', [EnvoiSocleController::class, 'arretGeneral'])->middleware('permission:users.manage');
