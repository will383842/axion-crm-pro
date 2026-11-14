<?php

use App\Http\Controllers\Api\EnvoiInfrastructureController;
use App\Http\Controllers\Api\EnvoiInfrastructureEcrituresController;
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

// R15 : écritures LIMITÉES, PROPRIÉTAIRE seul (`workspaces.manage` ; l'opérateur reçoit 403). Rien n'écrit dans un DNS ni sur un serveur,
// rien n'envoie. `appliquer`, `revenir` et `serveurs/{id}/test` répondent 409 : fermés tant qu'un ADR de K17 ne les ouvre pas.
Route::middleware('permission:workspaces.manage')->group(function () {
    $e = EnvoiInfrastructureEcrituresController::class;
    Route::middleware('throttle:envoi-infra-ecriture')->group(function () use ($e) {
        Route::post('/envoi/domaines', [$e, 'ajouterDomaine']);
        Route::post('/envoi/domaines/{id}/dmarc', [$e, 'rappelDmarc'])->where('id', '[0-9]{1,18}');
        Route::post('/envoi/serveurs/{id}/adresses-ip', [$e, 'ajouterAdresseIp'])->where('id', '[0-9]{1,18}');
    });
    Route::post('/envoi/domaines/{id}/dns/verifier', [$e, 'verifierDns'])->where('id', '[0-9]{1,18}')->middleware('throttle:envoi-dns-verifier');
    Route::post('/envoi/serveurs/{id}/test', [$e, 'testerServeur'])->where('id', '[0-9]{1,18}');
    Route::post('/envoi/configuration/appliquer', [$e, 'appliquer']);
    Route::post('/envoi/configuration/versions/{numero}/revenir', [$e, 'revenir'])->where('numero', '[0-9]{1,9}');
});
