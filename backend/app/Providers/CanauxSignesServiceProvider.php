<?php

namespace App\Providers;

use App\Support\FenetreHorodatage;
use Illuminate\Support\ServiceProvider;

/**
 * Validation au démarrage de la configuration des canaux internes signés.
 *
 * La fenêtre d'horodatage est relue et validée à CHAQUE démarrage (y compris
 * sous `config:cache`, où la valeur brute est figée telle que lue dans
 * l'environnement). Une valeur invalide lève une exception : l'application ne
 * démarre pas, plutôt que de servir un canal mal configuré.
 *
 * La valeur validée est réécrite dans la configuration sous forme d'entier :
 * les contrôleurs lisent ainsi une valeur déjà vérifiée.
 */
class CanauxSignesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $fenetre = FenetreHorodatage::valider(config('crm.ingest.max_clock_skew_seconds'));

        config(['crm.ingest.max_clock_skew_seconds' => $fenetre]);
    }
}
