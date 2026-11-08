<?php

namespace App\Envoi\Dns;

use DateTimeImmutable;

/** Rapport DMARC agrégé reçu, déjà lu. Comptes seulement : aucune donnée de personne. */
final class RapportDmarcAgrege
{
    /** @param list<array{ip: string, nombre: int, disposition: string, dkim: string, spf: string}> $lignes */
    public function __construct(
        public readonly string $emetteur,
        public readonly string $identifiant,
        public readonly string $domaine,
        public readonly DateTimeImmutable $debut,
        public readonly DateTimeImmutable $fin,
        public readonly array $lignes,
    ) {}

    public function total(): int
    {
        return array_sum(array_column($this->lignes, 'nombre'));
    }

    /** Aligné = DKIM OU SPF réussi. */
    public function alignes(): int
    {
        return $this->total() - $this->enEchec();
    }

    /** Échec complet : ni DKIM ni SPF. */
    public function enEchec(): int
    {
        $n = 0;
        foreach ($this->lignes as $l) {
            if ($l['dkim'] !== 'pass' && $l['spf'] !== 'pass') {
                $n += $l['nombre'];
            }
        }

        return $n;
    }

    public function tauxEchec(): float
    {
        return $this->total() === 0 ? 0.0 : round($this->enEchec() / $this->total(), 4);
    }

    /** @return array<string, int> ip => messages en échec complet */
    public function sourcesEnEchec(): array
    {
        $s = [];
        foreach ($this->lignes as $l) {
            if ($l['dkim'] !== 'pass' && $l['spf'] !== 'pass') {
                $s[$l['ip']] = ($s[$l['ip']] ?? 0) + $l['nombre'];
            }
        }

        return $s;
    }
}
