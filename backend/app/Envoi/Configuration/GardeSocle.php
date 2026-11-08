<?php

namespace App\Envoi\Configuration;

/**
 * Règle de fer : le socle local (plafond dur, homme mort, mode témoins, liste blanche, registre, domaine principal)
 * ne se règle JAMAIS par ce canal. Chaque clé, à toute profondeur, est normalisée (minuscules, puis tout ce qui
 * n'est pas [a-z0-9] retiré) avant comparaison. À appeler APRÈS les bornes de structure (récursion bornée).
 */
final class GardeSocle
{
    private const INTERDITES = [
        'plafonddur', 'plafondglobal', 'plafondglobaljour', 'hommemort', 'modetemoins', 'temoins',
        'listeblanche', 'whitelist', 'registre', 'domaineprincipal', 'domainesinterdits',
    ];

    public static function normaliser(string $cle): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($cle));
    }

    /**
     * @param  array<mixed>  $brut
     * @return list<string>
     */
    public static function clesVisees(array $brut): array
    {
        $trouvees = [];
        foreach ($brut as $cle => $valeur) {
            if (is_string($cle) && in_array(self::normaliser($cle), self::INTERDITES, true)) {
                $trouvees[] = $cle;
            }
            if (is_array($valeur)) {
                array_push($trouvees, ...self::clesVisees($valeur));
            }
        }

        return array_values(array_unique($trouvees));
    }
}
