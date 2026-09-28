<?php

namespace App\Crm\Referentiels;

/**
 * Résultat de la lecture d'un code d'activité (`NomenclatureNaf::classer`).
 *
 *  - `nomenclature` : `naf_rev2`, `naf_rev1`, `nap_1973`, `inconnue`, ou null
 *    si la fiche n'a aucun code ;
 *  - `codeRev2` : le code converti en NAF rév. 2 quand la table officielle
 *    le donne (rév. 2 d'origine, ou rév. 1 présent dans la table de passage),
 *    null sinon — on n'invente jamais un code ;
 *  - `secteur` : une clé de `Taxonomy::SECTEURS`, jamais vide ;
 *  - `methode` : comment le secteur a été obtenu (pour le bilan du
 *    reclassement : table, repli par groupe, par division…).
 */
final readonly class ClassementNaf
{
    public function __construct(
        public ?string $nomenclature,
        public ?string $codeRev2,
        public string $secteur,
        public string $methode,
    ) {}
}
