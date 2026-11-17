<?php

use App\Console\Commands\EnvoiCampagnesMaterialiser;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

// Envoi X1-L4-C — matérialisation des campagnes programmées et re-étalement du stock, chaque minute.
// PAS de condition « porte ouverte » : ces écritures sont inertes (aucun envoi) et doivent tourner porte fermée,
// sinon le stock arriverait en bloc à l'ouverture. La seule condition : le schéma P2 est en place (déploiement : code avant migrations).
Schedule::command(EnvoiCampagnesMaterialiser::SIGNATURE_PLANIFIEE)
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->when(fn (): bool => EnvoiCampagnesMaterialiser::schemaPret())
    ->onFailure(function (): void {
        Log::critical('[ENVOI] ' . EnvoiCampagnesMaterialiser::SIGNATURE_PLANIFIEE . ' : au moins une campagne en erreur.');
    });
