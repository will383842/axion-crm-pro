<?php

namespace App\Envoi\Configuration;

interface SourceConfiguration
{
    /** @return list<DomaineConfig> */
    public function domaines(): array;
}
