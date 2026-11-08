<?php

namespace App\Envoi\Dns;

final class RapportControle
{
    /** @param list<PointControle> $resultats */
    public function __construct(
        public readonly string $domaine,
        public readonly array $resultats,
    ) {}

    public function statutDe(string $code): ?string
    {
        foreach ($this->resultats as $p) {
            if ($p->code === $code) {
                return $p->statut;
            }
        }

        return null;
    }

    /** Prêt = aucun point bloquant en échec (PTR incohérent compris). */
    public function pret(): bool
    {
        foreach ($this->resultats as $p) {
            if ($p->statut === 'ko') {
                return false;
            }
        }

        return true;
    }

    /** DMARC absent => le domaine est mis en pause. */
    public function pause(): bool
    {
        return $this->statutDe('dmarc_present') === 'ko';
    }

    /** @return array<string, string> code => statut */
    public function enTableau(): array
    {
        $t = [];
        foreach ($this->resultats as $p) {
            $t[$p->code] = $p->statut;
        }

        return $t;
    }
}
