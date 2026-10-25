<?php

namespace App\Crm\Ingest;

/**
 * PROVENANCE ET DATE DE CHAQUE CHAMP d'une fiche (25/10/2026).
 *
 * `field_origins` (JSONB, depuis le socle L2) dit QUI a posé la valeur d'une
 * colonne : `declared`, `collected`, `manual`, `console`… `field_origins_at`
 * (JSONB, migration `2026_10_25_000010`) dit QUAND, en ISO 8601. Les deux
 * vivent côte à côte pour que la règle « le plus récent gagne » de la synchro
 * console ↔ CRM puisse être tranchée sans deviner.
 *
 * Une seule lecture/écriture de ces deux JSON : `CompaniesController` (retouche
 * manuelle) et `DossierClientIngest` (synchro console) ne doivent pas diverger
 * sur leur format.
 */
final class OriginesDesChamps
{
    /** @return array<string, string> */
    public static function decoder(mixed $brut): array
    {
        if (is_array($brut)) {
            $decode = $brut;
        } elseif (is_string($brut) && $brut !== '') {
            $decode = json_decode($brut, true);
        } else {
            return [];
        }
        if (! is_array($decode)) {
            return [];
        }

        $propre = [];
        foreach ($decode as $cle => $valeur) {
            if (is_string($cle) && is_string($valeur)) {
                $propre[$cle] = $valeur;
            }
        }

        return $propre;
    }

    /** @param  array<string, string>  $valeurs */
    public static function encoder(array $valeurs): string
    {
        return $valeurs === []
            ? '{}'
            : json_encode($valeurs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Marque `$colonnes` avec `$origine` à la date `$quand`.
     *
     * @param  list<string>  $colonnes
     * @return array{field_origins: string, field_origins_at?: string}
     */
    public static function marquer(
        mixed $originesBrutes,
        mixed $datesBrutes,
        array $colonnes,
        string $origine,
        string $quand,
        bool $avecDates = true,
    ): array {
        $origines = self::decoder($originesBrutes);
        $dates = self::decoder($datesBrutes);
        foreach ($colonnes as $colonne) {
            $origines[$colonne] = $origine;
            $dates[$colonne] = $quand;
        }

        $retour = ['field_origins' => self::encoder($origines)];
        if ($avecDates) {
            $retour['field_origins_at'] = self::encoder($dates);
        }

        return $retour;
    }
}
