<?php

namespace App\Envoi\Configuration;

/**
 * Règle de fer : le socle local (plafond dur, homme mort, mode témoins, liste blanche) ne se règle JAMAIS par
 * ce canal. Toute clé qui le vise, à n'importe quelle profondeur, est une dérive.
 */
final class GardeSocle
{
    private const INTERDITES = ['plafond_dur', 'plafond_global', 'plafond_global_jour', 'homme_mort', 'mode_temoins', 'temoins', 'liste_blanche', 'whitelist'];

    /**
     * @param  array<mixed>  $brut
     * @return list<string>
     */
    public static function clesVisees(array $brut): array
    {
        $trouvees = [];
        foreach ($brut as $cle => $valeur) {
            if (is_string($cle) && in_array(str_replace(['-', ' '], '_', strtolower($cle)), self::INTERDITES, true)) {
                $trouvees[] = $cle;
            }
            if (is_array($valeur)) {
                array_push($trouvees, ...self::clesVisees($valeur));
            }
        }

        return array_values(array_unique($trouvees));
    }
}
