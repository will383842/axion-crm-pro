<?php

namespace App\Envoi\Configuration;

interface SourceConfiguration
{
    /**
     * @return list<DomaineConfig>
     *
     * @throws PaquetInvalide
     */
    public function domaines(): array;

    /** @return list<string> domaines de la base laissés de côté (sans paramètres), connus après domaines() */
    public function ignores(): array;
}
