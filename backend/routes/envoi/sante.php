<?php

use App\Http\Controllers\Api\EnvoiSanteController;
use Illuminate\Support\Facades\Route;

// K15 : écran « Santé des envois » (lecture seule).
// Elle rend domaines d'envoi, listes noires, pauses et état du serveur : réservée à `users.manage` (owner, admin ;
// `operator` et `viewer` : 403). `workspaces.manage` exclurait l'admin ; une permission dédiée exigerait une migration de rôles.
Route::get('/envoi/sante', [EnvoiSanteController::class, 'index'])
    ->middleware('permission:users.manage');
