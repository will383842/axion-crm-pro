<?php

namespace Tests\Support;

use App\Crm\Brave\PlafondsBrave;

/**
 * Le quota Brave FACTICE des tests unitaires : même règle que la production
 * (`PlafondsBrave`), compteurs en mémoire — aucune base.
 */
final class QuotaBraveEnMemoire extends PlafondsBrave
{
    /** @var array<string, int> */
    public array $requetes = [];

    /** @var list<string> usages des réservations REFUSÉES, dans l'ordre */
    public array $refus = [];

    public function reserver(string $usage): bool
    {
        if (! $this->autorise($usage, $this->consommees($usage), $this->consommees())) {
            $this->refus[] = $usage;

            return false;
        }
        $this->requetes[$usage] = ($this->requetes[$usage] ?? 0) + 1;

        return true;
    }

    public function consommees(?string $usage = null): int
    {
        return $usage === null ? (int) array_sum($this->requetes) : ($this->requetes[$usage] ?? 0);
    }
}
