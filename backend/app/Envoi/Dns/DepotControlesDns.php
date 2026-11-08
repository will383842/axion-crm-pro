<?php

namespace App\Envoi\Dns;

use DateTimeImmutable;

/** Ajouts seulement : un contrôle de plus, une date de vérification posée. Rien ne s'efface. */
interface DepotControlesDns
{
    public function enregistrer(string $workspaceId, RapportControle $rapport, DateTimeImmutable $quand): void;

    /** Pose les dates SPF/DKIM/DMARC lues par la porte d'envoi (EnvoiAutorise). */
    public function marquerVerifie(string $workspaceId, string $domaine, DateTimeImmutable $quand): void;
}
