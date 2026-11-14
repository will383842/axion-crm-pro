<?php

namespace App\Envoi\Reputation;

use InvalidArgumentException;

/** Un constat normalisé de réputation. Valeurs fermées ; aucune adresse e-mail (preuve et ressource contrôlées par le dépôt). */
final class ConstatReputation
{
    public const SOURCES = ['google_postmaster', 'microsoft_snds', 'microsoft_jmrp', 'orange'];

    public const RESSOURCES = ['ip', 'domaine'];

    public const BONNE = 'bonne';

    public const MOYENNE = 'moyenne';

    public const MAUVAISE = 'mauvaise';

    public const INCONNUE = 'inconnue';

    public const VALEURS = [self::BONNE, self::MOYENNE, self::MAUVAISE, self::INCONNUE];

    public function __construct(
        public readonly string $source,
        public readonly string $ressourceType,
        public readonly string $ressource,
        public readonly string $jour,
        public readonly string $reputation,
        public readonly ?float $tauxPlaintes,
        public readonly ?int $erreursAuth,
        public readonly ?int $pieges,
        public readonly string $preuve,
        public readonly string $auteur,
        public readonly string $saisie = 'manuelle',
    ) {
        if (! in_array($source, self::SOURCES, true) || ! in_array($ressourceType, self::RESSOURCES, true) || ! in_array($reputation, self::VALEURS, true)) {
            throw new InvalidArgumentException('Constat de réputation invalide.');
        }
    }

    /** Fournisseur de destinataires freiné par cette source (Orange : `autre`, le CHECK des pauses n'a que trois valeurs). */
    public static function fournisseurDe(string $source): string
    {
        return match ($source) {
            'google_postmaster' => 'gmail',
            'microsoft_snds', 'microsoft_jmrp' => 'microsoft',
            default => 'autre',
        };
    }
}
