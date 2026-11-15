<?php

use App\Http\Controllers\Api\EnvoiChauffeController;
use Illuminate\Support\Facades\Route;

// D5 : chauffe (contrat openapi/envoi/infrastructure.yaml). Lecture `users.manage` (comme /envoi/sante) : compteurs seulement,
// aucun appel au serveur d'envoi. Le seul geste est `reculer` (propriétaire seul, `workspaces.manage`) : il va dans le sens sûr,
// il est idempotent pour la même journée, et il n'existe aucune route pour avancer. L'id vaut `ip:<clé>` ou `domaine:<clé>`.
Route::get('/envoi/chauffe', [EnvoiChauffeController::class, 'index'])->middleware('permission:users.manage');
Route::post('/envoi/chauffe/{id}/reculer', [EnvoiChauffeController::class, 'reculer'])
    ->where('id', '(ip|domaine):[A-Za-z0-9._-]{1,100}')->middleware('permission:workspaces.manage');
