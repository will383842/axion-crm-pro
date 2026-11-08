<?php

namespace App\Envoi\Serveur\Contrat;

/** Événement reçu avec son rang `seq` (par source) : permet de voir un trou. Jamais d'ouverture. */
final class EvenementSuivi
{
    public function __construct(
        public readonly string $id,
        public readonly int $seq,
        public readonly string $type,
        public readonly string $idInjection,
        /** K7 : un rebond dur en observation ne compte dans AUCUN frein (ajout seul, faux par défaut). */
        public readonly bool $observation = false,
    ) {}
}
