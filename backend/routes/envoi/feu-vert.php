<?php

use App\Http\Controllers\Api\EnvoiFeuVertController;
use Illuminate\Support\Facades\Route;

// L8-F05 : feu vert (contrat pilotage.yaml). Lecture ; la preuve d'un point manuel exige `workspaces.manage`.
Route::get('/envoi/feu-vert', [EnvoiFeuVertController::class, 'index']);
Route::post('/envoi/feu-vert/{code}/fait', [EnvoiFeuVertController::class, 'fait'])
    ->middleware('permission:workspaces.manage');
// K17-FV (D12) : feu vert final, propriétaire seul. If-Match, 428 et 412 ne valent que pour « accorde » ; « retire » est toujours accepté.
Route::post('/envoi/feu-vert/decision', [EnvoiFeuVertController::class, 'decision'])
    ->middleware('permission:workspaces.manage');
