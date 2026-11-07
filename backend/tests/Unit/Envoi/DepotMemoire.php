<?php

namespace Tests\Unit\Envoi;

use App\Envoi\Depot\DepotSocle;
use App\Envoi\EmpreinteTemoin;
use Closure;
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
        return in_array(EmpreinteTemoin::normaliser($adresse), $this->temoins, true);
    }

    public function injectesLe(string $workspaceId, DateTimeImmutable $jour): int
    {
        return $this->compteurs[$jour->format('Y-m-d')] ?? 0;
    }

    /** @var list<string> */
    public array $cles = [];

    public int $verrous = 0;

    public function injectesLeGlobal(DateTimeImmutable $jour): int
    {
        return $this->compteurs[$jour->format('Y-m-d')] ?? 0;
    }

    public function dejaInjecte(string $workspaceId, string $cleIdempotence): bool
    {
        return in_array($cleIdempotence, $this->cles, true);
    }

    public function sousVerrouInjection(DateTimeImmutable $jour, Closure $f): mixed
    {
        $this->verrous++;

        return $f();
    }

    public function noterInjection(string $workspaceId, DateTimeImmutable $jour, string $cleIdempotence): void
    {
        if ($this->dejaInjecte($workspaceId, $cleIdempotence)) {
            return;
        }
        $this->cles[] = $cleIdempotence;
        $this->compteurs[$jour->format('Y-m-d')] = $this->injectesLe($workspaceId, $jour) + 1;
    }

    public function armerToutArreter(string $workspaceId, string $auteur): void
    {
        $this->arret = true;
        $this->journaliser($workspaceId, 'tout_arreter', $auteur);
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
