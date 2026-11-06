<?php

namespace App\Envoi\Depot;

use DateTimeImmutable;

/** État local du socle d'envoi, par workspace. Ajout seulement : rien ne s'efface. */
interface DepotSocle
{
    public function toutArreter(string $workspaceId): bool;

    public function modeTemoins(string $workspaceId): bool;

    public function estTemoin(string $workspaceId, string $adresse): bool;

    public function injectesLe(string $workspaceId, DateTimeImmutable $jour): int;

    public function noterInjection(string $workspaceId, DateTimeImmutable $jour): void;

    /** Commande locale uniquement (artisan). */
    public function eteindreTemoins(string $workspaceId, string $auteur): void;

    /** @param array<string, mixed> $details */
    public function journaliser(string $workspaceId, string $commande, string $auteur, array $details = [], ?DateTimeImmutable $jour = null): void;
}
