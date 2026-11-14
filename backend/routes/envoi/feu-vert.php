<?php

use App\Http\Controllers\Api\EnvoiFeuVertController;
use Illuminate\Support\Facades\Route;

// L8-F05 : feu vert (contrat pilotage.yaml). Lecture ; la preuve d'un point manuel exige `workspaces.manage`.
Route::get('/envoi/feu-vert', [EnvoiFeuVertController::class, 'index']);
Route::post('/envoi/feu-vert/{code}/fait', [EnvoiFeuVertController::class, 'fait'])
    ->middleware('permission:workspaces.manage');
