<?php

/*
|--------------------------------------------------------------------------
| Double authentification par courriel (G3)
|--------------------------------------------------------------------------
| `actif` est ÉTEINT par défaut : tant qu'il l'est, aucun code n'est émis.
| Lire par `config()`, jamais `env()` hors de ce fichier (config:cache).
*/

return [
    'actif' => filter_var(env('CRM_DOUBLE_AUTH_COURRIEL_ENABLED', false), FILTER_VALIDATE_BOOLEAN) === true,
    'duree_code_minutes' => 10,
    'essais_max' => 5,
    // Émissions « connexion » tolérées par fenêtre de 10 minutes et par compte.
    'renvois_max' => 3,
    'duree_appareil_jours' => 30,
];
