<?php

namespace App\Envoi\Exceptions;

use RuntimeException;

/** Ajout de témoin refusé par une règle métier (plafond, fournisseur déjà couvert). Message sans adresse. */
final class TemoinRefuse extends RuntimeException
{
    public const PLAFOND = 6;

    public static function fournisseurDejaCouvert(string $fournisseur): self
    {
        return new self("Témoin refusé : le fournisseur « {$fournisseur} » a déjà un témoin (un seul par fournisseur).");
    }

    public static function plafondAtteint(): self
    {
        return new self('Témoin refusé : ' . self::PLAFOND . ' témoins au plus par workspace (un par fournisseur).');
    }
}
