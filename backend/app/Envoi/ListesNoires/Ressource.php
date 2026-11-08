<?php

namespace App\Envoi\ListesNoires;

final class Ressource
{
    public const IP = 'ip';

    public const DOMAINE = 'domaine';

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $type,
        public readonly string $valeur,
    ) {}
}
