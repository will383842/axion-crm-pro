<?php

namespace App\Socle\Alertes;

use Illuminate\Support\Facades\Cache;

/**
 * Anti-bruit : la même alerte (même titre, même produit, même gravité) ne
 * repart qu'une fois par fenêtre (15 minutes par défaut). La première passe
 * tout de suite ; les suivantes sont comptées puis absorbées.
 */
final class Regroupement
{
    /** Vrai si cette alerte a déjà été émise dans la fenêtre (donc à absorber). */
    public function dejaVue(string $titre, string $produit, string $gravite): bool
    {
        $minutes = max(1, (int) config('alertes.regroupement_minutes', 15));
        $cle = 'alertes:regroupement:' . sha1($produit . '|' . $gravite . '|' . $titre);

        if (Cache::add($cle, 1, now()->addMinutes($minutes))) {
            return false;
        }

        // Le compteur ne sert qu'au journal ; sa perte ne change rien à l'envoi.
        try {
            Cache::increment($cle . ':absorbees');
        } catch (\Throwable) {
        }

        return true;
    }
}
