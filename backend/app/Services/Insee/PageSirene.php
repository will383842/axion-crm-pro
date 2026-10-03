<?php

namespace App\Services\Insee;

/**
 * Une page du flux des modifications Sirene (lot N8), rendue par
 * `HttpInseeClient::iterateModificationsDepuis()`.
 *
 * Un OBJET et non un tableau (incident mémoire du 03/10/2026) : un générateur
 * PHP garde la dernière valeur rendue jusqu'au `yield` SUIVANT — donc pendant
 * toute la lecture de la page suivante. Avec un tableau, deux pages décodées
 * cohabitaient en mémoire ; avec cet objet, le générateur VIDE la page
 * (`liberer()`) dès qu'il reprend la main, avant de lire la suivante.
 */
final class PageSirene
{
    /**
     * @param  string  $curseur  le curseur qui a servi à lire cette page
     * @param  ?string  $suivant  le curseur de la page suivante (null : dernière page)
     * @param  list<array<string, mixed>>  $unites
     */
    public function __construct(
        public readonly string $curseur,
        public readonly ?string $suivant,
        public array $unites,
    ) {}

    public function liberer(): void
    {
        $this->unites = [];
    }
}
