<?php

namespace App\Envoi\Configuration;

/** Un domaine d'envoi tel que le serveur le recevra : nom, débit et plafond souhaités. Ni sélecteur ni clé DKIM. */
final class DomaineConfig
{
    public function __construct(
        public readonly string $domaine,
        public readonly int $debitHeure,
        public readonly int $plafondJour,
    ) {}

    /** @return array<string, string|int> */
    public function enTableau(): array
    {
        return ['domaine' => $this->domaine, 'debit_heure' => $this->debitHeure, 'plafond_jour' => $this->plafondJour];
    }
}
