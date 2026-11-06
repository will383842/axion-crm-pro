<?php

namespace Tests\Unit\Envoi;

use App\Envoi\Depot\DepotSocle;
use DateTimeImmutable;

final class DepotMemoire implements DepotSocle
{
    public bool $arret = false;

    public bool $temoinsAllumes = true;

    /** @var list<string> */
    public array $temoins = ['temoin@example.test'];

    /** @var array<string, int> */
    public array $compteurs = [];

    /** @var list<array{string, string}> */
    public array $journal = [];

    public function toutArreter(string $workspaceId): bool
    {
        return $this->arret;
    }

    public function modeTemoins(string $workspaceId): bool
    {
        return $this->temoinsAllumes;
    }

    public function estTemoin(string $workspaceId, string $adresse): bool
    {
        return in_array($adresse, $this->temoins, true);
    }

    public function injectesLe(string $workspaceId, DateTimeImmutable $jour): int
    {
        return $this->compteurs[$jour->format('Y-m-d')] ?? 0;
    }

    public function noterInjection(string $workspaceId, DateTimeImmutable $jour): void
    {
        $this->compteurs[$jour->format('Y-m-d')] = $this->injectesLe($workspaceId, $jour) + 1;
    }

    public function eteindreTemoins(string $workspaceId, string $auteur): void
    {
        $this->temoinsAllumes = false;
        $this->journaliser($workspaceId, 'temoins_off', $auteur);
    }

    public function journaliser(string $workspaceId, string $commande, string $auteur, array $details = [], ?DateTimeImmutable $jour = null): void
    {
        $this->journal[] = [$commande, $auteur];
    }
}
