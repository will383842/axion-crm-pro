<?php

namespace App\Crm\Ingest;

/**
 * Dictionnaire fermé « Famille › Détail » de l'origine (contrat v3, ADR § 4).
 *
 * Le fichier `resources/dictionnaire-origine.v1.json` est une copie TELLE
 * QUELLE de celui du site (test d'empreinte : `DictionnaireOrigineEmpreinteTest`).
 * Ajout = nouvelle version, lecteurs d'abord ; aucune clé n'est retirée.
 */
final class OrigineDictionnaire
{
    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /** @return array<string, array{libelle: string, details: array<string, array<string, mixed>>}> */
    public static function familles(): array
    {
        if (self::$cache === null) {
            $brut = file_get_contents(dirname(__DIR__, 3) . '/resources/dictionnaire-origine.v1.json');
            self::$cache = json_decode($brut === false ? '{}' : $brut, true, 512, JSON_THROW_ON_ERROR);
        }

        /** @var array<string, array{libelle: string, details: array<string, array<string, mixed>>}> */
        return self::$cache['familles'] ?? [];
    }

    public static function familleConnue(string $famille): bool
    {
        return array_key_exists($famille, self::familles());
    }

    public static function valide(string $famille, string $detail): bool
    {
        return isset(self::familles()[$famille]['details'][$detail]);
    }
}
