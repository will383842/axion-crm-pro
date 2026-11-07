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
    ) {}
}
