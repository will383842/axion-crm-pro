<?php

/*
|--------------------------------------------------------------------------
| ENVOI — palier 0, socle local
|--------------------------------------------------------------------------
| `reel_actif` est ÉTEINT par défaut : tant qu'il l'est, la passerelle locale
| refuse toute injection et ne tente aucune connexion. L'ouvrir est une
| décision d'exploitation (variable d'environnement), jamais une route d'API.
| `plafond_jour` : plafond dur d'injections par workspace et par jour ; au-delà,
| le message est reporté au lendemain.
*/

return [
    'reel_actif' => (bool) env('ENVOI_REEL_ACTIF', false),
    'plafond_jour' => (int) env('ENVOI_PLAFOND_JOUR', 20),
];
