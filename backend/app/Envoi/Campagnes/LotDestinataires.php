<?php

namespace App\Envoi\Campagnes;

/**
 * X1-L2 — un lot de destinataires et ses compteurs (nombres seulement : le lot L4 les écrira dans une colonne numérique).
 * Invariant : `membres = retenus + somme(SelectionDestinataires::MOTIFS)`.
 */
final class LotDestinataires
{
    /**
     * @param  list<Destinataire>  $destinataires
     * @param  array<string, int>  $compteurs
     */
    public function __construct(
        public readonly array $destinataires,
        public readonly array $compteurs,
        public readonly ?int $curseurSuivant,   // null = fin de la cible
    ) {}
}
