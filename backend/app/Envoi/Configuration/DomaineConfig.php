<?php

namespace App\Envoi\Configuration;

/** Un domaine d'envoi tel que le serveur le recevra : valeurs PUBLIQUES seulement (jamais de clé privée). */
final class DomaineConfig
{
    public function __construct(
        public readonly string $domaine,
        public readonly string $selecteur,
        public readonly string $clePublique,
        public readonly int $debitHeure,
        public readonly int $plafondJour,
    ) {}

    /** @return array<string, string|int> */
    public function enTableau(): array
    {
        return [
            'domaine' => $this->domaine, 'selecteur' => $this->selecteur, 'cle_publique' => $this->clePublique,
            'debit_heure' => $this->debitHeure, 'plafond_jour' => $this->plafondJour,
        ];
    }
}
