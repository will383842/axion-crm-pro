<?php

use App\Console\Commands\EnvoiRetraitsEnvoyer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

// Envoi — tâches planifiées du lot « transport (acheminement vers le serveur d'envoi) ».
// Chargé par routes/console.php, après envoi-orchestration.php et envoi-retention.php.

// C3-3 : envoie chaque minute les demandes de retrait nées des oppositions (cible : retrait en moins de 5 minutes).
// Interrupteurs d'envoi éteints : aucune connexion, les demandes restent en attente. Garde de schéma : le déploiement remplace
// le code AVANT `migrate` (table `envoi_retraits_demandes`, C3-0).
Schedule::command(EnvoiRetraitsEnvoyer::SIGNATURE_PLANIFIEE)
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->when(fn (): bool => Schema::hasTable('envoi_retraits_demandes'))
    ->onFailure(function (): void {
        Log::critical('[ENVOI] ' . EnvoiRetraitsEnvoyer::SIGNATURE_PLANIFIEE . ' : échec.');
    });
