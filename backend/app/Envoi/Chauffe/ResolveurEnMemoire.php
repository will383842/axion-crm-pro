<?php

namespace App\Envoi\Chauffe;

/** Faux résolveur pour les tests : une table domaine => fournisseur, « autre » sinon. AUCUN réseau. */
final class ResolveurEnMemoire implements ResolveurFournisseur
{
    /** @param array<string, string> $domaines */
    public function __construct(private readonly array $domaines = []) {}

    public function fournisseurDe(string $adresse, ?string $workspaceId = null): string
    {
        return $this->domaines[Fournisseur::domaine($adresse)] ?? Fournisseur::AUTRE;
    }
}
