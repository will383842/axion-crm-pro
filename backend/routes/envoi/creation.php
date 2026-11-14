<?php

use App\Http\Controllers\Envoi\CreationController;
use Illuminate\Support\Facades\Route;

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
