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
    // filter_var : « non », « off », « faux », « 0 » => false (un simple (bool) les lisait true).
    'reel_actif' => filter_var(env('ENVOI_REEL_ACTIF', false), FILTER_VALIDATE_BOOLEAN) === true,
    // Bornés à [0, 300] en V0 (App\Envoi\PasserelleLocale::PLAFOND_MAX) : au-delà, injection refusée.
    'plafond_jour' => (int) env('ENVOI_PLAFOND_JOUR', 20),
    // Plafond GLOBAL (tous espaces), en plus du plafond par espace.
    'plafond_global_jour' => (int) env('ENVOI_PLAFOND_GLOBAL_JOUR', 20),
    // Clé HMAC des empreintes de témoins. Jamais dans le dépôt : posée dans l'environnement.
    'cle_empreinte' => (string) env('ENVOI_CLE_EMPREINTE', ''),
];
