<?php

namespace App\Envoi\Configuration;

final class GenerateurConfiguration
{
    public function generer(SourceConfiguration $source): Paquet
    {
        return Paquet::depuis($source);
    }
}
