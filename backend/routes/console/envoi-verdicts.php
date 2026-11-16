<?php

use App\Console\Commands\EnvoiVerdictsRafraichir;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

// Envoi — tâches planifiées du lot « verdicts d'adresse (vérification des adresses avant envoi) ».
// Chargé par routes/console.php, après envoi-orchestration.php et envoi-retention.php.

// VERDICTS-CODE-2 : rafraîchit chaque heure (minute 17) les verdicts des inscriptions échues dans les 3 jours.
// N'envoie rien, aucune connexion SMTP ; sans inscription active, aucune requête DNS. Elle tourne même porte d'envoi
// fermée. Garde de schéma : le déploiement remplace le code AVANT `migrate` (colonne `motif`, table `email_domaines`).
Schedule::command(EnvoiVerdictsRafraichir::SIGNATURE_PLANIFIEE)
    ->hourlyAt(17)
    ->withoutOverlapping(30)
    ->onOneServer()
    ->when(fn (): bool => EnvoiVerdictsRafraichir::schemaPret())
    ->onFailure(function (): void {
        Log::critical('[ENVOI] ' . EnvoiVerdictsRafraichir::SIGNATURE_PLANIFIEE . ' : au moins un espace n\'a pas pu être rafraîchi.');
    });
