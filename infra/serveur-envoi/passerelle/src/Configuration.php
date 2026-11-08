<?php

declare(strict_types=1);

namespace Passerelle;

/** Les secrets viennent UNIQUEMENT de l'environnement : PASSERELLE_CLES = JSON {"espace":"cle"}. */
final class Configuration
{
    /** Longueur minimale d'une clé HMAC, en octets. */
    public const LONGUEUR_MIN_CLE = 32;

    /** Rotation : au plus deux clés actives par espace (l'ancienne et la nouvelle), choisies par `k=<id>`. */
    public const CLES_ACTIVES_MAX = 2;

    /**
     * Deux formes par espace : `"<uuid>":"<clé>"` (identifiant « 1 ») ou `"<uuid>":{"<id>":"<clé>", ...}` (2 au plus).
     * Une clé de moins de 32 octets est écartée ; plus de deux clés actives : l'espace entier est refusé.
     *
     * @return array<string,array<string,string>> clés par espace puis par identifiant
     */
    public static function cles(?string $json = null): array
    {
        $json ??= getenv('PASSERELLE_CLES') ?: '';
        $d = json_decode($json, true);
        if (! is_array($d)) {
            return [];
        }
        $sortie = [];
        foreach ($d as $ws => $v) {
            if (! is_string($ws)) {
                continue;
            }
            $v = is_string($v) ? ['1' => $v] : $v;
            if (! is_array($v) || count($v) > self::CLES_ACTIVES_MAX) {
                continue;
            }
            $valides = [];
            foreach ($v as $id => $cle) {
                if (preg_match('/^[A-Za-z0-9_-]{1,32}$/', (string) $id) === 1 && is_string($cle) && strlen($cle) >= self::LONGUEUR_MIN_CLE) {
                    $valides[(string) $id] = $cle;
                }
            }
            if ($valides !== []) {
                $sortie[$ws] = $valides;
            }
        }

        return $sortie;
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
