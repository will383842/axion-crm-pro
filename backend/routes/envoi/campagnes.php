<?php

use App\Http\Controllers\Envoi\CreationController;
use Illuminate\Support\Facades\Route;

// Routes Envoi : domaine « campagnes ». R11 : annulation de la validation (5 minutes), écriture réservée au propriétaire.
Route::post('/envoi/campagnes/{id}/annuler-validation', [CreationController::class, 'annuler'])->whereUuid('id')->middleware('permission:workspaces.manage');
