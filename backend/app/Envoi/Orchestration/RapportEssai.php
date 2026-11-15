<?php

namespace App\Envoi\Orchestration;

use DateTimeImmutable;

/** Résultat d'un essai à blanc : étapes vert/rouge (nom => réussi), début et fin. Jamais d'adresse. */
final class RapportEssai
{
    /** @param array<string, bool> $etapes */
    public function __construct(
        private readonly array $etapes,
        public readonly DateTimeImmutable $debut,
        public readonly DateTimeImmutable $fin,
    ) {}

    /** @return array<string, bool> */
    public function etapes(): array
    {
        return $this->etapes;
    }

    public function reussi(): bool
    {
        return $this->etapes !== [] && ! in_array(false, $this->etapes, true);
    }

    /** @return list<array{nom: string, resultat: string}> forme écrite dans `envoi_essais_blanc.etapes` */
    public function pourBase(): array
    {
        $liste = [];
        foreach ($this->etapes as $nom => $ok) {
            $liste[] = ['nom' => $nom, 'resultat' => $ok ? 'vert' : 'rouge'];
        }

        return $liste;
    }
}
