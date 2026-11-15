<?php

use App\Console\Commands\EnvoiTentativesArchiver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

// Envoi R06-ARCH (D16) — conservation des tentatives : détail 400 jours, puis agrégat journalier.
// Ne suit PAS `CRM_PURGE_ENABLED` : aucune donnée de personne n'est touchée (donc pas de `when`).
Schedule::command(EnvoiTentativesArchiver::SIGNATURE_PLANIFIEE)
    ->dailyAt('04:50')
    ->withoutOverlapping(60)
    ->onOneServer()
    ->onFailure(function (): void {
        Log::critical('[ENVOI] ' . EnvoiTentativesArchiver::SIGNATURE_PLANIFIEE . ' : au moins un espace n\'a pas pu être archivé.');
    });
