<?php

namespace App\Models\Concerns;

use App\Crm\ProvenanceTiers\ProvenanceTiers;

/**
 * Ôte les origines TIERS de `field_origins` (`apporteur`, `commercial`,
 * `societe` — N12, 03/10/2026) de toute fiche sérialisée pour un compte qui
 * n'a pas le rôle owner.
 *
 * Posé sur le MODÈLE, pas sur une route : `ContactsController::show` et
 * `CompaniesController::show` rendent le modèle entier, et une route future
 * qui le rendrait aussi serait couverte sans y penser. Les autres origines
 * (`declared`, `collected`…) restent lisibles.
 */
trait MasqueProvenanceTiers
{
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $tableau = parent::toArray();
        if (array_key_exists('field_origins', $tableau) && ! ProvenanceTiers::lisible()) {
            $tableau['field_origins'] = ProvenanceTiers::sansOriginesTiers($tableau['field_origins']);
        }

        return $tableau;
    }
}
