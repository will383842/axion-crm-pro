<?php

use App\Console\Commands\EnvoiCycle;
use App\Envoi\Orchestration\PorteEnvoiReelle;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

// Envoi L8-ORCH (D9) — chef d'orchestre : un cycle par minute. Tâche NON due tant que la porte d'envoi réel est fermée.
// La commande de péremption des réservations n'est PAS planifiée : `envoi:cycle` en appelle la logique (perimerToutesLesReservations).
Schedule::command(EnvoiCycle::SIGNATURE_PLANIFIEE)
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->when(fn (): bool => app(PorteEnvoiReelle::class)->ouverte())
    ->onFailure(function (): void {
        Log::critical('[ENVOI] ' . EnvoiCycle::SIGNATURE_PLANIFIEE . ' : au moins un espace est en erreur.');
    });
