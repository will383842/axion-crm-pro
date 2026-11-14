<?php

use App\Http\Controllers\Api\EnvoiInfrastructureController;
use Illuminate\Support\Facades\Route;

// R03 : infrastructure d'envoi, LECTURE seule (contrat openapi/envoi/infrastructure.yaml). `users.manage` comme /envoi/sante.
// Aucune route d'écriture ici. `{id}` numérique : tout autre format est un 404 (et un id d'un autre espace aussi, via la RLS).
Route::middleware('permission:users.manage')->group(function () {
    Route::get('/envoi/domaines', [EnvoiInfrastructureController::class, 'domaines']);
    Route::get('/envoi/domaines/{id}', [EnvoiInfrastructureController::class, 'domaine'])->whereNumber('id');
    Route::get('/envoi/listes-noires', [EnvoiInfrastructureController::class, 'listesNoires']);
    Route::get('/envoi/serveurs', [EnvoiInfrastructureController::class, 'serveurs']);
    Route::get('/envoi/serveurs/{id}/sante', [EnvoiInfrastructureController::class, 'santeServeur'])->whereNumber('id');
    Route::get('/envoi/configuration', [EnvoiInfrastructureController::class, 'configuration']);
});
