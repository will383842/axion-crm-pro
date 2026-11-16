<?php

namespace App\Envoi\Campagnes;

/**
 * Issue d'un appel de matérialisation : état de la ligne (en_cours, terminee, bloquee), inscriptions créées PAR CET APPEL, curseur actuel,
 * compteurs cumulés de la ligne, motif (blocage, ou « horizon_plein » quand l'appel s'arrête faute de place sur l'horizon).
 */
final class ResultatMaterialisation
{
    /** @param array<string,int> $compteurs */
    public function __construct(public readonly string $etat, public readonly int $inscrits, public readonly int $curseur, public readonly array $compteurs, public readonly ?string $motif) {}
}
