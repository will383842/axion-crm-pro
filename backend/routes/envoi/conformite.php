<?php

use App\Http\Controllers\Api\EnvoiConformiteController;
use Illuminate\Support\Facades\Route;

// R05 : conformité d'envoi en LECTURE (contrat conformite.yaml). Listes paginées, réservées à `users.manage`.
// Aucune écriture ni suppression ; la page publique de désinscription reste servie par routes/web.php.
Route::middleware('permission:users.manage')->group(function () {
    Route::get('/envoi/desinscriptions', [EnvoiConformiteController::class, 'desinscriptions']);
    Route::get('/envoi/opposition', [EnvoiConformiteController::class, 'opposition']);
});
