<?php

namespace App\Envoi\Configuration;

final class GenerateurConfiguration
{
    public function generer(string $workspaceId, SourceConfiguration $source): Paquet
    {
        return Paquet::pourEspace($workspaceId, $source);
    }
}
