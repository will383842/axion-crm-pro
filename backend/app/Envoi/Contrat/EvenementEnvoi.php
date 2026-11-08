<?php

namespace App\Envoi\Contrat;

/** Événement de retour (livré, rebond, plainte…). Jamais d'ouverture. */
final class EvenementEnvoi
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $idInjection,
        public readonly string $adresse,
        /** K7 : rebond en mode observation (50 premiers par espace) : jamais compté dans les freins. */
        public readonly bool $observation = false,
    ) {}
}
