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

    /**
     * Purge automatique des événements : INACTIVE par défaut. Les événements peuvent contenir des données de personnes ;
     * la règle de rétention doit être validée par le responsable avant activation (PASSERELLE_PURGE_ACTIVE=true).
     */
    public static function purgeActive(?string $valeur = null): bool
    {
        $valeur ??= getenv('PASSERELLE_PURGE_ACTIVE') ?: '';

        return $valeur === 'true';
    }
}
