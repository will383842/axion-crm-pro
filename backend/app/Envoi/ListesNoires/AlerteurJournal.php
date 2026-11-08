<?php

namespace App\Envoi\ListesNoires;

use App\Envoi\Depot\DepotSocle;
use Illuminate\Support\Facades\Log;

final class AlerteurJournal implements Alerteur
{
    public function __construct(private readonly DepotSocle $depot) {}

    public function alerter(Ressource $r, string $zone, string $reponse): void
    {
        Log::critical('[ENVOI] ' . $r->type . ' ' . $r->valeur . ' listé sur ' . $zone . ' : pause appliquée.');
        $this->depot->journaliser($r->workspaceId, 'liste_noire_pause', 'systeme', ['type' => $r->type, 'ressource' => $r->valeur, 'zone' => $zone, 'reponse' => $reponse]);
    }

    public function alerterNonFiable(Ressource $r, string $raison): void
    {
        Log::critical('[ENVOI] ' . $r->type . ' ' . $r->valeur . ' : contrôle des listes noires NON FIABLE (' . $raison . ') ; bloqué au-delà du délai de tolérance.');
        $this->depot->journaliser($r->workspaceId, 'liste_noire_non_fiable', 'systeme', ['type' => $r->type, 'ressource' => $r->valeur, 'raison' => $raison]);
    }
}
