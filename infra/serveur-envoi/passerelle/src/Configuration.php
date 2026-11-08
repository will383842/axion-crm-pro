<?php

declare(strict_types=1);

namespace Passerelle;

/** Les secrets viennent UNIQUEMENT de l'environnement : PASSERELLE_CLES = JSON {"espace":"cle"}. */
final class Configuration
{
    /** @return array<string,string> */
    public static function cles(?string $json = null): array
    {
        $json ??= getenv('PASSERELLE_CLES') ?: '';
        $d = json_decode($json, true);
        if (! is_array($d)) {
            return [];
        }

        return array_filter($d, fn ($v, $k): bool => is_string($k) && is_string($v) && $v !== '', ARRAY_FILTER_USE_BOTH);
    }
}
