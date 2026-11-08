<?php

namespace App\Envoi\Imap;

/**
 * Événement émis vers les arrêts de L1. `adresse` est transmise EN MÉMOIRE au
 * récepteur pour qu'il applique l'arrêt ; elle n'est jamais écrite par la relève.
 * `adresseExpediteur` : l'auteur du retour s'il diffère de l'adresse d'origine.
 */
final class RetourDetecte
{
    public function __construct(
        public readonly ClasseRetour $classe,
        public readonly string $idInjection,
        public readonly string $adresse,
        public readonly ?string $adresseExpediteur,
        public readonly string $cle,
    ) {}
}
