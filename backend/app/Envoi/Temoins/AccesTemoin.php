<?php

namespace App\Envoi\Temoins;

use SensitiveParameter;

/** Accès IMAP d'un témoin, déchiffré EN MÉMOIRE seulement : jamais affiché, journalisé ni sérialisé. */
final class AccesTemoin
{
    public function __construct(
        public readonly string $workspaceId,
        public readonly string $fournisseur,
        public readonly string $hote,
        public readonly string $adresse,
        #[SensitiveParameter]
        public readonly string $motDePasse,
        public readonly string $empreinteTemoin,
    ) {}

    /** Ne laisse rien filer dans un var_dump, un print_r ou une trace de pile. */
    public function __debugInfo(): array
    {
        return ['fournisseur' => $this->fournisseur, 'hote' => $this->hote];
    }
}
