<?php

namespace App\Envoi\Dns;

/** Une ligne du plan : la valeur est un MODÈLE à jetons `{...}`, jamais une valeur réelle. */
final class EnregistrementDns
{
    public function __construct(
        public readonly string $type,
        public readonly string $nom,
        public readonly string $valeurModele,
        public readonly string $role,
    ) {}
}
