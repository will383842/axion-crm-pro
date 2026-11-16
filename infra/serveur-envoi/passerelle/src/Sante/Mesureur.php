<?php

declare(strict_types=1);

namespace Passerelle\Sante;

interface Mesureur
{
    /** @return array<string, int|float|bool> clés du contrat seulement ; une mesure indisponible est OMISE (jamais 0) */
    public function mesurer(): array;
}
